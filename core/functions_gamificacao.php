<?php
/**
 * Ferreira & Sá Conecta — Sistema de Gamificação
 *
 * gamificar() → registra pontos, atualiza totais, verifica nível, notifica
 * verificar_nivel() → compara total com gamificacao_niveis
 * salvar_evento_realtime() → salva para polling global
 */

// Tabela de pontos por evento
function _gamificacao_eventos()
{
    return array(
        // COMERCIAL
        'lead_cadastrado'        => array('pts' => 5,   'area' => 'comercial',    'desc' => 'Lead cadastrado'),
        'contrato_fechado'       => array('pts' => 50,  'area' => 'comercial',    'desc' => 'Contrato fechado'),
        'contrato_bonus_alto'    => array('pts' => 30,  'area' => 'comercial',    'desc' => 'Bônus contrato alto valor'),
        'onboarding_realizado'   => array('pts' => 20,  'area' => 'comercial',    'desc' => 'Onboarding realizado'),
        'avaliacao_5_estrelas'   => array('pts' => 40,  'area' => 'comercial',    'desc' => 'Avaliação 5 estrelas'),
        'meta_atingida'          => array('pts' => 100, 'area' => 'comercial',    'desc' => 'Meta mensal atingida'),
        // OPERACIONAL
        'processo_distribuido'   => array('pts' => 30,  'area' => 'operacional',  'desc' => 'Processo distribuído'),
        'peticao_distribuicao'   => array('pts' => 50,  'area' => 'operacional',  'desc' => 'Petição para distribuição'),
        'prazo_cumprido'         => array('pts' => 25,  'area' => 'operacional',  'desc' => 'Prazo cumprido'),
        'tarefa_concluida'       => array('pts' => 10,  'area' => 'operacional',  'desc' => 'Tarefa concluída'),
        // MANUAL
        'pontos_manuais'         => array('pts' => 0,   'area' => 'comercial',    'desc' => 'Pontos manuais'),
    );
}

/**
 * Registra pontos de gamificação para um usuário
 */
