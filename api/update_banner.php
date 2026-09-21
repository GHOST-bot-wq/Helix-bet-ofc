<?php
// ===================================================================
//  api/update_banner.php - Atualiza URLs de banner e logo no banco MySQL
// ===================================================================

require_once __DIR__ . '/../config.php';

header('Content-Type: text/html; charset=utf-8');

try {
    $db = db();
    
    $configs = array(
        'banner_1'         => '/images/banner-principal.jpg',
        'banner_1_url'     => '/images/banner-principal.jpg',
        'banner_url'       => '/images/banner-principal.jpg',
        'site_logo_url'    => '/uploads/banners/banner_1775065856_35912429.png',
        'site_favicon_url' => '/uploads/banners/banner_1775065856_35912429.png',
    );
    
    $stmt = $db->prepare('INSERT INTO configuracoes (chave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = ?');
    
    foreach ($configs as $chave => $valor) {
        $stmt->execute(array($chave, $valor, $valor));
        echo "Configuracao '$chave' atualizada para '$valor'.<br>\n";
    }

    echo "<br><strong>Sucesso! Banco de dados atualizado para usar o banner e logo locais oficiais.</strong><br>\n";
} catch (Exception $e) {
    echo "Erro ao atualizar banco: " . $e->getMessage() . "<br>\n";
}
