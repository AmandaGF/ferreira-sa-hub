<?php
/**
 * Debug — Mostra o último payload/retorno da Fábrica de Petições
 * Acesso restrito por chave
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: application/json; charset=utf-8');

$logFile = __DIR__ . '/../../uploads/peticao_last_response.json';

if (!file_exists($logFile)) {
    echo json_encode(array('msg' => 'Nenhuma chamada registrada ainda. Gere uma petição primeiro.'));
    exit;
}

// Retornar o log completo
echo file_get_contents($logFile);
