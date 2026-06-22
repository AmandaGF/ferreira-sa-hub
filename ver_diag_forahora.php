<?php
/** Diag temp: confere se a auto-msg de fora de horário casa com o filtro. Remover após uso. */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('nope'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

$n = (int)$pdo->query("SELECT COUNT(*) FROM zapi_mensagens WHERE direcao='enviada' AND conteudo LIKE '%estamos fora do nosso hor%'")->fetchColumn();
echo "Mensagens 'enviada' que casam com '%estamos fora do nosso hor%': $n\n\n";

echo "Amostra (3):\n";
$st = $pdo->query("SELECT id, conversa_id, LEFT(conteudo,70) txt, created_at FROM zapi_mensagens WHERE direcao='enviada' AND conteudo LIKE '%estamos fora do nosso hor%' ORDER BY id DESC LIMIT 3");
foreach ($st->fetchAll() as $r) {
    echo "  msg#{$r['id']} conv#{$r['conversa_id']} {$r['created_at']} | {$r['txt']}\n";
}

// também tenta casar pelo começo "Obrigado pelo seu contato" pra comparar
$n2 = (int)$pdo->query("SELECT COUNT(*) FROM zapi_mensagens WHERE direcao='enviada' AND conteudo LIKE '%Obrigado pelo seu contato%'")->fetchColumn();
echo "\n(comparação) casam com '%Obrigado pelo seu contato%': $n2\n";
