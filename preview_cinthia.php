<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_aviso_cliente.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

// Pega o ultimo andamento do case #776 (Cinthia Gama)
$st = $pdo->prepare("SELECT ca.id, ca.data_andamento, ca.tipo, ca.tipo_origem, ca.descricao,
                            cs.title, cl.name AS cliente
                     FROM case_andamentos ca
                     LEFT JOIN cases cs ON cs.id = ca.case_id
                     LEFT JOIN clients cl ON cl.id = cs.client_id
                     WHERE ca.case_id = 776
                     ORDER BY ca.data_andamento DESC, ca.id DESC
                     LIMIT 1");
$st->execute();
$a = $st->fetch(PDO::FETCH_ASSOC);

echo "=== ULTIMO ANDAMENTO DO CASE #776 (Cinthia Gama x Indenizatoria) ===\n\n";
echo "Data:     " . $a['data_andamento'] . "\n";
echo "Tipo:     " . ($a['tipo'] ?: '-') . " / " . ($a['tipo_origem'] ?: '-') . "\n";
echo "Cliente:  " . $a['cliente'] . "\n";
echo "Processo: " . $a['title'] . "\n\n";
echo "DESCRICAO ORIGINAL (o que foi lancado):\n";
echo "----------------------------------------\n";
echo $a['descricao'] . "\n\n";

echo "==========================================\n";
echo "CHAMANDO IA (Claude Haiku 4.5)...\n";
echo "==========================================\n\n";

$ands = array($a);
$resumo = aviso_cliente_resumir_via_ia($ands, $a['cliente'], $a['title']);

echo "MENSAGEM QUE SERIA ENVIADA POR WHATSAPP:\n";
echo "==========================================\n";
if ($resumo) {
    echo $resumo . "\n";
} else {
    echo "(IA falhou ou saiu do personagem — cairia sem enviar)\n";
}
echo "==========================================\n";
echo "Caracteres: " . mb_strlen($resumo ?: '', 'UTF-8') . "\n";
