<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave invalida'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Templates de notificação ao cliente ===\n\n";

$rows = $pdo->query("SELECT id, tipo, titulo, mensagem_whatsapp FROM notificacao_config ORDER BY id")->fetchAll();
foreach ($rows as $r) {
    echo "#{$r['id']} [{$r['tipo']}] {$r['titulo']}\n";
    echo "  WA: " . str_replace("\n", " | ", $r['mensagem_whatsapp']) . "\n\n";
}

echo "=== Corrigindo acentuação ===\n\n";

$fixes = array(
    // Palavras sem acento → com acento
    'Ola,' => 'Olá,',
    'Ola ' => 'Olá ',
    'voce' => 'você',
    'escritorio' => 'escritório',
    'advogado(s) que esta' => 'advogado(s) que esta',
    'estamos a disposicao' => 'estamos à disposição',
    'a disposicao' => 'à disposição',
    'Ferreira e Sa' => 'Ferreira e Sá',
    'numero do processo' => 'número do processo',
    'distribuicao' => 'distribuição',
    'distribuido' => 'distribuído',
    'Numero' => 'Número',
    'acao' => 'ação',
    'duvida' => 'dúvida',
    'documentacao' => 'documentação',
    'informacao' => 'informação',
    'peticionamento' => 'peticionamento',
    'obrigacao' => 'obrigação',
    'providencia' => 'providência',
    'notificacao' => 'notificação',
    'tramitacao' => 'tramitação',
    'conclusao' => 'conclusão',
);

$updated = 0;
foreach ($rows as $r) {
    $msg = $r['mensagem_whatsapp'];
    $original = $msg;
    foreach ($fixes as $sem => $com) {
        $msg = str_replace($sem, $com, $msg);
    }
    if ($msg !== $original) {
        $pdo->prepare("UPDATE notificacao_config SET mensagem_whatsapp = ? WHERE id = ?")->execute(array($msg, $r['id']));
        echo "CORRIGIDO #{$r['id']} [{$r['tipo']}]\n";
        $updated++;
    }
}

echo "\n$updated template(s) corrigido(s).\n";

echo "\n=== Templates após correção ===\n\n";
$rows2 = $pdo->query("SELECT id, tipo, titulo, mensagem_whatsapp FROM notificacao_config ORDER BY id")->fetchAll();
foreach ($rows2 as $r) {
    echo "#{$r['id']} [{$r['tipo']}] {$r['titulo']}\n";
    echo "  WA: " . str_replace("\n", " | ", $r['mensagem_whatsapp']) . "\n\n";
}

echo "=== FEITO ===\n";
