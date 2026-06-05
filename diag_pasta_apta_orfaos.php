<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
error_reporting(E_ALL); ini_set('display_errors','1');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();
header('Content-Type: text/plain; charset=utf-8');

echo "== 1) TODOS os leads em stage='pasta_apta' ==\n";
$st = $pdo->query("SELECT l.id, l.name, l.stage, l.client_id, l.linked_case_id, l.converted_at, l.created_at, c.name AS client_name
                   FROM pipeline_leads l
                   LEFT JOIN clients c ON c.id = l.client_id
                   WHERE l.stage = 'pasta_apta'
                   ORDER BY l.id DESC");
$leads = $st->fetchAll(PDO::FETCH_ASSOC);
echo "  TOTAL: " . count($leads) . "\n\n";

echo "== 2) Verificacao 1-a-1: cada lead tem case ativo no operacional? ==\n\n";

$semCase = array();
$comCaseArquivado = array();
$comCaseOK = array();
$comCaseDuvidoso = array();

foreach ($leads as $l) {
    $cid = (int)$l['client_id'];
    $linkedCaseId = (int)$l['linked_case_id'];
    $caseInfo = null;

    // Primeiro tenta pelo linked_case_id
    if ($linkedCaseId > 0) {
        $stc = $pdo->prepare("SELECT id, title, status, client_id FROM cases WHERE id = ?");
        $stc->execute(array($linkedCaseId));
        $caseInfo = $stc->fetch(PDO::FETCH_ASSOC);
    }

    // Se nao tem linked, busca por client_id
    $casesPorCliente = array();
    if ($cid > 0) {
        $stc2 = $pdo->prepare("SELECT id, title, status FROM cases WHERE client_id = ? ORDER BY id DESC");
        $stc2->execute(array($cid));
        $casesPorCliente = $stc2->fetchAll(PDO::FETCH_ASSOC);
    }

    $linha = "  Lead#{$l['id']} '{$l['name']}' (client#{$cid} '{$l['client_name']}')";

    if (!$caseInfo && empty($casesPorCliente)) {
        $semCase[] = $l['id'];
        echo "  ❌ SEM CASE NENHUM: $linha\n";
        echo "     converted_at={$l['converted_at']} created_at={$l['created_at']}\n";
        continue;
    }

    // Tem case pelo linked
    if ($caseInfo) {
        if (in_array($caseInfo['status'], array('arquivado','cancelado'), true)) {
            $comCaseArquivado[] = $l['id'];
            echo "  ⚠️  CASE ARQUIVADO/CANCELADO: $linha\n";
            echo "     linked_case_id={$linkedCaseId} '{$caseInfo['title']}' status={$caseInfo['status']}\n";
            continue;
        }
        $comCaseOK[] = $l['id'];
        continue;
    }

    // Tem case por cliente mas nao linked - duvidoso
    $caseAtivo = null;
    foreach ($casesPorCliente as $c) {
        if (!in_array($c['status'], array('arquivado','cancelado'), true)) { $caseAtivo = $c; break; }
    }
    if ($caseAtivo) {
        $comCaseDuvidoso[] = $l['id'];
        echo "  ⚠️  TEM CASE ATIVO MAS linked_case_id VAZIO: $linha\n";
        echo "     case#{$caseAtivo['id']} '{$caseAtivo['title']}' status={$caseAtivo['status']}\n";
    } else {
        $comCaseArquivado[] = $l['id'];
        echo "  ⚠️  TODOS OS CASES DO CLIENTE ARQUIVADOS/CANCELADOS: $linha\n";
        foreach ($casesPorCliente as $c) echo "     case#{$c['id']} status={$c['status']}\n";
    }
}

echo "\n== 3) RESUMO ==\n";
echo "  Total em pasta_apta: " . count($leads) . "\n";
echo "  ✅ Com case ativo (OK): " . count($comCaseOK) . "\n";
echo "  ❌ SEM nenhum case: " . count($semCase) . " — IDs: " . implode(',', $semCase) . "\n";
echo "  ⚠️  Case arquivado/cancelado: " . count($comCaseArquivado) . " — IDs: " . implode(',', $comCaseArquivado) . "\n";
echo "  ⚠️  linked_case_id vazio mas tem case ativo: " . count($comCaseDuvidoso) . " — IDs: " . implode(',', $comCaseDuvidoso) . "\n";

echo "\n== 4) Cases criados nas ultimas 30d sem lead vinculado (orfaos no operacional) ==\n";
$st = $pdo->query("SELECT cs.id, cs.title, cs.status, cs.client_id, c.name AS client_name, cs.created_at
                   FROM cases cs LEFT JOIN clients c ON c.id = cs.client_id
                   WHERE cs.created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
                     AND cs.status NOT IN ('arquivado','cancelado','concluido')
                     AND IFNULL(cs.kanban_oculto, 0) = 0
                     AND NOT EXISTS (SELECT 1 FROM pipeline_leads l WHERE l.linked_case_id = cs.id)
                   ORDER BY cs.id DESC LIMIT 30");
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "  case#{$r['id']} '{$r['title']}' status={$r['status']} cliente='{$r['client_name']}' em {$r['created_at']}\n";
}
