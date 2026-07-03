<?php
require_once __DIR__ . '/core/database.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Diagnóstico WhatsApp favoritos ===\n\n";

// 1. Tabela existe?
try {
    $c = $pdo->query("SELECT COUNT(*) FROM user_wa_favoritos")->fetchColumn();
    echo "✓ Tabela user_wa_favoritos existe ({$c} registros no total)\n\n";
} catch (Exception $e) {
    echo "✗ Tabela não existe: " . $e->getMessage() . "\n";
    exit;
}

// 2. Registros por usuário/canal
echo "Registros por usuário e canal:\n";
$st = $pdo->query("SELECT u.name, f.canal, GROUP_CONCAT(f.fav_id ORDER BY f.ordem) AS favs, COUNT(*) AS n
                   FROM user_wa_favoritos f LEFT JOIN users u ON u.id = f.user_id
                   GROUP BY f.user_id, f.canal ORDER BY u.name, f.canal");
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "  " . str_pad($r['name'] ?: '(user desconhecido)', 30) . " canal {$r['canal']}: [{$r['favs']}] ({$r['n']} favs)\n";
}
if ($st->rowCount() === 0) echo "  (nenhum favorito salvo em NENHUM usuário)\n";

// 3. Amanda (user_id=1) especificamente
echo "\nAmanda (user_id=1) canal 24:\n";
$r = $pdo->query("SELECT fav_id, ordem, created_at FROM user_wa_favoritos WHERE user_id = 1 AND canal = '24' ORDER BY ordem")->fetchAll(PDO::FETCH_ASSOC);
if (!$r) echo "  (vazio)\n";
foreach ($r as $x) echo "  {$x['fav_id']} (ordem={$x['ordem']}, criado {$x['created_at']})\n";

echo "\nAmanda (user_id=1) canal 21:\n";
$r = $pdo->query("SELECT fav_id, ordem, created_at FROM user_wa_favoritos WHERE user_id = 1 AND canal = '21' ORDER BY ordem")->fetchAll(PDO::FETCH_ASSOC);
if (!$r) echo "  (vazio)\n";
foreach ($r as $x) echo "  {$x['fav_id']} (ordem={$x['ordem']}, criado {$x['created_at']})\n";
