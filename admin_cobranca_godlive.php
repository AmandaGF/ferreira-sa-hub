<?php
/** Go-live: marca o bom-dia de hoje como já feito + mostra preview (dry). Remover após uso. */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('nope'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions.php';
require_once __DIR__ . '/core/functions_comercial.php';
$pdo = db();

// pula o "bom dia" de hoje (ligamos à tarde) — começa amanhã 9h
comercial_set_cfg($pdo, 'comercial_cobranca_bomdia_data', date('Y-m-d'));
echo "Bom-dia de hoje marcado como já feito (começa amanhã às 9h). ✅\n\n";

echo "--- PREVIEW do próximo disparo (NÃO envia nada) ---\n";
$rep = comercial_rodar_cobranca($pdo, array('dry' => true, 'forcar_horario' => true, 'ignorar_ativo' => true));
echo "Leads pendentes agora: " . ($rep['pendentes'] ?? 0) . "\n";
foreach (($rep['detalhe'] ?? array()) as $d) {
    echo "  • {$d['nome']} — {$d['min']}min — resp: {$d['responsavel']}\n";
}
if (!empty($rep['grupo_preview'])) {
    echo "\nMensagem que iria pro grupo:\n" . $rep['grupo_preview'] . "\n";
}
