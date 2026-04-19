<?php
/**
 * Ferreira & Sá Hub — Integração Google Drive
 *
 * Envia POST para um Google Apps Script que cria a pasta do caso no Drive.
 * O Apps Script deve retornar JSON com { "folderUrl": "https://drive.google.com/..." }
 *
 * Para configurar:
 * 1. Crie um Google Apps Script com doPost() que cria a pasta
 * 2. Faça deploy como Web App (acesso: qualquer pessoa)
 * 3. Coloque a URL no config.php: define('GOOGLE_APPS_SCRIPT_URL', 'https://script.google.com/...');
 */

function create_drive_folder($clientName, $caseType, $caseId, $caseTitle = '') {
    // Verificar se a URL do Apps Script está configurada
    if (!defined('GOOGLE_APPS_SCRIPT_URL') || !GOOGLE_APPS_SCRIPT_URL) {
        return array('success' => false, 'error' => 'GOOGLE_APPS_SCRIPT_URL não configurado no config.php');
    }

    $payload = json_encode(array(
        'folderName'  => $clientName,
        'clientName'  => $clientName,
        'caseType'    => $caseType,
        'caseId'      => $caseId,
        'caseTitle'   => $caseTitle,
        'timestamp'   => date('Y-m-d H:i:s'),
    ));

    $ch = curl_init(GOOGLE_APPS_SCRIPT_URL);
    curl_setopt_array($ch, array(
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => array('Content-Type: application/json'),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ));

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return array('success' => false, 'error' => 'cURL: ' . $error);
    }

    $data = json_decode($response, true);

    if ($httpCode === 200 && isset($data['folderUrl'])) {
        // Salvar URL da pasta no caso
        $pdo = db();
        $pdo->prepare("UPDATE cases SET drive_folder_url = ? WHERE id = ?")
            ->execute(array($data['folderUrl'], $caseId));

        audit_log('drive_folder_created', 'case', $caseId, $data['folderUrl']);

        return array('success' => true, 'folderUrl' => $data['folderUrl']);
    }

    return array('success' => false, 'error' => 'HTTP ' . $httpCode . ': ' . $response);
}

/**
 * Faz upload de um arquivo (via URL pública ou base64) para uma pasta do Drive.
 * O Google Apps Script precisa ter um handler pra action=uploadFile.
 *
 * @param string $folderUrl URL completa da pasta do Drive (ex: https://drive.google.com/drive/folders/ABC123)
 * @param string $fileName  Nome que o arquivo terá no Drive
 * @param string $sourceUrl URL pública do arquivo pra baixar (ex: URL Z-API do arquivo recebido)
 * @param string $mimeType  MIME type (ex: image/jpeg, application/pdf)
 * @return array ['success' => bool, 'fileId' => ?, 'fileUrl' => ?, 'error' => ?]
 */
function upload_file_to_drive($folderUrl, $fileName, $sourceUrl, $mimeType = '') {
    if (!defined('GOOGLE_APPS_SCRIPT_URL') || !GOOGLE_APPS_SCRIPT_URL) {
        return array('success' => false, 'error' => 'GOOGLE_APPS_SCRIPT_URL não configurado');
    }

    // Extrai o ID da pasta da URL completa
    $folderId = '';
    if (preg_match('/folders\/([a-zA-Z0-9_-]+)/', $folderUrl, $m)) {
        $folderId = $m[1];
    } else {
        return array('success' => false, 'error' => 'URL da pasta do Drive inválida: ' . $folderUrl);
    }

    $payload = json_encode(array(
        'action'    => 'uploadFile',
        'folderId'  => $folderId,
        'fileName'  => $fileName,
        'sourceUrl' => $sourceUrl,
        'mimeType'  => $mimeType,
    ));

    $ch = curl_init(GOOGLE_APPS_SCRIPT_URL);
    curl_setopt_array($ch, array(
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => array('Content-Type: application/json'),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => false,
    ));
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) return array('success' => false, 'error' => 'cURL: ' . $err);
    $data = json_decode($resp, true);
    if ($http === 200 && !empty($data['fileId'])) {
        return array(
            'success' => true,
            'fileId'  => $data['fileId'],
            'fileUrl' => $data['fileUrl'] ?? null,
        );
    }
    return array('success' => false, 'error' => 'HTTP ' . $http . ': ' . $resp);
}
