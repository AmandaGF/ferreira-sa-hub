<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
ini_set('display_errors', '1');
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "=== MIGRACAO: Respostas padrao Central VIP ===\n\n";

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS salavip_respostas_padrao (
        id INT AUTO_INCREMENT PRIMARY KEY,
        titulo VARCHAR(80) NOT NULL,
        texto TEXT NOT NULL,
        ordem INT DEFAULT 0,
        ativo TINYINT(1) DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] Tabela salavip_respostas_padrao\n";
} catch (Exception $e) { echo "[ERRO] " . $e->getMessage() . "\n"; }

// Seed
try {
    $chk = (int)$pdo->query("SELECT COUNT(*) FROM salavip_respostas_padrao")->fetchColumn();
    if ($chk === 0) {
        $seeds = array(
            array('👋 Boas-vindas', 'Olá! Agradecemos o contato. Em breve retornaremos com o retorno sobre sua solicitação.', 1),
            array('📄 Docs recebidos', 'Confirmamos o recebimento dos documentos. Vamos analisar e entrar em contato em breve!', 2),
            array('⏳ Em análise', 'Sua solicitação está em análise pela nossa equipe jurídica. Retornaremos com uma posição assim que possível.', 3),
            array('🔁 Duplicado', 'Identificamos que já existe uma solicitação semelhante em andamento. Vamos continuar o atendimento por ela — esta pode ser encerrada.', 4),
            array('📞 Entraremos em contato', 'Recebemos sua mensagem. Nossa equipe entrará em contato pelo telefone cadastrado em breve.', 5),
            array('⚖️ Audiência agendada', 'Informamos que sua audiência foi agendada. A confirmação com data/horário foi enviada pelo seu e-mail e WhatsApp.', 6),
            array('💰 Acordo em andamento', 'Estamos em tratativas de acordo. Assim que houver uma proposta formal, entraremos em contato.', 7),
            array('✅ Resolvido', 'Sua solicitação foi resolvida. Se precisar, abra uma nova conversa a qualquer momento.', 8),
        );
        $stmt = $pdo->prepare("INSERT INTO salavip_respostas_padrao (titulo, texto, ordem) VALUES (?,?,?)");
        foreach ($seeds as $s) $stmt->execute($s);
        echo "[OK] 8 respostas padrão seed\n";
    } else {
        echo "[SKIP] Ja existem ({$chk}) respostas\n";
    }
} catch (Exception $e) { echo "[ERRO] " . $e->getMessage() . "\n"; }

echo "\n=== CONCLUIDO ===\n";
