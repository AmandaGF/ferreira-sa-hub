<?php
/**
 * Remove do histórico as mensagens "[reagiu com X]" que foram criadas
 * antes do fix de associação de reações (commit que adicionou tratamento
 * de reacao fromMe no webhook).
 *
 * Essas mensagens aparecem no chat como bolhas de texto estranhas —
 * poluem o visual. A reação real já foi capturada na coluna
 * minha_reacao/reacao_cliente da mensagem original (ou vai ser, depois
 * do fix). Então podemos descartar essas mensagens "fantasma" com segurança.
 */
require_once __DIR__ . '/core/database.php';

$key = $_GET['key'] ?? '';
if ($key !== 'fsa-hub-deploy-2026') {
    http_response_code(403);
    exit('Chave inválida');
}

$pdo = db();
$dryRun = !isset($_GET['exec']);

header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><meta charset="utf-8"><title>Limpar reações fantasma</title>';
echo '<style>body{font-family:Inter,Arial,sans-serif;max-width:1000px;margin:2rem auto;padding:0 1rem} h1{color:#052228} .muted{color:#6b7280} .badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:12px;font-weight:600} .ok{background:#d1fae5;color:#065f46} .act{background:#dc2626;color:#fff;padding:.6rem 1.2rem;border-radius:8px;text-decoration:none;display:inline-block;margin-top:1rem;font-weight:700}</style>';
echo '<h1>Limpar mensagens "[reagiu com X]" do histórico</h1>';
echo '<p class="muted">Modo: <strong>' . ($dryRun ? 'DRY RUN' : 'EXECUÇÃO REAL') . '</strong></p>';

// Conta quantas tem
$count = (int)$pdo->query("SELECT COUNT(*) FROM zapi_mensagens WHERE tipo = 'reacao' OR conteudo LIKE '[reagiu com %'")->fetchColumn();
echo '<p>Mensagens identificadas como reação-fantasma: <strong>' . $count . '</strong></p>';

if (!$dryRun && $count > 0) {
    $stmt = $pdo->prepare("DELETE FROM zapi_mensagens WHERE tipo = 'reacao' OR conteudo LIKE '[reagiu com %'");
    $stmt->execute();
    $apagadas = $stmt->rowCount();
    echo '<p class="badge ok">✓ ' . $apagadas . ' mensagem(ns) fantasma removida(s) do histórico.</p>';
    echo '<p class="muted">As reações reais continuam preservadas nas colunas minha_reacao/reacao_cliente das mensagens originais.</p>';
}

if ($dryRun) {
    echo '<hr><p><strong>Dry run — nada foi apagado.</strong></p>';
    if ($count > 0) {
        echo '<a class="act" href="?key=fsa-hub-deploy-2026&exec=1">▶ Apagar ' . $count . ' mensagem(ns) fantasma</a>';
    } else {
        echo '<p class="muted">Nenhuma limpeza necessária.</p>';
    }
} else {
    echo '<hr><p class="muted">Concluído em ' . date('d/m/Y H:i:s') . '.</p>';
}
