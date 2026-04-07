<?php
/**
 * Reprocessar formulários de cadastro_cliente já preenchidos
 * Atualiza dados faltantes nos clientes a partir do payload JSON salvo
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave invalida'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions.php';

header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

$mode = isset($_GET['mode']) ? $_GET['mode'] : 'test';
echo "=== REPROCESSAR FORMULARIOS CADASTRO ===\n";
echo "Modo: " . strtoupper($mode) . "\n";
echo "Data: " . date('Y-m-d H:i:s') . "\n\n";

// Buscar todos os formulários de cadastro_cliente que têm cliente vinculado
$stmt = $pdo->query("SELECT fs.id, fs.protocol, fs.linked_client_id, fs.payload_json, fs.client_name
    FROM form_submissions fs
    WHERE fs.form_type = 'cadastro_cliente'
    AND fs.linked_client_id IS NOT NULL
    AND fs.payload_json IS NOT NULL
    ORDER BY fs.id ASC");
$submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Total de formulários de cadastro: " . count($submissions) . "\n\n";

$updated = 0;
$skipped = 0;
$errors = 0;

if ($mode === 'run') {
    $pdo->beginTransaction();
}

foreach ($submissions as $sub) {
    $clientId = (int)$sub['linked_client_id'];
    $payload = json_decode($sub['payload_json'], true);
    if (!$payload) {
        echo "ERRO: JSON inválido no formulário #{$sub['id']} ({$sub['client_name']})\n";
        $errors++;
        continue;
    }

    // Buscar cliente atual
    $cStmt = $pdo->prepare("SELECT * FROM clients WHERE id = ?");
    $cStmt->execute(array($clientId));
    $client = $cStmt->fetch(PDO::FETCH_ASSOC);
    if (!$client) {
        echo "ERRO: Cliente #{$clientId} não encontrado para formulário #{$sub['id']}\n";
        $errors++;
        continue;
    }

    // Mapear campos do payload para campos do cliente
    $fillMap = array();

    // CPF
    $cpf = isset($payload['cpf']) ? trim($payload['cpf']) : '';
    if ($cpf && (empty($client['cpf']))) $fillMap['cpf'] = $cpf;

    // RG
    $rg = isset($payload['rg']) ? trim($payload['rg']) : '';
    if ($rg && (empty($client['rg']))) $fillMap['rg'] = $rg;

    // Nascimento
    $nasc = isset($payload['nascimento']) ? trim($payload['nascimento']) : '';
    if ($nasc && (empty($client['birth_date']))) $fillMap['birth_date'] = $nasc;

    // Profissão
    $prof = isset($payload['profissao']) ? trim($payload['profissao']) : '';
    if ($prof && (empty($client['profession']))) $fillMap['profession'] = $prof;

    // Estado civil
    $ec = isset($payload['estado_civil']) ? trim($payload['estado_civil']) : '';
    if ($ec && (empty($client['marital_status']))) $fillMap['marital_status'] = $ec;

    // Endereço
    $endereco = isset($payload['endereco']) ? trim($payload['endereco']) : '';
    if ($endereco && (empty($client['address_street']))) $fillMap['address_street'] = $endereco;

    // Cidade
    $cidade = isset($payload['cidade']) ? trim($payload['cidade']) : '';
    if ($cidade && (empty($client['address_city']))) $fillMap['address_city'] = $cidade;

    // UF
    $uf = isset($payload['uf']) ? trim($payload['uf']) : '';
    if ($uf && (empty($client['address_state']))) $fillMap['address_state'] = $uf;

    // CEP
    $cep = isset($payload['cep']) ? trim($payload['cep']) : '';
    if ($cep && (empty($client['address_zip']))) $fillMap['address_zip'] = $cep;

    // PIX
    $pix = isset($payload['pix']) ? trim($payload['pix']) : '';
    if ($pix && (empty($client['pix_key']))) $fillMap['pix_key'] = $pix;

    // Email
    $email = isset($payload['email']) ? trim($payload['email']) : '';
    if ($email && (empty($client['email']))) $fillMap['email'] = $email;

    // Celular
    $cel = isset($payload['celular']) ? trim($payload['celular']) : '';
    if ($cel && (empty($client['phone']))) $fillMap['phone'] = $cel;

    // Filhos
    $filhos = isset($payload['filhos']) ? trim($payload['filhos']) : '';
    if ($filhos === 'Sim' && ($client['has_children'] === null || $client['has_children'] === '')) $fillMap['has_children'] = 1;
    if ($filhos === 'Não' && ($client['has_children'] === null || $client['has_children'] === '')) $fillMap['has_children'] = 0;

    $nomeFilhos = isset($payload['nome_filhos']) ? trim($payload['nome_filhos']) : '';
    if ($nomeFilhos && (empty($client['children_names']))) $fillMap['children_names'] = $nomeFilhos;

    if (empty($fillMap)) {
        $skipped++;
        continue;
    }

    // Construir UPDATE
    $sets = array();
    $params = array();
    foreach ($fillMap as $field => $value) {
        $sets[] = "$field = ?";
        $params[] = $value;
    }
    $sets[] = "updated_at = NOW()";
    $params[] = $clientId;

    $sql = "UPDATE clients SET " . implode(', ', $sets) . " WHERE id = ?";

    echo "#{$sub['id']} {$sub['client_name']} (cliente #{$clientId}): ";
    echo implode(', ', array_keys($fillMap)) . "\n";

    if ($mode === 'run') {
        try {
            $pdo->prepare($sql)->execute($params);
            $updated++;
        } catch (Exception $e) {
            echo "  ERRO: " . $e->getMessage() . "\n";
            $errors++;
        }
    } else {
        $updated++;
    }
}

if ($mode === 'run') {
    if ($errors === 0) {
        $pdo->commit();
        echo "\nCOMMIT realizado.\n";
    } else {
        $pdo->rollBack();
        echo "\nROLLBACK - houve erros.\n";
    }
}

echo "\n=== RESULTADO ===\n";
echo "Processados: " . count($submissions) . "\n";
echo "Atualizados: $updated\n";
echo "Sem mudança: $skipped\n";
echo "Erros: $errors\n";
