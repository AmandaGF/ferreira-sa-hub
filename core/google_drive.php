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

/**
 * Pega ID de subpasta dentro de uma pasta-pai, criando se nao existir.
 * Apps Script handler: action=getOrCreateSubfolder
 *
 * @param string $parentFolderUrl URL completa da pasta pai (ex: https://drive.google.com/drive/folders/ABC)
 * @param string $subfolderName Nome da subpasta (ex: "01 - PARA DISTRIBUIR")
 * @return array ['success' => bool, 'folderId' => ?, 'created' => bool, 'error' => ?]
 */
function drive_get_or_create_subfolder($parentFolderUrl, $subfolderName) {
    if (!defined('GOOGLE_APPS_SCRIPT_URL') || !GOOGLE_APPS_SCRIPT_URL) {
        return array('success' => false, 'error' => 'GOOGLE_APPS_SCRIPT_URL nao configurado');
    }
    $parentId = '';
    if (preg_match('/folders\/([a-zA-Z0-9_-]+)/', $parentFolderUrl, $m)) {
        $parentId = $m[1];
    } else {
        return array('success' => false, 'error' => 'URL da pasta pai invalida');
    }
    $payload = json_encode(array(
        'action'         => 'getOrCreateSubfolder',
        'parentFolderId' => $parentId,
        'subfolderName'  => $subfolderName,
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
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($err) return array('success' => false, 'error' => 'cURL: ' . $err);
    $data = json_decode($resp, true);
    if ($http === 200 && !empty($data['folderId'])) {
        return array(
            'success'  => true,
            'folderId' => $data['folderId'],
            'created'  => !empty($data['created']),
        );
    }
    return array('success' => false, 'error' => 'HTTP ' . $http . ': ' . $resp);
}

/**
 * Lista nomes de arquivos dentro de uma pasta (so nomes, sem metadados).
 * Apps Script handler: action=listFilesInFolder
 *
 * Usado pra calcular proximo numero disponivel quando renomeando docs
 * (ex: ja existe comprovante_renda_1.pdf e _2.pdf -> proximo e' _3.pdf).
 *
 * @param string $folderId ID da pasta no Drive
 * @return array ['success' => bool, 'files' => [nomes], 'error' => ?]
 */
function drive_list_files_in_folder($folderId) {
    if (!defined('GOOGLE_APPS_SCRIPT_URL') || !GOOGLE_APPS_SCRIPT_URL) {
        return array('success' => false, 'error' => 'GOOGLE_APPS_SCRIPT_URL nao configurado');
    }
    if (!$folderId) {
        return array('success' => false, 'error' => 'folderId vazio');
    }
    $payload = json_encode(array(
        'action'   => 'listFilesInFolder',
        'folderId' => $folderId,
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
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($err) return array('success' => false, 'error' => 'cURL: ' . $err);
    $data = json_decode($resp, true);
    if ($http === 200 && isset($data['files']) && is_array($data['files'])) {
        return array(
            'success' => true,
            'files'   => $data['files'],
        );
    }
    return array('success' => false, 'error' => 'HTTP ' . $http . ': ' . $resp);
}

/**
 * Upload de arquivo via base64 (pra PDFs gerados localmente, sem URL publica).
 * Apps Script handler: action=uploadFileBase64
 *
 * @param string $folderId ID da pasta destino
 * @param string $fileName Nome do arquivo final
 * @param string $base64Content Conteudo do arquivo em base64
 * @param string $mimeType MIME type (ex: application/pdf)
 * @return array ['success' => bool, 'fileId' => ?, 'fileUrl' => ?, 'error' => ?]
 */
function upload_file_to_drive_base64($folderId, $fileName, $base64Content, $mimeType = 'application/pdf') {
    if (!defined('GOOGLE_APPS_SCRIPT_URL') || !GOOGLE_APPS_SCRIPT_URL) {
        return array('success' => false, 'error' => 'GOOGLE_APPS_SCRIPT_URL nao configurado');
    }
    $payload = json_encode(array(
        'action'        => 'uploadFileBase64',
        'folderId'      => $folderId,
        'fileName'      => $fileName,
        'contentBase64' => $base64Content,
        'mimeType'      => $mimeType,
    ));
    $ch = curl_init(GOOGLE_APPS_SCRIPT_URL);
    curl_setopt_array($ch, array(
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => array('Content-Type: application/json'),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 90,
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

/**
 * Calcula proximo nome com numero disponivel pra um tipo de documento.
 * Ex: ja existe comprovante_renda_1.pdf e _2.pdf na subpasta -> retorna comprovante_renda_3.pdf
 *
 * Se o prefixo NAO usa numeracao (ex: 'comprovante_residencia'), retorna o nome direto
 * SE nao existir. Se ja existe, adiciona _2, _3, etc.
 *
 * @param string $folderId ID da pasta onde checar
 * @param string $prefixo Base do nome (sem extensao). Ex: 'comprovante_renda'
 * @param string $extensao Ex: 'pdf', 'jpg', 'ogg'
 * @param bool $forcarNumeracao Se true, sempre adiciona _N mesmo pro primeiro
 * @return string Nome final calculado (ja com extensao)
 */
function drive_calcular_nome_disponivel($folderId, $prefixo, $extensao, $forcarNumeracao = false) {
    $resultado = drive_list_files_in_folder($folderId);
    $arquivos = ($resultado['success'] && !empty($resultado['files'])) ? $resultado['files'] : array();

    // Procura existentes com este prefixo
    $padrao = '/^' . preg_quote($prefixo, '/') . '(_(\d+))?\.' . preg_quote($extensao, '/') . '$/i';
    $numerosUsados = array();
    $existeSemNumero = false;
    foreach ($arquivos as $nomeArquivo) {
        if (preg_match($padrao, $nomeArquivo, $m)) {
            if (!empty($m[2])) {
                $numerosUsados[] = (int)$m[2];
            } else {
                $existeSemNumero = true;
            }
        }
    }

    if (!$forcarNumeracao && !$existeSemNumero && empty($numerosUsados)) {
        // Primeiro arquivo desse tipo, sem numero
        return $prefixo . '.' . $extensao;
    }

    // Calcula proximo N (1 se ainda nao existe nenhum numerado e ja tem o "sem numero")
    $proximo = 1;
    if (!empty($numerosUsados)) {
        $proximo = max($numerosUsados) + 1;
    } elseif ($existeSemNumero) {
        // Ja tem prefixo.ext, comeca em _2
        $proximo = 2;
    }
    return $prefixo . '_' . $proximo . '.' . $extensao;
}
