<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/afiliado_regra.php';
require_once __DIR__ . '/../rollover_helper.php';
require_once __DIR__ . '/../includes/facebook_pixel.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if (cfg('gateway_ativo', 'manual') !== 'akadpay') {
    http_response_code(200);
    echo json_encode(['ok' => true, 'info' => 'gateway inativo']);
    exit;
}

$rawBody = file_get_contents('php://input');
if (empty($rawBody)) {
    http_response_code(400);
    echo json_encode(['error' => 'Payload vazio']);
    exit;
}

$data = json_decode($rawBody, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['error' => 'JSON invalido']);
    exit;
}

// ── GGPIX: status=COMPLETE, type=PIX_IN. Casa por externalId (= referencia),
//    com fallback para transactionId. ─────────────────────────────────────────
$status = isset($data['status']) ? strtoupper((string)$data['status']) : '';
$type   = isset($data['type'])   ? strtoupper((string)$data['type'])   : '';

$isPago = ($status === 'COMPLETE') && ($type === 'PIX_IN');
if (!$isPago) {
    http_response_code(200);
    echo json_encode(['ok' => true, 'info' => 'evento ignorado: ' . $type . '/' . $status]);
    exit;
}

// Candidatos para localizar a transacao (externalId primeiro, depois transactionId)
$candidatos = [];
foreach (['externalId', 'transactionId'] as $k) {
    if (!empty($data[$k])) {
        $val = (string)$data[$k];
        if (!in_array($val, $candidatos)) $candidatos[] = $val;
    }
}

if (empty($candidatos)) {
    akadpay_log('AKADPAY_WEBHOOK_IN_SEM_TXID', substr($rawBody, 0, 800));
    http_response_code(422);
    echo json_encode(['error' => 'externalId/transactionId nao informado']);
    exit;
}

$txid = $candidatos[0];
$tx = null;
foreach ($candidatos as $candidato) {
    $stmt = db()->prepare('SELECT * FROM transacoes WHERE referencia = ? AND tipo = "deposito" LIMIT 1');
    $stmt->execute([$candidato]);
    $tx = $stmt->fetch();
    if ($tx) { $txid = $candidato; break; }
}

if (!$tx) {
    akadpay_log('AKADPAY_WEBHOOK_IN_TX_NAO_ENCONTRADA', 'candidatos=' . implode(',', $candidatos));
    http_response_code(200);
    echo json_encode(['ok' => true, 'info' => 'transacao nao encontrada']);
    exit;
}

if ($tx['status'] === 'aprovado') {
    http_response_code(200);
    echo json_encode(['ok' => true, 'info' => 'ja processada']);
    exit;
}

$db = db();
$db->beginTransaction();

try {
    $db->prepare('UPDATE transacoes SET status = "aprovado", updated_at = NOW() WHERE id = ?')
       ->execute([$tx['id']]);

    $db->prepare('UPDATE usuarios SET saldo = saldo + ? WHERE id = ?')
       ->execute([$tx['valor'], $tx['usuario_id']]);

    $stmtInd = $db->prepare('SELECT indicado_por FROM usuarios WHERE id = ?');
    $stmtInd->execute([$tx['usuario_id']]);
    $rowInd = $stmtInd->fetch();

    if ($rowInd && $rowInd['indicado_por']) {
        $afiliadorId = (int)$rowInd['indicado_por'];
        $convidadoId = (int)$tx['usuario_id'];

        if (cfg('afiliado_regra_ativo', '0') === '1' && cfg('afiliado_regra_momento', 'deposito') === 'deposito') {
            afil_classificar_convidado($afiliadorId, $convidadoId, 'deposito');
        }

        if (!afil_convidado_ignorado($afiliadorId, $convidadoId)) {
            $stmtPerc = $db->prepare('SELECT comissao_perc_individual FROM usuarios WHERE id = ?');
            $stmtPerc->execute([$afiliadorId]);
            $rowPerc = $stmtPerc->fetch();
            $percIndividual = ($rowPerc && $rowPerc['comissao_perc_individual'] !== null)
                ? (float)$rowPerc['comissao_perc_individual'] : null;
            $perc = $percIndividual !== null ? $percIndividual : (float)cfg('comissao_nivel1_perc', 10);

            if ($perc > 0) {
                $comissao = round($tx['valor'] * $perc / 100, 2);
                $db->prepare('UPDATE usuarios SET saldo_afiliado = saldo_afiliado + ?, total_comissao = total_comissao + ? WHERE id = ?')
                   ->execute([$comissao, $comissao, $afiliadorId]);
                $db->prepare('INSERT INTO transacoes (usuario_id, tipo, valor, status, referencia, descricao) VALUES (?, "bonus_indicacao", ?, "aprovado", ?, "Comissao de indicacao")')
                   ->execute([$afiliadorId, $comissao, $convidadoId]);
                $db->prepare('UPDATE usuarios SET bonus_pago = 1 WHERE id = ?')->execute([$convidadoId]);
            }
        }
    }

    $bonusPerc = (float)cfg('bonus_deposito_perc', 0);
    $bonusMin  = (float)cfg('bonus_deposito_minimo', 0);
    $bonusMax  = (float)cfg('bonus_deposito_maximo', 0);
    if ($bonusPerc > 0 && $tx['valor'] >= $bonusMin && ($bonusMax === 0.0 || $tx['valor'] <= $bonusMax)) {
        $bonus = round($tx['valor'] * $bonusPerc / 100, 2);
        if ($bonus > 0) {
            $db->prepare('UPDATE usuarios SET saldo = saldo + ? WHERE id = ?')->execute([$bonus, $tx['usuario_id']]);
            $db->prepare('INSERT INTO transacoes (usuario_id, tipo, valor, status, referencia, descricao) VALUES (?, "ajuste_admin", ?, "aprovado", ?, "Bonus de deposito")')
               ->execute([$tx['usuario_id'], $bonus, $tx['id']]);
        }
    }

    $db->commit();

    rollover_criar($tx['usuario_id'], $tx['id'], (float)$tx['valor']);

    if (fb_pixel_ativo()) {
        fb_event_purchase((float)$tx['valor'], (int)$tx['usuario_id'], (string)$tx['id']);
    }

    akadpay_log('AKADPAY_WEBHOOK_IN_APROVADO', 'txid=' . $txid . ' valor=' . $tx['valor']);

} catch (Exception $e) {
    $db->rollBack();
    akadpay_log('AKADPAY_WEBHOOK_IN_ERRO', $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erro interno']);
    exit;
}

http_response_code(200);
echo json_encode(['ok' => true, 'txid' => $txid]);
exit;

function akadpay_log($acao, $detalhes)
{
    try {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null;
        db()->prepare('INSERT INTO admin_logs (admin_id, acao, detalhes, ip) VALUES (NULL, ?, ?, ?)')
           ->execute([$acao, substr((string)$detalhes, 0, 1000), $ip]);
    } catch (Exception $e) {}
}
