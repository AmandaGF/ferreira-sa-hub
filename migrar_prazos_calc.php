<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave inválida'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Migração: Calculadora de Prazos ===\n\n";

$queries = array(
    "CREATE TABLE IF NOT EXISTS prazos_suspensoes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        data_inicio DATE NOT NULL,
        data_fim DATE NOT NULL,
        tipo ENUM('feriado_nacional','feriado_estadual','feriado_municipal','recesso','suspensao_chuvas','suspensao_energia','suspensao_sistema','ponto_facultativo','carnaval','semana_santa','outros') NOT NULL,
        abrangencia ENUM('todo_estado','comarca_especifica','capital') NOT NULL DEFAULT 'todo_estado',
        comarca VARCHAR(100) DEFAULT NULL,
        motivo VARCHAR(300) NOT NULL,
        ato_legislacao VARCHAR(200) DEFAULT NULL,
        publicacao DATE DEFAULT NULL,
        fonte_pdf VARCHAR(200) DEFAULT NULL,
        criado_por INT UNSIGNED DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_datas (data_inicio, data_fim),
        INDEX idx_comarca (comarca)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    "CREATE TABLE IF NOT EXISTS prazos_calculos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        case_id INT UNSIGNED DEFAULT NULL,
        tipo_prazo VARCHAR(100) DEFAULT NULL,
        data_disponibilizacao DATE NOT NULL,
        data_publicacao DATE NOT NULL,
        data_inicio_contagem DATE NOT NULL,
        quantidade INT NOT NULL,
        unidade ENUM('dias','meses') NOT NULL DEFAULT 'dias',
        comarca VARCHAR(100) DEFAULT NULL,
        data_fatal DATE NOT NULL,
        observacoes TEXT DEFAULT NULL,
        calculado_por INT UNSIGNED DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
);

foreach ($queries as $q) {
    try {
        $pdo->exec($q);
        echo "[OK] " . substr(trim($q), 0, 60) . "...\n";
    } catch (Exception $e) {
        echo "[INFO] " . $e->getMessage() . "\n";
    }
}

// Popular feriados 2026
echo "\n--- Populando feriados 2026 ---\n";
$feriados = array(
    // Feriados nacionais fixos
    array('2026-01-01','2026-01-01','feriado_nacional','todo_estado','Confraternizacao Universal','Lei Federal 10.607/2002'),
    array('2026-04-21','2026-04-21','feriado_nacional','todo_estado','Tiradentes','Lei Federal 10.607/2002'),
    array('2026-05-01','2026-05-01','feriado_nacional','todo_estado','Dia do Trabalho','Lei Federal 10.607/2002'),
    array('2026-09-07','2026-09-07','feriado_nacional','todo_estado','Independencia do Brasil','Lei Federal 10.607/2002'),
    array('2026-10-12','2026-10-12','feriado_nacional','todo_estado','N.S. Aparecida','Lei Federal 10.607/2002'),
    array('2026-11-02','2026-11-02','feriado_nacional','todo_estado','Finados','Lei Federal 10.607/2002'),
    array('2026-11-15','2026-11-15','feriado_nacional','todo_estado','Proclamacao da Republica','Lei Federal 10.607/2002'),
    array('2026-12-25','2026-12-25','feriado_nacional','todo_estado','Natal','Lei Federal 10.607/2002'),
    // Feriados estaduais RJ
    array('2026-04-23','2026-04-23','feriado_estadual','todo_estado','Dia de Sao Jorge','Lei Estadual 5198/2008'),
    array('2026-11-20','2026-11-20','feriado_estadual','todo_estado','Consciencia Negra','Lei Estadual 7716/2017'),
    // Carnaval 2026 (16-18 fev)
    array('2026-02-16','2026-02-18','carnaval','todo_estado','Carnaval','Lei 10.633/2024, art. 83 III'),
    // Semana Santa 2026 (Sexta-feira Santa = 03/04)
    array('2026-04-02','2026-04-03','semana_santa','todo_estado','Semana Santa (Quinta e Sexta)','Lei 10.633/2024, art. 83 IV'),
    // Corpus Christi 2026 (04/06)
    array('2026-06-04','2026-06-04','feriado_nacional','todo_estado','Corpus Christi','Variavel'),
    // Recesso forense
    array('2026-12-20','2027-01-06','recesso','todo_estado','Recesso Forense','Ato Executivo TJ 168/2025'),
    // Dia do Advogado (ponto facultativo TJ)
    array('2026-08-11','2026-08-11','ponto_facultativo','todo_estado','Dia do Advogado','Ato Executivo TJ'),
);

$stmtFer = $pdo->prepare("INSERT IGNORE INTO prazos_suspensoes (data_inicio, data_fim, tipo, abrangencia, motivo, ato_legislacao) VALUES (?,?,?,?,?,?)");
foreach ($feriados as $f) {
    try {
        $stmtFer->execute($f);
        echo "[OK] {$f[4]} ({$f[0]})\n";
    } catch (Exception $e) {
        echo "[SKIP] {$f[4]}: " . $e->getMessage() . "\n";
    }
}

echo "\n=== FIM ===\n";
