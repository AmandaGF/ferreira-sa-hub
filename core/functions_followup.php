<?php
/**
 * Follow-up comercial — ITEM 1 (Speed-to-lead).
 *
 * Hook chamado por process_form_submission() ao criar um lead NOVO: envia o 1º
 * contato (A1) pelo canal 21 e grava pipeline_leads.primeiro_contato_em.
 *
 * SEGURANÇA / KILL SWITCH (nasce DESLIGADO):
 *   - configuracoes.followup_ativo            = '0'  (mestre)
 *   - configuracoes.followup_speed_to_lead    = '0'  (item 1)
 *   Com qualquer um desligado, o hook é INERTE (não envia, não grava) — só loga.
 *
 * Itens 2–5 (Trilhas A/B, scheduler, KPIs) virão depois; este arquivo cobre só o 1.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions_zapi.php';

function followup_log($msg) {
    @file_put_contents(__DIR__ . '/../files/followup.log', '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
}

/** Lê toggle followup_* do banco (cacheado). */
function followup_cfg($chave, $default = '') {
    static $cache = null;
    if ($cache === null) {
        $cache = array();
        try {
            foreach (db()->query("SELECT chave, valor FROM configuracoes WHERE chave LIKE 'followup_%'")->fetchAll() as $r) {
                $cache[$r['chave']] = $r['valor'];
            }
        } catch (Exception $e) {}
    }
    return isset($cache[$chave]) ? $cache[$chave] : $default;
}

/** Dicionário slug case_type → rótulo legível. NUNCA devolve o slug cru. */
function followup_tema_legivel($caseType) {
    $ct = strtolower(trim((string)$caseType));
    $dic = array(
        'pensao_alimenticia' => 'pensão alimentícia',
        'alimentos' => 'pensão alimentícia',
        'divorcio' => 'divórcio',
        'divorcio_litigioso' => 'divórcio',
        'divorcio_consensual' => 'divórcio',
        'guarda' => 'guarda dos filhos',
        'convivencia' => 'regulamentação de convivência',
        'visitas' => 'regulamentação de convivência',
        'regulamentacao_visitas' => 'regulamentação de convivência',
        'investigacao_paternidade' => 'reconhecimento de paternidade',
        'paternidade' => 'reconhecimento de paternidade',
        'uniao_estavel' => 'reconhecimento de união estável',
        'alienacao_parental' => 'alienação parental',
        'inventario' => 'inventário e partilha',
    );
    if ($ct === '') return 'sua questão familiar';
    if (isset($dic[$ct])) return $dic[$ct];
    $legivel = trim(str_replace('_', ' ', $ct));
    return $legivel !== '' ? $legivel : 'sua questão familiar';
}

/** Sources que entram no speed-to-lead automático (decisão 2). */
function followup_source_elegivel($source) {
    $s = strtolower(trim((string)$source));
    if ($s === 'whatsapp') return false; // já está em conversa ativa
    return true;
}

/** Escolhe o template A1 conforme horário e origem. */
function followup_template_a1($source, $foraHorario) {
    if ($foraHorario) return 'Follow A1 - Fora de horario';
    if (strtolower(trim((string)$source)) === 'indicacao') return 'Follow A1 - Abertura (indicacao)';
    return 'Follow A1 - Abertura (form/anuncio)';
}

/**
 * Speed-to-lead: 1º contato no lead novo.
 * @param PDO  $pdo
 * @param int  $leadId
 * @param bool $dry  se true, NÃO envia nem grava — só retorna o que FARIA (teste).
 * @return array diagnóstico
 */
function followup_speed_to_lead($pdo, $leadId, $dry = false) {
    $leadId = (int)$leadId;
    $out = array('lead_id' => $leadId, 'acao' => '', 'enviado' => false, 'motivo' => '', 'template' => '', 'fora_horario' => 0, 'ligado' => 0, 'mensagem' => '');

    $st = $pdo->prepare("SELECT id, name, phone, source, case_type, client_id, stage, primeiro_contato_em FROM pipeline_leads WHERE id = ?");
    $st->execute(array($leadId));
    $lead = $st->fetch();
    if (!$lead) { $out['acao'] = 'skip'; $out['motivo'] = 'lead inexistente'; return $out; }

    if (!empty($lead['primeiro_contato_em'])) { $out['acao'] = 'skip'; $out['motivo'] = 'primeiro_contato_em ja setado'; return $out; }
    if ($lead['stage'] !== 'cadastro_preenchido') { $out['acao'] = 'skip'; $out['motivo'] = "stage={$lead['stage']} (!= cadastro_preenchido)"; return $out; }
    if (!followup_source_elegivel($lead['source'])) { $out['acao'] = 'skip'; $out['motivo'] = "source nao elegivel ({$lead['source']})"; return $out; }
    $phoneDig = preg_replace('/\D/', '', (string)$lead['phone']);
    if (strlen($phoneDig) < 10) { $out['acao'] = 'skip'; $out['motivo'] = 'telefone invalido'; return $out; }

    $ligado = (followup_cfg('followup_ativo', '0') === '1' && followup_cfg('followup_speed_to_lead', '0') === '1');
    $out['ligado'] = $ligado ? 1 : 0;

    $foraHorario = zapi_fora_horario();
    $out['fora_horario'] = $foraHorario ? 1 : 0;
    $tplNome = followup_template_a1($lead['source'], $foraHorario);
    $out['template'] = $tplNome;

    $nome = '';
    if (!empty($lead['client_id'])) $nome = zapi_nome_saudacao((int)$lead['client_id']);
    if ($nome === '') $nome = explode(' ', trim((string)$lead['name']))[0];
    $tema = followup_tema_legivel($lead['case_type']);

    $vars = array('nome' => $nome, 'tema' => $tema);
    if (!empty($lead['client_id'])) $vars['client_id'] = (int)$lead['client_id'];
    $msg = zapi_get_template($tplNome, $vars);
    $out['mensagem'] = $msg;

    if (!$msg) { $out['acao'] = 'erro'; $out['motivo'] = "template '$tplNome' ausente/vazio"; followup_log("lead $leadId: template ausente $tplNome"); return $out; }

    if ($dry) { $out['acao'] = 'dry'; $out['motivo'] = $ligado ? 'enviaria agora' : 'enviaria, mas kill switch DESLIGADO'; return $out; }

    if (!$ligado) {
        $out['acao'] = 'desligado'; $out['motivo'] = 'kill switch off — nada enviado';
        followup_log("lead $leadId: kill switch OFF — inerte (tpl=$tplNome)");
        return $out;
    }

    $r = zapi_send_text('21', $lead['phone'], $msg);
    if (!empty($r['ok'])) {
        $pdo->prepare("UPDATE pipeline_leads SET primeiro_contato_em = NOW() WHERE id = ? AND primeiro_contato_em IS NULL")->execute(array($leadId));
        $out['acao'] = 'enviado'; $out['enviado'] = true;
        followup_log("lead $leadId: A1 enviado ($tplNome) tema=$tema");
        if (function_exists('audit_log')) { try { audit_log('followup_speed_to_lead', 'lead', $leadId, $tplNome); } catch (Exception $e) {} }
    } else {
        $out['acao'] = 'falha'; $out['motivo'] = 'http ' . ($r['http_code'] ?? '?') . ' ' . ($r['erro'] ?? '');
        followup_log("lead $leadId: FALHA envio — " . $out['motivo']);
    }
    return $out;
}
