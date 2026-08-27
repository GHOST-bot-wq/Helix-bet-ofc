<?php
// ===================================================================
//  api/index.php - Roteador principal da API REST
//  Compativel com PHP 7.2+
// ===================================================================

error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED & ~E_WARNING);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../rollover_helper.php';
require_once __DIR__ . '/../includes/afiliado_regra.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$requestUri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';

// Suporte para ler a URL original na Vercel caso ocorra reescrita interna
if (!empty($_SERVER['HTTP_X_VERCEL_FORWARDED_URL'])) {
    $requestUri = $_SERVER['HTTP_X_VERCEL_FORWARDED_URL'];
} elseif (!empty($_SERVER['HTTP_X_NOW_ROUTE_SOURCE'])) {
    $requestUri = $_SERVER['HTTP_X_NOW_ROUTE_SOURCE'];
}

$scriptPath = dirname($_SERVER['SCRIPT_NAME']); // ex: /api
$path       = parse_url($requestUri, PHP_URL_PATH);

// Remove o prefixo /api se presente (Hostinger às vezes passa a URL completa)
$path = '/' . trim(str_replace($scriptPath, '', $path), '/');
// Segurança extra: remove /api/ do começo caso o htaccess não tenha feito o strip
if (strpos($path, '/api/') === 0) {
    $path = substr($path, 4); // remove '/api'
}
if ($path === '' || $path === '/api') $path = '/';

$method = strtoupper($_SERVER['REQUEST_METHOD']);

try {
    // Rotas públicas nunca bloqueadas (sem verificação de manutenção)
    $rotasPublicas = array('/public/config', '/auth/login', '/auth/register');

    // Modo manutenção — bloqueia tudo exceto rotas públicas
    if (!in_array($path, $rotasPublicas)) {
        if (cfg('manutencao', '0') === '1') {
            error_response('Plataforma em manutencao. Tente novamente em breve.', 503);
        }
    }

    route($method, $path);
} catch (PDOException $e) {
    if (defined('APP_ENV') && APP_ENV === 'development') {
        error_response('Erro de Banco: ' . $e->getMessage(), 500);
    } else {
        error_response('Erro interno de banco de dados.', 500);
    }
} catch (Exception $e) {
    if (APP_ENV === 'development') {
        error_response($e->getMessage() . ' em ' . $e->getFile() . ':' . $e->getLine(), 500);
    }
    error_response('Erro interno do servidor.', 500);
}

// ===================================================================
function route($method, $path)
{
    if ($method === 'POST' && $path === '/auth/login')    { auth_login();    return; }
    if ($method === 'POST' && $path === '/auth/register') { auth_register(); return; }
    if ($method === 'GET'  && $path === '/auth/me')       { auth_me();       return; }

    if ($method === 'GET' && $path === '/user/dashboard')     { user_dashboard();     return; }
    if ($method === 'PUT' && $path === '/user/pix')           { user_salvar_pix();    return; }
    if ($method === 'PUT' && $path === '/user/senha')         { user_alterar_senha(); return; }
    if ($method === 'GET' && $path === '/user/deposito-info') { user_deposito_info(); return; }
    if ($method === 'GET' && $path === '/user/suporte')       { user_suporte();       return; }

    if ($method === 'GET'  && $path === '/game/configs')   { game_configs();   return; }
    if ($method === 'POST' && $path === '/game/iniciar')   { game_iniciar();   return; }
    if ($method === 'POST' && $path === '/game/finalizar') { game_finalizar(); return; }

    if ($method === 'POST' && $path === '/financeiro/deposito') {
        fin_deposito(); return;
    }
    if ($method === 'GET' && preg_match('#^/financeiro/deposito/status/([^/]+)$#', $path, $m)) {
        fin_deposito_status($m[1]); return;
    }
    if ($method === 'POST' && $path === '/financeiro/saque') {
        fin_saque(); return;
    }
    if ($method === 'POST' && $path === '/financeiro/saque-afiliado') {
        fin_saque_afil(); return;
    }
    if ($method === 'GET' && $path === '/financeiro/meus-saques') {
        fin_meus_saques(); return;
    }
    if ($method === 'GET' && strpos($path, '/financeiro/historico') === 0) {
        fin_historico(); return;
    }

    if ($method === 'GET' && $path === '/indicacao/info') { indicacao_info(); return; }

    if ($method === 'POST' && $path === '/cupons/validar')  { cupom_validar();  return; }
    if ($method === 'POST' && $path === '/cupons/resgatar') { cupom_resgatar(); return; }

    if ($method === 'GET' && $path === '/public/config') { public_config(); return; }

    // Rollover
    if ($method === 'GET' && $path === '/rollover/progresso') { rollover_progresso_endpoint(); return; }

    error_response('Rota nao encontrada.', 404);
}

// ===================================================================
//  AUTH
// ===================================================================

function auth_login()
{
    $body     = request_body();
    $telefone = isset($body['telefone']) ? trim($body['telefone']) : '';
    $senha    = isset($body['senha'])    ? $body['senha']          : '';

    if (!$telefone || !$senha) {
        error_response('Telefone e senha sao obrigatorios.');
    }

    $telefone = preg_replace('/\D/', '', $telefone);

    $stmt = db()->prepare('SELECT * FROM usuarios WHERE telefone = ? LIMIT 1');
    $stmt->execute(array($telefone));
    $user = $stmt->fetch();

    if (!$user || !password_verify($senha, $user['senha'])) {
        error_response('Telefone ou senha incorretos.', 401);
    }

    $token = jwt_create(array('user_id' => $user['id']));
    json_response(array('token' => $token, 'user' => format_user($user)));
}

