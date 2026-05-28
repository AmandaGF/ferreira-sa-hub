<?php
/**
 * Ferreira & Sa Hub -- Migration: Modulo Previdenciario
 *
 * Cria as 3 tabelas que sustentam o modulo /modules/previdenciario/:
 *   - cases_previdenciario  (one-to-one com cases, dados INSS)
 *   - prev_pericias         (1:N pericias por caso)
 *   - prev_exigencias       (1:N exigencias por caso)
 *   - prev_migracao_pendente (staging da importacao planilha)
 *
 * Tambem garante 'previdenciario' como valor aceito em cases.category
 * (VARCHAR ja, so documenta).
 *
 * Acesso: /conecta/migrar_modulo_previdenciario.php?key=fsa-hub-deploy-2026
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }

require_once __DIR__ . '/core/database.php';
header('Content-Type: text/html; charset=utf-8');

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo '<!doctype html><meta charset="utf-8">';
echo '<style>
body{font-family:system-ui,sans-serif;background:#f8f4ef;color:#052228;padding:1.5rem;max-width:920px;margin:0 auto;}
h1{color:#052228;border-bottom:3px solid #B87333;padding-bottom:.5rem;}
.ok{background:#d1fae5;color:#065f46;padding:.6rem .9rem;border-radius:8px;margin:.5rem 0;border-left:4px solid #10b981;}
.erro{background:#fee2e2;color:#7f1d1d;padding:.6rem .9rem;border-radius:8px;margin:.5rem 0;border-left:4px solid #dc2626;}
.info{background:#fef3c7;color:#92400e;padding:.6rem .9rem;border-radius:8px;margin:.5rem 0;border-left:4px solid #f59e0b;}
code{background:#e5e7eb;padding:1px 5px;border-radius:3px;font-size:.85rem;}
h2{color:#6a3c2c;margin-top:1.5rem;}
</style>';

echo '<h1>Migration — Modulo Previdenciario</h1>';

function exec_safe(PDO $pdo, $sql, $descricao) {
    try {
        $pdo->exec($sql);
        echo '<div class="ok">&#x2705; ' . htmlspecialchars($descricao) . '</div>';
        return true;
    } catch (Exception $e) {
        echo '<div class="erro">&#x274C; ' . htmlspecialchars($descricao) . ': ' . htmlspecialchars($e->getMessage()) . '</div>';
        return false;
    }
}

echo '<h2>1. cases_previdenciario (dados INSS por caso)</h2>';
exec_safe($pdo, "
CREATE TABLE IF NOT EXISTS cases_previdenciario (
  case_id INT UNSIGNED PRIMARY KEY,
  especie ENUM(
    'aposentadoria',
    'aposentadoria_idade',
    'aposentadoria_tempo_contribuicao',
    'aposentadoria_especial',
    'aposentadoria_invalidez',
    'aposentadoria_invalidez_conversao',
    'aposentadoria_pcd',
    'auxilio_doenca',
    'auxilio_doenca_prorrogacao',
    'auxilio_doenca_conversao',
    'auxilio_acidente',
    'beneficio_incapacidade',
    'salario_maternidade',
    'salario_paternidade',
    'pensao_por_morte',
    'pensao_zika_lei_15156',
    'bpc_loas',
    'auxilio_reclusao',
    'revisao_beneficio',
    'restabelecimento',
    'inss_generico',
    'rpps',
    'previdencia_complementar',
    'outro'
  ) NOT NULL DEFAULT 'inss_generico',
  codigo_b VARCHAR(8) NULL COMMENT 'B31, B32, B41, B80, B87, B21 etc.',
  nb VARCHAR(20) NULL COMMENT 'Numero do Beneficio INSS',
  der DATE NULL COMMENT 'Data de Entrada do Requerimento',
  dib DATE NULL COMMENT 'Data de Inicio do Beneficio',
  dcb DATE NULL COMMENT 'Data de Cessacao do Beneficio',
  protocolo_meu_inss VARCHAR(50) NULL,
  fase ENUM('pre_requerimento','adm','recurso_adm','jef','vara_federal','crps','tnu','stj','stf','concluido') NOT NULL DEFAULT 'adm',
  rmi DECIMAL(12,2) NULL,
  rma DECIMAL(12,2) NULL,
  valor_atrasados DECIMAL(12,2) NULL,
  data_calculo_atrasados DATE NULL,
  resultado_adm ENUM('pendente','deferido','indeferido','exigencia','cessado','arquivado') NOT NULL DEFAULT 'pendente',
  data_decisao_adm DATE NULL,
  motivo_indeferimento VARCHAR(500) NULL,
  carta_indeferimento_path VARCHAR(500) NULL,
  cnis_atualizado TINYINT(1) DEFAULT 0,
  data_ultimo_cnis DATE NULL,
  cnis_observacoes TEXT NULL,
  recurso_protocolado TINYINT(1) DEFAULT 0,
  data_recurso DATE NULL,
  numero_recurso VARCHAR(50) NULL,
  camara_julgadora VARCHAR(100) NULL,
  exposicao_agentes_nocivos TINYINT(1) DEFAULT 0,
  agentes_nocivos_descricao TEXT NULL,
  ppp_recebido TINYINT(1) DEFAULT 0,
  ltcat_recebido TINYINT(1) DEFAULT 0,
  beneficio_implementado TINYINT(1) DEFAULT 0,
  data_implementacao DATE NULL,
  monitorar_radar TINYINT(1) DEFAULT 0,
  radar_observacao VARCHAR(500) NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_by INT UNSIGNED NULL,
  CONSTRAINT fk_prev_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE,
  INDEX idx_especie (especie),
  INDEX idx_fase (fase),
  INDEX idx_resultado (resultado_adm),
  INDEX idx_der (der),
  INDEX idx_radar (monitorar_radar)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
", 'cases_previdenciario criada (ou ja existia)');

echo '<h2>2. prev_pericias (1:N por caso)</h2>';
exec_safe($pdo, "
CREATE TABLE IF NOT EXISTS prev_pericias (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  case_id INT UNSIGNED NOT NULL,
  tipo ENUM('medica_adm','medica_jud','social_adm','social_jud','psiquiatrica','reabilitacao') NOT NULL,
  data_agendada DATETIME NULL,
  data_realizada DATETIME NULL,
  local VARCHAR(255) NULL,
  perito_nome VARCHAR(150) NULL,
  cliente_compareceu TINYINT(1) DEFAULT NULL,
  motivo_falta VARCHAR(255) NULL,
  resultado ENUM('aguardando','apto','incapaz_total','incapaz_parcial','incapaz_definitivo','recuperavel','sem_incapacidade') DEFAULT 'aguardando',
  laudo_path VARCHAR(500) NULL,
  observacoes TEXT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  created_by INT UNSIGNED NULL,
  CONSTRAINT fk_pericia_case FOREIGN KEY (case_id) REFERENCES cases_previdenciario(case_id) ON DELETE CASCADE,
  INDEX idx_data_agendada (data_agendada),
  INDEX idx_resultado (resultado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
", 'prev_pericias criada (ou ja existia)');

echo '<h2>3. prev_exigencias (1:N por caso)</h2>';
exec_safe($pdo, "
CREATE TABLE IF NOT EXISTS prev_exigencias (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  case_id INT UNSIGNED NOT NULL,
  data_abertura DATE NOT NULL,
  prazo_cumprimento DATE NULL,
  data_cumprimento DATE NULL,
  descricao TEXT NOT NULL,
  documentos_solicitados TEXT NULL,
  status ENUM('aberta','cumprida','vencida','prorrogada') NOT NULL DEFAULT 'aberta',
  aguardando_cliente TINYINT(1) DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  created_by INT UNSIGNED NULL,
  CONSTRAINT fk_exigencia_case FOREIGN KEY (case_id) REFERENCES cases_previdenciario(case_id) ON DELETE CASCADE,
  INDEX idx_status (status),
  INDEX idx_prazo (prazo_cumprimento)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
", 'prev_exigencias criada (ou ja existia)');

echo '<h2>4. prev_migracao_pendente (staging da importacao planilha)</h2>';
exec_safe($pdo, "
CREATE TABLE IF NOT EXISTS prev_migracao_pendente (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cliente_nome VARCHAR(255) NOT NULL,
  demanda_original VARCHAR(255) NULL,
  especie_normalizada VARCHAR(60) NOT NULL,
  codigo_b VARCHAR(8) NULL,
  fase VARCHAR(40) NULL,
  status_normalizado VARCHAR(40) NULL,
  observacoes TEXT NULL,
  profissional VARCHAR(80) NULL,
  motivo_pendencia ENUM('sem_cliente','cliente_ambiguo') NOT NULL,
  candidatos_client_ids VARCHAR(255) NULL COMMENT 'CSV de client_ids quando ambiguo',
  resolvida TINYINT(1) DEFAULT 0,
  case_id_criado INT UNSIGNED NULL,
  resolvido_em DATETIME NULL,
  resolvido_por INT UNSIGNED NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_resolvida (resolvida),
  INDEX idx_motivo (motivo_pendencia)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
", 'prev_migracao_pendente criada (ou ja existia)');

echo '<h2>5. Verifica cases.category aceita "previdenciario"</h2>';
try {
    $row = $pdo->query("SHOW COLUMNS FROM cases LIKE 'category'")->fetch();
    if ($row) {
        echo '<div class="info">cases.category tipo: <code>' . htmlspecialchars($row['Type']) . '</code></div>';
        echo '<div class="ok">Coluna existe; vamos usar valor literal <code>previdenciario</code> nos novos cases.</div>';
    } else {
        echo '<div class="erro">cases.category NAO existe — verificar antes de seguir!</div>';
    }
} catch (Exception $e) {
    echo '<div class="erro">Erro: ' . htmlspecialchars($e->getMessage()) . '</div>';
}

echo '<h2>FIM</h2>';
echo '<div class="ok"><strong>Schema pronto.</strong> Proximo passo: rodar <code>migrar_demandas_prev.php?key=fsa-hub-deploy-2026&amp;modo=simular</code> para ver o que sera importado da planilha (sem persistir).</div>';
