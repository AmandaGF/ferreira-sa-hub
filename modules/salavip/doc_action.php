<?php
/**
 * Aceitar / Rejeitar documento enviado pelo cliente via Central VIP.
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
if (!has_min_role('gestao')) { flash_set('error', 'Acesso restrito.'); redirect(url('modules/salavip/')); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect(url('modules/salavip/')); }
if (!validate_csrf()) { flash_set('error', 'Token inválido.'); redirect(url('modules/salavip/')); }

$pdo = db();
$docId  = (int)($_POST['doc_id'] ?? 0);
$acao   = $_POST['acao'] ?? '';

if (!$docId || !in_array($acao, array('aceitar','rejeitar'), true)) {
    flash_set('error', 'Parâmetros inválidos.');
    redirect(url('modules/salavip/'));
}

$novoStatus = $acao === 'aceitar' ? 'aceito' : 'rejeitado';
$pdo->prepare("UPDATE salavip_documentos_cliente SET status = ? WHERE id = ?")
    ->execute(array($novoStatus, $docId));

audit_log('salavip_doc_cliente_' . $acao, 'salavip_documentos_cliente', $docId);
flash_set('success', 'Documento ' . ($acao === 'aceitar' ? 'aceito' : 'rejeitado') . ' com sucesso.');
redirect(url('modules/salavip/'));
