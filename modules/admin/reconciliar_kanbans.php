<?php
/**
 * Reconciliador Pipeline ↔ Operacional
 * Detecta divergências entre cases.status e pipeline_leads.stage.
 *
 * Regra-chave (corrigida 17/05/2026): NÃO força mais uma direção fixa.
 * O lado ATUALIZADO POR ÚLTIMO vence — assim um caso reativado depois do
 * lead ter sido cancelado (ex.: Ubirajara #1197/#651) NÃO é re-cancelado;
 * o lead é que passa a refletir o caso. Aplicação é por linha (opt-in),
 * nunca "tudo cego". Cron aplica só a direção "mais recente vence".
 */
require_once __DIR__ . '/../../core/middleware.php';
require_once __DIR__ . '/../../core/database.php';

$isCron = (php_sapi_name() === 'cli') || (isset($_GET['cron_key']) && $_GET['cron_key'] === 'fsa-reconcile-2026');
if (!$isCron) {
    require_login();
    if (!has_role('admin')) { flash_set('error', 'Apenas admin.'); redirect(url('modules/dashboard/')); }
}

$pdo = db();

// case.status terminal → lead.stage canônico (quando o CASO manda)
function mapear_case_para_lead($caseStatus) {
    switch ($caseStatus) {
        case 'cancelado':    return 'cancelado';
        case 'doc_faltante': return 'doc_faltante';
        case 'suspenso':     return 'suspenso';
        // caso ativo (em_andamento, distribuido, etc.) → lead "fechado/ativo"
        case 'em_andamento':
        case 'em_elaboracao':
        case 'distribuido':
        case 'aguardando_docs':
        case 'aguardando_prazo': return 'contrato_assinado';
        // arquivado/finalizado/concluido NÃO força o lead (pode ser pasta_apta legítimo)
        default: return null;
    }
}

// lead.stage terminal comercial → case.status canônico (quando o LEAD manda)
function mapear_lead_para_case($leadStage) {
    switch ($leadStage) {
        case 'cancelado': return 'cancelado';
        case 'perdido':   return 'cancelado';
        case 'suspenso':  return 'suspenso';
        case 'contrato_assinado': return 'em_andamento'; // lead reativado → caso ativo
        default: return null;
    }
}

// ── APLICAR (POST, por linha) ────────────────────────────────────────────
$aplicados = 0; $erros = array();
if (!$isCron && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) { flash_set('error', 'Token inválido.'); redirect(url('modules/admin/reconciliar_kanbans.php')); }
    $acoes = isset($_POST['acao']) && is_array($_POST['acao']) ? $_POST['acao'] : array();
    foreach ($acoes as $par => $dir) {
        list($leadId, $caseId) = array_map('intval', explode(':', $par) + array(0, 0));
        if (!$leadId || !$caseId || $dir === 'ignorar') continue;
        $st = $pdo->prepare("SELECT l.stage AS ls, c.status AS cs FROM pipeline_leads l JOIN cases c ON c.id = ? WHERE l.id = ?");
        $st->execute(array($caseId, $leadId));
        $cur = $st->fetch();
        if (!$cur) { $erros[] = "Par $par não encontrado"; continue; }
        if ($dir === 'caso_recebe') {
            $novo = mapear_lead_para_case($cur['ls']);
            if ($novo === null || $novo === $cur['cs']) continue;
            $closed = in_array($novo, array('cancelado')) ? 'COALESCE(closed_at, CURDATE())' : 'closed_at';
            $pdo->prepare("UPDATE cases SET status = ?, closed_at = $closed, updated_at = NOW() WHERE id = ?")
                ->execute(array($novo, $caseId));
            audit_log('reconcile_case', 'case', $caseId, "{$cur['cs']} → $novo (escolha admin: caso espelha lead {$cur['ls']})");
            $aplicados++;
        } elseif ($dir === 'lead_recebe') {
            $novo = mapear_case_para_lead($cur['cs']);
            if ($novo === null || $novo === $cur['ls']) continue;
            $pdo->prepare("UPDATE pipeline_leads SET stage = ?, updated_at = NOW() WHERE id = ?")
                ->execute(array($novo, $leadId));
            audit_log('reconcile_lead', 'lead', $leadId, "{$cur['ls']} → $novo (escolha admin: lead espelha caso {$cur['cs']})");
            $aplicados++;
        }
    }
    flash_set('success', "$aplicados correção(ões) aplicada(s)." . ($erros ? ' Erros: ' . implode('; ', $erros) : ''));
    redirect(url('modules/admin/reconciliar_kanbans.php'));
}

