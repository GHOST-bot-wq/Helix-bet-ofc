<?php
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$rawBody = file_get_contents('php://input');
$txid = '';
if (!empty($rawBody)) {
    $data = json_decode($rawBody, true);
    if (is_array($data) && !empty($data['idTransaction'])) {
        $txid = (string)$data['idTransaction'];
    }
}

// Webhook de saque mantido apenas para compatibilidade de endpoint.
// Nao altera status nem saldo: saque agora e 100% manual via aprovacao admin.
akadpay_out_log('AKADPAY_WEBHOOK_OUT_IGNORADO_MANUAL', 'txid=' . $txid . ' payload=' . substr((string)$rawBody, 0, 800));

http_response_code(200);
echo json_encode([
    'ok' => true,
    'info' => 'saque manual ativo: webhook ignorado',
]);
exit;

function akadpay_out_log($acao, $detalhes)
{
    try {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null;
        db()->prepare('INSERT INTO admin_logs (admin_id, acao, detalhes, ip) VALUES (NULL, ?, ?, ?)')
           ->execute([$acao, substr((string)$detalhes, 0, 1000), $ip]);
    } catch (Exception $e) {}
}
