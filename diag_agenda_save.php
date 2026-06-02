<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
error_reporting(E_ALL); ini_set('display_errors','1');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();
header('Content-Type: text/plain; charset=utf-8');

echo "== 1) Coluna referencia_evento_id existe em agenda_eventos? ==\n";
$cols = $pdo->query("SHOW COLUMNS FROM agenda_eventos LIKE 'referencia_evento_id'")->fetchAll(PDO::FETCH_ASSOC);
if ($cols) {
    foreach ($cols as $c) echo "  EXISTE: " . json_encode($c) . "\n";
} else {
    echo "  NAO EXISTE - vou tentar criar\n";
    try { $pdo->exec("ALTER TABLE agenda_eventos ADD COLUMN referencia_evento_id INT NULL"); echo "  CRIADA com sucesso\n"; }
    catch (Exception $e) { echo "  ERRO no ALTER: " . $e->getMessage() . "\n"; }
}

echo "\n== 2) Audiencia mais recente criada ==\n";
$st = $pdo->query("SELECT id, titulo, tipo, modalidade, data_inicio, status, created_at FROM agenda_eventos WHERE tipo='audiencia' ORDER BY id DESC LIMIT 3");
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) echo "  " . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";

echo "\n== 3) Lembretes vinculados a audiencias (reuniao_interna com referencia) ==\n";
try {
    $st = $pdo->query("SELECT id, titulo, data_inicio, status, referencia_evento_id, created_at FROM agenda_eventos WHERE tipo='reuniao_interna' AND referencia_evento_id IS NOT NULL ORDER BY id DESC LIMIT 5");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) echo "  " . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
} catch (Exception $e) { echo "  ERRO: " . $e->getMessage() . "\n"; }

echo "\n== 4) Eventos do tipo reuniao_interna criados nas ultimas 2h (talvez orfaos) ==\n";
$st = $pdo->query("SELECT id, titulo, data_inicio, status, referencia_evento_id, created_at FROM agenda_eventos WHERE tipo='reuniao_interna' AND created_at > DATE_SUB(NOW(), INTERVAL 2 HOUR) ORDER BY id DESC LIMIT 10");
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) echo "  " . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";

echo "\n== 5) Ver error_log (ultimas linhas) ==\n";
$logPath = ini_get('error_log');
echo "  log_path: " . ($logPath ?: '(default)') . "\n";
if ($logPath && file_exists($logPath)) {
    $linhas = @file($logPath);
    if ($linhas) {
        $ult = array_slice($linhas, -30);
        foreach ($ult as $l) {
            $l = trim($l);
            if ($l !== '' && (stripos($l, 'agenda') !== false || stripos($l, 'audiencia') !== false || stripos($l, 'lembrete') !== false || stripos($l, 'eventos') !== false)) {
                echo "  " . substr($l, 0, 300) . "\n";
            }
        }
    }
}
