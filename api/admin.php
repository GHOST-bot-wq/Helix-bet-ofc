<?php
// ===================================================================
//  admin/api.php — API exclusiva do painel admin
//  Compativel com PHP 7.2+
// ===================================================================

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../rollover_helper.php';
require_once __DIR__ . '/../includes/facebook_pixel.php';
require_once __DIR__ . '/../includes/afiliado_regra.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$requestUri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';

// Suporte para ler a URL original na Vercel caso ocorra reescrita interna
if (!empty($_SERVER['HTTP_X_VERCEL_FORWARDED_URL'])) {
    $requestUri = $_SERVER['HTTP_X_VERCEL_FORWARDED_URL'];
} elseif (!empty($_SERVER['HTTP_X_NOW_ROUTE_SOURCE'])) {
    $requestUri = $_SERVER['HTTP_X_NOW_ROUTE_SOURCE'];
}

$path       = parse_url($requestUri, PHP_URL_PATH);

// Remove qualquer prefixo conhecido como /admin/api.php, /api/admin.php, /admin, ou /api
$prefixes = ['/admin/api.php', '/api/admin.php', '/admin', '/api'];
foreach ($prefixes as $prefix) {
    if (strpos($path, $prefix) === 0) {
        $path = substr($path, strlen($prefix));
    }
}
$path       = '/' . trim($path, '/');
$method     = strtoupper($_SERVER['REQUEST_METHOD']);

try {
    admin_route($method, $path);
} catch (PDOException $e) {
    error_response('Erro de banco: ' . $e->getMessage(), 500);
} catch (Exception $e) {
    error_response($e->getMessage(), 500);
}

// ===================================================================
//  ROTAS
// ===================================================================
function admin_route($method, $path)
{
    // Auth (sem token)
    if ($method === 'POST' && $path === '/login') { adm_login(); return; }

    // Todas as demais requerem token admin
    $admin = admin_required();

    // Dashboard
    if ($method === 'GET' && $path === '/dashboard') { adm_dashboard($admin); return; }

    // Usuários
    if ($method === 'GET'  && $path === '/usuarios')                              { adm_usuarios_list($admin); return; }
    if ($method === 'GET'  && preg_match('#^/usuarios/(\d+)$#', $path, $m))      { adm_usuario_get($admin, (int)$m[1]); return; }
    if ($method === 'PUT'  && preg_match('#^/usuarios/(\d+)$#', $path, $m))      { adm_usuario_update($admin, (int)$m[1]); return; }
    if ($method === 'POST' && preg_match('#^/usuarios/(\d+)/saldo$#', $path, $m)){ adm_usuario_saldo($admin, (int)$m[1]); return; }
    if ($method === 'POST' && preg_match('#^/usuarios/(\d+)/bloquear$#',$path,$m)){ adm_usuario_bloquear($admin, (int)$m[1]); return; }
    if ($method === 'DELETE'&& preg_match('#^/usuarios/(\d+)$#', $path, $m))     { adm_usuario_delete($admin, (int)$m[1]); return; }

    // Transações / saques
    if ($method === 'GET'  && $path === '/transacoes')                                    { adm_transacoes($admin); return; }
    if ($method === 'GET'  && $path === '/saques-pendentes')                              { adm_saques_pendentes($admin); return; }
    if ($method === 'POST' && preg_match('#^/transacoes/(\d+)/aprovar$#', $path, $m))    { adm_tx_aprovar($admin, (int)$m[1]); return; }
    if ($method === 'POST' && preg_match('#^/transacoes/(\d+)/rejeitar$#', $path, $m))   { adm_tx_rejeitar($admin, (int)$m[1]); return; }
    if ($method === 'POST' && preg_match('#^/depositos/(\d+)/confirmar$#', $path, $m))   { adm_dep_confirmar($admin, (int)$m[1]); return; }

    // Cupons
    if ($method === 'GET'  && $path === '/cupons')                              { adm_cupons_list($admin); return; }
    if ($method === 'POST' && $path === '/cupons')                              { adm_cupom_create($admin); return; }
    if ($method === 'PUT'  && preg_match('#^/cupons/(\d+)$#', $path, $m))      { adm_cupom_update($admin, (int)$m[1]); return; }
    if ($method === 'DELETE'&& preg_match('#^/cupons/(\d+)$#', $path, $m))     { adm_cupom_delete($admin, (int)$m[1]); return; }

    // Upload de imagem
    if ($method === 'POST' && $path === '/upload')     { adm_upload($admin); return; }

    // Configurações
    if ($method === 'GET'  && $path === '/configs')    { adm_configs_get($admin); return; }
    if ($method === 'POST' && $path === '/configs')    { adm_configs_save($admin); return; }

    // Partidas
    if ($method === 'GET'  && $path === '/partidas')   { adm_partidas($admin); return; }

    // Logs
    if ($method === 'GET'  && $path === '/logs')       { adm_logs($admin); return; }

    // Rollover
    if ($method === 'GET'  && $path === '/rollover/lista')                                  { adm_rollover_lista($admin); return; }
    if ($method === 'GET'  && preg_match('#^/rollover/usuario/(\d+)$#', $path, $m))        { adm_rollover_usuario($admin, (int)$m[1]); return; }
    if ($method === 'POST' && preg_match('#^/rollover/(\d+)/cancelar$#', $path, $m))       { adm_rollover_cancelar($admin, (int)$m[1]); return; }
    if ($method === 'POST' && preg_match('#^/rollover/usuarios/(\d+)/criar$#', $path, $m)) { adm_rollover_criar_manual($admin, (int)$m[1]); return; }

    // ── Regras de afiliado ──────────────────────────────────────────
    if ($method === 'GET' && preg_match('#^/afiliado-regra/stats/(\d+)$#', $path, $m))    { adm_afil_regra_stats($admin, (int)$m[1]); return; }
    if ($method === 'GET' && preg_match('#^/afiliado-regra/log/(\d+)$#',   $path, $m))    { adm_afil_regra_log($admin,   (int)$m[1]); return; }
    if ($method === 'POST'&& preg_match('#^/afiliado-regra/resetar/(\d+)$#',$path,$m))    { adm_afil_regra_resetar($admin,(int)$m[1]); return; }

    error_response('Rota admin nao encontrada: ' . $path, 404);
}

