<?php
/**
 * Diag: prazos vencidos nao concluidos + notificacoes
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function h($s) { echo "\n========== $s ==========\n"; }

h('1) TODOS OS PRAZOS NAO CONCLUIDOS');
$st = $pdo->query("
    SELECT p.id, p.case_id, p.descricao_acao, p.prazo_fatal, p.concluido,
           DATEDIFF(p.prazo_fatal, CURDATE()) AS dias,
           cl.name AS cliente, cs.title AS case_title
    FROM prazos_processuais p
    LEFT JOIN clients cl ON cl.id = p.client_id
    LEFT JOIN cases cs ON cs.id = p.case_id
    WHERE p.concluido = 0
    ORDER BY p.prazo_fatal ASC
");
$prazos = $st->fetchAll();
echo "Total: " . count($prazos) . " prazos nao concluidos\n\n";

$vencidos = 0;
foreach ($prazos as $p) {
    $marca = '   ';
    if ((int)$p['dias'] < 0) { $marca = '🚨 '; $vencidos++; }
    elseif ((int)$p['dias'] === 0) $marca = '⏰ ';
    elseif ((int)$p['dias'] <= 3) $marca = '⚠️ ';
    printf("%s#%-4d %s dias=%+d (fatal: %s) — %s | cliente: %s\n",
        $marca, $p['id'], date('d/m/Y', strtotime($p['prazo_fatal'])),
        (int)$p['dias'], $p['prazo_fatal'],
        mb_substr($p['descricao_acao'], 0, 35),
        mb_substr($p['cliente'] ?? '-', 0, 25));
}
echo "\nVENCIDOS: $vencidos\n";

h('2) HOJE NO SERVIDOR');
echo "  CURDATE() do banco: " . $pdo->query("SELECT CURDATE()")->fetchColumn() . "\n";
echo "  NOW() do banco: " . $pdo->query("SELECT NOW()")->fetchColumn() . "\n";
echo "  Hoje PHP: " . date('Y-m-d H:i:s') . "\n";

h('3) QUERY DO DASHBOARD (atualizada — prazo_fatal <= +7d)');
$st = $pdo->prepare("SELECT COUNT(*) FROM prazos_processuais WHERE concluido = 0 AND prazo_fatal <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)");
$st->execute();
echo "  Dashboard contara: " . (int)$st->fetchColumn() . "\n";

$st = $pdo->prepare("SELECT p.id, p.descricao_acao, p.prazo_fatal, DATEDIFF(p.prazo_fatal, CURDATE()) as dias FROM prazos_processuais p WHERE p.concluido = 0 AND p.prazo_fatal <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) ORDER BY p.prazo_fatal ASC LIMIT 10");
$st->execute();
foreach ($st->fetchAll() as $p) {
    printf("     #%-4d dias=%+d %s\n", $p['id'], (int)$p['dias'], mb_substr($p['descricao_acao'], 0, 50));
}

h('4) NOTIFICACOES DA AMANDA (user_id=1) NAO LIDAS');
try {
    $st = $pdo->prepare("
        SELECT id, tipo, titulo, mensagem, lida, criado_em
        FROM notifications
        WHERE user_id = 1
        ORDER BY id DESC LIMIT 15
    ");
    $st->execute();
    foreach ($st->fetchAll() as $n) {
        printf("  #%-5d [%s] %s %s | lida=%s | %s\n",
            $n['id'], $n['tipo'], $n['criado_em'],
            mb_substr($n['titulo'], 0, 40),
            (int)$n['lida'] === 1 ? 'sim' : '** NAO **',
            mb_substr($n['mensagem'] ?? '', 0, 50));
    }
} catch (Exception $e) {
    echo "Erro: " . $e->getMessage() . "\n";
}

h('5) ULTIMA EXECUCAO DO CRON agenda_lembretes');
echo "  (Como saber? Verificar /files/agenda_lembretes_lock ou log do cPanel)\n";
try {
    $arq = __DIR__ . '/files/agenda_lembretes_lock';
    if (file_exists($arq)) {
        echo "  Lock existe, mtime: " . date('Y-m-d H:i:s', filemtime($arq)) . "\n";
    } else {
        echo "  Lock nao existe.\n";
    }
} catch (Exception $e) {}

h('6) PRAZOS QUE DEVERIAM TER NOTIFICACAO MAS NAO TEM (vencidos sem alertado_vencido_em hoje)');
try {
    $st = $pdo->query("
        SELECT id, descricao_acao, prazo_fatal,
               DATEDIFF(prazo_fatal, CURDATE()) AS dias,
               alertado_vencido_em
        FROM prazos_processuais
        WHERE concluido = 0
          AND prazo_fatal < CURDATE()
          AND (alertado_vencido_em IS NULL OR DATE(alertado_vencido_em) < CURDATE())
        ORDER BY prazo_fatal ASC
    ");
    foreach ($st->fetchAll() as $p) {
        printf("  #%-4d dias=%+d %s (alertado_vencido_em: %s)\n",
            $p['id'], (int)$p['dias'],
            mb_substr($p['descricao_acao'], 0, 40),
            $p['alertado_vencido_em'] ?? 'nunca');
    }
    echo "\n  ^^ Esses precisam que o cron rode pra criar a notify.\n";
} catch (Exception $e) {
    echo "Erro (coluna nao existe?): " . $e->getMessage() . "\n";
}

echo "\nFIM.\n";
