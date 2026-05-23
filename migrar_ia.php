<?php
/**
 * migrar_ia.php — Infraestrutura do Módulo de IA do Hub.
 *
 * Cria:
 *  - Tabela ia_usage_log    (log de cada chamada à API com tokens + custo)
 *  - Coluna cases.ia_resumo + ia_resumo_em       (cache de resumo IA por caso)
 *  - Coluna case_andamentos.urgencia_ia          (classificação automática)
 *  - Coluna clients.esfriando_score + motivos    (detector de cliente esfriando)
 *  - Configurações (configuracoes):
 *      ia_users_autorizados        — CSV de user_ids que podem usar IA
 *      ia_feature_<x>_enabled      — killswitch por feature
 *      ia_orcamento_mensal_reais   — teto do orçamento mensal
 *      ia_alerta_orcamento_em      — controle pra não alertar mais de 1x/mes
 *
 * Uso (uma vez): curl "https://ferreiraesa.com.br/conecta/migrar_ia.php?key=fsa-hub-deploy-2026"
 */
require_once __DIR__ . '/core/database.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Negado.'); }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== MIGRAÇÃO IA ===\n\n";

// 1) ia_usage_log: telemetria + custo de cada chamada
echo "1. ia_usage_log...\n";
$pdo->exec("CREATE TABLE IF NOT EXISTS ia_usage_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    feature VARCHAR(50) NOT NULL,
    modelo VARCHAR(50) NOT NULL,
    user_id INT NULL,
    input_tokens INT DEFAULT 0,
    output_tokens INT DEFAULT 0,
    cached_input_tokens INT DEFAULT 0,
    custo_usd DECIMAL(10,6) DEFAULT 0,
    custo_brl DECIMAL(10,4) DEFAULT 0,
    duracao_ms INT DEFAULT 0,
    status VARCHAR(20) DEFAULT 'ok',
    erro TEXT NULL,
    contexto VARCHAR(200) NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_feature_data (feature, created_at),
    INDEX idx_user_data (user_id, created_at),
    INDEX idx_data (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
echo "   ok\n\n";

// 2) cache de resumo IA por caso (em cases pra acessar junto com o resto)
echo "2. cases.ia_resumo...\n";
try { $pdo->exec("ALTER TABLE cases ADD COLUMN ia_resumo TEXT NULL"); echo "   add ia_resumo\n"; } catch (Exception $e) { echo "   ja existe\n"; }
try { $pdo->exec("ALTER TABLE cases ADD COLUMN ia_resumo_em DATETIME NULL"); echo "   add ia_resumo_em\n"; } catch (Exception $e) { echo "   ja existe\n"; }
echo "\n";

// 3) classificação automática de andamento (urgente / normal / info)
echo "3. case_andamentos.urgencia_ia...\n";
try { $pdo->exec("ALTER TABLE case_andamentos ADD COLUMN urgencia_ia VARCHAR(10) NULL"); echo "   add urgencia_ia\n"; } catch (Exception $e) { echo "   ja existe\n"; }
try { $pdo->exec("ALTER TABLE case_andamentos ADD INDEX idx_urgencia (urgencia_ia)"); echo "   add idx_urgencia\n"; } catch (Exception $e) { echo "   ja existe\n"; }
echo "\n";

// 4) detector de cliente esfriando (sem IA — score numérico via cron)
echo "4. clients.esfriando_*...\n";
try { $pdo->exec("ALTER TABLE clients ADD COLUMN esfriando_score INT DEFAULT 0"); echo "   add esfriando_score\n"; } catch (Exception $e) { echo "   ja existe\n"; }
try { $pdo->exec("ALTER TABLE clients ADD COLUMN esfriando_motivos TEXT NULL"); echo "   add esfriando_motivos\n"; } catch (Exception $e) { echo "   ja existe\n"; }
try { $pdo->exec("ALTER TABLE clients ADD COLUMN esfriando_em DATETIME NULL"); echo "   add esfriando_em\n"; } catch (Exception $e) { echo "   ja existe\n"; }
echo "\n";

// 5) Configurações
echo "5. Configurações...\n";
$cfgs = array(
    // CSV de user_ids autorizados. Default: Amanda (1) e Luiz (descobre abaixo)
    'ia_users_autorizados'              => null,  // preenche abaixo
    // killswitches por feature (1=ligada, 0=desligada)
    'ia_feature_resumo_caso_enabled'    => '1',
    'ia_feature_classif_andamento_enabled' => '1',
    'ia_feature_cliente_esfriando_enabled' => '1',
    // orçamento mensal em reais — alerta quando atinge
    'ia_orcamento_mensal_reais'         => '300',
    'ia_alerta_orcamento_em'            => '',
    // câmbio usado pra converter custo USD em BRL (atualizar manualmente se variar muito)
    'ia_cambio_brl'                     => '5.50',
);

// Descobrir user_ids autorizados: Amanda (admin user_id=1) + Luiz (procura pelo nome)
$autorizados = array(1); // Amanda admin sempre
try {
    $st = $pdo->prepare("SELECT id FROM users WHERE LOWER(name) LIKE '%luiz%' AND is_active = 1 ORDER BY id LIMIT 1");
    $st->execute();
    $luizId = (int)$st->fetchColumn();
    if ($luizId > 0) { $autorizados[] = $luizId; echo "   Luiz detectado: user_id=$luizId\n"; }
    else { echo "   Luiz NAO detectado — Amanda libera manualmente no admin\n"; }
} catch (Exception $e) {}
$cfgs['ia_users_autorizados'] = implode(',', $autorizados);

// Insere/atualiza
$stIns = $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = IF(VALUES(valor) IS NULL, valor, COALESCE(NULLIF(valor,''), VALUES(valor)))");
foreach ($cfgs as $k => $v) {
    if ($v === null) continue;
    // só insere se nao existe (nao sobrescreve config que admin ja ajustou)
    $exist = $pdo->prepare("SELECT 1 FROM configuracoes WHERE chave = ?");
    $exist->execute(array($k));
    if ($exist->fetchColumn()) { echo "   [skip] $k ja existe\n"; continue; }
    $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES (?, ?)")->execute(array($k, (string)$v));
    echo "   [add] $k = $v\n";
}

echo "\n=== CONCLUIDO ===\n";
