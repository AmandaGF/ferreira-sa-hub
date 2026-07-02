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

// Detecta pendentes por 2 caminhos:
// (1) vínculo direto por audiencias.agenda_evento_id
// (2) case_id + evento tipo audiencia/mediacao_cejusc já realizado e sem outra
//     audiencia vinculada — cobre solicitações antigas sem agenda_evento_id
$sql = "SELECT DISTINCT au.id AS aud_id, au.status AS aud_status, au.tipo, au.data_hora,
               au.agenda_evento_id, ae.id AS ev_id, ae.status AS ev_status, ae.titulo,
               ae.data_inicio AS ev_data, cs.title AS case_title, cs.id AS case_id,
               (CASE WHEN au.agenda_evento_id = ae.id THEN 'vinculo direto' ELSE 'match por case+data' END) AS caminho
        FROM audiencias au
        JOIN cases cs ON cs.id = au.case_id
        JOIN agenda_eventos ae ON ae.case_id = au.case_id AND ae.status = 'realizado'
        WHERE au.status NOT IN ('cancelada','realizada')
          AND (
                au.agenda_evento_id = ae.id
                OR (
                    ae.tipo IN ('audiencia','mediacao_cejusc')
                    AND au.agenda_evento_id IS NULL
                )
          )";
$rs = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

echo "Encontradas " . count($rs) . " audiencia(s) pendentes de fechamento:\n\n";
foreach ($rs as $r) {
    echo "  aud#{$r['aud_id']} (status={$r['aud_status']}) · tipo={$r['tipo']} · data={$r['data_hora']} · caminho='{$r['caminho']}'\n";
    echo "    ev#{$r['ev_id']} '{$r['titulo']}' (data={$r['ev_data']} status={$r['ev_status']})\n";
    echo "    case#{$r['case_id']}: {$r['case_title']}\n\n";
}

$aplicar = !empty($_GET['fix']);
if ($aplicar && $rs) {
    $ok = 0;
    // Atualiza status E preenche agenda_evento_id se estava vazio
    $up = $pdo->prepare("UPDATE audiencias SET status='realizada', updated_at=NOW(), agenda_evento_id=COALESCE(agenda_evento_id, ?) WHERE id = ?");
    foreach ($rs as $r) {
        try { $up->execute(array((int)$r['ev_id'], (int)$r['aud_id'])); $ok++; }
        catch (Exception $e) { echo "  ERRO em aud#{$r['aud_id']}: " . $e->getMessage() . "\n"; }
    }
    echo "\n✓ {$ok} audiencia(s) atualizadas.\n";
} elseif ($rs) {
    echo "\nDRY-RUN. Pra aplicar de verdade, adicione &fix=1 na URL.\n";
} else {
    echo "\nNada pra fazer.\n";
}
