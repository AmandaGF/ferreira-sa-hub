<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
error_reporting(E_ALL); ini_set('display_errors','1');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();
header('Content-Type: text/plain; charset=utf-8');

$ids = array(416, 417);

echo "== EVENTOS de agenda (audiencias) ==\n";
foreach ($ids as $id) {
    $st = $pdo->prepare("SELECT * FROM agenda_eventos WHERE id = ?");
    $st->execute(array($id));
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if ($r) {
        echo "  AUD #{$id}:\n";
        foreach (array('titulo','tipo','modalidade','data_inicio','status','case_id','client_id','responsavel_id','created_at','created_by') as $k) {
            echo "    $k = " . var_export($r[$k] ?? null, true) . "\n";
        }
    } else {
        echo "  AUD #{$id}: NAO existe\n";
    }
}

echo "\n== ANDAMENTOS criados pra cada uma (case_andamentos) ==\n";
foreach ($ids as $id) {
    $caseSt = $pdo->prepare("SELECT case_id FROM agenda_eventos WHERE id = ?");
    $caseSt->execute(array($id));
    $caseId = (int)$caseSt->fetchColumn();
    if (!$caseId) { echo "  AUD #{$id}: sem case_id\n"; continue; }
    $st = $pdo->prepare("SELECT id, data_andamento, tipo, descricao, created_at, created_by FROM case_andamentos WHERE case_id = ? AND created_at >= '2026-06-02 11:25:00' ORDER BY id DESC");
    $st->execute(array($caseId));
    $rs = $st->fetchAll(PDO::FETCH_ASSOC);
    echo "  AUD #{$id} caso#{$caseId} - {" . count($rs) . "} andamentos criados hoje 11:25+:\n";
    foreach ($rs as $r) {
        echo "    and#{$r['id']} [{$r['tipo']}] {$r['created_at']} por user#{$r['created_by']}:\n";
        echo "      " . substr($r['descricao'], 0, 200) . "\n";
    }
}

echo "\n== NOTIFICACOES criadas ==\n";
try {
    $st = $pdo->query("SELECT id, user_id, titulo, mensagem, created_at FROM notifications WHERE created_at >= '2026-06-02 11:25:00' AND (mensagem LIKE '%CRISTINA%' OR mensagem LIKE '%AIJ%') ORDER BY id DESC");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        echo "  notif#{$r['id']} user#{$r['user_id']} em {$r['created_at']}: {$r['titulo']} - {$r['mensagem']}\n";
    }
} catch (Exception $e) { echo "  erro: " . $e->getMessage() . "\n"; }

echo "\n== LEMBRETES (reuniao_interna) vinculados ==\n";
$st = $pdo->query("SELECT id, titulo, data_inicio, status, referencia_evento_id, created_at FROM agenda_eventos WHERE tipo='reuniao_interna' AND referencia_evento_id IN (416, 417)");
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "  lembrete#{$r['id']} ref={$r['referencia_evento_id']} titulo={$r['titulo']} status={$r['status']}\n";
}
echo "\n(nada acima = nenhum lembrete criado p/ as duplicadas)\n";
