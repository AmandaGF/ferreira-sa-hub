<?php
/**
 * Migracao: adiciona 'para_execucao_ia' ao ENUM cases.status
 *
 * Amanda pediu em 07/06/2026 nova coluna "Para Execucao - IA" no Kanban Operacional.
 * Esta migracao expande o ENUM pra aceitar o novo status. Sem isso, UPDATE com
 * status='para_execucao_ia' silenciosamente vira string vazia/erro de truncamento.
 *
 * Idempotente + defensivo: le o ENUM ATUAL em producao (que pode ter sido
 * expandido por migracoes anteriores nao refletidas no schema.sql), extrai
 * todos os valores existentes via regex, e adiciona 'para_execucao_ia' se
 * faltar. Preserva tudo o que ja esta la.
 *
 * Uso: curl -s "https://ferreiraesa.com.br/conecta/migrar_status_para_execucao_ia.php?key=fsa-hub-deploy-2026"
 */

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';

if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') {
    http_response_code(403);
    exit('forbidden');
}

header('Content-Type: text/plain; charset=utf-8');

$pdo = db();

echo "=== Migracao: cases.status += 'para_execucao_ia' ===\n\n";

try {
    $row = $pdo->query("SHOW COLUMNS FROM cases LIKE 'status'")->fetch();
    if (!$row) {
        echo "[ERRO] Coluna cases.status nao encontrada.\n";
        exit;
    }
    $tipo = $row['Type'];
    echo "Tipo atual: $tipo\n\n";

    if (strpos($tipo, "'para_execucao_ia'") !== false) {
        echo "[SKIP] ENUM ja contem 'para_execucao_ia'. NOOP.\n";
        exit;
    }

    // Extrai TODOS os valores existentes via regex (preserva o que esta em prod)
    if (!preg_match("/^enum\\((.+)\\)$/i", $tipo, $m)) {
        echo "[ERRO] Tipo nao parece ser ENUM: $tipo\n";
        exit;
    }
    preg_match_all("/'((?:[^']|'')*)'/", $m[1], $matches);
    $valoresAtuais = $matches[1];

    if (empty($valoresAtuais)) {
        echo "[ERRO] Nao consegui extrair valores do ENUM.\n";
        exit;
    }

    echo "Valores atuais (" . count($valoresAtuais) . "):\n";
    foreach ($valoresAtuais as $v) echo "  - $v\n";
    echo "\n";

    // Adiciona o novo no final
    $valoresAtuais[] = 'para_execucao_ia';

    $listaQuoted = "'" . implode("','", array_map(function($v) {
        return str_replace("'", "''", $v);
    }, $valoresAtuais)) . "'";

    // Preserva o DEFAULT atual (se houver)
    $sqlDefault = '';
    if (!empty($row['Default'])) {
        $sqlDefault = " DEFAULT '" . str_replace("'", "''", $row['Default']) . "'";
    }
    $sqlNotNull = ($row['Null'] === 'NO') ? ' NOT NULL' : '';

    $sql = "ALTER TABLE cases MODIFY COLUMN `status` ENUM($listaQuoted)$sqlNotNull$sqlDefault";
    echo "SQL a executar:\n$sql\n\n";

    $pdo->exec($sql);
    echo "[OK] ENUM expandido.\n\n";

    $row2 = $pdo->query("SHOW COLUMNS FROM cases LIKE 'status'")->fetch();
    echo "Tipo apos migracao:\n$row2[Type]\n\n";

    echo "=== MIGRACAO CONCLUIDA ===\n";
} catch (Exception $e) {
    echo "[ERRO] " . $e->getMessage() . "\n";
}
