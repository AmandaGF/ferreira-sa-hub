<?php
/**
 * Diag AUTENTICADO — roda a mesma query do admin.php com middleware COMPLETO,
 * mas SEM layout_start/end. Isola se o bug é no middleware ou no layout.
 *
 * Acesse LOGADA como Amanda.
 */
// Sem cache
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/core/middleware.php';
require_login();
if (!has_min_role('gestao')) { http_response_code(403); exit('Acesso restrito'); }

$pdo = db();

// === EXATAMENTE como admin.php ===
$filtroPerfil = $_GET['role'] ?? 'todos';
$where = "WHERE u.is_active = 1";
$params = array();
if ($filtroPerfil !== 'todos') {
    $where .= " AND u.role = ?";
    $params[] = $filtroPerfil;
}

$total = (int)$pdo->query("SELECT COUNT(*) FROM treinamento_modulos WHERE ativo = 1")->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT u.id, u.name, u.role, u.setor,
            COUNT(CASE WHEN p.concluido = 1 THEN 1 END) AS concluidos,
            MAX(p.updated_at) AS ultimo_acesso,
            COALESCE(SUM(p.pontos_ganhos), 0) AS pontos
     FROM users u
     LEFT JOIN treinamento_progresso p ON p.user_id = u.id
     {$where}
     GROUP BY u.id
     ORDER BY concluidos DESC, pontos DESC"
);
$stmt->execute($params);
$equipe = $stmt->fetchAll();

// Dump cru em JSON pra comparar
$dump = array_map(function($r){
    return array(
        'id' => $r['id'],
        'name' => $r['name'],
        'name_len' => mb_strlen($r['name']),
        'name_hex' => bin2hex($r['name']),
        'role' => $r['role'],
        'setor' => $r['setor'],
    );
}, $equipe);

$u = current_user();
?>
<!DOCTYPE html>
<html><head><meta charset="utf-8"><title>Diag AUTH</title>
<style>body{font-family:monospace;padding:20px;background:#fff}.h{background:#ffe;padding:10px;border:2px dashed #f90;margin-bottom:10px}.ok{color:green;font-weight:bold}.err{color:red;font-weight:bold}table{border-collapse:collapse;width:100%;font-size:12px}th,td{border:1px solid #ccc;padding:6px;text-align:left;vertical-align:top}</style>
</head><body>

<div class="h">
<strong>Hora do servidor:</strong> <?= date('Y-m-d H:i:s') ?><br>
<strong>Usuário logado:</strong> id=<?= (int)($u['id'] ?? 0) ?> | name="<?= e($u['name'] ?? '') ?>" | role=<?= e($u['role'] ?? '') ?><br>
<strong>Total linhas retornadas:</strong> <span class="<?= count($equipe) >= 9 ? 'ok' : 'err' ?>"><?= count($equipe) ?></span><br>
<strong>Total módulos ativos:</strong> <?= $total ?><br>
<strong>PDO::ATTR_CASE:</strong> <?= $pdo->getAttribute(PDO::ATTR_CASE) ?> (0=NATURAL, 1=LOWER, 2=UPPER)<br>
<strong>PDO::ATTR_ERRMODE:</strong> <?= $pdo->getAttribute(PDO::ATTR_ERRMODE) ?><br>
<strong>mb_internal_encoding:</strong> <?= mb_internal_encoding() ?><br>
<strong>default_charset:</strong> <?= ini_get('default_charset') ?><br>
</div>

<h2>1. DUMP CRU DOS NOMES (byte-a-byte)</h2>
<table>
<tr><th>ID</th><th>name (raw)</th><th>LEN</th><th>HEX (primeiros bytes)</th><th>role</th><th>setor</th></tr>
<?php foreach ($dump as $d): ?>
<tr>
<td><?= (int)$d['id'] ?></td>
<td><?= htmlentities($d['name']) ?></td>
<td><?= (int)$d['name_len'] ?></td>
<td style="font-size:10px"><?= substr($d['name_hex'], 0, 60) ?>...</td>
<td><?= htmlentities($d['role']) ?></td>
<td><?= htmlentities($d['setor']) ?></td>
</tr>
<?php endforeach; ?>
</table>

<h2>2. MESMA RENDERIZAÇÃO QUE O admin.php (com e())</h2>
<table>
<tr><th>ID</th><th>Nome (e() escapado)</th><th>Role</th><th>Concluídos</th><th>Pontos</th></tr>
<?php foreach ($equipe as $r): ?>
<tr>
<td><?= (int)$r['id'] ?></td>
<td><strong><?= e($r['name']) ?></strong></td>
<td><?= e($r['role']) ?></td>
<td><?= (int)$r['concluidos'] ?></td>
<td><?= (int)$r['pontos'] ?></td>
</tr>
<?php endforeach; ?>
</table>

<h2>3. JSON CRU (para copiar se precisar)</h2>
<pre style="background:#f5f5f5;padding:10px;overflow:auto"><?= htmlentities(json_encode($dump, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>

</body></html>
