<?php
/**
 * Diag: onde estao os 49 cases previdenciarios importados.
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "=== CASES PREVIDENCIARIOS IMPORTADOS (notes LIKE 'Importado da planilha%') ===\n\n";
$total = (int)$pdo->query("SELECT COUNT(*) FROM cases WHERE notes LIKE 'Importado da planilha%'")->fetchColumn();
echo "TOTAL: $total cases\n\n";

echo "--- Por status ---\n";
$st = $pdo->query("SELECT status, COUNT(*) AS qtd FROM cases WHERE notes LIKE 'Importado da planilha%' GROUP BY status ORDER BY qtd DESC");
foreach ($st->fetchAll() as $r) {
    printf("  %-25s %3d\n", $r['status'], $r['qtd']);
}

echo "\n--- Por kanban_prev ---\n";
$st = $pdo->query("SELECT COALESCE(kanban_prev,0) AS kp, COUNT(*) AS qtd FROM cases WHERE notes LIKE 'Importado da planilha%' GROUP BY kp");
foreach ($st->fetchAll() as $r) {
    printf("  kanban_prev=%s : %d\n", $r['kp'], $r['qtd']);
}

echo "\n--- Por case_type / category ---\n";
$st = $pdo->query("SELECT case_type, category, COUNT(*) AS qtd FROM cases WHERE notes LIKE 'Importado da planilha%' GROUP BY case_type, category");
foreach ($st->fetchAll() as $r) {
    printf("  case_type=%s · category=%s : %d\n", $r['case_type'], $r['category'], $r['qtd']);
}

echo "\n--- Por responsavel ---\n";
$st = $pdo->query("SELECT u.name, COUNT(c.id) AS qtd FROM cases c LEFT JOIN users u ON u.id=c.responsible_user_id WHERE c.notes LIKE 'Importado da planilha%' GROUP BY u.id, u.name");
foreach ($st->fetchAll() as $r) {
    printf("  %-30s %3d\n", $r['name'] ?? '(sem)', $r['qtd']);
}

echo "\n--- Parceria ---\n";
$parceria = (int)$pdo->query("SELECT COUNT(*) FROM cases WHERE notes LIKE 'Importado da planilha%' AND is_parceria=1")->fetchColumn();
echo "  is_parceria=1: $parceria (Rejane)\n";

echo "\n=== ONDE APARECEM ===\n\n";

echo "1) /modules/processos/ (listagem geral)\n";
echo "   Query: SELECT * FROM cases WHERE case_number IS NOT NULL OR case_type='previdenciario'\n";
$qtdProcessos = (int)$pdo->query("SELECT COUNT(*) FROM cases WHERE notes LIKE 'Importado da planilha%' AND (case_number IS NOT NULL OR case_type='previdenciario')")->fetchColumn();
echo "   Visiveis: $qtdProcessos / $total ✓ (todos devem aparecer)\n\n";

echo "2) /modules/prev/ (Kanban PREV — colunas: aguardando_docs, pasta_apta, aguardando_analise_inss, aguardando_pericia, recurso_administrativo, etc.)\n";
echo "   Query simplificada: SELECT * FROM cases WHERE kanban_prev=1\n";
$qtdPrev = (int)$pdo->query("SELECT COUNT(*) FROM cases WHERE notes LIKE 'Importado da planilha%' AND kanban_prev=1")->fetchColumn();
echo "   kanban_prev=1: $qtdPrev cases\n";

// Status que o Kanban PREV reconhece como coluna
$statusKanbanPrev = array('aguardando_docs','pasta_apta','aguardando_analise_inss','aguardando_pericia','recurso_administrativo','recurso_crps','acao_judicial','aguardando_sentenca','cumprimento_precatorio','aguardando_implantacao','suspenso','parceria','cancelado');
$placeholders = implode(',', array_fill(0, count($statusKanbanPrev), '?'));
$st2 = $pdo->prepare("SELECT COUNT(*) FROM cases WHERE notes LIKE 'Importado da planilha%' AND kanban_prev=1 AND status IN ($placeholders)");
$st2->execute($statusKanbanPrev);
$visiveisKanbanPrev = (int)$st2->fetchColumn();
echo "   Com status que bate nas colunas do Kanban PREV: $visiveisKanbanPrev\n";
echo "   ATENCAO: status atuais (em_andamento/concluido/cancelado) PROVAVELMENTE NAO BATEM com as colunas do Kanban PREV!\n";
echo "   Cases com status='cancelado' aparecem na coluna 'Cancelado'.\n";
echo "   Cases com 'concluido'/'em_andamento' precisam ser remapeados pra coluna correta.\n\n";

echo "3) /modules/operacional/ (Kanban Operacional)\n";
echo "   Query: kanban_oculto=0, NAO inclui PREV no padrao (mas cases prev podem aparecer dependendo do filtro)\n";
$qtdOper = (int)$pdo->query("SELECT COUNT(*) FROM cases WHERE notes LIKE 'Importado da planilha%' AND COALESCE(kanban_oculto,0)=0 AND status NOT IN ('concluido','arquivado','cancelado','perdido')")->fetchColumn();
echo "   Visiveis no Operacional (kanban_oculto=0, status nao terminal): $qtdOper\n";

echo "\n=== SAMPLE: 10 PRIMEIROS IMPORTADOS ===\n";
$st = $pdo->query("SELECT c.id, c.title, c.status, c.case_number, cl.name AS cliente, u.name AS resp,
                          cp.especie, cp.codigo_b, cp.fase, cp.resultado_adm, cp.monitorar_radar
                   FROM cases c
                   LEFT JOIN clients cl ON cl.id=c.client_id
                   LEFT JOIN users u ON u.id=c.responsible_user_id
                   LEFT JOIN cases_previdenciario cp ON cp.case_id=c.id
                   WHERE c.notes LIKE 'Importado da planilha%'
                   ORDER BY c.id DESC LIMIT 10");
foreach ($st->fetchAll() as $r) {
    printf("  #%-5d %s\n", $r['id'], mb_substr($r['title'], 0, 60));
    printf("        cliente: %s · responsavel: %s · status=%s\n", mb_substr($r['cliente'] ?? '?', 0, 40), $r['resp'] ?? '-', $r['status']);
    printf("        prev: especie=%s codigo_b=%s fase=%s resultado=%s radar=%d\n", $r['especie'], $r['codigo_b'] ?? '-', $r['fase'], $r['resultado_adm'], $r['monitorar_radar']);
    if ($r['case_number']) printf("        CNJ: %s\n", $r['case_number']);
    echo "        URL: https://ferreiraesa.com.br/conecta/modules/operacional/caso_ver.php?id={$r['id']}\n\n";
}

echo "FIM.\n";
