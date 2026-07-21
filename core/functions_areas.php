<?php
/**
 * Sistema central de ÁREAS jurídicas — Amanda 20/07/2026.
 * Amanda: "estou com muita dificuldade em separar o que é prev do que é de
 * outra área".
 *
 * Cada `case_type` da tabela cases (também usado em pipeline_leads) mapeia
 * pra uma "área" com código curto, label, cor e ícone. Aplicado em cards,
 * badges, filtros e cores em toda tela do sistema.
 */

/**
 * Retorna array com dados da área:
 *   ['code' => 'PREV', 'label' => 'Previdenciário', 'cor' => '#10b981', 'icon' => '🩺']
 */
function fsa_area_from_case_type($caseType) {
    static $mapa = null;
    if ($mapa === null) {
        $mapa = array(
            // Cada área: [code, label, cor, icon]
            'FAM'  => array('code' => 'FAM',  'label' => 'Família',        'cor' => '#4c6ef5', 'corFundo' => '#eef2ff', 'icon' => '🏛️'),
            'PREV' => array('code' => 'PREV', 'label' => 'Previdenciário', 'cor' => '#10b981', 'corFundo' => '#ecfdf5', 'icon' => '🩺'),
            'CONS' => array('code' => 'CONS', 'label' => 'Consumidor',     'cor' => '#f59e0b', 'corFundo' => '#fef3c7', 'icon' => '🛒'),
            'TRAB' => array('code' => 'TRAB', 'label' => 'Trabalhista',    'cor' => '#7c3aed', 'corFundo' => '#f3e8ff', 'icon' => '👷'),
            'CIV'  => array('code' => 'CIV',  'label' => 'Cível',          'cor' => '#0891b2', 'corFundo' => '#ecfeff', 'icon' => '⚖️'),
            'CRIM' => array('code' => 'CRIM', 'label' => 'Criminal',       'cor' => '#dc2626', 'corFundo' => '#fef2f2', 'icon' => '🚨'),
            'COND' => array('code' => 'COND', 'label' => 'Condominial',    'cor' => '#92400e', 'corFundo' => '#fff7ed', 'icon' => '🏢'),
            'SAUD' => array('code' => 'SAUD', 'label' => 'Saúde',          'cor' => '#0d9488', 'corFundo' => '#f0fdfa', 'icon' => '⚕️'),
            'IMOB' => array('code' => 'IMOB', 'label' => 'Imobiliário',    'cor' => '#a16207', 'corFundo' => '#fefce8', 'icon' => '🏠'),
            'EMPR' => array('code' => 'EMPR', 'label' => 'Empresarial',    'cor' => '#1e40af', 'corFundo' => '#dbeafe', 'icon' => '💼'),
            'OUT'  => array('code' => 'OUT',  'label' => 'Outros',         'cor' => '#6b7280', 'corFundo' => '#f9fafb', 'icon' => '📁'),
        );
    }

    $t = mb_strtolower(trim((string)$caseType), 'UTF-8');
    if ($t === '') return $mapa['OUT'];

    // Regras (ordem importa — mais específicas primeiro).
    // Usa strpos pra matcar substring dentro do case_type (que pode vir com
    // texto livre tipo "Alimentos + Convivência", "PREV — Salário Maternidade").
    $regras = array(
        // Previdenciário — INSS, benefícios, aposentadoria
        'PREV' => array('prev', 'inss', 'aposentador', 'salário-maternidade', 'salario-maternidade', 'salario_maternidade', 'salario maternidade', 'auxílio-doença', 'auxilio-doenca', 'auxilio doenca', 'auxilio_doenca', 'invalidez', 'incapacidade', 'bpc', 'loas', 'pensão por morte', 'pensao por morte', 'benefício', 'beneficio', 'requerimento administrativo', 'recurso administrativo', 'reconhecimento tempo', 'averbação tempo', 'crps', 'jef', 'rpps'),
        // Família
        'FAM' => array('família', 'familia', 'divórcio', 'divorcio', 'guarda', 'convivência', 'convivencia', 'alimentos', 'pensão', 'pensao', 'medida protetiva', 'violência doméstica', 'violencia domestica', 'união estável', 'uniao estavel', 'investigação de paternidade', 'investigacao paternidade', 'adoção', 'adocao', 'curatela', 'tutela', 'reconhecimento paternidade', 'dissolução', 'dissolucao', 'inventário', 'inventario', 'testamento', 'sucessão', 'sucessao', 'sucessões', 'sucessoes', 'partilha', 'destituição poder familiar', 'destituicao poder familiar', 'suprimento consentimento'),
        // Consumidor
        'CONS' => array('consumid', 'cdc', 'produto defeituoso', 'serviço defeituoso', 'servico defeituoso', 'plano de saúde', 'plano de saude', 'operadora', 'banco', 'financeira', 'financiamento', 'seguradora', 'seguro', 'aereo', 'aéreo', 'viagem', 'atraso voo', 'inclusão indevida', 'inclusao indevida', 'spc', 'serasa', 'negativação', 'negativacao', 'cobrança indevida', 'cobranca indevida', 'estelionato sentimental', 'indenizatória', 'indenizatoria', 'danos morais', 'danos materiais', 'revisional'),
        // Trabalhista
        'TRAB' => array('trabalhist', 'clt', 'rescisão', 'rescisao', 'verba rescisória', 'verba rescisoria', 'horas extras', 'assédio moral', 'assedio moral', 'insalubridade', 'periculosidade', 'fgts', 'aviso prévio', 'aviso previo', 'estabilidade'),
        // Criminal
        'CRIM' => array('crime', 'criminal', 'penal', 'ação penal', 'acao penal', 'habeas corpus', 'inquérito', 'inquerito', 'delegacia', 'boletim de ocorrência', 'boletim ocorrencia', 'lesão corporal', 'lesao corporal', 'ameaça', 'ameaca'),
        // Condominial
        'COND' => array('condomínio', 'condominio', 'condomin', 'cota condominial', 'assembleia', 'síndico', 'sindico'),
        // Saúde (judicialização, plano)
        'SAUD' => array('saúde', 'saude', 'medicamento', 'cirurgia', 'internação', 'internacao', 'home care', 'tratamento médico', 'tratamento medico', 'erro médico', 'erro medico', 'sus', 'renome', 'conitec'),
        // Imobiliário
        'IMOB' => array('imobiliár', 'imobiliar', 'usucapião', 'usucapiao', 'despejo', 'aluguel', 'locação', 'locacao', 'imóvel', 'imovel', 'escritura', 'registro'),
        // Empresarial
        'EMPR' => array('empresarial', 'societár', 'societar', 'contratual', 'cobrança empresarial', 'cobranca empresarial', 'execução extrajudicial', 'execucao extrajudicial', 'execução título', 'execucao titulo', 'monitória', 'monitoria', 'falência', 'falencia', 'recuperação judicial', 'recuperacao judicial', 'organizações contabo', 'organizacoes contabo'),
        // Cível genérico (última linha antes de OUT)
        'CIV'  => array('cível', 'civel', 'civil', 'obrigação de fazer', 'obrigacao fazer', 'petição', 'peticao', 'oferecimento', 'agravo', 'apelação', 'apelacao', 'execução', 'execucao', 'cumprimento sentença', 'cumprimento sentenca', 'alvará', 'alvara', 'habilitação', 'habilitacao'),
    );

    foreach ($regras as $codigo => $keywords) {
        foreach ($keywords as $kw) {
            if (strpos($t, $kw) !== false) return $mapa[$codigo];
        }
    }
    return $mapa['OUT'];
}