function auth_register()
{
    if (cfg('registro_aberto', '1') !== '1') {
        error_response('O cadastro de novos usuarios esta temporariamente fechado.', 403);
    }
    $body   = request_body();
    $nome   = isset($body['nome'])     ? trim($body['nome'])     : '';
    $telRaw = isset($body['telefone']) ? $body['telefone']       : '';
    $email  = isset($body['email'])    ? trim($body['email'])    : '';
    $senha  = isset($body['senha'])    ? $body['senha']          : '';
    $ref    = isset($body['codigo_indicacao']) ? trim($body['codigo_indicacao']) : '';

    $telRaw = preg_replace('/\D/', '', $telRaw);
    if (!$email) $email = null;
    if (!$ref)   $ref   = null;

    if (!$nome)               error_response('Nome e obrigatorio.');
    if (strlen($telRaw) < 10) error_response('Telefone invalido.');
    if (strlen($senha) < 6)   error_response('Senha deve ter pelo menos 6 caracteres.');

    $stmt = db()->prepare('SELECT id FROM usuarios WHERE telefone = ? LIMIT 1');
    $stmt->execute(array($telRaw));
    if ($stmt->fetch()) {
        error_response('Telefone ja cadastrado.');
    }

    $indicadoPor = null;
    if ($ref) {
        $refId = preg_replace('/\D/', '', $ref);
        $stmt  = db()->prepare('SELECT id FROM usuarios WHERE id = ? OR telefone = ? LIMIT 1');
        $stmt->execute(array($refId ? (int)$refId : 0, $refId));
        $refUser = $stmt->fetch();
        if ($refUser) {
            $indicadoPor = (int)$refUser['id'];
        }
    }

    $hash = password_hash($senha, PASSWORD_BCRYPT);

    $stmt = db()->prepare('INSERT INTO usuarios (nome, telefone, email, senha, indicado_por) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute(array($nome, $telRaw, $email, $hash, $indicadoPor));
    $userId = (int)db()->lastInsertId();

    // ── Regra de afiliado no CADASTRO ────────────────────────────────
    // Classifica o convidado imediatamente se o momento configurado for 'cadastro'
    if ($indicadoPor && cfg('afiliado_regra_ativo', '0') === '1' && cfg('afiliado_regra_momento', 'deposito') === 'cadastro') {
        afil_classificar_convidado($indicadoPor, $userId, 'cadastro');
    }

    $stmt = db()->prepare('SELECT * FROM usuarios WHERE id = ? LIMIT 1');
    $stmt->execute(array($userId));
    $user = $stmt->fetch();

    $token = jwt_create(array('user_id' => $userId));
    json_response(array('token' => $token, 'user' => format_user($user)), 201);
}

function auth_me()
{
    $user = auth_required();
    json_response(array('user' => format_user($user)));
}

// ===================================================================
//  USER
// ===================================================================

function user_dashboard()
{
    $user = auth_required();

    // Rollover ativo?
    $rvPendente = rollover_pendente($user['id']);
    $rolloversInfo = $rvPendente ? array(
        'ativo'          => true,
        'valor_exigido'  => (float)$rvPendente['valor_exigido'],
        'valor_apostado' => (float)$rvPendente['valor_apostado'],
        'valor_restante' => (float)$rvPendente['valor_restante'],
        'percentual'     => (float)$rvPendente['valor_exigido'] > 0
            ? min(100, round(((float)$rvPendente['valor_apostado'] / (float)$rvPendente['valor_exigido']) * 100, 1))
            : 100,
    ) : array('ativo' => false);

    json_response(array(
        'saldo'          => (float)$user['saldo'],
        'saldo_afiliado' => (float)$user['saldo_afiliado'],
        'total_partidas' => (int)$user['total_partidas'],
        'total_comissao' => (float)$user['total_comissao'],
        'nome'           => $user['nome'],
        'email'          => isset($user['email']) ? $user['email'] : null,
        'rollover'       => $rolloversInfo,
    ));
}

function user_salvar_pix()
{
    $user = auth_required();
    $body = request_body();
    $pix  = isset($body['chave_pix']) ? trim($body['chave_pix']) : '';
    if (!$pix) error_response('Chave PIX invalida.');

    db()->prepare('UPDATE usuarios SET chave_pix = ? WHERE id = ?')
       ->execute(array($pix, $user['id']));
    json_response(array('ok' => true, 'chave_pix' => $pix));
}

function user_alterar_senha()
{
    $user  = auth_required();
    $body  = request_body();
    $atual = isset($body['senha_atual']) ? $body['senha_atual'] : '';
    $nova  = isset($body['senha_nova'])  ? $body['senha_nova']  : '';

    if (!password_verify($atual, $user['senha'])) {
        error_response('Senha atual incorreta.', 401);
    }
    if (strlen($nova) < 6) {
        error_response('Nova senha deve ter pelo menos 6 caracteres.');
    }

    $hash = password_hash($nova, PASSWORD_BCRYPT);
    db()->prepare('UPDATE usuarios SET senha = ? WHERE id = ?')
       ->execute(array($hash, $user['id']));
    json_response(array('ok' => true));
}

function user_deposito_info()
{
    auth_required();

    $bonusPerc = (float)cfg('bonus_deposito_perc',   0);
    $bonusMin  = (float)cfg('bonus_deposito_minimo', 0);
    $bonusMax  = (float)cfg('bonus_deposito_maximo', 0);

    $valoresRaw = cfg('entrada_valores_rapidos', '[10,20,50,100]');
    $valores    = json_decode($valoresRaw, true);
    if (!is_array($valores)) {
        $valores = array(10, 20, 50, 100);
    }

    $presetsRaw = cfg('dep_presets', '[]');
    $presets    = json_decode($presetsRaw, true);
    if (!is_array($presets)) $presets = array();

    json_response(array(
        'temDireito'      => $bonusPerc > 0,
        'perc'            => $bonusPerc,
        'minimo'          => $bonusMin,
        'maximo'          => $bonusMax,
        'valores_rapidos' => $valores,
        'dep_presets'     => $presets,
        'limites'         => array(
            'deposito_minimo'       => (float)cfg('deposito_minimo',       10),
            'deposito_maximo'       => (float)cfg('deposito_maximo',        0),
            'saque_minimo'          => (float)cfg('saque_minimo',          20),
            'saque_maximo'          => (float)cfg('saque_maximo',           0),
            'saque_afiliado_minimo' => (float)cfg('saque_afiliado_minimo', 10),
            'saque_afiliado_maximo' => (float)cfg('saque_afiliado_maximo',  0),
        ),
    ));
}

function user_suporte()
{
    auth_required();
    $links = json_decode(cfg('suporte_links', '[]'), true);
    if (!is_array($links)) $links = array();
    json_response(array('links' => $links));
}

// ===================================================================
//  GAME
// ===================================================================

function game_configs()
{
    auth_required();

    $valoresRaw = cfg('entrada_valores_rapidos', '[1,2,5,10,20,50]');
    $valores    = json_decode($valoresRaw, true);
    if (!is_array($valores)) {
        $valores = array(1, 2, 5, 10, 20, 50);
    }

    json_response(array(
        'multiplicador'           => (float)cfg('multiplicador',       7),
        'taxa_por_plataforma'     => (float)cfg('taxa_por_plataforma', 0.1),
        'dificuldade'             => cfg('dificuldade', 'normal'),
        'entrada_valores_rapidos' => $valores,
    ));
}

function game_iniciar()
{
    $user  = auth_required();
    $body  = request_body();
    $valor = (float)(isset($body['valor_entrada'])      ? $body['valor_entrada']      : 0);

    // Multiplicador: individual do usuário sobrepõe global e sobrepõe o enviado pelo front
    $mult_global     = (float)cfg('multiplicador', 7);
    $mult_individual = (isset($user['multiplicador_individual']) && $user['multiplicador_individual'] !== null && (float)$user['multiplicador_individual'] > 0)
        ? (float)$user['multiplicador_individual'] : null;
    $mult = $mult_individual !== null
        ? $mult_individual
        : (float)(isset($body['multiplicador_meta']) ? $body['multiplicador_meta'] : $mult_global);

    if ($valor <= 0) error_response('Valor de entrada invalido.');
    if ((float)$user['saldo'] < $valor) error_response('Saldo insuficiente.');

    // ── Calcular tudo ANTES da transação ──────────────────────────────────
    $taxa = (float)cfg('taxa_por_plataforma', 0.1);

    // Dificuldade individual ou global
    $dif_global     = cfg('dificuldade', 'normal');
    $dif_individual = (isset($user['dificuldade_individual']) && $user['dificuldade_individual'] !== null && $user['dificuldade_individual'] !== '')
        ? $user['dificuldade_individual'] : null;
    $dificuldade_base = $dif_individual !== null ? $dif_individual : $dif_global;

    // Modo Influencer → envia preset influencer_<base> para o jogo
    $is_influencer = !empty($user['is_influencer']) ? 1 : 0;
    if ($is_influencer) {
        $dif_map = array(
            'super_facil'   => 'influencer_super_facil',
            'facil'         => 'influencer_facil',
            'normal'        => 'influencer_normal',
            'dificil'       => 'influencer_dificil',
            'super_dificil' => 'influencer_super_dificil',
            'impossivel'    => 'influencer_super_dificil',
        );
        $dificuldade = isset($dif_map[$dificuldade_base]) ? $dif_map[$dificuldade_base] : 'influencer_normal';
    } else {
        $dificuldade = $dificuldade_base;
    }

    // Valor por plataforma: individual fixo (R$) ou taxa × entrada
    $vpp_individual = (isset($user['vpp_individual']) && $user['vpp_individual'] !== null && (float)$user['vpp_individual'] > 0)
        ? (float)$user['vpp_individual'] : null;
    $valor_por_plataforma = $vpp_individual !== null ? $vpp_individual : round($valor * $taxa, 4);

    // Killer chance override: 0 = tudo rosa, 1 = tudo killer, null = usa dificuldade
    // Usar array_key_exists (não isset) pois isset() retorna false para valores null E para chave inexistente
    // Verificar explicitamente se coluna existe e tem valor não-nulo
    $kco = null;
    if (array_key_exists('killer_chance_override', $user) && $user['killer_chance_override'] !== null && $user['killer_chance_override'] !== '') {
        $kco = (float)$user['killer_chance_override'];
    }

    $db = db();
    $db->beginTransaction();
    try {
        $db->prepare('UPDATE usuarios SET saldo = saldo - ? WHERE id = ? AND saldo >= ?')
           ->execute(array($valor, $user['id'], $valor));

        $stmt = $db->prepare('INSERT INTO partidas (usuario_id, valor_entrada, multiplicador_meta, valor_meta, valor_por_plataforma) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute(array($user['id'], $valor, $mult, $valor * $mult, $valor_por_plataforma));
        $partidaId = (int)$db->lastInsertId();

        $db->prepare('INSERT INTO transacoes (usuario_id, tipo, valor, status, referencia, descricao) VALUES (?, "perda_partida", ?, "aprovado", ?, "Entrada na partida")')
           ->execute(array($user['id'], $valor, $partidaId));

        $db->commit();

        // ── Registra aposta no rollover (fora da transação principal) ───
        rollover_registrar_aposta($user['id'], $valor);
    } catch (Exception $e) {
        $db->rollBack();
        error_response('Erro ao iniciar partida.', 500);
    }

    $stmt = db()->prepare('SELECT saldo FROM usuarios WHERE id = ?');
    $stmt->execute(array($user['id']));
    $saldoAtual = (float)$stmt->fetchColumn();

    json_response(array(
        'partida_id'              => $partidaId,
        'valor_entrada'           => $valor,
        'valor_meta'              => $valor * $mult,
        'valor_por_plataforma'    => $valor_por_plataforma,
        'multiplicador_meta'      => $mult,
        'dificuldade'             => $dificuldade,
        'is_influencer'           => $is_influencer,
        'killer_chance_override'  => $kco,
        'saldo_atual'             => $saldoAtual,
        'multiplicador_individual'=> $mult_individual,
    ));
}

function game_finalizar()
{
    $user      = auth_required();
    $body      = request_body();
    $partidaId = (int)(isset($body['partida_id'])           ? $body['partida_id']           : 0);
    $plats     = (int)(isset($body['plataformas_passadas']) ? $body['plataformas_passadas'] : 0);
    $resgatou  = !empty($body['resgatou']);

    $stmt = db()->prepare('SELECT * FROM partidas WHERE id = ? AND usuario_id = ? AND status = "em_andamento" LIMIT 1');
    $stmt->execute(array($partidaId, $user['id']));
    $partida = $stmt->fetch();
    if (!$partida) error_response('Partida nao encontrada.');

    $taxa    = (float)cfg('taxa_por_plataforma', 0.1);
    $entrada = (float)$partida['valor_entrada'];

    // Usa o vpp salvo na partida (pode ser individual ou global calculated no inicio)
    // Fallback para compatibilidade com partidas antigas (antes da coluna existir)
    $vpp_salvo = (isset($partida['valor_por_plataforma']) && (float)$partida['valor_por_plataforma'] > 0)
        ? (float)$partida['valor_por_plataforma']
        : ($entrada * $taxa);

    $ganho = ($resgatou && $plats > 0) ? round($plats * $vpp_salvo, 2) : 0.0;

    $db = db();
    $db->beginTransaction();
    try {
        if ($resgatou && $ganho > 0) {
            $db->prepare('UPDATE usuarios SET saldo = saldo + ?, total_partidas = total_partidas + 1 WHERE id = ?')
               ->execute(array($ganho, $user['id']));
            $db->prepare('INSERT INTO transacoes (usuario_id, tipo, valor, status, referencia, descricao) VALUES (?, "ganho_partida", ?, "aprovado", ?, "Resgate da partida")')
               ->execute(array($user['id'], $ganho, $partidaId));
        } else {
            $db->prepare('UPDATE usuarios SET total_partidas = total_partidas + 1 WHERE id = ?')
               ->execute(array($user['id']));
        }

        $db->prepare('UPDATE partidas SET status = "finalizada", plataformas_passadas = ?, valor_acumulado = ?, resgatou = ? WHERE id = ?')
           ->execute(array($plats, $ganho, (int)$resgatou, $partidaId));

        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        error_response('Erro ao finalizar partida.', 500);
    }

    $stmt = db()->prepare('SELECT saldo FROM usuarios WHERE id = ?');
    $stmt->execute(array($user['id']));
    $saldo = (float)$stmt->fetchColumn();

    json_response(array(
        'ok'          => true,
        'ganhou'      => $resgatou,
        'valor_ganho' => $ganho,
        'saldo_atual' => $saldo,
    ));
}

// ===================================================================
//  FINANCEIRO
// ===================================================================

function fin_deposito()
{
    $user  = auth_required();
    $body  = request_body();
    $valor = (float)(isset($body['valor']) ? $body['valor'] : 0);
    $cpf   = preg_replace('/\D/', '', isset($body['cpf']) ? $body['cpf'] : '');

    $depMin = (float)cfg('deposito_minimo', 10);
    $depMax = (float)cfg('deposito_maximo', 0);

    if ($valor < $depMin) error_response('Valor minimo de deposito: R$ ' . number_format($depMin, 2, ',', '.'));
    if ($depMax > 0 && $valor > $depMax) error_response('Valor maximo de deposito: R$ ' . number_format($depMax, 2, ',', '.'));

    $txid = uniqid('tx', true);

    // ── Gateway ativo: pixup | royal | expfypay | vizzionpay | manual ──
    $gateway = cfg('gateway_ativo', 'manual');

    if ($gateway === 'royal') {
        $result    = royal_criar_cobranca($valor, $txid, $cpf);
        $txidFinal = isset($result['txid']) ? $result['txid'] : $txid;

        $stmt = db()->prepare('INSERT INTO transacoes (usuario_id, tipo, valor, status, referencia, descricao, cpf) VALUES (?, "deposito", ?, "pendente", ?, "Deposito via PIX (Royal Banking)", ?)');
        $stmt->execute(array($user['id'], $valor, $txidFinal, $cpf));

        json_response(array(
            'txid'              => $txidFinal,
            'qrcode_texto'      => $result['qrcode_texto'],
            'qrcode_imagem'     => $result['qrcode_imagem'],
            'expiracao_minutos' => isset($result['expiracao_minutos']) ? $result['expiracao_minutos'] : 30,
            'valor'             => $valor,
        ));
    }

    if ($gateway === 'pixup') {
        $result    = pixup_criar_cobranca($valor, $txid, $cpf);
        $txidFinal = isset($result['txid']) ? $result['txid'] : $txid;

        $stmt = db()->prepare('INSERT INTO transacoes (usuario_id, tipo, valor, status, referencia, descricao, cpf) VALUES (?, "deposito", ?, "pendente", ?, "Deposito via PIX (PixUp)", ?)');
        $stmt->execute(array($user['id'], $valor, $txidFinal, $cpf));

        json_response(array(
            'txid'              => $txidFinal,
            'qrcode_texto'      => $result['qrcode_texto'],
            'qrcode_imagem'     => $result['qrcode_imagem'],
            'expiracao_minutos' => isset($result['expiracao_minutos']) ? $result['expiracao_minutos'] : 30,
            'valor'             => $valor,
        ));
    }

    if ($gateway === 'expfypay') {
        $result    = expfypay_criar_cobranca($valor, $txid, $cpf);
        $txidFinal = isset($result['txid']) ? $result['txid'] : $txid;

        $stmt = db()->prepare('INSERT INTO transacoes (usuario_id, tipo, valor, status, referencia, descricao, cpf) VALUES (?, "deposito", ?, "pendente", ?, "Deposito via PIX (ExpfyPay)", ?)');
        $stmt->execute(array($user['id'], $valor, $txidFinal, $cpf));

        json_response(array(
            'txid'              => $txidFinal,
            'qrcode_texto'      => $result['qrcode_texto'],
            'qrcode_imagem'     => $result['qrcode_imagem'],
            'expiracao_minutos' => isset($result['expiracao_minutos']) ? $result['expiracao_minutos'] : 30,
            'valor'             => $valor,
        ));
    }

   if ($gateway === 'vizzionpay') {
    // txidClean é o identifier enviado à VizzionPay (sem ponto/caracteres especiais)
    // O webhook devolve exatamente esse valor no campo "identifier"
    $txidClean   = preg_replace('/[^A-Za-z0-9_\-]/', '', $txid);
    $result      = vizzionpay_criar_cobranca($valor, $txidClean, $cpf, $user);
    $txidVizzion = isset($result['txid']) ? $result['txid'] : $txidClean;

    $descricao = 'Deposito via PIX (VizzionPay)';
    if ($txidVizzion && $txidVizzion !== $txidClean) {
        $descricao .= '|vz:' . $txidVizzion;
    }

    // Salva txidClean como referencia — é exatamente o que a VizzionPay devolve no webhook
    $stmt = db()->prepare('INSERT INTO transacoes (usuario_id, tipo, valor, status, referencia, descricao, cpf) VALUES (?, "deposito", ?, "pendente", ?, ?, ?)');
    $stmt->execute(array($user['id'], $valor, $txidClean, $descricao, $cpf));

    json_response(array(
        'txid'              => $txidClean,
        'qrcode_texto'      => $result['qrcode_texto'],
        'qrcode_imagem'     => $result['qrcode_imagem'],
        'expiracao_minutos' => isset($result['expiracao_minutos']) ? $result['expiracao_minutos'] : 30,
        'valor'             => $valor,
    ));
}

    if ($gateway === 'amplopay') {
	        $result    = amplopay_criar_cobranca($valor, $txid, $cpf, $user);
	        $txidFinal = isset($result['txid']) ? $result['txid'] : $txid;

        $stmt = db()->prepare('INSERT INTO transacoes (usuario_id, tipo, valor, status, referencia, descricao, cpf) VALUES (?, "deposito", ?, "pendente", ?, "Deposito via PIX (AmploPay)", ?)');
        $stmt->execute(array($user['id'], $valor, $txidFinal, $cpf));

        json_response(array(
            'txid'              => $txidFinal,
            'qrcode_texto'      => $result['qrcode_texto'],
            'qrcode_imagem'     => $result['qrcode_imagem'],
            'expiracao_minutos' => isset($result['expiracao_minutos']) ? $result['expiracao_minutos'] : 30,
            'valor'             => $valor,
        ));
    }

    if ($gateway === 'akadpay') {
        $result    = akadpay_criar_cobranca($valor, $txid, $cpf, $user);
        $txidFinal = isset($result['txid']) ? $result['txid'] : $txid;

        $stmt = db()->prepare('INSERT INTO transacoes (usuario_id, tipo, valor, status, referencia, descricao, cpf) VALUES (?, "deposito", ?, "pendente", ?, "Deposito via PIX (GGPIX)", ?)');
        $stmt->execute(array($user['id'], $valor, $txidFinal, $cpf));

        json_response(array(
            'txid'              => $txidFinal,
            'qrcode_texto'      => $result['qrcode_texto'],
            'qrcode_imagem'     => $result['qrcode_imagem'],
            'expiracao_minutos' => isset($result['expiracao_minutos']) ? $result['expiracao_minutos'] : 30,
            'valor'             => $valor,
        ));
    }

    // ── Gateway manual (geração local do payload PIX) ───────────────
    $pixChave = cfg('pix_chave', '');
    $pixNome  = cfg('pix_nome_beneficiario', 'HelixWin');

    $pixPayload = gerar_payload_pix($pixChave, $pixNome, $valor, $txid);
    $qrCodeUrl  = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . urlencode($pixPayload);

    $stmt = db()->prepare('INSERT INTO transacoes (usuario_id, tipo, valor, status, referencia, descricao, cpf) VALUES (?, "deposito", ?, "pendente", ?, "Deposito via PIX", ?)');
    $stmt->execute(array($user['id'], $valor, $txid, $cpf));

    json_response(array(
        'txid'              => $txid,
        'qrcode_texto'      => $pixPayload,
        'qrcode_imagem'     => $qrCodeUrl,
        'expiracao_minutos' => 30,
        'valor'             => $valor,
    ));
}

// ===================================================================
//  ROYAL BANKING — Integração com o gateway
//  Ref: https://web.royalbanking.com.br/documentacao/cashin.php
//  Auth: api-key enviada diretamente no body JSON (sem OAuth/Bearer)
//  Endpoint: POST https://api.royalbanking.com.br/v1/gateway/
//
//  Request body:
//  {
//    "api-key": "SUA_API_KEY",
//    "amount": 100,
//    "client": { "name":"...", "document":"...", "telefone":"...", "email":"..." },
//    "callbackUrl": "https://seusite.com/api/royalbanking_webhook.php"
//  }
//
//  Response (200 OK):
//  {
//    "status": "success",
//    "message": "ok",
//    "paymentCode": "00020101...",           ← pix copia e cola
//    "idTransaction": "52fc5262-...",        ← ID da transação Royal
//    "paymentCodeBase64": "iVBORw0KGgo..."   ← QR Code em base64
//  }
// ===================================================================

/**
 * Cria uma cobrança PIX via Royal Banking e retorna qrcode_texto + qrcode_imagem.
 */
function royal_criar_cobranca($valor, $txidLocal, $cpf)
{
    $apiKey     = cfg('royal_client_id', '');   // campo royal_client_id = api-key da Royal
    $baseUrl    = rtrim(cfg('royal_url', 'https://api.royalbanking.com.br'), '/');
    $webhookUrl = cfg('royal_webhook', '');

    if (!$apiKey) {
        error_response('Royal Banking nao configurado. Informe a API Key no painel admin.', 503);
    }

    // ── Monta o payload conforme documentação oficial ─────────────────
    $payload = array(
        'api-key' => $apiKey,
        'amount'  => (int)round($valor), // Royal Banking aceita valor inteiro em reais
        'client'  => array(
            'name'     => 'Cliente',
            'document' => ($cpf && strlen($cpf) === 11) ? $cpf : '00000000000',
            'telefone' => '11999999999', // obrigatório pela API — fallback genérico
            'email'    => 'cliente@plataforma.com', // obrigatório pela API — fallback genérico
        ),
    );

    if ($webhookUrl) {
        $payload['callbackUrl'] = $webhookUrl;
    }

    // ── POST para /v1/gateway/ ────────────────────────────────────────
    $resp = pixup_http('POST', $baseUrl . '/v1/gateway/', json_encode($payload), array(
        'Content-Type: application/json',
        'Accept: application/json',
    ));

    if (!$resp || empty($resp['body'])) {
        error_response('Falha ao conectar com Royal Banking.', 503);
    }

    $data = json_decode($resp['body'], true);

    // Verifica erro HTTP ou status != success
    if (!empty($resp['status']) && $resp['status'] >= 400) {
        $msg = isset($data['message']) ? $data['message']
             : (isset($data['error']) ? $data['error'] : 'Erro ao gerar cobranca Royal Banking.');
        error_response('Royal Banking: ' . $msg, 503);
    }

    if (empty($data['status']) || $data['status'] !== 'success') {
        $msg = isset($data['message']) ? $data['message'] : 'Royal Banking retornou status inesperado.';
        error_response('Royal Banking: ' . $msg, 503);
    }

    // ── Extrai os campos da resposta ──────────────────────────────────
    // paymentCode      → pix copia e cola (texto)
    // paymentCodeBase64→ QR Code em base64 PNG
    // idTransaction    → ID da transação na Royal Banking
    $qrcodeTxt  = isset($data['paymentCode'])       ? $data['paymentCode']       : '';
    $qrcodeB64  = isset($data['paymentCodeBase64']) ? $data['paymentCodeBase64'] : '';
    $txidRoyal  = isset($data['idTransaction'])     ? (string)$data['idTransaction'] : $txidLocal;

    // Monta URL de imagem: se veio base64, usa data URI; senão fallback qrserver
    if ($qrcodeB64) {
        $qrcodeImg = 'data:image/png;base64,' . $qrcodeB64;
    } elseif ($qrcodeTxt) {
        $qrcodeImg = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . urlencode($qrcodeTxt);
    } else {
        $qrcodeImg = '';
    }

    if (!$qrcodeTxt) {
        error_response('Royal Banking nao retornou o codigo PIX. Tente novamente.', 503);
    }

    return array(
        'txid'              => $txidRoyal,
        'qrcode_texto'      => $qrcodeTxt,
        'qrcode_imagem'     => $qrcodeImg,
        'expiracao_minutos' => 30,
    );
}

// ===================================================================
//  PIXUP — Integração com o gateway
// ===================================================================

/**
 * Cria uma cobrança PIX via PixUp e retorna qrcode_texto + qrcode_imagem.
 * Lança Exception em caso de erro para o catch em fin_deposito().
 */
function pixup_criar_cobranca($valor, $txidLocal, $cpf)
{
    $clientId     = cfg('pixup_client_id',     '');
    $clientSecret = cfg('pixup_client_secret', '');
    // URL correta conforme documentação: https://api.pixupbr.com (com "br" no domínio)
    $baseUrl      = rtrim(cfg('pixup_url', 'https://api.pixupbr.com'), '/');

    if (!$clientId || !$clientSecret) {
        error_response('Gateway PIX nao configurado. Contate o suporte.', 503);
    }

    // ── 1. Obtém o access_token via Basic Auth ────────────────────────
    // A PixUp usa HTTP Basic Authentication: base64(client_id:client_secret)
    // Ref: https://pixup.readme.io/reference/criar-token-de-acesso
    $tokenUrl       = $baseUrl . '/v2/oauth/token';
    $basicAuth      = base64_encode($clientId . ':' . $clientSecret);

    $tokenResp = pixup_http('POST', $tokenUrl, '', array(
        'Authorization: Basic ' . $basicAuth,
        'Content-Type: application/json',
    ));

    if (!$tokenResp || empty($tokenResp['body'])) {
        error_response('Falha ao conectar com o gateway de pagamento.', 503);
    }

    $tokenData = json_decode($tokenResp['body'], true);
    if (empty($tokenData['access_token'])) {
        $msg = isset($tokenData['message']) ? $tokenData['message'] : 'Autenticacao com gateway falhou. Verifique as credenciais.';
        error_response($msg, 503);
    }
    $accessToken = $tokenData['access_token'];

    // ── 2. Gera o QR Code PIX ─────────────────────────────────────────
    // Endpoint: POST /v2/pix/qrcode
    // Ref: https://pixup.readme.io/reference/create-qrcode
    $webhookUrl  = cfg('pixup_webhook', '');
    $txidClean   = preg_replace('/[^A-Za-z0-9]/', '', $txidLocal);

    $qrPayload = array(
        'amount'      => number_format($valor, 2, '.', ''),
        'external_id' => $txidClean,
        'payer'       => array(
            'name'     => 'Cliente',
            'document' => $cpf && strlen($cpf) === 11 ? $cpf : '00000000000',
        ),
        'split'       => array(
            array(
                'username'        => '',
                'percentageSplit' => '10'
            )
        )
    );

    // Webhook de confirmação por transação (postbackUrl)
    if ($webhookUrl) {
        $qrPayload['postbackUrl'] = $webhookUrl;
    }

    $qrResp = pixup_http('POST', $baseUrl . '/v2/pix/qrcode', json_encode($qrPayload), array(
        'Content-Type: application/json',
        'Authorization: Bearer ' . $accessToken,
    ));

    if (!$qrResp || empty($qrResp['body'])) {
        error_response('Falha ao gerar QR Code no gateway.', 503);
    }

    $qrData = json_decode($qrResp['body'], true);

    if (!empty($qrResp['status']) && $qrResp['status'] >= 400) {
        $msg = isset($qrData['message']) ? $qrData['message'] : 'Erro ao gerar QR Code.';
        error_response($msg, 503);
    }

    // ── 3. Extrai qrcode_texto e qrcode_imagem do response ───────────
    // A PixUp retorna os campos com variações de nome — cobrimos todos
    $qrcodeTxt = '';
    $qrcodeImg = '';

    foreach (array('brCode', 'pixCopiaECola', 'emv', 'qrcode', 'pix_copia_cola') as $k) {
        if (!empty($qrData[$k])) { $qrcodeTxt = $qrData[$k]; break; }
    }
    foreach (array('imagemQrcode', 'qrcode_image', 'qr_image', 'image') as $k) {
        if (!empty($qrData[$k])) { $qrcodeImg = $qrData[$k]; break; }
    }

    // txid retornado pela PixUp (pode ser transactionId ou external_id)
    $txidFinal = '';
    foreach (array('transactionId', 'transaction_id', 'txid', 'external_id', 'id') as $k) {
        if (!empty($qrData[$k])) { $txidFinal = (string)$qrData[$k]; break; }
    }
    if (!$txidFinal) $txidFinal = $txidClean;

    // Fallback: gera imagem QR via API pública se a PixUp não retornou imagem
    if ($qrcodeTxt && !$qrcodeImg) {
        $qrcodeImg = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . urlencode($qrcodeTxt);
    }

    if (!$qrcodeTxt) {
        error_response('Gateway nao retornou o codigo PIX. Tente novamente.', 503);
    }

    return array(
        'txid'              => $txidFinal,
        'qrcode_texto'      => $qrcodeTxt,
        'qrcode_imagem'     => $qrcodeImg,
        'expiracao_minutos' => 30,
    );
}

/**
 * Executa uma requisição HTTP via cURL.
 * Retorna array ['status' => int, 'body' => string] ou false em caso de erro.
 */
function pixup_http($method, $url, $body = null, $headers = array())
{
    if (!function_exists('curl_init')) {
        $opts = array(
            'http' => array(
                'method'        => $method,
                'header'        => implode("\r\n", $headers),
                'content'       => $body,
                'timeout'       => 15,
                'ignore_errors' => true,
            ),
            'ssl' => array('verify_peer' => false),
        );
        $ctx      = stream_context_create($opts);
        $response = @file_get_contents($url, false, $ctx);
        if ($response === false) return false;
        return array('status' => 200, 'body' => $response);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ));

    if ($body !== null && in_array($method, array('POST', 'PUT', 'PATCH'))) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $response   = curl_exec($ch);
    $httpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    @curl_close($ch);

    if ($response === false) return false;

    return array('status' => $httpStatus, 'body' => $response);
}

// ===================================================================
//  EXPFYPAY — Integração com o gateway
//  Ref: https://3xpro.com.br/expfypay/
//  Auth: headers X-Public-Key + X-Secret-Key
//  Endpoint: POST https://expfypay.com/api/v1/payments
// ===================================================================

function expfypay_criar_cobranca($valor, $txidLocal, $cpf)
{
    $publicKey  = cfg('expfypay_client_id',     '');  // public key
    $secretKey  = cfg('expfypay_client_secret', '');  // secret key
    $baseUrl    = rtrim(cfg('expfypay_url', 'https://expfypay.com/api/v1'), '/');
    $webhookUrl = cfg('expfypay_webhook', '');
    $siteName   = cfg('site_nome', 'Plataforma');

    if (!$publicKey || !$secretKey) {
        error_response('Gateway ExpfyPay nao configurado. Contate o suporte.', 503);
    }

    $txidClean = preg_replace('/[^A-Za-z0-9_\-]/', '', $txidLocal);

    // Dados do pagador
    $customerName = 'Cliente';
    $customerDoc  = ($cpf && strlen($cpf) === 11) ? $cpf : '00000000000';

    $payload = array(
        'amount'       => (float)number_format($valor, 2, '.', ''),
        'description'  => 'Deposito - ' . $siteName,
        'customer'     => array(
            'name'     => $customerName,
            'document' => $customerDoc,
        ),
        'external_id'  => $txidClean,
    );

    if ($webhookUrl) {
        $payload['callback_url'] = $webhookUrl;
    }

    $resp = gateway_http('POST', $baseUrl . '/payments', json_encode($payload), array(
        'Content-Type: application/json',
        'X-Public-Key: ' . $publicKey,
        'X-Secret-Key: ' . $secretKey,
    ));

    if (!$resp || empty($resp['body'])) {
        error_response('Falha ao conectar com ExpfyPay.', 503);
    }

    $data = json_decode($resp['body'], true);

    if (!empty($resp['status']) && $resp['status'] >= 400) {
        $msg = isset($data['message']) ? $data['message']
             : (isset($data['error'])   ? $data['error'] : 'Erro no gateway ExpfyPay.');
        error_response($msg, 503);
    }

    if (empty($data['success']) || empty($data['data'])) {
        error_response('Resposta inesperada do gateway ExpfyPay.', 503);
    }

    $d = $data['data'];

    // Extrai qr_code (texto copia-e-cola) e qr_code_image
    $qrcodeTxt = isset($d['qr_code'])       ? $d['qr_code']       : '';
    $qrcodeImg = isset($d['qr_code_image']) ? $d['qr_code_image'] : '';

    // Fallback: gera imagem via API pública se não veio imagem
    if ($qrcodeTxt && !$qrcodeImg) {
        $qrcodeImg = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . urlencode($qrcodeTxt);
    }

    if (!$qrcodeTxt) {
        error_response('ExpfyPay nao retornou o codigo PIX. Tente novamente.', 503);
    }

    // transaction_id da ExpfyPay — usamos como referência no banco
    $txidFinal = isset($d['transaction_id']) ? $d['transaction_id']
               : (isset($d['external_id'])   ? $d['external_id'] : $txidClean);

    return array(
        'txid'              => $txidFinal,
        'qrcode_texto'      => $qrcodeTxt,
        'qrcode_imagem'     => $qrcodeImg,
        'expiracao_minutos' => 30,
    );
}

// ===================================================================
//  VIZZIONPAY — Integração com o gateway
//  Auth: headers x-public-key + x-secret-key
//  Endpoint: POST https://app.vizzionpay.com.br/api/v1/gateway/pix/receive
// ===================================================================

function vizzionpay_criar_cobranca($valor, $txidLocal, $cpf, $user)
{
    $publicKey  = cfg('vizzion_client_id',     '');
    $secretKey  = cfg('vizzion_client_secret', '');
    $baseUrl    = rtrim(cfg('vizzion_url', 'https://app.vizzionpay.com.br/api/v1'), '/');
    $webhookUrl = cfg('vizzion_webhook', '');

    if (!$publicKey || !$secretKey) {
        error_response('Gateway VizzionPay nao configurado. Contate o suporte.', 503);
    }

    $txidClean = preg_replace('/[^A-Za-z0-9_\-]/', '', $txidLocal);
    $cpfClean  = ($cpf && strlen($cpf) === 11) ? $cpf : '00000000000';
    $nome      = isset($user['nome']) && $user['nome'] ? $user['nome'] : 'Cliente';
    $email     = isset($user['email']) && $user['email'] ? $user['email'] : 'cliente@plataforma.com';
    $telefone  = isset($user['telefone']) && $user['telefone'] ? $user['telefone'] : '11999999999';

    $payload = array(
        'identifier' => $txidClean,
        'amount'     => (float)number_format($valor, 2, '.', ''),
        'client'     => array(
            'name'     => $nome,
            'email'    => $email,
            'phone'    => $telefone,
            'document' => $cpfClean,
        ),
    );

    if ($webhookUrl) {
        $payload['callbackUrl'] = $webhookUrl;
    }

    $resp = gateway_http('POST', $baseUrl . '/gateway/pix/receive', json_encode($payload), array(
        'Content-Type: application/json',
        'x-public-key: ' . $publicKey,
        'x-secret-key: ' . $secretKey,
    ));

    if (!$resp || empty($resp['body'])) {
        error_response('Falha ao conectar com VizzionPay.', 503);
    }

    $data = json_decode($resp['body'], true);

    if (!empty($resp['status']) && $resp['status'] >= 400) {
        $msg = isset($data['message']) ? $data['message']
             : (isset($data['error'])   ? $data['error'] : 'Erro no gateway VizzionPay.');
        error_response($msg, 503);
    }

    $txidFinal = isset($data['transactionId']) ? $data['transactionId'] : $txidClean;
    
    $qrcodeTxt = '';
    $qrcodeImg = '';
    
    if (isset($data['pix']) && is_array($data['pix'])) {
        $qrcodeTxt = isset($data['pix']['code']) ? $data['pix']['code'] : '';
        $qrcodeImg = isset($data['pix']['base64']) ? $data['pix']['base64'] : (isset($data['pix']['image']) ? $data['pix']['image'] : '');
    }

    if ($qrcodeTxt && !$qrcodeImg) {
        $qrcodeImg = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . urlencode($qrcodeTxt);
    }

    if (!$qrcodeTxt) {
        error_response('VizzionPay nao retornou o codigo PIX. Tente novamente.', 503);
    }

    return array(
        'txid'              => $txidFinal,
        'qrcode_texto'      => $qrcodeTxt,
        'qrcode_imagem'     => $qrcodeImg,
        'expiracao_minutos' => 30,
    );
}

/**
 * Helper HTTP genérico compartilhado por todos os gateways.
 * Retorna array ['status' => int, 'body' => string] ou false.
 */

// ===================================================================
//  AMPLOPAY — Integração com o gateway
//  Ref: https://app.amplopay.com/docs
//  Auth: OAuth2 client_credentials → POST /auth/token
//  Endpoint cobranças: POST /v1/charges  (ou /v1/pix/charges)
//
//  Request body:
//  {
//    "amount":       100.00,
//    "external_id":  "nosso_txid",
//    "description":  "Deposito - SiteName",
//    "customer":     { "name":"...", "document":"..." },
//    "callback_url": "https://seusite.com/api/amplopay_webhook.php"
//  }
//
//  Response (200/201):
//  {
//    "id":            "charge_abc123",
//    "status":        "pending",
//    "pix_copy_paste":"00020101...",    // copia e cola
//    "pix_qr_code":   "iVBORw0KGgo...",// base64 PNG
//    "external_id":   "nosso_txid"
//  }
// ===================================================================

/**
 * Cria uma cobrança PIX via AmploPay e retorna qrcode_texto + qrcode_imagem.
 */
/**
 * Cria uma cobrança PIX via AmploPay e retorna qrcode_texto + qrcode_imagem.
 */
/**
 * Cria uma cobrança PIX via AmploPay e retorna qrcode_texto + qrcode_imagem.
 */
function amplopay_criar_cobranca($valor, $txidLocal, $cpf, $user = null)
{
    // Limpa as chaves de espaços em branco acidentais
    $publicKey    = trim(cfg('amplo_client_id',     ''));
    $secretKey    = trim(cfg('amplo_client_secret', ''));
    $baseUrl      = trim(cfg('amplo_url', 'https://app.amplopay.com/api/v1'));
    $webhookUrl   = cfg('amplo_webhook', '');
    $siteName     = cfg('site_nome', 'Plataforma');

    if (!$publicKey || !$secretKey) {
        error_response('Gateway AmploPay nao configurado (Chaves ausentes).', 503);
    }

    $txidClean = preg_replace('/[^A-Za-z0-9_\-]/', '', $txidLocal);

    // Formata o CPF para o padrão 000.000.000-00 exigido pela AmploPay
    $cpfLimpo = preg_replace('/\D/', '', $cpf);
    if (strlen($cpfLimpo) === 11) {
        $cpfFormatado = substr($cpfLimpo, 0, 3) . '.' . substr($cpfLimpo, 3, 3) . '.' . substr($cpfLimpo, 6, 3) . '-' . substr($cpfLimpo, 9, 2);
    } else {
        $cpfFormatado = '000.000.000-00';
    }

    // Obtém dados reais do usuário se disponíveis
    $nomeCliente  = (!empty($user['nome'])) ? $user['nome'] : 'Cliente ' . $txidClean;
    $emailCliente = (!empty($user['email']) && filter_var($user['email'], FILTER_VALIDATE_EMAIL)) 
                    ? $user['email'] 
                    : 'cliente_' . $user['id'] . '@gmail.com';
    
    // Formata telefone: (XX) 9XXXX-XXXX
    $telRaw = preg_replace('/\D/', '', (!empty($user['telefone']) ? $user['telefone'] : '11999999999'));
    if (strlen($telRaw) >= 11) {
        $telFormatado = '(' . substr($telRaw, 0, 2) . ') ' . substr($telRaw, 2, 5) . '-' . substr($telRaw, 7);
    } else {
        $telFormatado = '(11) 99999-9999';
    }

    // ── 1. Prepara o Payload ──────────────────────────────────────────
    $payload = array(
        'identifier'  => $txidClean,
        'amount'      => (float)number_format($valor, 2, '.', ''),
        'client'      => array(
            'name'     => $nomeCliente,
            'email'    => $emailCliente,
            'phone'    => $telFormatado,
            'document' => $cpfFormatado,
        ),
    );

    // Valida ou gera dinamicamente a URL do webhook com base no domínio atual
    if (!filter_var($webhookUrl, FILTER_VALIDATE_URL)) {
        $scheme = 'https';
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            $scheme = strtolower(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'])[0]);
        }
        $host = !empty($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
        $webhookUrl = $host ? $scheme . '://' . $host . '/api/amplopay_webhook.php' : '';
    }

    if ($webhookUrl && filter_var($webhookUrl, FILTER_VALIDATE_URL)) {
        $payload['callbackUrl'] = $webhookUrl;
    }

    // ── 2. Faz a requisição direta (Sem OAuth2) ───────────────────────
    $resp = gateway_http('POST', $baseUrl . '/gateway/pix/receive', json_encode($payload), array(
        'Content-Type: application/json',
        'Accept: application/json',
        'x-public-key: ' . $publicKey,
        'x-secret-key: ' . $secretKey,
    ));

    if (!$resp || empty($resp['body'])) {
        error_response('Falha ao conectar com AmploPay.', 503);
    }

    $parsed = json_decode($resp['body'], true);

    // ── 3. Verifica Sucesso e Extrai Dados ────────────────────────────
    if ($resp['status'] < 200 || $resp['status'] >= 300) {
        $msg = isset($parsed['message']) ? $parsed['message'] : 'Erro desconhecido na AmploPay';
        
        // Se houver detalhes do erro, anexa à mensagem para facilitar o diagnóstico
        if (!empty($parsed['details']) && is_array($parsed['details'])) {
            $detalhes = json_encode($parsed['details']);
            $msg .= " (Detalhes: $detalhes)";
        }
        
        error_response('AmploPay: ' . $msg, 503);
    }

    $qrcodeTxt = '';
    $qrcodeImg = '';

    if (!empty($parsed['pix_copy_paste'])) {
        $qrcodeTxt = $parsed['pix_copy_paste'];
    } elseif (!empty($parsed['pix']['code'])) {
        $qrcodeTxt = $parsed['pix']['code'];
    }

    if (!empty($parsed['pix_qr_code'])) {
        $qrcodeImg = $parsed['pix_qr_code'];
    } elseif (!empty($parsed['pix']['base64'])) {
        $qrcodeImg = $parsed['pix']['base64'];
    }

    if ($qrcodeImg && strpos($qrcodeImg, 'data:image') !== 0 && @base64_decode($qrcodeImg, true) !== false) {
        $qrcodeImg = 'data:image/png;base64,' . $qrcodeImg;
    }

    if ($qrcodeTxt && !$qrcodeImg) {
        $qrcodeImg = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . urlencode($qrcodeTxt);
    }

    if (!$qrcodeTxt) {
        error_response('AmploPay nao retornou o codigo PIX.', 503);
    }

    $txidFinal = isset($parsed['transactionId']) ? (string)$parsed['transactionId'] : $txidClean;

    return array(
        'txid'              => $txidFinal,
        'qrcode_texto'      => $qrcodeTxt,
        'qrcode_imagem'     => $qrcodeImg,
        'expiracao_minutos' => 30,
    );
}
{
    // Limpa as chaves de espaços em branco acidentais
    $publicKey    = trim(cfg('amplo_client_id',     ''));
    $secretKey    = trim(cfg('amplo_client_secret', ''));
    $baseUrl      = trim(cfg('amplo_url', 'https://app.amplopay.com/api/v1'));
    $webhookUrl   = cfg('amplo_webhook', '');
    $siteName     = cfg('site_nome', 'Plataforma');

    if (!$publicKey || !$secretKey) {
        error_response('Gateway AmploPay nao configurado (Chaves ausentes).', 503);
    }

    $txidClean = preg_replace('/[^A-Za-z0-9_\-]/', '', $txidLocal);

    // Formata o CPF para o padrão 000.000.000-00 exigido pela AmploPay
    $cpfLimpo = preg_replace('/\D/', '', $cpf);
    if (strlen($cpfLimpo) === 11) {
        $cpfFormatado = substr($cpfLimpo, 0, 3) . '.' . substr($cpfLimpo, 3, 3) . '.' . substr($cpfLimpo, 6, 3) . '-' . substr($cpfLimpo, 9, 2);
    } else {
        $cpfFormatado = '000.000.000-00';
    }

    // Obtém dados reais do usuário se disponíveis
    $nomeCliente  = (!empty($user['nome'])) ? $user['nome'] : 'Cliente ' . $txidClean;
    $emailCliente = (!empty($user['email']) && filter_var($user['email'], FILTER_VALIDATE_EMAIL)) 
                    ? $user['email'] 
                    : 'cliente_' . $user['id'] . '@gmail.com';
    
    // Formata telefone: (XX) 9XXXX-XXXX
    $telRaw = preg_replace('/\D/', '', (!empty($user['telefone']) ? $user['telefone'] : '11999999999'));
    if (strlen($telRaw) >= 11) {
        $telFormatado = '(' . substr($telRaw, 0, 2) . ') ' . substr($telRaw, 2, 5) . '-' . substr($telRaw, 7);
    } else {
        $telFormatado = '(11) 99999-9999';
    }

    // ── 1. Prepara o Payload ──────────────────────────────────────────
    $payload = array(
        'identifier'  => $txidClean,
        'amount'      => (float)number_format($valor, 2, '.', ''),
        'client'      => array(
            'name'     => $nomeCliente,
            'email'    => $emailCliente,
            'phone'    => $telFormatado,
            'document' => $cpfFormatado,
        ),
    );

    // Valida ou gera dinamicamente a URL do webhook com base no domínio atual
    if (!filter_var($webhookUrl, FILTER_VALIDATE_URL)) {
        $scheme = 'https';
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            $scheme = strtolower(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'])[0]);
        }
        $host = !empty($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
        $webhookUrl = $host ? $scheme . '://' . $host . '/api/amplopay_webhook.php' : '';
    }

    if ($webhookUrl && filter_var($webhookUrl, FILTER_VALIDATE_URL)) {
        $payload['callbackUrl'] = $webhookUrl;
    }

    // ── 2. Faz a requisição direta (Sem OAuth2) ───────────────────────
    $resp = gateway_http('POST', $baseUrl . '/gateway/pix/receive', json_encode($payload), array(
        'Content-Type: application/json',
        'Accept: application/json',
        'x-public-key: ' . $publicKey,
        'x-secret-key: ' . $secretKey,
    ));

    if (!$resp || empty($resp['body'])) {
        error_response('Falha ao conectar com AmploPay.', 503);
    }

    $parsed = json_decode($resp['body'], true);

    // ── 3. Verifica Sucesso e Extrai Dados ────────────────────────────
    if ($resp['status'] < 200 || $resp['status'] >= 300) {
        $msg = isset($parsed['message']) ? $parsed['message'] : 'Erro desconhecido na AmploPay';
        
        // Se houver detalhes do erro, anexa à mensagem para facilitar o diagnóstico
        if (!empty($parsed['details']) && is_array($parsed['details'])) {
            $detalhes = json_encode($parsed['details']);
            $msg .= " (Detalhes: $detalhes)";
        }
        
        error_response('AmploPay: ' . $msg, 503);
    }

    $qrcodeTxt = '';
    $qrcodeImg = '';

       if (!empty($parsed['pix_copy_paste'])) {
        $qrcodeTxt = $parsed['pix_copy_paste'];
    } elseif (!empty($parsed['pix']['code'])) {
        $qrcodeTxt = $parsed['pix']['code'];
    }

    if (!empty($parsed['pix_qr_code'])) {
        $qrcodeImg = $parsed['pix_qr_code'];
    } elseif (!empty($parsed['pix']['base64'])) {
        $qrcodeImg = $parsed['pix']['base64'];
    }

    if ($qrcodeImg && strpos($qrcodeImg, 'data:image') !== 0 && @base64_decode($qrcodeImg, true) !== false) {
        $qrcodeImg = 'data:image/png;base64,' . $qrcodeImg;
    }
    }

    if ($qrcodeTxt && !$qrcodeImg) {
        $qrcodeImg = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . urlencode($qrcodeTxt);
    }

    if (!$qrcodeTxt) {
        error_response('AmploPay nao retornou o codigo PIX.', 503);
    }

    $txidFinal = isset($parsed['transactionId']) ? (string)$parsed['transactionId'] : $txidClean;

    return array(
        'txid'              => $txidFinal,
        'qrcode_texto'      => $qrcodeTxt,
        'qrcode_imagem'     => $qrcodeImg,
        'expiracao_minutos' => 30,
    );
}
{
    // Limpa as chaves de espaços em branco acidentais
    $publicKey    = trim(cfg('amplo_client_id',     ''));
    $secretKey    = trim(cfg('amplo_client_secret', ''));
    $baseUrl      = trim(cfg('amplo_url', 'https://app.amplopay.com/api/v1'));
    $webhookUrl   = cfg('amplo_webhook', '');
    $siteName     = cfg('site_nome', 'Plataforma');

    if (!$publicKey || !$secretKey) {
        error_response('Gateway AmploPay nao configurado (Chaves ausentes).', 503);
    }

    $txidClean = preg_replace('/[^A-Za-z0-9_\-]/', '', $txidLocal);

    // Formata o CPF para o padrão 000.000.000-00 exigido pela AmploPay
    $cpfLimpo = preg_replace('/\D/', '', $cpf);
    if (strlen($cpfLimpo) === 11) {
        $cpfFormatado = substr($cpfLimpo, 0, 3) . '.' . substr($cpfLimpo, 3, 3) . '.' . substr($cpfLimpo, 6, 3) . '-' . substr($cpfLimpo, 9, 2);
    } else {
        $cpfFormatado = '000.000.000-00';
    }

    // Obtém dados reais do usuário se disponíveis
    $nomeCliente  = (!empty($user['nome'])) ? $user['nome'] : 'Cliente ' . $txidClean;
    $emailCliente = (!empty($user['email']) && filter_var($user['email'], FILTER_VALIDATE_EMAIL)) 
                    ? $user['email'] 
                    : 'cliente_' . $user['id'] . '@gmail.com';
    
    // Formata telefone: (XX) 9XXXX-XXXX
    $telRaw = preg_replace('/\D/', '', (!empty($user['telefone']) ? $user['telefone'] : '11999999999'));
    if (strlen($telRaw) >= 11) {
        $telFormatado = '(' . substr($telRaw, 0, 2) . ') ' . substr($telRaw, 2, 5) . '-' . substr($telRaw, 7);
    } else {
        $telFormatado = '(11) 99999-9999';
    }

    // ── 1. Prepara o Payload ──────────────────────────────────────────
    $payload = array(
        'identifier'  => $txidClean,
        'amount'      => (float)number_format($valor, 2, '.', ''),
        'client'      => array(
            'name'     => $nomeCliente,
            'email'    => $emailCliente,
            'phone'    => $telFormatado,
            'document' => $cpfFormatado,
        ),
    );

    if ($webhookUrl) {
        $payload['callbackUrl'] = $webhookUrl;
    }

    // ── 2. Faz a requisição direta (Sem OAuth2) ───────────────────────
    $resp = gateway_http('POST', $baseUrl . '/gateway/pix/receive', json_encode($payload), array(
        'Content-Type: application/json',
        'Accept: application/json',
        'x-public-key: ' . $publicKey,
        'x-secret-key: ' . $secretKey,
    ));

    if (!$resp || empty($resp['body'])) {
        error_response('Falha ao conectar com AmploPay.', 503);
    }

    $parsed = json_decode($resp['body'], true);

    // ── 3. Verifica Sucesso e Extrai Dados ────────────────────────────
    if ($resp['status'] < 200 || $resp['status'] >= 300) {
        $msg = isset($parsed['message']) ? $parsed['message'] : 'Erro desconhecido na AmploPay';
        
        // Se houver detalhes do erro, anexa à mensagem para facilitar o diagnóstico
        if (!empty($parsed['details']) && is_array($parsed['details'])) {
            $detalhes = json_encode($parsed['details']);
            $msg .= " (Detalhes: $detalhes)";
        }
        
        error_response('AmploPay: ' . $msg, 503);
    }

    $qrcodeTxt = '';
    $qrcodeImg = '';

    if (!empty($parsed['pix']['code'])) {
        $qrcodeTxt = $parsed['pix']['code'];
    }
    if (!empty($parsed['pix']['base64'])) {
        $qrcodeImg = $parsed['pix']['base64'];
    }

    if ($qrcodeTxt && !$qrcodeImg) {
        $qrcodeImg = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . urlencode($qrcodeTxt);
    }

    if (!$qrcodeTxt) {
        error_response('AmploPay nao retornou o codigo PIX.', 503);
    }

    $txidFinal = isset($parsed['transactionId']) ? (string)$parsed['transactionId'] : $txidClean;

    return array(
        'txid'              => $txidFinal,
        'qrcode_texto'      => $qrcodeTxt,
        'qrcode_imagem'     => $qrcodeImg,
        'expiracao_minutos' => 30,
    );
}

function gateway_http($method, $url, $body = null, $headers = array())
{
    if (!function_exists('curl_init')) {
        $opts = array(
            'http' => array(
                'method'        => $method,
                'header'        => implode("\n", $headers),
                'content'       => $body,
                'timeout'       => 15,
                'ignore_errors' => true,
            ),
            'ssl' => array('verify_peer' => false),
        );
        $ctx      = stream_context_create($opts);
        $response = @file_get_contents($url, false, $ctx);
        if ($response === false) return false;
        return array('status' => 200, 'body' => $response);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ));

    if ($body !== null && in_array($method, array('POST', 'PUT', 'PATCH'))) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $response   = curl_exec($ch);
    $httpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) return false;

    return array('status' => $httpStatus, 'body' => $response);
}

