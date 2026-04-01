<?php
/**
 * Migração Firebase Firestore → Conecta MySQL
 * Recupera TODOS os documentos das coleções de formulários do Firebase
 * e insere em form_submissions do Conecta, vinculando aos clientes.
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
set_time_limit(300);

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions.php';

$pdo = db();
$dryRun = !isset($_GET['executar']); // Modo simulação por padrão

echo "=== MIGRAÇÃO FIREBASE → CONECTA ===\n";
echo $dryRun ? ">>> MODO SIMULAÇÃO (adicione &executar para rodar) <<<\n\n" : ">>> EXECUTANDO IMPORTAÇÃO <<<\n\n";

// Firebase Firestore REST API
$projectId = 'coleta-clientes';
$baseUrl = 'https://firestore.googleapis.com/v1/projects/' . $projectId . '/databases/(default)/documents/';

// Coleções para migrar
$colecoes = array(
    'convivencia' => 'convivencia',
    'gastos_pensao' => 'gastos_pensao',
    'leads' => 'calculadora_lead',
    'clientes' => 'cadastro_cliente',
    // Tentar variações comuns
    'gastos' => 'gastos_pensao',
    'calculadora' => 'calculadora_lead',
    'cadastro' => 'cadastro_cliente',
    'formularios' => 'convivencia',
);

$totalImportados = 0;
$totalDuplicados = 0;
$totalErros = 0;

foreach ($colecoes as $colecao => $formType) {
    echo "--- Coleção: $colecao (tipo: $formType) ---\n";

    $url = $baseUrl . $colecao . '?pageSize=500';
    $docs = buscarDocumentos($url);

    if ($docs === null) {
        echo "  ERRO ou coleção não existe. Pulando.\n\n";
        continue;
    }

    if (empty($docs)) {
        echo "  Vazia (0 documentos).\n\n";
        continue;
    }

    echo "  Encontrados: " . count($docs) . " documentos\n";

    foreach ($docs as $doc) {
        $docId = basename($doc['name']);
        $campos = isset($doc['fields']) ? $doc['fields'] : array();
        $payload = converterFirestoreParaArray($campos);

        if (empty($payload)) {
            $totalErros++;
            continue;
        }

        // Extrair dados do cliente
        $nome = extrairCampo($payload, array('nome_responsavel', 'client_name', 'nome', 'nome_completo', 'name'));
        $telefone = extrairCampo($payload, array('whatsapp', 'client_phone', 'celular', 'telefone', 'phone'));
        $email = extrairCampo($payload, array('client_email', 'email', 'e-mail'));
        $protocolo = extrairCampo($payload, array('protocolo', 'protocol'));

        if (!$nome && !$telefone) {
            echo "  [SKIP] Doc $docId — sem nome nem telefone\n";
            $totalErros++;
            continue;
        }

        // Verificar duplicado (por protocolo ou por nome+tipo+data)
        $isDuplicate = false;
        if ($protocolo) {
            $chk = $pdo->prepare("SELECT id FROM form_submissions WHERE protocol = ? LIMIT 1");
            $chk->execute(array($protocolo));
            if ($chk->fetch()) $isDuplicate = true;
        }
        if (!$isDuplicate && $nome) {
            // Verificar por nome + tipo (evitar duplicar migrados anteriores)
            $chk = $pdo->prepare("SELECT id FROM form_submissions WHERE form_type = ? AND client_name = ? LIMIT 1");
            $chk->execute(array($formType, $nome));
            if ($chk->fetch()) $isDuplicate = true;
        }

        if ($isDuplicate) {
            $totalDuplicados++;
            continue;
        }

        // Gerar protocolo se não tem
        if (!$protocolo) {
            $protocolo = strtoupper(substr($formType, 0, 3)) . '-' . substr(md5($docId . json_encode($payload)), 0, 10);
        }

        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);

        // Extrair created_at do Firestore (campo createTime ou created_at no payload)
        $createdAt = null;
        if (isset($doc['createTime'])) {
            $createdAt = date('Y-m-d H:i:s', strtotime($doc['createTime']));
        }
        $createdAtPayload = extrairCampo($payload, array('created_at', 'data_envio', 'timestamp'));
        if (!$createdAt && $createdAtPayload) {
            $ts = strtotime($createdAtPayload);
            if ($ts) $createdAt = date('Y-m-d H:i:s', $ts);
        }
        if (!$createdAt) $createdAt = date('Y-m-d H:i:s');

        if ($dryRun) {
            echo "  [IMPORTAR] $nome | $telefone | $formType | $protocolo | $createdAt\n";
        } else {
            try {
                // Inserir form_submission
                $stmt = $pdo->prepare(
                    "INSERT INTO form_submissions (form_type, protocol, client_name, client_email, client_phone, status, payload_json, ip_address, created_at)
                     VALUES (?, ?, ?, ?, ?, 'novo', ?, 'firebase', ?)"
                );
                $stmt->execute(array($formType, $protocolo, $nome, $email, $telefone, $payloadJson, $createdAt));
                $submissionId = (int)$pdo->lastInsertId();

                // Vincular ao cliente
                if ($nome) {
                    $clientId = null;
                    // Buscar por telefone (8 últimos dígitos)
                    if ($telefone) {
                        $phoneLast8 = substr(preg_replace('/\D/', '', $telefone), -8);
                        if (strlen($phoneLast8) >= 8) {
                            $chk = $pdo->prepare("SELECT id FROM clients WHERE phone LIKE ? LIMIT 1");
                            $chk->execute(array('%' . $phoneLast8));
                            $row = $chk->fetch();
                            if ($row) $clientId = (int)$row['id'];
                        }
                    }
                    // Buscar por nome exato
                    if (!$clientId) {
                        $chk = $pdo->prepare("SELECT id FROM clients WHERE name = ? LIMIT 1");
                        $chk->execute(array($nome));
                        $row = $chk->fetch();
                        if ($row) $clientId = (int)$row['id'];
                    }

                    if ($clientId) {
                        $pdo->prepare("UPDATE form_submissions SET linked_client_id = ? WHERE id = ?")
                            ->execute(array($clientId, $submissionId));
                    }
                }

                echo "  [OK] #$submissionId — $nome\n";
            } catch (Exception $e) {
                echo "  [ERRO] $nome — " . $e->getMessage() . "\n";
                $totalErros++;
                continue;
            }
        }

        $totalImportados++;
    }

    echo "\n";
}

echo "=== RESUMO ===\n";
echo "Importados: $totalImportados\n";
echo "Duplicados (pulados): $totalDuplicados\n";
echo "Erros: $totalErros\n";
if ($dryRun) echo "\n>>> Para executar: adicione &executar <<<\n";
echo "\n=== FIM ===\n";

// ═══════════════════════════════════════════════════════
// FUNÇÕES AUXILIARES
// ═══════════════════════════════════════════════════════

function buscarDocumentos($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'FES-Migration/1.0',
    ));
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) return null;

    $data = json_decode($response, true);
    if (!$data || !isset($data['documents'])) return array();

    $allDocs = $data['documents'];

    // Paginação
    while (isset($data['nextPageToken'])) {
        $nextUrl = $url . '&pageToken=' . $data['nextPageToken'];
        $ch = curl_init($nextUrl);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'FES-Migration/1.0',
        ));
        $response = curl_exec($ch);
        curl_close($ch);
        $data = json_decode($response, true);
        if ($data && isset($data['documents'])) {
            $allDocs = array_merge($allDocs, $data['documents']);
        } else {
            break;
        }
    }

    return $allDocs;
}

function converterFirestoreParaArray($fields) {
    $result = array();
    foreach ($fields as $key => $value) {
        $result[$key] = converterValorFirestore($value);
    }
    return $result;
}

function converterValorFirestore($value) {
    if (isset($value['stringValue'])) return $value['stringValue'];
    if (isset($value['integerValue'])) return (int)$value['integerValue'];
    if (isset($value['doubleValue'])) return (float)$value['doubleValue'];
    if (isset($value['booleanValue'])) return $value['booleanValue'];
    if (isset($value['nullValue'])) return null;
    if (isset($value['timestampValue'])) return $value['timestampValue'];
    if (isset($value['mapValue']) && isset($value['mapValue']['fields'])) {
        return converterFirestoreParaArray($value['mapValue']['fields']);
    }
    if (isset($value['arrayValue']) && isset($value['arrayValue']['values'])) {
        $arr = array();
        foreach ($value['arrayValue']['values'] as $v) {
            $arr[] = converterValorFirestore($v);
        }
        return $arr;
    }
    // Valor desconhecido — retornar como string
    return json_encode($value);
}

function extrairCampo($payload, $possiveisNomes) {
    foreach ($possiveisNomes as $nome) {
        if (isset($payload[$nome]) && is_string($payload[$nome]) && trim($payload[$nome]) !== '') {
            return trim($payload[$nome]);
        }
    }
    return null;
}
