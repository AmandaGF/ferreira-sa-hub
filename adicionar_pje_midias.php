<?php
/**
 * Adiciona PJe Midias (CNJ) no Portal de Links.
 * Idempotente: se ja existir pelo URL, atualiza.
 * URL: /conecta/adicionar_pje_midias.php?key=fsa-hub-deploy-2026
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit; }
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

$url = 'https://midias.pje.jus.br/midias/web/site/login';
$title = 'PJe Mídias — CNJ (áudios/vídeos de audiência)';
$hint  = 'Sistema do CNJ pra baixar áudios/vídeos de audiências gravadas no PJe';

// Descobre categorias mais usadas pra escolher a melhor
$cats = $pdo->query("SELECT DISTINCT category FROM portal_links WHERE category IS NOT NULL ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
echo "Categorias existentes:\n" . implode("\n  · ", array_merge(array(''), $cats)) . "\n\n";

// Vou usar 'Federal / TRF' se existir (mais proximo semanticamente — sistema nacional)
$cat = in_array('Federal / TRF', $cats, true) ? 'Federal / TRF' : 'Tribunais - Sudeste';
echo "Categoria escolhida: $cat\n\n";

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

$max = (int)$pdo->query("SELECT COALESCE(MAX(sort_order),0) FROM portal_links WHERE category = " . $pdo->quote($cat))->fetchColumn();
$sort = $max + 1;

$stmt = $pdo->prepare(
    'INSERT INTO portal_links (category, title, url, username, password_encrypted, hint, audience, is_favorite, sort_order, created_by)
     VALUES (?, ?, ?, NULL, NULL, ?, "internal", 0, ?, ?)'
);
$stmt->execute(array($cat, $title, $url, $hint, $sort, $userId));
$newId = (int)$pdo->lastInsertId();

echo "✓ Inserido link id=$newId, categoria='$cat', sort_order=$sort\n";
echo "  Título: $title\n";
