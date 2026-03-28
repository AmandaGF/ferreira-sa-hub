<?php
/**
 * Migração de dados dos bancos antigos para form_submissions
 * Execute UMA VEZ e depois apague!
 */

if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
set_time_limit(300);
echo "=== Migracao de Dados ===\n\n";

// Banco novo (Hub)
require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/database.php';
require_once __DIR__ . '/../../core/functions.php';
$hub = db();

// Verificar se já tem dados importados
$existing = (int)$hub->query("SELECT COUNT(*) FROM form_submissions")->fetchColumn();
echo "Formularios ja existentes no Hub: $existing\n\n";

$totalImported = 0;

// ═══════════════════════════════════════════════════════
// 1. CONVIVÊNCIA
// ═══════════════════════════════════════════════════════
echo "--- 1. CONVIVENCIA ---\n";
try {
    $convPdo = new PDO(
        'mysql:host=localhost;dbname=ferre3151357_bd_convivencia;charset=utf8mb4',
        'ferre3151357_admin_convivencia',
        'Ar192114@',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    $rows = $convPdo->query("SELECT * FROM intake_visitas ORDER BY created_at ASC")->fetchAll();
    echo "Registros encontrados: " . count($rows) . "\n";

    $stmtCheck = $hub->prepare("SELECT id FROM form_submissions WHERE protocol = ? AND form_type = 'convivencia'");
    $stmtInsert = $hub->prepare(
        "INSERT INTO form_submissions (form_type, protocol, client_name, client_email, client_phone, status, payload_json, ip_address, user_agent, created_at)
         VALUES ('convivencia', ?, ?, ?, ?, 'processado', ?, ?, ?, ?)"
    );

    $imported = 0;
    foreach ($rows as $row) {
        $protocol = $row['protocol'] ?? generate_protocol('CVV');

        // Verificar duplicata
        $stmtCheck->execute([$protocol]);
        if ($stmtCheck->fetch()) { continue; }

        // Montar payload JSON
        $payload = $row['answers_json'] ?? json_encode($row, JSON_UNESCAPED_UNICODE);

        $stmtInsert->execute([
            $protocol,
            $row['client_name'] ?? null,
            $row['client_email'] ?? null,
            $row['client_phone'] ?? null,
            $payload,
            $row['ip_address'] ?? null,
            $row['user_agent'] ?? null,
            $row['created_at'] ?? date('Y-m-d H:i:s'),
        ]);
        $imported++;
    }
    echo "Importados: $imported\n\n";
    $totalImported += $imported;
} catch (PDOException $e) {
    echo "ERRO Convivencia: " . $e->getMessage() . "\n\n";
}

// ═══════════════════════════════════════════════════════
// 2. GASTOS PENSÃO
// ═══════════════════════════════════════════════════════
echo "--- 2. GASTOS PENSAO ---\n";
try {
    $gasPdo = new PDO(
        'mysql:host=localhost;dbname=ferre3151357_gastos_pensao;charset=utf8mb4',
        'ferre3151357_pensao_user',
        'Ar192114@',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    // Descobrir nome da tabela
    $tables = $gasPdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "Tabelas: " . implode(', ', $tables) . "\n";

    // Tentar a tabela mais provável
    $tableName = null;
    foreach ($tables as $t) {
        if (strpos($t, 'resposta') !== false || strpos($t, 'pensao') !== false || strpos($t, 'gasto') !== false) {
            $tableName = $t;
            break;
        }
    }
    if (!$tableName && !empty($tables)) {
        $tableName = $tables[0]; // Primeira tabela
    }

    if ($tableName) {
        echo "Usando tabela: $tableName\n";
        $rows = $gasPdo->query("SELECT * FROM `$tableName` ORDER BY created_at ASC")->fetchAll();
        echo "Registros encontrados: " . count($rows) . "\n";

        $stmtCheck = $hub->prepare("SELECT id FROM form_submissions WHERE protocol = ? AND form_type = 'gastos_pensao'");
        $stmtInsert = $hub->prepare(
            "INSERT INTO form_submissions (form_type, protocol, client_name, client_email, client_phone, status, payload_json, ip_address, user_agent, created_at)
             VALUES ('gastos_pensao', ?, ?, ?, ?, 'processado', ?, ?, ?, ?)"
        );

        $imported = 0;
        foreach ($rows as $row) {
            $protocol = $row['protocol'] ?? $row['protocolo'] ?? generate_protocol('GST');

            $stmtCheck->execute([$protocol]);
            if ($stmtCheck->fetch()) { continue; }

            // Montar payload - usar campo JSON se existir, senão toda a row
            $payload = $row['payload_json'] ?? $row['respostas_json'] ?? $row['dados_json'] ?? json_encode($row, JSON_UNESCAPED_UNICODE);

            $clientName = $row['client_name'] ?? $row['nome_responsavel'] ?? $row['nome'] ?? null;
            $clientPhone = $row['client_phone'] ?? $row['whatsapp'] ?? $row['telefone'] ?? null;
            $clientEmail = $row['client_email'] ?? $row['email'] ?? null;

            $stmtInsert->execute([
                $protocol,
                $clientName,
                $clientEmail,
                $clientPhone,
                $payload,
                $row['ip_address'] ?? $row['ip'] ?? null,
                $row['user_agent'] ?? null,
                $row['created_at'] ?? date('Y-m-d H:i:s'),
            ]);
            $imported++;
        }
        echo "Importados: $imported\n\n";
        $totalImported += $imported;
    } else {
        echo "Nenhuma tabela encontrada!\n\n";
    }
} catch (PDOException $e) {
    echo "ERRO Gastos Pensao: " . $e->getMessage() . "\n\n";
}

echo "=== MIGRACAO CONCLUIDA ===\n";
echo "Total importado: $totalImported registros\n";
echo "Acesse: /conecta/modules/formularios/ para ver os dados\n";
