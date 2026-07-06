<?php
/**
 * Cron do Jorjão: varre eventos que precisam tocar sino no grupo.
 *
 * Por enquanto só cuida da TOCADA 2 (petição distribuída):
 *   - Cases com jorjao_distribuicao_tocado=0 E (case_number preenchido OU
 *     stage='em_andamento') → toca sino + marca como tocado.
 *
 * Config cron cPanel (a cada 10 min):
 *   */10 * * * *  curl -s "https://ferreiraesa.com.br/conecta/cron/jorjao_tick.php?key=fsa-hub-deploy-2026"
 *
 * Idempotente. Se killswitch da tocada estiver OFF, cron ainda roda mas não
 * envia (mas TAMBÉM não marca como tocado, pra quando ligar, disparar).
 */

$isCli = php_sapi_name() === 'cli';
if (!$isCli && ($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') {
    http_response_code(403); die('Acesso negado.');
}
ini_set('display_errors', '1'); error_reporting(E_ALL);
register_shutdown_function(function() {
    $e = error_get_last();
    if ($e && in_array($e['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR))) {
        echo "\n[FATAL] {$e['message']} em {$e['file']}:{$e['line']}\n";
    }
});

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/functions_jorjao.php';

if (!$isCli) { header('Content-Type: text/plain; charset=utf-8'); }
$pdo = db();
$inicio = microtime(true);
echo "[" . date('Y-m-d H:i:s') . "] === jorjao_tick ===\n";

// Lock leve (evita 2 ticks paralelos derrubarem a Z-API)
$lockFile = dirname(__DIR__) . '/files/jorjao_tick.lock';
if (file_exists($lockFile)) {
    $age = time() - @filemtime($lockFile);
    if ($age < 180) { echo "OUTRO TICK EM EXECUCAO ({$age}s) — abortando\n"; exit; }
    @unlink($lockFile);
}
@file_put_contents($lockFile, date('Y-m-d H:i:s'));

$totScan = 0; $totTocado = 0; $totSkipKill = 0;

try {
    // TOCADA 2 — petição distribuída
    $ativa = jorjao_tocada_ativa('peticao_distribuida');
    echo "Tocada peticao_distribuida ativa: " . ($ativa ? 'SIM' : 'NAO (killswitch)') . "\n";

    // Se DESLIGADA: o cron ainda existe mas não faz nada — quando Amanda
    // ligar, o proximo tick pega tudo que acumulou. Isso pode gerar spam
    // repentino. Como salvaguarda: só considera cases dos ULTIMOS 30 DIAS.
    $stCandidatos = $pdo->query("
        SELECT cs.id, cs.title, cs.status, cs.case_number, cs.case_type,
               cs.client_id, cs.responsible_user_id, cs.created_at, cs.updated_at,
               c.name AS client_name
        FROM cases cs
        LEFT JOIN clients c ON c.id = cs.client_id
        WHERE cs.jorjao_distribuicao_tocado = 0
          AND ((cs.case_number IS NOT NULL AND cs.case_number <> '')
               OR cs.status = 'em_andamento')
          AND cs.updated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ORDER BY cs.updated_at DESC
        LIMIT 20
    ")->fetchAll(PDO::FETCH_ASSOC);

    $totScan = count($stCandidatos);
    echo "Candidatos escaneados: {$totScan}\n\n";

    foreach ($stCandidatos as $case) {
        $id = (int)$case['id'];

        if (!$ativa) {
            // Killswitch OFF: NÃO marca como tocado — deixa pra tocar quando ligar
            $totSkipKill++;
            continue;
        }

        $r = jorjao_peticao_distribuida($case);

        // Sempre marca como tocado depois de tentar (mesmo em falha) pra não
        // ficar tentando o mesmo case toda hora. Se der falha real, aparece
        // no log jorjao_log_peticao_distribuida pra diagnostico.
        $pdo->prepare("UPDATE cases SET jorjao_distribuicao_tocado = 1 WHERE id = ?")
            ->execute(array($id));

        if (!empty($r['ok'])) {
            echo "  [OK] case #{$id} ({$case['title']}) — tocou\n";
            $totTocado++;
        } else {
            echo "  [FALHA] case #{$id} — " . ($r['erro'] ?? '?') . "\n";
        }

        usleep(400000); // 0.4s entre envios pra não estourar Z-API
    }

    $dur = round(microtime(true) - $inicio, 1);
    echo "\n=== CONCLUIDO ({$dur}s) === Scan: {$totScan} · Tocados: {$totTocado} · Skip killswitch: {$totSkipKill}\n";
} catch (Exception $e) {
    echo "ERRO FATAL: " . $e->getMessage() . "\n";
} finally {
    @unlink($lockFile);
}
