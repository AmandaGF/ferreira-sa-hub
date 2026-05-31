<?php
// migrar_zapi_fluxos.php
// Fundacao do MOTOR DE FLUXOS do WhatsApp (familia zapi_fluxo*).
// Idempotente: CREATE TABLE IF NOT EXISTS — nunca apaga nem altera tabela existente.
//
// Padrao de bootstrap: igual aos demais migrar_*.php da casa
// (key check + die + header text/plain + require config+database + factory db()).
//
// Disparar via: curl -s "https://ferreiraesa.com.br/conecta/migrar_zapi_fluxos.php?key=fsa-hub-deploy-2026"

if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Migração: Motor de Fluxos do WhatsApp (zapi_fluxo*) ===\n\n";

// Snapshot do total de tabelas ANTES (pra verificar diff = 6)
$tabelasAntes = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
echo "Total de tabelas no banco ANTES: " . count($tabelasAntes) . "\n\n";

$statements = [

// 1) O fluxo em si
"CREATE TABLE IF NOT EXISTS zapi_fluxo (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(150) NOT NULL,
  descricao TEXT NULL,
  canal VARCHAR(10) NULL,
  gatilho_tipo VARCHAR(30) NOT NULL DEFAULT 'manual',
  gatilho_config TEXT NULL,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  execucoes INT UNSIGNED NOT NULL DEFAULT 0,
  bloco_inicial_id INT UNSIGNED NULL,
  criado_por INT UNSIGNED NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (ativo),
  INDEX (gatilho_tipo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

// 2) Os blocos (nos do fluxo)
"CREATE TABLE IF NOT EXISTS zapi_fluxo_bloco (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  fluxo_id INT UNSIGNED NOT NULL,
  tipo VARCHAR(20) NOT NULL,
  config_json LONGTEXT NULL,
  pos_x INT NOT NULL DEFAULT 0,
  pos_y INT NOT NULL DEFAULT 0,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (fluxo_id),
  CONSTRAINT fk_zfb_fluxo FOREIGN KEY (fluxo_id) REFERENCES zapi_fluxo(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

// 3) As conexoes entre blocos (arestas com saida nomeada)
"CREATE TABLE IF NOT EXISTS zapi_fluxo_aresta (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  fluxo_id INT UNSIGNED NOT NULL,
  origem_bloco_id INT UNSIGNED NOT NULL,
  destino_bloco_id INT UNSIGNED NOT NULL,
  saida VARCHAR(50) NOT NULL DEFAULT 'default',
  INDEX (fluxo_id),
  INDEX (origem_bloco_id),
  CONSTRAINT fk_zfa_fluxo FOREIGN KEY (fluxo_id) REFERENCES zapi_fluxo(id) ON DELETE CASCADE,
  CONSTRAINT fk_zfa_origem FOREIGN KEY (origem_bloco_id) REFERENCES zapi_fluxo_bloco(id) ON DELETE CASCADE,
  CONSTRAINT fk_zfa_destino FOREIGN KEY (destino_bloco_id) REFERENCES zapi_fluxo_bloco(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

// 4) O estado da execucao por conversa (onde cada contato parou). O cron varre 'aguardando_ate'.
// conversa_id liga-se a zapi_conversas via INDEX, SEM foreign key rigida (de proposito: evita
// que diferenca de tipo na tabela existente faca a migracao falhar; o vinculo e' garantido em PHP).
"CREATE TABLE IF NOT EXISTS zapi_fluxo_execucao (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  fluxo_id INT UNSIGNED NOT NULL,
  conversa_id INT UNSIGNED NOT NULL,
  bloco_atual_id INT UNSIGNED NULL,
  estado VARCHAR(20) NOT NULL DEFAULT 'em_andamento',
  aguardando_ate DATETIME NULL,
  tentativas INT UNSIGNED NOT NULL DEFAULT 0,
  iniciado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (conversa_id),
  INDEX (estado),
  INDEX (aguardando_ate),
  CONSTRAINT fk_zfe_fluxo FOREIGN KEY (fluxo_id) REFERENCES zapi_fluxo(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

// 5) Definicao dos campos estruturados que o fluxo coleta
"CREATE TABLE IF NOT EXISTS zapi_campo (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  chave VARCHAR(60) NOT NULL UNIQUE,
  nome VARCHAR(120) NOT NULL,
  tipo VARCHAR(20) NOT NULL DEFAULT 'texto',
  descricao TEXT NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

// 6) Valores coletados por conversa (o intake estruturado que alimenta a Fabrica de Peticoes)
"CREATE TABLE IF NOT EXISTS zapi_conversa_valor (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  conversa_id INT UNSIGNED NOT NULL,
  campo_id INT UNSIGNED NOT NULL,
  valor TEXT NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_conversa_campo (conversa_id, campo_id),
  INDEX (campo_id),
  CONSTRAINT fk_zcv_campo FOREIGN KEY (campo_id) REFERENCES zapi_campo(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

];

// Lista das tabelas que CADA statement deveria criar (na mesma ordem do array).
$alvos = ['zapi_fluxo','zapi_fluxo_bloco','zapi_fluxo_aresta','zapi_fluxo_execucao','zapi_campo','zapi_conversa_valor'];

$erros = array();
foreach ($statements as $i => $sql) {
    $alvo = $alvos[$i];
    $jaExistia = in_array($alvo, $tabelasAntes, true);
    try {
        $pdo->exec($sql);
        $existeAgora = (bool)$pdo->query("SHOW TABLES LIKE " . $pdo->quote($alvo))->fetchColumn();
        if (!$existeAgora) {
            echo "  [ERRO] $alvo — exec OK mas SHOW TABLES nao a encontrou\n";
            $erros[] = $alvo . ': nao apareceu apos exec';
        } elseif ($jaExistia) {
            echo "  [OK ja existia] $alvo\n";
        } else {
            echo "  [CRIADA agora ] $alvo\n";
        }
    } catch (PDOException $e) {
        error_log('[migrar_zapi_fluxos] ' . $e->getMessage());
        echo "  [FALHA] $alvo: " . $e->getMessage() . "\n";
        $erros[] = $alvo . ': ' . $e->getMessage();
    }
}

echo "\n--- Verificacao final ---\n";

// 4 da familia zapi_fluxo*
$familiaFluxo = $pdo->query("SHOW TABLES LIKE 'zapi_fluxo%'")->fetchAll(PDO::FETCH_COLUMN);
echo "zapi_fluxo%: " . count($familiaFluxo) . " tabelas — " . implode(', ', $familiaFluxo) . "\n";

// 2 individuais
$tabCampo  = $pdo->query("SHOW TABLES LIKE 'zapi_campo'")->fetchColumn();
$tabValor  = $pdo->query("SHOW TABLES LIKE 'zapi_conversa_valor'")->fetchColumn();
echo "zapi_campo: " . ($tabCampo ?: '(NAO ENCONTRADA)') . "\n";
echo "zapi_conversa_valor: " . ($tabValor ?: '(NAO ENCONTRADA)') . "\n";

// Diff total de tabelas
$tabelasDepois = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
$diff = count($tabelasDepois) - count($tabelasAntes);
echo "\nTotal de tabelas DEPOIS: " . count($tabelasDepois) . "\n";
echo "DIFF: +$diff tabelas (esperado: +6 na primeira execucao, +0 nas seguintes)\n";

// Tabelas novas (que nao existiam antes)
$novas = array_values(array_diff($tabelasDepois, $tabelasAntes));
if (!empty($novas)) {
    echo "Novas: " . implode(', ', $novas) . "\n";
}

// Sanity: nenhuma tabela pre-existente desapareceu
$removidas = array_values(array_diff($tabelasAntes, $tabelasDepois));
if (!empty($removidas)) {
    echo "\n[ALERTA] Tabelas desaparecidas (NUNCA deveria acontecer): " . implode(', ', $removidas) . "\n";
} else {
    echo "Nenhuma tabela pre-existente foi removida ou renomeada. OK.\n";
}

echo "\n=== Fim ===\n";
if ($erros) {
    echo "ERROS: " . count($erros) . "\n";
} else {
    echo "Sem erros.\n";
}
