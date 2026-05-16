<?php
/**
 * Captura de lead do site institucional (lp/v2.php e LPs por área).
 * Público, sem login. POST via fetch → process_form_submission() →
 * cliente (dedup) + pipeline_leads (landing/cadastro_preenchido) +
 * histórico + notifica gestão/comercial + push.
 *
 * Anti-spam: honeypot ("website") + tempo mínimo de preenchimento.
 * Retorna JSON { ok: bool, protocol?, error? }.
 */
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(array('ok' => false, 'error' => 'Método inválido.'));
    exit;
}

// Honeypot: campo escondido que humano não preenche. Bot preencheu → finge OK.
if (trim($_POST['website'] ?? '') !== '') {
    echo json_encode(array('ok' => true, 'protocol' => 'OK'));
    exit;
}
// Tempo mínimo: form carregado há < 2s = robô.
$ts = (int)($_POST['ts'] ?? 0);
if ($ts > 0 && (time() * 1000 - $ts) < 2000) {
    echo json_encode(array('ok' => true, 'protocol' => 'OK'));
    exit;
}

require_once __DIR__ . '/../core/form_handler.php';

$nome  = trim($_POST['nome'] ?? '');
$fone  = trim($_POST['telefone'] ?? '');
$email = trim($_POST['email'] ?? '');
$msg   = trim($_POST['mensagem'] ?? '');
$area  = trim($_POST['area'] ?? '');

if (mb_strlen($nome) < 2) {
    echo json_encode(array('ok' => false, 'error' => 'Informe seu nome.'));
    exit;
}
$foneDigitos = preg_replace('/\D/', '', $fone);
if (strlen($foneDigitos) < 10 && $email === '') {
    echo json_encode(array('ok' => false, 'error' => 'Informe um WhatsApp/telefone válido (com DDD) ou um e-mail.'));
    exit;
}

// Área → formType + rótulo legível (case_type do lead)
$areasMap = array(
    'familia'     => array('site_familia',     'Direito de Família'),
    'sucessoes'   => array('site_sucessoes',   'Sucessões e Inventário'),
    'imobiliario' => array('site_imobiliario', 'Direito Imobiliário'),
    'consumidor'  => array('site_consumidor',  'Direito do Consumidor'),
    'civel'       => array('site_civel',       'Responsabilidade Civil'),
    'contratos'   => array('site_contratos',   'Contratos e Cível'),
);
$slug = strtolower($area);
list($formType, $areaLabel) = isset($areasMap[$slug]) ? $areasMap[$slug] : array('site_lead', '');

$origem = trim($_POST['origem'] ?? 'site');
$notasLead = trim(
    ($areaLabel ? "Área: {$areaLabel}\n" : '') .
    ($msg !== '' ? "Mensagem: {$msg}\n" : '') .
    "Origem: {$origem} (site institucional)"
);

$clientData = array(
    'name'      => $nome,
    'phone'     => $fone ?: null,
    'email'     => $email ?: null,
    'case_type' => $areaLabel ?: 'Contato pelo site',
    'lead_notes'=> $notasLead,
);

$payload = json_encode(array(
    'nome' => $nome, 'telefone' => $fone, 'email' => $email,
    'area' => $slug, 'area_label' => $areaLabel, 'mensagem' => $msg,
    'origem' => $origem,
    'pagina' => substr(trim($_POST['pagina'] ?? ''), 0, 300),
    'utm'    => substr(trim($_POST['utm'] ?? ''), 0, 300),
    'enviado_em' => date('Y-m-d H:i:s'),
), JSON_UNESCAPED_UNICODE);

try {
    $r = process_form_submission($formType, $clientData, $payload);
    echo json_encode(array('ok' => true, 'protocol' => $r['protocol'] ?? 'OK'));
} catch (Throwable $e) {
    @error_log('[lead_site] ' . $e->getMessage());
    echo json_encode(array('ok' => false, 'error' => 'Não foi possível enviar agora. Tente pelo WhatsApp.'));
}
