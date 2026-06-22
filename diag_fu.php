<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('x'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__.'/core/config.php'; require_once __DIR__.'/core/database.php';
$pdo=db();
echo "=== Estado do Follow-up ===\n";
foreach($pdo->query("SELECT chave,valor FROM configuracoes WHERE chave LIKE 'followup_%'")->fetchAll() as $r) echo "  {$r['chave']} = {$r['valor']}\n";
echo "\nleads com primeiro_contato_em preenchido: ".(int)$pdo->query("SELECT COUNT(*) FROM pipeline_leads WHERE primeiro_contato_em IS NOT NULL")->fetchColumn()."\n";
echo "templates followup cadastrados:\n";
foreach($pdo->query("SELECT nome FROM zapi_templates WHERE categoria='followup' ORDER BY nome")->fetchAll() as $r) echo "  - {$r['nome']}\n";
