<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== QUEILA #1175 — cadastro completo ===\n";
$st = $pdo->prepare("SELECT id, name, phone, email, cpf, criado_em, updated_at FROM clients WHERE id = 1175");
$st->execute();
$c = $st->fetch(PDO::FETCH_ASSOC);
print_r($c);

$telSoNumeros = preg_replace('/\D/', '', $c['phone'] ?? '');
echo "\nTel só números: '$telSoNumeros' (" . strlen($telSoNumeros) . " dígitos)\n";
echo "Padrão esperado BR: 11 dígitos (DDD 2 + 9 do celular)\n";
echo "Se tem 10, é fixo ou celular sem o 9\n";

echo "\n=== Conversas WA vinculadas a essa Queila ===\n";
$st = $pdo->prepare("SELECT co.id, co.canal, co.numero, co.nome_contato, co.ultima_mensagem_em, co.status
                     FROM zapi_conversas co WHERE co.client_id = 1175");
$st->execute();
foreach ($st as $r) print_r($r);

echo "\n=== Últimas mensagens em conversas dessa Queila ===\n";
$st = $pdo->prepare("SELECT m.id, m.direcao, m.status, m.zapi_message_id, m.created_at, m.tipo,
                            SUBSTRING(m.texto, 1, 80) AS texto_preview, co.canal
                     FROM zapi_mensagens m JOIN zapi_conversas co ON co.id=m.conversa_id
                     WHERE co.client_id = 1175
                     ORDER BY m.created_at DESC LIMIT 10");
$st->execute();
foreach ($st as $r) {
    printf("  #%d [%s canal=%s] %s status=%s mid=%s\n     %s\n",
        $r['id'], $r['direcao'], $r['canal'], $r['created_at'],
        ($r['status'] ?: 'VAZIO'), substr($r['zapi_message_id'] ?? '', 0, 25),
        substr($r['texto_preview'] ?? '', 0, 80));
}

echo "\n=== Fila de envio pendente pra Queila ===\n";
try {
    $st = $pdo->prepare("SELECT id, telefone, mensagem, status, agendado_para, criado_em, tentativas, ultimo_erro
                         FROM wa_agendamentos
                         WHERE telefone LIKE '%98349028%' OR telefone LIKE '%73983%'
                         ORDER BY criado_em DESC LIMIT 5");
    $st->execute();
    foreach ($st as $r) print_r($r);
} catch (Exception $e) { echo "  (tabela wa_agendamentos: " . $e->getMessage() . ")\n"; }

echo "\n=== zapi_fila_envio da Queila ===\n";
try {
    $st = $pdo->prepare("SELECT * FROM zapi_fila_envio WHERE client_id = 1175 ORDER BY id DESC LIMIT 5");
    $st->execute();
    foreach ($st as $r) print_r($r);
} catch (Exception $e) { echo "  (erro: " . $e->getMessage() . ")\n"; }
