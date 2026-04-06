<?php
/**
 * Ferreira & Sá Hub — API DataJud (sync manual)
 * Endpoint AJAX para sincronizar um caso com o DataJud.
 */

require_once __DIR__ . '/../core/middleware.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(array('erro' => 'Metodo nao permitido'));
    exit;
}

if (!validate_csrf()) {
    echo json_encode(array('erro' => 'Token CSRF invalido', 'csrf' => generate_csrf_token()));
    exit;
}

$newCsrf = generate_csrf_token();

$caseId = (int)($_POST['case_id'] ?? 0);
if (!$caseId) {
    echo json_encode(array('erro' => 'case_id obrigatorio', 'csrf' => $newCsrf));
    exit;
}

// Verificar permissão: colaborador só pode sincronizar seus próprios casos
$pdo = db();
if (has_role('colaborador')) {
    $check = $pdo->prepare("SELECT responsible_user_id FROM cases WHERE id = ?");
    $check->execute(array($caseId));
    $row = $check->fetch();
    if (!$row || (int)$row['responsible_user_id'] !== current_user_id()) {
        echo json_encode(array('erro' => 'Sem permissao', 'csrf' => $newCsrf));
        exit;
    }
}

$resultado = datajud_sincronizar_caso($caseId, 'manual', current_user_id());

// Buscar andamentos atualizados para refresh inline
$andamentos = array();
if ($resultado['status'] === 'sucesso' && ($resultado['novos'] ?? 0) > 0) {
    $stmt = $pdo->prepare(
        "SELECT a.*, u.name as user_name FROM case_andamentos a
         LEFT JOIN users u ON u.id = a.created_by
         WHERE a.case_id = ? ORDER BY a.data_andamento DESC, a.created_at DESC LIMIT 10"
    );
    $stmt->execute(array($caseId));
    $andamentos = $stmt->fetchAll();
}

echo json_encode(array(
    'status' => $resultado['status'],
    'novos'  => isset($resultado['novos']) ? $resultado['novos'] : 0,
    'msg'    => $resultado['msg'],
    'csrf'   => $newCsrf,
    'andamentos_count' => count($andamentos),
));
