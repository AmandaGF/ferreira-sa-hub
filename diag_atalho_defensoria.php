<?php
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
header('Content-Type: text/plain; charset=utf-8');
error_reporting(E_ALL); ini_set('display_errors','1');
$pdo = db();

echo "-- Atalho /defensoria --\n";
$st = $pdo->prepare("SELECT * FROM zapi_templates WHERE atalho = ? LIMIT 3");
$st->execute(array('defensoria'));
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
if (!$rows) {
    echo "Nao achei atalho literal 'defensoria'. Buscando similares...\n\n";
    $st = $pdo->query("SELECT id, nome, atalho, categoria, canal, ativo, LEFT(conteudo, 400) AS preview
                        FROM zapi_templates
                        WHERE atalho LIKE '%defen%' OR nome LIKE '%efensoria%' OR conteudo LIKE '%efensoria%'
                        LIMIT 10");
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
}
foreach ($rows as $r) {
    echo str_repeat('=', 78) . "\n";
    echo "id     : {$r['id']}\n";
    echo "nome   : {$r['nome']}\n";
    echo "atalho : /" . ($r['atalho'] ?? '(sem atalho)') . "\n";
    echo "canal  : {$r['canal']}  categoria: " . ($r['categoria'] ?? '-') . "  ativo: " . ($r['ativo'] ?? '-') . "\n";
    if (isset($r['conteudo'])) {
        echo "\nCONTEUDO COMPLETO:\n" . str_repeat('-', 40) . "\n";
        echo $r['conteudo'] . "\n" . str_repeat('-', 40) . "\n";
    } elseif (isset($r['preview'])) {
        echo "PREVIEW: {$r['preview']}\n";
    }
    if (!empty($r['created_at'])) echo "criado em: {$r['created_at']}\n";
    if (!empty($r['updated_at'])) echo "atualizado em: {$r['updated_at']}\n";
}

// Ver tambem se tem atalho de "localizacao" pra comparar
echo "\n\n-- Atalhos com 'localiza' no nome/conteudo (pra comparar) --\n";
$st = $pdo->query("SELECT id, nome, atalho, LEFT(conteudo, 200) AS preview
                    FROM zapi_templates
                    WHERE atalho LIKE '%local%' OR nome LIKE '%localiza%' OR nome LIKE '%endere%'
                    LIMIT 10");
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "  /{$r['atalho']} — {$r['nome']}\n     preview: {$r['preview']}\n";
}