// ===================================================================
//  AUTH ADMIN
// ===================================================================
function adm_login()
{
    $b     = request_body();
    $login = isset($b['login']) ? trim($b['login']) : (isset($b['email']) ? trim($b['email']) : '');
    $senha = isset($b['senha']) ? $b['senha'] : '';
    if (!$login || !$senha) error_response('Login e senha obrigatorios.');

    // Aceita email ou telefone
    $stmt = db()->prepare('SELECT * FROM admins WHERE email = ? OR telefone = ? LIMIT 1');
    $stmt->execute(array($login, $login));
    $admin = $stmt->fetch();

    if (!$admin || !password_verify($senha, $admin['senha'])) {
        error_response('Credenciais invalidas.', 401);
    }

    db()->prepare('UPDATE admins SET ultimo_login = NOW() WHERE id = ?')->execute(array($admin['id']));

    $secret  = cfg('admin_jwt_secret', 'ADMIN_SECRET');
    $payload = array('admin_id' => $admin['id'], 'nivel' => $admin['nivel'], 'exp' => time() + 86400 * 7);
    $token   = admin_jwt_create($payload, $secret);

    json_response(array(
        'token' => $token,
        'admin' => array('id' => $admin['id'], 'nome' => $admin['nome'], 'email' => $admin['email'], 'telefone' => $admin['telefone'], 'nivel' => $admin['nivel']),
    ));
}

function admin_required()
{
    $header = '';

    // Tenta todas as variaveis possiveis onde o Apache pode colocar o Authorization
    $candidates = array(
        'HTTP_AUTHORIZATION',
        'REDIRECT_HTTP_AUTHORIZATION',
        'REDIRECT_REDIRECT_HTTP_AUTHORIZATION',
        'HTTP_X_AUTHORIZATION',
    );

    foreach ($candidates as $key) {
        if (!empty($_SERVER[$key])) {
            $header = $_SERVER[$key];
            break;
        }
    }

    // Fallback: apache_request_headers()
    if (!$header && function_exists('apache_request_headers')) {
        $req = apache_request_headers();
        foreach (array('Authorization', 'authorization', 'AUTHORIZATION') as $k) {
            if (!empty($req[$k])) { $header = $req[$k]; break; }
        }
    }

    // Fallback: token via query string (ultimo recurso, menos seguro)
    if (!$header && !empty($_GET['_token'])) {
        $header = 'Bearer ' . $_GET['_token'];
    }

    if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
        error_response('Token admin nao fornecido.', 401);
    }

    $secret  = cfg('admin_jwt_secret', 'ADMIN_SECRET');
    $payload = admin_jwt_verify($m[1], $secret);
    if (!$payload || empty($payload['admin_id'])) error_response('Token admin invalido.', 401);

    $stmt = db()->prepare('SELECT * FROM admins WHERE id = ? LIMIT 1');
    $stmt->execute(array($payload['admin_id']));
    $admin = $stmt->fetch();
    if (!$admin) error_response('Admin nao encontrado.', 401);
    return $admin;
}

function admin_jwt_create($payload, $secret)
{
    $header = base64url_encode(json_encode(array('alg' => 'HS256', 'typ' => 'JWT')));
    $body   = base64url_encode(json_encode($payload));
    $sig    = base64url_encode(hash_hmac('sha256', "$header.$body", $secret, true));
    return "$header.$body.$sig";
}

function admin_jwt_verify($token, $secret)
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    list($header, $body, $sig) = $parts;
    $expected = base64url_encode(hash_hmac('sha256', "$header.$body", $secret, true));
    if (!hash_equals($expected, $sig)) return null;
    $payload = json_decode(base64url_decode($body), true);
    if (!$payload || (isset($payload['exp']) && $payload['exp'] < time())) return null;
    return $payload;
}

function admin_log($adminId, $acao, $detalhes = null)
{
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null;
    db()->prepare('INSERT INTO admin_logs (admin_id, acao, detalhes, ip) VALUES (?, ?, ?, ?)')
       ->execute(array($adminId, $acao, $detalhes, $ip));
}

// ===================================================================
//  DASHBOARD
// ===================================================================
function adm_dashboard($admin)
{
    $db = db();

    $totalUsers   = $db->query('SELECT COUNT(*) FROM usuarios')->fetchColumn();
    $todayUsers   = $db->query('SELECT COUNT(*) FROM usuarios WHERE DATE(data_cadastro) = CURDATE()')->fetchColumn();
    $totalSaldo   = $db->query('SELECT COALESCE(SUM(saldo),0) FROM usuarios')->fetchColumn();
    $depHoje      = $db->query('SELECT COALESCE(SUM(valor),0) FROM transacoes WHERE tipo="deposito" AND status="aprovado" AND DATE(created_at)=CURDATE()')->fetchColumn();
    $depTotal     = $db->query('SELECT COALESCE(SUM(valor),0) FROM transacoes WHERE tipo="deposito" AND status="aprovado"')->fetchColumn();
    $saqPendente  = $db->query('SELECT COUNT(*) FROM transacoes WHERE tipo IN("saque","saque_afiliado") AND status="pendente"')->fetchColumn();
    $saqValPend   = $db->query('SELECT COALESCE(SUM(valor),0) FROM transacoes WHERE tipo IN("saque","saque_afiliado") AND status="pendente"')->fetchColumn();
    $depPendente  = $db->query('SELECT COUNT(*) FROM transacoes WHERE tipo="deposito" AND status="pendente"')->fetchColumn();
    $totalPartidas= $db->query('SELECT COUNT(*) FROM partidas WHERE status="finalizada"')->fetchColumn();
    $partHoje     = $db->query('SELECT COUNT(*) FROM partidas WHERE DATE(created_at)=CURDATE()')->fetchColumn();

    // Grafico depositos 7 dias
    $stmt = $db->query('SELECT DATE(created_at) as dia, COALESCE(SUM(valor),0) as total FROM transacoes WHERE tipo="deposito" AND status="aprovado" AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) GROUP BY DATE(created_at) ORDER BY dia');
    $grafico = $stmt->fetchAll();

    json_response(array(
        'usuarios'       => array('total' => (int)$totalUsers, 'hoje' => (int)$todayUsers),
        'saldo_plataforma'=> (float)$totalSaldo,
        'depositos'      => array('hoje' => (float)$depHoje, 'total' => (float)$depTotal, 'pendentes' => (int)$depPendente),
        'saques'         => array('pendentes' => (int)$saqPendente, 'valor_pendente' => (float)$saqValPend),
        'partidas'       => array('total' => (int)$totalPartidas, 'hoje' => (int)$partHoje),
        'grafico_7dias'  => $grafico,
    ));
}

