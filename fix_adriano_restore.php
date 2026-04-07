<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave invalida'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

// Restaurar dados do caso #669 a partir dos andamentos
$pdo->prepare("UPDATE cases SET
    case_number = '0809633-18.2024.8.19.0014',
    case_type = 'Execução de Alimentos',
    status = 'em_andamento',
    kanban_oculto = 0,
    updated_at = NOW()
    WHERE id = 669")->execute();

echo "Caso #669 restaurado:\n";
echo "- Numero: 0809633-18.2024.8.19.0014\n";
echo "- Tipo: Execução de Alimentos\n";
echo "- Status: em_andamento\n";
echo "- Oculto: 0\n";

// Verificar
$r = $pdo->query("SELECT id, title, status, case_number, case_type, kanban_oculto FROM cases WHERE id = 669")->fetch();
echo "\nVerificação: status={$r['status']} num={$r['case_number']} tipo={$r['case_type']} oculto={$r['kanban_oculto']}\n";
echo "\n=== FEITO ===\n";
