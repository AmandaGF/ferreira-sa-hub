<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('x'); }
@header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors', '1');
error_reporting(E_ALL);
set_time_limit(120);

echo "BACKFILL CONVIVENCIA\n\n";

// === PASSO 0: pegar credenciais do Hub PRIMEIRO ===
// (config.php do banco antigo tb usa define() — se carregar antes, as constantes
// do Hub não conseguem mais ser definidas, e tudo conecta no banco errado)
require_once __DIR__ . '/core/config.php';
$hubHost = DB_HOST;
$hubName = DB_NAME;
$hubUser = DB_USER;
$hubPass = DB_PASS;

$pdoHub = new PDO(
    "mysql:host={$hubHost};dbname={$hubName};charset=utf8mb4",
    $hubUser, $hubPass,
    array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC)
);
echo "[1] Hub conectado: {$hubName}\n";

// === PASSO 1: ler banco antigo via PDO direto (parseando credenciais) ===
echo "  reading config..."; @ob_flush(); flush();
$confPath = dirname(__DIR__) . '/convivencia_form/config.php';
$confSrc = @file_get_contents($confPath);
echo " ok " . strlen((string)$confSrc) . " bytes\n"; @ob_flush(); flush();

$dbVars = array('host'=>null,'name'=>null,'user'=>null,'pass'=>null);
foreach (array('host'=>'DB_HOST','name'=>'DB_NAME','user'=>'DB_USER','pass'=>'DB_PASS') as $k=>$const) {
    // Aceita: define('NOME','VALOR') OU NOME='VALOR' OU $NOME='VALOR' OU const NOME='VALOR'
    if (preg_match("/(?:define\(\s*['\"]" . $const . "['\"]\s*,\s*|\b\\\$?" . $const . "\s*=\s*|\bconst\s+" . $const . "\s*=\s*)['\"]([^'\"]+)['\"]/", $confSrc, $m)) {
        $dbVars[$k] = $m[1];
    }
}
echo "  parsed: host={$dbVars['host']} db={$dbVars['name']} user={$dbVars['user']} pass=" . ($dbVars['pass'] ? '***' : 'null') . "\n";
@ob_flush(); flush();
$pdoOld = new PDO(
    "mysql:host={$dbVars['host']};dbname={$dbVars['name']};charset=utf8mb4",
    $dbVars['user'], $dbVars['pass'],
    array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC)
);
$rows = $pdoOld->query("SELECT * FROM intake_visitas WHERE created_at > '2026-04-01 23:59:59' ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
echo "[2] Banco antigo ({$dbVars['name']}) lido: " . count($rows) . " linhas após 01/04\n\n";
$pdoOld = null;

// === PASSO 2: cleanup ===
try {
    $stmtDel = $pdoHub->prepare("DELETE FROM form_submissions WHERE id = 525");
    $stmtDel->execute();
    echo "[3] Cleanup #525: removidos " . $stmtDel->rowCount() . "\n\n";
} catch (Exception $e) {
    echo "[3 erro] " . $e->getMessage() . "\n\n";
}

// === PASSO 3: insert ===
$migrados = 0; $ja = 0; $erros = 0;
$ins = $pdoHub->prepare(
    "INSERT INTO form_submissions (form_type, protocol, payload_json, ip_address, user_agent, created_at, linked_client_id)
     VALUES ('convivencia', ?, ?, ?, ?, ?, ?)"
);
$chk = $pdoHub->prepare("SELECT id FROM form_submissions WHERE form_type='convivencia' AND payload_json LIKE ?");
$findClient = $pdoHub->prepare(
    "SELECT id FROM clients WHERE
        REPLACE(REPLACE(REPLACE(REPLACE(phone,' ',''),'(',''),')',''),'-','') LIKE ?
     ORDER BY id LIMIT 1"
);

foreach ($rows as $v) {
    echo "[#{$v['id']}] [{$v['protocol']}] {$v['created_at']} — {$v['client_name']}\n";

    $chk->execute(array('%' . $v['protocol'] . '%'));
    if ($chk->fetchColumn()) { echo "  já existe, skip\n"; $ja++; continue; }

    $phoneClean = preg_replace('/\D/', '', (string)$v['client_phone']);
    $clientId = null;
    if (strlen($phoneClean) >= 9) {
        $findClient->execute(array('%' . substr($phoneClean, -9)));
        $clientId = $findClient->fetchColumn() ?: null;
    }

    $answers = json_decode((string)$v['answers_json'], true) ?: array();
    $payload = array_merge(array(
        'client_name'       => $v['client_name'],
        'client_phone'      => $v['client_phone'],
        'client_email'      => $v['client_email'],
        'child_name'        => $v['child_name'],
        'child_age'         => (int)$v['child_age'],
        'relationship_role' => $v['relationship_role'],
        'protocol_original' => $v['protocol'],
    ), $answers);

    $newProto = 'CON-' . strtoupper(bin2hex(random_bytes(5)));
    try {
        $ins->execute(array(
            $newProto,
            json_encode($payload, JSON_UNESCAPED_UNICODE),
            $v['ip_address'] ?? null,
            $v['user_agent'] ?? null,
            $v['created_at'],
            $clientId,
        ));
        $newId = (int)$pdoHub->lastInsertId();
        echo "  ✓ Hub #{$newId} ({$newProto}) — client_id=" . ($clientId ?: 'null') . "\n";
        $migrados++;
    } catch (Exception $e) {
        echo "  ✗ ERRO: " . $e->getMessage() . "\n";
        $erros++;
    }
}

echo "\n=== RESUMO ===\nMigrados: {$migrados} | Já existentes: {$ja} | Erros: {$erros}\n";
