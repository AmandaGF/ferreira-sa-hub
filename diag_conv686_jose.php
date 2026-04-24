<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('x'); }
header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/core/database.php';
$pdo = db();
?>
<!DOCTYPE html><html><head><meta charset="utf-8"><title>Diag conv 686 x JOSE</title>
<style>body{font-family:monospace;padding:20px;font-size:12px}h2{background:#ffe;padding:8px;border-left:4px solid #f90}table{border-collapse:collapse;margin:10px 0;font-size:11px}th,td{border:1px solid #ccc;padding:4px 8px;vertical-align:top}.err{background:#fee}.ok{color:#080}</style>
</head><body>

<h2>A) Todas as conversas com client_id=864 (JOSE)</h2>
<?php
$q = $pdo->prepare("SELECT id, canal, telefone, nome_contato, status, updated_at FROM zapi_conversas WHERE client_id = ?");
$q->execute(array(864));
$res = $q->fetchAll();
?>
<table><tr><th>id</th><th>canal</th><th>telefone</th><th>nome_contato</th><th>status</th><th>updated</th></tr>
<?php foreach ($res as $r): ?>
<tr><td><?= $r['id'] ?></td><td><?= $r['canal'] ?></td><td><?= htmlspecialchars($r['telefone']) ?></td><td><?= htmlspecialchars($r['nome_contato'] ?? '') ?></td><td><?= $r['status'] ?></td><td><?= $r['updated_at'] ?></td></tr>
<?php endforeach; if(!$res) echo '<tr><td colspan=6 class=err>NENHUMA conversa vinculada ao JOSE no Hub</td></tr>'; ?>
</table>

<h2>B) Conversas com telefone contendo "9949" ou "99949" (possível JOSE)</h2>
<?php
$q = $pdo->query("SELECT id, canal, telefone, client_id, nome_contato, status, updated_at FROM zapi_conversas WHERE telefone LIKE '%9949%' OR telefone LIKE '%99492534%' OR telefone LIKE '%24999%'");
$res = $q->fetchAll();
?>
<table><tr><th>id</th><th>canal</th><th>telefone</th><th>client_id</th><th>nome_contato</th><th>status</th><th>updated</th></tr>
<?php foreach ($res as $r): ?>
<tr><td><?= $r['id'] ?></td><td><?= $r['canal'] ?></td><td><?= htmlspecialchars($r['telefone']) ?></td><td><?= $r['client_id'] ?? '-' ?></td><td><?= htmlspecialchars($r['nome_contato'] ?? '') ?></td><td><?= $r['status'] ?></td><td><?= $r['updated_at'] ?></td></tr>
<?php endforeach; if(!$res) echo '<tr><td colspan=7 class=err>Nenhuma conversa com esse padrão</td></tr>'; ?>
</table>

<h2>C) Histórico da conv 686 (últimas 30 mensagens)</h2>
<?php
$q = $pdo->prepare("SELECT id, direcao, created_at, SUBSTR(COALESCE(texto,''), 1, 80) AS preview, zapi_message_id, enviado_por_id, tipo FROM zapi_mensagens WHERE conversa_id = ? ORDER BY id DESC LIMIT 30");
$q->execute(array(686));
$res = $q->fetchAll();
?>
<table><tr><th>id</th><th>dir</th><th>data</th><th>tipo</th><th>por</th><th>zapi_id</th><th>preview</th></tr>
<?php foreach ($res as $r): ?>
<tr><td><?= $r['id'] ?></td><td><?= $r['direcao'] ?></td><td><?= $r['created_at'] ?></td><td><?= $r['tipo'] ?></td><td><?= $r['enviado_por_id'] ?></td><td><?= htmlspecialchars(substr($r['zapi_message_id'] ?? '', 0, 20)) ?></td><td><?= htmlspecialchars($r['preview']) ?></td></tr>
<?php endforeach; ?>
</table>

<h2>D) Estrutura da conv 686 (todos os campos)</h2>
<?php
$q = $pdo->prepare("SELECT * FROM zapi_conversas WHERE id = 686");
$q->execute();
$r = $q->fetch();
?>
<table>
<?php foreach ($r as $k=>$v): ?>
<tr><td><strong><?= $k ?></strong></td><td><?= htmlspecialchars((string)$v) ?></td></tr>
<?php endforeach; ?>
</table>

<h2>E) Campo chat_lid ou similar em todas as conversas de JOSE (buscar no JSON raw)</h2>
<?php
$q = $pdo->prepare("SELECT id, conversa_id, tipo, direcao, created_at, SUBSTR(payload_json, 1, 500) AS payload_inicio FROM zapi_mensagens WHERE conversa_id = ? ORDER BY id DESC LIMIT 5");
$q->execute(array(686));
$res = $q->fetchAll();
?>
<table><tr><th>id</th><th>conv</th><th>tipo</th><th>dir</th><th>data</th><th>payload (início)</th></tr>
<?php foreach ($res as $r): ?>
<tr><td><?= $r['id'] ?></td><td><?= $r['conversa_id'] ?></td><td><?= $r['tipo'] ?></td><td><?= $r['direcao'] ?></td><td><?= $r['created_at'] ?></td><td style="word-break:break-all;max-width:800px;"><pre><?= htmlspecialchars($r['payload_inicio'] ?? '') ?></pre></td></tr>
<?php endforeach; ?>
</table>

</body></html>
