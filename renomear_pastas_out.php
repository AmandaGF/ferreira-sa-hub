<?php
/**
 * Renomeia no Google Drive as pastas que ficaram com o prefixo de badge
 * "📁 OUT " (bug do drag). Usa cases.title (ja limpo) como nome-alvo.
 * Requer o handler action=renameFolder no Apps Script.
 *
 * Dry-run por padrao. Aplicar: &apply=1
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave invalida'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/google_drive.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();
$apply = ($_GET['apply'] ?? '') === '1';

// Casos cujas PASTAS do Drive ainda tem o prefixo de badge (titulos ja limpos no banco).
$ids = array(1642, 1646, 1647);
$in = implode(',', array_map('intval', $ids));
$rows = $pdo->query("SELECT id, title, drive_folder_url FROM cases WHERE id IN ($in) AND drive_folder_url IS NOT NULL AND drive_folder_url <> ''")->fetchAll();

echo "=== RENOMEAR pastas Drive (strip prefixo badge) ===\n";
echo "Modo: " . ($apply ? "APLICAR" : "DRY-RUN (use &apply=1)") . "\n\n";

foreach ($rows as $r) {
    echo "#{$r['id']} -> nome-alvo: \"{$r['title']}\"\n   pasta: {$r['drive_folder_url']}\n";
    if ($apply) {
        $res = rename_drive_folder($r['drive_folder_url'], $r['title']);
        echo "   resultado: " . ($res['success'] ? "OK (title=\"{$res['title']}\")" : "FALHOU -> {$res['error']}") . "\n";
    }
    echo "\n";
}
echo "Obs: renomear NAO muda o ID/URL da pasta — drive_folder_url continua valido.\n";
echo "=== FIM ===\n";