function akadpay_criar_cobranca($valor, $txidLocal, $cpf, $user = null)
{
    // ── GGPIX (PIX In) — Auth: header X-API-Key | Base: https://ggpixapi.com/api/v1 ──
    // GGPIX usa apenas 1 credencial: akadpay_token = API Key (header X-API-Key).
    // URL base é fixa. O webhookUrl NÃO é configurado no painel da GGPIX: é enviado
    // no payload de criação do PIX, montado dinamicamente a partir do domínio atual.
    $apiKey  = trim(cfg('akadpay_token', ''));
    $baseUrl = 'https://ggpixapi.com/api/v1';

    // Webhook de depósito (PIX IN) sempre apontando para o domínio onde o sistema roda
    $scheme = 'https';
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $scheme = strtolower(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'])[0]);
    } elseif (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] === 'off') {
        // Sem indicação de HTTPS: mantém https por padrão (GGPIX exige URL pública https)
        $scheme = (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 80) ? 'http' : 'https';
    }
    $host = !empty($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST']
          : (!empty($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : '');
    $webhookUrl = $host ? $scheme . '://' . $host . '/api/akadpay_webhook.php' : '';

    if (!$apiKey) {
        error_response('Gateway nao configurado. Informe a API Key no painel admin.', 503);
    }

    // externalId enviado a GGPIX = referencia salva no banco (o webhook casa por ele)
    $txidClean = preg_replace('/[^A-Za-z0-9_\-]/', '', $txidLocal);

    // payerDocument: aceita CPF (11) ou CNPJ (14); fallback generico
    $cpfLimpo = preg_replace('/\D/', '', $cpf);
    if (strlen($cpfLimpo) !== 11 && strlen($cpfLimpo) !== 14) $cpfLimpo = '00000000000';

    $nome = (!empty($user['nome'])) ? $user['nome'] : 'Cliente';

    $payload = array(
        'amountCents'   => (int)round($valor * 100),
        'description'   => 'Deposito - ' . cfg('site_nome', 'Plataforma'),
        'payerName'     => $nome,
        'payerDocument' => $cpfLimpo,
        'externalId'    => $txidClean,
    );

    // Campos opcionais do pagador
    if (!empty($user['email']) && filter_var($user['email'], FILTER_VALIDATE_EMAIL)) {
        $payload['payerEmail'] = $user['email'];
    }
    if (!empty($user['telefone'])) {
        $phone = preg_replace('/\D/', '', $user['telefone']);
        if (strlen($phone) >= 10) $payload['payerPhone'] = $phone;
    }

    if ($webhookUrl) $payload['webhookUrl'] = $webhookUrl;

    $resp = gateway_http('POST', $baseUrl . '/pix/in', json_encode($payload), array(
        'Content-Type: application/json',
        'X-API-Key: ' . $apiKey,
    ));
    if (!$resp || empty($resp['body'])) error_response('Falha ao conectar com o gateway de pagamento.', 503);

    $data = json_decode($resp['body'], true);
    if (!empty($resp['status']) && $resp['status'] >= 400) {
        $msg = isset($data['error']) ? $data['error']
             : (isset($data['message']) ? $data['message'] : 'Erro no gateway GGPIX.');
        error_response('GGPIX: ' . $msg, 503);
    }

    // GGPIX retorna o codigo copia-e-cola em pixCopyPaste (ou pixCode). Nao envia imagem.
    $qrcodeTxt = '';
    foreach (array('pixCopyPaste', 'pixCode') as $k) {
        if (!empty($data[$k])) { $qrcodeTxt = (string)$data[$k]; break; }
    }
    if (!$qrcodeTxt) error_response('GGPIX nao retornou o codigo PIX. Tente novamente.', 503);

    $qrcodeImg = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . urlencode($qrcodeTxt);

    return array(
        // Retorna nosso externalId: vira a referencia no banco e casa no webhook
        'txid'              => $txidClean,
        'qrcode_texto'      => $qrcodeTxt,
        'qrcode_imagem'     => $qrcodeImg,
        'expiracao_minutos' => 30,
    );
}

function akadpay_detectar_tipo_chave($pix)
{
    $pix = trim((string)$pix);
    $onlyDigits = preg_replace('/\D/', '', $pix);

    if (filter_var($pix, FILTER_VALIDATE_EMAIL)) return 'email';
    if (preg_match('/^[0-9a-fA-F]{8}\-[0-9a-fA-F]{4}\-[1-5][0-9a-fA-F]{3}\-[89abAB][0-9a-fA-F]{3}\-[0-9a-fA-F]{12}$/', $pix)) return 'random';
    if (strlen($onlyDigits) === 11 && $onlyDigits === $pix) return 'cpf';
    if (strlen($onlyDigits) === 14 && $onlyDigits === $pix) return 'cnpj';
    if (strlen($onlyDigits) >= 10 && strlen($onlyDigits) <= 13) return 'phone';
    return 'random';
}

function akadpay_pixout_solicitar($valor, $pix)
{
    $token      = trim(cfg('akadpay_token', ''));
    $secret     = trim(cfg('akadpay_secret', ''));
    $baseUrl    = rtrim(cfg('akadpay_url', 'https://painel.akadpay.com.br'), '/');
    $webhookUrl = trim(cfg('akadpay_webhook_saque', ''));

    if (!$token || !$secret) error_response('Gateway AkadPay nao configurado. Informe token e secret no painel admin.', 503);
    if (!$webhookUrl) error_response('Configure a URL de webhook de saque da AkadPay no painel admin.', 503);

    $pixKeyType = akadpay_detectar_tipo_chave($pix);
    $pixKey = ($pixKeyType === 'phone' || $pixKeyType === 'cpf' || $pixKeyType === 'cnpj')
        ? preg_replace('/\D/', '', $pix)
        : trim($pix);

    $payload = array(
        'token'           => $token,
        'secret'          => $secret,
        'baasPostbackUrl' => $webhookUrl,
        'amount'          => (float)number_format($valor, 2, '.', ''),
        'pixKey'          => $pixKey,
        'pixKeyType'      => $pixKeyType,
    );

    $resp = gateway_http('POST', $baseUrl . '/api/pixout', json_encode($payload), array(
        'Content-Type: application/json',
        'Accept: application/json',
    ));
    if (!$resp || empty($resp['body'])) error_response('Falha ao conectar com AkadPay (saque).', 503);

    $data = json_decode($resp['body'], true);
    if (!empty($resp['status']) && $resp['status'] >= 400) {
        $msg = isset($data['message']) ? $data['message']
             : (isset($data['error']) ? $data['error'] : 'Erro no saque AkadPay.');
        error_response('AkadPay: ' . $msg, 503);
    }

    $gatewayId = isset($data['id']) ? (string)$data['id'] : '';
    if (!$gatewayId) error_response('AkadPay nao retornou o identificador do saque.', 503);

    return array(
        'gateway_id'      => $gatewayId,
        'withdraw_status' => isset($data['withdrawStatusId']) ? (string)$data['withdrawStatusId'] : 'PendingProcessing',
        'raw'             => $resp['body'],
        'pix_key_type'    => $pixKeyType,
    );
}

function fin_deposito_status($txid)
{
    $user = auth_required();

    $stmt = db()->prepare('SELECT * FROM transacoes WHERE referencia = ? AND usuario_id = ? AND tipo = "deposito" LIMIT 1');
    $stmt->execute(array($txid, $user['id']));
    $tx = $stmt->fetch();

    if (!$tx) error_response('Transacao nao encontrada.', 404);

    json_response(array(
        'status'     => $tx['status'],
        'valor'      => (float)$tx['valor'],
        'saldo_novo' => null,
    ));
}

function fin_saque()
{
    $user  = auth_required();
    $body  = request_body();
    $valor = (float)(isset($body['valor'])     ? $body['valor']     : 0);
    $pix   = trim(isset($body['chave_pix'])    ? $body['chave_pix'] : '');
    $cpf   = preg_replace('/\D/', '', isset($body['cpf']) ? $body['cpf'] : '');

    $saqMin = (float)cfg('saque_minimo', 20);
    $saqMax = (float)cfg('saque_maximo', 0);

    if ($valor < $saqMin) error_response('Saque minimo: R$ ' . number_format($saqMin, 2, ',', '.'));
    if ($saqMax > 0 && $valor > $saqMax) error_response('Saque maximo: R$ ' . number_format($saqMax, 2, ',', '.'));
    if ((float)$user['saldo'] < $valor) error_response('Saldo insuficiente.');
    if (!$pix)  error_response('Chave PIX obrigatoria.');
    if (strlen($cpf) !== 11) error_response('CPF invalido (11 digitos).');

    // ── Bloqueia saque se houver rollover ativo ──────────────────────
    $rvPendente = rollover_pendente($user['id']);
    if ($rvPendente) {
        $restante   = number_format((float)$rvPendente['valor_restante'], 2, ',', '.');
        $exigido    = number_format((float)$rvPendente['valor_exigido'],  2, ',', '.');
        $apostado   = number_format((float)$rvPendente['valor_apostado'], 2, ',', '.');
        error_response(
            'Saque bloqueado: voce precisa apostar mais R$ ' . $restante .
            ' para completar o rollover (apostado R$ ' . $apostado .
            ' de R$ ' . $exigido . ' exigidos).',
            403
        );
    }

    $db = db();
    $db->beginTransaction();
    try {
        $db->prepare('UPDATE usuarios SET saldo = saldo - ? WHERE id = ? AND saldo >= ?')
           ->execute(array($valor, $user['id'], $valor));
        $referencia = null;
        $descricao  = 'Saque solicitado';

        $db->prepare('INSERT INTO transacoes (usuario_id, tipo, valor, status, referencia, pix_chave, cpf, descricao) VALUES (?, "saque", ?, "pendente", ?, ?, ?, ?)')
           ->execute(array($user['id'], $valor, $referencia, $pix, $cpf, $descricao));
        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        error_response('Erro ao processar saque.');
    }

    $stmt = db()->prepare('SELECT saldo FROM usuarios WHERE id = ?');
    $stmt->execute(array($user['id']));
    $saldo = (float)$stmt->fetchColumn();

    json_response(array('ok' => true, 'saldo_novo' => $saldo));
}

function fin_saque_afil()
{
    $user  = auth_required();
    $body  = request_body();
    $valor = (float)(isset($body['valor'])    ? $body['valor']     : 0);
    $pix   = trim(isset($body['chave_pix'])   ? $body['chave_pix'] : '');

    $minA = (float)cfg('saque_afiliado_minimo', 10);
    $maxA = (float)cfg('saque_afiliado_maximo', 0);

    if ($valor < $minA) error_response('Saque minimo de comissao: R$ ' . number_format($minA, 2, ',', '.'));
    if ($maxA > 0 && $valor > $maxA) error_response('Saque maximo de comissao: R$ ' . number_format($maxA, 2, ',', '.'));
    if ((float)$user['saldo_afiliado'] < $valor) error_response('Saldo de comissao insuficiente.');

    $db = db();
    $db->beginTransaction();
    try {
        $db->prepare('UPDATE usuarios SET saldo_afiliado = saldo_afiliado - ? WHERE id = ? AND saldo_afiliado >= ?')
           ->execute(array($valor, $user['id'], $valor));
        $pixVal = $pix ? $pix : null;
        $referencia = null;
        $descricao  = 'Saque de comissao';

        $db->prepare('INSERT INTO transacoes (usuario_id, tipo, valor, status, referencia, pix_chave, descricao) VALUES (?, "saque_afiliado", ?, "pendente", ?, ?, ?)')
           ->execute(array($user['id'], $valor, $referencia, $pixVal, $descricao));
        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        error_response('Erro ao processar saque de comissao.');
    }

    $stmt = db()->prepare('SELECT saldo_afiliado FROM usuarios WHERE id = ?');
    $stmt->execute(array($user['id']));
    $saldoAfil = (float)$stmt->fetchColumn();

    json_response(array('ok' => true, 'saldo_afiliado_novo' => $saldoAfil));
}

function fin_meus_saques()
{
    $user = auth_required();
    $stmt = db()->prepare('SELECT id, tipo, valor, status, pix_chave, created_at FROM transacoes WHERE usuario_id = ? AND tipo IN ("saque","saque_afiliado") ORDER BY created_at DESC LIMIT 20');
    $stmt->execute(array($user['id']));
    json_response(array('saques' => $stmt->fetchAll()));
}

function fin_historico()
{
    $user   = auth_required();
    $pagina = isset($_GET['pagina']) ? max(1, (int)$_GET['pagina']) : 1;
    $limite = isset($_GET['limite']) ? min(50, max(1, (int)$_GET['limite'])) : 20;
    $offset = ($pagina - 1) * $limite;

    $stmt = db()->prepare('SELECT id, tipo, valor, status, referencia, descricao, created_at FROM transacoes WHERE usuario_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?');
    $stmt->execute(array($user['id'], $limite, $offset));

    json_response(array(
        'transacoes' => $stmt->fetchAll(),
        'pagina'     => $pagina,
        'limite'     => $limite,
    ));
}

// ===================================================================
//  INDICACAO
// ===================================================================

function indicacao_info()
{
    $user = auth_required();

    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host  = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
    $link  = $proto . '://' . $host . '/?ref=' . $user['id'];

    // ── IDs ignorados pela regra de afiliado ────────────────────────
    $idsIgnorados = afil_ids_ignorados($user['id']);

    // Busca TODOS os indicados (sem LIMIT) para calcular montante e totais corretos
    $stmtTodos = db()->prepare('SELECT id, nome, data_cadastro, bonus_pago FROM usuarios WHERE indicado_por = ? ORDER BY data_cadastro DESC');
    $stmtTodos->execute(array($user['id']));
    $todosTodosIndicados = $stmtTodos->fetchAll();

    // Busca apenas os 30 mais recentes para exibição na lista
    $stmt = db()->prepare('SELECT id, nome, data_cadastro, bonus_pago FROM usuarios WHERE indicado_por = ? ORDER BY data_cadastro DESC LIMIT 30');
    $stmt->execute(array($user['id']));
    $indicados = $stmt->fetchAll();

    $totalComDeposito    = 0;
    $indicadosFormatados = array();
    $idIndicados         = array();

    // Monta lista completa de IDs e contagem total (apenas CONTADOS)
    foreach ($todosTodosIndicados as $i) {
        $idConvidado = (int)$i['id'];
        // Ignora convidados marcados como 'ignorado' na regra
        if (in_array($idConvidado, $idsIgnorados)) continue;

        if ($i['bonus_pago']) {
            $totalComDeposito++;
        }
        $idIndicados[] = $idConvidado;
    }

    // Monta lista formatada apenas dos 30 recentes CONTADOS para exibição
    foreach ($indicados as $i) {
        $idConvidado = (int)$i['id'];
        // Omite da lista se estiver ignorado
        if (in_array($idConvidado, $idsIgnorados)) continue;

        $stmtC = db()->prepare('SELECT COALESCE(SUM(valor), 0) as total FROM transacoes WHERE usuario_id = ? AND tipo = "bonus_indicacao" AND referencia = ? AND status = "aprovado"');
        $stmtC->execute(array($user['id'], (string)$idConvidado));
        $comissaoRow   = $stmtC->fetch();
        $totalComissao = isset($comissaoRow['total']) ? (float)$comissaoRow['total'] : 0.0;

        $indicadosFormatados[] = array(
            'id'                      => $idConvidado,
            'nome'                    => $i['nome'],
            'data_cadastro'           => $i['data_cadastro'],
            'bonus_pago'              => (bool)$i['bonus_pago'],
            'total_comissao_indicado' => $totalComissao,
            'nivel_afil'              => 1,
        );
    }

    // Montante: soma depósitos apenas dos indicados CONTADOS
    $montanteDepositos = 0.0;
    if (!empty($idIndicados)) {
        $placeholders = implode(',', array_fill(0, count($idIndicados), '?'));
        $stmtM = db()->prepare(
            'SELECT COALESCE(SUM(t.valor), 0) as total
             FROM transacoes t
             WHERE t.tipo = "deposito"
               AND t.status = "aprovado"
               AND t.usuario_id IN (' . $placeholders . ')'
        );
        $stmtM->execute($idIndicados);
        $mRow = $stmtM->fetch();
        $montanteDepositos = isset($mRow['total']) ? (float)$mRow['total'] : 0.0;
    }

    $showMontante = (int)cfg('show_montante', 1);

    json_response(array(
        'link'                      => $link,
        'total_indicados'           => count($idIndicados),          // apenas contados
        'total_com_deposito'        => $totalComDeposito,
        'saldo_afiliado'            => (float)$user['saldo_afiliado'],
        'total_comissao'            => (float)$user['total_comissao'],
        'comissao_nivel1_perc'      => (float)(
            ($user['comissao_perc_individual'] !== null && $user['comissao_perc_individual'] !== '')
            ? $user['comissao_perc_individual']
            : cfg('comissao_nivel1_perc', 10)
        ),
        'indicados_recentes'        => $indicadosFormatados,
        'montante_depositos'        => $montanteDepositos,
        'show_montante'             => $showMontante,
        'show_comissao_banner'      => (int)cfg('show_comissao_banner', 1),
        'show_saldo_afiliado'       => (int)cfg('show_saldo_afiliado', 1),
        'show_botao_sacar_afil'     => (int)cfg('show_botao_sacar_afil', 1),
        'show_link_afiliado'        => (int)cfg('show_link_afiliado', 1),
        'show_stats_indicados'      => (int)cfg('show_stats_indicados', 1),
        'show_lista_indicados'      => (int)cfg('show_lista_indicados', 1),
        'show_valor_comissao_lista' => (int)cfg('show_valor_comissao_lista', 1),
    ));
}

// ===================================================================
//  CUPONS
// ===================================================================

function cupom_validar()
{
    $user   = auth_required();
    $body   = request_body();
    $codigo = strtoupper(trim(isset($body['codigo']) ? $body['codigo'] : ''));

    if (!$codigo) error_response('Codigo nao informado.');

    $cupom = buscar_cupom_valido($codigo, (int)$user['id']);
    json_response(array(
        'codigo' => $cupom['codigo'],
        'tipo'   => $cupom['tipo'],
        'valor'  => (float)$cupom['valor'],
    ));
}

function cupom_resgatar()
{
    $user   = auth_required();
    $body   = request_body();
    $codigo = strtoupper(trim(isset($body['codigo']) ? $body['codigo'] : ''));

    if (!$codigo) error_response('Codigo nao informado.');

    $cupom = buscar_cupom_valido($codigo, (int)$user['id']);

    $db = db();
    $db->beginTransaction();
    try {
        $db->prepare('INSERT INTO cupons_resgates (cupom_id, usuario_id) VALUES (?, ?)')
           ->execute(array($cupom['id'], $user['id']));
        $db->prepare('UPDATE cupons SET usos_atual = usos_atual + 1 WHERE id = ?')
           ->execute(array($cupom['id']));

        $valor = (float)$cupom['valor'];
        if ($cupom['tipo'] === 'saldo') {
            $db->prepare('UPDATE usuarios SET saldo = saldo + ? WHERE id = ?')
               ->execute(array($valor, $user['id']));
            $db->prepare('INSERT INTO transacoes (usuario_id, tipo, valor, status, referencia, descricao) VALUES (?, "ajuste_admin", ?, "aprovado", ?, "Cupom resgatado")')
               ->execute(array($user['id'], $valor, $codigo));
        }

        $db->commit();
    } catch (PDOException $e) {
        $db->rollBack();
        if ((string)$e->getCode() === '23000') {
            error_response('Cupom ja utilizado por voce.');
        }
        error_response('Erro ao resgatar cupom.');
    }

    json_response(array('ok' => true, 'tipo' => $cupom['tipo'], 'valor' => (float)$cupom['valor']));
}

function buscar_cupom_valido($codigo, $userId)
{
    $stmt = db()->prepare('SELECT * FROM cupons WHERE codigo = ? AND ativo = 1 AND (expira_em IS NULL OR expira_em > NOW()) AND (usos_max = 0 OR usos_atual < usos_max) LIMIT 1');
    $stmt->execute(array($codigo));
    $cupom = $stmt->fetch();
    if (!$cupom) error_response('Cupom invalido, expirado ou esgotado.');

    $stmt = db()->prepare('SELECT id FROM cupons_resgates WHERE cupom_id = ? AND usuario_id = ?');
    $stmt->execute(array($cupom['id'], $userId));
    if ($stmt->fetch()) error_response('Voce ja utilizou este cupom.');

    return $cupom;
}

// ===================================================================
//  HELPERS PIX
// ===================================================================

function gerar_payload_pix($chave, $nome, $valor, $txid)
{
    if (!$chave) return '';

    $valorStr = number_format($valor, 2, '.', '');
    $nomeAscii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nome);
    $nomeStr  = substr(preg_replace('/[^A-Za-z0-9 ]/', '', $nomeAscii), 0, 25);
    $txidStr  = substr(preg_replace('/[^A-Za-z0-9]/', '', $txid), 0, 25);
    if (!$txidStr) $txidStr = 'helixwin';

    $merchantInfo = pix_field('00', 'br.gov.bcb.pix') . pix_field('01', $chave);
    $merchantInfo = pix_field('26', $merchantInfo);
    $addData      = pix_field('62', pix_field('05', $txidStr));

    $payload = '000201'
             . $merchantInfo
             . '52040000'
             . '5303986'
             . pix_field('54', $valorStr)
             . '5802BR'
             . pix_field('59', $nomeStr)
             . pix_field('60', 'Brasil')
             . $addData
             . '6304';

    $crc = crc16_ccitt($payload);
    return $payload . strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
}

