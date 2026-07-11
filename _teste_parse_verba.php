<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
header('Content-Type: text/plain; charset=utf-8');

// Reproducao EXATA do parse do matriz.php:
// $verba = (float)str_replace(',', '.', str_replace('.', '', $_POST['verba_prevista'] ?? '0'));
$parse = function($raw) {
    return (float)str_replace(',', '.', str_replace('.', '', $raw));
};

$testes = array(
    '20,00', '20', '20.00',
    '45,00', '100,00',
    '1.000,00',   // BR: milhar+decimal
    '1500,00',
    'R$ 20,00',   // com prefixo
    ' 20,00 ',    // com espaco
    '0,00',
    '',
    '0',
);
foreach ($testes as $t) {
    printf("input=%-15s => %s\n", '"'.$t.'"', var_export($parse($t), true));
}
