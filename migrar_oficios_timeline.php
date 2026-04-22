<?php
/**
 * Garante que todas as colunas e tabelas da feature 'Linha do tempo do ofício' existam.
 * URL: /conecta/migrar_oficios_timeline.php?key=fsa-hub-deploy-2026
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit; }
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Migração: Ofícios — Linha do Tempo ===\n\n";

// Helper: adiciona coluna só se não existir (sem AFTER pra evitar falha de ordem)
function addCol($pdo, $tabela, $coluna, $def) {
    $exist = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$tabela' AND COLUMN_NAME = '$coluna'")->fetchColumn();
    if ($exist) { echo "  - $coluna já existe\n"; return; }
    try { $pdo->exec("ALTER TABLE $tabela ADD COLUMN $coluna $def"); echo "  + $coluna criada\n"; }
    catch (Exception $e) { echo "  ❌ $coluna erro: " . $e->getMessage() . "\n"; }
}

echo "[1] oficios_enviados — colunas extras\n";
addCol($pdo, 'oficios_enviados', 'case_id', 'INT UNSIGNED NULL');
addCol($pdo, 'oficios_enviados', 'empresa_cnpj', 'VARCHAR(20) NULL');
addCol($pdo, 'oficios_enviados', 'rh_email', 'VARCHAR(150) NULL');
addCol($pdo, 'oficios_enviados', 'rh_contato', 'VARCHAR(50) NULL');
addCol($pdo, 'oficios_enviados', 'funcionario_nome', 'VARCHAR(150) NULL');
addCol($pdo, 'oficios_enviados', 'funcionario_cargo', 'VARCHAR(100) NULL');
addCol($pdo, 'oficios_enviados', 'funcionario_matricula', 'VARCHAR(30) NULL');
addCol($pdo, 'oficios_enviados', 'funcionario_genero', "CHAR(1) DEFAULT 'M'");
addCol($pdo, 'oficios_enviados', 'conta_banco', 'VARCHAR(100) NULL');
addCol($pdo, 'oficios_enviados', 'conta_agencia', 'VARCHAR(20) NULL');
addCol($pdo, 'oficios_enviados', 'conta_numero', 'VARCHAR(30) NULL');
addCol($pdo, 'oficios_enviados', 'conta_titular', 'VARCHAR(150) NULL');
addCol($pdo, 'oficios_enviados', 'conta_cpf', 'VARCHAR(20) NULL');
addCol($pdo, 'oficios_enviados', 'tipo_oficio', "VARCHAR(30) NULL DEFAULT 'pensao_empregador'");
addCol($pdo, 'oficios_enviados', 'status_oficio', "VARCHAR(40) DEFAULT 'aguardando_contato_rh'");
addCol($pdo, 'oficios_enviados', 'ultima_atividade_em', 'DATETIME DEFAULT NULL');
addCol($pdo, 'oficios_enviados', 'alerta_cobranca_em', 'DATETIME DEFAULT NULL');

echo "\n[2] Tabela oficios_historico\n";
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS oficios_historico (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        oficio_id INT UNSIGNED NOT NULL,
        tipo VARCHAR(40) NOT NULL,
        descricao TEXT,
        created_by INT UNSIGNED DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_oficio (oficio_id, created_at)
    )");
    echo "  ✅ tabela garantida\n";
} catch (Exception $e) { echo "  ❌ erro: " . $e->getMessage() . "\n"; }

echo "\n[3] Backfill ultima_atividade_em (pra ofícios antigos sem atividade registrada)\n";
try {
    $r = $pdo->exec("UPDATE oficios_enviados SET ultima_atividade_em = COALESCE(data_envio, created_at) WHERE ultima_atividade_em IS NULL");
    echo "  ✅ $r ofícios atualizados\n";
} catch (Exception $e) { echo "  ❌ erro: " . $e->getMessage() . "\n"; }

echo "\n=== FIM ===\n";
