<?php
/**
 * Despesas Mensais — Submit
 * Grava direto no banco do Conecta (form_submissions)
 */
require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/database.php';

header('Content-Type: application/json; charset=utf-8');

function responder($ok, $msg, $extra = array()) {
    echo json_encode(array_merge(array('ok' => $ok, 'message' => $msg), $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        responder(false, 'Metodo invalido.');
    }

    $conteudo = file_get_contents('php://input');
    $dados = json_decode($conteudo, true);

    if (!is_array($dados)) {
        responder(false, 'Dados invalidos.');
    }

    $nome = trim($dados['nome_responsavel'] ?? '');
    $whatsapp = trim($dados['whatsapp'] ?? '');
    $fonte_renda = trim($dados['fonte_renda'] ?? '');

    if ($nome === '') responder(false, 'Nome do responsavel e obrigatorio.');
    if ($whatsapp === '') responder(false, 'Telefone/WhatsApp e obrigatorio.');
    if ($fonte_renda === '') responder(false, 'Fonte principal de renda e obrigatoria.');

    // Filho pode ser "nao_tenho_filhos"
    $nomeFilho = trim($dados['nome_filho_referente'] ?? '');
    $semFilho = isset($dados['sem_filhos']) && $dados['sem_filhos'] === 'sim';
    if (!$semFilho && $nomeFilho === '') {
        responder(false, 'Informe o nome do filho(a) ou marque que nao tem filhos.');
    }

    $protocolo = 'DSP-' . strtoupper(substr(md5(uniqid((string)mt_rand(), true)), 0, 10));
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $payload_json = json_encode($dados, JSON_UNESCAPED_UNICODE);

    $pdo = db();
    $pdo->prepare(
        "INSERT INTO form_submissions (form_type, protocol, client_name, client_phone, client_email, status, payload_json, ip_address, created_at)
         VALUES (?, ?, ?, ?, '', 'novo', ?, ?, NOW())"
    )->execute(array(
        'despesas_mensais',
        $protocolo,
        $nome,
        $whatsapp,
        $payload_json,
        $ip,
    ));

    responder(true, 'Enviado com sucesso!', array('protocolo' => $protocolo));

} catch (Throwable $e) {
    responder(false, 'Erro interno: ' . $e->getMessage());
}
