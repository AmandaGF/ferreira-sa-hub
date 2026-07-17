<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== ALFREDO STATUS " . date('d/m/Y H:i:s') . " ===\n\n";

// Killswitch global
$g = (string)$pdo->query("SELECT valor FROM configuracoes WHERE chave='alfredo_ativo_global'")->fetchColumn();
echo "Killswitch global: " . ($g === '1' ? '✓ ATIVO' : '✗ DESLIGADO') . "\n";

// Lock cron
$lock = (string)$pdo->query("SELECT valor FROM configuracoes WHERE chave='alfredo_lock'")->fetchColumn();
if ($lock) echo "Lock do cron: rodando ha " . (time() - (int)$lock) . "s\n";
else echo "Lock do cron: livre\n";

echo "\n=== CONVERSAS COM ALFREDO ATIVO ===\n";
try {
    foreach ($pdo->query("SELECT co.id, co.nome_contato, co.telefone, co.canal, co.client_id,
                                 cl.name AS client_name, co.alfredo_ativado_em,
                                 co.ultima_msg_em, co.ultima_mensagem
                          FROM zapi_conversas co
                          LEFT JOIN clients cl ON cl.id = co.client_id
                          WHERE co.alfredo_ativo = 1") as $r) {
        printf("  conv#%d canal=%s | %s (%s)\n", $r['id'], $r['canal'],
            $r['client_name'] ?: $r['nome_contato'], $r['telefone']);
        echo "    ativado em: " . $r['alfredo_ativado_em'] . "\n";
        echo "    ultima msg: " . $r['ultima_msg_em'] . " -> " . mb_substr((string)$r['ultima_mensagem'], 0, 80) . "\n";
    }
} catch (Exception $e) { echo "  (nenhuma / erro: " . $e->getMessage() . ")\n"; }

echo "\n=== ULTIMAS 5 MSGS RECEBIDAS canal 24 (todas as convs) ===\n";
foreach ($pdo->query("SELECT m.id, m.conversa_id, m.direcao, m.created_at, LEFT(m.texto, 60) t,
                             co.nome_contato, cl.name AS client_name
                      FROM zapi_mensagens m
                      JOIN zapi_conversas co ON co.id = m.conversa_id
                      LEFT JOIN clients cl ON cl.id = co.client_id
                      WHERE co.canal = '24' AND m.direcao = 'recebida'
                      ORDER BY m.id DESC LIMIT 5") as $r) {
    printf("  msg#%d conv#%d %s | %s: %s\n", $r['id'], $r['conversa_id'], $r['created_at'],
        $r['client_name'] ?: $r['nome_contato'], preg_replace('/\s+/', ' ', $r['t']));
}

echo "\n=== SUGESTOES ALFREDO NAS ULTIMAS 24H ===\n";
try {
    $c = 0;
    foreach ($pdo->query("SELECT id, conversa_id, status, eh_sos, created_at, LEFT(sugestao_texto,80) t
                          FROM alfredo_sugestoes
                          WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                          ORDER BY id DESC LIMIT 10") as $r) {
        $c++;
        printf("  sug#%d conv#%d status=%s sos=%d %s | %s\n",
            $r['id'], $r['conversa_id'], $r['status'], $r['eh_sos'], $r['created_at'],
            preg_replace('/\s+/', ' ', $r['t']));
    }
    if (!$c) echo "  (nenhuma sugestao gerada)\n";
} catch (Exception $e) { echo "  erro: " . $e->getMessage() . "\n"; }
