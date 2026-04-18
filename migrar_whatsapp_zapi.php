<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
ini_set('display_errors', '1');
error_reporting(E_ALL);
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "=== MIGRACAO: WhatsApp CRM (Z-API) ===\n\n";

// 1. zapi_instancias
echo "--- zapi_instancias ---\n";
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS zapi_instancias (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nome VARCHAR(50) NOT NULL,
        numero VARCHAR(20) NOT NULL,
        ddd VARCHAR(3) NOT NULL,
        instancia_id VARCHAR(100) NOT NULL DEFAULT '',
        token VARCHAR(200) NOT NULL DEFAULT '',
        tipo ENUM('comercial','cx','operacional') NOT NULL,
        ativo TINYINT(1) NOT NULL DEFAULT 1,
        conectado TINYINT(1) NOT NULL DEFAULT 0,
        ultima_verificacao DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_ddd (ddd)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] Tabela zapi_instancias\n";
} catch (Exception $e) { echo "[ERRO] " . $e->getMessage() . "\n"; }

// Seed das 2 instâncias
try {
    $chk = (int)$pdo->query("SELECT COUNT(*) FROM zapi_instancias")->fetchColumn();
    if ($chk === 0) {
        $pdo->exec("INSERT INTO zapi_instancias (nome, numero, ddd, tipo) VALUES
            ('Comercial', '21999999999', '21', 'comercial'),
            ('CX/Operacional', '24999999999', '24', 'cx')");
        echo "[OK] 2 instancias seed (21 comercial, 24 cx)\n";
    } else {
        echo "[SKIP] Instancias ja existem ({$chk})\n";
    }
} catch (Exception $e) { echo "[ERRO] " . $e->getMessage() . "\n"; }

// 2. zapi_conversas
echo "\n--- zapi_conversas ---\n";
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS zapi_conversas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        instancia_id INT NOT NULL,
        telefone VARCHAR(20) NOT NULL,
        nome_contato VARCHAR(150) DEFAULT NULL,
        client_id INT DEFAULT NULL,
        lead_id INT DEFAULT NULL,
        atendente_id INT DEFAULT NULL,
        status ENUM('aguardando','em_atendimento','bot','transferido','resolvido','arquivado') NOT NULL DEFAULT 'aguardando',
        canal ENUM('21','24') NOT NULL,
        ultima_mensagem TEXT,
        ultima_msg_em DATETIME DEFAULT NULL,
        nao_lidas INT NOT NULL DEFAULT 0,
        bot_ativo TINYINT(1) NOT NULL DEFAULT 0,
        bot_etapa VARCHAR(50) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_telefone_instancia (telefone, instancia_id),
        INDEX idx_status (status),
        INDEX idx_canal (canal),
        INDEX idx_atendente (atendente_id),
        INDEX idx_client (client_id),
        INDEX idx_lead (lead_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] Tabela zapi_conversas\n";
} catch (Exception $e) { echo "[ERRO] " . $e->getMessage() . "\n"; }

// 3. zapi_mensagens
echo "\n--- zapi_mensagens ---\n";
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS zapi_mensagens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        conversa_id INT NOT NULL,
        zapi_message_id VARCHAR(100) DEFAULT NULL,
        direcao ENUM('recebida','enviada') NOT NULL,
        tipo ENUM('texto','imagem','documento','audio','video','sticker','localizacao','contato','outro') NOT NULL DEFAULT 'texto',
        conteudo TEXT,
        arquivo_url VARCHAR(500) DEFAULT NULL,
        arquivo_nome VARCHAR(200) DEFAULT NULL,
        arquivo_mime VARCHAR(100) DEFAULT NULL,
        arquivo_tamanho INT DEFAULT NULL,
        arquivo_salvo_drive TINYINT(1) NOT NULL DEFAULT 0,
        drive_file_id VARCHAR(200) DEFAULT NULL,
        enviado_por_id INT DEFAULT NULL,
        enviado_por_bot TINYINT(1) NOT NULL DEFAULT 0,
        lida TINYINT(1) NOT NULL DEFAULT 0,
        entregue TINYINT(1) NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'enviada',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_conversa (conversa_id),
        INDEX idx_created (created_at),
        INDEX idx_zapi_msg (zapi_message_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] Tabela zapi_mensagens\n";
} catch (Exception $e) { echo "[ERRO] " . $e->getMessage() . "\n"; }

