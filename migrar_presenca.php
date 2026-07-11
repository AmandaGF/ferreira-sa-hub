<?php
/**
 * Módulo Presença — Relacionamento & Retenção.
 * Migração da Fase 1 (fundação): 13 tabelas com prefixo presenca_ +
 * tabela centro_custo compartilhada.
 *
 * Referências reais (não os nomes do blueprint):
 *   clientes -> clients
 *   processos -> cases
 *   usuarios  -> users
 *
 * Idempotente. Rodar via URL:
 *   /conecta/migrar_presenca.php?key=fsa-hub-deploy-2026
 */
require_once __DIR__ . '/core/database.php';

if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') {
    http_response_code(403);
    exit('Chave inválida');
}

$pdo = db();
header('Content-Type: text/plain; charset=utf-8');
echo "=== Migração Presença — Fase 1 ===\n\n";

$exec = function ($sql, $rotulo) use ($pdo) {
    try { $pdo->exec($sql); echo "✓ $rotulo\n"; }
    catch (Exception $e) { echo "⚠ $rotulo: " . $e->getMessage() . "\n"; }
};

// ── centro_custo (compartilhada com outros módulos futuros) ──
$exec("CREATE TABLE IF NOT EXISTS centro_custo (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(80) NOT NULL,
    slug VARCHAR(80) NOT NULL UNIQUE,
    categoria VARCHAR(40) NULL,
    descricao VARCHAR(255) NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", 'centro_custo');

// ── 3.1 Perfis ──
$exec("CREATE TABLE IF NOT EXISTS presenca_perfil (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(60) NOT NULL,
    slug VARCHAR(60) NOT NULL UNIQUE,
    ticket_min DECIMAL(10,2) NULL,
    ticket_max DECIMAL(10,2) NULL,
    verba_min DECIMAL(10,2) NOT NULL DEFAULT 0,
    verba_max DECIMAL(10,2) NOT NULL DEFAULT 0,
    cor_hex CHAR(7) NOT NULL DEFAULT '#0E2E36',
    ordem SMALLINT NOT NULL DEFAULT 0,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", 'presenca_perfil');

// ── 3.2 Fases ──
$exec("CREATE TABLE IF NOT EXISTS presenca_fase (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(80) NOT NULL,
    slug VARCHAR(80) NOT NULL UNIQUE,
    gatilho VARCHAR(255) NULL,
    tipo ENUM('processual','efemeride') NOT NULL DEFAULT 'processual',
    recorrente TINYINT(1) NOT NULL DEFAULT 0,
    ordem SMALLINT NOT NULL DEFAULT 0,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", 'presenca_fase');

// ── 3.3 Frases ──
$exec("CREATE TABLE IF NOT EXISTS presenca_frase (
    id INT AUTO_INCREMENT PRIMARY KEY,
    texto VARCHAR(255) NOT NULL,
    fase_id INT NULL,
    tom VARCHAR(40) NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_fase (fase_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", 'presenca_frase');

// ── 3.4 Brindes ──
$exec("CREATE TABLE IF NOT EXISTS presenca_brinde (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(120) NOT NULL,
    categoria VARCHAR(40) NOT NULL,
    descricao TEXT NULL,
    embalagem VARCHAR(255) NULL,
    imagem_url VARCHAR(255) NULL,
    qtd_compra_referencia INT NOT NULL DEFAULT 1,
    eh_kit TINYINT(1) NOT NULL DEFAULT 0,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", 'presenca_brinde');

// ── 3.5 Galeria de mockups ──
$exec("CREATE TABLE IF NOT EXISTS presenca_brinde_imagem (
    id INT AUTO_INCREMENT PRIMARY KEY,
    brinde_id INT NOT NULL,
    arquivo VARCHAR(255) NOT NULL,
    tipo ENUM('mockup','foto_real','embalagem') NOT NULL DEFAULT 'mockup',
    principal TINYINT(1) NOT NULL DEFAULT 0,
    ordem SMALLINT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_brinde (brinde_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", 'presenca_brinde_imagem');

// ── 3.6 Composição de kits ──
$exec("CREATE TABLE IF NOT EXISTS presenca_brinde_componente (
    id INT AUTO_INCREMENT PRIMARY KEY,
    kit_id INT NOT NULL,
    componente_id INT NOT NULL,
    quantidade INT NOT NULL DEFAULT 1,
    KEY idx_kit (kit_id),
    KEY idx_comp (componente_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", 'presenca_brinde_componente');

// ── 3.7 Fornecedores ──
$exec("CREATE TABLE IF NOT EXISTS presenca_fornecedor (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(120) NOT NULL,
    contato_nome VARCHAR(120) NULL,
    telefone VARCHAR(40) NULL,
    email VARCHAR(120) NULL,
    site VARCHAR(160) NULL,
    observacoes TEXT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", 'presenca_fornecedor');

// ── 3.8 Orçamentos ──
$exec("CREATE TABLE IF NOT EXISTS presenca_orcamento (
    id INT AUTO_INCREMENT PRIMARY KEY,
    brinde_id INT NOT NULL,
    fornecedor_id INT NOT NULL,
    valor_unitario DECIMAL(10,2) NOT NULL,
    qtd_minima INT NOT NULL DEFAULT 1,
    frete DECIMAL(10,2) NOT NULL DEFAULT 0,
    prazo_producao_dias INT NULL,
    prazo_entrega_dias INT NULL,
    nota_qualidade TINYINT NULL,
    validade_ate DATE NULL,
    link_proposta VARCHAR(255) NULL,
    arquivo_proposta VARCHAR(255) NULL,
    score DECIMAL(5,2) NULL,
    escolhido TINYINT(1) NOT NULL DEFAULT 0,
    observacoes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_brinde (brinde_id),
    KEY idx_forn (fornecedor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", 'presenca_orcamento');

// ── 3.9 Estoque ──
$exec("CREATE TABLE IF NOT EXISTS presenca_estoque (
    brinde_id INT PRIMARY KEY,
    estoque_atual INT NOT NULL DEFAULT 0,
    estoque_minimo INT NOT NULL DEFAULT 0,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", 'presenca_estoque');

// ── 3.10 Matriz de regras ──
$exec("CREATE TABLE IF NOT EXISTS presenca_regra (
    id INT AUTO_INCREMENT PRIMARY KEY,
    perfil_id INT NOT NULL,
    fase_id INT NOT NULL,
    brinde_id INT NULL,
    frase_id INT NULL,
    verba_prevista DECIMAL(10,2) NOT NULL DEFAULT 0,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uq_perfil_fase (perfil_id, fase_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", 'presenca_regra');

// ── 3.11 Restrições de sensibilidade ──
$exec("CREATE TABLE IF NOT EXISTS presenca_restricao (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT NOT NULL,
    processo_id INT NULL,
    nivel ENUM('nao_enviar','confirmar_endereco') NOT NULL,
    motivo VARCHAR(255) NULL,
    criado_por INT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_cliente (cliente_id),
    KEY idx_ativo (ativo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", 'presenca_restricao');

// ── 3.12 Envios ──
$exec("CREATE TABLE IF NOT EXISTS presenca_envio (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT NOT NULL,
    processo_id INT NULL,
    perfil_id INT NOT NULL,
    fase_id INT NOT NULL,
    brinde_id INT NULL,
    frase_id INT NULL,
    fornecedor_id INT NULL,
    status ENUM('sugerido','aprovado','em_producao','enviado','entregue','cancelado') NOT NULL DEFAULT 'sugerido',
    bloqueado TINYINT(1) NOT NULL DEFAULT 0,
    bloqueio_motivo VARCHAR(255) NULL,
    data_alvo DATE NULL,
    data_pedido_limite DATE NULL,
    data_sugerida DATE NULL,
    data_aprovacao DATETIME NULL,
    data_envio DATE NULL,
    data_entrega DATE NULL,
    custo_previsto DECIMAL(10,2) NOT NULL DEFAULT 0,
    custo_real DECIMAL(10,2) NULL,
    centro_custo_id INT NULL,
    rastreio VARCHAR(80) NULL,
    aprovado_por INT NULL,
    origem ENUM('automatico','manual') NOT NULL DEFAULT 'manual',
    observacoes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_status (status),
    KEY idx_cliente (cliente_id),
    KEY idx_dedup (cliente_id, fase_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", 'presenca_envio');

// ── 3.13 Config e retenção ──
$exec("CREATE TABLE IF NOT EXISTS presenca_config (
    chave VARCHAR(60) PRIMARY KEY,
    valor VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", 'presenca_config');

$exec("CREATE TABLE IF NOT EXISTS presenca_evento_retencao (
    id INT AUTO_INCREMENT PRIMARY KEY,
    envio_id INT NOT NULL,
    tipo ENUM('indicacao','avaliacao','reativacao','post_espontaneo') NOT NULL,
    cliente_indicador_id INT NULL,
    processo_novo_id INT NULL,
    valor DECIMAL(10,2) NULL,
    data DATE NOT NULL,
    observacoes VARCHAR(255) NULL,
    KEY idx_envio (envio_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4", 'presenca_evento_retencao');

echo "\n=== Seed inicial ===\n\n";

// PERFIS
$stCount = (int)$pdo->query("SELECT COUNT(*) FROM presenca_perfil")->fetchColumn();
if ($stCount === 0) {
    $pdo->exec("INSERT INTO presenca_perfil (id,nome,slug,ticket_min,ticket_max,verba_min,verba_max,cor_hex,ordem) VALUES
     (1,'Essencial','essencial',0,1500,15,30,'#7E8F6E',1),
     (2,'Premium','premium',1500,7000,50,90,'#A9803B',2),
     (3,'Alta','alta',7000,NULL,150,300,'#0E2E36',3)");
    echo "✓ 3 perfis seed\n";
} else echo "· perfis já preenchidos ($stCount)\n";

// FASES
$stCount = (int)$pdo->query("SELECT COUNT(*) FROM presenca_fase")->fetchColumn();
if ($stCount === 0) {
    $pdo->exec("INSERT INTO presenca_fase (id,nome,slug,gatilho,tipo,recorrente,ordem) VALUES
     (1,'Boas-vindas','boas-vindas','Assinatura do contrato','processual',0,1),
     (2,'O fôlego','o-folego','Processo parado / perícia / conclusos','processual',0,2),
     (3,'O marco','o-marco','Liminar deferida / audiência realizada','processual',0,3),
     (4,'A nova fase','a-nova-fase','Sentença / acordo / trânsito em julgado','processual',0,4),
     (5,'Aniversário do caso','aniversario-caso','1 ano do caso ganho','efemeride',1,5)");
    echo "✓ 5 fases seed\n";
} else echo "· fases já preenchidas ($stCount)\n";

// FRASES
$stCount = (int)$pdo->query("SELECT COUNT(*) FROM presenca_frase")->fetchColumn();
if ($stCount === 0) {
    $pdo->exec("INSERT INTO presenca_frase (id,texto,fase_id,tom) VALUES
     (1,'Recomeçar também é um direito seu.',1,'recomeço'),
     (2,'A partir de agora, você não decide sozinho.',1,'companhia'),
     (3,'A espera também faz parte. E você não está nela sozinho.',2,'companhia'),
     (4,'Enquanto a Justiça caminha, essa luz fica acesa por você.',2,'paz'),
     (5,'Cada fase vencida, uma luz a mais.',3,'conquista'),
     (6,'Que a sua nova fase floresça.',3,'recomeço'),
     (7,'Toda nova fase merece uma luz para começar.',4,'recomeço'),
     (8,'Fecha-se um ciclo. Acende-se uma nova casa.',4,'recomeço'),
     (9,'Você não passou por isso sozinho.',NULL,'companhia'),
     (10,'Caminhar ao seu lado até aqui foi uma honra.',NULL,'companhia')");
    echo "✓ 10 frases seed\n";
} else echo "· frases já preenchidas ($stCount)\n";

// BRINDES
$stCount = (int)$pdo->query("SELECT COUNT(*) FROM presenca_brinde")->fetchColumn();
if ($stCount === 0) {
    $pdo->exec("INSERT INTO presenca_brinde (id,nome,categoria,embalagem,qtd_compra_referencia,eh_kit) VALUES
     (1,'Vela Votiva + Cartão','vela','Saco kraft com fita, lacre de cera e cartão',20,0),
     (2,'Ecobag Manifesto','ecobag','Cinta de papel com selo',30,0),
     (3,'Sachê Aromático','vela','Envelope de papel semente',40,0),
     (4,'Caixa Presenteável - Vela Média','kit','Caixa branded, palha, laço e tag',20,0),
     (5,'Suculenta em Vaso Branded','planta','Vaso cerâmico com marca a laser',20,0),
     (6,'Difusor de Ambiente','difusor','Caixa com berço e varetas',20,0),
     (7,'Kit Rígido Curado','kit','Caixa rígida, lacre de cera, entrega em mãos',10,1),
     (8,'Cartão Caligrafado + Lacre','cartao','Papel especial com lacre de cera',50,0)");
    echo "✓ 8 brindes seed\n";
    $pdo->exec("INSERT INTO presenca_brinde_componente (kit_id,componente_id,quantidade) VALUES (7,1,1),(7,6,1)");
    echo "✓ Kit VIP composto (vela + difusor)\n";
    $pdo->exec("INSERT INTO presenca_estoque (brinde_id,estoque_atual,estoque_minimo) VALUES
     (1,0,10),(2,0,10),(3,0,10),(4,0,5),(5,0,5),(6,0,5),(7,0,3),(8,0,20)");
    echo "✓ Estoque zerado (você preenche saldo real depois)\n";
} else echo "· brindes já preenchidos ($stCount)\n";

// MATRIZ DE REGRAS
$stCount = (int)$pdo->query("SELECT COUNT(*) FROM presenca_regra")->fetchColumn();
if ($stCount === 0) {
    $pdo->exec("INSERT INTO presenca_regra (perfil_id,fase_id,brinde_id,frase_id,verba_prevista) VALUES
     (1,1,2,1,20),(2,1,8,2,30),(3,1,8,10,60),
     (2,2,6,3,50),(3,2,6,4,120),
     (2,3,5,6,60),(3,3,5,5,90),
     (1,4,1,7,30),(2,4,4,8,90),(3,4,7,9,280)");
    echo "✓ 10 regras seed (matriz perfil × fase)\n";
} else echo "· matriz de regras já preenchida ($stCount)\n";

// CONFIG
$stCount = (int)$pdo->query("SELECT COUNT(*) FROM presenca_config")->fetchColumn();
if ($stCount === 0) {
    $pdo->exec("INSERT INTO presenca_config (chave,valor) VALUES
     ('teto_mensal','1500'),
     ('lead_dias_padrao','15'),
     ('automacao_ativa','0'),
     ('upload_max_mb','5'),
     ('peso_preco','0.45'),('peso_prazo','0.20'),('peso_qualidade','0.25'),('peso_qtd','0.10')");
    echo "✓ 8 configs seed (automação DESLIGADA por default)\n";
} else echo "· configs já preenchidos ($stCount)\n";

// CENTRO DE CUSTO — cria um pro Presença
$stCount = (int)$pdo->query("SELECT COUNT(*) FROM centro_custo WHERE slug = 'presenca'")->fetchColumn();
if ($stCount === 0) {
    $pdo->exec("INSERT INTO centro_custo (nome, slug, categoria, descricao) VALUES
     ('Presença', 'presenca', 'relacionamento', 'Verba de brindes e presentes do programa Presença')");
    echo "✓ Centro de custo 'Presença' criado\n";
} else echo "· centro de custo Presença já existe\n";

// Pastas de upload
$dirOrc = APP_ROOT . '/files/presenca/orcamentos';
$dirMock = APP_ROOT . '/files/presenca/mockups';
foreach (array($dirOrc, $dirMock) as $d) {
    if (!is_dir($d)) {
        @mkdir($d, 0755, true);
        @file_put_contents($d . '/.htaccess', "Options -ExecCGI\n<FilesMatch \"\\.(php|phtml|phps|cgi|pl|py|sh)$\">\n  Require all denied\n</FilesMatch>\n");
        echo "✓ Pasta criada: " . str_replace(APP_ROOT, '', $d) . " (.htaccess bloqueando exec)\n";
    } else echo "· Pasta já existe: " . str_replace(APP_ROOT, '', $d) . "\n";
}

echo "\n=== CONCLUÍDO ===\n";
echo "Próximo: /conecta/modules/presenca/\n";
