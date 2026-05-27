<?php
/**
 * Diag: por que docs GED nao aparecem para clientes na Central VIP.
 * URL: /conecta/diag_salavip_ged.php?key=fsa-hub-deploy-2026
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
error_reporting(E_ALL); ini_set('display_errors', '1');
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function h($s) { echo "\n========== $s ==========\n"; }

h('1) ULTIMOS 20 DOCS GED ENVIADOS');
$st = $pdo->query("SELECT id, cliente_id, processo_id, titulo, categoria, visivel_cliente, compartilhado_em, compartilhado_por FROM salavip_ged ORDER BY id DESC LIMIT 20");
foreach ($st->fetchAll() as $r) {
    printf("  #%-4d cliente=%-5d processo=%-5s visivel=%d %s %s | %s\n",
        $r['id'], (int)$r['cliente_id'], $r['processo_id'] ?? 'NULL',
        (int)$r['visivel_cliente'],
        $r['compartilhado_em'], 'user#' . (int)$r['compartilhado_por'],
        mb_substr($r['titulo'], 0, 50));
}

h('2) DOCS COM visivel_cliente = 0 (escondidos do cliente)');
$qtd = (int)$pdo->query("SELECT COUNT(*) FROM salavip_ged WHERE visivel_cliente = 0")->fetchColumn();
echo "Qtd: $qtd\n";
if ($qtd > 0 && $qtd <= 50) {
    $st = $pdo->query("SELECT id, cliente_id, titulo, compartilhado_em FROM salavip_ged WHERE visivel_cliente = 0 ORDER BY id DESC LIMIT 50");
    foreach ($st->fetchAll() as $r) {
        printf("  #%-4d cliente=%-5d %s | %s\n", $r['id'], $r['cliente_id'], $r['compartilhado_em'], mb_substr($r['titulo'], 0, 50));
    }
}

h('3) USUARIOS DA CENTRAL VIP (salavip_usuarios)');
try {
    $cols = $pdo->query("SHOW COLUMNS FROM salavip_usuarios")->fetchAll(PDO::FETCH_COLUMN);
    echo "Colunas: " . implode(', ', $cols) . "\n";
    $st = $pdo->query("SELECT * FROM salavip_usuarios ORDER BY id DESC LIMIT 15");
    foreach ($st->fetchAll() as $r) {
        echo "  user#{$r['id']} | cliente_id={$r['cliente_id']} | email=" . ($r['email'] ?? '') . " | ativo=" . ($r['ativo'] ?? '?') . " | ultimo_acesso=" . ($r['ultimo_acesso_em'] ?? $r['ultimo_acesso'] ?? '-') . "\n";
    }
} catch (Exception $e) {
    echo "Erro: " . $e->getMessage() . "\n";
}

h('4) CRUZAR: ULTIMOS DOCS x USUARIOS DA CENTRAL VIP');
echo "Para cada doc GED enviado nos ultimos 7 dias, existe usuario VIP ativo do cliente?\n\n";
$docs = $pdo->query("SELECT id, cliente_id, titulo, compartilhado_em, visivel_cliente FROM salavip_ged WHERE compartilhado_em > DATE_SUB(NOW(), INTERVAL 7 DAY) ORDER BY id DESC LIMIT 30")->fetchAll();
foreach ($docs as $d) {
    $st = $pdo->prepare("SELECT id, email, ativo FROM salavip_usuarios WHERE cliente_id = ?");
    $st->execute([$d['cliente_id']]);
    $users = $st->fetchAll();
    $clienteName = $pdo->prepare("SELECT name FROM clients WHERE id = ?"); $clienteName->execute([$d['cliente_id']]);
    $cn = $clienteName->fetchColumn();
    echo "doc#{$d['id']} cliente#{$d['cliente_id']} ({$cn}) visivel={$d['visivel_cliente']} - " . mb_substr($d['titulo'],0,40) . "\n";
    if (empty($users)) {
        echo "    ⚠️  SEM usuario VIP cadastrado para este cliente — cliente NUNCA vai acessar!\n";
    } else {
        foreach ($users as $u) {
            $ativoStr = (int)$u['ativo'] === 1 ? '✓ ATIVO' : '✗ INATIVO';
            echo "    -> user#{$u['id']} {$u['email']} [{$ativoStr}]\n";
        }
    }
}

h('5) TOTAL DE DOCS POR STATUS');
$st = $pdo->query("SELECT visivel_cliente, COUNT(*) AS qtd FROM salavip_ged GROUP BY visivel_cliente");
foreach ($st->fetchAll() as $r) {
    echo "  visivel_cliente={$r['visivel_cliente']} : {$r['qtd']}\n";
}

h('6) DOCS POR CLIENTE (top 10)');
$st = $pdo->query("SELECT g.cliente_id, c.name, COUNT(*) AS qtd, SUM(g.visivel_cliente) AS visiveis FROM salavip_ged g LEFT JOIN clients c ON c.id=g.cliente_id GROUP BY g.cliente_id ORDER BY qtd DESC LIMIT 10");
foreach ($st->fetchAll() as $r) {
    printf("  cliente#%-5d %-30s docs=%d visiveis=%d\n", $r['cliente_id'], mb_substr($r['name'] ?? '?', 0, 30), $r['qtd'], $r['visiveis']);
}

h('FIM');
