<?php
// ===================================================================
//  includes/afiliado_regra.php
//  Sistema de controle de afiliados com regra X:Y configurável
//
//  COMO FUNCIONA:
//  - Regra "2:1" → a cada 2 convidados CONTADOS, o 3º é IGNORADO
//  - Regra "3:1" → a cada 3 contados, o 4º é ignorado
//  - Regra "5:2" → a cada 5 contados, os próximos 2 são ignorados
//
//  Convidados IGNORADOS:
//  - Funcionam normalmente (cadastro, depósito, saldo, jogo)
//  - NÃO aparecem na listagem do afiliador
//  - NÃO geram comissão para o afiliador
//  - NÃO são contabilizados nas estatísticas de indicação
//
//  Compatível com PHP 7.2+
// ===================================================================

/**
 * Analisa uma string de regra "X:Y" e retorna [contar => X, ignorar => Y].
 * Retorna null se a string for inválida.
 *
 * @param  string|null $regra  Ex.: "2:1", "3:1", "5:2"
 * @return array|null
 */
function afil_parse_regra($regra)
{
    if (!$regra || !is_string($regra)) return null;
    $regra = trim($regra);
    if (!preg_match('/^(\d+):(\d+)$/', $regra, $m)) return null;

    $contar  = (int)$m[1];
    $ignorar = (int)$m[2];

    if ($contar <= 0 || $ignorar <= 0) return null;

    return array('contar' => $contar, 'ignorar' => $ignorar);
}

/**
 * Retorna a regra efetiva para um afiliador:
 * prioridade → regra_personalizada do afiliador > regra global.
 *
 * @param  int $afiliadorId
 * @return array  ['contar' => int, 'ignorar' => int, 'raw' => string]
 */
function afil_regra_efetiva($afiliadorId)
{
    // Tenta regra personalizada do usuário
    $stmt = db()->prepare('SELECT afiliado_regra_personalizada FROM usuarios WHERE id = ? LIMIT 1');
    $stmt->execute(array($afiliadorId));
    $row = $stmt->fetch();

    $regraPersonalizada = ($row && !empty($row['afiliado_regra_personalizada']))
        ? $row['afiliado_regra_personalizada']
        : null;

    $parsed = $regraPersonalizada ? afil_parse_regra($regraPersonalizada) : null;

    if (!$parsed) {
        // Fallback: regra global
        $regraGlobal = cfg('afiliado_regra_padrao', '2:1');
        $parsed      = afil_parse_regra($regraGlobal);
        if (!$parsed) {
            // Regra global inválida — usa padrão absoluto
            $parsed     = array('contar' => 2, 'ignorar' => 1);
            $regraGlobal = '2:1';
        }
        $parsed['raw'] = $regraGlobal;
    } else {
        $parsed['raw'] = $regraPersonalizada;
    }

    return $parsed;
}

/**
 * Determina se um novo convidado deve ser CONTADO ou IGNORADO.
 *
 * Algoritmo:
 *   - A regra X:Y define ciclos de (X + Y) posições.
 *   - Dentro de cada ciclo: posições 1..X → contado; X+1..X+Y → ignorado.
 *
 * @param  int $afiliadorId    ID de quem indicou
 * @param  int $convidadoId   ID do novo usuário convidado
 * @param  string $momento    'cadastro' ou 'deposito'
 * @return array  ['status' => 'contado'|'ignorado', 'posicao' => int, 'regra' => string]
 */
