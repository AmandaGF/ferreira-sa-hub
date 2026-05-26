<?php
// Migração: colunas cases.ia_analise_aprofundada + ia_analise_em
// Pra feature "Análise estratégica profunda" (Sonnet) — 26/05/2026.
// Rode 1x via: curl "https://ferreiraesa.com.br/conecta/migrar_ia_analise_aprofundada.php?key=fsa-hub-deploy-2026"

if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') {
    http_response_code(403);
    exit('forbidden');
}

require_once __DIR__ . '/core/database.php';
$pdo = db();

header('Content-Type: text/plain; charset=utf-8');

$alters = array(
    "ALTER TABLE cases ADD COLUMN ia_analise_aprofundada MEDIUMTEXT NULL AFTER ia_resumo_em",
    "ALTER TABLE cases ADD COLUMN ia_analise_em DATETIME NULL AFTER ia_analise_aprofundada",
);

foreach ($alters as $sql) {
    try {
        $pdo->exec($sql);
        echo "OK: $sql\n";
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        if (strpos($msg, 'Duplicate column') !== false || strpos($msg, 'duplicate') !== false) {
            echo "JÁ EXISTE (ok): $sql\n";
        } else {
            echo "ERRO: $sql -> $msg\n";
        }
    }
}

// Killswitch default OFF (Amanda liga manualmente em /admin/ia_custo.php)
try {
    $pdo->prepare("INSERT IGNORE INTO configuracoes (chave, valor) VALUES ('ia_feature_analise_aprofundada_enabled', '0')")
        ->execute();
    echo "OK: killswitch ia_feature_analise_aprofundada_enabled criado (default=0, OFF)\n";
} catch (Throwable $e) {
    echo "ERRO killswitch: " . $e->getMessage() . "\n";
}

echo "\nPronto. Agora ligue a feature em /admin/ia_custo.php se quiser testar.\n";
