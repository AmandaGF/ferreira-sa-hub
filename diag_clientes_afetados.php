<?php
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
header('Content-Type: text/plain; charset=utf-8');
error_reporting(E_ALL); ini_set('display_errors','1');
$pdo = db();

echo "=== Clientes afetados pelo bug do cliente_id=0 ===\n\n";

// Threads com respostas da equipe que estao com lida_cliente=0 (nao vistas)
$st = $pdo->query(
    "SELECT t.id AS thread_id, t.assunto, t.status, t.cliente_id, t.atualizado_em,
            c.name AS cli_nome, c.email AS cli_email, c.phone AS cli_phone,
            (SELECT COUNT(*) FROM salavip_mensagens WHERE thread_id = t.id AND origem = 'conecta' AND lida_cliente = 0) AS n_nao_lidas
     FROM salavip_threads t
     JOIN clients c ON c.id = t.cliente_id
     WHERE EXISTS (
         SELECT 1 FROM salavip_mensagens m
         WHERE m.thread_id = t.id AND m.origem = 'conecta' AND m.lida_cliente = 0
     )
     ORDER BY t.atualizado_em DESC"
);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
echo "Total de threads com respostas nao-lidas: " . count($rows) . "\n\n";

foreach ($rows as $r) {
    echo "-- thread #$r[thread_id] '$r[assunto]' status=$r[status] ($r[n_nao_lidas] nao-lidas) --\n";
    echo "   Cliente #$r[cliente_id] $r[cli_nome]\n";
    echo "   📧 $r[cli_email]\n";
    echo "   📱 $r[cli_phone]\n";
    echo "   Atualizada em: $r[atualizado_em]\n\n";
}