// 4. zapi_templates
echo "\n--- zapi_templates ---\n";
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS zapi_templates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nome VARCHAR(100) NOT NULL,
        conteudo TEXT NOT NULL,
        canal ENUM('21','24','ambos') NOT NULL DEFAULT 'ambos',
        categoria VARCHAR(50) DEFAULT NULL,
        ativo TINYINT(1) NOT NULL DEFAULT 1,
        created_by INT DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_ativo (ativo),
        INDEX idx_canal (canal)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] Tabela zapi_templates\n";
} catch (Exception $e) { echo "[ERRO] " . $e->getMessage() . "\n"; }

// Seed templates iniciais
try {
    $chk = (int)$pdo->query("SELECT COUNT(*) FROM zapi_templates")->fetchColumn();
    if ($chk === 0) {
        $adminId = (int)$pdo->query("SELECT id FROM users WHERE role='admin' ORDER BY id LIMIT 1")->fetchColumn() ?: null;
        $templates = array(
            array('Boas-vindas Comercial', 'Olá, {{nome}}! 😊 Seja bem-vindo(a) ao escritório Ferreira & Sá Advocacia. Em breve um de nossos advogados entrará em contato. Enquanto isso, pode nos contar brevemente sobre o que precisa?', '21', 'recepcao'),
            array('Fora do horário', 'Olá! 🌙 Nosso horário de atendimento presencial é de segunda a sexta, das 10h às 18h. Sua mensagem foi recebida e será respondida no próximo dia útil. Para urgências, estamos disponíveis remotamente 24h.', 'ambos', 'automatico'),
            array('Confirmação de documentos', 'Olá, {{nome}}! ✅ Confirmamos o recebimento do(s) documento(s) enviado(s). Nossa equipe irá analisar e retornará em breve. Obrigado!', '24', 'documentos'),
            array('Processo distribuído', 'Olá, {{nome}}! ⚖️ Informamos que seu processo foi distribuído com o número {{numero_processo}}. Você pode acompanhar o andamento pela sua Sala VIP: ferreiraesa.com.br/salavip', '24', 'processo'),
            array('Agendamento de consulta', 'Olá, {{nome}}! 📅 Sua consulta está confirmada para {{data}} às {{hora}}. Qualquer dúvida, estamos à disposição!', 'ambos', 'agenda'),
        );
        $stmt = $pdo->prepare("INSERT INTO zapi_templates (nome, conteudo, canal, categoria, created_by) VALUES (?,?,?,?,?)");
        foreach ($templates as $t) {
            $stmt->execute(array($t[0], $t[1], $t[2], $t[3], $adminId));
        }
        echo "[OK] 5 templates seed inseridos\n";
    } else {
        echo "[SKIP] Templates ja existem ({$chk})\n";
    }
} catch (Exception $e) { echo "[ERRO] " . $e->getMessage() . "\n"; }

echo "\n=== CONCLUIDO ===\n";
echo "\nProximos passos:\n";
echo "1. Criar conta em z-api.io e 2 instancias (DDD 21 e DDD 24)\n";
echo "2. Escanear QR Code em cada instancia\n";
echo "3. Configurar webhook Z-API para: https://ferreiraesa.com.br/conecta/api/zapi_webhook.php?numero=21 (e =24)\n";
echo "4. Adicionar no core/config.php:\n";
echo "     define('ZAPI_INSTANCE_21', '...');\n";
echo "     define('ZAPI_TOKEN_21', '...');\n";
echo "     define('ZAPI_INSTANCE_24', '...');\n";
echo "     define('ZAPI_TOKEN_24', '...');\n";
echo "     define('ZAPI_CLIENT_TOKEN', '...');\n";
echo "     define('ZAPI_BASE_URL', 'https://api.z-api.io/instances');\n";
echo "5. Acessar /conecta/modules/whatsapp/ no menu Comercial\n";
