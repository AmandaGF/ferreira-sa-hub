<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
header('Content-Type: text/plain; charset=utf-8');
require_once dirname(__DIR__) . '/salavip/config.php';
$pdo = sv_db();

echo "=== SCHEMA salavip_threads ===\n";
foreach ($pdo->query("DESCRIBE salavip_threads") as $r) echo "  " . $r['Field'] . " (" . $r['Type'] . ")\n";

echo "\n=== APLICANDO FIX no mensagem_ver.php ===\n";
$f = dirname(__DIR__) . '/salavip/pages/mensagem_ver.php';
$c = file_get_contents($f);

$antes = "LEFT JOIN cases c ON c.id = t.case_id";
$depois = "LEFT JOIN cases c ON c.id = t.processo_id";

if (strpos($c, $antes) === false) {
    echo "  Padrao antigo NAO encontrado. Talvez ja foi corrigido? Grepando 'case_id':\n";
    foreach (explode("\n", $c) as $i => $l) {
        if (strpos($l, 'case_id') !== false) echo "    linha " . ($i+1) . ": $l\n";
    }
    exit;
}

$novo = str_replace($antes, $depois, $c);
$bak = $f . '.bak-' . date('Ymd_His');
file_put_contents($bak, $c);
echo "  Backup salvo: " . basename($bak) . "\n";

file_put_contents($f, $novo);
echo "  Fix aplicado! Trocado 't.case_id' por 't.processo_id' no JOIN.\n";

// Verifica se o resto do arquivo usa case_id em outro lugar
echo "\n=== Outras ocorrencias de 'case_id' no arquivo (pra verificar) ===\n";
foreach (explode("\n", $novo) as $i => $l) {
    if (strpos($l, 'case_id') !== false) echo "  linha " . ($i+1) . ": $l\n";
}