function gamificar($user_id, $evento, $referencia_id = null, $referencia_tipo = null, $pontos_override = null)
{
    $user_id = (int)$user_id;
    if ($user_id <= 0) return false;

    $eventos = _gamificacao_eventos();
    if (!isset($eventos[$evento])) return false;

    $cfg    = $eventos[$evento];
    $pontos = ($pontos_override !== null) ? (int)$pontos_override : $cfg['pts'];
    $area   = $cfg['area'];
    $desc   = $cfg['desc'];
    $mes    = (int)date('n');
    $ano    = (int)date('Y');

    if ($pontos <= 0) return false;

    $pdo = db();

    // 1. Registrar ponto
    $pdo->prepare(
        "INSERT INTO gamificacao_pontos (user_id, evento, area, pontos, descricao, referencia_id, referencia_tipo, mes, ano)
         VALUES (?,?,?,?,?,?,?,?,?)"
    )->execute(array($user_id, $evento, $area, $pontos, $desc, $referencia_id, $referencia_tipo, $mes, $ano));

    // 2. Atualizar totais
    $campo_mes   = "pontos_mes_{$area}";
    $campo_total = "pontos_total_{$area}";

    $isContrato = in_array($evento, array('contrato_fechado'));

    $pdo->prepare(
        "INSERT INTO gamificacao_totais (user_id, {$campo_mes}, {$campo_total}, contratos_mes, contratos_total, mes_referencia, ano_referencia)
         VALUES (?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            {$campo_mes}   = IF(mes_referencia = ? AND ano_referencia = ?, {$campo_mes} + ?, ?),
            {$campo_total} = {$campo_total} + ?,
            contratos_mes  = IF(mes_referencia = ? AND ano_referencia = ?, contratos_mes + ?, ?),
            contratos_total= contratos_total + ?,
            mes_referencia = ?,
            ano_referencia = ?"
    )->execute(array(
        $user_id, $pontos, $pontos, ($isContrato ? 1 : 0), ($isContrato ? 1 : 0), $mes, $ano,
        $mes, $ano, $pontos, $pontos,
        $pontos,
        $mes, $ano, ($isContrato ? 1 : 0), ($isContrato ? 1 : 0),
        ($isContrato ? 1 : 0),
        $mes, $ano
    ));

    // 3. Verificar upgrade de nível
    $nivelNovo = _verificar_nivel($user_id);

    // 4. Buscar dados do usuário para o evento
    $userName = '';
    try {
        $stmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
        $stmt->execute(array($user_id));
        $userName = $stmt->fetchColumn() ?: '';
    } catch (Exception $e) {}

    $iniciais = '';
    $parts = explode(' ', $userName);
    if (count($parts) >= 2) {
        $iniciais = mb_strtoupper(mb_substr($parts[0], 0, 1) . mb_substr(end($parts), 0, 1));
    } elseif ($userName) {
        $iniciais = mb_strtoupper(mb_substr($userName, 0, 2));
    }

    // 5. Salvar evento para polling global
    $payload = array(
        'user_id'    => $user_id,
        'nome'       => explode(' ', $userName)[0],
        'iniciais'   => $iniciais,
        'evento'     => $evento,
        'descricao'  => $desc,
        'pontos'     => $pontos,
        'area'       => $area,
        'nivel_novo' => $nivelNovo,
    );
    _salvar_evento_realtime($payload);

    // 6. Notificação interna
    try {
        if (function_exists('notify')) {
            notify($user_id, "🎉 +{$pontos} pontos — {$desc}", 'gamificacao');
        }
    } catch (Exception $e) {}

    return $payload;
}

/**
 * Verifica e atualiza nível de carreira do usuário
 */
function _verificar_nivel($user_id)
{
    $pdo = db();

    $stmt = $pdo->prepare("SELECT pontos_total_comercial + pontos_total_operacional as total, nivel_num FROM gamificacao_totais WHERE user_id = ?");
    $stmt->execute(array($user_id));
    $row = $stmt->fetch();
    if (!$row) return null;

    $totalPts = (int)$row['total'];
    $nivelAtual = (int)$row['nivel_num'];

    // Buscar nível correto
    $stmt2 = $pdo->prepare("SELECT nivel_num, nome, badge_emoji FROM gamificacao_niveis WHERE pontos_minimos <= ? ORDER BY pontos_minimos DESC LIMIT 1");
    $stmt2->execute(array($totalPts));
    $nivel = $stmt2->fetch();

    if ($nivel && (int)$nivel['nivel_num'] > $nivelAtual) {
        $pdo->prepare("UPDATE gamificacao_totais SET nivel = ?, nivel_num = ? WHERE user_id = ?")
            ->execute(array($nivel['nome'], $nivel['nivel_num'], $user_id));
        return array('nome' => $nivel['nome'], 'emoji' => $nivel['badge_emoji'], 'num' => $nivel['nivel_num']);
    }

    return null;
}

/**
 * Salva evento para o polling de tempo real
 */
function _salvar_evento_realtime($payload)
{
    try {
        db()->prepare("INSERT INTO gamificacao_eventos (payload) VALUES (?)")
            ->execute(array(json_encode($payload, JSON_UNESCAPED_UNICODE)));
        // Limpar eventos antigos (>5 min)
        db()->exec("DELETE FROM gamificacao_eventos WHERE created_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)");
    } catch (Exception $e) {}
}

/**
 * Buscar eventos recentes para polling (últimos N segundos)
 */
function gamificacao_check_eventos($segundos = 15)
{
    try {
        $stmt = db()->prepare("SELECT payload FROM gamificacao_eventos WHERE created_at > DATE_SUB(NOW(), INTERVAL ? SECOND) ORDER BY created_at ASC");
        $stmt->execute(array($segundos));
        $rows = $stmt->fetchAll();
        $eventos = array();
        foreach ($rows as $r) {
            $p = json_decode($r['payload'], true);
            if ($p) $eventos[] = $p;
        }
        return $eventos;
    } catch (Exception $e) {
        return array();
    }
}

/**
 * Buscar posição do usuário no ranking mensal
 */
function gamificacao_posicao($user_id, $area = 'comercial')
{
    $campo = "pontos_mes_{$area}";
    $mes = (int)date('n');
    $ano = (int)date('Y');
    try {
        $stmt = db()->prepare(
            "SELECT user_id, {$campo} as pts FROM gamificacao_totais
             WHERE mes_referencia = ? AND ano_referencia = ? AND {$campo} > 0
             ORDER BY {$campo} DESC"
        );
        $stmt->execute(array($mes, $ano));
        $pos = 1;
        foreach ($stmt->fetchAll() as $r) {
            if ((int)$r['user_id'] === (int)$user_id) return $pos;
            $pos++;
        }
    } catch (Exception $e) {}
    return 0;
}
