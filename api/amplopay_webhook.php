<?php
// ===================================================================
//  api/amplopay_webhook.php — Webhook de confirmação AmploPay v1
// ===================================================================

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/afiliado_regra.php';
require_once __DIR__ . '/../rollover_helper.php';
require_once __DIR__ . '/../includes/facebook_pixel.php';

header('Content-Type: application/json; charset=utf-8');

// ── Só aceita POST ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(array('error' => 'Method not allowed'));
    exit;
}

$rawBody = file_get_contents('php://input');

if (empty($rawBody)) {
    http_response_code(400);
    echo json_encode(array('error' => 'Payload vazio'));
    exit;
}

$data = json_decode($rawBody, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(array('error' => 'JSON invalido'));
    exit;
}

// ── Verifica evento e status ─────────────────────────────────────────
// AmploPay v1 usa: event = "TRANSACTION_PAID"
//                  transaction.status = "COMPLETED"
$event  = isset($data['event']) ? $data['event'] : '';
$status = isset($data['transaction']['status']) ? $data['transaction']['status'] : '';

$isApproved = ($event === 'TRANSACTION_PAID' || $status === 'COMPLETED');

if (!$isApproved) {
    http_response_code(200);
    echo json_encode(array('ok' => true, 'info' => 'evento/status ignorado: ' . $event . '/' . $status));
    exit;
}

// ── Extrai o txid / identifier ───────────────────────────────────────
// Na v1, o nosso txid enviado na criação vem em transaction.identifier
$txid = isset($data['transaction']['identifier']) ? (string)$data['transaction']['identifier'] : '';

// Fallback para o ID interno da AmploPay se necessário
$amplo_id = isset($data['transaction']['id']) ? (string)$data['transaction']['id'] : '';

if (!$txid && !$amplo_id) {
    amplopay_log('WEBHOOK_SEM_ID', substr($rawBody, 0, 500));
    http_response_code(422);
    echo json_encode(array('error' => 'ID nao encontrado no payload'));
    exit;
}

// ── Busca a transação no banco ────────────────────────────────────────
$tx = null;
$refs = array_unique(array_filter(array($txid, $amplo_id)));

foreach ($refs as $ref) {
    $stmt = db()->prepare(
        'SELECT * FROM transacoes WHERE referencia = ? AND tipo = "deposito" LIMIT 1'
    );
    $stmt->execute(array($ref));
    $tx = $stmt->fetch();
    if ($tx) break;
}

if (!$tx) {
    amplopay_log('WEBHOOK_TX_NAO_ENCONTRADA', 'txid=' . $txid . ' amplo_id=' . $amplo_id);
    http_response_code(200);
    echo json_encode(array('ok' => true, 'info' => 'transacao nao encontrada'));
    exit;
}

// ── Idempotência: já aprovada? ───────────────────────────────────────
if ($tx['status'] === 'aprovado') {
    http_response_code(200);
    echo json_encode(array('ok' => true, 'info' => 'ja processada'));
    exit;
}

// ── Confirma o depósito ──────────────────────────────────────────────
$db = db();
$db->beginTransaction();

