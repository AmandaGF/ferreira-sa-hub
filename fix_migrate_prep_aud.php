<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();
header('Content-Type: text/plain; charset=utf-8');
// Migra todos os lembretes vinculados a audiencia (reuniao_interna com
// referencia_evento_id) pro novo tipo preparacao_audiencia
$st = $pdo->prepare("UPDATE agenda_eventos SET tipo='preparacao_audiencia' WHERE tipo='reuniao_interna' AND referencia_evento_id IS NOT NULL");
$st->execute();
echo "Migrados " . $st->rowCount() . " lembrete(s) reuniao_interna -> preparacao_audiencia\n";

// Mostra estado final
$st = $pdo->query("SELECT id, titulo, tipo, data_inicio, status FROM agenda_eventos WHERE referencia_evento_id IS NOT NULL");
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) echo "  #{$r['id']} tipo={$r['tipo']} status={$r['status']} {$r['titulo']}\n";
