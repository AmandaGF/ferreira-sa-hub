<?php
require_once __DIR__ . '/core/middleware.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Cosme Pereira dos Santos (client_id=2491, form id=730) ===\n\n";

// 1) Cliente
echo "── CLIENTE ──\n";
$c = $pdo->prepare("SELECT * FROM clients WHERE id=2491");
$c->execute();
$row = $c->fetch(PDO::FETCH_ASSOC);
if (!$row) { echo "❌ Cliente 2491 NAO EXISTE\n"; }
else {
    foreach (array('id','name','cpf','phone','email','source','client_status','created_at','created_by') as $k) {
        echo "  $k: " . ($row[$k] ?? 'NULL') . "\n";
    }
}

// 2) Form submission
echo "\n── FORM SUBMISSION ──\n";
$f = $pdo->prepare("SELECT * FROM form_submissions WHERE id=730");
$f->execute();
$frow = $f->fetch(PDO::FETCH_ASSOC);
if (!$frow) { echo "❌ Form 730 NAO EXISTE\n"; }
else {
    foreach (array('id','form_type','protocol','client_name','client_phone','status','linked_client_id','linked_case_id','created_at') as $k) {
        echo "  $k: " . ($frow[$k] ?? 'NULL') . "\n";
    }
}

// 3) Simula a query do CRM
echo "\n── SIMULA QUERY DO CRM (clientes com form_submissions, ultimo mes) ──\n";
$st = $pdo->query("SELECT c.id, c.name, fs.status AS fs_status, fs.form_type, fs.created_at AS fs_em
                   FROM clients c
                   INNER JOIN form_submissions fs ON fs.linked_client_id = c.id AND fs.status != 'arquivado'
                   WHERE c.id = 2491
                   ORDER BY fs.created_at DESC");
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
if (!$rows) {
    echo "❌ Cosme NAO aparece no CRM com filtro padrao!\n";
    // Verifica sem filtro de status
    $st2 = $pdo->query("SELECT c.id, c.name, fs.status, fs.form_type FROM clients c
                        INNER JOIN form_submissions fs ON fs.linked_client_id = c.id
                        WHERE c.id = 2491");
    $r2 = $st2->fetchAll(PDO::FETCH_ASSOC);
    if ($r2) {
        echo "  Mas com JOIN sem filtro de status, achou:\n";
        foreach ($r2 as $r) echo "    fs.status='" . $r['status'] . "' fs.form_type='" . $r['form_type'] . "'\n";
        echo "  → Provavel: form esta com status 'arquivado'\n";
    } else {
        echo "  Nem com JOIN solto. Pode ser que linked_client_id esteja errado.\n";
    }
} else {
    echo "✓ Aparece no CRM:\n";
    foreach ($rows as $r) {
        echo "  id={$r['id']} | {$r['name']} | form_type={$r['form_type']} | fs_status={$r['fs_status']} | em={$r['fs_em']}\n";
    }
}

echo "\n── TOTAL form_submissions cadastro_cliente nos ultimos 2 dias ──\n";
$st3 = $pdo->query("SELECT id, form_type, client_name, status, linked_client_id, created_at
                    FROM form_submissions
                    WHERE form_type = 'cadastro_cliente'
                      AND created_at >= DATE_SUB(NOW(), INTERVAL 2 DAY)
                    ORDER BY id DESC");
foreach ($st3->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "  id={$r['id']} | {$r['client_name']} | status={$r['status']} | client_id=" . ($r['linked_client_id'] ?: '-') . " | em {$r['created_at']}\n";
}
