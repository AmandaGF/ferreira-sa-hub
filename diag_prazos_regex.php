<?php
if (($_GET['key']??'') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('no'); }
header('Content-Type: text/plain; charset=utf-8');

$f = __DIR__ . '/modules/prazos/index.php';
echo "Arquivo: $f\n";
echo "filemtime: " . date('Y-m-d H:i:s', filemtime($f)) . "\n\n";

// Pega a linha da regex de recurso
$lines = file($f);
foreach ($lines as $i => $ln) {
    if (strpos($ln, "'recurso'") !== false || strpos($ln, "recurso|apela") !== false) {
        echo "L" . ($i+1) . ": " . trim($ln) . "\n";
    }
}

// Testa a regex direto contra strings reais dos prints
function _classificar($d) {
    $d = mb_strtolower((string)$d, 'UTF-8');
    if (preg_match('/^publica[çc][ãa]o:|intima[çc][ãa]o/u', $d)) return 'djen';
    if (preg_match('/recurso|apela[çc][ãa]o|inomin|embarg|agravo|contra[r\\s-]*raz[õo]es/u', $d)) return 'recurso';
    if (preg_match('/contesta[çc][ãa]o|defesa\\b|r[eé]plica/u', $d)) return 'contestacao';
    if (preg_match('/alega[çc][õo]es?\\s*finais?|memori/u', $d)) return 'alegacoes';
    if (preg_match('/prova|per[íi]cia|testemunha|diligen[çc]ia/u', $d)) return 'provas';
    return 'outros';
}

echo "\n--- Teste de classificação ---\n";
$casos = array(
    "PRAZO: Contrarrazões — Amanda Ferreira x Obrigação de Fazer",
    "Contrarrazões — 10 dias",
    "PRAZO: Outro — Angela Sobral x IPVA",
    "Publicação: INTIMAÇÃO | Amanda Ferreira x Vivo",
);
foreach ($casos as $c) echo "  [" . _classificar($c) . "] $c\n";