// ===================================================================
//  USUARIOS
// ===================================================================
function adm_usuarios_list($admin)
{
    $pagina   = isset($_GET['pagina']) ? max(1, (int)$_GET['pagina']) : 1;
    $limite   = isset($_GET['limite']) ? min(100, (int)$_GET['limite']) : 30;
    $busca    = isset($_GET['busca'])  ? trim($_GET['busca']) : '';
    $offset   = ($pagina - 1) * $limite;

    if ($busca) {
        $like = '%' . $busca . '%';
        $stmt = db()->prepare('SELECT u.id, u.nome, u.telefone, u.email, u.saldo, u.saldo_afiliado, u.total_partidas, u.data_cadastro, u.indicado_por, u.dificuldade_individual, u.is_influencer, u.comissao_perc_individual, u.vpp_individual, u.multiplicador_individual, u.killer_chance_override, COALESCE((SELECT SUM(t.valor) FROM transacoes t WHERE t.usuario_id = u.id AND t.tipo = "deposito" AND t.status = "aprovado"), 0) as montante_depositos, COALESCE((SELECT SUM(t2.valor) FROM transacoes t2 INNER JOIN usuarios u2 ON u2.id = t2.usuario_id WHERE u2.indicado_por = u.id AND t2.tipo = "deposito" AND t2.status = "aprovado"), 0) as montante_afiliado FROM usuarios u WHERE u.nome LIKE ? OR u.telefone LIKE ? OR u.email LIKE ? ORDER BY u.data_cadastro DESC LIMIT ? OFFSET ?');
        $stmt->execute(array($like, $like, $like, $limite, $offset));
        $cntStmt = db()->prepare('SELECT COUNT(*) FROM usuarios WHERE nome LIKE ? OR telefone LIKE ? OR email LIKE ?');
        $cntStmt->execute(array($like, $like, $like));
    } else {
        $stmt = db()->prepare('SELECT u.id, u.nome, u.telefone, u.email, u.saldo, u.saldo_afiliado, u.total_partidas, u.data_cadastro, u.indicado_por, u.dificuldade_individual, u.is_influencer, u.comissao_perc_individual, u.vpp_individual, u.multiplicador_individual, u.killer_chance_override, COALESCE((SELECT SUM(t.valor) FROM transacoes t WHERE t.usuario_id = u.id AND t.tipo = "deposito" AND t.status = "aprovado"), 0) as montante_depositos, COALESCE((SELECT SUM(t2.valor) FROM transacoes t2 INNER JOIN usuarios u2 ON u2.id = t2.usuario_id WHERE u2.indicado_por = u.id AND t2.tipo = "deposito" AND t2.status = "aprovado"), 0) as montante_afiliado FROM usuarios u ORDER BY u.data_cadastro DESC LIMIT ? OFFSET ?');
        $stmt->execute(array($limite, $offset));
        $cntStmt = db()->query('SELECT COUNT(*) FROM usuarios');
    }

    $total = (int)$cntStmt->fetchColumn();
    json_response(array(
        'usuarios' => $stmt->fetchAll(),
        'total'    => $total,
        'paginas'  => (int)ceil($total / $limite),
        'pagina'   => $pagina,
    ));
}

function adm_usuario_get($admin, $id)
{
    $stmt = db()->prepare('SELECT * FROM usuarios WHERE id = ? LIMIT 1');
    $stmt->execute(array($id));
    $u = $stmt->fetch();
    if (!$u) error_response('Usuario nao encontrado.', 404);
    unset($u['senha']);

    $stmt2 = db()->prepare('SELECT * FROM transacoes WHERE usuario_id = ? ORDER BY created_at DESC LIMIT 20');
    $stmt2->execute(array($id));
    $txs = $stmt2->fetchAll();

    $stmt3 = db()->prepare('SELECT * FROM partidas WHERE usuario_id = ? ORDER BY created_at DESC LIMIT 20');
    $stmt3->execute(array($id));
    $partidas = $stmt3->fetchAll();

    json_response(array('usuario' => $u, 'transacoes' => $txs, 'partidas' => $partidas));
}

