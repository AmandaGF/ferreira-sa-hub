<?php
/**
 * Migração: Suspensão Bilateral + Alertas de Inatividade + Notificações ao Cliente
 * Adiciona colunas e tabelas para os Blocos 2, 3 e 4 das Regras Adicionais
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
set_time_limit(120);

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Migração: Suspensão + Alertas + Notificações Cliente ===\n\n";

// ─── BLOCO 2: Colunas de suspensão ───────────────────────
echo "1. Adicionando colunas de suspensão em pipeline_leads...\n";
$queries = array(
    "ALTER TABLE pipeline_leads ADD COLUMN coluna_antes_suspensao VARCHAR(80) DEFAULT NULL AFTER stage_antes_doc_faltante",
    "ALTER TABLE pipeline_leads ADD COLUMN data_suspensao DATETIME DEFAULT NULL AFTER coluna_antes_suspensao",
    "ALTER TABLE pipeline_leads ADD COLUMN prazo_suspensao DATE DEFAULT NULL AFTER data_suspensao",
);
foreach ($queries as $q) {
    try { $pdo->exec($q); echo "   OK: " . substr($q, 0, 80) . "...\n"; }
    catch (Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) { echo "   Já existe.\n"; }
        else { echo "   ERRO: " . $e->getMessage() . "\n"; }
    }
}

echo "\n2. Adicionando colunas de suspensão em cases...\n";
$queries = array(
    "ALTER TABLE cases ADD COLUMN coluna_antes_suspensao VARCHAR(80) DEFAULT NULL AFTER stage_antes_doc_faltante",
    "ALTER TABLE cases ADD COLUMN data_suspensao DATETIME DEFAULT NULL AFTER coluna_antes_suspensao",
    "ALTER TABLE cases ADD COLUMN prazo_suspensao DATE DEFAULT NULL AFTER data_suspensao",
);
foreach ($queries as $q) {
    try { $pdo->exec($q); echo "   OK: " . substr($q, 0, 80) . "...\n"; }
    catch (Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) { echo "   Já existe.\n"; }
        else { echo "   ERRO: " . $e->getMessage() . "\n"; }
    }
}

// ─── BLOCO 3: Tabela de alertas enviados (evitar duplicatas) ──
echo "\n3. Criando tabela alertas_enviados...\n";
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS alertas_enviados (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        tipo VARCHAR(60) NOT NULL COMMENT 'tipo do alerta: inatividade_elaboracao, inatividade_docs, etc',
        referencia_tipo VARCHAR(30) NOT NULL COMMENT 'lead ou case',
        referencia_id INT UNSIGNED NOT NULL,
        enviado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        proximo_alerta DATE DEFAULT NULL COMMENT 'Próxima data para repetir',
        INDEX idx_tipo_ref (tipo, referencia_tipo, referencia_id),
        INDEX idx_proximo (proximo_alerta)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "   OK: tabela alertas_enviados criada.\n";
} catch (Exception $e) { echo "   " . $e->getMessage() . "\n"; }

// ─── BLOCO 4: Tabela de notificações ao cliente ──────────
echo "\n4. Criando tabela notificacoes_cliente...\n";
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS notificacoes_cliente (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        client_id INT UNSIGNED NOT NULL,
        case_id INT UNSIGNED DEFAULT NULL,
        lead_id INT UNSIGNED DEFAULT NULL,
        tipo VARCHAR(60) NOT NULL COMMENT 'boas_vindas, docs_recebidos, processo_distribuido, doc_faltante',
        canal VARCHAR(20) NOT NULL DEFAULT 'whatsapp' COMMENT 'whatsapp, email',
        destinatario VARCHAR(200) DEFAULT NULL COMMENT 'telefone ou email',
        mensagem TEXT NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pendente' COMMENT 'pendente, enviado, falha',
        enviado_em DATETIME DEFAULT NULL,
        enviado_por INT UNSIGNED DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_client (client_id),
        INDEX idx_status (status),
        INDEX idx_tipo (tipo),
        FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "   OK: tabela notificacoes_cliente criada.\n";
} catch (Exception $e) { echo "   " . $e->getMessage() . "\n"; }

// ─── BLOCO 4: Tabela de configuração de notificações ─────
echo "\n5. Criando tabela notificacao_config...\n";
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS notificacao_config (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        tipo VARCHAR(60) NOT NULL UNIQUE COMMENT 'boas_vindas, docs_recebidos, processo_distribuido, doc_faltante',
        titulo VARCHAR(150) NOT NULL,
        mensagem_whatsapp TEXT NOT NULL,
        mensagem_email TEXT DEFAULT NULL,
        ativo TINYINT(1) NOT NULL DEFAULT 1,
        variaveis_disponiveis VARCHAR(500) DEFAULT NULL,
        updated_by INT UNSIGNED DEFAULT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE INDEX idx_tipo (tipo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "   OK: tabela notificacao_config criada.\n";
} catch (Exception $e) { echo "   " . $e->getMessage() . "\n"; }

// ─── Seed das mensagens padrão ───────────────────────────
echo "\n6. Inserindo templates de notificação padrão...\n";
$templates = array(
    array(
        'boas_vindas',
        'Boas-vindas (Contrato Assinado)',
        "Olá, [Nome]! Seja bem-vindo(a) à Ferreira & Sá Advocacia.\nSeu contrato foi assinado com sucesso. Nossa equipe já está preparando sua documentação. Em breve entraremos em contato.\nQualquer dúvida, estamos à disposição!",
        "[Nome], [tipo_acao]"
    ),
    array(
        'docs_recebidos',
        'Documentos Recebidos / Pasta Apta',
        "Olá, [Nome]! Confirmamos o recebimento de toda a sua documentação.\nNossa equipe jurídica já está iniciando os trabalhos no seu caso.\nRetornaremos com atualizações em breve!",
        "[Nome], [tipo_acao]"
    ),
    array(
        'processo_distribuido',
        'Processo Distribuído',
        "Olá, [Nome]! Temos uma boa notícia: sua ação foi distribuída.\nNúmero do processo: [numero_processo]\nVara/Juízo: [vara_juizo]\nAcompanhe pelo site do tribunal com este número.\nContinuaremos te atualizando sobre as próximas etapas!",
        "[Nome], [numero_processo], [vara_juizo], [tipo_acao]"
    ),
    array(
        'doc_faltante',
        'Documento Faltante (Cobrança)',
        "Olá, [Nome]! Para darmos continuidade ao seu processo, precisamos do seguinte documento: [descricao_documento].\nPor favor, envie o quanto antes para nosso WhatsApp ou pelo link da sua pasta: [link_drive]\nQualquer dúvida, entre em contato conosco!",
        "[Nome], [descricao_documento], [link_drive], [tipo_acao]"
    ),
);

foreach ($templates as $t) {
    try {
        $check = $pdo->prepare("SELECT id FROM notificacao_config WHERE tipo = ?");
        $check->execute(array($t[0]));
        if (!$check->fetch()) {
            $pdo->prepare("INSERT INTO notificacao_config (tipo, titulo, mensagem_whatsapp, variaveis_disponiveis) VALUES (?,?,?,?)")
                ->execute($t);
            echo "   Inserido: {$t[0]}\n";
        } else {
            echo "   Já existe: {$t[0]}\n";
        }
    } catch (Exception $e) { echo "   ERRO {$t[0]}: " . $e->getMessage() . "\n"; }
}

// ─── Verificação final ───────────────────────────────────
echo "\n=== VERIFICAÇÃO ===\n";
echo "pipeline_leads colunas suspensão: ";
try {
    $cols = $pdo->query("SHOW COLUMNS FROM pipeline_leads LIKE '%suspens%'")->fetchAll();
    echo count($cols) . " encontradas\n";
    foreach ($cols as $c) echo "  - {$c['Field']}\n";
} catch (Exception $e) { echo "ERRO\n"; }

echo "cases colunas suspensão: ";
try {
    $cols = $pdo->query("SHOW COLUMNS FROM cases LIKE '%suspens%'")->fetchAll();
    echo count($cols) . " encontradas\n";
    foreach ($cols as $c) echo "  - {$c['Field']}\n";
} catch (Exception $e) { echo "ERRO\n"; }

echo "alertas_enviados: " . $pdo->query("SELECT COUNT(*) FROM alertas_enviados")->fetchColumn() . " registros\n";
echo "notificacoes_cliente: " . $pdo->query("SELECT COUNT(*) FROM notificacoes_cliente")->fetchColumn() . " registros\n";
echo "notificacao_config: " . $pdo->query("SELECT COUNT(*) FROM notificacao_config")->fetchColumn() . " templates\n";

echo "\nPronto!\n";
