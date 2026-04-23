<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('x'); }
header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_utils.php';
$pdo = db();

echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Diag</title></head><body>';
echo '<h1>Simulação do Progresso da Equipe</h1>';
echo '<p>Esta página NÃO tem login/middleware/CSS/JS — é 100% HTML puro. Se os nomes aparecerem INTEIROS aqui mas com 1 letra no Treinamento/admin.php, é cache do browser/PWA ou extensão.</p>';
echo '<table border="1" cellpadding="8" style="border-collapse:collapse;font-family:Arial;">';
echo '<tr style="background:#052228;color:#fff"><th>ID</th><th>Usuário</th><th>Perfil</th><th>Setor</th></tr>';

$r = $pdo->query("SELECT id, name, role, setor FROM users WHERE is_active = 1 ORDER BY id")->fetchAll();
foreach ($r as $u) {
    echo '<tr>';
    echo '<td>' . (int)$u['id'] . '</td>';
    echo '<td><strong>' . e($u['name']) . '</strong></td>';
    echo '<td>' . e($u['role']) . '</td>';
    echo '<td>' . e($u['setor']) . '</td>';
    echo '</tr>';
}
echo '</table>';
echo '</body></html>';