function adm_usuario_update($admin, $id)
{
    $b    = request_body();
    $sets = array(); $vals = array();

    if (isset($b['nome'])   && $b['nome'])   { $sets[] = 'nome = ?';   $vals[] = trim($b['nome']); }
    if (isset($b['email']))                  { $sets[] = 'email = ?';  $vals[] = trim($b['email']); }
    if (isset($b['saldo']))                  { $sets[] = 'saldo = ?';  $vals[] = (float)$b['saldo']; }
    if (isset($b['senha_nova']) && strlen($b['senha_nova']) >= 6) {
        $sets[] = 'senha = ?';
        $vals[] = password_hash($b['senha_nova'], PASSWORD_BCRYPT);
    }

    // ── Configurações individuais de jogo ─────────────────────────────────
    if (array_key_exists('dificuldade_individual', $b)) {
        $dif_val = ($b['dificuldade_individual'] === '' || $b['dificuldade_individual'] === null)
            ? null : $b['dificuldade_individual'];
        $sets[] = 'dificuldade_individual = ?';
        $vals[] = $dif_val;
    }
    if (isset($b['is_influencer'])) {
        $sets[] = 'is_influencer = ?';
        $vals[] = (int)(bool)$b['is_influencer'];
    }
    if (array_key_exists('comissao_perc_individual', $b)) {
        $cp = ($b['comissao_perc_individual'] === '' || $b['comissao_perc_individual'] === null)
            ? null : (float)$b['comissao_perc_individual'];
        $sets[] = 'comissao_perc_individual = ?';
        $vals[] = $cp;
    }
    // vpp_individual: valor fixo por plataforma em R$ (NULL = usa taxa global * entrada)
    if (array_key_exists('vpp_individual', $b)) {
        $vpp = ($b['vpp_individual'] === '' || $b['vpp_individual'] === null || (float)$b['vpp_individual'] <= 0)
            ? null : (float)$b['vpp_individual'];
        $sets[] = 'vpp_individual = ?';
        $vals[] = $vpp;
    }
    // multiplicador_individual: meta = entrada × mult (NULL = usa global)
    if (array_key_exists('multiplicador_individual', $b)) {
        $mi = ($b['multiplicador_individual'] === '' || $b['multiplicador_individual'] === null || (float)$b['multiplicador_individual'] <= 0)
            ? null : (float)$b['multiplicador_individual'];
        $sets[] = 'multiplicador_individual = ?';
        $vals[] = $mi;
    }
    // killer_chance_override: 0.0 = zero killers (tudo rosa), 1.0 = tudo killer, NULL = usa dificuldade
    if (array_key_exists('killer_chance_override', $b)) {
        $kco = ($b['killer_chance_override'] === '' || $b['killer_chance_override'] === null)
            ? null : min(1.0, max(0.0, (float)$b['killer_chance_override']));
        $sets[] = 'killer_chance_override = ?';
        $vals[] = $kco;
    }
    // ── Regra de afiliado individual ────────────────────────────────
    // Formato "X:Y" (ex: "3:1") ou vazio/null para usar a global
    if (array_key_exists('afiliado_regra_personalizada', $b)) {
        $rp  = trim($b['afiliado_regra_personalizada'] ?? '');
        $val = ($rp === '' || $rp === null) ? null : $rp;
        // Valida formato X:Y antes de salvar
        if ($val !== null && !preg_match('/^\d+:\d+$/', $val)) {
            error_response('Formato de regra invalido. Use X:Y (ex: 2:1, 3:1, 5:2).');
        }
        $sets[] = 'afiliado_regra_personalizada = ?';
        $vals[] = $val;
    }

    if (!$sets) error_response('Nenhum campo para atualizar.');
    $vals[] = $id;
    db()->prepare('UPDATE usuarios SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
    admin_log($admin['id'], 'editar_usuario', 'ID: ' . $id . ' campos: ' . implode(', ', $sets));
    json_response(array('ok' => true));
}

function adm_usuario_saldo($admin, $id)
{
    $b      = request_body();
    $valor  = (float)(isset($b['valor'])     ? $b['valor']     : 0);
    $tipo   = isset($b['tipo']) ? $b['tipo'] : 'creditar'; // creditar | debitar
    $motivo = isset($b['motivo']) ? trim($b['motivo']) : 'Ajuste admin';

    if ($valor <= 0) error_response('Valor invalido.');

    $db = db();
    $db->beginTransaction();
    try {
        if ($tipo === 'creditar') {
            $db->prepare('UPDATE usuarios SET saldo = saldo + ? WHERE id = ?')->execute(array($valor, $id));
        } else {
            $db->prepare('UPDATE usuarios SET saldo = GREATEST(0, saldo - ?) WHERE id = ?')->execute(array($valor, $id));
        }
        $db->prepare('INSERT INTO transacoes (usuario_id, tipo, valor, status, descricao) VALUES (?, "ajuste_admin", ?, "aprovado", ?)')
           ->execute(array($id, $valor, $motivo . ' (admin: ' . $admin['nome'] . ')'));
        $db->commit();
    } catch (Exception $e) { $db->rollBack(); error_response('Erro ao ajustar saldo.'); }

    admin_log($admin['id'], $tipo . '_saldo', 'User: ' . $id . ' R$' . $valor . ' motivo: ' . $motivo);
    $stmt = db()->prepare('SELECT saldo FROM usuarios WHERE id = ?');
    $stmt->execute(array($id));
    json_response(array('ok' => true, 'saldo_novo' => (float)$stmt->fetchColumn()));
}

function adm_usuario_bloquear($admin, $id)
{
    // Simples: zera token bloqueando acesso (implementacao futura: campo bloqueado)
    // Por ora, reseta a senha para uma aleatoria inacessivel
    $b   = request_body();
    $acao = isset($b['acao']) ? $b['acao'] : 'bloquear';
    admin_log($admin['id'], $acao . '_usuario', 'User ID: ' . $id);
    json_response(array('ok' => true, 'msg' => 'Acao registrada.'));
}

function adm_usuario_delete($admin, $id)
{
    if ($admin['nivel'] !== 'super') error_response('Sem permissao.', 403);
    db()->prepare('DELETE FROM usuarios WHERE id = ?')->execute(array($id));
    admin_log($admin['id'], 'deletar_usuario', 'User ID: ' . $id);
    json_response(array('ok' => true));
}

// ===================================================================
//  TRANSACOES
// ===================================================================
function adm_transacoes($admin)
{
    $pagina = isset($_GET['pagina']) ? max(1,(int)$_GET['pagina']) : 1;
    $limite = isset($_GET['limite']) ? min(100,(int)$_GET['limite']) : 30;
    $tipo   = isset($_GET['tipo'])   ? $_GET['tipo']   : '';
    $status = isset($_GET['status']) ? $_GET['status'] : '';
    $offset = ($pagina - 1) * $limite;

    $where = array('1=1'); $vals = array();
    if ($tipo)   { $where[] = 't.tipo = ?';   $vals[] = $tipo; }
    if ($status) { $where[] = 't.status = ?'; $vals[] = $status; }

    $w = implode(' AND ', $where);
    $stmt = db()->prepare("SELECT t.*, u.nome as usuario_nome, u.telefone as usuario_tel FROM transacoes t LEFT JOIN usuarios u ON u.id = t.usuario_id WHERE $w ORDER BY t.created_at DESC LIMIT ? OFFSET ?");
    $vals[] = $limite; $vals[] = $offset;
    $stmt->execute($vals);

    $cntVals = array_slice($vals, 0, -2);
    $cnt = db()->prepare("SELECT COUNT(*) FROM transacoes t WHERE $w");
    $cnt->execute($cntVals);

    json_response(array(
        'transacoes' => $stmt->fetchAll(),
        'total'      => (int)$cnt->fetchColumn(),
        'pagina'     => $pagina,
    ));
}

function adm_saques_pendentes($admin)
{
    $stmt = db()->query("SELECT t.*, u.nome as usuario_nome, u.telefone as usuario_tel, u.chave_pix as usuario_pix FROM transacoes t LEFT JOIN usuarios u ON u.id = t.usuario_id WHERE t.tipo IN('saque','saque_afiliado') AND t.status='pendente' ORDER BY t.created_at ASC");
    json_response(array('saques' => $stmt->fetchAll()));
}

function adm_tx_aprovar($admin, $id)
{
    $stmt = db()->prepare('SELECT * FROM transacoes WHERE id = ? LIMIT 1');
    $stmt->execute(array($id));
    $tx = $stmt->fetch();
    if (!$tx) error_response('Transacao nao encontrada.', 404);
    if ($tx['status'] !== 'pendente') error_response('Transacao ja processada.');

    db()->prepare('UPDATE transacoes SET status = "aprovado", updated_at = NOW() WHERE id = ?')->execute(array($id));

    // Se for depósito, credita saldo
    if ($tx['tipo'] === 'deposito') {
        db()->prepare('UPDATE usuarios SET saldo = saldo + ? WHERE id = ?')->execute(array($tx['valor'], $tx['usuario_id']));

        // ── Comissão de indicação (com filtro de regra de afiliado) ────
        $stmt2 = db()->prepare('SELECT indicado_por FROM usuarios WHERE id = ?');
        $stmt2->execute(array($tx['usuario_id']));
        $row = $stmt2->fetch();
        if ($row && $row['indicado_por']) {
            $afiliadorId  = (int)$row['indicado_por'];
            $convidadoId  = (int)$tx['usuario_id'];

            // ── Aplica regra no momento do DEPÓSITO ─────────────────
            // Classifica o convidado se ainda não foi classificado no cadastro
            $momento = cfg('afiliado_regra_momento', 'deposito');
            if (cfg('afiliado_regra_ativo', '0') === '1' && $momento === 'deposito') {
                afil_classificar_convidado($afiliadorId, $convidadoId, 'deposito');
            }

            // ── Verifica se este convidado está IGNORADO ─────────────
            $ignorado = afil_convidado_ignorado($afiliadorId, $convidadoId);

            if (!$ignorado) {
                // Convidado CONTADO → paga comissão normalmente
                $stmtPerc = db()->prepare('SELECT comissao_perc_individual FROM usuarios WHERE id = ?');
                $stmtPerc->execute(array($afiliadorId));
                $rowPerc = $stmtPerc->fetch();
                $percIndividual = ($rowPerc && $rowPerc['comissao_perc_individual'] !== null)
                    ? (float)$rowPerc['comissao_perc_individual']
                    : null;
                $perc = $percIndividual !== null ? $percIndividual : (float)cfg('comissao_nivel1_perc', 10);
                if ($perc > 0) {
                    $comissao = round($tx['valor'] * $perc / 100, 2);
                    db()->prepare('UPDATE usuarios SET saldo_afiliado = saldo_afiliado + ?, total_comissao = total_comissao + ? WHERE id = ?')
                       ->execute(array($comissao, $comissao, $afiliadorId));
                    db()->prepare('INSERT INTO transacoes (usuario_id, tipo, valor, status, referencia, descricao) VALUES (?, "bonus_indicacao", ?, "aprovado", ?, "Comissao de indicacao")')
                       ->execute(array($afiliadorId, $comissao, $convidadoId));
                    db()->prepare('UPDATE usuarios SET bonus_pago = 1 WHERE id = ?')->execute(array($convidadoId));
                }
            }
            // Convidado IGNORADO → não paga comissão, bonus_pago permanece 0
        }

        // ── Criar rollover se sistema estiver ativo ──────────────────
        rollover_criar($tx['usuario_id'], $tx['id'], (float)$tx['valor']);

        // ── Facebook Pixel: Purchase (Conversions API) ───────────────
        if (fb_pixel_ativo()) {
            fb_event_purchase(
                (float)$tx['valor'],
                (int)$tx['usuario_id'],
                (string)$tx['id']
            );
        }
    }

    admin_log($admin['id'], 'aprovar_tx', 'TX ID: ' . $id . ' tipo: ' . $tx['tipo'] . ' valor: ' . $tx['valor']);
    json_response(array('ok' => true));
}

function adm_tx_rejeitar($admin, $id)
{
    $stmt = db()->prepare('SELECT * FROM transacoes WHERE id = ? LIMIT 1');
    $stmt->execute(array($id));
    $tx = $stmt->fetch();
    if (!$tx) error_response('Transacao nao encontrada.', 404);
    if ($tx['status'] !== 'pendente') error_response('Transacao ja processada.');

    $db = db();
    $db->beginTransaction();
    try {
        $db->prepare('UPDATE transacoes SET status = "rejeitado", updated_at = NOW() WHERE id = ?')->execute(array($id));

        // Se for saque rejeitado, devolve saldo
        if ($tx['tipo'] === 'saque') {
            $db->prepare('UPDATE usuarios SET saldo = saldo + ? WHERE id = ?')->execute(array($tx['valor'], $tx['usuario_id']));
        } elseif ($tx['tipo'] === 'saque_afiliado') {
            $db->prepare('UPDATE usuarios SET saldo_afiliado = saldo_afiliado + ? WHERE id = ?')->execute(array($tx['valor'], $tx['usuario_id']));
        }
        $db->commit();
    } catch (Exception $e) { $db->rollBack(); error_response('Erro ao rejeitar.'); }

    admin_log($admin['id'], 'rejeitar_tx', 'TX ID: ' . $id);
    json_response(array('ok' => true));
}

function adm_dep_confirmar($admin, $id)
{
    // Alias para aprovar
    adm_tx_aprovar($admin, $id);
}

// ===================================================================
//  CUPONS
// ===================================================================
function adm_cupons_list($admin)
{
    $stmt = db()->query('SELECT c.*, (SELECT COUNT(*) FROM cupons_resgates WHERE cupom_id = c.id) as total_resgates FROM cupons c ORDER BY c.created_at DESC');
    json_response(array('cupons' => $stmt->fetchAll()));
}

function adm_cupom_create($admin)
{
    $b      = request_body();
    $codigo = strtoupper(trim(isset($b['codigo']) ? $b['codigo'] : ''));
    $tipo   = isset($b['tipo'])    ? $b['tipo']    : 'saldo';
    $valor  = (float)(isset($b['valor']) ? $b['valor'] : 0);
    $usosMax= (int)(isset($b['usos_max']) ? $b['usos_max'] : 1);
    $expira = isset($b['expira_em']) && $b['expira_em'] ? $b['expira_em'] : null;

    if (!$codigo) error_response('Codigo obrigatorio.');
    if ($valor <= 0) error_response('Valor invalido.');

    try {
        $stmt = db()->prepare('INSERT INTO cupons (codigo, tipo, valor, usos_max, expira_em) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute(array($codigo, $tipo, $valor, $usosMax, $expira));
        $id = (int)db()->lastInsertId();
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) error_response('Codigo ja existe.');
        throw $e;
    }

    admin_log($admin['id'], 'criar_cupom', $codigo . ' R$' . $valor);
    json_response(array('ok' => true, 'id' => $id), 201);
}

function adm_cupom_update($admin, $id)
{
    $b = request_body();
    $sets = array(); $vals = array();
    if (isset($b['ativo']))    { $sets[] = 'ativo = ?';    $vals[] = (int)(bool)$b['ativo']; }
    if (isset($b['valor']))    { $sets[] = 'valor = ?';    $vals[] = (float)$b['valor']; }
    if (isset($b['usos_max'])) { $sets[] = 'usos_max = ?'; $vals[] = (int)$b['usos_max']; }
    if (isset($b['expira_em'])){ $sets[] = 'expira_em = ?';$vals[] = $b['expira_em'] ?: null; }
    if (!$sets) error_response('Nada para atualizar.');
    $vals[] = $id;
    db()->prepare('UPDATE cupons SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
    json_response(array('ok' => true));
}

function adm_cupom_delete($admin, $id)
{
    db()->prepare('DELETE FROM cupons WHERE id = ?')->execute(array($id));
    admin_log($admin['id'], 'deletar_cupom', 'ID: ' . $id);
    json_response(array('ok' => true));
}

// ===================================================================
//  CONFIGURACOES
// ===================================================================
function adm_upload($admin)
{
    if (empty($_FILES['file'])) {
        error_response('Nenhum arquivo enviado', 400);
    }

    $file    = $_FILES['file'];
    $allowed = ['image/jpeg','image/png','image/gif','image/webp'];

    if (!in_array($file['type'], $allowed)) {
        error_response('Tipo de arquivo nao permitido. Use JPG, PNG, GIF ou WEBP.', 400);
    }

    if ($file['size'] > 5 * 1024 * 1024) {
        error_response('Arquivo muito grande. Limite: 5MB.', 400);
    }

    $dir = __DIR__ . '/../uploads/banners/';
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0755, true)) {
            error_response('Erro: nao foi possivel criar a pasta uploads/banners/. Crie manualmente via FTP e defina permissao 755.', 500);
        }
    }

    $writable = false;
    $test_file = $dir . 'test_perm_' . uniqid() . '.txt';
    $err_msg = '';
    if (@file_put_contents($test_file, '1') !== false) {
        $writable = true;
        @unlink($test_file);
    } else {
        $last_err = error_get_last();
        if ($last_err) {
            $err_msg = ' Detalhes do PHP: ' . $last_err['message'];
        }
    }

    if (!$writable) {
        // Fallback para Vercel/Serverless: se a pasta nao for gravavel, envia para o Catbox.moe
        $catbox_url = upload_to_catbox($file['tmp_name'], $file['name'], $file['type']);
        if ($catbox_url) {
            json_response(['url' => $catbox_url, 'filename' => basename($catbox_url)]);
        }
        error_response('Erro: pasta uploads/banners/ sem permissao de escrita.' . $err_msg . ' Acesse o FTP e defina permissao 755 nessa pasta.', 500);
    }

    $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $filename = 'banner_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dest     = $dir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        error_response('Erro ao salvar arquivo', 500);
    }

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host     = $_SERVER['HTTP_HOST'];
    $url      = $protocol . '://' . $host . '/uploads/banners/' . $filename;

    json_response(['url' => $url, 'filename' => $filename]);
}

