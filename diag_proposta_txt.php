<?php
/** Conteudo dos templates de proposta/objecao p/ avaliar enquadramento do desconto.
 *  curl "https://ferreiraesa.com.br/conecta/diag_proposta_txt.php?key=fsa-hub-deploy-2026"
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Forbidden.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();
$st = $pdo->query("SELECT id, nome, canal, conteudo FROM zapi_templates WHERE nome LIKE '%ropost%' OR nome LIKE '%bje%' OR nome LIKE '%desconto%' OR conteudo LIKE '%20%' OR conteudo LIKE '%desconto%' OR conteudo LIKE '%cart%' ORDER BY id");
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "=== #{$r['id']} [{$r['canal']}] {$r['nome']} ===\n{$r['conteudo']}\n\n";
}
echo "--- inferencia de genero existe? ---\n";
echo function_exists('zapi_inferir_genero_por_nome') ? "(carregar fn...)\n" : "";
echo "=== FIM ===\n";
