<?php
/**
 * Migração: adiciona link "Localizar Preso — SEAP/RJ" no Portal de Links.
 *
 * Pedido da Amanda 19/07/2026. NÃO usa o seed_links.php porque ele faz
 * DELETE FROM portal_links antes de repopular (apagaria tudo que foi
 * adicionado pela UI desde o seed original).
 *
 * Idempotente: se o link já existe (mesma URL), não duplica.
 *
 * Key-protected. Chamada direta via ?key=...
 */

if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';

ini_set('display_errors', '1');
error_reporting(E_ALL);
header('Content-Type: text/plain; charset=utf-8');

$URL      = 'https://visitanteseap.detran.rj.gov.br/VisitanteSeap/localizarpreso.html';
$TITULO   = 'Localizar Preso — SEAP/RJ';
$CATEGORIA = 'Dept. Operacional';
$HINT     = 'Localizar onde a pessoa está presa (Secretaria de Administração Penitenciária do RJ)';

echo "Migracao: link Localizar Preso SEAP/RJ\n\n";

try {
    $pdo = db();
    echo "Conexao OK\n";

    // Ja existe? (compara pela URL, que e o identificador real)
    $st = $pdo->prepare('SELECT id, title, category FROM portal_links WHERE url = ? LIMIT 1');
    $st->execute(array($URL));
    $existente = $st->fetch();

    if ($existente) {
        echo "[SKIP] Link ja existe (id={$existente['id']}, categoria: {$existente['category']}, titulo: {$existente['title']})\n";
        echo "\nNada a fazer.\n";
        exit;
    }

    // Coloca no fim da categoria
    $st = $pdo->prepare('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM portal_links WHERE category = ?');
    $st->execute(array($CATEGORIA));
    $ordem = (int)$st->fetchColumn();

    // created_by: primeiro admin ativo (o script roda sem sessao)
    $adminId = null;
    try {
        $adminId = $pdo->query("SELECT id FROM users WHERE role = 'admin' AND is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
        if ($adminId === false) $adminId = null;
    } catch (Exception $e) {}

    $ins = $pdo->prepare(
        'INSERT INTO portal_links (title, category, url, username, password_encrypted, hint, audience, is_favorite, sort_order, created_by)
         VALUES (?, ?, ?, NULL, NULL, ?, ?, ?, ?, ?)'
    );
    $ins->execute(array($TITULO, $CATEGORIA, $URL, $HINT, 'internal', 0, $ordem, $adminId));

    echo "[OK] Link inserido (id=" . $pdo->lastInsertId() . ")\n";
    echo "     Categoria: {$CATEGORIA}\n";
    echo "     Titulo:    {$TITULO}\n";
    echo "     Ordem:     {$ordem}\n";
    echo "\nMigracao concluida!\n";

} catch (Exception $e) {
    echo "ERRO: " . $e->getMessage() . "\n";
    http_response_code(500);
}
