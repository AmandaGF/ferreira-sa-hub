<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('x'); }
@header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors', '1');
error_reporting(E_ALL);

echo "BACKFILL CONVIVENCIA — início\n\n";

// === PASSO 1: ler do banco antigo, sem usar o db() do Hub ===
$rows = array();
try {
    require_once dirname(__DIR__) . '/convivencia_form/config.php';
    $pdoOld = pdo();
    $rows = $pdoOld->query("SELECT * FROM intake_visitas WHERE created_at > '2026-04-01 23:59:59' ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    echo "[1] Lidas " . count($rows) . " linhas do banco antigo.\n\n";
    $pdoOld = null; // fecha conexão
} catch (Throwable $e) {
    echo "[1 ERRO] " . $e->getMessage() . "\n";
    exit;
}

// === PASSO 2: conectar no Hub via PDO direto (evita self-heal do db()) ===
set_time_limit(120);
require_once __DIR__ . '/core/config.php';
$pdoHub = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    array(
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    )
);
echo "[2] Conectado no banco Hub via PDO direto.\n\n";
@ob_flush(); flush();

// === PASSO 3: cleanup do teste ===
echo "[3] iniciando cleanup...\n"; @ob_flush(); flush();
try {
    $stmtDel = $pdoHub->prepare("DELETE FROM form_submissions WHERE id = ?");
    $stmtDel->execute(array(525));
    echo "[3] Cleanup #525: removidos " . $stmtDel->rowCount() . "\n\n";
} catch (Exception $e) {
    echo "[3 ERRO cleanup] " . $e->getMessage() . "\n\n";
}
@ob_flush(); flush();

// === PASSO 4: insert das que faltam ===
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
    $name = $v['client_name'];
    echo "[#{$v['id']}] [{$v['protocol']}] {$v['created_at']} — {$name}\n";

    // Já tem?
    $chk->execute(array('%' . $v['protocol'] . '%'));
    if ($chk->fetchColumn()) { echo "  já existe, skip\n"; $ja++; continue; }

    // Cliente?
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