try {
    // 1. Aprova a transação
    $affected = $db->prepare(
        'UPDATE transacoes SET status = "aprovado", updated_at = NOW() WHERE id = ? AND status != "aprovado"'
    );
    $affected->execute(array($tx['id']));

    if ($affected->rowCount() === 0) {
        $db->rollBack();
        http_response_code(200);
        echo json_encode(array('ok' => true, 'info' => 'concorrencia'));
        exit;
    }

    // 2. Credita saldo do usuário
    $db->prepare(
        'UPDATE usuarios SET saldo = saldo + ? WHERE id = ?'
    )->execute(array($tx['valor'], $tx['usuario_id']));

    // 3. Comissão de indicação
    $stmtInd = $db->prepare('SELECT indicado_por FROM usuarios WHERE id = ?');
    $stmtInd->execute(array($tx['usuario_id']));
    $rowInd = $stmtInd->fetch();

    if ($rowInd && $rowInd['indicado_por']) {
        $afiliadorId = (int)$rowInd['indicado_por'];
        $convidadoId = (int)$tx['usuario_id'];

        // Se modo=deposito, classifica agora; se modo=cadastro, já foi classificado lá
        if (cfg('afiliado_regra_ativo', '0') === '1' && cfg('afiliado_regra_momento', 'deposito') === 'deposito') {
            afil_classificar_convidado($afiliadorId, $convidadoId, 'deposito');
        }

        // Não paga comissão se o convidado estiver marcado como ignorado
        if (!afil_convidado_ignorado($afiliadorId, $convidadoId)) {
            $stmtPerc = $db->prepare('SELECT comissao_perc_individual FROM usuarios WHERE id = ?');
            $stmtPerc->execute(array($afiliadorId));
            $rowPerc = $stmtPerc->fetch();
            $percIndividual = ($rowPerc && $rowPerc['comissao_perc_individual'] !== null)
                ? (float)$rowPerc['comissao_perc_individual'] : null;
            $perc = $percIndividual !== null ? $percIndividual : (float)cfg('comissao_nivel1_perc', 10);

            if ($perc > 0) {
                $comissao = round((float)$tx['valor'] * $perc / 100, 2);
                $db->prepare(
                    'UPDATE usuarios SET saldo_afiliado = saldo_afiliado + ?, total_comissao = total_comissao + ? WHERE id = ?'
                )->execute(array($comissao, $comissao, $afiliadorId));
                $db->prepare(
                    'INSERT INTO transacoes (usuario_id, tipo, valor, status, referencia, descricao) VALUES (?, "bonus_indicacao", ?, "aprovado", ?, "Comissao de indicacao")'
                )->execute(array($afiliadorId, $comissao, $convidadoId));
                $db->prepare('UPDATE usuarios SET bonus_pago = 1 WHERE id = ?')
                   ->execute(array($convidadoId));
            }
        }
    }

    // 4. Bônus de depósito
    $bonusPerc = (float)cfg('bonus_deposito_perc', 0);
    if ($bonusPerc > 0) {
        $bonus = round((float)$tx['valor'] * $bonusPerc / 100, 2);
        if ($bonus > 0) {
            $db->prepare('UPDATE usuarios SET saldo = saldo + ? WHERE id = ?')
               ->execute(array($bonus, $tx['usuario_id']));
            $db->prepare(
                'INSERT INTO transacoes (usuario_id, tipo, valor, status, referencia, descricao) VALUES (?, "ajuste_admin", ?, "aprovado", ?, "Bonus de deposito")'
            )->execute(array($tx['usuario_id'], $bonus, $tx['id']));
        }
    }

    $db->commit();

    // 5. Rollover
    rollover_criar($tx['usuario_id'], $tx['id'], (float)$tx['valor']);

    // 6. Facebook Pixel
    if (fb_pixel_ativo()) {
        fb_event_purchase((float)$tx['valor'], (int)$tx['usuario_id'], (string)$tx['id']);
    }

    amplopay_log('WEBHOOK_APROVADO', 'txid=' . $txid . ' valor=' . $tx['valor']);

} catch (Exception $e) {
    $db->rollBack();
    amplopay_log('WEBHOOK_ERRO', $e->getMessage());
    http_response_code(500);
    echo json_encode(array('error' => 'Erro interno'));
    exit;
}

http_response_code(200);
echo json_encode(array('ok' => true));
exit;

function amplopay_log($acao, $detalhes) {
    try {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null;
        db()->prepare('INSERT INTO admin_logs (acao, detalhes, ip) VALUES (?, ?, ?)')
           ->execute(array($acao, substr($detalhes, 0, 1000), $ip));
    } catch (Exception $e) {}
}
