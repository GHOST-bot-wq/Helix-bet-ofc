<?php
// ===================================================================
//  update_banner.php - Atualiza URLs de banner e logo no banco MySQL
// ===================================================================

require_once __DIR__ . '/config.php';

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

    // Copia o banner do upload para as pastas locais se estiver no Windows local
    $userUpload = 'C:/Users/Win11/.gemini/antigravity/brain/7d996493-41d0-4511-82cd-d833505ab93b/.user_uploaded/media_1790018657820.jpg';
    if (file_exists($userUpload)) {
        if (!is_dir(__DIR__ . '/images')) @mkdir(__DIR__ . '/images', 0777, true);
        if (!is_dir(__DIR__ . '/uploads/banners')) @mkdir(__DIR__ . '/uploads/banners', 0777, true);
        @copy($userUpload, __DIR__ . '/images/banner-principal.jpg');
        @copy($userUpload, __DIR__ . '/uploads/banners/banner-principal.jpg');
        echo "Arquivo de imagem copiado para as pastas locais com sucesso!<br>\n";
    }
    
    echo "<br><strong>Sucesso! Banco de dados e imagens sincronizados com sucesso.</strong><br>\n";
} catch (Exception $e) {
    echo "Erro ao atualizar: " . $e->getMessage() . "<br>\n";
}
