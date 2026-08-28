<?php
header('Content-Type: text/plain');
echo "=== SERVER VARIABLES ===\n";
print_r($_SERVER);

echo "\n=== DATABASE CONNECTION TEST ===\n";
try {
    require_once __DIR__ . '/../config.php';
    $db = db();
    echo "Database connected successfully!\n";
    
    $stmt = $db->query("SELECT COUNT(*) FROM configuracoes");
    echo "Config count: " . $stmt->fetchColumn() . "\n";
} catch (Exception $e) {
    echo "Database error: " . $e->getMessage() . "\n";
}
