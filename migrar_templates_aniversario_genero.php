<?php
/**
 * Migração: substitui '(a)' / '(o)' / 'tê-lo(a)' / 'Prezado(a)' / 'cercado(a)'
 * nos templates de aniversário pela sintaxe {{masc|fem}} suportada pela função
 * zapi_get_template (commit anterior).
 *
 * Idempotente. Dry-run default. Aplicar: &aplicar=1
 *
 * ferreiraesa.com.br/conecta/migrar_templates_aniversario_genero.php?key=fsa-hub-deploy-2026
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') die('Acesso negado.');
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();
$aplicar = ($_GET['aplicar'] ?? '0') === '1';

echo "=== Migração templates de aniversário: gênero ===\n";
echo $aplicar ? "MODO: APLICAR\n\n" : "MODO: DRY-RUN (use &aplicar=1 pra gravar)\n\n";

// Lista de substituições (regex case-insensitive)
$subs = array(
    // Vocativos
    '/Prezado\(a\)/u'      => '{{Prezado|Prezada}}',
    '/prezado\(a\)/u'      => '{{prezado|prezada}}',
    '/Querido\(a\)/u'      => '{{Querido|Querida}}',
    '/querido\(a\)/u'      => '{{querido|querida}}',
    '/Caro\(a\)/u'         => '{{Caro|Cara}}',
    '/caro\(a\)/u'         => '{{caro|cara}}',
    '/Estimado\(a\)/u'     => '{{Estimado|Estimada}}',
    '/estimado\(a\)/u'     => '{{estimado|estimada}}',
    // Pronome enclítico
    '/tê-lo\(a\)/u'        => 'tê-{{lo|la}}',
    '/te-lo\(a\)/u'        => 'te-{{lo|la}}',
    '/vê-lo\(a\)/u'        => 'vê-{{lo|la}}',
    '/recebê-lo\(a\)/u'    => 'recebê-{{lo|la}}',
    '/atendê-lo\(a\)/u'    => 'atendê-{{lo|la}}',
    // Particípios e adjetivos comuns em -ado(a) / -ido(a)
    '/cercado\(a\)/u'      => 'cercad{{o|a}}',
    '/abraçado\(a\)/u'     => 'abraçad{{o|a}}',
    '/cuidado\(a\)/u'      => 'cuidad{{o|a}}',
    '/preparado\(a\)/u'    => 'preparad{{o|a}}',
    '/contemplado\(a\)/u'  => 'contemplad{{o|a}}',
    '/iluminado\(a\)/u'    => 'iluminad{{o|a}}',
    '/feliz\(es\)/u'       => 'feliz',
    '/parabenizado\(a\)/u' => 'parabenizad{{o|a}}',
);

$rows = $pdo->query("SELECT id, nome, conteudo FROM zapi_templates WHERE categoria = 'aniversario'")->fetchAll();

foreach ($rows as $r) {
    echo "─── #{$r['id']} · {$r['nome']} ───\n";
    $original = $r['conteudo'];
    $novo = $original;
    $aplicacoes = array();

    foreach ($subs as $regex => $rep) {
        $count = 0;
        $novo = preg_replace($regex, $rep, $novo, -1, $count);
        if ($count > 0) {
            $aplicacoes[] = $regex . ' → ' . $rep . ' (' . $count . 'x)';
        }
    }

    if ($novo === $original) {
        echo "  Nenhuma mudança necessária.\n\n";
        continue;
    }

    echo "  Substituições:\n";
    foreach ($aplicacoes as $a) echo "    • " . $a . "\n";
    echo "\n  ANTES:\n" . preg_replace('/^/m', '    | ', $original) . "\n";
    echo "  DEPOIS:\n" . preg_replace('/^/m', '    | ', $novo) . "\n\n";

    if ($aplicar) {
        $pdo->prepare("UPDATE zapi_templates SET conteudo = ? WHERE id = ?")
            ->execute(array($novo, $r['id']));
        echo "  [OK] Gravado.\n\n";
    }
}

echo "\n[FIM]\n";
echo $aplicar ? "Aplicado.\n" : "Rodou em DRY-RUN. &aplicar=1 pra valer.\n";
