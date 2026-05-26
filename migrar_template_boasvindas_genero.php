<?php
/**
 * Migração: corrige 'bem-vindo(a)' no template de boas-vindas.
 * Idempotente. Roda direto, sem dry-run (template específico já identificado).
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') die('Acesso negado.');
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

$subs = array(
    '/bem-vindo\(a\)/u'  => 'bem-{{vindo|vinda}}',
    '/Bem-vindo\(a\)/u'  => 'Bem-{{vindo|vinda}}',
    '/benvindo\(a\)/u'   => 'ben{{vindo|vinda}}',
);

$rows = $pdo->query("SELECT id, nome, categoria, conteudo FROM zapi_templates WHERE ativo = 1")->fetchAll();
$tocados = 0;

foreach ($rows as $r) {
    $orig = $r['conteudo'];
    $novo = $orig;
    foreach ($subs as $re => $rep) {
        $novo = preg_replace($re, $rep, $novo);
    }
    if ($novo !== $orig) {
        $pdo->prepare("UPDATE zapi_templates SET conteudo = ? WHERE id = ?")
            ->execute(array($novo, $r['id']));
        echo "[OK] #{$r['id']} [{$r['categoria']}] {$r['nome']}\n";
        echo "     ANTES:  " . substr($orig, 0, 200) . "...\n";
        echo "     DEPOIS: " . substr($novo, 0, 200) . "...\n\n";
        $tocados++;
    }
}
echo "\nTotal atualizados: $tocados\n";
