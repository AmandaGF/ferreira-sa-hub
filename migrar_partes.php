<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave inválida'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Migração: Partes do Processo ===\n\n";

// 1. Criar tabela
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS case_partes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        case_id INT NOT NULL,
        papel VARCHAR(30) NOT NULL,
        tipo_pessoa VARCHAR(10) NOT NULL DEFAULT 'fisica',
        nome VARCHAR(200),
        cpf VARCHAR(14),
        rg VARCHAR(20),
        nascimento DATE,
        profissao VARCHAR(100),
        estado_civil VARCHAR(30),
        razao_social VARCHAR(200),
        cnpj VARCHAR(18),
        nome_fantasia VARCHAR(200),
        representante_nome VARCHAR(200),
        representante_cpf VARCHAR(14),
        email VARCHAR(200),
        telefone VARCHAR(20),
        endereco VARCHAR(300),
        cidade VARCHAR(100),
        uf VARCHAR(2),
        cep VARCHAR(9),
        client_id INT,
        representa_parte_id INT,
        observacoes TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_case (case_id),
        INDEX idx_papel (papel),
        INDEX idx_cpf (cpf),
        INDEX idx_cnpj (cnpj),
        INDEX idx_client (client_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] Tabela case_partes criada\n";
} catch (Exception $e) {
    echo "[INFO] " . $e->getMessage() . "\n";
}

// 2. Migrar réus existentes de cases.parte_re_nome
try {
    $migrados = $pdo->exec("INSERT IGNORE INTO case_partes (case_id, papel, tipo_pessoa, nome, cpf)
        SELECT id, 'reu', 'fisica', parte_re_nome, parte_re_cpf_cnpj
        FROM cases WHERE parte_re_nome IS NOT NULL AND parte_re_nome != ''
        AND id NOT IN (SELECT case_id FROM case_partes WHERE papel = 'reu')");
    echo "[OK] Réus migrados: $migrados\n";
} catch (Exception $e) {
    echo "[INFO] " . $e->getMessage() . "\n";
}

// 3. Migrar autores (clientes vinculados ao caso)
try {
    $migrados = $pdo->exec("INSERT IGNORE INTO case_partes (case_id, papel, tipo_pessoa, nome, cpf, client_id)
        SELECT cs.id, 'autor', 'fisica', cl.name, cl.cpf, cl.id
        FROM cases cs
        JOIN clients cl ON cl.id = cs.client_id
        WHERE cs.client_id IS NOT NULL
        AND cs.id NOT IN (SELECT case_id FROM case_partes WHERE papel = 'autor')");
    echo "[OK] Autores migrados: $migrados\n";
} catch (Exception $e) {
    echo "[INFO] " . $e->getMessage() . "\n";
}

// 4. Contar
$total = $pdo->query("SELECT COUNT(*) FROM case_partes")->fetchColumn();
$porPapel = $pdo->query("SELECT papel, COUNT(*) as t FROM case_partes GROUP BY papel")->fetchAll();
echo "\nTotal partes: $total\n";
foreach ($porPapel as $p) echo "  " . $p['papel'] . ": " . $p['t'] . "\n";

echo "\n=== FIM ===\n";
