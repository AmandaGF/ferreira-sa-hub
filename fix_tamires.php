<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();
header('Content-Type: text/plain; charset=utf-8');

// Conversa id=221 (Tamires/Tamyris, telefone 5524999242710, atendente=6)
$st = $pdo->prepare("SELECT id, telefone, nome_contato, status, atendente_id, nao_lidas FROM zapi_conversas WHERE id = 221");
$st->execute();
$c = $st->fetch(PDO::FETCH_ASSOC);
if (!$c) { echo "Conversa 221 nao existe\n"; exit; }
echo "ANTES: status={$c['status']} atendente={$c['atendente_id']} nao_lidas={$c['nao_lidas']}\n";

if ($c['status'] === 'arquivado') {
    // Tinha atendente=6, entao volta pra em_atendimento (como faria o webhook agora)
    $novo = $c['atendente_id'] ? 'em_atendimento' : 'aguardando';
    $pdo->prepare("UPDATE zapi_conversas SET status = ? WHERE id = ?")->execute(array($novo, 221));
    echo "  -> desarquivada como $novo\n";
}
$st->execute();
$c = $st->fetch(PDO::FETCH_ASSOC);
echo "DEPOIS: status={$c['status']} atendente={$c['atendente_id']} nao_lidas={$c['nao_lidas']}\n";
