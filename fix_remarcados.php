<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

// Eventos com status 'remarcado' que têm data futura = foram atualizados pelo fluxo antigo
// Devem voltar para 'agendado'
$rows = $pdo->query("SELECT id, titulo, data_inicio, status FROM agenda_eventos WHERE status = 'remarcado' AND data_inicio >= NOW()")->fetchAll();
echo "Eventos remarcados com data futura: " . count($rows) . "\n";
foreach ($rows as $r) {
    echo "  #" . $r['id'] . " " . $r['data_inicio'] . " " . $r['titulo'] . "\n";
}

$pdo->exec("UPDATE agenda_eventos SET status = 'agendado', updated_at = NOW() WHERE status = 'remarcado' AND data_inicio >= NOW()");
echo "Corrigidos para 'agendado'.\n";
