<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave invalida'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== DIAG case_type = 'Outro' (badge OUT) ===\n";
echo "Data: " . date('Y-m-d H:i:s') . "\n\n";

// 1. Distribuicao de case_type entre LEADS recentes (ultimos 30 dias)
echo "1. pipeline_leads - case_type dos leads criados nos ultimos 30 dias:\n";
$rows = $pdo->query("
    SELECT COALESCE(NULLIF(case_type,''),'(vazio/NULL)') AS ct, source, COUNT(*) AS n
    FROM pipeline_leads
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY ct, source
    ORDER BY n DESC
")->fetchAll();
foreach ($rows as $r) echo "   [{$r['ct']}]  source={$r['source']}  ->  {$r['n']}\n";

// 2. Os leads pos-contrato atuais (o que a Amanda ve no print)
echo "\n2. pipeline_leads em estagios pos-contrato (o Kanban do print):\n";
$rows = $pdo->query("
    SELECT id, name, source, COALESCE(NULLIF(case_type,''),'(vazio/NULL)') AS ct,
           stage, linked_case_id, DATE(created_at) AS criado
    FROM pipeline_leads
    WHERE stage IN ('contrato_assinado','agendado_docs','reuniao_cobranca','pasta_apta','pasta_apta_prev')
    ORDER BY created_at DESC
    LIMIT 40
")->fetchAll();
foreach ($rows as $r) {
    echo "   #{$r['id']} | {$r['criado']} | src={$r['source']} | case_type=[{$r['ct']}] | stage={$r['stage']} | caso={$r['linked_case_id']} | {$r['name']}\n";
}

// 3. Cases correspondentes - case_type vs title (title tem 'x Alimentos' etc)
echo "\n3. cases recentes (ultimos 30 dias) - case_type vs title:\n";
$rows = $pdo->query("
    SELECT id, COALESCE(NULLIF(case_type,''),'(vazio/NULL)') AS ct, title, DATE(created_at) AS criado
    FROM cases
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ORDER BY created_at DESC
    LIMIT 40
")->fetchAll();
foreach ($rows as $r) {
    echo "   #{$r['id']} | {$r['criado']} | case_type=[{$r['ct']}] | {$r['title']}\n";
}

// 4. Quantos cases tem case_type que NAO classifica (== Outro literal ou vazio)
echo "\n4. cases ativos com case_type == 'Outro'/'outro' literal:\n";
$r = $pdo->query("SELECT COUNT(*) n FROM cases WHERE LOWER(TRIM(case_type)) = 'outro'")->fetch();
echo "   total (todos): {$r['n']}\n";
$r = $pdo->query("SELECT COUNT(*) n FROM pipeline_leads WHERE LOWER(TRIM(case_type)) = 'outro'")->fetch();
echo "   pipeline_leads: {$r['n']}\n";

echo "\n=== FIM ===\n";
