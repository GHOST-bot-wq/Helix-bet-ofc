<?php
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// Se o arquivo solicitado existir fisicamente, deixa o PHP servi-lo diretamente (CSS, JS, imagens, etc.)
if ($uri !== '/' && file_exists(__DIR__ . $uri) && !is_dir(__DIR__ . $uri)) {
    return false;
}

// Roteamento de APIs administrativas
if (strpos($uri, '/api/admin') === 0) {
    $_SERVER['SCRIPT_NAME'] = '/api/admin.php';
    // Ajusta a URI se necessário para as rotas internas do arquivo PHP
    include __DIR__ . '/api/admin.php';
} 
// Roteamento de APIs públicas e do usuário
elseif (strpos($uri, '/api') === 0) {
    $_SERVER['SCRIPT_NAME'] = '/api/index.php';
    include __DIR__ . '/api/index.php';
} 
// Fallback para o index.html da SPA
else {
    include __DIR__ . '/index.html';
}
