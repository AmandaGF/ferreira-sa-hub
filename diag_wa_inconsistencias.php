<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('x'); }
header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/core/database.php';
$pdo = db();

// normaliza telefone = só dígitos, sem 55 inicial, sem 9 opcional do celular após DDD
function norm($tel) {
    $t = preg_replace('/\D/', '', (string)$tel);
    // remove 55 inicial se tem mais de 11 dígitos
    if (strlen($t) > 11 && substr($t, 0, 2) === '55') $t = substr($t, 2);
    return $t;
}
function chave($tel) {
    // chave de comparação — últimos 8 dígitos (número sem DDD nem 9)
    $n = norm($tel);
    return substr($n, -8);
}
?>
<!DOCTYPE html><html><head><meta charset="utf-8"><title>Diag WhatsApp inconsistências</title>
<style>body{font-family:monospace;padding:20px;font-size:12px}h2{background:#ffe;padding:8px;border-left:4px solid #f90;margin-top:20px}table{border-collapse:collapse;margin:10px 0;font-size:11px}th,td{border:1px solid #ccc;padding:4px 8px;vertical-align:top}.err{background:#fee;color:#c00;font-weight:bold}.ok{color:#080}.warn{background:#ffc}</style>
</head><body>

<h2>1. Conversas vinculadas a client_id onde o telefone NÃO BATE com o cliente</h2>
<p>Compara últimos 8 dígitos do telefone da conversa vs últimos 8 do cliente vinculado.</p>
<?php
$q = $pdo->query(
    "SELECT co.id AS conv_id, co.canal, co.telefone AS conv_tel, co.nome_contato,
            c.id AS cli_id, c.name AS cli_name, c.phone AS cli_phone,
            co.status, co.updated_at
     FROM zapi_conversas co
     JOIN clients c ON c.id = co.client_id
     WHERE co.client_id IS NOT NULL
     ORDER BY co.updated_at DESC"
);
$todas = $q->fetchAll();
$inconsist = array();
foreach ($todas as $r) {
    $a = chave($r['conv_tel']);
    $b = chave($r['cli_phone']);
    if ($a !== $b) $inconsist[] = $r;
}
?>
<p><strong>Total de conversas vinculadas:</strong> <?= count($todas) ?> · <strong class="err"><?= count($inconsist) ?> com INCONSISTÊNCIA</strong></p>
<table><tr><th>conv_id</th><th>canal</th><th>tel_conv</th><th>client_id</th><th>nome_cliente</th><th>tel_cliente</th><th>nome_contato</th><th>status</th><th>updated_at</th></tr>
<?php foreach (array_slice($inconsist, 0, 200) as $r): ?>
<tr class="err">
<td><?= $r['conv_id'] ?></td>
<td><?= $r['canal'] ?></td>
<td><?= htmlspecialchars($r['conv_tel']) ?></td>
<td><?= $r['cli_id'] ?></td>
<td><?= htmlspecialchars($r['cli_name']) ?></td>
<td><?= htmlspecialchars($r['cli_phone']) ?></td>
<td><?= htmlspecialchars($r['nome_contato'] ?? '') ?></td>
<td><?= $r['status'] ?></td>
<td><?= $r['updated_at'] ?></td>
</tr>
<?php endforeach; if (count($inconsist) > 200) echo '<tr><td colspan=9>... (+'.(count($inconsist)-200).' linhas omitidas)</td></tr>'; ?>
</table>

<h2>2. Conversas com telefone SUSPEITO (muito curto ou muito longo)</h2>
<?php
$q = $pdo->query(
    "SELECT id, canal, telefone, client_id, nome_contato, status, updated_at
     FROM zapi_conversas
     WHERE telefone IS NOT NULL
     ORDER BY updated_at DESC"
);
$suspeitos = array();
foreach ($q->fetchAll() as $r) {
    $n = norm($r['telefone']);
    $len = strlen($n);
    // Brasileiro esperado: 10 (fixo sem 9) ou 11 (celular com 9)
    if ($len < 10 || $len > 11) {
        $r['_len'] = $len;
        $r['_norm'] = $n;
        $suspeitos[] = $r;
    }
}
?>
<p><strong>Total de conversas com telefone fora do padrão brasileiro (10-11 dígitos sem DDI):</strong> <?= count($suspeitos) ?></p>
<table><tr><th>conv_id</th><th>canal</th><th>telefone</th><th>normalizado</th><th>len</th><th>client_id</th><th>nome_contato</th><th>status</th></tr>
<?php foreach (array_slice($suspeitos, 0, 100) as $r): ?>
<tr class="warn">
<td><?= $r['id'] ?></td>
<td><?= $r['canal'] ?></td>
<td><?= htmlspecialchars($r['telefone']) ?></td>
<td><?= $r['_norm'] ?></td>
<td><?= $r['_len'] ?></td>
<td><?= $r['client_id'] ?? '-' ?></td>
<td><?= htmlspecialchars($r['nome_contato'] ?? '') ?></td>
<td><?= $r['status'] ?></td>
</tr>
<?php endforeach; ?>
</table>

<h2>3. Mensagens de aniversário enviadas nas últimas 48h</h2>
<?php
$q = $pdo->query(
    "SELECT m.id, m.conversa_id, m.direcao, m.created_at,
            SUBSTR(m.texto, 1, 60) AS preview,
            co.telefone AS conv_tel,
            co.client_id, cli.name AS cli_name, cli.phone AS cli_phone,
            cli.birth_date
     FROM zapi_mensagens m
     JOIN zapi_conversas co ON co.id = m.conversa_id
     LEFT JOIN clients cli ON cli.id = co.client_id
     WHERE (m.texto LIKE '%Feliz aniversário%' OR m.texto LIKE '%Feliz Aniversario%'
            OR m.texto LIKE '%Parabéns%' OR m.texto LIKE '%parabeniza%')
       AND m.direcao = 'enviada'
       AND m.created_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
     ORDER BY m.id DESC"
);
$msgs = $q->fetchAll();
?>
<p><strong>Total:</strong> <?= count($msgs) ?></p>
<table><tr><th>msg_id</th><th>conv_id</th><th>data</th><th>tel_conv</th><th>client_id</th><th>cli_name</th><th>cli_phone</th><th>aniversário</th><th>preview</th><th>tel_bate?</th></tr>
<?php foreach ($msgs as $r):
    $bate = (chave($r['conv_tel']) === chave($r['cli_phone']));
?>
<tr class="<?= $bate ? '' : 'err' ?>">
<td><?= $r['id'] ?></td>
<td><?= $r['conversa_id'] ?></td>
<td><?= $r['created_at'] ?></td>
<td><?= htmlspecialchars($r['conv_tel']) ?></td>
<td><?= $r['client_id'] ?? '-' ?></td>
<td><?= htmlspecialchars($r['cli_name'] ?? '') ?></td>
<td><?= htmlspecialchars($r['cli_phone'] ?? '') ?></td>
<td><?= $r['birth_date'] ?? '' ?></td>
<td><?= htmlspecialchars($r['preview']) ?>...</td>
<td><?= $bate ? '<span class=ok>OK</span>' : '<b>NÃO</b>' ?></td>
</tr>
<?php endforeach; ?>
</table>

<h2>4. Clientes com MESMO telefone normalizado (colisão)</h2>
<?php
$q = $pdo->query("SELECT id, name, phone FROM clients WHERE phone IS NOT NULL AND phone != ''");
$porChave = array();
foreach ($q->fetchAll() as $r) {
    $k = chave($r['phone']);
    if (strlen($k) < 7) continue;
    $porChave[$k][] = $r;
}
$colisoes = array_filter($porChave, function($arr){ return count($arr) > 1; });
?>
<p><strong>Chaves colidindo (&gt; 1 cliente mesmo telefone):</strong> <?= count($colisoes) ?></p>
<table><tr><th>chave (últimos 8 díg.)</th><th>qtd</th><th>clientes</th></tr>
<?php foreach (array_slice($colisoes, 0, 30, true) as $k => $arr): ?>
<tr class="warn">
<td><?= $k ?></td>
<td><?= count($arr) ?></td>
<td>
<?php foreach ($arr as $r) echo '#' . $r['id'] . ' ' . htmlspecialchars($r['name']) . ' (' . htmlspecialchars($r['phone']) . ')<br>'; ?>
</td>
</tr>
<?php endforeach; ?>
</table>

<h2>5. Conv 686 — histórico completo (caso da Alícia)</h2>
<?php
$q = $pdo->prepare("SELECT id, direcao, created_at, SUBSTR(texto, 1, 100) AS preview, zapi_message_id, enviado_por_id FROM zapi_mensagens WHERE conversa_id = ? ORDER BY id DESC LIMIT 30");
$q->execute(array(686));
$res = $q->fetchAll();
?>
<table><tr><th>msg_id</th><th>direção</th><th>data</th><th>zapi_msg_id</th><th>enviado_por</th><th>preview</th></tr>
<?php foreach ($res as $r): ?>
<tr><td><?= $r['id'] ?></td><td><?= $r['direcao'] ?></td><td><?= $r['created_at'] ?></td><td><?= htmlspecialchars($r['zapi_message_id'] ?? '') ?></td><td><?= $r['enviado_por_id'] ?></td><td><?= htmlspecialchars($r['preview']) ?></td></tr>
<?php endforeach; ?>
</table>

<h2>6. Existe outra conversa do JOSE (client_id=864)?</h2>
<?php
$q = $pdo->prepare("SELECT id, canal, telefone, client_id, nome_contato, status, updated_at FROM zapi_conversas WHERE client_id = ? OR telefone LIKE '%99492534%' OR telefone LIKE '%99949-2534%'");
$q->execute(array(864));
$res = $q->fetchAll();
?>
<table><tr><th>conv_id</th><th>canal</th><th>telefone</th><th>client_id</th><th>nome_contato</th><th>status</th><th>updated_at</th></tr>
<?php foreach ($res as $r): ?>
<tr><td><?= $r['id'] ?></td><td><?= $r['canal'] ?></td><td><?= htmlspecialchars($r['telefone']) ?></td><td><?= $r['client_id'] ?></td><td><?= htmlspecialchars($r['nome_contato'] ?? '') ?></td><td><?= $r['status'] ?></td><td><?= $r['updated_at'] ?></td></tr>
<?php endforeach; if (!$res) echo '<tr><td colspan=7 class=err>JOSE não tem conversa no Hub — mas mensagem foi enviada (logs do cron confirmam) → fila direta, sem conversa</td></tr>'; ?>
</table>

</body></html>
