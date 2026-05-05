<?php
/**
 * Move os 6 lembretes da Carina (#4) abertos do 04/05 → 05/05 (hoje).
 * Idempotente: só mexe nos que estão atrasados E ainda em aberto E não arquivados.
 */
require_once __DIR__ . '/core/middleware.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Antes ===\n";
$st = $pdo->query("SELECT id, data_evento, titulo, concluido, IFNULL(arquivado,0) AS arq
                   FROM eventos_dia
                   WHERE tipo = 'lembrete' AND usuario_id = 4
                     AND data_evento < CURDATE()
                     AND concluido = 0 AND IFNULL(arquivado, 0) = 0");
$rows = $st->fetchAll();
echo "  pendentes atrasados: " . count($rows) . "\n";
foreach ($rows as $r) echo "    #{$r['id']} {$r['data_evento']} '{$r['titulo']}'\n";

if (!empty($rows)) {
    $pdo->prepare("UPDATE eventos_dia SET data_evento = CURDATE()
                   WHERE tipo = 'lembrete' AND usuario_id = 4
                     AND data_evento < CURDATE()
                     AND concluido = 0 AND IFNULL(arquivado, 0) = 0")
        ->execute();
    echo "\n  → " . count($rows) . " movidos pra hoje (CURDATE)\n";
}

echo "\n=== Depois ===\n";
$st2 = $pdo->query("SELECT id, data_evento, titulo FROM eventos_dia
                    WHERE tipo = 'lembrete' AND usuario_id = 4 AND data_evento = CURDATE()
                      AND IFNULL(arquivado, 0) = 0");
foreach ($st2->fetchAll() as $r) echo "  #{$r['id']} {$r['data_evento']} '{$r['titulo']}'\n";
