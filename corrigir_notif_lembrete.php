<?php
/**
 * Corrige notificações cuja coluna `type` foi gravada com URL e `link` com
 * "alerta"/"urgencia" (bug em cron/agenda_lembretes.php, descoberto 30/06/2026).
 * Inverte os 2 campos só nas linhas afetadas.
 */
if (($_GET['key']??'') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Corrige notificações com tipo/link trocados ===\n\n";

// Critério: o `type` começa com '/conecta/modules/' (parece URL) E o `link` é
// um valor curto que parece um tipo (alerta/urgencia/info/sucesso/etc).
$sel = $pdo->query(
    "SELECT id, type, link FROM notifications
     WHERE type LIKE '/conecta/modules/%'
       AND link IN ('alerta','urgencia','info','sucesso','pendencia','warning','danger')"
);
$rows = $sel->fetchAll(PDO::FETCH_ASSOC);
echo "Encontradas " . count($rows) . " notificações com tipo/link trocados.\n\n";

// Detecta o tamanho real da coluna link pra evitar truncamento de novo
$colLink = $pdo->query("SHOW COLUMNS FROM notifications LIKE 'link'")->fetch(PDO::FETCH_ASSOC);
echo "Coluna `link`: " . ($colLink['Type'] ?? '?') . "\n\n";

$aplicar = !empty($_GET['fix']);
$up = $pdo->prepare("UPDATE notifications SET type = ?, link = ? WHERE id = ?");
$ok = 0;
foreach ($rows as $r) {
    $tipoCorreto = $r['link'];                         // 'alerta' / 'urgencia' (cabe em qualquer coluna)
    // Como o type estava com URL e foi truncado pra ~40 chars, perdemos o ?evento=ID.
    // Em vez de manter URL inválida, set link pro módulo agenda — pelo menos abre uma tela útil.
    $linkCorreto = '/conecta/modules/agenda/';
    echo "  #{$r['id']} type='{$r['type']}' link='{$r['link']}'"
       . " → type='{$tipoCorreto}' link='{$linkCorreto}'\n";
    if ($aplicar) {
        try { $up->execute(array($tipoCorreto, $linkCorreto, $r['id'])); $ok++; } catch (Exception $e) { echo "    ERRO: " . $e->getMessage() . "\n"; }
    }
}
echo "\n";
if ($aplicar) echo "✓ {$ok} notificações corrigidas.\n";
else echo "DRY-RUN. Pra aplicar de verdade, adicione &fix=1 na URL.\n";
