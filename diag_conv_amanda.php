<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Diag: conversa Amanda Ferreira no DDD 24 ===\n\n";

// Achar a conversa da Amanda no DDD 24
$conv = $pdo->query("SELECT co.*, cl.name AS client_name
                     FROM zapi_conversas co
                     LEFT JOIN clients cl ON cl.id = co.client_id
                     WHERE co.canal = '24' AND co.telefone LIKE '%4992234554%'
                     LIMIT 1")->fetch();
if (!$conv) { echo "Conversa não encontrada.\n"; exit; }

echo "Conversa id={$conv['id']} | cliente={$conv['client_name']} | client_id={$conv['client_id']}\n\n";

echo "=== Últimas 10 mensagens dessa conversa ===\n";
$msgs = $pdo->prepare("SELECT id, direcao, tipo, status, LEFT(conteudo, 60) as c, arquivo_url IS NOT NULL AS tem_arq, arquivo_salvo_drive, created_at
                       FROM zapi_mensagens WHERE conversa_id = ? ORDER BY id DESC LIMIT 10");
$msgs->execute(array($conv['id']));
foreach ($msgs->fetchAll() as $m) {
    echo "  #{$m['id']} | {$m['direcao']} | {$m['tipo']} | status={$m['status']} | conteudo='{$m['c']}' | tem_arquivo=" . ($m['tem_arq'] ? 'SIM' : 'não') . " | salvo_drive=" . ($m['arquivo_salvo_drive'] ?: 0) . " | {$m['created_at']}\n";
}

echo "\n=== Qual seria o preview calculado pela query atual? ===\n";
$preview = $pdo->prepare("SELECT id, conteudo, status FROM zapi_mensagens
                          WHERE conversa_id = ? AND status != 'deletada' AND conteudo IS NOT NULL AND conteudo != ''
                          ORDER BY id DESC LIMIT 1");
$preview->execute(array($conv['id']));
$p = $preview->fetch();
if ($p) echo "  Preview: msg #{$p['id']} status={$p['status']} '{$p['conteudo']}'\n";
else echo "  Nenhuma mensagem qualifica → preview vazio\n";

echo "\n=== Casos da cliente ({$conv['client_name']}) ===\n";
if ($conv['client_id']) {
    $cases = $pdo->prepare("SELECT id, client_title, case_type, status, drive_folder_url FROM cases WHERE client_id = ?");
    $cases->execute(array($conv['client_id']));
    foreach ($cases->fetchAll() as $c) {
        echo "  Caso #{$c['id']} | {$c['client_title']} | {$c['case_type']} | status={$c['status']} | drive=" . ($c['drive_folder_url'] ? substr($c['drive_folder_url'], 0, 60) . '...' : 'VAZIO') . "\n";
    }
} else {
    echo "  Conversa sem client_id vinculado\n";
}
