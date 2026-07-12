<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== QUEILA #1175 ===\n";
$c = $pdo->query("SELECT id, name, phone, phone2, email, cpf FROM clients WHERE id=1175")->fetch(PDO::FETCH_ASSOC);
print_r($c);

$telSo = preg_replace('/\D/', '', $c['phone'] ?? '');
$tel2So = preg_replace('/\D/', '', $c['phone2'] ?? '');
echo "\nTel principal só números: '$telSo' (" . strlen($telSo) . " dígitos)\n";
echo "Tel2 só números: '$tel2So' (" . strlen($tel2So) . " dígitos)\n";
echo "Esperado BR: 10 (fixo) ou 11 (celular com 9)\n";

echo "\n=== Conversas WA vinculadas ===\n";
foreach ($pdo->query("SELECT id, canal, telefone, nome_contato, ultima_msg_em, status FROM zapi_conversas WHERE client_id=1175") as $r) {
    printf("  conv#%d canal=%s tel=%s nome=%s ultima=%s status=%s\n",
        $r['id'], $r['canal'], $r['telefone'], substr($r['nome_contato'],0,30), $r['ultima_msg_em'], $r['status']);
}

echo "\n=== Últimas mensagens em conversas dessa Queila ===\n";
$st = $pdo->query("SELECT m.id, m.direcao, m.status, m.tipo, m.zapi_message_id, m.created_at,
                          SUBSTRING(m.conteudo, 1, 90) AS preview, co.canal, co.telefone
                   FROM zapi_mensagens m JOIN zapi_conversas co ON co.id=m.conversa_id
                   WHERE co.client_id=1175 ORDER BY m.created_at DESC LIMIT 15");
foreach ($st as $r) {
    printf("  #%d [%s canal=%s] %s status=%s mid=%s tipo=%s\n     %s\n",
        $r['id'], $r['direcao'], $r['canal'], $r['created_at'],
        ($r['status'] ?: 'VAZIO'), substr($r['zapi_message_id']??'',0,25), $r['tipo'],
        $r['preview']);
}

echo "\n=== Buscar msgs recentes pelo TELEFONE (mesmo se conversa nao ta vinculada) ===\n";
if ($telSo) {
    $st = $pdo->prepare("SELECT m.id, m.direcao, m.status, m.created_at,
                                SUBSTRING(m.conteudo,1,90) AS preview, co.canal, co.telefone, co.client_id
                         FROM zapi_mensagens m JOIN zapi_conversas co ON co.id=m.conversa_id
                         WHERE co.telefone LIKE ? OR co.telefone LIKE ?
                         ORDER BY m.created_at DESC LIMIT 10");
    $st->execute(array('%'.$telSo.'%', '%'.substr($telSo,-8).'%'));
    foreach ($st as $r) {
        printf("  #%d [%s canal=%s] %s status=%s tel=%s client=%s\n     %s\n",
            $r['id'], $r['direcao'], $r['canal'], $r['created_at'],
            ($r['status'] ?: 'VAZIO'), $r['telefone'], $r['client_id'], $r['preview']);
    }
}

echo "\n=== Últimas 15 msgs ENVIADAS no canal 24 nas ultimas 24h ===\n";
$st = $pdo->query("SELECT m.id, m.status, m.created_at, m.zapi_message_id,
                          SUBSTRING(m.conteudo,1,60) AS preview, co.telefone, co.nome_contato
                   FROM zapi_mensagens m JOIN zapi_conversas co ON co.id=m.conversa_id
                   WHERE co.canal='24' AND m.direcao='enviada'
                     AND m.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                   ORDER BY m.created_at DESC LIMIT 15");
foreach ($st as $r) {
    printf("  #%d %s status=%s tel=%s (%s)\n     mid=%s | %s\n",
        $r['id'], $r['created_at'], ($r['status']?:'VAZIO'), $r['telefone'],
        substr($r['nome_contato']??'',0,20), substr($r['zapi_message_id']??'',0,25), $r['preview']);
}

echo "\n=== Instância Z-API canal 24 ===\n";
foreach ($pdo->query("SELECT id, canal, instance_id, connected, ultima_verificacao FROM zapi_instancias WHERE canal='24'") as $r) {
    printf("  canal=%s inst=%s conn=%s verif=%s\n", $r['canal'], substr($r['instance_id']??'',0,30), $r['connected'], $r['ultima_verificacao']);
}
