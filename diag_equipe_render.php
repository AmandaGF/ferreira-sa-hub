<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('x'); }
header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_utils.php';
$pdo = db();

// Simula EXATAMENTE a mesma query do equipe.php, mas renderiza SEM middleware/layout.
$total = (int)$pdo->query("SELECT COUNT(*) FROM treinamento_modulos WHERE ativo = 1")->fetchColumn();

$stmt = $pdo->query(
    "SELECT u.id, u.name, u.role, u.setor,
            COUNT(CASE WHEN p.concluido = 1 THEN 1 END) AS concluidos,
            MAX(p.updated_at) AS ultimo_acesso,
            COALESCE(SUM(p.pontos_ganhos), 0) AS pontos
     FROM users u
     LEFT JOIN treinamento_progresso p ON p.user_id = u.id
     WHERE u.is_active = 1
     GROUP BY u.id
     ORDER BY concluidos DESC, pontos DESC"
);
$equipe = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html><head><meta charset="utf-8"><title>Diag Render Equipe</title>
<style>body{font-family:Arial;padding:20px}.check{background:#ffe;padding:10px;border:2px dashed #f90;margin-bottom:20px}</style>
</head><body>
<h1>Diag equipe.php — mesma query, renderização simples</h1>
<div class="check">
  <strong>Total users ativos retornados pela query:</strong> <?= count($equipe) ?><br>
  <strong>Total módulos ativos:</strong> <?= $total ?>
</div>
<table border="1" cellpadding="6" style="border-collapse:collapse;font-size:.9em">
  <tr style="background:#052228;color:#fff">
    <th>ID</th><th>Nome (e() escapado)</th><th>Nome (raw)</th><th>LEN</th><th>Role</th><th>Setor</th><th>Concluídos</th><th>Pontos</th>
  </tr>
<?php foreach ($equipe as $r): ?>
  <tr>
    <td><?= (int)$r['id'] ?></td>
    <td><strong><?= e($r['name']) ?></strong></td>
    <td><code><?= htmlentities($r['name']) ?></code></td>
    <td><?= mb_strlen($r['name']) ?></td>
    <td><?= e($r['role']) ?></td>
    <td><?= e($r['setor']) ?></td>
    <td><?= (int)$r['concluidos'] ?></td>
    <td><?= (int)$r['pontos'] ?></td>
  </tr>
<?php endforeach; ?>
</table>
</body></html>
