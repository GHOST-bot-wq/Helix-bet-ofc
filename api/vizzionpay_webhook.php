<?php
// ===================================================================
//  api/vizzionpay_webhook.php — Webhook de confirmação VizzionPay
// ===================================================================
file_put_contents(__DIR__ . '/webhook_debug.log', date('Y-m-d H:i:s') . ' ' . $_SERVER['REQUEST_METHOD'] . ' ' . file_get_contents('php://input') . "\n", FILE_APPEND);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/afiliado_regra.php';
require_once __DIR__ . '/../rollover_helper.php';
require_once __DIR__ . '/../includes/facebook_pixel.php';

header('Content-Type: application/json; charset=utf-8');

// Só aceita POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Gateway ativo? Responde 200 para não gerar retentativas
if (cfg('gateway_ativo', 'manual') !== 'vizzionpay') {
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

// ── Verifica o evento / status ────────────────────────────────────────
$event = isset($data['event']) ? $data['event'] : '';

// VizzionPay envia os dados dentro de $data['transaction']
$tx_data = isset($data['transaction']) && is_array($data['transaction']) ? $data['transaction'] : [];
$status  = isset($tx_data['status']) ? strtoupper($tx_data['status']) : '';

$isPago = ($event === 'TRANSACTION_PAID')
       || ($status === 'COMPLETED')
       || ($status === 'PAID')
       || ($status === 'OK');

if (!$isPago) {
    http_response_code(200);
    echo json_encode(['ok' => true, 'info' => 'evento ignorado: ' . $event . '/' . $status]);
    exit;
}

// ── Extrai o identifier: VizzionPay envia dentro de transaction{} ─────
$candidatos = [];

// Busca dentro de transaction{} primeiro (estrutura real da VizzionPay)
foreach (['identifier', 'external_id', 'transactionId', 'txid', 'transaction_id', 'id'] as $k) {
    if (!empty($tx_data[$k])) {
        $val = (string)$tx_data[$k];
        if (!in_array($val, $candidatos)) $candidatos[] = $val;
    }
}

// Fallback: busca na raiz do payload também
foreach (['identifier', 'external_id', 'transactionId', 'txid', 'transaction_id', 'id'] as $k) {
    if (!empty($data[$k])) {
        $val = (string)$data[$k];
        if (!in_array($val, $candidatos)) $candidatos[] = $val;
    }
}
if (empty($candidatos)) {
    vizzionpay_log('WEBHOOK_SEM_TXID', $rawBody);
    http_response_code(422);
    echo json_encode(['error' => 'txid nao encontrado no payload']);
    exit;
}

$txid = $candidatos[0]; // para log

// ── Busca a transação no banco tentando todos os IDs candidatos ───────
$tx = null;

foreach ($candidatos as $candidato) {
    $stmt = db()->prepare(
        'SELECT * FROM transacoes WHERE referencia = ? AND tipo = "deposito" LIMIT 1'
    );
    $stmt->execute([$candidato]);
    $tx = $stmt->fetch();
    if ($tx) { $txid = $candidato; break; }
}

if (!$tx) {
    vizzionpay_log('WEBHOOK_TX_NAO_ENCONTRADA', 'candidatos=' . implode(',', $candidatos) . ' payload=' . substr($rawBody, 0, 500));
    http_response_code(200);
    echo json_encode(['ok' => true, 'info' => 'transacao nao encontrada']);
    exit;
}

// ── Idempotência ──────────────────────────────────────────────────────
if ($tx['status'] === 'aprovado') {
    http_response_code(200);
    echo json_encode(['ok' => true, 'info' => 'ja processada']);
    exit;
}

// ── Credita saldo + comissão + bônus ─────────────────────────────────
$db = db();
$db->beginTransaction();

try {
    // 1. Aprova a transação
    $db->prepare(
        'UPDATE transacoes SET status = "aprovado", updated_at = NOW() WHERE id = ?'
    )->execute([$tx['id']]);

    // 2. Credita saldo do usuário
    $db->prepare(
        'UPDATE usuarios SET saldo = saldo + ? WHERE id = ?'
    )->execute([$tx['valor'], $tx['usuario_id']]);

    // 3. Comissão de indicação
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
                $db->prepare(
                    'UPDATE usuarios SET saldo_afiliado = saldo_afiliado + ?, total_comissao = total_comissao + ? WHERE id = ?'
                )->execute([$comissao, $comissao, $afiliadorId]);
                $db->prepare(
                    'INSERT INTO transacoes (usuario_id, tipo, valor, status, referencia, descricao) VALUES (?, "bonus_indicacao", ?, "aprovado", ?, "Comissao de indicacao")'
                )->execute([$afiliadorId, $comissao, $convidadoId]);
                $db->prepare('UPDATE usuarios SET bonus_pago = 1 WHERE id = ?')
                   ->execute([$convidadoId]);
            }
        }
    }

    // 4. Bônus de depósito
    $bonusPerc = (float)cfg('bonus_deposito_perc', 0);
    $bonusMin  = (float)cfg('bonus_deposito_minimo', 0);
    $bonusMax  = (float)cfg('bonus_deposito_maximo', 0);
    if ($bonusPerc > 0 && $tx['valor'] >= $bonusMin && ($bonusMax === 0.0 || $tx['valor'] <= $bonusMax)) {
        $bonus = round($tx['valor'] * $bonusPerc / 100, 2);
        if ($bonus > 0) {
            $db->prepare('UPDATE usuarios SET saldo = saldo + ? WHERE id = ?')
               ->execute([$bonus, $tx['usuario_id']]);
            $db->prepare(
                'INSERT INTO transacoes (usuario_id, tipo, valor, status, referencia, descricao) VALUES (?, "ajuste_admin", ?, "aprovado", ?, "Bonus de deposito")'
            )->execute([$tx['usuario_id'], $bonus, $tx['id']]);
        }
    }

    $db->commit();

    // ── 5. Criar rollover se sistema estiver ativo ───────────────────
    rollover_criar($tx['usuario_id'], $tx['id'], (float)$tx['valor']);

    // ── 6. Facebook Pixel — evento Purchase (Conversions API) ────────
    if (fb_pixel_ativo()) {
        fb_event_purchase(
            (float)$tx['valor'],
            (int)$tx['usuario_id'],
            (string)$tx['id']
        );
    }

    vizzionpay_log('WEBHOOK_APROVADO', 'txid=' . $txid . ' valor=' . $tx['valor'] . ' usuario=' . $tx['usuario_id']);

} catch (Exception $e) {
    $db->rollBack();
    vizzionpay_log('WEBHOOK_ERRO', $e->getMessage() . ' txid=' . $txid);
    http_response_code(500);
    echo json_encode(['error' => 'Erro interno ao processar pagamento']);
    exit;
}

http_response_code(200);
echo json_encode(['ok' => true, 'txid' => $txid]);
exit;

// ── Logger ────────────────────────────────────────────────────────────
function vizzionpay_log($acao, $detalhes)
{
    try {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null;
        db()->prepare(
            'INSERT INTO admin_logs (admin_id, acao, detalhes, ip) VALUES (NULL, ?, ?, ?)'
        )->execute([$acao, substr((string)$detalhes, 0, 1000), $ip]);
    } catch (Exception $e) {
        // silencia — log não pode derrubar o webhook
    }
}