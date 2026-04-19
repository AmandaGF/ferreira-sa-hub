<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors', '1');
error_reporting(E_ALL);
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "Cases recentes (top 15) e seus clientes:\n\n";
$rows = $pdo->query("SELECT cs.id, cs.client_id, cs.client_title, cs.case_type, cs.status, cs.case_number,
                            cl.name, cl.phone, cl.cpf
                     FROM cases cs LEFT JOIN clients cl ON cl.id = cs.client_id
                     ORDER BY cs.id DESC LIMIT 15")->fetchAll();
foreach ($rows as $r) {
    echo "  #{$r['id']} | cliente: {$r['name']} (id={$r['client_id']}, fone={$r['phone']}, cpf={$r['cpf']})\n";
    echo "      título: {$r['client_title']} | tipo: {$r['case_type']} | status: {$r['status']}\n\n";
}

echo "\nTOTAL de cases no banco: " . (int)$pdo->query("SELECT COUNT(*) FROM cases")->fetchColumn() . "\n";
echo "Cases NÃO arquivados: " . (int)$pdo->query("SELECT COUNT(*) FROM cases WHERE status != 'arquivado'")->fetchColumn() . "\n";

echo "\nCases que contêm '24992234554' OU 'Amanda' em QUALQUER campo texto:\n";
$rows = $pdo->query("SELECT cs.id, cs.client_id, cs.client_title, cs.case_type, cs.case_number, cs.notes, cl.name
                     FROM cases cs LEFT JOIN clients cl ON cl.id = cs.client_id
                     WHERE cs.client_title LIKE '%Amanda%'
                        OR cs.case_number LIKE '%Amanda%'
                        OR cs.notes LIKE '%Amanda%'
                        OR cl.phone LIKE '%4992234554%'
                        OR cl.name LIKE '%Amanda%'
                     ORDER BY cs.id DESC")->fetchAll();
if (empty($rows)) echo "  Nenhum.\n";
foreach ($rows as $r) echo "  #{$r['id']} | cliente={$r['name']} | título={$r['client_title']}\n";
