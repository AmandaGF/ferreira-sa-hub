<?php
/**
 * Reconciliador Pipeline ↔ Operacional
 * Detecta divergências entre cases.status e pipeline_leads.stage e corrige onde a regra é clara.
 * Pode ser chamado via web (admin) ou via cron.
 */
require_once __DIR__ . '/../../core/middleware.php';
require_once __DIR__ . '/../../core/database.php';

$isCron = (php_sapi_name() === 'cli') || (isset($_GET['cron_key']) && $_GET['cron_key'] === 'fsa-reconcile-2026');
if (!$isCron) {
    require_login();
    if (!has_role('admin')) { flash_set('error', 'Apenas admin.'); redirect(url('modules/dashboard/')); }
}

$pdo = db();
$apply = isset($_GET['apply']) || $isCron;

// Mapeamento case.status → lead.stage canônico
function mapear_case_para_lead($caseStatus) {
    switch ($caseStatus) {
        case 'cancelado':       return 'cancelado';
        case 'arquivado':       return 'finalizado';
        case 'finalizado':      return 'finalizado';
        case 'concluido':       return 'finalizado';
        case 'doc_faltante':    return 'doc_faltante';
        case 'aguardando_docs': return 'pasta_apta';
        case 'em_elaboracao':   return 'pasta_apta';
        case 'em_andamento':    return 'pasta_apta';
        case 'distribuido':     return 'pasta_apta';
        case 'aguardando_prazo':return 'pasta_apta';
        case 'suspenso':        return 'suspenso';
        case 'kanban_prev':     return 'pasta_apta';
        case 'parceria_previdenciario': return 'pasta_apta';
        default: return null;
    }
}

// Mapeamento lead.stage → case.status canônico (quando lead manda)
function mapear_lead_para_case($leadStage) {
    switch ($leadStage) {
        case 'cancelado': return 'cancelado';
        case 'perdido':   return 'cancelado';
        case 'finalizado':return 'arquivado';
        case 'suspenso':  return 'suspenso';
        default: return null;
    }
}

$divergencias = array();
$corrigidos = 0;

// Buscar todos os pares case ↔ lead vinculados
$rows = $pdo->query("
    SELECT l.id AS lead_id, l.stage AS lead_stage, l.linked_case_id, l.name AS lead_name,
           c.id AS case_id, c.status AS case_status, c.title
    FROM pipeline_leads l
    INNER JOIN cases c ON c.id = l.linked_case_id
    WHERE l.linked_case_id IS NOT NULL AND l.linked_case_id > 0
")->fetchAll();

foreach ($rows as $r) {
    $leadStage   = $r['lead_stage'];
    $caseStatus  = $r['case_status'];
    $caseCanon   = mapear_case_para_lead($caseStatus);
    $leadCanon   = mapear_lead_para_case($leadStage);

    // REGRA 1: Se lead está em estado terminal/comercial (cancelado/perdido/suspenso/finalizado),
    //          o case deve refletir.
    if ($leadCanon !== null) {
        if ($caseStatus !== $leadCanon) {
            $divergencias[] = array(
                'tipo'   => 'lead_manda',
                'lead'   => $r['lead_id'], 'case' => $r['case_id'], 'name' => $r['lead_name'],
                'de'     => "case=$caseStatus", 'para' => "case=$leadCanon",
                'motivo' => "Lead em '$leadStage' → caso deve estar '$leadCanon'",
            );
            if ($apply) {
                $pdo->prepare("UPDATE cases SET status = ?, closed_at = COALESCE(closed_at, CURDATE()), updated_at = NOW() WHERE id = ?")
                    ->execute(array($leadCanon, $r['case_id']));
                audit_log('reconcile_case', 'case', $r['case_id'], "$caseStatus → $leadCanon (espelho do lead $leadStage)");
                $corrigidos++;
            }
        }
        continue;
    }

    // REGRA 2: Caso contrário, o case manda. Lead deve refletir o status do case.
    if ($caseCanon !== null && $leadStage !== $caseCanon) {
        // Exceções: se o lead está em estado pré-contrato e o case está em algo ativo, é OK divergir
        // (o lead representa estado comercial e o case representa estado operacional, mas
        // já passamos do contrato — então alinha)
        $divergencias[] = array(
            'tipo'   => 'case_manda',
            'lead'   => $r['lead_id'], 'case' => $r['case_id'], 'name' => $r['lead_name'],
            'de'     => "lead=$leadStage", 'para' => "lead=$caseCanon",
            'motivo' => "Caso em '$caseStatus' → lead deve estar '$caseCanon'",
        );
        if ($apply) {
            $pdo->prepare("UPDATE pipeline_leads SET stage = ?, updated_at = NOW() WHERE id = ?")
                ->execute(array($caseCanon, $r['lead_id']));
            audit_log('reconcile_lead', 'lead', $r['lead_id'], "$leadStage → $caseCanon (espelho do caso $caseStatus)");
            $corrigidos++;
        }
    }
}

if ($isCron) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "Reconciliacao concluida.\n";
    echo "Divergencias: " . count($divergencias) . "\n";
    echo "Corrigidos: $corrigidos\n";
    foreach ($divergencias as $d) {
        echo "- Lead #{$d['lead']} / Case #{$d['case']} ({$d['name']}): {$d['de']} → {$d['para']} | {$d['motivo']}\n";
    }
    exit;
}

