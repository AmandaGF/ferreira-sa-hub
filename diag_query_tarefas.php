<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Forbidden.'); }
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/core/database.php';
$pdo = db();

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    $r = $pdo->query("SELECT id FROM case_tasks ORDER BY id DESC LIMIT 1")->fetch();
    $id = (int)($r['id'] ?? 0);
    echo "Usando id={$id} (ultima tarefa)\n\n";
}

// Tenta a query NOVA
try {
    $stmt = $pdo->prepare("SELECT t.*,
                                  cs.title as case_title,
                                  cs.case_number,
                                  cs.comarca,
                                  cs.comarca_uf,
                                  cs.court,
                                  cs.case_type,
                                  cs.drive_folder_url,
                                  cs.client_id as case_client_id,
                                  c.name as client_name,
                                  u.name as case_responsavel_name
        FROM case_tasks t
        LEFT JOIN cases cs ON cs.id = t.case_id
        LEFT JOIN clients c ON c.id = cs.client_id
        LEFT JOIN users u ON u.id = cs.responsible_user_id
        WHERE t.id = ?");
    $stmt->execute(array($id));
    $r = $stmt->fetch();
    echo "OK (query nova). Resultado:\n";
    print_r($r);
} catch (Exception $e) {
    echo "ERRO na query nova: " . $e->getMessage() . "\n\n";
    echo "Vou listar as colunas existentes de 'cases':\n";
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM cases")->fetchAll();
        foreach ($cols as $c) echo "  - " . $c['Field'] . " (" . $c['Type'] . ")\n";
    } catch (Exception $e2) {
        echo "Tambem falhou: " . $e2->getMessage() . "\n";
    }
}