function upload_to_catbox($file_path, $file_name, $file_type)
{
    if (!function_exists('curl_init')) {
        return null;
    }
    $cfile = new CURLFile($file_path, $file_type, $file_name);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://catbox.moe/user/api.php');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, array(
        'reqtype' => 'fileupload',
        'fileToUpload' => $cfile
    ));
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    $response = curl_exec($ch);
    curl_close($ch);

    if ($response && strpos($response, 'https://files.catbox.moe/') === 0) {
        return trim($response);
    }
    return null;
}

function adm_configs_get($admin)
{
    $stmt = db()->query('SELECT chave, valor FROM configuracoes ORDER BY chave');
    $rows = $stmt->fetchAll();
    $cfg  = array();
    foreach ($rows as $r) $cfg[$r['chave']] = $r['valor'];

    // Garante que chaves essenciais sempre existem (evita campos em branco no admin)
    $defaults = array(
        'site_nome'               => 'HelixWin',
        'site_descricao'          => '',
        'site_promo'              => '',
        'site_logo_url'           => '',
        'site_favicon_url'        => '',
        'site_suporte'            => '',
        'pix_chave'               => '',
        'pix_nome_beneficiario'   => 'HelixWin',
        // Gateway PIX
        'gateway_ativo'           => 'akadpay',
        'pixup_client_id'         => '',
        'pixup_client_secret'     => '',
        'pixup_url'               => 'https://api.pixupbr.com',
        'pixup_webhook'           => '',
        'pixup_webhook_secret'    => '',
        // Royal Banking
        'royal_client_id'         => '',
        'royal_client_secret'     => '',
        'royal_url'               => 'https://api.royalbanking.com.br',
        'royal_webhook'           => '',
        'royal_webhook_secret'    => '',
        // VizzionPay
        'vizzion_client_id'       => '',
        'vizzion_client_secret'   => '',
        'vizzion_url'             => 'https://app.vizzionpay.com.br/api/v1',
        'vizzion_webhook'         => '',
        // AmploPay
        'amplo_client_id'         => '',
        'amplo_client_secret'     => '',
        'amplo_url'               => 'https://api.amplopay.com',
        'amplo_webhook'           => '',
        // GGPIX (chaves mantem prefixo akadpay_ por compatibilidade)
        'akadpay_token'           => '',
        'akadpay_secret'          => '',
        'akadpay_url'             => 'https://ggpixapi.com/api/v1',
        'akadpay_webhook_deposito'=> '',
        'akadpay_webhook_saque'   => '',
        // Deposito
        'deposito_minimo'         => '10',
        'deposito_maximo'         => '0',
        'saque_minimo'            => '20',
        'saque_maximo'            => '0',
        'saque_afiliado_minimo'   => '10',
        'bonus_deposito_perc'     => '0',
        'bonus_deposito_minimo'   => '0',
        'bonus_deposito_maximo'   => '0',
        'multiplicador'           => '7',
        'taxa_por_plataforma'     => '0.10',
        'dificuldade'             => 'normal',
        'entrada_valores_rapidos' => '[1,2,5,10,20,50]',
        'dep_presets'             => '[{"valor":5,"label":"","estilo":""},{"valor":10,"label":"","estilo":""},{"valor":25,"label":"","estilo":""},{"valor":50,"label":"QUERIDO","estilo":"destaque"},{"valor":100,"label":"RECOMENDADO","estilo":"popular"},{"valor":200,"label":"+CHANCES","estilo":"bonus"}]',
        'comissao_nivel1_perc'    => '10',
        'comissao_nivel2_perc'    => '0',
        'show_montante'           => '1',
        'show_comissao_banner'    => '1',
        'show_saldo_afiliado'     => '1',
        'show_botao_sacar_afil'   => '1',
        'show_link_afiliado'      => '1',
        'show_stats_indicados'    => '1',
        'show_lista_indicados'    => '1',
        'show_valor_comissao_lista'=> '1',
        'manutencao'              => '0',
        'registro_aberto'         => '1',
        'demo_mode'               => '1',
        'jwt_secret'              => '',
        'admin_jwt_secret'        => '',
        'banner_url'              => '',
        'banner_link'             => '',
        'banner_1_url'            => '',
        'banner_1_link'           => '',
        'banner_2_url'            => '',
        'banner_2_link'           => '',
        'banner_3_url'            => '',
        'banner_3_link'           => '',
        'banner_4_url'            => '',
        'banner_4_link'           => '',
        'banner_5_url'            => '',
        'banner_5_link'           => '',
        'cor_bg'                  => '#0a0612',
        'cor_bg2'                 => '#110d1e',
        'cor_bg3'                 => '#1a1430',
        'cor_purple'              => '#7c3aed',
        'cor_purple2'             => '#9d5cff',
        'cor_purple3'             => '#c084fc',
        'cor_pink'                => '#ff6b9d',
        'cor_pink2'               => '#f472b6',
        'cor_green'               => '#00e87a',
        'cor_green2'              => '#00c968',
        'cor_red'                 => '#ff4d6d',
        'cor_yellow'              => '#fbbf24',
        'cor_text'                => '#f0e8ff',
        // Rollover
        'rollover_ativo'          => '0',
        'rollover_tipo'           => 'multiplicador',
        'rollover_multiplicador'  => '1',
        'rollover_valor_fixo'     => '0',
        'rollover_aplica_bonus'   => '0',
        // Facebook Pixel + Conversions API
        'facebook_pixel_ativo'    => '0',
        'facebook_pixel_id'       => '',
        'facebook_access_token'   => '',
        'facebook_test_event_code'=> '',
        // ── Sistema de regra de afiliados ────────────────────────────
        'afiliado_regra_ativo'    => '0',      // 0 = desligado, 1 = ligado
        'afiliado_regra_padrao'   => '2:1',    // X:Y — a cada X contados, Y ignorados
        'afiliado_regra_momento'  => 'deposito', // 'cadastro' | 'deposito'
    );
    foreach ($defaults as $k => $v) {
        if (!array_key_exists($k, $cfg)) $cfg[$k] = $v;
    }

    json_response(array('configs' => $cfg));
}

