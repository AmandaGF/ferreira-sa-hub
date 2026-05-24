<?php
/**
 * Migração: garante que a coluna `cases.category` aceita o valor 'administrativa'
 * (sistema ja tinha judicial/extrajudicial/pre_processual — adicionamos administrativa
 * como 4o tipo de demanda para casos em sede administrativa: INSS, autarquias, agencias
 * reguladoras, processos administrativos disciplinares, etc).
 *
 * Acesse: ferreiraesa.com.br/conecta/migrar_tipo_demanda.php?key=fsa-hub-deploy-2026
 *
 * Como a coluna ja existe e e VARCHAR(20), nao precisa ALTER — basta passar a aceitar
 * 'administrativa' na camada PHP (caso_novo.php, api.php, etc).
 *
 * Este script faz apenas DIAGNOSTICO: conta quantos cases cada tipo possui hoje e
 * lista heuristica de candidatos a 'administrativa' (case_type contendo "INSS",
 * "Administrativo", "BPC", "LOAS", "PAD" etc) para Amanda reclassificar manualmente.
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';

$pdo = db();

echo "=== Migracao tipo_demanda (campo 'category' expandido) ===\n\n";

// 1. Garante que a coluna existe e aceita o novo valor (VARCHAR aceita qualquer string)
try {
    $cols = $pdo->query("SHOW COLUMNS FROM cases LIKE 'category'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE cases ADD COLUMN category VARCHAR(20) NOT NULL DEFAULT 'judicial' AFTER case_type");
        echo "[OK] Coluna 'category' criada (VARCHAR 20).\n";
    } else {
        $col = $cols[0];
        echo "[OK] Coluna 'category' ja existe (" . $col['Type'] . ", default '" . $col['Default'] . "').\n";
    }
} catch (Exception $e) {
    echo "[ERRO] " . $e->getMessage() . "\n";
}

echo "\n--- Distribuicao atual ---\n";
$dist = $pdo->query("SELECT category, COUNT(*) AS qtd FROM cases GROUP BY category ORDER BY qtd DESC")->fetchAll();
foreach ($dist as $d) {
    echo "  " . str_pad((string)$d['category'], 18, ' ') . " " . $d['qtd'] . "\n";
}

echo "\n--- Candidatos a reclassificar como 'administrativa' (heuristica por case_type) ---\n";
$padroes = array('%INSS%', '%dministrativ%', '%BPC%', '%LOAS%', '%PAD%', '%Receita Federal%', '%Anatel%', '%Aneel%', '%CRM%', '%CRO%', '%OAB Disciplinar%');
$where = implode(' OR ', array_fill(0, count($padroes), 'case_type LIKE ?'));
$sql = "SELECT id, title, case_type, category FROM cases
        WHERE category != 'administrativa' AND ($where)
        ORDER BY created_at DESC LIMIT 50";
$stmt = $pdo->prepare($sql);
$stmt->execute($padroes);
$cands = $stmt->fetchAll();
if (empty($cands)) {
    echo "  Nenhum candidato encontrado pela heuristica.\n";
} else {
    echo "  " . count($cands) . " candidatos (primeiros 50):\n";
    foreach ($cands as $c) {
        echo "    #" . str_pad((string)$c['id'], 5, ' ') . " [" . str_pad((string)$c['category'], 14, ' ') . "] "
            . substr((string)$c['case_type'], 0, 30) . " | " . substr((string)$c['title'], 0, 60) . "\n";
    }
    echo "\n  >>> Reclassifique manualmente na pasta do caso (campo 'Tipo de demanda').\n";
}

echo "\n[FIM]\n";