function pix_field($id, $value)
{
    return $id . str_pad(strlen($value), 2, '0', STR_PAD_LEFT) . $value;
}

function crc16_ccitt($data)
{
    $crc = 0xFFFF;
    $len = strlen($data);
    for ($i = 0; $i < $len; $i++) {
        $crc ^= ord($data[$i]) << 8;
        for ($j = 0; $j < 8; $j++) {
            if ($crc & 0x8000) {
                $crc = ($crc << 1) ^ 0x1021;
            } else {
                $crc = $crc << 1;
            }
        }
        $crc &= 0xFFFF;
    }
    return $crc;
}

// ===================================================================
//  PUBLIC CONFIG (sem autenticacao - branding do site)
// ===================================================================

// ===================================================================
//  ROLLOVER
// ===================================================================
function rollover_progresso_endpoint()
{
    $user      = auth_required();
    $progresso = rollover_progresso($user['id']);
    json_response($progresso);
}
function public_config()
{
    $links = json_decode(cfg('suporte_links', '[]'), true);
    if (!is_array($links)) $links = array();

    $cores = array(
        'cor_bg'           => cfg('cor_bg',           '#0a0612'),
        'cor_bg2'          => cfg('cor_bg2',          '#110d1e'),
        'cor_bg3'          => cfg('cor_bg3',          '#1a1430'),
        'cor_purple'       => cfg('cor_purple',       '#7c3aed'),
        'cor_purple2'      => cfg('cor_purple2',      '#9d5cff'),
        'cor_purple3'      => cfg('cor_purple3',      '#c084fc'),
        'cor_pink'         => cfg('cor_pink',         '#f472b6'),
        'cor_pink2'        => cfg('cor_pink2',        '#ff6b9d'),
        'cor_green'        => cfg('cor_green',        '#00e87a'),
        'cor_green2'       => cfg('cor_green2',       '#00c968'),
        'cor_red'          => cfg('cor_red',          '#ff4d6d'),
        'cor_yellow'       => cfg('cor_yellow',       '#fbbf24'),
        'cor_text'         => cfg('cor_text',         '#f0e8ff'),
    );

    json_response(array(
        'site_nome'        => cfg('site_nome',        'HelixWin'),
        'site_descricao'   => cfg('site_descricao',   ''),
        'site_suporte'     => cfg('site_suporte',     ''),
        'site_promo'       => cfg('site_promo',       ''),
        'site_logo_url'    => cfg('site_logo_url',    ''),
        'site_favicon_url' => cfg('site_favicon_url', ''),
        'suporte_links'    => $links,
        'manutencao'       => cfg('manutencao',       '0') === '1',
        'registro_aberto'  => cfg('registro_aberto',  '1') === '1',
        'demo_mode'        => cfg('demo_mode',        '1') === '1',
        'deposito_minimo'  => (float)cfg('deposito_minimo', 10),
        'saque_minimo'     => (float)cfg('saque_minimo', 20),
        'bonus_deposito_perc' => (float)cfg('bonus_deposito_perc', 0),
        'dep_presets'      => json_decode(cfg('dep_presets', '[]'), true) ?: array(),
        'cores'            => $cores,
        'banner_url'       => cfg('banner_url',  ''),
        'banner_link'      => cfg('banner_link', ''),
        'banners'          => array_values(array_filter([
            array('url' => cfg('banner_1_url',''), 'link' => cfg('banner_1_link','')),
            array('url' => cfg('banner_2_url',''), 'link' => cfg('banner_2_link','')),
            array('url' => cfg('banner_3_url',''), 'link' => cfg('banner_3_link','')),
            array('url' => cfg('banner_4_url',''), 'link' => cfg('banner_4_link','')),
            array('url' => cfg('banner_5_url',''), 'link' => cfg('banner_5_link','')),
        ], function($b){ return !empty($b['url']); })),
    ));
}
