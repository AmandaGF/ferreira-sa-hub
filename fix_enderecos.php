<?php
/**
 * Corrigir endereços com cidade/UF faltantes
 * Extrai cidade e UF do campo address_street quando estão concatenados
 * e também busca via CEP quando disponível
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave invalida'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';

header('Content-Type: text/plain; charset=utf-8');
$pdo = db();
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'test';

echo "=== CORRIGIR ENDERECOS SEM CIDADE/UF ===\n";
echo "Modo: " . strtoupper($mode) . "\n\n";

// Buscar clientes que têm endereço mas não têm cidade ou UF
$stmt = $pdo->query("SELECT id, name, address_street, address_city, address_state, address_zip
    FROM clients
    WHERE address_street IS NOT NULL AND address_street != ''
    AND (address_city IS NULL OR address_city = '' OR address_state IS NULL OR address_state = '')
    ORDER BY name");
$clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Clientes com endereço mas sem cidade/UF: " . count($clients) . "\n\n";

$updated = 0;
$viaCepOk = 0;
$parseOk = 0;
$noFix = 0;

if ($mode === 'run') $pdo->beginTransaction();

foreach ($clients as $c) {
    $city = '';
    $state = '';
    $newStreet = $c['address_street'];

    // Método 1: Buscar via CEP (mais confiável)
    $cep = preg_replace('/\D/', '', $c['address_zip']);
    if (strlen($cep) === 8) {
        $ctx = stream_context_create(array('http' => array('timeout' => 5)));
        $json = @file_get_contents('https://viacep.com.br/ws/' . $cep . '/json/', false, $ctx);
        if ($json) {
            $data = json_decode($json, true);
            if ($data && !isset($data['erro'])) {
                $city = $data['localidade'];
                $state = $data['uf'];
                $viaCepOk++;
            }
        }
        usleep(200000); // 200ms entre requests
    }

    // Método 2: Tentar parsear do campo address_street (ex: "Rua X, nº Y, Bairro, Cidade/UF")
    if (empty($city)) {
        $street = $c['address_street'];
        // Padrão: ..., Cidade/UF
        if (preg_match('/,\s*([^,]+)\/([A-Z]{2})\s*$/', $street, $m)) {
            $city = trim($m[1]);
            $state = trim($m[2]);
            // Remover cidade/UF do endereço
            $newStreet = trim(preg_replace('/,\s*[^,]+\/[A-Z]{2}\s*$/', '', $street));
            $parseOk++;
        }
    }

    if (empty($city) || empty($state)) {
        echo "SEM FIX: #{$c['id']} {$c['name']} | {$c['address_street']} | CEP: {$c['address_zip']}\n";
        $noFix++;
        continue;
    }

    echo "FIX: #{$c['id']} {$c['name']} → cidade={$city} UF={$state}\n";

    if ($mode === 'run') {
        $params = array($city, $state);
        $sql = "UPDATE clients SET address_city = ?, address_state = ?";
        if ($newStreet !== $c['address_street']) {
            $sql .= ", address_street = ?";
            $params[] = $newStreet;
        }
        $sql .= ", updated_at = NOW() WHERE id = ?";
        $params[] = $c['id'];
        $pdo->prepare($sql)->execute($params);
    }
    $updated++;
}

if ($mode === 'run') {
    $pdo->commit();
    echo "\nCOMMIT realizado.\n";
}

echo "\n=== RESULTADO ===\n";
echo "Total analisados: " . count($clients) . "\n";
echo "Corrigidos: $updated (via CEP: $viaCepOk, via parse: $parseOk)\n";
echo "Sem correção: $noFix\n";
