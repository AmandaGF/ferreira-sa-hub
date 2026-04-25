<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('x'); }
@header('Content-Type: text/plain; charset=utf-8');
@ini_set('display_errors', '1');
error_reporting(E_ALL);

try {
    require_once dirname(__DIR__) . '/convivencia_form/config.php';
    $pdoOld = pdo();
} catch (Throwable $e) { echo "[ERRO old] " . $e->getMessage() . "\n"; exit; }

require_once __DIR__ . '/core/database.php';
$pdoHub = db();

echo "\n=== BACKFILL CONVIVENCIA (intake_visitas → form_submissions) ===\n\n";
@ob_flush(); flush();

// 1. Cleanup: tira o teste do diag (#525) se ainda está
echo "Etapa 1...\n"; @ob_flush(); flush();
try {
    $del = $pdoHub->exec("DELETE FROM form_submissions WHERE id = 525 AND payload_json LIKE '%TESTE DUAL-WRITE%'");
    echo "Cleanup #525 teste: removidos {$del}\n\n";
} catch (Exception $e) {
    echo "ERRO cleanup: " . $e->getMessage() . "\n\n";
}
@ob_flush(); flush();

// 2. Schema do form_submissions (campos esperados)
$colunas = $pdoHub->query("SHOW COLUMNS FROM form_submissions")->fetchAll(PDO::FETCH_COLUMN);
echo "Colunas form_submissions: " . implode(', ', $colunas) . "\n\n";

// 3. Pega entradas do banco antigo após 01/04
$rows = $pdoOld->query("SELECT * FROM intake_visitas WHERE created_at > '2026-04-01 23:59:59' ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
echo "Entradas em intake_visitas após 01/04: " . count($rows) . "\n\n";

$migrados = 0; $ja = 0; $erros = 0;

$insertStmt = $pdoHub->prepare(
    "INSERT INTO form_submissions (form_type, protocol, payload_json, ip_address, user_agent, created_at, linked_client_id)
     VALUES ('convivencia', ?, ?, ?, ?, ?, ?)"
);

foreach ($rows as $v) {
    echo "── #{$v['id']} [{$v['protocol']}] {$v['created_at']} — {$v['client_name']}\n";

    // Já foi migrado?
    $chk = $pdoHub->prepare("SELECT id FROM form_submissions WHERE form_type='convivencia' AND payload_json LIKE ?");
    $chk->execute(array('%' . $v['protocol'] . '%'));
    if ($chk->fetchColumn()) { echo "   → Já existe, skip\n\n"; $ja++; continue; }

    // Acha o cliente vinculado por phone (últimos 9 dígitos)
    $phoneClean = preg_replace('/\D/', '', (string)$v['client_phone']);
    $clientId = null;
    if (strlen($phoneClean) >= 9) {
        $cstmt = $pdoHub->prepare(
            "SELECT id FROM clients WHERE
                REPLACE(REPLACE(REPLACE(REPLACE(phone,' ',''),'(',''),')',''),'-','') LIKE ?
             ORDER BY id LIMIT 1"
        );
        $cstmt->execute(array('%' . substr($phoneClean, -9)));
        $clientId = $cstmt->fetchColumn() ?: null;
    }

    // Monta payload completo
    $answers = json_decode((string)$v['answers_json'], true) ?: array();
    $payload = array_merge(array(
        'client_name'       => $v['client_name'],
        'client_phone'      => $v['client_phone'],
        'client_email'      => $v['client_email'],
        'child_name'        => $v['child_name'],
        'child_age'         => (int)$v['child_age'],
        'relationship_role' => $v['relationship_role'],
        'protocol_original' => $v['protocol'],
        '_backfill_em'      => date('Y-m-d H:i:s'),
    ), $answers);

    // Gera protocolo CON-XXX (mesmo padrão do api_form.php)
    $newProto = 'CON-' . strtoupper(bin2hex(random_bytes(5)));

    try {
        $insertStmt->execute(array(
            $newProto,
            json_encode($payload, JSON_UNESCAPED_UNICODE),
            $v['ip_address'] ?? null,
            $v['user_agent'] ?? null,
            $v['created_at'],   // mantém data original
            $clientId,
        ));
        $newId = (int)$pdoHub->lastInsertId();
        echo "   ✓ Hub #{$newId} ({$newProto}) — client_id=" . ($clientId ?: 'null') . "\n\n";
        $migrados++;
    } catch (Exception $e) {
        echo "   ✗ ERRO: " . $e->getMessage() . "\n\n";
        $erros++;
    }
}

echo "=== RESUMO ===\n";
echo "Migrados: {$migrados}\n";
echo "Já existentes (pulados): {$ja}\n";
echo "Erros: {$erros}\n";
