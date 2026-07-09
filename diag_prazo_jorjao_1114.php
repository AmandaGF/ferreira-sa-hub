<?php
/**
 * DIAG 09/07/2026 — descobrir qual prazo o Jorjao celebrou as 11:14
 * hoje. Processo comecava com 0801602.
 * Rodar: /conecta/diag_prazo_jorjao_1114.php?key=fsa-hub-deploy-2026
 */
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';

if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }

header('Content-Type: text/plain; charset=utf-8');
error_reporting(E_ALL); ini_set('display_errors', '1');
$pdo = db();

echo "=== DIAG prazo Jorjao 09/07 as 11:14 ===\n\n";

// 1) Prazos processuais concluidos hoje entre 10:30 e 11:30
echo "-- prazos_processuais concluidos hoje ~11h --\n";
try {
    $st = $pdo->query(
        "SELECT p.id, p.case_id, p.numero_processo, p.descricao_acao,
                p.prazo_fatal, p.concluido, p.concluido_em, p.usuario_id,
                c.title AS case_title, c.case_number,
                cl.name AS client_name,
                u.name AS usuario_nome
         FROM prazos_processuais p
         LEFT JOIN cases c   ON c.id  = p.case_id
         LEFT JOIN clients cl ON cl.id = p.client_id
         LEFT JOIN users u   ON u.id  = p.usuario_id
         WHERE p.concluido = 1
           AND DATE(p.concluido_em) = CURDATE()
           AND HOUR(p.concluido_em) BETWEEN 10 AND 12
         ORDER BY p.concluido_em"
    );
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) echo "  (vazio)\n";
    foreach ($rows as $r) {
        echo "  #{$r['id']} concluido_em={$r['concluido_em']}\n";
        echo "     descricao: {$r['descricao_acao']}\n";
        echo "     processo: " . ($r['numero_processo'] ?: '-') . "  case_id: " . ($r['case_id'] ?: '-') . "\n";
        echo "     case_title: " . ($r['case_title'] ?: '-') . "  case_number: " . ($r['case_number'] ?: '-') . "\n";
        echo "     cliente: " . ($r['client_name'] ?: '-') . "\n";
        echo "     usuario_id={$r['usuario_id']} ({$r['usuario_nome']})\n\n";
    }
} catch (Throwable $e) { echo "  ERRO: " . $e->getMessage() . "\n"; }

// 2) Tarefas do tipo 'prazo' concluidas hoje ~11h
echo "\n-- case_tasks tipo=prazo concluidas hoje ~11h --\n";
try {
    $st = $pdo->query(
        "SELECT ct.id, ct.case_id, ct.title, ct.completed_at, ct.assigned_to,
                c.title AS case_title, c.case_number,
                cl.name AS client_name,
                u.name AS assigned_nome
         FROM case_tasks ct
         LEFT JOIN cases c   ON c.id  = ct.case_id
         LEFT JOIN clients cl ON cl.id = c.client_id
         LEFT JOIN users u   ON u.id  = ct.assigned_to
         WHERE ct.tipo = 'prazo'
           AND ct.status = 'concluido'
           AND DATE(ct.completed_at) = CURDATE()
           AND HOUR(ct.completed_at) BETWEEN 10 AND 12
         ORDER BY ct.completed_at"
    );
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) echo "  (vazio)\n";
    foreach ($rows as $r) {
        echo "  #{$r['id']} completed_at={$r['completed_at']}\n";
        echo "     titulo: {$r['title']}\n";
        echo "     case_id: " . ($r['case_id'] ?: '-') . "  case_title: " . ($r['case_title'] ?: '-') . "\n";
        echo "     case_number: " . ($r['case_number'] ?: '-') . "\n";
        echo "     cliente: " . ($r['client_name'] ?: '-') . "\n";
        echo "     assigned_to={$r['assigned_to']} ({$r['assigned_nome']})\n\n";
    }
} catch (Throwable $e) { echo "  ERRO: " . $e->getMessage() . "\n"; }

// 3) Busca por processo comecando com 0801602 (do print do Jorjao)
echo "\n-- prazos com processo comecando '0801602' --\n";
try {
    $st = $pdo->prepare(
        "SELECT p.id, p.case_id, p.numero_processo, p.descricao_acao, p.concluido_em,
                c.title AS case_title
         FROM prazos_processuais p
         LEFT JOIN cases c ON c.id = p.case_id
         WHERE p.numero_processo LIKE ?
            OR REPLACE(REPLACE(REPLACE(p.numero_processo,'-',''),'.',''),'/','') LIKE ?
         ORDER BY p.concluido_em DESC LIMIT 5"
    );
    $st->execute(array('0801602%', '0801602%'));
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) echo "  (nao achei prazo com esse numero — Jorjao pode ter pego de outra origem)\n";
    foreach ($rows as $r) {
        echo "  #{$r['id']} processo={$r['numero_processo']}\n";
        echo "     case={$r['case_id']} ({$r['case_title']})\n";
        echo "     descricao: {$r['descricao_acao']}\n";
        echo "     concluido_em: " . ($r['concluido_em'] ?: '-') . "\n\n";
    }
} catch (Throwable $e) { echo "  ERRO: " . $e->getMessage() . "\n"; }

// 4) Cases com case_number comecando 0801602
echo "\n-- cases com case_number comecando '0801602' --\n";
try {
    $st = $pdo->prepare(
        "SELECT id, title, case_number, status, client_id FROM cases
         WHERE case_number LIKE ?
            OR REPLACE(REPLACE(REPLACE(case_number,'-',''),'.',''),'/','') LIKE ?
         ORDER BY id DESC LIMIT 5"
    );
    $st->execute(array('0801602%', '0801602%'));
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) echo "  (nenhum)\n";
    foreach ($rows as $r) {
        echo "  case #{$r['id']} num={$r['case_number']} status={$r['status']}\n";
        echo "     titulo: {$r['title']}\n\n";
    }
} catch (Throwable $e) { echo "  ERRO: " . $e->getMessage() . "\n"; }

// 5) Audit log de jorjao entre 11:00 e 11:30
echo "\n-- audit_log de jorjao ~11h --\n";
try {
    $st = $pdo->query(
        "SELECT * FROM audit_log
         WHERE created_at >= CONCAT(CURDATE(), ' 10:30:00')
           AND created_at <= CONCAT(CURDATE(), ' 12:00:00')
           AND (acao LIKE '%jorjao%' OR entidade LIKE '%jorjao%' OR acao LIKE '%prazo%')
         ORDER BY id DESC LIMIT 10"
    );
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        echo "  #{$r['id']} " . ($r['created_at']??'-') . " user=" . ($r['user_id']??'-')
           . " acao=" . ($r['acao']??'-') . " entidade=" . ($r['entidade']??'-')
           . " id=" . ($r['entidade_id']??'-') . "  det=" . mb_substr((string)($r['detalhes']??''), 0, 100) . "\n";
    }
} catch (Throwable $e) { echo "  ERRO: " . $e->getMessage() . "\n"; }

echo "\n=== FIM ===\n";