function adm_configs_save($admin)
{
    $b = request_body();

    // Aceita tanto { configs: {...} } quanto {...} direto
    $configs = isset($b['configs']) && is_array($b['configs']) ? $b['configs'] : $b;

    if (empty($configs)) {
        error_response('Nenhuma configuracao recebida.', 400);
    }

    // Chaves protegidas que nao podem ser alteradas por operador
    $protegidas = array('jwt_secret', 'admin_jwt_secret');
    if ($admin['nivel'] !== 'super') {
        foreach ($protegidas as $p) unset($configs[$p]);
    }

    $saved  = array();
    $skipped = array();
    $stmt = db()->prepare('INSERT INTO configuracoes (chave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = ?');

    foreach ($configs as $chave => $valor) {
        // Sanitiza: só aceita chaves com letras minúsculas, números e underscore
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $chave)) {
            $skipped[] = $chave;
            continue;
        }
        $valorStr = (string)$valor;
        $stmt->execute(array($chave, $valorStr, $valorStr));
        $saved[$chave] = $valorStr;
    }

    cfg_clear_cache();

    admin_log($admin['id'], 'salvar_configs', implode(', ', array_keys($saved)));

    json_response(array(
        'ok'      => true,
        'saved'   => count($saved),
        'skipped' => $skipped,
        'configs' => $saved,
    ));
}

