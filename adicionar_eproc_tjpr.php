<?php
/**
 * Adiciona TJPR Eproc 1º Grau no Portal de Links.
 * Idempotente: se já existir pelo URL, atualiza.
 * URL: /conecta/adicionar_eproc_tjpr.php?key=fsa-hub-deploy-2026
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit; }
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

$url = 'https://sso.tjpr.jus.br/auth/realms/eproc/protocol/openid-connect/auth?response_type=code&redirect_uri=https%3A%2F%2Feproc1g.tjpr.jus.br%2Feproc%2Fexterno_controlador.php%3Facao%3DSSO%2Fcallback&client_id=eproc1g&nonce=ae333eb95760397e403270791a5c24b5&state=cc207e41baa98a105d3778204f49f419&scope=profile+openid';
$title = 'TJPR — Paraná — eproc 1º Grau';
$hint  = 'Tribunal de Justiça do Paraná — eproc 1º Grau (SSO / login via TJPR)';
$cat   = 'Tribunais - Sul';

$userId = (int)$pdo->query("SELECT id FROM users WHERE role = 'admin' AND is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
if (!$userId) { echo "ERRO: nenhum admin ativo\n"; exit(1); }

$chk = $pdo->prepare("SELECT id FROM portal_links WHERE url = ? OR (title = ? AND category = ?) LIMIT 1");
$chk->execute(array($url, $title, $cat));
if ($existingId = (int)$chk->fetchColumn()) {
    echo "Link já existe (id=$existingId). Atualizando URL/título/hint.\n";
    $upd = $pdo->prepare("UPDATE portal_links SET title=?, url=?, hint=?, category=? WHERE id=?");
    $upd->execute(array($title, $url, $hint, $cat, $existingId));
    echo "OK\n";
    exit;
}

$max = (int)$pdo->query("SELECT COALESCE(MAX(sort_order),0) FROM portal_links WHERE category = 'Tribunais - Sul'")->fetchColumn();
$sort = $max + 1;

$stmt = $pdo->prepare(
    'INSERT INTO portal_links (category, title, url, username, password_encrypted, hint, audience, is_favorite, sort_order, created_by)
     VALUES (?, ?, ?, NULL, NULL, ?, "internal", 0, ?, ?)'
);
$stmt->execute(array($cat, $title, $url, $hint, $sort, $userId));
$newId = (int)$pdo->lastInsertId();

echo "✓ Inserido link id=$newId, categoria='$cat', sort_order=$sort\n";
echo "  Título: $title\n";
