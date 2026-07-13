<?php
/**
 * Helpers do modulo Previdenciario — Amanda 13/07/2026.
 * Centraliza a lista de tipos de benefício previdenciário pra reuso em
 * modules/prev/index.php, caso_novo.php e outros.
 */

/**
 * Lista completa de tipos de demanda previdenciária, agrupada por categoria.
 * Baseada em RGPS/INSS + LOAS + Regras de Transição EC 103/2019 + RPPS + Complementar.
 * Retorna array associativo: categoria => array de tipos.
 */
function prev_tipos_beneficio_agrupados() {
    return array(
        'BPC / LOAS' => array(
            'BPC — Pessoa com Deficiência',
            'BPC — Idoso 65+',
        ),
        'Aposentadorias' => array(
            'Aposentadoria por Idade Urbana',
            'Aposentadoria por Idade Rural (Segurado Especial)',
            'Aposentadoria por Idade Híbrida (Urbano + Rural)',
            'Aposentadoria por Tempo de Contribuição',
            'Aposentadoria da Pessoa com Deficiência',
            'Aposentadoria por Idade da PCD',
            'Aposentadoria Especial (Agentes Nocivos)',
            'Aposentadoria por Incapacidade Permanente (ex-Invalidez)',
            'Aposentadoria Programada (EC 103)',
        ),
        'Regras de Transição (pós EC 103/2019)' => array(
            'Transição — Pontos',
            'Transição — Idade Progressiva',
            'Transição — Pedágio 50%',
            'Transição — Pedágio 100%',
            'Transição — Idade Mínima Professor',
        ),
        'Auxílios' => array(
            'Auxílio por Incapacidade Temporária (ex-Auxílio-Doença)',
            'Auxílio-Acidente',
            'Auxílio-Reclusão',
        ),
        'Salários' => array(
            'Salário-Maternidade Urbana',
            'Salário-Maternidade Rural',
            'Salário-Paternidade (Lei 15.371/2026)',
            'Salário-Família',
        ),
        'Pensões' => array(
            'Pensão por Morte Urbana',
            'Pensão por Morte Rural',
            'Pensão Especial Zika (Lei 15.156/2025)',
        ),
        'Revisões' => array(
            'Revisão da Vida Toda',
            'Revisão do Buraco Negro',
            'Revisão do Teto',
            'Revisão da RMI',
            'Revisão de Aposentadoria Especial',
            'Restabelecimento de Benefício',
            'Reativação de Benefício Cessado',
            'Reafirmação da DER',
            'Retificação do CNIS',
        ),
        'Reconhecimento / Averbação' => array(
            'Reconhecimento de Tempo Especial',
            'Reconhecimento de Tempo Rural',
            'Reconhecimento de Vínculo (Justificação)',
            'Averbação de Tempo (CTC)',
        ),
        'Recursos Administrativos' => array(
            'Recurso Administrativo INSS',
            'Recurso ao CRPS',
            'Recurso à CAJ',
        ),
        'RPPS (Servidor Público)' => array(
            'Aposentadoria Voluntária RPPS',
            'Aposentadoria por Incapacidade RPPS',
            'Pensão por Morte RPPS',
            'Revisão RPPS',
        ),
        'Previdência Complementar' => array(
            'Complementação de Aposentadoria (Fundo de Pensão)',
            'Revisão PGBL / VGBL',
        ),
        'Outros' => array(
            'Cumprimento de Sentença Previdenciária',
            'Ação Rescisória Previdenciária',
            'Consulta / Parecer',
            'Outros',
        ),
    );
}

/**
 * Lista chapada (só os labels, sem categoria) — pra where clauses e filtros.
 */
function prev_tipos_beneficio() {
    $out = array();
    foreach (prev_tipos_beneficio_agrupados() as $cat => $items) {
        foreach ($items as $it) $out[] = $it;
    }
    return $out;
}

/**
 * Renderiza <optgroup> pra <select> — reuso em prev/index (filtro) e
 * prev/caso_novo (form novo processo).
 * $selecionado = valor atualmente selecionado (opcional).
 */
function prev_render_optgroups($selecionado = '') {
    $html = '';
    foreach (prev_tipos_beneficio_agrupados() as $cat => $items) {
        $html .= '<optgroup label="' . htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') . '">';
        foreach ($items as $it) {
            $sel = ($selecionado === $it) ? ' selected' : '';
            $html .= '<option value="' . htmlspecialchars($it, ENT_QUOTES, 'UTF-8') . '"' . $sel . '>'
                   . htmlspecialchars($it, ENT_QUOTES, 'UTF-8') . '</option>';
        }
        $html .= '</optgroup>';
    }
    return $html;
}