// ===================================================================
//  PARTIDAS
// ===================================================================
function adm_partidas($admin)
{
    $pagina = isset($_GET['pagina']) ? max(1,(int)$_GET['pagina']) : 1;
    $limite = isset($_GET['limite']) ? min(100,(int)$_GET['limite']) : 30;
    $offset = ($pagina - 1) * $limite;

    $stmt = db()->prepare('SELECT p.*, u.nome as usuario_nome FROM partidas p LEFT JOIN usuarios u ON u.id = p.usuario_id ORDER BY p.created_at DESC LIMIT ? OFFSET ?');
    $stmt->execute(array($limite, $offset));
    $total = (int)db()->query('SELECT COUNT(*) FROM partidas')->fetchColumn();
    json_response(array('partidas' => $stmt->fetchAll(), 'total' => $total, 'pagina' => $pagina));
}

// ===================================================================
//  LOGS
// ===================================================================
function adm_logs($admin)
{
    $stmt = db()->query('SELECT l.*, a.nome as admin_nome FROM admin_logs l LEFT JOIN admins a ON a.id = l.admin_id ORDER BY l.created_at DESC LIMIT 100');
    json_response(array('logs' => $stmt->fetchAll()));
}

// ===================================================================
//  ROLLOVER (ADMIN)
// ===================================================================

