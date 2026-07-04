<?php
/**
 * Migração: vínculo cobrança ↔ MÚLTIPLOS processos (combo).
 * Ex: cliente fecha alimentos + divórcio num contrato/orçamento só. A equipe
 * duplica as pastas (2 casos), mas a cobrança é uma. Esta tabela liga a mesma
 * cobrança a vários casos. O caso "primário" continua em asaas_cobrancas.case_id
 * (comportamento antigo intacto); esta tabela guarda apenas os processos EXTRAS.
 * Leitura "cobranças do caso X" = ac.case_id = X OR EXISTS(vínculo extra).
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Migração: asaas_cobranca_cases ===\n\n";

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS asaas_cobranca_cases (
        id INT AUTO_INCREMENT PRIMARY KEY,
        cobranca_id INT NOT NULL,
        case_id INT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_cob_case (cobranca_id, case_id),
        KEY idx_case (case_id),
        KEY idx_cobranca (cobranca_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "OK: tabela asaas_cobranca_cases criada (ou já existia)\n";
} catch (Exception $e) {
    echo "ERRO: " . $e->getMessage() . "\n";
}

echo "\nPronto! (junção guarda só os processos EXTRA; o primário fica em asaas_cobrancas.case_id)\n";
