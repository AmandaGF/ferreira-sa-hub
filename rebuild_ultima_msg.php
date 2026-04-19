<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "=== Reconstruir ultima_mensagem de todas as conversas ===\n\n";

$convs = $pdo->query("SELECT id, ultima_mensagem FROM zapi_conversas")->fetchAll();
$atualizadas = 0;
foreach ($convs as $c) {
    $sel = $pdo->prepare("SELECT conteudo, created_at FROM zapi_mensagens
                          WHERE conversa_id = ? AND status != 'deletada' AND conteudo IS NOT NULL AND conteudo != ''
                          ORDER BY id DESC LIMIT 1");
    $sel->execute(array($c['id']));
    $latest = $sel->fetch();
    if ($latest) {
        $novo = mb_substr($latest['conteudo'], 0, 500);
        if ($novo !== $c['ultima_mensagem']) {
            $pdo->prepare("UPDATE zapi_conversas SET ultima_mensagem = ?, ultima_msg_em = ? WHERE id = ?")
                ->execute(array($novo, $latest['created_at'], $c['id']));
            $atualizadas++;
        }
    } else {
        // Nenhuma mensagem visível — limpa
        if ($c['ultima_mensagem']) {
            $pdo->prepare("UPDATE zapi_conversas SET ultima_mensagem = NULL WHERE id = ?")->execute(array($c['id']));
            $atualizadas++;
        }
    }
}
echo "Conversas com preview atualizado: {$atualizadas} de " . count($convs) . "\n";
echo "\n=== CONCLUIDO ===\n";
