<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors', '1');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== MINUTAS DA MINERVA (14/06) + PECAS DA FABRICA ===\n\n";

echo "--- Casos com rastro da Minerva ---\n";
$q = $pdo->query("SELECT DISTINCT case_id FROM case_andamentos
                  WHERE descricao LIKE '%Minerva%' OR descricao LIKE '%elaborada por IA%'");
$ids = $q->fetchAll(PDO::FETCH_COLUMN);
foreach ($ids as $id) {
    $s = $pdo->prepare("SELECT id, title, case_type, status, drive_folder_url FROM cases WHERE id = ?");
    $s->execute([$id]);
    $c = $s->fetch();
    if (!$c) { continue; }
    echo "\nCase #{$c['id']} — {$c['title']}\n";
    echo "  tipo: {$c['case_type']} | status atual: {$c['status']}\n";
    echo "  DRIVE: {$c['drive_folder_url']}\n";
    $a = $pdo->prepare("SELECT data_andamento, descricao FROM case_andamentos WHERE case_id = ? ORDER BY id DESC LIMIT 3");
    $a->execute([$id]);
    foreach ($a->fetchAll() as $r) { echo "    [{$r['data_andamento']}] " . mb_substr($r['descricao'], 0, 130) . "\n"; }
}

echo "\n\n--- Pecas da Fabrica (case_documents) — iniciais de familia ---\n";
$rows = $pdo->query("SELECT cd.id, cd.case_id, cd.tipo_peca, cd.tipo_acao, cd.created_at,
                     cd.tokens_output, LENGTH(cd.conteudo_html) AS tam_html, c.title, c.drive_folder_url
                     FROM case_documents cd LEFT JOIN cases c ON c.id = cd.case_id
                     WHERE cd.tipo_peca = 'peticao_inicial'
                     ORDER BY cd.created_at DESC LIMIT 8")->fetchAll();
foreach ($rows as $r) {
    echo "\ndoc#{$r['id']} — case#{$r['case_id']} — {$r['title']}\n";
    echo "  tipo_acao: {$r['tipo_acao']} | {$r['created_at']} | tokens_out: {$r['tokens_output']} | html: {$r['tam_html']} chars\n";
    echo "  DRIVE: {$r['drive_folder_url']}\n";
}

echo "\n\n--- AMOSTRA: conteudo da ultima inicial de alimentos gerada pela Fabrica ---\n";
$r = $pdo->query("SELECT id, case_id, tipo_acao, conteudo_html FROM case_documents
                  WHERE tipo_peca = 'peticao_inicial' AND tipo_acao LIKE '%aliment%'
                  ORDER BY created_at DESC LIMIT 1")->fetch();
if ($r) {
    echo "doc#{$r['id']} case#{$r['case_id']} ({$r['tipo_acao']})\n";
    echo "----- INICIO (primeiros 6000 chars, tags removidas) -----\n";
    $txt = html_entity_decode(strip_tags(preg_replace('/<(br|\/p|\/h[1-6]|\/li|\/tr)>/i', "\n", $r['conteudo_html'])), ENT_QUOTES, 'UTF-8');
    $txt = preg_replace("/\n{3,}/", "\n\n", $txt);
    echo mb_substr($txt, 0, 6000) . "\n";
    echo "----- FIM DA AMOSTRA -----\n";
} else {
    echo "  (nenhuma inicial de alimentos encontrada)\n";
}

echo "\n=== FIM ===\n";
