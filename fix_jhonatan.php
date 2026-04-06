<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave invalida'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

// Caso #675 — Jhonatan Oferecimento Alimentos — precisa ir para distribuido
// Já tem case_number e distribution_date de uma tentativa anterior
$pdo->prepare("UPDATE cases SET status = 'distribuido', distribution_date = COALESCE(distribution_date, CURDATE()), updated_at = NOW() WHERE id = 675")->execute();
echo "Caso #675 atualizado para distribuido.\n";

// Verificar
$r = $pdo->prepare("SELECT id, title, status, case_number, distribution_date FROM cases WHERE id = 675");
$r->execute();
$row = $r->fetch();
echo "Resultado: status={$row['status']} num={$row['case_number']} dist={$row['distribution_date']}\n";

echo "\n=== FEITO ===\n";
