<?php
/**
 * Backfill: sincroniza audiencias.status='realizada' quando o
 * agenda_eventos correspondente já está 'realizado'.
 * (feature Amanda 02/07/2026 — cards de audiencista continuavam
 * aparecendo mesmo após audiência já ter sido marcada como realizada)
 *
 * Uso: GET ?key=fsa-hub-deploy-2026        (dry-run)
 *      GET ?key=fsa-hub-deploy-2026&fix=1  (aplica)
 */
require_once __DIR__ . '/core/database.php';
if (($_GET['key']??'') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Backfill audiencias.status='realizada' ===\n\n";

// Detecta pendentes: audiencias designadas/abertas cujo evento agenda já é 'realizado'
$sql = "SELECT au.id AS aud_id, au.status AS aud_status, au.tipo, au.data_hora,
               au.agenda_evento_id, ae.status AS ev_status, ae.titulo,
               cs.title AS case_title, cs.id AS case_id
        FROM audiencias au
        JOIN agenda_eventos ae ON ae.id = au.agenda_evento_id
        LEFT JOIN cases cs ON cs.id = au.case_id
        WHERE au.status NOT IN ('cancelada','realizada')
          AND ae.status = 'realizado'";
$rs = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

echo "Encontradas " . count($rs) . " audiencia(s) pendentes de fechamento:\n\n";
foreach ($rs as $r) {
    echo "  aud#{$r['aud_id']} (status={$r['aud_status']}) · tipo={$r['tipo']} · data={$r['data_hora']}\n";
    echo "    ev#{$r['agenda_evento_id']} '{$r['titulo']}' (status={$r['ev_status']})\n";
    echo "    case#{$r['case_id']}: {$r['case_title']}\n\n";
}

$aplicar = !empty($_GET['fix']);
if ($aplicar && $rs) {
    $ok = 0;
    $up = $pdo->prepare("UPDATE audiencias SET status='realizada', updated_at=NOW() WHERE id = ?");
    foreach ($rs as $r) {
        try { $up->execute(array((int)$r['aud_id'])); $ok++; }
        catch (Exception $e) { echo "  ERRO em aud#{$r['aud_id']}: " . $e->getMessage() . "\n"; }
    }
    echo "\n✓ {$ok} audiencia(s) atualizadas.\n";
} elseif ($rs) {
    echo "\nDRY-RUN. Pra aplicar de verdade, adicione &fix=1 na URL.\n";
} else {
    echo "\nNada pra fazer.\n";
}
