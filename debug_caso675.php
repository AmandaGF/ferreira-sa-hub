<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave invalida'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();
$r = $pdo->query("SELECT id, title, case_number, court, comarca, comarca_uf, regional, sistema_tribunal, segredo_justica, distribution_date, drive_folder_url, status, parte_re_nome, parte_re_cpf_cnpj FROM cases WHERE id = 675")->fetch();
foreach ($r as $k => $v) {
    if (!is_numeric($k)) echo "$k = " . ($v === null ? 'NULL' : ($v === '' ? '(vazio)' : $v)) . "\n";
}
