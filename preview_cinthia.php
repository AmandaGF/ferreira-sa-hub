<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_aviso_cliente.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();
@set_time_limit(60);

$st = $pdo->prepare("SELECT ca.id, ca.data_andamento, ca.tipo, ca.descricao,
                            cs.title, cl.name AS cliente
                     FROM case_andamentos ca
                     LEFT JOIN cases cs ON cs.id = ca.case_id
                     LEFT JOIN clients cl ON cl.id = cs.client_id
                     WHERE ca.case_id = 776
                     ORDER BY ca.data_andamento DESC, ca.id DESC LIMIT 1");
$st->execute();
$a = $st->fetch(PDO::FETCH_ASSOC);

echo "=== ORIGINAL ===\n" . $a['descricao'] . "\n\n";

echo "=== IA v5 (nome no topo + Central VIP no fim + blacklist ampla) ===\n\n";
$r = aviso_cliente_resumir_via_ia(array($a), $a['cliente'], $a['title']);
echo ($r ?: '(vazio/descartado)') . "\n";
echo "\n---\nchars: " . mb_strlen($r ?: '', 'UTF-8') . "\n";
