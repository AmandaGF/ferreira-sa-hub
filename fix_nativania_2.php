<?php
// Desarquiva Nativania de novo (formulario tinha bug de form aninhado).
// Apaga este arquivo apos rodar.
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();
echo "<pre>";
$st = $pdo->query("SELECT id, nome_completo, status FROM colaboradores_onboarding WHERE nome_completo LIKE '%Nativ%' ORDER BY id DESC");
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "BEFORE: id={$r['id']} nome={$r['nome_completo']} status={$r['status']}\n";
    if ($r['status'] === 'arquivado') {
        $pdo->prepare("UPDATE colaboradores_onboarding SET status='ativo' WHERE id = ?")->execute(array($r['id']));
        echo "  -> reativado\n";
    }
}
$st2 = $pdo->query("SELECT id, nome_completo, status FROM colaboradores_onboarding WHERE nome_completo LIKE '%Nativ%' ORDER BY id DESC");
foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "AFTER:  id={$r['id']} nome={$r['nome_completo']} status={$r['status']}\n";
}
echo "</pre>";
