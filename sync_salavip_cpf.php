<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
ini_set('display_errors', '1');
error_reporting(E_ALL);
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "=== SINCRONIZAR CPF/CNPJ entre CRM e Central VIP ===\n\n";

// Listar divergências
$sql = "SELECT su.id as su_id, su.cpf as su_cpf, su.nome_exibicao,
               c.id as client_id, c.name as client_name, c.cpf as client_cpf
        FROM salavip_usuarios su
        LEFT JOIN clients c ON c.id = su.cliente_id
        WHERE c.cpf IS NOT NULL
          AND REPLACE(REPLACE(REPLACE(REPLACE(c.cpf,'.',''),'-',''),'/',''),' ','')
           != REPLACE(REPLACE(REPLACE(REPLACE(su.cpf,'.',''),'-',''),'/',''),' ','')";

try {
    $divergencias = $pdo->query($sql)->fetchAll();
} catch (Exception $e) {
    echo "ERRO SQL: " . $e->getMessage() . "\n";
    exit;
}
echo "Divergências encontradas: " . count($divergencias) . "\n\n";

$aplicar = isset($_GET['aplicar']) && $_GET['aplicar'] === '1';

foreach ($divergencias as $d) {
    echo "--- #{$d['su_id']} — {$d['client_name']} ---\n";
    echo "  Central VIP (login): {$d['su_cpf']}\n";
    echo "  CRM (correto):       {$d['client_cpf']}\n";
    if ($aplicar) {
        $pdo->prepare("UPDATE salavip_usuarios SET cpf = ?, atualizado_em = NOW() WHERE id = ?")
            ->execute(array($d['client_cpf'], $d['su_id']));
        echo "  ✓ ATUALIZADO\n";
    } else {
        echo "  (somente visualização — adicione &aplicar=1 para sincronizar)\n";
    }
    echo "\n";
}

if (!$aplicar && count($divergencias) > 0) {
    echo "Para aplicar as correções, chame:\n";
    echo "  /conecta/sync_salavip_cpf.php?key=fsa-hub-deploy-2026&aplicar=1\n";
}

echo "\n=== CONCLUÍDO ===\n";