function afil_classificar_convidado($afiliadorId, $convidadoId, $momento = 'deposito')
{
    // Sistema desligado → sempre conta
    if (cfg('afiliado_regra_ativo', '0') !== '1') {
        return array('status' => 'contado', 'posicao' => 0, 'regra' => 'desligado');
    }

    // Verifica se já foi classificado (idempotência)
    $stmtEx = db()->prepare('SELECT status, posicao, regra_aplicada FROM afiliado_regra_log WHERE afiliador_id = ? AND convidado_id = ? LIMIT 1');
    $stmtEx->execute(array($afiliadorId, $convidadoId));
    $existing = $stmtEx->fetch();
    if ($existing) {
        return array(
            'status'  => $existing['status'],
            'posicao' => (int)$existing['posicao'],
            'regra'   => $existing['regra_aplicada'],
        );
    }

    $regra = afil_regra_efetiva($afiliadorId);

    // Próxima posição = total de registros deste afiliador + 1
    $stmtPos = db()->prepare('SELECT COUNT(*) FROM afiliado_regra_log WHERE afiliador_id = ?');
    $stmtPos->execute(array($afiliadorId));
    $total   = (int)$stmtPos->fetchColumn();
    $posicao = $total + 1;

    // Calcula status pela posição no ciclo
    $ciclo     = $regra['contar'] + $regra['ignorar'];
    $posNoCiclo = (($posicao - 1) % $ciclo) + 1; // 1-based dentro do ciclo
    $status    = ($posNoCiclo <= $regra['contar']) ? 'contado' : 'ignorado';

    // Persiste no log
    $stmtIns = db()->prepare(
        'INSERT INTO afiliado_regra_log
            (afiliador_id, convidado_id, posicao, status, momento, regra_aplicada)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            status = VALUES(status), regra_aplicada = VALUES(regra_aplicada)'
    );
    $stmtIns->execute(array(
        $afiliadorId,
        $convidadoId,
        $posicao,
        $status,
        $momento,
        $regra['raw'],
    ));

    return array('status' => $status, 'posicao' => $posicao, 'regra' => $regra['raw']);
}

/**
 * Verifica se um convidado está IGNORADO para um afiliador específico.
 * Usado antes de pagar comissão e para filtrar listas.
 *
 * @param  int $afiliadorId
 * @param  int $convidadoId
 * @return bool  true = ignorado (não gera comissão, não aparece)
 */
function afil_convidado_ignorado($afiliadorId, $convidadoId)
{
    if (cfg('afiliado_regra_ativo', '0') !== '1') return false;

    $stmt = db()->prepare('SELECT status FROM afiliado_regra_log WHERE afiliador_id = ? AND convidado_id = ? LIMIT 1');
    $stmt->execute(array($afiliadorId, $convidadoId));
    $row = $stmt->fetch();

    // Se ainda não está no log, ainda não foi classificado → não ignora
    if (!$row) return false;

    return $row['status'] === 'ignorado';
}

/**
 * Retorna estatísticas da regra para um afiliador.
 * Útil para exibir no admin.
 *
 * @param  int $afiliadorId
 * @return array
 */
function afil_stats($afiliadorId)
{
    $stmt = db()->prepare(
        'SELECT
            COUNT(*) as total,
            SUM(status = "contado")  as contados,
            SUM(status = "ignorado") as ignorados
         FROM afiliado_regra_log
         WHERE afiliador_id = ?'
    );
    $stmt->execute(array($afiliadorId));
    $row = $stmt->fetch();

    return array(
        'total'    => (int)($row['total']    ?? 0),
        'contados' => (int)($row['contados'] ?? 0),
        'ignorados'=> (int)($row['ignorados'] ?? 0),
        'regra'    => afil_regra_efetiva($afiliadorId),
    );
}

/**
 * Retorna os IDs dos convidados IGNORADOS de um afiliador.
 * Usado para filtrar listas na rota /indicacao/info.
 *
 * @param  int $afiliadorId
 * @return int[]
 */
function afil_ids_ignorados($afiliadorId)
{
    if (cfg('afiliado_regra_ativo', '0') !== '1') return array();

    $stmt = db()->prepare(
        'SELECT convidado_id FROM afiliado_regra_log
         WHERE afiliador_id = ? AND status = "ignorado"'
    );
    $stmt->execute(array($afiliadorId));
    return array_column($stmt->fetchAll(), 'convidado_id');
}
