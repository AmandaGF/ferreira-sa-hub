<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') die('nope');
header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors', '1'); error_reporting(E_ALL);
while (ob_get_level() > 0) { ob_end_clean(); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== DIAG PEÇAS CAILANE ===\n\n";

// Acha cliente
$st = $pdo->prepare("SELECT id, name FROM clients WHERE name LIKE ? ORDER BY id DESC LIMIT 3");
$st->execute(array('%Cailane%'));
$clis = $st->fetchAll(PDO::FETCH_ASSOC);
echo "Clientes 'Cailane':\n";
foreach ($clis as $c) echo "  #{$c['id']} {$c['name']}\n";
if (!$clis) { echo "NÃO ACHOU\n"; exit; }
$clientId = (int)$clis[0]['id'];
echo "\nUsando #{$clientId}\n\n";

// Lista peças (a mesma query que a tela usa)
echo "── Peças (case_documents WHERE client_id={$clientId}) ──\n";
$st = $pdo->prepare("SELECT cd.id, cd.case_id, cd.client_id, cd.tipo_peca, cd.tipo_acao, cd.titulo,
                            LENGTH(cd.conteudo_html) AS tam_html,
                            cd.gerado_por, cd.created_at,
                            u.name as user_name, cs.title as case_title
                     FROM case_documents cd
                     LEFT JOIN users u ON u.id = cd.gerado_por
                     LEFT JOIN cases cs ON cs.id = cd.case_id
                     WHERE cd.client_id = ? ORDER BY cd.created_at DESC");
$st->execute(array($clientId));
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
echo "Total: " . count($rows) . "\n\n";
foreach ($rows as $r) {
    echo "  Peça #{$r['id']}\n";
    echo "    titulo: " . ($r['titulo'] ?: '(vazio)') . "\n";
    echo "    tipo_peca: {$r['tipo_peca']} · tipo_acao: {$r['tipo_acao']}\n";
    echo "    case_id: {$r['case_id']} · case_title: " . ($r['case_title'] ?: '(órfão!)') . "\n";
    echo "    conteudo_html: {$r['tam_html']} chars\n";
    echo "    gerado_por: {$r['gerado_por']} ({$r['user_name']}) · created_at: {$r['created_at']}\n";
    echo "\n";
}

// Testa o SELECT do ver.php pra cada peça
echo "── Testa SELECT do ver.php ──\n";
foreach ($rows as $r) {
    $id = (int)$r['id'];
    try {
        $s2 = $pdo->prepare("SELECT cd.*, u.name as user_name, ue.name as editado_por_name
                             FROM case_documents cd
                             LEFT JOIN users u ON u.id = cd.gerado_por
                             LEFT JOIN users ue ON ue.id = cd.editado_por
                             WHERE cd.id = ?");
        $s2->execute(array($id));
        $x = $s2->fetch();
        echo "  #{$id}: " . ($x ? "OK — {$x['titulo']}" : "FALSO (nao encontrou)") . "\n";
    } catch (Exception $e) {
        echo "  #{$id}: ✗ ERRO — " . $e->getMessage() . "\n";
    }
}

// Colunas disponíveis
echo "\n── Colunas de case_documents ──\n";
$cols = $pdo->query("SHOW COLUMNS FROM case_documents")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) echo "  {$c['Field']} · {$c['Type']}\n";
