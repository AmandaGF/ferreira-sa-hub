<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== MIGRAÇÃO: Cobrança de Honorários ===\n\n";

// 1. Tabela principal honorarios_cobranca
echo "--- honorarios_cobranca ---\n";
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS honorarios_cobranca (
        id INT AUTO_INCREMENT PRIMARY KEY,
        client_id INT NOT NULL,
        case_id INT DEFAULT NULL,
        contrato_id INT UNSIGNED DEFAULT NULL,
        tipo_debito VARCHAR(100) NOT NULL,
        valor_total DECIMAL(12,2) NOT NULL,
        valor_pago DECIMAL(12,2) DEFAULT 0,
        vencimento DATE NOT NULL,
        status ENUM('em_dia','atrasado','notificado_1','notificado_2','notificado_extrajudicial','judicial','pago','cancelado') DEFAULT 'em_dia',
        entrada_automatica TINYINT(1) DEFAULT 0,
        responsavel_cobranca INT DEFAULT NULL,
        observacoes TEXT,
        created_by INT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_status (status),
        INDEX idx_vencimento (vencimento),
        INDEX idx_client (client_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] Tabela honorarios_cobranca criada\n";
} catch (Exception $e) {
    echo "[INFO] " . $e->getMessage() . "\n";
}

// 2. Tabela de histórico
echo "\n--- honorarios_cobranca_historico ---\n";
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS honorarios_cobranca_historico (
        id INT AUTO_INCREMENT PRIMARY KEY,
        cobranca_id INT NOT NULL,
        etapa ENUM('notificacao_1','notificacao_2','notificacao_extrajudicial','judicial','pagamento_parcial','pagamento_total','cancelamento','observacao') NOT NULL,
        descricao TEXT,
        valor_pago DECIMAL(12,2) DEFAULT NULL,
        enviado_via ENUM('whatsapp','email','portal','manual') DEFAULT NULL,
        enviado_por INT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_cobranca (cobranca_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] Tabela honorarios_cobranca_historico criada\n";
} catch (Exception $e) {
    echo "[INFO] " . $e->getMessage() . "\n";
}

// 3. Tabela de configuração
echo "\n--- honorarios_config ---\n";
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS honorarios_config (
        id INT AUTO_INCREMENT PRIMARY KEY,
        dias_para_cobranca INT DEFAULT 90,
        prazo_notificacao_1 INT DEFAULT 7,
        prazo_notificacao_2 INT DEFAULT 15,
        prazo_extrajudicial INT DEFAULT 10,
        responsavel_padrao_id INT DEFAULT NULL,
        msg_notificacao_1 TEXT,
        msg_notificacao_2 TEXT,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] Tabela honorarios_config criada\n";
} catch (Exception $e) {
    echo "[INFO] " . $e->getMessage() . "\n";
}

// 4. Inserir config padrão
echo "\n--- Config padrão ---\n";
$chk = $pdo->query("SELECT COUNT(*) FROM honorarios_config")->fetchColumn();
if ($chk == 0) {
    $msg1 = 'Olá, [Nome]! 😊 Identificamos que há um débito de honorários advocatícios em aberto no valor de R$ [valor] com vencimento em [data]. Caso já tenha efetuado o pagamento, por favor desconsidere esta mensagem. Em caso de dúvidas, estamos à disposição. PIX: 51.294.223/0001-40 (Ferreira e Sá Advocacia)';
    $msg2 = 'Prezado(a) [Nome], viemos por meio desta comunicação informar que identificamos débito de honorários advocatícios contratuais no valor de R$ [valor], vencido em [data], ainda pendente de regularização. Solicitamos a quitação em até 7 dias úteis a fim de evitar medidas administrativas e judiciais cabíveis. PIX: 51.294.223/0001-40 | Ferreira & Sá Advocacia — (24) 9.9205-0096';
    $stmt = $pdo->prepare("INSERT INTO honorarios_config (dias_para_cobranca, prazo_notificacao_1, prazo_notificacao_2, prazo_extrajudicial, msg_notificacao_1, msg_notificacao_2) VALUES (90, 7, 15, 10, ?, ?)");
    $stmt->execute(array($msg1, $msg2));
    echo "[OK] Config padrão inserida\n";
} else {
    echo "[JÁ EXISTE] Config padrão\n";
}

echo "\n=== MIGRAÇÃO CONCLUÍDA ===\n";
