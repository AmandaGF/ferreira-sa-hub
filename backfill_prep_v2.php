<?php
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== BACKFILL SEGURO — preparacao_audiencia usa audiencia referenciada como verdade ===\n\n";

$st = $pdo->query(
    "SELECT p.id AS prep_id, p.titulo AS prep_titulo, p.case_id AS prep_case, p.client_id AS prep_client, p.referencia_evento_id,
            a.case_id AS aud_case, a.client_id AS aud_client, a.titulo AS aud_titulo
     FROM agenda_eventos p
     JOIN agenda_eventos a ON a.id = p.referencia_evento_id
     WHERE p.tipo = 'preparacao_audiencia'
       AND p.referencia_evento_id IS NOT NULL
       AND p.status NOT IN ('cancelado','realizado')
       AND (p.case_id != a.case_id OR p.client_id != a.client_id OR (p.case_id IS NULL AND a.case_id IS NOT NULL))"
);
$divs = $st->fetchAll(PDO::FETCH_ASSOC);
echo "Preparacoes divergentes da audiencia referenciada: " . count($divs) . "\n\n";

$ok = 0;
foreach ($divs as $d) {
    echo "-- prep #{$d['prep_id']} '{$d['prep_titulo']}' --\n";
    echo "   ANTES: case_id={$d['prep_case']} client_id={$d['prep_client']}\n";
    echo "   Audiencia ref: case_id={$d['aud_case']} client_id={$d['aud_client']}\n";
    $stU = $pdo->prepare("UPDATE agenda_eventos SET case_id = ?, client_id = ?, updated_at = NOW() WHERE id = ?");
    $stU->execute(array((int)$d['aud_case'], (int)$d['aud_client'], (int)$d['prep_id']));
    $ok++;
    echo "   ✓ Corrigido pra bater com audiencia\n\n";
}

echo "=== RESUMO ===\n";
echo "Total corrigidos: $ok\n";
