<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('x'); }
header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/core/database.php';
$pdo = db();
?>
<!DOCTYPE html><html><head><meta charset="utf-8"><title>Diag bug aniversário</title>
<style>body{font-family:monospace;padding:20px}h2{background:#ffe;padding:8px;border-left:4px solid #f90}table{border-collapse:collapse;margin:10px 0}th,td{border:1px solid #ccc;padding:6px 10px;vertical-align:top}.err{color:red;font-weight:bold}pre{background:#f5f5f5;padding:8px;white-space:pre-wrap}</style>
</head><body>

<h2>1. Clientes com nome "JOSE HERICKSON" ou parecido</h2>
<?php
$q = $pdo->prepare("SELECT id, name, phone, birth_date FROM clients WHERE name LIKE ? OR name LIKE ? OR name LIKE ?");
$q->execute(array('%HERICKSON%', '%BARREIRA%', '%JOSE%BARREIRA%'));
$res = $q->fetchAll();
?>
<table><tr><th>ID</th><th>Nome</th><th>Telefone</th><th>Nascimento</th></tr>
<?php foreach ($res as $r): ?>
<tr><td><?= $r['id'] ?></td><td><?= htmlspecialchars($r['name']) ?></td><td><?= htmlspecialchars($r['phone']) ?></td><td><?= $r['birth_date'] ?></td></tr>
<?php endforeach; if (!$res) echo '<tr><td colspan=4 class=err>NÃO ENCONTRADO</td></tr>'; ?>
</table>

<h2>2. Clientes com nome "Alícia" ou "Wogel"</h2>
<?php
$q = $pdo->prepare("SELECT id, name, phone, birth_date FROM clients WHERE name LIKE ? OR name LIKE ? OR name LIKE ?");
$q->execute(array('%Alícia%', '%Wogel%', '%Carvalho Wogel%'));
$res = $q->fetchAll();
?>
<table><tr><th>ID</th><th>Nome</th><th>Telefone</th><th>Nascimento</th></tr>
<?php foreach ($res as $r): ?>
<tr><td><?= $r['id'] ?></td><td><?= htmlspecialchars($r['name']) ?></td><td><?= htmlspecialchars($r['phone']) ?></td><td><?= $r['birth_date'] ?></td></tr>
<?php endforeach; if (!$res) echo '<tr><td colspan=4 class=err>NÃO ENCONTRADO</td></tr>'; ?>
</table>

<h2>3. Conversa WhatsApp com telefone "4832031248" ou similar</h2>
<?php
$q = $pdo->prepare("SELECT id, telefone, canal, client_id, atendente_id, nome_contato, status FROM zapi_conversas WHERE telefone LIKE ? OR telefone LIKE ? OR telefone LIKE ?");
$q->execute(array('%4832031248%', '%32031248%', '%3203124%'));
$res = $q->fetchAll();
?>
<table><tr><th>ID</th><th>Telefone</th><th>Canal</th><th>client_id</th><th>atendente_id</th><th>Nome contato</th><th>Status</th></tr>
<?php foreach ($res as $r): ?>
<tr><td><?= $r['id'] ?></td><td><?= $r['telefone'] ?></td><td><?= $r['canal'] ?></td><td><?= $r['client_id'] ?></td><td><?= $r['atendente_id'] ?></td><td><?= htmlspecialchars($r['nome_contato'] ?? '') ?></td><td><?= $r['status'] ?></td></tr>
<?php endforeach; if (!$res) echo '<tr><td colspan=7 class=err>NÃO ENCONTRADO</td></tr>'; ?>
</table>

<h2>4. Últimas 20 msgs de aniversário enviadas (buscar "Feliz aniversário")</h2>
<?php
$q = $pdo->query("SELECT m.id, m.conversa_id, m.direcao, m.created_at, m.texto, c.telefone, c.client_id, cli.name AS client_name FROM zapi_mensagens m LEFT JOIN zapi_conversas c ON c.id = m.conversa_id LEFT JOIN clients cli ON cli.id = c.client_id WHERE m.texto LIKE '%Feliz aniversário%' OR m.texto LIKE '%Feliz Aniversario%' ORDER BY m.id DESC LIMIT 20");
$res = $q->fetchAll();
?>
<table><tr><th>msg_id</th><th>conv_id</th><th>dir</th><th>data</th><th>telefone</th><th>client_id</th><th>client_name</th><th>texto (início)</th></tr>
<?php foreach ($res as $r): ?>
<tr><td><?= $r['id'] ?></td><td><?= $r['conversa_id'] ?></td><td><?= $r['direcao'] ?></td><td><?= $r['created_at'] ?></td><td><?= htmlspecialchars($r['telefone']) ?></td><td><?= $r['client_id'] ?></td><td><?= htmlspecialchars($r['client_name'] ?? '') ?></td><td><?= htmlspecialchars(mb_substr($r['texto'], 0, 80)) ?>...</td></tr>
<?php endforeach; ?>
</table>

<h2>5. birthday_greetings — últimos 20 registros</h2>
<?php
$q = $pdo->query("SELECT bg.id, bg.client_id, bg.year, bg.sent_at, cli.name, cli.phone, cli.birth_date FROM birthday_greetings bg LEFT JOIN clients cli ON cli.id = bg.client_id ORDER BY bg.id DESC LIMIT 20");
$res = $q->fetchAll();
?>
<table><tr><th>id</th><th>client_id</th><th>year</th><th>sent_at</th><th>nome</th><th>telefone</th><th>aniversário</th></tr>
<?php foreach ($res as $r): ?>
<tr><td><?= $r['id'] ?></td><td><?= $r['client_id'] ?></td><td><?= $r['year'] ?></td><td><?= $r['sent_at'] ?></td><td><?= htmlspecialchars($r['name'] ?? '') ?></td><td><?= htmlspecialchars($r['phone'] ?? '') ?></td><td><?= $r['birth_date'] ?></td></tr>
<?php endforeach; ?>
</table>

<h2>6. Duplicatas potenciais: clientes com telefones iguais (primeiros 8 dígitos)</h2>
<?php
$q = $pdo->query("SELECT COUNT(*) AS qtd, REGEXP_REPLACE(phone, '[^0-9]', '') AS phone_clean FROM clients WHERE phone IS NOT NULL AND phone != '' GROUP BY phone_clean HAVING qtd > 1 ORDER BY qtd DESC LIMIT 10");
$res = $q->fetchAll();
?>
<table><tr><th>qtd</th><th>telefone (normalizado)</th></tr>
<?php foreach ($res as $r): ?>
<tr><td><?= $r['qtd'] ?></td><td><?= $r['phone_clean'] ?></td></tr>
<?php endforeach; ?>
</table>

</body></html>
