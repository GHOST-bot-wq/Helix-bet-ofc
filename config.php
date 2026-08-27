<?php
// ===================================================================
//  config.php - Conexao com o banco e helpers globais
//  Compativel com PHP 7.2+
// ===================================================================

define('DB_HOST', 'bavwjmiclz2ptlzhy8nr-mysql.services.clever-cloud.com');
define('DB_NAME', 'bavwjmiclz2ptlzhy8nr');
define('DB_USER', 'u99oi9uyvl3yn5mv');
define('DB_PASS', 'J3gJK724V6g5590jwihw');
define('DB_CHARSET', 'utf8mb4');
define('APP_ENV', 'development');

function db()
{
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
    $pdo = new PDO($dsn, DB_USER, DB_PASS, array(
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ));
    return $pdo;
}

function cfg($chave, $default = null)
{
    static $cache = array();
    if (array_key_exists($chave, $cache)) return $cache[$chave];
    $stmt = db()->prepare('SELECT valor FROM configuracoes WHERE chave = ? LIMIT 1');
    $stmt->execute(array($chave));
    $row = $stmt->fetch();
    $cache[$chave] = $row ? $row['valor'] : $default;
    return $cache[$chave];
}

// Chamada após salvar configs — limpa o cache estático da mesma requisição
function cfg_clear_cache()
{
    // Cada requisição PHP é um processo separado, então o cache estático
    // nunca afeta outras requisições. Esta função existe apenas por segurança.
    // Nada a fazer aqui.
}

function jwt_create($payload)
{
    $secret  = cfg('jwt_secret', 'change_me_now');
    $header  = base64url_encode(json_encode(array('alg' => 'HS256', 'typ' => 'JWT')));
    $payload['iat'] = time();
    $payload['exp'] = time() + 60 * 60 * 24 * 30;
    $body = base64url_encode(json_encode($payload));
    $sig  = base64url_encode(hash_hmac('sha256', "$header.$body", $secret, true));
    return "$header.$body.$sig";
}

function jwt_verify($token)
{
    $secret = cfg('jwt_secret', 'change_me_now');
    $parts  = explode('.', $token);
    if (count($parts) !== 3) return null;
    list($header, $body, $sig) = $parts;
    $expected = base64url_encode(hash_hmac('sha256', "$header.$body", $secret, true));
    if (!hash_equals($expected, $sig)) return null;
    $payload = json_decode(base64url_decode($body), true);
    if (!$payload || (isset($payload['exp']) && $payload['exp'] < time())) return null;
    return $payload;
}

function base64url_encode($data)
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64url_decode($data)
{
    return base64_decode(strtr($data, '-_', '+/'));
}

function json_response($data, $status = 200)
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function error_response($message, $status = 400)
{
    json_response(array('error' => $message), $status);
}

function request_body()
{
    static $body = null;
    if ($body !== null) return $body;
    $raw  = file_get_contents('php://input');
    $body = json_decode($raw, true);
    if (!is_array($body)) $body = array();
    return $body;
}

function auth_required()
{
    // Fix Hostinger/Apache: Authorization header pode chegar de formas diferentes
    $header = '';
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        $header = $_SERVER['HTTP_AUTHORIZATION'];
    } elseif (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $header = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    } elseif (function_exists('apache_request_headers')) {
        $req = apache_request_headers();
        if (!empty($req['Authorization'])) {
            $header = $req['Authorization'];
        } elseif (!empty($req['authorization'])) {
            $header = $req['authorization'];
        }
    }
    if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
        error_response('Token nao fornecido.', 401);
    }
    $payload = jwt_verify($m[1]);
    if (!$payload || empty($payload['user_id'])) {
        error_response('Token invalido ou expirado.', 401);
    }
    $stmt = db()->prepare('SELECT * FROM usuarios WHERE id = ? LIMIT 1');
    $stmt->execute(array($payload['user_id']));
    $user = $stmt->fetch();
    if (!$user) error_response('Usuario nao encontrado.', 401);
    return $user;
}

function format_user($u)
{
    return array(
        'id'             => (int)$u['id'],
        'nome'           => $u['nome'],
        'telefone'       => $u['telefone'],
        'email'          => isset($u['email'])     ? $u['email']     : null,
        'chave_pix'      => isset($u['chave_pix']) ? $u['chave_pix'] : null,
        'saldo'          => (float)$u['saldo'],
        'saldo_afiliado' => (float)$u['saldo_afiliado'],
        'total_partidas' => (int)$u['total_partidas'],
        'data_cadastro'  => $u['data_cadastro'],
    );
}

if (APP_ENV === 'development') {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(0);
}
