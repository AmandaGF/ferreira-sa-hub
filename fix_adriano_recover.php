<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave invalida'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Tentando recuperar dados do caso #669 ===\n\n";

// Verificar andamentos
echo "ANDAMENTOS:\n";
$and = $pdo->query("SELECT id, data_andamento, tipo, LEFT(descricao, 100) as desc_curta, tipo_origem FROM case_andamentos WHERE case_id = 669 ORDER BY data_andamento DESC")->fetchAll();
echo count($and) . " andamentos encontrados\n";
foreach ($and as $a) { echo "  #{$a['id']} | {$a['data_andamento']} | {$a['tipo']} | {$a['tipo_origem']} | {$a['desc_curta']}\n"; }

// Verificar agenda
echo "\nAGENDA:\n";
$ag = $pdo->query("SELECT id, titulo, tipo, data_inicio FROM agenda_eventos WHERE case_id = 669 ORDER BY data_inicio DESC")->fetchAll();
echo count($ag) . " eventos encontrados\n";
foreach ($ag as $a) { echo "  #{$a['id']} | {$a['data_inicio']} | {$a['tipo']} | {$a['titulo']}\n"; }

// Verificar partes
echo "\nPARTES:\n";
$pt = $pdo->query("SELECT id, nome, papel FROM case_partes WHERE case_id = 669")->fetchAll();
echo count($pt) . " partes\n";
foreach ($pt as $p) { echo "  #{$p['id']} | {$p['papel']} | {$p['nome']}\n"; }

// Verificar tarefas
echo "\nTAREFAS:\n";
$tk = $pdo->query("SELECT id, title, status FROM case_tasks WHERE case_id = 669")->fetchAll();
echo count($tk) . " tarefas\n";
foreach ($tk as $t) { echo "  #{$t['id']} | {$t['status']} | {$t['title']}\n"; }

// Verificar datajud
echo "\nDATAJUD SYNC:\n";
$dj = $pdo->query("SELECT id, status, movimentos_novos, mensagem FROM datajud_sync_log WHERE case_id = 669")->fetchAll();
echo count($dj) . " syncs\n";
foreach ($dj as $d) { echo "  #{$d['id']} | {$d['status']} | novos={$d['movimentos_novos']} | {$d['mensagem']}\n"; }

// Verificar prazos
echo "\nPRAZOS:\n";
$pz = $pdo->query("SELECT id, descricao_acao, prazo_fatal FROM prazos_processuais WHERE case_id = 669")->fetchAll();
echo count($pz) . " prazos\n";
foreach ($pz as $p) { echo "  #{$p['id']} | {$p['prazo_fatal']} | {$p['descricao_acao']}\n"; }

// Verificar docs pendentes
echo "\nDOCS PENDENTES:\n";
$dp = $pdo->query("SELECT id, descricao, status FROM documentos_pendentes WHERE case_id = 669")->fetchAll();
echo count($dp) . " docs\n";
foreach ($dp as $d) { echo "  #{$d['id']} | {$d['status']} | {$d['descricao']}\n"; }
