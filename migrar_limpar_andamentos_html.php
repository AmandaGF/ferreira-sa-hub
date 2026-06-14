<?php
/**
 * Limpa HTML cru gravado em case_andamentos.descricao (vindos do DJen antes
 * do fix em functions_djen.php). Decodifica entidades e remove tags.
 *
 * SEGURO: o conteúdo cru original continua preservado em case_publicacoes.conteudo.
 * Dry-run por padrão. Para aplicar de fato: &apply=1
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
set_time_limit(300);

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_utils.php';
$pdo = db();

$apply = (($_GET['apply'] ?? '') === '1');
echo "=== Limpeza de HTML em case_andamentos ===\n";
echo $apply ? "MODO: APLICAR (vai gravar)\n\n" : "MODO: DRY-RUN (nada será gravado — use &apply=1 pra valer)\n\n";

// Pega andamentos com cara de HTML (tag ou entidade nomeada)
$rows = $pdo->query("SELECT id, descricao FROM case_andamentos
    WHERE descricao REGEXP '<[a-zA-Z/]|&[a-zA-Z]+;'")->fetchAll();

echo "Andamentos candidatos: " . count($rows) . "\n\n";

$up = $pdo->prepare("UPDATE case_andamentos SET descricao = ? WHERE id = ?");
$alterados = 0; $iguais = 0; $amostra = 0;
foreach ($rows as $r) {
    $limpo = limpar_html_juridico($r['descricao']);
    if ($limpo === $r['descricao']) { $iguais++; continue; }
    $alterados++;
    if ($amostra < 3) {
        echo "--- #{$r['id']} ---\n";
        echo "ANTES (300): " . substr($r['descricao'], 0, 300) . "...\n";
        echo "DEPOIS(300): " . substr($limpo, 0, 300) . "...\n\n";
        $amostra++;
    }
    if ($apply) $up->execute(array($limpo, $r['id']));
}

echo str_repeat('=', 50) . "\n";
echo "Sem mudança (já limpos / falso-positivo): $iguais\n";
echo "Alterados: $alterados " . ($apply ? "(GRAVADOS)" : "(seriam gravados)") . "\n";
echo "\n" . ($apply ? "Pronto! Aplicado." : "Dry-run concluído. Rode com &apply=1 pra gravar.") . "\n";
