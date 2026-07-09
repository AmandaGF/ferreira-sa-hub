<?php
/**
 * Adiciona Eproc TJSP 1º Grau na parte de Links.
 * Idempotente: se já existir pelo URL, não duplica.
 * URL: /conecta/adicionar_eproc_tjsp_1g.php?key=fsa-hub-deploy-2026
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit; }
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

$url = 'https://sso.tjsp.jus.br/realms/eproc/protocol/openid-connect/auth?kc_idp_hint=tjsp&eproc_client_id=eproc1g.tjsp.jus.br&response_type=code&redirect_uri=https%3A%2F%2Feproc1g.tjsp.jus.br%2Feproc%2Fexterno_controlador.php%3Facao%3DSSO%2Fcallback&client_id=eproc1g.tjsp.jus.br&nonce=bd606bd560dd33c517becafd50874043&state=08f0405847ee4e47191a3c1f42b4e7f4&scope=profile+openid';
$title = 'TJSP — São Paulo — eproc 1º Grau';
$hint  = 'Tribunal de Justiça de SP — eproc 1º Grau (SSO / login via TJSP)';
$cat   = 'Tribunais - Sudeste';

// Descobre user_id admin pra created_by
$userId = (int)$pdo->query("SELECT id FROM users WHERE role = 'admin' AND is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
if (!$userId) { echo "ERRO: nenhum admin ativo\n"; exit(1); }

// Idempotência: match por URL exata OU por título+categoria
$chk = $pdo->prepare("SELECT id FROM portal_links WHERE url = ? OR (title = ? AND category = ?) LIMIT 1");
$chk->execute(array($url, $title, $cat));
if ($existingId = (int)$chk->fetchColumn()) {
    echo "Link já existe (id=$existingId). Atualizando URL/título/hint.\n";
    $upd = $pdo->prepare("UPDATE portal_links SET title=?, url=?, hint=?, category=? WHERE id=?");
    $upd->execute(array($title, $url, $hint, $cat, $existingId));
    echo "OK\n";
    exit;
}

// Sort_order: pega o max da categoria Sudeste + 1 pra colocar no final
$max = (int)$pdo->query("SELECT COALESCE(MAX(sort_order),0) FROM portal_links WHERE category = 'Tribunais - Sudeste'")->fetchColumn();
$sort = $max + 1;

$stmt = $pdo->prepare(
    'INSERT INTO portal_links (category, title, url, username, password_encrypted, hint, audience, is_favorite, sort_order, created_by)
     VALUES (?, ?, ?, NULL, NULL, ?, "internal", 0, ?, ?)'
);
$stmt->execute(array($cat, $title, $url, $hint, $sort, $userId));
$newId = (int)$pdo->lastInsertId();

echo "✓ Inserido link id=$newId, categoria='$cat', sort_order=$sort\n";
echo "  Título: $title\n";
echo "  URL: " . substr($url, 0, 120) . "...\n";
