<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave inválida'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Atualizar mensagens de notificação ao cliente ===\n\n";

// Verificar estrutura da tabela
try {
    $cols = $pdo->query("DESCRIBE notificacao_config")->fetchAll();
    echo "Colunas: ";
    foreach ($cols as $c) echo $c['Field'] . ', ';
    echo "\n\n";
} catch (Exception $e) {
    echo "Tabela não existe. Criando...\n";
    $pdo->exec("CREATE TABLE IF NOT EXISTS notificacao_config (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tipo VARCHAR(50) NOT NULL UNIQUE,
        titulo VARCHAR(200),
        mensagem_whatsapp TEXT,
        mensagem_email TEXT,
        ativo TINYINT(1) DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    echo "Tabela criada!\n\n";
}

// Mensagens
$msgs = array(
    'boas_vindas' => array(
        'titulo' => 'Boas-vindas ao cliente',
        'whatsapp' => "Olá! Seja muito bem-vindo(a) ao número oficial de atendimento ao(à) cliente do escritório Ferreira e Sá Advocacia.\n\nEste é o número exclusivo para:\n📋 Atualizações e acompanhamento processual\n📁 Envio e recebimento de documentos\n🔒 Suporte jurídico com sigilo profissional\n🤝 Atendimento personalizado a clientes novos e recorrentes\n\n⚠️ IMPORTANTE — Documentos Pendentes:\nCaso você possua documentos a enviar, solicitamos que o faça o quanto antes, pois o andamento do seu caso pode depender diretamente da entrega em tempo hábil.\n\nTodas as comunicações neste canal são registradas com segurança e total confidencialidade.",
    ),
    'docs_recebidos' => array(
        'titulo' => 'Documentos recebidos',
        'whatsapp' => "Olá, [Nome]! Informamos que todos os documentos solicitados foram recebidos com sucesso. Sua pasta está completa e seguiremos com o andamento do seu caso.\n\nQualquer novidade, entraremos em contato.\n\nFerreira & Sá Advocacia",
    ),
    'processo_distribuido' => array(
        'titulo' => 'Processo distribuído',
        'whatsapp' => "Olá, [Nome]! Informamos que seu processo foi distribuído.\n\nNúmero: [numero_processo]\nVara/Juízo: [vara_juizo]\n\nA partir de agora, acompanharemos todas as movimentações e manteremos você informado(a).\n\nFerreira & Sá Advocacia",
    ),
    'doc_faltante' => array(
        'titulo' => 'Documento faltante',
        'whatsapp' => "Olá, [Nome]! Identificamos que ainda faltam documentos para dar andamento ao seu caso:\n\n📄 [descricao_documento]\n\nPor favor, envie o quanto antes para não atrasar o processo.\n\nFerreira & Sá Advocacia",
    ),
);

foreach ($msgs as $tipo => $data) {
    try {
        $stmt = $pdo->prepare("INSERT INTO notificacao_config (tipo, titulo, mensagem_whatsapp, ativo) VALUES (?, ?, ?, 1) ON DUPLICATE KEY UPDATE titulo = VALUES(titulo), mensagem_whatsapp = VALUES(mensagem_whatsapp)");
        $stmt->execute(array($tipo, $data['titulo'], $data['whatsapp']));
        echo "[OK] $tipo — " . $data['titulo'] . "\n";
    } catch (Exception $e) {
        echo "[ERRO] $tipo — " . $e->getMessage() . "\n";
    }
}

echo "\n--- Mensagens atuais ---\n";
try {
    $rows = $pdo->query("SELECT tipo, titulo, LEFT(mensagem_whatsapp, 80) as preview, ativo FROM notificacao_config")->fetchAll();
    foreach ($rows as $r) {
        echo ($r['ativo'] ? '[ATIVO]' : '[INATIVO]') . " " . $r['tipo'] . " — " . $r['titulo'] . "\n  " . $r['preview'] . "...\n";
    }
} catch (Exception $e) { echo $e->getMessage(); }

echo "\n=== FIM ===\n";
