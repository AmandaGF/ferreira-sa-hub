<?php
/**
 * Amanda 10/07/2026: envia WA pras clientes com respostas nao-lidas
 * na Central VIP (bug cliente_id=0 corrigido, mas elas nunca foram
 * avisadas). Uma msg por cliente, agregada se tem multiplas threads.
 *
 * URL: /conecta/aviso_salavip_pendentes.php?key=fsa-hub-deploy-2026
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_zapi.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Envio WhatsApp — Clientes com respostas nao-lidas Central VIP ===\n\n";

// Agrupa por cliente
$st = $pdo->query(
    "SELECT c.id AS cli_id, c.name AS cli_nome, c.phone AS cli_phone,
            GROUP_CONCAT(t.assunto SEPARATOR '||') AS assuntos,
            SUM((SELECT COUNT(*) FROM salavip_mensagens WHERE thread_id = t.id AND origem='conecta' AND lida_cliente=0)) AS total_nao_lidas,
            COUNT(t.id) AS n_threads
     FROM salavip_threads t
     JOIN clients c ON c.id = t.cliente_id
     WHERE EXISTS (
         SELECT 1 FROM salavip_mensagens m
         WHERE m.thread_id = t.id AND m.origem='conecta' AND m.lida_cliente = 0
     )
     GROUP BY c.id, c.name, c.phone"
);
$clientes = $st->fetchAll(PDO::FETCH_ASSOC);
echo "Total de clientes a avisar: " . count($clientes) . "\n\n";

$sucesso = 0; $erro = 0;
foreach ($clientes as $c) {
    echo str_repeat('-', 60) . "\n";
    echo "Cliente #$c[cli_id] $c[cli_nome] · Tel: $c[cli_phone]\n";

    if (empty($c['cli_phone'])) {
        echo "  ✗ Sem telefone — pulado\n";
        $erro++;
        continue;
    }

    $primeiroNome = explode(' ', trim($c['cli_nome']))[0];
    $primeiroNome = mb_convert_case(mb_strtolower($primeiroNome, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
    $assuntos = array_unique(array_map('trim', explode('||', $c['assuntos'])));
    $n = (int)$c['total_nao_lidas'];

    // Monta mensagem
    if (count($assuntos) === 1) {
        $refConversa = 'sua conversa *"' . $assuntos[0] . '"*';
    } else {
        $refConversa = 'suas conversas: ' . implode(', ', array_map(function($a){ return '*"' . $a . '"*'; }, $assuntos));
    }

    $msg = "Olá, {$primeiroNome}! Tudo bem? 😊\n\n"
         . "Você tem *{$n} nova(s) mensagem(ns)* aguardando leitura na sua *Central VIP*, referente a {$refConversa}.\n\n"
         . "Acesse pra visualizar:\n"
         . "🔒 https://ferreiraesa.com.br/salavip\n\n"
         . "_Login: seu CPF · Senha: definida no primeiro acesso (se esqueceu, use 'Esqueci minha senha')._\n\n"
         . "Qualquer dúvida, estamos à disposição!\n"
         . "Equipe Ferreira e Sá Advocacia";

    // Envia pelo canal 24 (CX/Operacional)
    $r = zapi_send_text('24', $c['cli_phone'], $msg);
    if (!empty($r['ok'])) {
        echo "  ✓ Enviado com sucesso (n_msg=$n, n_threads=$c[n_threads])\n";
        $sucesso++;
    } else {
        echo "  ✗ Falha: " . ($r['erro'] ?? 'desconhecido') . "\n";
        $erro++;
    }
}

echo "\n=== RESUMO ===\n";
echo "Sucesso: $sucesso\n";
echo "Erro: $erro\n";
