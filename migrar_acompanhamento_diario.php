<?php
/**
 * Migração: tabela acompanhamento_msg_diario
 * Feature Amanda 01/07/2026: envio automático diário via WhatsApp
 * informando cliente que estamos acompanhando quando NÃO houve andamento.
 *
 * Uso: GET /migrar_acompanhamento_diario.php?key=fsa-hub-deploy-2026
 */
require_once __DIR__ . '/core/database.php';
if (($_GET['key']??'') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Migração: Acompanhamento diário via WhatsApp ===\n\n";

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS acompanhamento_msg_diario (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        client_id INT UNSIGNED NOT NULL,
        case_id INT UNSIGNED NOT NULL,
        canal ENUM('21','24') NOT NULL DEFAULT '24',
        horario_envio TIME NOT NULL DEFAULT '10:00:00',
        dias_uteis_only TINYINT(1) NOT NULL DEFAULT 1,
        ativo TINYINT(1) NOT NULL DEFAULT 1,
        ultimo_envio_em DATETIME NULL,
        ultimo_template_idx INT NULL,
        ultima_data_andamento_visto DATE NULL,
        total_envios INT NOT NULL DEFAULT 0,
        obs TEXT NULL,
        pausado_em DATETIME NULL,
        pausado_motivo TEXT NULL,
        created_by INT UNSIGNED NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_client_case (client_id, case_id),
        INDEX idx_ativo (ativo),
        INDEX idx_case (case_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "  ✓ Tabela acompanhamento_msg_diario criada\n";
} catch (Exception $e) { echo "  ✗ " . $e->getMessage() . "\n"; }

// Killswitch geral
try {
    $chave = 'acompanhamento_msg_diario_ativo';
    $st = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = ?");
    $st->execute(array($chave));
    if ($st->fetchColumn() === false) {
        $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES (?, '1')")->execute(array($chave));
        echo "  ✓ Killswitch {$chave} = '1' (LIGADO)\n";
    } else {
        echo "  - Killswitch {$chave} já existe\n";
    }
} catch (Exception $e) { echo "  ✗ Killswitch: " . $e->getMessage() . "\n"; }

echo "\n=== FIM ===\n";
echo "Pra desligar tudo (killswitch):\n";
echo "  UPDATE configuracoes SET valor='0' WHERE chave='acompanhamento_msg_diario_ativo';\n";
