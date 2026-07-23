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

/**
 * Amanda 07/07/2026: PJE não aceita travessão (—, U+2014) nem en dash (–)
 * em nome de arquivo. Padronizamos qualquer variação de traço "elegante"
 * pra hífen simples ANTES de mandar pro Drive. Blindagem em ponto único —
 * garante que qualquer upload passa por aqui.
 *
 * Substituições:
 *   —  (em dash)   → -
 *   –  (en dash)   → -
 *   ―  (horiz bar) → -
 *   ‒  (figure dash) → -
 *   −  (minus sign)  → -
 *
 * Espaços múltiplos são colapsados. Trim final.
 */
function _drive_sanitize_filename($nome) {
    if (!is_string($nome) || $nome === '') return $nome;
    $nome = strtr($nome, array(
        "\xE2\x80\x94" => '-', // — em dash
        "\xE2\x80\x93" => '-', // – en dash
        "\xE2\x80\x92" => '-', // ‒ figure dash
        "\xE2\x80\x95" => '-', // ― horizontal bar
        "\xE2\x88\x92" => '-', // − minus sign
    ));
    // Também remove caracteres de controle invisíveis (u+200b zero width space, etc)
    $nome = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $nome);
    // Colapsa múltiplos hífens/espaços
    $nome = preg_replace('/-{2,}/', '-', $nome);
    $nome = preg_replace('/\s{2,}/', ' ', $nome);
    return trim($nome);
}

/**
 * Amanda 03/07: helper de POST com retry — Apps Script tem cold start, e o
 * LiteSpeed WAF do TurboCloud as vezes fecha SSL antes de completar. Retry
 * com backoff resolve os erros esporádicos "timed out after 949 milliseconds".
 *
 * @param string $payload JSON stringificado
 * @param int $timeout Timeout total em segundos (default 30)
 * @param int $tentativas Nº de tentativas (default 3)
 * @return array {resp: string, http: int, err: string}
 */
function _drive_post_com_retry($payload, $timeout = 30, $tentativas = 3) {
    $ultima = array('resp' => '', 'http' => 0, 'err' => 'não tentado');
    for ($i = 1; $i <= $tentativas; $i++) {
        $ch = curl_init(GOOGLE_APPS_SCRIPT_URL);
        curl_setopt_array($ch, array(
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => array('Content-Type: application/json'),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TCP_KEEPALIVE  => 1,
            CURLOPT_TCP_KEEPIDLE   => 30,
            CURLOPT_USERAGENT      => 'FES-Hub/1.0',
        ));
        $resp = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        $ultima = array('resp' => $resp, 'http' => $http, 'err' => $err);
        // Sucesso: retorna direto
        if (!$err && $http >= 200 && $http < 400) return $ultima;
        // Falhas transient (timeout, SSL, network) — retry com backoff exponencial
        $ehTransient = ($err && (
            stripos($err, 'timed out') !== false ||
            stripos($err, 'timeout') !== false ||
            stripos($err, 'ssl') !== false ||
            stripos($err, 'connect') !== false ||
            stripos($err, 'network') !== false
        )) || $http === 0 || $http === 502 || $http === 503 || $http === 504;
        if (!$ehTransient) return $ultima; // erro definitivo, nao adianta retry
        if ($i < $tentativas) {
            // Backoff: 500ms, 1500ms, 3000ms
            usleep($i * 500 * 1000);
        }
    }
    return $ultima;
}

function create_drive_folder($clientName, $caseType, $caseId, $caseTitle = '') {
    // Verificar se a URL do Apps Script está configurada
    if (!defined('GOOGLE_APPS_SCRIPT_URL') || !GOOGLE_APPS_SCRIPT_URL) {
        return array('success' => false, 'error' => 'GOOGLE_APPS_SCRIPT_URL não configurado no config.php');
    }

    // Bloqueio PJE: travessão (—/–) recusado em nome de arquivo/pasta.
    // Aplica antes de enviar pro Apps Script (pasta + subpastas ficam limpas)
    $clientName = _drive_sanitize_filename($clientName);
    $caseType   = _drive_sanitize_filename($caseType);
    $caseTitle  = _drive_sanitize_filename($caseTitle);

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

    // Bloqueio PJE: travessão (—/–) recusado em nome de arquivo
    $fileName = _drive_sanitize_filename($fileName);

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
 * Renomeia uma pasta do Drive. NAO muda o ID/URL da pasta (o link continua
 * valido). Apps Script handler: action=renameFolder (folderId + newName).
 *
 * @param string $folderIdOrUrl ID ou URL completa da pasta
 * @param string $newName Novo nome
 * @return array ['success'=>bool, 'title'=>?, 'error'=>?]
 */
function rename_drive_folder($folderIdOrUrl, $newName) {
    if (!defined('GOOGLE_APPS_SCRIPT_URL') || !GOOGLE_APPS_SCRIPT_URL) {
        return array('success' => false, 'error' => 'GOOGLE_APPS_SCRIPT_URL não configurado');
    }
    $folderId = $folderIdOrUrl;
    if (preg_match('/folders\/([a-zA-Z0-9_-]+)/', $folderIdOrUrl, $m)) $folderId = $m[1];
    $newName = _drive_sanitize_filename($newName);
    if ($folderId === '' || $newName === '') {
        return array('success' => false, 'error' => 'folderId ou newName vazio');
    }
    $payload = json_encode(array(
        'action'   => 'renameFolder',
        'folderId' => $folderId,
        'newName'  => $newName,
    ));
    $r = _drive_post_com_retry($payload, 30, 3);
    if ($r['err']) return array('success' => false, 'error' => 'cURL: ' . $r['err']);
    $data = json_decode($r['resp'], true);
    if ($r['http'] === 200 && !empty($data['ok'])) {
        return array('success' => true, 'title' => $data['title'] ?? $newName);
    }
    return array('success' => false, 'error' => 'HTTP ' . $r['http'] . ': ' . $r['resp']);
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
    $r = _drive_post_com_retry($payload, 30, 3);
    if ($r['err']) return array('success' => false, 'error' => 'cURL: ' . $r['err']);
    $data = json_decode($r['resp'], true);
    if ($r['http'] === 200 && !empty($data['folderId'])) {
        return array(
            'success'  => true,
            'folderId' => $data['folderId'],
            'created'  => !empty($data['created']),
        );
    }
    // Amanda 03/07: mensagem clara pra caso comum de "pasta pai deletada".
    // Apps Script retorna Exception getFolderById quando o parentFolderId
    // aponta pra pasta que foi apagada/movida pra lixeira no Drive.
    $errApps = isset($data['error']) ? (string)$data['error'] : '';
    if (stripos($errApps, 'getFolderById') !== false || stripos($errApps, 'not found') !== false) {
        return array(
            'success' => false,
            'error'   => 'A pasta principal deste processo foi APAGADA do Google Drive. Restaure da lixeira (drive.google.com/drive/trash) ou clique em "Criar pasta no Drive" na tela do caso pra gerar uma nova.',
        );
    }
    return array('success' => false, 'error' => 'HTTP ' . $r['http'] . ': ' . substr((string)$r['resp'], 0, 300));
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
    // Bloqueio PJE: travessão (—/–) recusado em nome de arquivo
    $fileName = _drive_sanitize_filename($fileName);
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
