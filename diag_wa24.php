<?php
if (($_GET['key']??'') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('no'); }
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Diag canal 24 — " . date('d/m/Y H:i:s') . " ===\n\n";

// 0) Schema real das tabelas zapi
echo "--- Schema zapi_mensagens ---\n";
try {
    $cols = $pdo->query("DESCRIBE zapi_mensagens")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) echo "  {$c['Field']} ({$c['Type']})\n";
} catch (Exception $e) { echo "  ERRO: " . $e->getMessage() . "\n"; }

echo "\n--- Schema zapi_conversas ---\n";
try {
    $cols = $pdo->query("DESCRIBE zapi_conversas")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) echo "  {$c['Field']} ({$c['Type']})\n";
} catch (Exception $e) { echo "  ERRO: " . $e->getMessage() . "\n"; }

echo "\n--- Total de msgs por canal (geral) ---\n";
try {
    $t = $pdo->query("SELECT canal, COUNT(*) AS qtd, MAX(created_at) AS ult FROM zapi_mensagens GROUP BY canal")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($t as $r) echo "  canal={$r['canal']} qtd={$r['qtd']} ult={$r['ult']}\n";
} catch (Exception $e) {
    // talvez a coluna seja outra
    echo "  (created_at não existe? tentando outras: ";
    try {
        $t = $pdo->query("SELECT canal, COUNT(*) AS qtd FROM zapi_mensagens GROUP BY canal")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($t as $r) echo "canal={$r['canal']} qtd={$r['qtd']} ";
    } catch (Exception $e2) { echo $e2->getMessage(); }
    echo ")\n";
}

echo "\n--- Total msgs canal 24 hoje ---\n";
try {
    $t = $pdo->query("SELECT COUNT(*) AS qtd, MAX(created_at) AS ult FROM zapi_mensagens WHERE canal='24' AND DATE(created_at)=CURDATE()")->fetch(PDO::FETCH_ASSOC);
    echo "  total={$t['qtd']} ult={$t['ult']}\n";
} catch (Exception $e) { echo "  ERRO: " . $e->getMessage() . "\n"; }

echo "\n--- Total msgs canal 24 ontem (29/06) ---\n";
try {
    $t = $pdo->query("SELECT COUNT(*) AS qtd, MAX(created_at) AS ult FROM zapi_mensagens WHERE canal='24' AND DATE(created_at)='2026-06-29'")->fetch(PDO::FETCH_ASSOC);
    echo "  total={$t['qtd']} ult={$t['ult']}\n";
} catch (Exception $e) { echo "  ERRO: " . $e->getMessage() . "\n"; }

echo "\n--- Instâncias zapi ---\n";
try {
    $cols2 = $pdo->query("DESCRIBE zapi_instancias")->fetchAll(PDO::FETCH_COLUMN);
    echo "  Colunas: " . implode(', ', $cols2) . "\n";
    $i = $pdo->query("SELECT * FROM zapi_instancias")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($i as $r) {
        $r['token'] = isset($r['token']) ? substr((string)$r['token'], 0, 10) . '...' : '?';
        print_r($r);
    }
} catch (Exception $e) { echo "  ERRO: " . $e->getMessage() . "\n"; }

echo "\n--- Últimas conversas canal=24 (qualquer schema) ---\n";
try {
    $cols3 = $pdo->query("SHOW COLUMNS FROM zapi_conversas")->fetchAll(PDO::FETCH_COLUMN);
    // tenta achar coluna de "última atualização"
    $orderCol = 'id';
    foreach (array('atualizado_em','updated_at','ultima_mensagem_em','ultima_em','last_message_at') as $c) {
        if (in_array($c, $cols3)) { $orderCol = $c; break; }
    }
    echo "  Ordenando por: $orderCol\n";
    $cvs = $pdo->query("SELECT * FROM zapi_conversas WHERE canal='24' ORDER BY $orderCol DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cvs as $c) {
        $keys = array('id', 'telefone', 'nome_contato', $orderCol);
        $line = '';
        foreach ($keys as $k) if (isset($c[$k])) $line .= "$k={$c[$k]} ";
        echo "  $line\n";
    }
} catch (Exception $e) { echo "  ERRO: " . $e->getMessage() . "\n"; }

echo "\n--- Tail webhook log (se existe) ---\n";
foreach (array('/files/zapi_webhook.log','/files/wa_inbound.log','/files/zapi_in.log','/files/zapi.log') as $logf) {
    $f = __DIR__ . $logf;
    if (is_file($f)) {
        echo "  $logf (size " . filesize($f) . " bytes, modificado " . date('d/m H:i', filemtime($f)) . "):\n";
        $lines = @file($f);
        if ($lines) {
            $tail = array_slice($lines, -10);
            foreach ($tail as $l) echo '    ' . rtrim($l) . "\n";
        }
        break;
    }
}
