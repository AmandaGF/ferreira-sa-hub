<?php
/**
 * Tira o atendimento (atendente_id -> NULL) das conversas que estão com a Andressia.
 *  - Sem parâmetro: só INSPECIONA (lista a usuária + conversas por status/canal).
 *  - ?exec=1&uid=N : EXECUTA pra o user N (limpa atendente_id, solta delegação,
 *                    e volta status 'em_atendimento'/'transferido' -> 'aguardando').
 * Key obrigatória.
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions.php';
$pdo = db();

echo "=== Andressia — tirar atendimento ===\n\n";

// 1) achar a(s) usuária(s)
$us = $pdo->query("SELECT id, name, role, is_active FROM users WHERE name LIKE '%Andress%' ORDER BY id")->fetchAll();
if (!$us) { echo "Nenhum usuário com nome parecido com 'Andress'.\n"; exit; }
echo "Usuárias encontradas:\n";
foreach ($us as $u) {
    echo "  #{$u['id']} — {$u['name']} (role={$u['role']}, ativo={$u['is_active']})\n";
    $st = $pdo->prepare("SELECT canal, status, COUNT(*) q FROM zapi_conversas WHERE atendente_id = ? GROUP BY canal, status ORDER BY canal, status");
    $st->execute(array($u['id']));
    $rows = $st->fetchAll();
    $tot = 0;
    foreach ($rows as $r) { echo "       canal {$r['canal']} · {$r['status']}: {$r['q']}\n"; $tot += $r['q']; }
    echo "     TOTAL conversas atribuídas: {$tot}\n";
}

// 2) execução (só se ?exec=1&uid=N)
$exec = !empty($_GET['exec']);
$uid  = (int)($_GET['uid'] ?? 0);
if ($exec && $uid > 0) {
    echo "\n--- EXECUTANDO para user #{$uid} ---\n";
    // volta pra fila os que estavam ativos; não mexe em resolvido/arquivado
    $up1 = $pdo->prepare("UPDATE zapi_conversas
        SET atendente_id = NULL, delegada = 0, delegada_por = NULL, delegada_em = NULL,
            status = CASE WHEN status IN ('em_atendimento','transferido') THEN 'aguardando' ELSE status END
        WHERE atendente_id = ?");
    $up1->execute(array($uid));
    $n = $up1->rowCount();
    echo "Conversas atualizadas (atendente_id -> NULL): {$n}\n";
    if (function_exists('audit_log')) { try { audit_log('atendente_limpo_massa', 'user', $uid, 'conversas=' . $n); } catch (Exception $e) {} }
    echo "OK.\n";
} else {
    echo "\n(Para executar: adicione &exec=1&uid=ID na URL.)\n";
}
