<?php
/**
 * Endpoint temporário: exporta conteúdo dos 23 módulos como JSON
 * (usado uma vez pra popular o vault Obsidian). Key-protected.
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit; }
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/core/database.php';
$pdo = db();

$modulos = $pdo->query("SELECT * FROM treinamento_modulos WHERE ativo = 1 ORDER BY ordem")->fetchAll();
$conteudos = require __DIR__ . '/modules/treinamento/conteudo.php';

$out = array();
foreach ($modulos as $m) {
    $slug = $m['slug'];
    $cont = $conteudos[$slug] ?? array();
    $out[] = array(
        'slug' => $slug,
        'titulo' => $m['titulo'],
        'descricao' => $m['descricao'],
        'icone' => $m['icone'],
        'perfis_alvo' => json_decode($m['perfis_alvo'], true) ?: array(),
        'ordem' => (int)$m['ordem'],
        'pontos' => (int)$m['pontos'],
        'por_que' => $cont['por_que'] ?? '',
        'passos' => $cont['passos'] ?? array(),
        'atencao' => $cont['atencao'] ?? null,
        'dica' => $cont['dica'] ?? null,
        'missao' => $cont['missao'] ?? '',
    );
}
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
