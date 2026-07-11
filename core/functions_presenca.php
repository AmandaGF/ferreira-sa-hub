<?php
/**
 * Presença — Motor de sugestões, mudança de status e baixa de estoque.
 * Amanda 11/07/2026 — Fase 2 do blueprint.
 */

/**
 * Descobre o perfil do cliente pela MAIOR faixa de honorários dos processos ativos dele.
 * Retorna o registro do perfil (linha do presenca_perfil) ou null.
 *
 * @param PDO $pdo
 * @param int $clientId
 * @return array|null
 */
function presenca_perfil_do_cliente(PDO $pdo, $clientId) {
    if (!$clientId) return null;
    $st = $pdo->prepare("
        SELECT MAX(estimated_value_cents)/100 AS honorario
        FROM cases WHERE client_id = ? AND status NOT IN ('cancelado','arquivado')
    ");
    $st->execute(array((int)$clientId));
    $h = (float)$st->fetchColumn();
    if ($h <= 0) {
        $st = $pdo->prepare("SELECT MAX(estimated_value_cents)/100 FROM cases WHERE client_id = ?");
        $st->execute(array((int)$clientId));
        $h = (float)$st->fetchColumn();
    }
    if ($h <= 0) return null;
    $perfis = $pdo->query("SELECT * FROM presenca_perfil WHERE ativo = 1 ORDER BY ordem, id")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($perfis as $p) {
        $ok = true;
        if ($p['ticket_min'] !== null && $h < (float)$p['ticket_min']) $ok = false;
        if ($p['ticket_max'] !== null && $h > (float)$p['ticket_max']) $ok = false;
        if ($ok) return $p;
    }
    return null;
}

/**
 * Retorna a restrição ATIVA mais forte pro cliente (opcional processo).
 * 'nao_enviar' > 'confirmar_endereco'. Restrição específica do processo tem prioridade.
 */
function presenca_restricao(PDO $pdo, $clientId, $processoId = null) {
    $st = $pdo->prepare("
        SELECT * FROM presenca_restricao
        WHERE cliente_id = ? AND ativo = 1 AND (processo_id IS NULL OR processo_id = ?)
        ORDER BY (processo_id IS NOT NULL) DESC,
                 CASE nivel WHEN 'nao_enviar' THEN 0 ELSE 1 END
        LIMIT 1
    ");
    $st->execute(array((int)$clientId, (int)$processoId));
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Calcula a data-limite pra pedir ao fornecedor: data_alvo - (produção + entrega)
 * em dias úteis (segunda a sexta, sem feriados nacionais fixos).
 * Cálculo simples — quando a Calculadora de Prazos do Conecta for exposta como
 * função helper, aqui é o ponto de troca.
 */
function presenca_data_pedido_limite($dataAlvo, $totalDiasUteis) {
    if (!$dataAlvo || $totalDiasUteis <= 0) return $dataAlvo;
    $t = strtotime($dataAlvo);
    $rest = (int)$totalDiasUteis;
    while ($rest > 0) {
        $t = strtotime('-1 day', $t);
        $dow = (int)date('N', $t); // 1=seg, 7=dom
        if ($dow < 6) $rest--;
    }
    return date('Y-m-d', $t);
}

/**
 * Retorna prazo total (produção + entrega) do orçamento ESCOLHIDO do brinde.
 * Fallback: config lead_dias_padrao.
 */
function presenca_prazo_do_brinde(PDO $pdo, $brindeId) {
    if (!$brindeId) return 15;
    $st = $pdo->prepare("
        SELECT COALESCE(prazo_producao_dias, 0) + COALESCE(prazo_entrega_dias, 0) AS tot
        FROM presenca_orcamento WHERE brinde_id = ? AND escolhido = 1 LIMIT 1
    ");
    $st->execute(array((int)$brindeId));
    $tot = (int)$st->fetchColumn();
    if ($tot > 0) return $tot;
    $st = $pdo->query("SELECT valor FROM presenca_config WHERE chave='lead_dias_padrao' LIMIT 1");
    return (int)($st->fetchColumn() ?: 15);
}

/**
 * Custo previsto do brinde = custo total do orçamento ESCOLHIDO (unit * lote + frete)
 * dividido pelo lote (custo unitário embalado). Fallback: verba_prevista da regra ou 0.
 */
function presenca_custo_previsto(PDO $pdo, $brindeId, $verbaFallback = 0.0) {
    if (!$brindeId) return (float)$verbaFallback;
    $st = $pdo->prepare("
        SELECT o.valor_unitario, o.qtd_minima, o.frete, b.qtd_compra_referencia
        FROM presenca_orcamento o JOIN presenca_brinde b ON b.id = o.brinde_id
        WHERE o.brinde_id = ? AND o.escolhido = 1 LIMIT 1
    ");
    $st->execute(array((int)$brindeId));
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return (float)$verbaFallback;
    $qtd = max(1, (int)$row['qtd_compra_referencia']);
    $custoUnit = ((float)$row['valor_unitario']) + ((float)$row['frete']) / $qtd;
    return round($custoUnit, 2);
}

/**
 * Função central: sugere um envio pra (cliente, fase). Aplica todas as regras:
 *  1. Descobre perfil do cliente pela faixa
 *  2. Barreira de sensibilidade (não fura restrição)
 *  3. Busca regra ativa (perfil × fase); se não houver, retorna null
 *  4. Dedup: não cria se já existe envio (cliente, fase) não cancelado
 *  5. Calcula data_pedido_limite pelo prazo do orçamento escolhido
 *
 * @return int|null  ID do envio criado ou null (com $reason preenchido por ref)
 */
function presenca_sugerir_envio(PDO $pdo, $clienteId, $faseSlug, $dataAlvo = null, $origem = 'manual', $processoId = null, &$reason = null) {
    $reason = null;
    $clienteId = (int)$clienteId;
    if (!$clienteId) { $reason = 'cliente_ausente'; return null; }

    // 1) Perfil
    $perfil = presenca_perfil_do_cliente($pdo, $clienteId);
    if (!$perfil) { $reason = 'sem_perfil'; return null; }

    // Fase
    $st = $pdo->prepare("SELECT * FROM presenca_fase WHERE slug = ? AND ativo = 1 LIMIT 1");
    $st->execute(array($faseSlug));
    $fase = $st->fetch(PDO::FETCH_ASSOC);
    if (!$fase) { $reason = 'fase_invalida'; return null; }

    // 2) Sensibilidade
    $restr = presenca_restricao($pdo, $clienteId, $processoId);
    $bloqueado = 0; $motivoBloq = null;
    if ($restr) {
        if ($restr['nivel'] === 'nao_enviar') { $reason = 'restricao_nao_enviar'; return null; }
        $bloqueado = 1;
        $motivoBloq = 'Confirmar endereço antes de enviar — ' . ($restr['motivo'] ?: 'restrição ativa');
    }

    // 3) Regra
    $st = $pdo->prepare("SELECT * FROM presenca_regra WHERE perfil_id = ? AND fase_id = ? AND ativo = 1 LIMIT 1");
    $st->execute(array((int)$perfil['id'], (int)$fase['id']));
    $regra = $st->fetch(PDO::FETCH_ASSOC);
    if (!$regra || (empty($regra['brinde_id']) && empty($regra['frase_id']) && (float)$regra['verba_prevista'] <= 0)) {
        $reason = 'sem_regra'; return null;
    }

    // 4) Dedup
    $st = $pdo->prepare("SELECT id FROM presenca_envio WHERE cliente_id = ? AND fase_id = ? AND status <> 'cancelado' LIMIT 1");
    $st->execute(array($clienteId, (int)$fase['id']));
    if ($st->fetchColumn()) { $reason = 'ja_existe'; return null; }

    // 5) Data-limite
    if (!$dataAlvo) $dataAlvo = date('Y-m-d', strtotime('+7 days'));
    $prazo = presenca_prazo_do_brinde($pdo, (int)$regra['brinde_id']);
    $dataPedidoLim = presenca_data_pedido_limite($dataAlvo, $prazo);

    // Custo previsto (usa verba da regra como fallback)
    $custoPrev = presenca_custo_previsto($pdo, (int)$regra['brinde_id'], (float)$regra['verba_prevista']);
    if ($custoPrev <= 0) $custoPrev = (float)$regra['verba_prevista'];

    // Centro de custo do Presença
    $ccId = null;
    try { $ccId = (int)$pdo->query("SELECT id FROM centro_custo WHERE slug='presenca' LIMIT 1")->fetchColumn() ?: null; } catch (Exception $e) {}

    $st = $pdo->prepare("INSERT INTO presenca_envio
        (cliente_id, processo_id, perfil_id, fase_id, brinde_id, frase_id, status, bloqueado, bloqueio_motivo,
         data_alvo, data_pedido_limite, data_sugerida, custo_previsto, centro_custo_id, origem)
        VALUES (?, ?, ?, ?, ?, ?, 'sugerido', ?, ?, ?, ?, CURDATE(), ?, ?, ?)");
    $st->execute(array(
        $clienteId, $processoId ?: null, (int)$perfil['id'], (int)$fase['id'],
        !empty($regra['brinde_id']) ? (int)$regra['brinde_id'] : null,
        !empty($regra['frase_id']) ? (int)$regra['frase_id'] : null,
        $bloqueado, $motivoBloq, $dataAlvo, $dataPedidoLim, $custoPrev, $ccId, $origem
    ));
    $envId = (int)$pdo->lastInsertId();
    if (function_exists('audit_log')) audit_log('presenca_envio_sugerido', 'presenca_envio', $envId, "cliente=$clienteId fase={$fase['slug']} origem=$origem");
    return $envId;
}

/**
 * Muda o status do envio + aplica efeitos colaterais:
 *   - aprovado: registra aprovado_por + data_aprovacao
 *   - enviado : baixa o estoque (do brinde ou componentes do kit) + registra data_envio
 *   - entregue: registra data_entrega
 *   - cancelado: só marca (não desfaz baixa de estoque)
 */
function presenca_mudar_status(PDO $pdo, $envioId, $novoStatus, $dados = array()) {
    $validos = array('sugerido','aprovado','em_producao','enviado','entregue','cancelado');
    if (!in_array($novoStatus, $validos, true)) return array('ok'=>false, 'erro'=>'status invalido');

    $st = $pdo->prepare("SELECT * FROM presenca_envio WHERE id = ? LIMIT 1");
    $st->execute(array((int)$envioId));
    $env = $st->fetch(PDO::FETCH_ASSOC);
    if (!$env) return array('ok'=>false, 'erro'=>'envio nao encontrado');

    // Bloqueado (sensibilidade) só sai da coluna sugerido depois de destravar
    if (!empty($env['bloqueado']) && $novoStatus !== 'cancelado' && $env['status'] === 'sugerido' && $novoStatus !== 'sugerido') {
        if (empty($dados['forca_desbloqueio'])) {
            return array('ok'=>false, 'erro'=>'bloqueado por restricao — confirme endereco antes');
        }
    }

    $userId = function_exists('current_user_id') ? current_user_id() : null;
    $extraSets = array(); $extraVals = array();

    if ($novoStatus === 'aprovado' && empty($env['data_aprovacao'])) {
        $extraSets[] = "aprovado_por = ?, data_aprovacao = NOW()";
        $extraVals[] = $userId;
    }

    if ($novoStatus === 'enviado') {
        $extraSets[] = "data_envio = ?";
        $extraVals[] = !empty($dados['data_envio']) ? $dados['data_envio'] : date('Y-m-d');
        if (isset($dados['custo_real'])) { $extraSets[] = "custo_real = ?"; $extraVals[] = (float)$dados['custo_real']; }
        if (!empty($dados['fornecedor_id'])) { $extraSets[] = "fornecedor_id = ?"; $extraVals[] = (int)$dados['fornecedor_id']; }
        if (!empty($dados['rastreio'])) { $extraSets[] = "rastreio = ?"; $extraVals[] = trim($dados['rastreio']); }
        // Baixa estoque do brinde (ou componentes se kit) — só na PRIMEIRA vez que vai pra 'enviado'
        if ($env['status'] !== 'enviado' && !empty($env['brinde_id'])) {
            _presenca_baixar_estoque_do_envio($pdo, (int)$env['brinde_id']);
        }
    }

    if ($novoStatus === 'entregue') {
        $extraSets[] = "data_entrega = ?";
        $extraVals[] = !empty($dados['data_entrega']) ? $dados['data_entrega'] : date('Y-m-d');
    }

    $sql = "UPDATE presenca_envio SET status = ?";
    $vals = array($novoStatus);
    if ($extraSets) { $sql .= ', ' . implode(', ', $extraSets); $vals = array_merge($vals, $extraVals); }
    $sql .= " WHERE id = ?";
    $vals[] = (int)$envioId;
    $pdo->prepare($sql)->execute($vals);

    if (function_exists('audit_log')) audit_log('presenca_envio_status', 'presenca_envio', (int)$envioId, $env['status'] . ' -> ' . $novoStatus);
    return array('ok'=>true, 'novo'=>$novoStatus);
}

/**
 * Baixa estoque. Se brinde é kit, baixa cada componente pela quantidade.
 * Senão, baixa 1 do próprio brinde.
 */
function _presenca_baixar_estoque_do_envio(PDO $pdo, $brindeId) {
    $st = $pdo->prepare("SELECT eh_kit FROM presenca_brinde WHERE id = ?");
    $st->execute(array($brindeId));
    $ehKit = (int)$st->fetchColumn();
    if ($ehKit) {
        $stC = $pdo->prepare("SELECT componente_id, quantidade FROM presenca_brinde_componente WHERE kit_id = ?");
        $stC->execute(array($brindeId));
        $upd = $pdo->prepare("UPDATE presenca_estoque SET estoque_atual = GREATEST(0, estoque_atual - ?) WHERE brinde_id = ?");
        foreach ($stC as $c) $upd->execute(array((int)$c['quantidade'], (int)$c['componente_id']));
    } else {
        $pdo->prepare("UPDATE presenca_estoque SET estoque_atual = GREATEST(0, estoque_atual - 1) WHERE brinde_id = ?")->execute(array($brindeId));
    }
}

/**
 * Desbloqueia envio (Amanda confirmou endereço da cliente).
 */
function presenca_desbloquear(PDO $pdo, $envioId, $motivoConf = '') {
    $pdo->prepare("UPDATE presenca_envio SET bloqueado = 0, bloqueio_motivo = NULL WHERE id = ?")->execute(array((int)$envioId));
    if (function_exists('audit_log')) audit_log('presenca_envio_desbloqueio', 'presenca_envio', (int)$envioId, $motivoConf);
}
