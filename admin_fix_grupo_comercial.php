<?php
/** Corrige o grupo salvo (sufixo @g.us + canal certo) e opcionalmente manda teste.
 *  ?key=...           → corrige e mostra
 *  ?key=...&enviar=1  → corrige e ENVIA mensagem de teste no grupo
 *  Remover após uso. */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('nope'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions.php';
require_once __DIR__ . '/core/functions_comercial.php';
$pdo = db();

$cfg = comercial_cfg($pdo);
echo "ANTES: grupo_id='{$cfg['grupo_id']}' canal='{$cfg['grupo_canal']}'\n";

$base = preg_replace('/(@g\.us|-group)$/', '', $cfg['grupo_id']);
if ($base === '') { echo "Nenhum grupo configurado.\n"; exit; }

$ddd = null;
$st = $pdo->prepare("SELECT i.ddd FROM zapi_conversas co JOIN zapi_instancias i ON i.id=co.instancia_id
                     WHERE co.telefone = ? AND COALESCE(co.eh_grupo,0)=1 LIMIT 1");
$st->execute(array($base));
$ddd = $st->fetchColumn();
$canal = $ddd ? (string)$ddd : $cfg['grupo_canal'];
$fixed = (strpos($base, '@') === false && strpos($base, '-group') === false) ? $base . '@g.us' : $base;

comercial_set_cfg($pdo, 'comercial_grupo_id', $fixed);
comercial_set_cfg($pdo, 'comercial_cobranca_canal', $canal);
echo "DEPOIS: grupo_id='{$fixed}' canal='{$canal}' (detectado pelo número que participa do grupo)\n";

if (!empty($_GET['enviar'])) {
    echo "\nEnviando teste...\n";
    $r = zapi_send_text($canal, $fixed, "✅ Teste do CRM Comercial — as cobranças de leads pendentes vão chegar neste grupo.");
    echo "ok=" . (!empty($r['ok']) ? 'SIM' : 'NAO') . " http=" . ($r['http_code'] ?? '?') . " erro=" . ($r['erro'] ?? '-') . "\n";
}
