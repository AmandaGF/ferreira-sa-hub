<?php
// Diagnostico READ-ONLY: por que a Tamires (24999242710) nao aparece no hub WhatsApp
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
error_reporting(E_ALL); ini_set('display_errors','1');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();
echo "<pre style='font-family:Consolas,monospace;font-size:12px;'>";

$tels = array('24999242710','5524999242710','24 99924-2710','24999242710@c.us');
$padroes = array('%24999242710%', '%999242710%');

echo "=== 1) CONVERSAS EM zapi_conversas (busca por telefone) ===\n";
$st = $pdo->prepare("SELECT id, contato_nome, contato_telefone, canal, status, atendente_id, nao_lidas, ultima_msg_em, ultima_msg_preview, criada_em FROM zapi_conversas WHERE contato_telefone LIKE ? OR contato_telefone LIKE ? ORDER BY id DESC");
$st->execute($padroes);
$convs = $st->fetchAll(PDO::FETCH_ASSOC);
if (!$convs) echo "  Nenhuma conversa encontrada por padroes: " . implode(' / ', $padroes) . "\n";
foreach ($convs as $c) {
    echo "  CONV id={$c['id']} tel={$c['contato_telefone']} canal={$c['canal']} status={$c['status']} atendente={$c['atendente_id']} nao_lidas={$c['nao_lidas']}\n";
    echo "    nome: {$c['contato_nome']}\n";
    echo "    ultima_em: {$c['ultima_msg_em']}\n";
    echo "    preview: " . substr($c['ultima_msg_preview'] ?? '', 0, 80) . "\n";
}

echo "\n=== 2) MENSAGENS ULTIMAS 7 DIAS contendo 24999242710 ou variantes ===\n";
$st2 = $pdo->prepare("SELECT id, conversa_id, direcao, conteudo_preview, zapi_message_id, status, criada_em FROM zapi_mensagens WHERE (raw_payload LIKE ? OR raw_payload LIKE ?) AND criada_em > DATE_SUB(NOW(), INTERVAL 7 DAY) ORDER BY id DESC LIMIT 20");
$st2->execute($padroes);
$msgs = $st2->fetchAll(PDO::FETCH_ASSOC);
if (!$msgs) echo "  Nenhuma mensagem nos ultimos 7d cita esse numero no payload\n";
foreach ($msgs as $m) {
    echo "  MSG id={$m['id']} conv={$m['conversa_id']} dir={$m['direcao']} status={$m['status']} em={$m['criada_em']}\n";
    echo "    preview: " . substr($m['conteudo_preview'] ?? '', 0, 80) . "\n";
    echo "    zapi_id: " . substr($m['zapi_message_id'] ?? '', 0, 30) . "\n";
}

echo "\n=== 3) BLOCKLIST / LISTAS NEGRAS ===\n";
try {
    $blk = $pdo->query("SHOW TABLES LIKE '%bloq%'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($blk as $t) echo "  tabela: $t\n";
    if (!$blk) echo "  Nenhuma tabela de bloqueio encontrada\n";
} catch (Exception $e) { echo "  erro: " . $e->getMessage() . "\n"; }

echo "\n=== 4) CLIENTE por telefone ===\n";
$st4 = $pdo->prepare("SELECT id, name, phone, created_at FROM clients WHERE phone LIKE ? OR phone LIKE ?");
$st4->execute($padroes);
foreach ($st4->fetchAll(PDO::FETCH_ASSOC) as $c) {
    echo "  CLIENTE id={$c['id']} nome={$c['name']} tel={$c['phone']} criado={$c['created_at']}\n";
}

echo "\n=== 5) WEBHOOK: ultimas 30 entradas (qualquer numero) pra ver se chegam ===\n";
try {
    // Busca alguma tabela de log
    $logs = $pdo->query("SHOW TABLES LIKE 'zapi_webhook_log'")->fetchAll(PDO::FETCH_COLUMN);
    if ($logs) {
        $st5 = $pdo->query("SELECT id, criado_em, LEFT(payload, 200) AS preview FROM zapi_webhook_log ORDER BY id DESC LIMIT 5");
        foreach ($st5->fetchAll(PDO::FETCH_ASSOC) as $r) {
            echo "  LOG id={$r['id']} em={$r['criado_em']}\n    " . $r['preview'] . "\n";
        }
    } else {
        echo "  Sem tabela zapi_webhook_log\n";
    }
} catch (Exception $e) { echo "  erro: " . $e->getMessage() . "\n"; }

echo "\n=== 6) INSTANCIAS Z-API ATIVAS ===\n";
$st6 = $pdo->query("SELECT canal, descricao, ativa, criado_em FROM zapi_instancias");
foreach ($st6->fetchAll(PDO::FETCH_ASSOC) as $i) {
    echo "  CANAL {$i['canal']} ativa={$i['ativa']} desc={$i['descricao']}\n";
}

echo "</pre>";