// View HTML
$pageTitle = 'Reconciliar Pipeline ↔ Operacional';
require_once __DIR__ . '/../../templates/layout_start.php';
?>
<div class="container" style="max-width:1100px;margin:20px auto;padding:0 20px">
    <h1>🔄 Reconciliar Pipeline ↔ Operacional</h1>
    <p style="color:#666">Compara <code>cases.status</code> com <code>pipeline_leads.stage</code> e corrige divergências.</p>

    <?php if (!$apply): ?>
        <div style="background:#fef3c7;border:1px solid #f59e0b;padding:12px;border-radius:8px;margin:16px 0">
            <strong>Modo simulação</strong> — nenhuma alteração foi feita.
            <a href="?apply=1" class="btn btn-primary" style="margin-left:12px"
               onclick="return confirm('Aplicar correções no banco?')">Aplicar Correções</a>
        </div>
    <?php else: ?>
        <div style="background:#d1fae5;border:1px solid #10b981;padding:12px;border-radius:8px;margin:16px 0">
            ✅ <strong><?= $corrigidos ?> divergências corrigidas.</strong>
            <a href="?" class="btn btn-outline" style="margin-left:12px">Ver de novo</a>
        </div>
    <?php endif; ?>

    <h2>Divergências encontradas: <?= count($divergencias) ?></h2>
    <?php if (empty($divergencias)): ?>
        <p style="color:#10b981">✅ Tudo sincronizado!</p>
    <?php else: ?>
        <table class="table" style="width:100%;border-collapse:collapse">
            <thead style="background:#f3f4f6">
                <tr>
                    <th style="padding:8px;text-align:left;border:1px solid #e5e7eb">Lead</th>
                    <th style="padding:8px;text-align:left;border:1px solid #e5e7eb">Case</th>
                    <th style="padding:8px;text-align:left;border:1px solid #e5e7eb">Nome</th>
                    <th style="padding:8px;text-align:left;border:1px solid #e5e7eb">Tipo</th>
                    <th style="padding:8px;text-align:left;border:1px solid #e5e7eb">De</th>
                    <th style="padding:8px;text-align:left;border:1px solid #e5e7eb">Para</th>
                    <th style="padding:8px;text-align:left;border:1px solid #e5e7eb">Motivo</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($divergencias as $d): ?>
                <tr>
                    <td style="padding:8px;border:1px solid #e5e7eb"><a href="<?= url('modules/pipeline/lead_ver.php?id=' . $d['lead']) ?>">#<?= $d['lead'] ?></a></td>
                    <td style="padding:8px;border:1px solid #e5e7eb"><a href="<?= url('modules/operacional/caso_ver.php?id=' . $d['case']) ?>">#<?= $d['case'] ?></a></td>
                    <td style="padding:8px;border:1px solid #e5e7eb"><?= e($d['name']) ?></td>
                    <td style="padding:8px;border:1px solid #e5e7eb"><?= $d['tipo'] === 'lead_manda' ? '📤 Lead manda' : '📥 Caso manda' ?></td>
                    <td style="padding:8px;border:1px solid #e5e7eb"><code><?= e($d['de']) ?></code></td>
                    <td style="padding:8px;border:1px solid #e5e7eb"><code><?= e($d['para']) ?></code></td>
                    <td style="padding:8px;border:1px solid #e5e7eb;font-size:12px"><?= e($d['motivo']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
