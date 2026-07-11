<?php
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

// Amanda 11/07: 3 celulas com verba nao persistiram. Grava direto pelos IDs
// que apareceram no diag anterior.
$targets = array(
    11 => 20.00,   // Essencial x O folego
    15 => 45.00,   // Premium x Aniversario
    14 => 100.00,  // Alta x Aniversario
);
$upd = $pdo->prepare("UPDATE presenca_regra SET verba_prevista = ? WHERE id = ?");
foreach ($targets as $id => $verba) {
    $upd->execute(array($verba, $id));
    echo "id=$id -> verba R$ " . number_format($verba, 2, ',', '.') . " (afetadas: " . $upd->rowCount() . ")\n";
}

echo "\nEstado atual das 3 regras:\n";
$sql = "SELECT r.id, p.nome AS perfil, f.nome AS fase, r.verba_prevista
        FROM presenca_regra r
        JOIN presenca_perfil p ON p.id = r.perfil_id
        JOIN presenca_fase f ON f.id = r.fase_id
        WHERE r.id IN (11, 14, 15) ORDER BY r.id";
foreach ($pdo->query($sql) as $r) {
    printf("  id=%d | %-10s x %-25s | verba = R$ %s\n", $r['id'], $r['perfil'], $r['fase'], number_format((float)$r['verba_prevista'], 2, ',', '.'));
}
