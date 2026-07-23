<?php
/**
 * Limpa o prefixo de badge de area ("📁 OUT ", "🏛️ FAM ", etc.) que vazou
 * pro cases.title quando o lead era arrastado no Kanban do Pipeline
 * (bug do textContent em pipeline/index.php, corrigido em 2026-07-23).
 *
 * Dry-run por padrao. Para aplicar: &apply=1
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave invalida'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();
$apply = ($_GET['apply'] ?? '') === '1';

// Emoji(s) no inicio + CODIGO de area + espaco. Exige ao menos 1 emoji pra
// nao pegar titulo que por acaso comece com uma dessas siglas.
$re = '/^[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2190}-\x{21FF}\x{2B00}-\x{2BFF}\x{FE0F}\x{200D}]+\s*(FAM|PREV|CONS|TRAB|CIV|CRIM|COND|SAUD|IMOB|EMPR|OUT)\s+/u';

echo "=== LIMPEZA prefixo badge no cases.title ===\n";
echo "Modo: " . ($apply ? "APLICAR" : "DRY-RUN (use &apply=1 pra gravar)") . "\n\n";

$rows = $pdo->query("SELECT id, title, drive_folder_url FROM cases WHERE title REGEXP '[[:<:]](FAM|PREV|CONS|TRAB|CIV|CRIM|COND|SAUD|IMOB|EMPR|OUT)[[:>:]]'")->fetchAll();

$upd = $pdo->prepare("UPDATE cases SET title = ? WHERE id = ?");
$n = 0;
foreach ($rows as $r) {
    $novo = preg_replace($re, '', $r['title']);
    if ($novo !== null && $novo !== $r['title'] && trim($novo) !== '') {
        $n++;
        echo "#{$r['id']}\n   ANTES: {$r['title']}\n   DEPOIS: {$novo}\n";
        echo "   Pasta Drive: " . ($r['drive_folder_url'] ?: '(sem pasta)') . "\n\n";
        if ($apply) $upd->execute(array($novo, $r['id']));
    }
}

echo "----\n";
echo ($apply ? "Titulos corrigidos: $n\n" : "Titulos que SERIAM corrigidos: $n (nada gravado)\n");
echo "\nATENCAO: o NOME das pastas no Google Drive nao muda por aqui —\n";
echo "isso e feito no Drive (via conector/Apps Script), separadamente.\n";
echo "=== FIM ===\n";
