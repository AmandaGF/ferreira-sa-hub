<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
error_reporting(E_ALL);
ini_set('display_errors', '1');
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';

$pdo = db();

// Listar usuarios pendentes
echo "Usuarios pendentes (is_active=0):\n";
$pendentes = $pdo->query("SELECT id, name, email FROM users WHERE is_active = 0")->fetchAll();
if (empty($pendentes)) {
    echo "  Nenhum usuario pendente.\n";
} else {
    foreach ($pendentes as $u) {
        echo "  ID={$u['id']} - {$u['name']} ({$u['email']})\n";
    }
}

// Se tem parametro de teste, simular rejeicao
$testId = isset($_GET['reject']) ? (int)$_GET['reject'] : 0;
if ($testId) {
    echo "\nSimulando rejeicao do user ID=$testId...\n";

    try {
        // Verificar FKs
        $tables = array(
            'audit_log' => 'user_id',
            'tickets' => 'requester_id',
            'ticket_assignees' => 'user_id',
            'ticket_messages' => 'user_id',
            'pipeline_leads' => 'assigned_to',
            'portal_links' => 'created_by',
            'cases' => 'responsible_user_id',
            'form_submissions' => 'linked_client_id',
        );

        foreach ($tables as $table => $col) {
            try {
                $cnt = $pdo->prepare("SELECT COUNT(*) FROM $table WHERE $col = ?");
                $cnt->execute(array($testId));
                $n = $cnt->fetchColumn();
                if ($n > 0) {
                    echo "  $table.$col = $n registros\n";
                }
            } catch (Exception $e) {
                echo "  $table.$col - ERRO: " . $e->getMessage() . "\n";
            }
        }

        // Tentar limpar e deletar
        echo "\nLimpando referencias...\n";
        $pdo->prepare('UPDATE audit_log SET user_id = NULL WHERE user_id = ?')->execute(array($testId));
        echo "  audit_log OK\n";

        try { $pdo->prepare('UPDATE tickets SET requester_id = NULL WHERE requester_id = ?')->execute(array($testId)); echo "  tickets OK\n"; } catch (Exception $e) { echo "  tickets ERRO: " . $e->getMessage() . "\n"; }
        try { $pdo->prepare('UPDATE pipeline_leads SET assigned_to = NULL WHERE assigned_to = ?')->execute(array($testId)); echo "  pipeline OK\n"; } catch (Exception $e) { echo "  pipeline ERRO: " . $e->getMessage() . "\n"; }
        try { $pdo->prepare('UPDATE portal_links SET created_by = NULL WHERE created_by = ?')->execute(array($testId)); echo "  portal OK\n"; } catch (Exception $e) { echo "  portal ERRO: " . $e->getMessage() . "\n"; }
        try { $pdo->prepare('DELETE FROM ticket_assignees WHERE user_id = ?')->execute(array($testId)); echo "  ticket_assignees OK\n"; } catch (Exception $e) { echo "  ticket_assignees ERRO: " . $e->getMessage() . "\n"; }
        try { $pdo->prepare('DELETE FROM ticket_messages WHERE user_id = ?')->execute(array($testId)); echo "  ticket_messages OK\n"; } catch (Exception $e) { echo "  ticket_messages ERRO: " . $e->getMessage() . "\n"; }

        echo "\nDeletando usuario...\n";
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute(array($testId));
        echo "  SUCESSO!\n";

    } catch (Exception $e) {
        echo "  ERRO FINAL: " . $e->getMessage() . "\n";
    }
}

echo "\nPara testar rejeicao: ?key=fsa-hub-deploy-2026&reject=ID\n";
