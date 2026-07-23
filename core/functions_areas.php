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
/**
 * Normaliza texto pra matching de area: minusculo + sem acento + espacos
 * colapsados. Assim "Salário Maternidade" casa com a chave "salario maternidade".
 */
function _fsa_norm_area($s) {
    $s = mb_strtolower(trim((string)$s), 'UTF-8');
    $de = array('á','à','â','ã','ä','é','è','ê','ë','í','ì','î','ï','ó','ò','ô','õ','ö','ú','ù','û','ü','ç','ñ');
    $pa = array('a','a','a','a','a','e','e','e','e','i','i','i','i','o','o','o','o','o','u','u','u','u','c','n');
    $s = str_replace($de, $pa, $s);
    return preg_replace('/\s+/', ' ', $s);
}

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

    // Normaliza SEM acento (as palavras-chave abaixo sao comparadas ja sem
    // acento). Antes, "Salário Maternidade"/"Auxílio Doença" caiam em OUT
    // porque a chave estava sem acento e o texto vinha com acento. Bug 07/2026.
    $t = _fsa_norm_area((string)$caseType);
    if ($t === '') return $mapa['OUT'];

    // Regras (ordem importa — mais específicas primeiro).
    // Usa strpos pra matcar substring dentro do case_type (que pode vir com
    // texto livre tipo "Alimentos + Convivência", "PREV — Salário Maternidade").
    // TODAS as chaves aqui devem estar SEM ACENTO (o texto ja e normalizado).
    $regras = array(
        // Previdenciário — INSS, benefícios, aposentadoria
        'PREV' => array('prev', 'inss', 'aposentador', 'salario-maternidade', 'salario maternidade', 'salario_maternidade', 'auxilio-maternidade', 'auxilio maternidade', 'aux maternidade', 'auxilio-doenca', 'auxilio doenca', 'auxilio_doenca', 'invalidez', 'incapacidade', 'bpc', 'loas', 'pensao por morte', 'beneficio', 'requerimento administrativo', 'recurso administrativo', 'reconhecimento tempo', 'averbacao tempo', 'crps', 'jef', 'rpps'),
        // Família
        'FAM' => array('familia', 'divorcio', 'guarda', 'convivencia', 'alimentos', 'pensao', 'medida protetiva', 'violencia domestica', 'uniao estavel', 'paternidade', 'investigacao de paternidade', 'investigacao paternidade', 'reconhecimento de paternidade', 'adocao', 'curatela', 'tutela', 'dissolucao', 'inventario', 'testamento', 'sucessao', 'sucessoes', 'partilha', 'destituicao poder familiar', 'suprimento consentimento', 'abandono afetivo'),
        // Consumidor
        'CONS' => array('consumid', 'cdc', 'produto defeituoso', 'servico defeituoso', 'plano de saude', 'operadora', 'banco', 'bancario', 'financeira', 'financiamento', 'seguradora', 'seguro', 'aereo', 'viagem', 'atraso voo', 'inclusao indevida', 'spc', 'serasa', 'negativacao', 'cobranca indevida', 'estelionato', 'indeniza', 'dano moral', 'dano material', 'danos morais', 'danos materiais', 'revisional', 'revisao de contrato', 'revisao bancario', 'juros abusivos', 'superendividamento', 'fraude banc'),
        // Trabalhista
        'TRAB' => array('trabalhist', 'clt', 'rescisao', 'verba rescisoria', 'horas extras', 'assedio moral', 'insalubridade', 'periculosidade', 'fgts', 'aviso previo', 'estabilidade'),
        // Criminal
        'CRIM' => array('crime', 'criminal', 'penal', 'acao penal', 'habeas corpus', 'inquerito', 'delegacia', 'boletim de ocorrencia', 'boletim ocorrencia', 'lesao corporal', 'ameaca', 'anpp'),
        // Condominial
        'COND' => array('condominio', 'condomin', 'cota condominial', 'assembleia', 'sindico'),
        // Saúde (judicialização, plano)
        'SAUD' => array('saude', 'medicamento', 'cirurgia', 'internacao', 'home care', 'tratamento medico', 'erro medico', 'sus', 'renome', 'conitec'),
        // Imobiliário
        'IMOB' => array('imobiliar', 'usucapiao', 'despejo', 'aluguel', 'locacao', 'imovel', 'escritura', 'registro'),
        // Empresarial
        'EMPR' => array('empresarial', 'societar', 'cobranca empresarial', 'execucao extrajudicial', 'execucao titulo', 'monitoria', 'falencia', 'recuperacao judicial', 'organizacoes contabo'),
        // Cível genérico (última linha antes de OUT)
        'CIV'  => array('civel', 'civil', 'obrigacao de fazer', 'obrigacao fazer', 'obrigacao de nao fazer', 'peticao', 'oferecimento', 'agravo', 'apelacao', 'execucao', 'cumprimento sentenca', 'cumprimento de sentenca', 'alvara', 'habilitacao', 'anulatoria', 'consignatoria', 'possessoria', 'rescisoria', 'embargos', 'responsabilidade civil'),
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
