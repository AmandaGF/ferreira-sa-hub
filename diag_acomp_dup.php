<?php
if (($_GET['key']??'') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('no'); }
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

// Config e dados do caso
$c = $pdo->query(
    "SELECT a.*, cs.case_type, cs.title AS case_title
     FROM acompanhamento_msg_diario a
     JOIN cases cs ON cs.id = a.case_id LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);

echo "cfg#{$c['id']} case={$c['case_id']}\n";
echo "  ultimo_envio_em = {$c['ultimo_envio_em']}\n";
echo "  ultimo_template_idx = " . ($c['ultimo_template_idx'] ?? '(null)') . "\n";
echo "  ultima_data_andamento_visto = " . ($c['ultima_data_andamento_visto'] ?? '(null)') . "\n";
echo "  total_envios = {$c['total_envios']}\n";
echo "  case_type = " . ($c['case_type'] ?: '(vazio)') . "\n";
echo "  case_title = {$c['case_title']}\n";

// Parte adversa em case_partes
$partes = $pdo->prepare("SELECT papel, nome, razao_social, representante_nome, nome_fantasia, eh_nosso_cliente FROM case_partes WHERE case_id = ?");
$partes->execute(array((int)$c['case_id']));
echo "\n--- Partes do caso ---\n";
foreach ($partes->fetchAll(PDO::FETCH_ASSOC) as $p) {
    $nome = $p['nome'] ?: $p['razao_social'] ?: $p['representante_nome'] ?: $p['nome_fantasia'] ?: '(sem nome)';
    echo "  papel={$p['papel']} | nome={$nome} | eh_nosso_cliente={$p['eh_nosso_cliente']}\n";
}

// Ultimas msgs enviadas pra este cliente hoje (canal 24)
echo "\n--- Últimas msgs canal 24 do cliente hoje ---\n";
$cliPhone = $pdo->prepare("SELECT phone FROM clients WHERE id = ?");
$cliPhone->execute(array((int)$c['client_id']));
$tel = $cliPhone->fetchColumn();
$telDig = preg_replace('/\D/', '', $tel);
echo "  Telefone base: {$tel} (digits: {$telDig})\n";
$ms = $pdo->query(
    "SELECT m.id, m.created_at, m.status, m.zapi_message_id, LEFT(m.conteudo,100) AS preview
     FROM zapi_mensagens m
     JOIN zapi_conversas co ON co.id = m.conversa_id
     WHERE co.canal='24' AND m.direcao='enviada' AND DATE(m.created_at)=CURDATE()
       AND REPLACE(REPLACE(REPLACE(REPLACE(co.telefone,'(',''),')',''),'-',''),' ','') LIKE '%{$telDig}%'
     ORDER BY m.id DESC LIMIT 10"
)->fetchAll(PDO::FETCH_ASSOC);
foreach ($ms as $m) {
    $prev = str_replace(array("\n","\r"), ' | ', $m['preview']);
    echo "  #{$m['id']} em {$m['created_at']} status={$m['status']}\n    {$prev}\n";
}
