<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== CLIENTS 'Liliane' ===\n";
$st = $pdo->query("SELECT id, name, cpf, rg, birth_date, profession, marital_status, email, phone, address_street, address_city, address_state
                   FROM clients WHERE name LIKE '%Liliane%' OR name LIKE '%liliane%'");
$clis = $st->fetchAll(PDO::FETCH_ASSOC);
foreach ($clis as $c) print_r($c);
if (empty($clis)) echo "  (nenhum cliente com 'Liliane' no nome)\n";

echo "\n=== case_partes com nome 'Liliane' ===\n";
$st = $pdo->query("SELECT cp.id AS parte_id, cp.case_id, cp.papel, cp.tipo_pessoa,
                          cp.nome, cp.cpf, cp.rg, cp.nascimento, cp.profissao, cp.estado_civil,
                          cp.email, cp.telefone, cp.endereco, cp.cidade, cp.uf, cp.client_id,
                          cs.title
                   FROM case_partes cp
                   LEFT JOIN cases cs ON cs.id = cp.case_id
                   WHERE cp.nome LIKE '%Liliane%'");
foreach ($st as $r) print_r($r);
