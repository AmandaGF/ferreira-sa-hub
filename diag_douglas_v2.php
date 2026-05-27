<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
require_once __DIR__ . '/core/database.php';
$pdo = db();
header('Content-Type: text/plain; charset=utf-8');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$cid = 637; // client_id do Douglas Silva de Souza

echo "=== Investigando bug Douglas (client#$cid) ===\n\n";

echo "--- TODOS os casos do client_id $cid (inclusive arquivados) ---\n";
$st = $pdo->prepare("SELECT id, title, case_number, status, kanban_oculto, created_at, updated_at FROM cases WHERE client_id = ? ORDER BY id DESC");
$st->execute(array($cid));
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $cs) {
    echo "  case#{$cs['id']}  '{$cs['title']}'  status={$cs['status']}  kanban_oculto={$cs['kanban_oculto']}  CNJ=" . ($cs['case_number'] ?: '-') . "\n";
    echo "       criado=" . $cs['created_at'] . "  atualizado=" . $cs['updated_at'] . "\n";
}

echo "\n--- Conversas WhatsApp deste client ---\n";
try {
    $st = $pdo->query("SHOW COLUMNS FROM zapi_conversas");
    $cols = array();
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) $cols[] = $c['Field'];
    $hasAt = in_array('atualizado_em', $cols) ? 'atualizado_em' : (in_array('updated_at', $cols) ? 'updated_at' : null);
    $st = $pdo->prepare("SELECT id, canal, telefone, nome_contato" . ($hasAt ? ", $hasAt AS ult" : '') . " FROM zapi_conversas WHERE client_id = ? ORDER BY id DESC");
    $st->execute(array($cid));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $cv) {
        echo "  conv#{$cv['id']}  canal={$cv['canal']}  tel='{$cv['telefone']}'  nome='{$cv['nome_contato']}'" . (isset($cv['ult']) ? '  ult=' . $cv['ult'] : '') . "\n";
    }
} catch (Throwable $e) { echo "  ERRO: " . $e->getMessage() . "\n"; }

echo "\n--- Conversas WhatsApp com mesmo telefone (sem client_id ou outro client_id) ---\n";
$phones = array('5524999798349','24999798349','24998731817','5524998731817');
foreach ($phones as $ph) {
    try {
        $st = $pdo->prepare("SELECT id, canal, telefone, nome_contato, client_id FROM zapi_conversas WHERE telefone = ? OR telefone = ? OR telefone = ? ORDER BY id DESC LIMIT 5");
        $st->execute(array($ph, '+' . $ph, preg_replace('/^55/', '', $ph)));
        $r = $st->fetchAll(PDO::FETCH_ASSOC);
        if ($r) {
            echo "  tel='$ph':\n";
            foreach ($r as $cv) echo "       conv#{$cv['id']}  canal={$cv['canal']}  tel='{$cv['telefone']}'  client_id=" . ($cv['client_id'] ?? 'NULL') . "  nome='{$cv['nome_contato']}'\n";
        }
    } catch (Throwable $e) {}
}

echo "\n--- Diagnostico ---\n";
echo "Cliente Douglas#637 tem 1 caso (arquivado).\n";
echo "A funcao waAbrirProcesso() filtra 'status !== arquivado' — por isso diz 'sem processo ativo'.\n";
echo "Provavel cenario: a Amanda criou um caso NOVO mas em outro client (duplicata) OU o caso atual foi marcado arquivado por engano.\n";
