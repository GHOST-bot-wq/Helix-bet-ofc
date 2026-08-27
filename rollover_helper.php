<?php
// =====================================================================
//  rollover_helper.php — Lógica central do sistema de rollover
//  Inclua este arquivo em: api/index.php, admin/api.php,
//  api/pixup_webhook.php, api/expfypay_webhook.php
//  Compatível com PHP 7.2+
// =====================================================================

/**
 * Cria um rollover para um depósito aprovado.
 * Deve ser chamado DENTRO de uma transação DB já aberta quando possível,
 * ou com $db = null para usar uma transação própria.
 *
 * @param int   $usuarioId   ID do usuário
 * @param int   $transacaoId ID da transação de depósito
 * @param float $valorDep    Valor do depósito
 * @param PDO   $db          Conexão PDO (para reutilizar a transação do webhook)
 * @return bool true se rollover criado, false se sistema desativado
 */
function rollover_criar($usuarioId, $transacaoId, $valorDep, $db = null)
{
    // Sistema ativo?
    if (cfg('rollover_ativo', '0') !== '1') {
        return false;
    }

    // Apenas quando há bônus?
    $apenasComBonus = cfg('rollover_aplica_bonus', '0') === '1';
    if ($apenasComBonus) {
        $bonusPerc = (float)cfg('bonus_deposito_perc', 0);
        $bonusMin  = (float)cfg('bonus_deposito_minimo', 0);
        if ($bonusPerc <= 0 || $valorDep < $bonusMin) {
            return false;
        }
    }

    $tipo         = cfg('rollover_tipo', 'multiplicador');
    $multiplicador = (float)cfg('rollover_multiplicador', 1);
    $valorFixo    = (float)cfg('rollover_valor_fixo', 0);

    if ($tipo === 'multiplicador') {
        if ($multiplicador <= 0) return false;
        $valorExigido = round($valorDep * $multiplicador, 2);
        $multSalvo    = $multiplicador;
        $fixoSalvo    = null;
    } else {
        if ($valorFixo <= 0) return false;
        $valorExigido = $valorFixo;
        $multSalvo    = null;
        $fixoSalvo    = $valorFixo;
    }

    $conn = $db ?: db();
    $conn->prepare(
        'INSERT INTO rollovers
            (usuario_id, transacao_id, valor_deposito, tipo_rollover,
             multiplicador, valor_fixo, valor_exigido, valor_apostado, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, 0, "ativo")'
    )->execute(array(
        $usuarioId,
        $transacaoId,
        $valorDep,
        $tipo,
        $multSalvo,
        $fixoSalvo,
        $valorExigido,
    ));

    return true;
}

/**
 * Registra o valor de uma aposta nos rollovers ativos do usuário.
 * Deve ser chamado ao iniciar uma partida (game_iniciar).
 * Pode ser chamado dentro ou fora de transação existente.
 *
 * @param int   $usuarioId ID do usuário
 * @param float $valor     Valor apostado
 * @param PDO   $db        Conexão PDO opcional
 */
function rollover_registrar_aposta($usuarioId, $valor, $db = null)
{
    if (cfg('rollover_ativo', '0') !== '1') return;
    if ($valor <= 0) return;

    $conn = $db ?: db();

    // Busca rollovers ativos do usuário
    $stmt = $conn->prepare(
        'SELECT id, valor_exigido, valor_apostado
         FROM rollovers
         WHERE usuario_id = ? AND status = "ativo"
         ORDER BY created_at ASC'
    );
    $stmt->execute(array($usuarioId));
    $rollovers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rollovers as $r) {
        $restante     = max(0, (float)$r['valor_exigido'] - (float)$r['valor_apostado']);
        $incremento   = min($valor, $restante); // não ultrapassa o necessário
        $novoApostado = (float)$r['valor_apostado'] + $incremento;
        $completo     = $novoApostado >= (float)$r['valor_exigido'];

        if ($completo) {
            $conn->prepare(
                'UPDATE rollovers
                 SET valor_apostado = ?, status = "completo", completed_at = NOW()
                 WHERE id = ?'
            )->execute(array($novoApostado, $r['id']));
        } else {
            $conn->prepare(
                'UPDATE rollovers SET valor_apostado = ? WHERE id = ?'
            )->execute(array($novoApostado, $r['id']));
        }
    }
}

/**
 * Verifica se o usuário possui rollover pendente (bloqueia saque).
 *
 * @param int $usuarioId
 * @return array|null Array com dados do rollover ativo ou null se livre
 */
function rollover_pendente($usuarioId)
{
    if (cfg('rollover_ativo', '0') !== '1') return null;

    $stmt = db()->prepare(
        'SELECT id, valor_exigido, valor_apostado, valor_restante,
                tipo_rollover, multiplicador, valor_fixo,
                valor_deposito, created_at
         FROM rollovers
         WHERE usuario_id = ? AND status = "ativo"
         ORDER BY created_at ASC
         LIMIT 1'
    );
    $stmt->execute(array($usuarioId));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Retorna o progresso de todos os rollovers ativos do usuário.
 *
 * @param int $usuarioId
 * @return array
 */
function rollover_progresso($usuarioId)
{
    $stmt = db()->prepare(
        'SELECT id, valor_deposito, tipo_rollover, multiplicador, valor_fixo,
                valor_exigido, valor_apostado, valor_restante,
                status, created_at, completed_at
         FROM rollovers
         WHERE usuario_id = ?
         ORDER BY created_at DESC
         LIMIT 10'
    );
    $stmt->execute(array($usuarioId));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $ativos = array();
    $historico = array();
    foreach ($rows as $r) {
        $perc = (float)$r['valor_exigido'] > 0
            ? min(100, round(((float)$r['valor_apostado'] / (float)$r['valor_exigido']) * 100, 1))
            : 100;
        $r['percentual'] = $perc;
        $r['bloqueante'] = ($r['status'] === 'ativo');
        if ($r['status'] === 'ativo') {
            $ativos[] = $r;
        } else {
            $historico[] = $r;
        }
    }

    return array(
        'tem_rollover_ativo'  => count($ativos) > 0,
        'saque_bloqueado'     => count($ativos) > 0,
        'ativos'              => $ativos,
        'historico'           => $historico,
    );
}