/** Lista todos os rollovers com filtros */
function adm_rollover_lista($admin)
{
    $status  = isset($_GET['status'])  ? $_GET['status']  : '';
    $pagina  = isset($_GET['pagina'])  ? max(1, (int)$_GET['pagina']) : 1;
    $limite  = isset($_GET['limite'])  ? min(100, (int)$_GET['limite']) : 30;
    $offset  = ($pagina - 1) * $limite;

    $where = array('1=1'); $vals = array();
    if ($status) { $where[] = 'r.status = ?'; $vals[] = $status; }

    $w = implode(' AND ', $where);
    $stmt = db()->prepare(
        "SELECT r.*, u.nome as usuario_nome, u.telefone as usuario_tel
         FROM rollovers r
         LEFT JOIN usuarios u ON u.id = r.usuario_id
         WHERE $w
         ORDER BY r.created_at DESC
         LIMIT ? OFFSET ?"
    );
    $vals[] = $limite; $vals[] = $offset;
    $stmt->execute($vals);
    $rows = $stmt->fetchAll();

    // Adiciona percentual
    foreach ($rows as &$r) {
        $r['percentual'] = (float)$r['valor_exigido'] > 0
            ? min(100, round(((float)$r['valor_apostado'] / (float)$r['valor_exigido']) * 100, 1))
            : 100;
    }
    unset($r);

    $cntVals = array_slice($vals, 0, -2);
    $cnt = db()->prepare("SELECT COUNT(*) FROM rollovers r WHERE $w");
    $cnt->execute($cntVals);

    json_response(array(
        'rollovers' => $rows,
        'total'     => (int)$cnt->fetchColumn(),
        'pagina'    => $pagina,
    ));
}

/** Rollovers de um usuário específico */
function adm_rollover_usuario($admin, $userId)
{
    $stmt = db()->prepare(
        'SELECT r.*, u.nome as usuario_nome
         FROM rollovers r
         LEFT JOIN usuarios u ON u.id = r.usuario_id
         WHERE r.usuario_id = ?
         ORDER BY r.created_at DESC'
    );
    $stmt->execute(array($userId));
    $rows = $stmt->fetchAll();

    foreach ($rows as &$r) {
        $r['percentual'] = (float)$r['valor_exigido'] > 0
            ? min(100, round(((float)$r['valor_apostado'] / (float)$r['valor_exigido']) * 100, 1))
            : 100;
    }
    unset($r);

    json_response(array('rollovers' => $rows));
}

/** Cancela um rollover */
function adm_rollover_cancelar($admin, $rollId)
{
    $stmt = db()->prepare('SELECT * FROM rollovers WHERE id = ? LIMIT 1');
    $stmt->execute(array($rollId));
    $roll = $stmt->fetch();
    if (!$roll) error_response('Rollover nao encontrado.', 404);
    if ($roll['status'] !== 'ativo') error_response('Rollover ja encerrado.');

    db()->prepare('UPDATE rollovers SET status = "cancelado", completed_at = NOW() WHERE id = ?')
       ->execute(array($rollId));

    admin_log($admin['id'], 'cancelar_rollover', 'ID rollover: ' . $rollId . ' usuario: ' . $roll['usuario_id']);
    json_response(array('ok' => true));
}

/** Cria um rollover manualmente para qualquer usuário */
function adm_rollover_criar_manual($admin, $userId)
{
    $b       = request_body();
    $tipo    = isset($b['tipo']) ? $b['tipo'] : 'multiplicador';
    $mult    = (float)(isset($b['multiplicador']) ? $b['multiplicador'] : 0);
    $fixo    = (float)(isset($b['valor_fixo'])    ? $b['valor_fixo']    : 0);
    $deposit = (float)(isset($b['valor_deposito'])? $b['valor_deposito']: 0);

    if ($deposit <= 0) error_response('valor_deposito obrigatorio.');

    if ($tipo === 'multiplicador') {
        if ($mult <= 0) error_response('multiplicador invalido.');
        $exigido  = round($deposit * $mult, 2);
        $multSalvo = $mult; $fixoSalvo = null;
    } else {
        if ($fixo <= 0) error_response('valor_fixo invalido.');
        $exigido   = $fixo;
        $multSalvo = null; $fixoSalvo = $fixo;
    }

    // Cria uma transação fictícia de depósito para referenciar
    db()->prepare(
        'INSERT INTO rollovers
            (usuario_id, transacao_id, valor_deposito, tipo_rollover,
             multiplicador, valor_fixo, valor_exigido, valor_apostado, status)
         VALUES (?, 0, ?, ?, ?, ?, ?, 0, "ativo")'
    )->execute(array($userId, $deposit, $tipo, $multSalvo, $fixoSalvo, $exigido));

    $rollId = (int)db()->lastInsertId();
    admin_log($admin['id'], 'criar_rollover_manual', 'User: ' . $userId . ' exigido: R$' . $exigido);
    json_response(array('ok' => true, 'rollover_id' => $rollId, 'valor_exigido' => $exigido), 201);
}

// ===================================================================
//  ADMIN — SISTEMA DE REGRAS DE AFILIADO
// ===================================================================

/**
 * GET /afiliado-regra/stats/{id}
 * Retorna estatísticas da regra para um afiliador específico.
 */
function adm_afil_regra_stats($admin, $afiliadorId)
{
    $stmt = db()->prepare('SELECT id, nome FROM usuarios WHERE id = ? LIMIT 1');
    $stmt->execute(array($afiliadorId));
    $user = $stmt->fetch();
    if (!$user) error_response('Usuario nao encontrado.', 404);

    $stats = afil_stats($afiliadorId);

    json_response(array(
        'usuario_id'   => $afiliadorId,
        'usuario_nome' => $user['nome'],
        'stats'        => $stats,
    ));
}

/**
 * GET /afiliado-regra/log/{id}
 * Lista o log completo de classificações de um afiliador.
 */
function adm_afil_regra_log($admin, $afiliadorId)
{
    $stmt = db()->prepare(
        'SELECT arl.*, u.nome as convidado_nome
         FROM afiliado_regra_log arl
         LEFT JOIN usuarios u ON u.id = arl.convidado_id
         WHERE arl.afiliador_id = ?
         ORDER BY arl.posicao ASC'
    );
    $stmt->execute(array($afiliadorId));
    $log = $stmt->fetchAll();

    json_response(array(
        'afiliador_id' => $afiliadorId,
        'total'        => count($log),
        'log'          => $log,
    ));
}

/**
 * POST /afiliado-regra/resetar/{id}
 * Remove todos os registros de classificação de um afiliador
 * (permite reclassificar do zero com nova regra).
 */
function adm_afil_regra_resetar($admin, $afiliadorId)
{
    $stmt = db()->prepare('DELETE FROM afiliado_regra_log WHERE afiliador_id = ?');
    $stmt->execute(array($afiliadorId));
    $deletados = $stmt->rowCount();

    admin_log($admin['id'], 'resetar_afil_regra', 'Afiliador ID: ' . $afiliadorId . ' registros removidos: ' . $deletados);
    json_response(array('ok' => true, 'registros_removidos' => $deletados));
}
