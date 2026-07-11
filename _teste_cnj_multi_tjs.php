<?php
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/functions_cnj_parser.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
header('Content-Type: text/plain; charset=utf-8');

$testes = array(
    // TJRJ (tem tabela propria com acentos)
    '0001234-56.2025.8.19.0066' => 'RJ / Volta Redonda',
    '0001234-56.2025.8.19.0209' => 'RJ / Rio de Janeiro (Regional Barra da Tijuca)',
    '0001234-56.2025.8.19.0001' => 'RJ / Rio de Janeiro (Capital pura)',

    // TJSP
    '1000000-00.2025.8.26.0100' => 'SP / codigo 0100',
    '1000000-00.2025.8.26.0053' => 'SP / codigo 0053',
    '1000000-00.2025.8.26.0001' => 'SP / codigo 0001',

    // TJMG
    '1000000-00.2025.8.13.0024' => 'MG / Belo Horizonte',
    '1000000-00.2025.8.13.0079' => 'MG / codigo 0079',

    // TJRS
    '1000000-00.2025.8.21.0001' => 'RS / Porto Alegre',

    // TJSC
    '1000000-00.2025.8.24.0001' => 'SC / codigo 0001',

    // TJBA
    '1000000-00.2025.8.05.0001' => 'BA / codigo 0001',

    // TJPR
    '1000000-00.2025.8.16.0001' => 'PR / codigo 0001',

    // TJSE
    '1000000-00.2025.8.25.0001' => 'SE / codigo 0001',

    // TJES
    '1000000-00.2025.8.08.0001' => 'ES / codigo 0001',

    // Nao-estadual: TRT2 (SP)
    '1000000-00.2025.5.02.0001' => 'Trabalhista SP (TRT2)',

    // Federal TRF3 (SP)
    '1000000-00.2025.4.03.0001' => 'Federal SP (TRF3)',

    // Invalido
    '123' => 'Numero invalido',
);

foreach ($testes as $cnj => $desc) {
    $p = parse_cnj($cnj);
    printf("%-32s %s\n", $cnj, $desc);
    if ($p['ok']) {
        printf("  -> Segmento: %s / Tribunal: %s / UF: %s / Comarca: %s\n\n",
            $p['segmento_nome'], $p['tribunal_nome'], $p['uf'], $p['comarca'] ?: '(nao mapeada)');
    } else {
        printf("  -> ERRO: %s\n\n", $p['erro']);
    }
}
