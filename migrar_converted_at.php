<?php
/**
 * Migração one-shot: preenche pipeline_leads.converted_at para leads
 * em stages pós-contrato (contrato_assinado ou depois) que foram criados
 * com converted_at NULL — pelo bug do fluxo "duplicar pasta" no Operacional
 * (corrigido em c9f7175).
 *
 * Fonte da verdade: pipeline_history. Pega a data da PRIMEIRA transição
 * do lead para 'contrato_assinado' (MIN).
 *
 * SÓ ALTERA o campo converted_at. NÃO toca em nenhum outro campo.
 * SÓ afeta leads que têm converted_at IS NULL (campo vazio).
 */
require_once __DIR__ . '/core/database.php';

$key = $_GET['key'] ?? '';
if ($key !== 'fsa-hub-deploy-2026') {
    http_response_code(403);
    exit('Chave inválida');
}

$pdo = db();
$dryRun = !isset($_GET['exec']);

header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><meta charset="utf-8"><title>Migrar converted_at</title>';
echo '<style>body{font-family:Inter,Arial,sans-serif;max-width:1200px;margin:2rem auto;padding:0 1rem;color:#052228} h1,h2{color:#052228} table{border-collapse:collapse;width:100%;margin:1rem 0;font-size:13px} th,td{border:1px solid #d1d5db;padding:.4rem .6rem;text-align:left} th{background:#f3f4f6} .badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600} .ok{background:#d1fae5;color:#065f46} .warn{background:#fef3c7;color:#78350f} .err{background:#fee2e2;color:#991b1b} .act{background:#052228;color:#fff;padding:.6rem 1.2rem;border-radius:8px;text-decoration:none;display:inline-block;margin-top:1rem;font-weight:700} .act-warn{background:#dc2626;color:#fff;padding:.6rem 1.2rem;border-radius:8px;text-decoration:none;display:inline-block;margin-top:1rem;font-weight:700} .muted{color:#6b7280;font-size:13px} .small{font-size:.7rem;color:#6b7280}</style>';

echo '<h1>Migrar converted_at — leads em contrato_assinado+ com campo vazio</h1>';
echo '<p class="muted">Modo atual: <strong>' . ($dryRun ? 'DRY RUN (só mostra, não altera)' : 'EXECUÇÃO REAL') . '</strong></p>';

// Stages onde o lead DEVERIA ter converted_at preenchido (passou por contrato_assinado)
$stagesComContrato = array('contrato_assinado','agendado_docs','reuniao_cobranca','doc_faltante','pasta_apta','finalizado');
$placeholders = implode(',', array_fill(0, count($stagesComContrato), '?'));

// 1. Candidatos — leads com converted_at NULL em stages pós-contrato
$sql = "SELECT l.id, l.name, l.stage, l.created_at, l.linked_case_id,
               (SELECT MIN(h.created_at)
                  FROM pipeline_history h
                  WHERE h.lead_id = l.id AND h.to_stage = 'contrato_assinado') AS historia_data
        FROM pipeline_leads l
        WHERE l.converted_at IS NULL
          AND l.stage IN ($placeholders)
        ORDER BY l.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($stagesComContrato);
$candidatos = $stmt->fetchAll();

$comHistorico = array();
$semHistorico = array();
foreach ($candidatos as $c) {
    if (!empty($c['historia_data'])) $comHistorico[] = $c;
    else $semHistorico[] = $c;
}

echo '<h2>🟢 Candidatos seguros (com data no pipeline_history): ' . count($comHistorico) . '</h2>';
echo '<p class="muted">Esses têm registro de quando foram pra <code>contrato_assinado</code>. Vou usar essa data.</p>';

if (count($comHistorico) > 0) {
    echo '<table><tr><th>ID</th><th>Lead</th><th>Stage atual</th><th>created_at (hoje)</th><th>converted_at (será preenchido com)</th><th>Diferença</th></tr>';
    foreach ($comHistorico as $c) {
        $diffDias = '';
        if ($c['created_at'] && $c['historia_data']) {
            $d1 = strtotime($c['created_at']);
            $d2 = strtotime($c['historia_data']);
            $diff = round(($d2 - $d1) / 86400);
            $diffDias = $diff . 'd';
            if (abs($diff) > 30) $diffDias = '<span style="color:#dc2626;font-weight:700;">' . $diffDias . ' ⚠️</span>';
        }
        echo '<tr>';
        echo '<td>' . (int)$c['id'] . '</td>';
        echo '<td><strong>' . htmlspecialchars($c['name']) . '</strong> <span class="small">(caso #' . (int)$c['linked_case_id'] . ')</span></td>';
        echo '<td><span class="badge ok">' . htmlspecialchars($c['stage']) . '</span></td>';
        echo '<td>' . htmlspecialchars($c['created_at']) . '</td>';
        echo '<td><strong>' . htmlspecialchars($c['historia_data']) . '</strong></td>';
        echo '<td>' . $diffDias . '</td>';
        echo '</tr>';
    }
    echo '</table>';

    if (!$dryRun) {
        $up = $pdo->prepare("UPDATE pipeline_leads SET converted_at = ? WHERE id = ? AND converted_at IS NULL");
        $aplicadas = 0;
        foreach ($comHistorico as $c) {
            if ($up->execute(array($c['historia_data'], $c['id']))) $aplicadas += $up->rowCount();
        }
        echo '<p class="badge ok">✓ ' . $aplicadas . ' lead(s) atualizado(s) com converted_at do histórico.</p>';
    }
}

echo '<h2>🟡 Sem histórico (edge case): ' . count($semHistorico) . '</h2>';
echo '<p class="muted">Esses não têm registro em pipeline_history da transição pra contrato_assinado. Geralmente são leads antigos criados antes do histórico existir. Vão ficar como estão — você pode editar manualmente depois pelo botão "Data Fech." da planilha.</p>';

if (count($semHistorico) > 0) {
    echo '<table><tr><th>ID</th><th>Lead</th><th>Stage</th><th>created_at</th></tr>';
    foreach ($semHistorico as $c) {
        echo '<tr>';
        echo '<td>' . (int)$c['id'] . '</td>';
        echo '<td>' . htmlspecialchars($c['name']) . '</td>';
        echo '<td>' . htmlspecialchars($c['stage']) . '</td>';
        echo '<td>' . htmlspecialchars($c['created_at']) . '</td>';
        echo '</tr>';
    }
    echo '</table>';
}

// Rodapé
if ($dryRun) {
    echo '<hr><p><strong>Isso é só simulação — nada foi alterado.</strong></p>';
    echo '<a class="act-warn" href="?key=fsa-hub-deploy-2026&exec=1">▶ Executar de verdade (preenche converted_at das ' . count($comHistorico) . ' seguras)</a>';
} else {
    echo '<hr><p class="muted">Execução concluída em ' . date('d/m/Y H:i:s') . '.</p>';
    echo '<p class="small">Se algo estiver errado, avise — cada linha alterada tinha converted_at=NULL antes. Reverter = setar de volta pra NULL.</p>';
}
