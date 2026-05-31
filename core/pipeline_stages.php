<?php
/**
 * Pipeline Stages — fonte única da verdade
 *
 * Criado em 31/05/2026 após o bug Nilce r17: vários arquivos usavam strings
 * literais de stages que NÃO existiam mais no pipeline atual ('contrato',
 * 'preparacao_pasta'). O Relatórios sub-contava conversões em silêncio por
 * meses sem warning algum.
 *
 * Use SEMPRE estas constantes/funções em vez de strings cruas. Quando algum
 * stage for renomeado/dividido/excluído, só este arquivo muda — o resto
 * pega automaticamente.
 *
 * Stages canônicos definidos em modules/pipeline/index.php (linha ~20).
 */

// ── KANBAN (10 stages visíveis no quadro) ──
define('PIPELINE_STAGE_CADASTRO',     'cadastro_preenchido');
define('PIPELINE_STAGE_ELABORACAO',   'elaboracao_docs');
define('PIPELINE_STAGE_LINK',         'link_enviados');
define('PIPELINE_STAGE_CONTRATO',     'contrato_assinado');
define('PIPELINE_STAGE_AGENDADO',     'agendado_docs');
define('PIPELINE_STAGE_REUNIAO',      'reuniao_cobranca');
define('PIPELINE_STAGE_DOC_FALTANTE', 'doc_faltante');
define('PIPELINE_STAGE_PASTA_APTA',   'pasta_apta');
define('PIPELINE_STAGE_CANCELADO',    'cancelado');
define('PIPELINE_STAGE_SUSPENSO',     'suspenso');
define('PIPELINE_STAGE_PARA_ARQUIVAR','para_arquivar');

// ── TERMINAIS (saem do Kanban, ficam no histórico) ──
define('PIPELINE_STAGE_FINALIZADO',   'finalizado');
define('PIPELINE_STAGE_PERDIDO',      'perdido');
define('PIPELINE_STAGE_ARQUIVADO',    'arquivado');

/**
 * Stages pré-contrato (lead AINDA não assinou).
 */
function pipeline_stages_pre_contrato() {
    return array(
        PIPELINE_STAGE_CADASTRO,
        PIPELINE_STAGE_ELABORACAO,
        PIPELINE_STAGE_LINK,
    );
}

/**
 * Stages pós-contrato (lead JÁ assinou — usado pra contar "conversões").
 * Inclui finalizado (caso virou processo no Operacional).
 *
 * Esta é a definição CANÔNICA de "conversão":
 *   SELECT COUNT(*) FROM pipeline_leads
 *   WHERE stage IN pipeline_stages_pos_contrato()
 *     AND DATE(converted_at) BETWEEN ? AND ?
 */
function pipeline_stages_pos_contrato() {
    return array(
        PIPELINE_STAGE_CONTRATO,
        PIPELINE_STAGE_AGENDADO,
        PIPELINE_STAGE_REUNIAO,
        PIPELINE_STAGE_DOC_FALTANTE,
        PIPELINE_STAGE_PASTA_APTA,
        PIPELINE_STAGE_FINALIZADO,
    );
}

/**
 * Stages ativos no Kanban (excluindo terminais e arquivados).
 * Útil pra queries de "leads em fluxo".
 */
function pipeline_stages_ativos_kanban() {
    return array(
        PIPELINE_STAGE_CADASTRO,
        PIPELINE_STAGE_ELABORACAO,
        PIPELINE_STAGE_LINK,
        PIPELINE_STAGE_CONTRATO,
        PIPELINE_STAGE_AGENDADO,
        PIPELINE_STAGE_REUNIAO,
        PIPELINE_STAGE_DOC_FALTANTE,
        PIPELINE_STAGE_PASTA_APTA,
        PIPELINE_STAGE_SUSPENSO,
        PIPELINE_STAGE_PARA_ARQUIVAR,
    );
}

/**
 * Stages terminais (lead saiu do funil, não conta mais como ativo).
 */
function pipeline_stages_terminais() {
    return array(
        PIPELINE_STAGE_FINALIZADO,
        PIPELINE_STAGE_PERDIDO,
        PIPELINE_STAGE_ARQUIVADO,
        PIPELINE_STAGE_CANCELADO,
    );
}

/**
 * Labels visuais por stage (pt-BR). Use em renderização de gráficos/cards.
 */
function pipeline_stage_label($stage) {
    static $map = array(
        PIPELINE_STAGE_CADASTRO      => 'Cadastro',
        PIPELINE_STAGE_ELABORACAO    => 'Elaboração',
        PIPELINE_STAGE_LINK          => 'Link Enviado',
        PIPELINE_STAGE_CONTRATO      => 'Contrato',
        PIPELINE_STAGE_AGENDADO      => 'Agendado',
        PIPELINE_STAGE_REUNIAO       => 'Cobrando Docs',
        PIPELINE_STAGE_DOC_FALTANTE  => 'Doc Faltante',
        PIPELINE_STAGE_PASTA_APTA    => 'Pasta Apta',
        PIPELINE_STAGE_SUSPENSO      => 'Suspenso',
        PIPELINE_STAGE_PARA_ARQUIVAR => 'Para Arquivar',
        PIPELINE_STAGE_FINALIZADO    => 'Finalizado',
        PIPELINE_STAGE_PERDIDO       => 'Perdido',
        PIPELINE_STAGE_ARQUIVADO     => 'Arquivado',
        PIPELINE_STAGE_CANCELADO     => 'Cancelado',
    );
    return isset($map[$stage]) ? $map[$stage] : $stage;
}

/**
 * Helper: monta '?,?,?...' pra usar em IN(...) com PDO.
 *   $stages = pipeline_stages_pos_contrato();
 *   $sql = "WHERE stage IN (" . pipeline_stages_in_placeholders($stages) . ")";
 *   $stmt->execute($stages);
 */
function pipeline_stages_in_placeholders($stages) {
    return implode(',', array_fill(0, count($stages), '?'));
}
