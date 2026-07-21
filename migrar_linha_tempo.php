<?php
/**
 * Migracao 21/07/2026 — Linha do Tempo do Cliente (pagina publica animada por processo).
 *
 * Cria:
 *  - case_timeline          (1 por caso: token, publicacao, trava por CPF, blocos de texto, midia)
 *  - case_timeline_eventos  (N marcos narrativos da linha do tempo)
 *  - case_timeline_tentativas (rate limit do gate de CPF na pagina publica)
 *
 * Rodar: /conecta/migrar_linha_tempo.php?key=fsa-hub-deploy-2026
 */
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Migracao Linha do Tempo do Cliente (Amanda 21/07/2026) ===\n\n";

$tabelas = array(

// ── 1. Configuracao da linha do tempo (1 por caso) ────────────────
'case_timeline' => "
CREATE TABLE IF NOT EXISTS case_timeline (
    id INT AUTO_INCREMENT PRIMARY KEY,
    case_id INT NOT NULL,
    token CHAR(32) NOT NULL,
    publicado TINYINT(1) NOT NULL DEFAULT 0,

    titulo VARCHAR(200) NULL,
    lede TEXT NULL,

    gate ENUM('cpf','aberto') NOT NULL DEFAULT 'cpf',
    gate_cpf VARCHAR(14) NULL,
    gate_label VARCHAR(120) NULL,

    painel_ok TEXT NULL,
    painel_atencao TEXT NULL,
    painel_acao TEXT NULL,

    pedidos TEXT NULL,
    pedidos_auto TINYINT(1) NOT NULL DEFAULT 1,
    proximos_passos TEXT NULL,
    fecho TEXT NULL,

    midia_url VARCHAR(500) NULL,
    midia_tipo ENUM('video','audio') NULL,
    midia_titulo VARCHAR(200) NULL,

    visualizacoes INT NOT NULL DEFAULT 0,
    ultima_visualizacao DATETIME NULL,
    publicado_em DATETIME NULL,

    criado_por INT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NULL,

    UNIQUE KEY uq_case (case_id),
    UNIQUE KEY uq_token (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

// ── 2. Marcos da linha do tempo ───────────────────────────────────
'case_timeline_eventos' => "
CREATE TABLE IF NOT EXISTS case_timeline_eventos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    timeline_id INT NOT NULL,
    data_evento DATE NULL,
    data_label VARCHAR(60) NULL,

    titulo VARCHAR(200) NOT NULL,
    texto TEXT NULL,
    nota TEXT NULL,

    tipo ENUM('nos','decisao','audiencia','recurso','marco','alerta','agora','outro')
        NOT NULL DEFAULT 'outro',
    destaque TINYINT(1) NOT NULL DEFAULT 0,
    visivel TINYINT(1) NOT NULL DEFAULT 1,
    ordem INT NOT NULL DEFAULT 0,

    andamento_id INT NULL,
    gerado_ia TINYINT(1) NOT NULL DEFAULT 0,
    editado_manual TINYINT(1) NOT NULL DEFAULT 0,

    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NULL,

    KEY idx_timeline (timeline_id, ordem),
    KEY idx_andamento (andamento_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

// ── 3. Rate limit do gate de CPF ──────────────────────────────────
'case_timeline_tentativas' => "
CREATE TABLE IF NOT EXISTS case_timeline_tentativas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    token CHAR(32) NOT NULL,
    ip VARCHAR(45) NOT NULL,
    sucesso TINYINT(1) NOT NULL DEFAULT 0,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_token_ip (token, ip, criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

);

foreach ($tabelas as $nome => $sql) {
    try {
        $pdo->exec($sql);
        echo "OK: tabela $nome\n";
    } catch (Throwable $e) {
        echo "ERRO em $nome: " . $e->getMessage() . "\n";
    }
}

// ── Confere se ficou tudo de pe ────────────────────────────────────
echo "\n--- Verificacao ---\n";
foreach (array_keys($tabelas) as $nome) {
    try {
        $n = (int)$pdo->query("SELECT COUNT(*) FROM `$nome`")->fetchColumn();
        echo "$nome: existe ($n registro(s))\n";
    } catch (Throwable $e) {
        echo "$nome: NAO EXISTE — " . $e->getMessage() . "\n";
    }
}

// ── Feature de IA nasce DESLIGADA (Amanda liga em /admin/ia_custo.php) ──
try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM configuracoes WHERE chave = 'ia_feature_linha_tempo_enabled'");
    $st->execute();
    if (!(int)$st->fetchColumn()) {
        $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES ('ia_feature_linha_tempo_enabled', '0')")
            ->execute();
        echo "\nFeature IA 'linha_tempo' criada DESLIGADA (ligar em /modules/admin/ia_custo.php).\n";
    } else {
        echo "\nFeature IA 'linha_tempo' ja existia — killswitch preservado.\n";
    }
} catch (Throwable $e) {
    echo "\nNao consegui registrar a feature de IA: " . $e->getMessage() . "\n";
}

echo "\n=== Concluido ===\n";