/**
 * Retorna HTML pronto do badge da área.
 * $tamanho: 'xs' (~9px), 'sm' (default, ~10-11px) ou 'md' (~13px).
 */
function fsa_area_badge($caseType, $tamanho = 'sm', $comLabel = false) {
    $a = fsa_area_from_case_type($caseType);
    $fs = $tamanho === 'xs' ? '.58rem' : ($tamanho === 'md' ? '.82rem' : '.68rem');
    $pad = $tamanho === 'xs' ? '1px 5px' : ($tamanho === 'md' ? '3px 10px' : '2px 7px');
    $txt = $comLabel ? ($a['icon'] . ' ' . $a['code'] . ' ' . $a['label']) : ($a['icon'] . ' ' . $a['code']);
    return '<span class="fsa-area-badge" title="Área: ' . htmlspecialchars($a['label'], ENT_QUOTES, 'UTF-8') . '" '
         . 'style="display:inline-block;background:' . $a['corFundo'] . ';color:' . $a['cor'] . ';'
         . 'font-size:' . $fs . ';font-weight:800;padding:' . $pad . ';border-radius:8px;'
         . 'letter-spacing:.03em;line-height:1.2;vertical-align:middle;border:1px solid ' . $a['cor'] . '22;">'
         . htmlspecialchars($txt, ENT_QUOTES, 'UTF-8') . '</span>';
}

/**
 * Retorna a cor da área (pra usar em border-left de cards).
 */
function fsa_area_cor($caseType) {
    $a = fsa_area_from_case_type($caseType);
    return $a['cor'];
}

/**
 * CSS reutilizável (imprima 1x por página que usa muitos badges).
 */
function fsa_area_css() {
    return "<style>
    .fsa-area-badge { text-transform:none; }
    .fsa-area-border-left { border-left:4px solid var(--fsa-area-cor, #6b7280) !important; }
    </style>";
}
