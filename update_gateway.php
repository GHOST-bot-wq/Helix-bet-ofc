<?php
// ===================================================================
//  update_gateway.php - Script temporário para configurar a AmploPay
// ===================================================================

require_once __DIR__ . '/config.php';

try {
    $db = db();
    
    $configs = array(
        'gateway_ativo'       => 'amplopay',
        'amplo_client_id'     => 'dev-leonardo00_o55t3uom1rhu98e0',
        'amplo_client_secret' => 'd6vlfzndmmxlxb24huzlretickkkad5320av0n8n3jtoyb72uno2lvcwuop8wc5e',
    );
    
    $stmt = $db->prepare('INSERT INTO configuracoes (chave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = ?');
    
    foreach ($configs as $chave => $valor) {
        $stmt->execute(array($chave, $valor, $valor));
        echo "Configuração '$chave' atualizada com sucesso.\n";
    }
    
    echo "\nGateway alterado para AmploPay com sucesso!\n";
} catch (Exception $e) {
    echo "Erro ao atualizar gateway: " . $e->getMessage() . "\n";
}