// ── DETECTAR DIVERGÊNCIAS ────────────────────────────────────────────────
$rows = $pdo->query("
    SELECT l.id AS lead_id, l.stage AS lead_stage, l.name AS lead_name,
           l.updated_at AS lead_upd, c.id AS case_id, c.status AS case_status,
           c.updated_at AS case_upd, c.title
    FROM pipeline_leads l
    INNER JOIN cases c ON c.id = l.linked_case_id
    WHERE l.linked_case_id IS NOT NULL AND l.linked_case_id > 0
")->fetchAll();

$divergencias = array();
foreach ($rows as $r) {
    $leadCanon = mapear_lead_para_case($r['lead_stage']);   // o que o caso seria se o lead mandasse
    $caseCanon = mapear_case_para_lead($r['case_status']);   // o que o lead seria se o caso mandasse

    $precisaCaso = ($leadCanon !== null && $r['case_status'] !== $leadCanon);
    $precisaLead = ($caseCanon !== null && $r['lead_stage'] !== $caseCanon);
    if (!$precisaCaso && !$precisaLead) continue;

    $tsLead = strtotime($r['lead_upd'] ?: '1970-01-01');
    $tsCase = strtotime($r['case_upd'] ?: '1970-01-01');
    // Lado atualizado por último vence → o OUTRO lado é corrigido pra refletir.
    $maisRecente = $tsCase >= $tsLead ? 'caso' : 'lead';
    $sugestao = $maisRecente === 'caso' ? 'lead_recebe' : 'caso_recebe';
    // Se a sugestão não tem mapeamento válido, cai pra outra; se nenhuma, ignora.
    if ($sugestao === 'lead_recebe' && $caseCanon === null) $sugestao = ($leadCanon !== null ? 'caso_recebe' : 'ignorar');
    if ($sugestao === 'caso_recebe' && $leadCanon === null) $sugestao = ($caseCanon !== null ? 'lead_recebe' : 'ignorar');

    $alerta = '';
    if ($maisRecente === 'caso' && $leadCanon !== null && $r['case_status'] !== $leadCanon) {
        $alerta = '⚠ Caso mudou DEPOIS do lead — provável reativação. Sugerido: corrigir o LEAD (não re-cancelar o caso).';
    }

    $divergencias[] = array(
        'lead' => (int)$r['lead_id'], 'case' => (int)$r['case_id'], 'name' => $r['lead_name'],
        'lead_stage' => $r['lead_stage'], 'case_status' => $r['case_status'],
        'lead_upd' => $r['lead_upd'], 'case_upd' => $r['case_upd'],
        'mais_recente' => $maisRecente, 'sugestao' => $sugestao, 'alerta' => $alerta,
        'opt_caso' => $leadCanon, // se "caso recebe": vira isso
        'opt_lead' => $caseCanon, // se "lead recebe": vira isso
    );
}

// ── CRON: aplica só "mais recente vence" (seguro, nunca re-cancela reativado) ──
if ($isCron) {
    header('Content-Type: text/plain; charset=utf-8');
    $n = 0;
    foreach ($divergencias as $d) {
        if ($d['sugestao'] === 'caso_recebe' && $d['opt_caso'] !== null && $d['opt_caso'] !== $d['case_status']) {
            $closed = $d['opt_caso'] === 'cancelado' ? 'COALESCE(closed_at, CURDATE())' : 'closed_at';
            $pdo->prepare("UPDATE cases SET status=?, closed_at=$closed, updated_at=NOW() WHERE id=?")->execute(array($d['opt_caso'], $d['case']));
            audit_log('reconcile_case', 'case', $d['case'], "{$d['case_status']} → {$d['opt_caso']} (cron: mais recente=lead)");
            $n++;
        } elseif ($d['sugestao'] === 'lead_recebe' && $d['opt_lead'] !== null && $d['opt_lead'] !== $d['lead_stage']) {
            $pdo->prepare("UPDATE pipeline_leads SET stage=?, updated_at=NOW() WHERE id=?")->execute(array($d['opt_lead'], $d['lead']));
            audit_log('reconcile_lead', 'lead', $d['lead'], "{$d['lead_stage']} → {$d['opt_lead']} (cron: mais recente=caso)");
            $n++;
        }
    }
    echo "Reconciliacao concluida.\nDivergencias: " . count($divergencias) . "\nCorrigidos (cron, mais-recente-vence): $n\n";
    exit;
}

$pageTitle = 'Reconciliar Pipeline ↔ Operacional';
require_once __DIR__ . '/../../templates/layout_start.php';
?>
<div class="container" style="max-width:1180px;margin:20px auto;padding:0 20px">
    <h1>🔄 Reconciliar Pipeline ↔ Operacional</h1>
    <p style="color:#666">Compara <code>cases.status</code> com <code>pipeline_leads.stage</code>. Por padrão, <strong>o lado atualizado por último vence</strong> — escolha por linha o que aplicar.</p>

    <h2>Divergências encontradas: <?= count($divergencias) ?></h2>
    <?php if (empty($divergencias)): ?>
        <p style="color:#10b981">✅ Tudo sincronizado!</p>
    <?php else: ?>
    <form method="POST">
        <?= csrf_input() ?>
        <div style="background:#fef3c7;border:1px solid #f59e0b;padding:12px;border-radius:8px;margin:16px 0">
            <strong>Marque a ação de cada linha</strong> e clique em aplicar. Nada muda sem você escolher.
            <button type="submit" class="btn btn-primary" style="margin-left:12px"
                onclick="return confirm('Aplicar as ações escolhidas no banco?')">Aplicar selecionadas</button>
        </div>
        <table class="table" style="width:100%;border-collapse:collapse;font-size:13px">
            <thead style="background:#f3f4f6">
                <tr>
                    <th style="padding:8px;text-align:left;border:1px solid #e5e7eb">Lead / Case</th>
                    <th style="padding:8px;text-align:left;border:1px solid #e5e7eb">Nome</th>
                    <th style="padding:8px;text-align:left;border:1px solid #e5e7eb">Lead (stage · atualizado)</th>
                    <th style="padding:8px;text-align:left;border:1px solid #e5e7eb">Caso (status · atualizado)</th>
                    <th style="padding:8px;text-align:left;border:1px solid #e5e7eb">Ação</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($divergencias as $d): $k = $d['lead'] . ':' . $d['case']; ?>
                <tr<?= $d['alerta'] ? ' style="background:#fff7ed"' : '' ?>>
                    <td style="padding:8px;border:1px solid #e5e7eb;white-space:nowrap">
                        <a href="<?= url('modules/pipeline/lead_ver.php?id=' . $d['lead']) ?>">L#<?= $d['lead'] ?></a> /
                        <a href="<?= url('modules/operacional/caso_ver.php?id=' . $d['case']) ?>">C#<?= $d['case'] ?></a>
                    </td>
                    <td style="padding:8px;border:1px solid #e5e7eb"><?= e($d['name']) ?></td>
                    <td style="padding:8px;border:1px solid #e5e7eb"><code><?= e($d['lead_stage']) ?></code><br><small style="color:#888"><?= e($d['lead_upd']) ?><?= $d['mais_recente']==='lead' ? ' · <b>+ recente</b>' : '' ?></small></td>
                    <td style="padding:8px;border:1px solid #e5e7eb"><code><?= e($d['case_status']) ?></code><br><small style="color:#888"><?= e($d['case_upd']) ?><?= $d['mais_recente']==='caso' ? ' · <b>+ recente</b>' : '' ?></small></td>
                    <td style="padding:8px;border:1px solid #e5e7eb">
                        <select name="acao[<?= $k ?>]" style="padding:6px;border:1px solid #ccc;border-radius:6px;max-width:340px">
                            <option value="ignorar" <?= $d['sugestao']==='ignorar'?'selected':'' ?>>Ignorar (não mexer)</option>
                            <?php if ($d['opt_lead'] !== null && $d['opt_lead'] !== $d['lead_stage']): ?>
                                <option value="lead_recebe" <?= $d['sugestao']==='lead_recebe'?'selected':'' ?>>Lead → "<?= e($d['opt_lead']) ?>" (espelha o caso)</option>
                            <?php endif; ?>
                            <?php if ($d['opt_caso'] !== null && $d['opt_caso'] !== $d['case_status']): ?>
                                <option value="caso_recebe" <?= $d['sugestao']==='caso_recebe'?'selected':'' ?>>Caso → "<?= e($d['opt_caso']) ?>" (espelha o lead)</option>
                            <?php endif; ?>
                        </select>
                        <?php if ($d['alerta']): ?><div style="color:#c2410c;font-size:11px;margin-top:4px"><?= e($d['alerta']) ?></div><?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <button type="submit" class="btn btn-primary" style="margin-top:16px"
            onclick="return confirm('Aplicar as ações escolhidas no banco?')">Aplicar selecionadas</button>
    </form>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
