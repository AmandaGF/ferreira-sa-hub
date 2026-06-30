<?php
if (($_GET['key']??'') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('no'); }
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Diag canal 24 — " . date('d/m/Y H:i:s') . " ===\n\n";

// 1) Última msg recebida em qualquer canal (sanity)
echo "--- Última msg recebida (qualquer canal) ---\n";
$ult = $pdo->query("SELECT canal, sentido, criada_em, ddi, telefone FROM zapi_mensagens ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
foreach ($ult as $m) echo "  canal={$m['canal']} sent={$m['sentido']} em={$m['criada_em']} tel={$m['ddi']}{$m['telefone']}\n";

// 2) Últimas 10 msgs do canal 24 (qualquer sentido)
echo "\n--- Últimas 10 msgs canal=24 ---\n";
$c24 = $pdo->query("SELECT id, sentido, criada_em, telefone, LEFT(corpo,60) AS preview, zapi_message_id, status
                    FROM zapi_mensagens WHERE canal='24' ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
foreach ($c24 as $m) echo "  #{$m['id']} {$m['sentido']} em={$m['criada_em']} tel={$m['telefone']} status={$m['status']} msgid={$m['zapi_message_id']} | {$m['preview']}\n";

// 3) Verificar última msg de HOJE no canal 24
echo "\n--- Msgs canal=24 hoje (" . date('Y-m-d') . ") ---\n";
$hoje = $pdo->query("SELECT COUNT(*) AS total, MAX(criada_em) AS ultima FROM zapi_mensagens WHERE canal='24' AND DATE(criada_em)=CURDATE()")->fetch(PDO::FETCH_ASSOC);
echo "  Total hoje: {$hoje['total']} | última: {$hoje['ultima']}\n";

// 4) Última de cada sentido HOJE
$sent = $pdo->query("SELECT sentido, COUNT(*) AS qtd, MAX(criada_em) AS ult FROM zapi_mensagens WHERE canal='24' AND DATE(criada_em)=CURDATE() GROUP BY sentido")->fetchAll(PDO::FETCH_ASSOC);
foreach ($sent as $s) echo "  {$s['sentido']}: {$s['qtd']} (última {$s['ult']})\n";

// 5) Instância 24 do banco
echo "\n--- Instância 24 (zapi_instancias) ---\n";
try {
    $inst = $pdo->query("SELECT canal, instancia_id, LEFT(token,12) AS tok_prefix, ativo, atualizado_em FROM zapi_instancias WHERE canal='24'")->fetch(PDO::FETCH_ASSOC);
    if ($inst) {
        echo "  instancia_id={$inst['instancia_id']} tok={$inst['tok_prefix']}... ativo={$inst['ativo']} atualizado={$inst['atualizado_em']}\n";
    } else echo "  NÃO ENCONTRADA\n";
} catch (Exception $e) { echo "  Erro: " . $e->getMessage() . "\n"; }

// 6) Heartbeat / saúde
echo "\n--- Heartbeat zapi (saude_check) ---\n";
try {
    $hb = $pdo->query("SELECT MAX(criada_em) AS ult FROM zapi_saude_logs WHERE canal='24'")->fetch(PDO::FETCH_ASSOC);
    echo "  última checagem: " . ($hb['ult'] ?? '?') . "\n";
} catch (Exception $e) {
    echo "  (tabela zapi_saude_logs não existe ou erro)\n";
}

// 7) Webhook logs (se tabela existir)
echo "\n--- Tabelas relevantes ---\n";
$tbs = $pdo->query("SHOW TABLES LIKE 'zapi%'")->fetchAll(PDO::FETCH_COLUMN);
foreach ($tbs as $t) echo "  $t\n";

// 8) Ver últimas conversas atualizadas canal 24
echo "\n--- Últimas 5 conversas canal=24 ---\n";
$cvs = $pdo->query("SELECT id, telefone, nome_contato, ultima_em, ultima_texto, ultimo_sentido FROM zapi_conversas WHERE canal='24' ORDER BY ultima_em DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cvs as $c) {
    $prev = mb_substr(preg_replace('/\s+/', ' ', (string)$c['ultima_texto']), 0, 50);
    echo "  conv#{$c['id']} {$c['telefone']} {$c['nome_contato']} | últ {$c['ultima_em']} ({$c['ultimo_sentido']}) | {$prev}\n";
}
