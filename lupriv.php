<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Cases do PREV com 'Luiz Eduardo' ou 'exemplo' ou 'teste' ===\n";
$st = $pdo->query("SELECT cs.id, cs.title, cs.status, cs.prev_status, cs.kanban_prev, cs.created_at,
                          c.name AS client_name
                   FROM cases cs
                   LEFT JOIN clients c ON c.id = cs.client_id
                   WHERE cs.kanban_prev = 1
                     AND (cs.title LIKE '%Luiz Eduardo%' OR cs.title LIKE '%exemplo%'
                          OR cs.title LIKE '%teste%' OR c.name LIKE '%Luiz Eduardo%')
                   ORDER BY cs.id DESC");
foreach ($st as $r) print_r($r);

if (!empty($_GET['delete']) && (int)$_GET['delete'] > 0) {
    $id = (int)$_GET['delete'];
    $c = $pdo->prepare("SELECT id, title FROM cases WHERE id=?");
    $c->execute(array($id));
    $case = $c->fetch(PDO::FETCH_ASSOC);
    if (!$case) { echo "\ncase #$id nao existe\n"; exit; }
    echo "\n== EXCLUINDO case #$id: {$case['title']} ==\n";
    // Delete cascade seguro — cases + relacionamentos
    $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
    $tabsRel = array('case_tasks','case_andamentos','case_partes','documentos_pendentes',
                     'agenda_eventos','prazos_processuais','case_procuracao_regras',
                     'salavip_threads','gerid_pesquisas','tickets','asaas_cobrancas',
                     'audit_log','notificacoes','asaas_cobranca_cases');
    foreach ($tabsRel as $t) {
        try {
            if ($t === 'audit_log') {
                $n = $pdo->prepare("DELETE FROM $t WHERE entity_type='case' AND entity_id=?");
                $n->execute(array($id));
            } elseif ($t === 'notificacoes') {
                // pode nao ter case_id
                continue;
            } else {
                $n = $pdo->prepare("DELETE FROM $t WHERE case_id=?");
                $n->execute(array($id));
            }
            $cnt = $n->rowCount();
            if ($cnt > 0) echo "  $t: $cnt linha(s) removida(s)\n";
        } catch (Exception $e) { /* tabela pode nao existir ou ter fk diferente */ }
    }
    // Zera vinculo em pipeline_leads (soft)
    try { $pdo->prepare("UPDATE pipeline_leads SET linked_case_id = NULL WHERE linked_case_id = ?")->execute(array($id)); } catch (Exception $e) {}
    // Delete o case
    $pdo->prepare("DELETE FROM cases WHERE id = ?")->execute(array($id));
    $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
    audit_log('case_deletado_teste', 'case', $id, "Luiz Eduardo exemplo — removido");
    echo "  case #$id excluido.\n";
}
