<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_aviso_cliente.php';
if (function_exists('opcache_reset')) opcache_reset();
header('Content-Type: text/plain; charset=utf-8');
@set_time_limit(240);
$pdo = db();
$cl = $pdo->query("SELECT id, name FROM clients WHERE name LIKE '%Rayane%Viana%' LIMIT 1")->fetch();
$a = $pdo->query("SELECT ca.descricao, ca.data_andamento, ca.tipo, cs.title AS case_title
                  FROM case_andamentos ca JOIN cases cs ON cs.id=ca.case_id
                  WHERE cs.client_id={$cl['id']} AND cs.status NOT IN ('arquivado','cancelado','renunciamos','concluido','finalizado')
                    AND COALESCE(ca.visivel_cliente,0)=1
                  ORDER BY ca.data_andamento DESC, ca.id DESC LIMIT 1")->fetch();
$modo = aviso_cliente_determinar_modo($pdo, (int)$cl['id'], (string)$a['data_andamento']);
echo "modo=" . $modo['modo'] . " (dias=" . $modo['dias'] . ", perguntou=" . (int)$modo['cliente_perguntou_apos'] . ")\n\n";
echo "=== chamando aviso_cliente_resumir_via_ia (com Sonnet pois modo != NOVIDADE) ===\n";
$msg = aviso_cliente_resumir_via_ia(array($a), $cl['name'], $a['case_title'], array(), $modo);
echo ($msg ?: '(vazio)') . "\n";
