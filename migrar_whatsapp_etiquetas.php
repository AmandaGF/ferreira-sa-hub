<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
ini_set('display_errors', '1');
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "=== MIGRACAO: WhatsApp Etiquetas ===\n\n";

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS zapi_etiquetas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nome VARCHAR(60) NOT NULL,
        cor VARCHAR(10) NOT NULL DEFAULT '#6b7280',
        ordem INT NOT NULL DEFAULT 0,
        ativo TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_ativo (ativo),
        INDEX idx_ordem (ordem)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] zapi_etiquetas\n";
} catch (Exception $e) { echo "[ERRO] " . $e->getMessage() . "\n"; }

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS zapi_conversa_etiquetas (
        conversa_id INT NOT NULL,
        etiqueta_id INT NOT NULL,
        aplicada_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        aplicada_por INT DEFAULT NULL,
        PRIMARY KEY (conversa_id, etiqueta_id),
        INDEX idx_etiqueta (etiqueta_id),
        FOREIGN KEY (conversa_id) REFERENCES zapi_conversas(id) ON DELETE CASCADE,
        FOREIGN KEY (etiqueta_id) REFERENCES zapi_etiquetas(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] zapi_conversa_etiquetas\n";
} catch (Exception $e) { echo "[ERRO] " . $e->getMessage() . "\n"; }

// Seed
try {
    $chk = (int)$pdo->query("SELECT COUNT(*) FROM zapi_etiquetas")->fetchColumn();
    if ($chk === 0) {
        $seeds = array(
            array('🔴 Urgente',         '#ef4444', 1),
            array('⭐ VIP',              '#f59e0b', 2),
            array('📄 Aguardando Docs',  '#3b82f6', 3),
            array('💼 Negociação',       '#8b5cf6', 4),
            array('🎯 Lead Quente',      '#ec4899', 5),
            array('✅ Fechado',          '#22c55e', 6),
            array('❌ Perdido',          '#6b7280', 7),
            array('🚫 Spam',             '#78716c', 8),
        );
        $stmt = $pdo->prepare("INSERT INTO zapi_etiquetas (nome, cor, ordem) VALUES (?,?,?)");
        foreach ($seeds as $s) $stmt->execute($s);
        echo "[OK] 8 etiquetas seed\n";
    } else {
        echo "[SKIP] Etiquetas ja existem ({$chk})\n";
    }
} catch (Exception $e) { echo "[ERRO] " . $e->getMessage() . "\n"; }

echo "\n=== CONCLUIDO ===\n";
