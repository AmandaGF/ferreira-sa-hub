<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "=== DEBUG caso_ver.php ===\n\n";

// Simular o que caso_ver.php faz
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions.php';

$pdo = db();
$caseId = (int)($_GET['id'] ?? 659);

$stmt = $pdo->prepare(
    'SELECT cs.*, c.name as client_name, c.phone as client_phone, c.id as client_id, u.name as responsible_name
     FROM cases cs LEFT JOIN clients c ON c.id = cs.client_id LEFT JOIN users u ON u.id = cs.responsible_user_id
     WHERE cs.id = ?'
);
$stmt->execute(array($caseId));
$case = $stmt->fetch();

if (!$case) { die("Caso $caseId não encontrado\n"); }

echo "Caso: " . $case['title'] . "\n";
echo "Status: " . $case['status'] . "\n";
echo "Sistema: " . ($case['sistema_tribunal'] ?? 'null') . "\n";
echo "Segredo: " . ($case['segredo_justica'] ?? 'null') . "\n";
echo "Departamento: " . ($case['departamento'] ?? 'null') . "\n";
echo "Comarca UF: " . ($case['comarca_uf'] ?? 'null') . "\n";
echo "Client phone: " . ($case['client_phone'] ?? 'null') . "\n\n";

// Testar o que caso_ver faz
$statusCores = array(
    'em_andamento' => '#059669',
    'suspenso'     => '#d97706',
    'arquivado'    => '#dc2626',
    'renunciamos'  => '#6b7280',
);

$clientPhone = $case['client_phone'] ? preg_replace('/\D/', '', $case['client_phone']) : '';
$clientWhatsapp = $clientPhone ? 'https://wa.me/55' . $clientPhone : '';
$corStatus = isset($statusCores[$case['status']]) ? $statusCores[$case['status']] : '#052228';

echo "clientPhone: $clientPhone\n";
echo "clientWhatsapp: $clientWhatsapp\n";
echo "corStatus: $corStatus\n\n";

// Tentar incluir o arquivo e capturar erro
echo "Tentando carregar caso_ver.php...\n";
try {
    ob_start();
    // Simular sessão
    session_start();
    $_SESSION['user_id'] = 1;
    $_SESSION['user_role'] = 'admin';
    $_GET['id'] = $caseId;
    include __DIR__ . '/modules/operacional/caso_ver.php';
    $output = ob_get_clean();
    echo "OK! Output: " . strlen($output) . " bytes\n";
} catch (Throwable $e) {
    ob_end_clean();
    echo "ERRO: " . $e->getMessage() . "\n";
    echo "Arquivo: " . $e->getFile() . "\n";
    echo "Linha: " . $e->getLine() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
}
