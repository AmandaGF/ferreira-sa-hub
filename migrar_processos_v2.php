<?php
/**
 * Migração: adicionar colunas para melhorias no módulo de processos
 * - sistema_tribunal (PJE, DCP, ESaj, EProc, etc.)
 * - segredo_justica (0/1)
 * - departamento (operacional, administrativo, etc.)
 * - comarca_uf (UF separado da cidade)
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== MIGRAÇÃO: Melhorias Processos v2 ===\n\n";

$alteracoes = array(
    "ADD COLUMN sistema_tribunal VARCHAR(30) NULL DEFAULT NULL AFTER comarca" =>
        "sistema_tribunal — PJE, DCP, ESaj, EProc, etc.",
    "ADD COLUMN segredo_justica TINYINT(1) NOT NULL DEFAULT 0 AFTER sistema_tribunal" =>
        "segredo_justica — sim/não",
    "ADD COLUMN departamento VARCHAR(40) NULL DEFAULT 'operacional' AFTER responsible_user_id" =>
        "departamento — operacional, administrativo, etc.",
    "ADD COLUMN comarca_uf CHAR(2) NULL DEFAULT NULL AFTER comarca" =>
        "comarca_uf — UF separado (para busca por estado)",
);

foreach ($alteracoes as $sql => $desc) {
    $colName = trim(explode(' ', trim(explode('COLUMN', $sql)[1]))[0]);

    // Verificar se coluna já existe
    $chk = $pdo->query("SHOW COLUMNS FROM cases LIKE '$colName'");
    if ($chk->fetch()) {
        echo "[JÁ EXISTE] $colName — $desc\n";
        continue;
    }

    try {
        $pdo->exec("ALTER TABLE cases $sql");
        echo "[OK] $colName — $desc\n";
    } catch (Exception $e) {
        echo "[ERRO] $colName — " . $e->getMessage() . "\n";
    }
}

// Tentar extrair UF das comarcas existentes (formato "Cidade - UF" ou "Cidade/UF")
echo "\n--- Extraindo UF das comarcas existentes ---\n";
$rows = $pdo->query("SELECT id, comarca FROM cases WHERE comarca IS NOT NULL AND comarca != '' AND (comarca_uf IS NULL OR comarca_uf = '')")->fetchAll();
$updated = 0;
foreach ($rows as $r) {
    $uf = null;
    // Padrão: "Cidade - UF" ou "Cidade/UF" ou "Cidade -UF"
    if (preg_match('/[\s\-\/]+([A-Z]{2})\s*$/i', $r['comarca'], $m)) {
        $uf = strtoupper($m[1]);
    }
    if ($uf && strlen($uf) === 2) {
        $pdo->prepare("UPDATE cases SET comarca_uf = ? WHERE id = ?")->execute(array($uf, $r['id']));
        $updated++;
    }
}
echo "UFs extraídas: $updated de " . count($rows) . " comarcas\n";

// Preencher departamento = 'operacional' onde está NULL
$affected = $pdo->exec("UPDATE cases SET departamento = 'operacional' WHERE departamento IS NULL OR departamento = ''");
echo "Departamento 'operacional' definido: $affected casos\n";

echo "\n=== MIGRAÇÃO CONCLUÍDA ===\n";
