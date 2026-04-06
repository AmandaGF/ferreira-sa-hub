<?php
/**
 * Ferreira & Sá Hub — Monitoramento DataJud (CNJ)
 * Painel avançado com feed de movimentações, filtros e sync manual
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
if (!has_min_role('gestao')) { flash_set('error', 'Sem permissao.'); redirect(url('modules/dashboard/')); }

$pdo = db();

// ── Filtros ──
$periodo = $_GET['periodo'] ?? '7';
$status_filtro = $_GET['status'] ?? 'todos';
$busca = trim($_GET['busca'] ?? '');

$dias = in_array($periodo, array('1','7','15','30','90')) ? (int)$periodo : 7;
$dataCorte = date('Y-m-d H:i:s', strtotime("-{$dias} days"));

// ── Sync manual de caso unico (AJAX) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'sync_one') {
    header('Content-Type: application/json; charset=utf-8');
    if (!validate_csrf()) {
        echo json_encode(array('status' => 'erro', 'msg' => 'Token invalido', 'csrf' => generate_csrf_token()));
        exit;
    }
    $cid = (int)($_POST['case_id'] ?? 0);
    if ($cid > 0) {
        $res = datajud_sincronizar_caso($cid, 'manual', current_user_id());
        $res['csrf'] = generate_csrf_token();
        echo json_encode($res);
    } else {
        echo json_encode(array('status' => 'erro', 'msg' => 'ID invalido', 'csrf' => generate_csrf_token()));
    }
    exit;
}

// ── Sync todos (POST form) ──
$msgSync = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'sync_all') {
    if (validate_csrf()) {
        // Chamar cron via HTTP interno
        $cronUrl = url('api/datajud_cron.php') . '?key=fsa-hub-deploy-2026';
        $ch = curl_init($cronUrl);
        curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_SSL_VERIFYPEER => false));
        curl_exec($ch);
        curl_close($ch);
        $msgSync = 'sucesso';
    }
}

// ── Query principal: processos + ultima sync ──
$whereStatus = '';
$paramsQuery = array($dataCorte);

if ($status_filtro === 'com_novidades') {
    $whereStatus = "AND EXISTS (SELECT 1 FROM case_andamentos ca WHERE ca.case_id = cs.id AND ca.tipo_origem = 'datajud' AND ca.created_at >= ?)";
    $paramsQuery[] = $dataCorte;
} elseif ($status_filtro === 'erro') {
    $whereStatus = "AND cs.datajud_erro IS NOT NULL AND cs.datajud_sincronizado = 0";
} elseif ($status_filtro === 'nunca') {
    $whereStatus = "AND cs.datajud_ultima_sync IS NULL";
}

$buscaWhere = '';
if ($busca) {
    $buscaWhere = "AND (cs.title LIKE ? OR cs.case_number LIKE ? OR c.name LIKE ?)";
    $paramsQuery[] = "%$busca%";
    $paramsQuery[] = "%$busca%";
    $paramsQuery[] = "%$busca%";
}

$stmt = $pdo->prepare("
    SELECT cs.id, cs.title, cs.case_number, cs.datajud_ultima_sync,
           cs.datajud_sincronizado, cs.datajud_erro, cs.status,
           c.name as client_name,
           (SELECT COUNT(*) FROM case_andamentos ca
            WHERE ca.case_id = cs.id AND ca.tipo_origem = 'datajud'
            AND ca.created_at >= ?) as novos_periodo,
           (SELECT COUNT(*) FROM case_andamentos ca2
            WHERE ca2.case_id = cs.id AND ca2.tipo_origem = 'datajud') as total_datajud
    FROM cases cs
    LEFT JOIN clients c ON c.id = cs.client_id
    WHERE cs.case_number IS NOT NULL AND cs.case_number != ''
    AND cs.status NOT IN ('arquivado','cancelado')
    $whereStatus
    $buscaWhere
    ORDER BY novos_periodo DESC, cs.datajud_ultima_sync DESC
    LIMIT 200
");
$stmt->execute($paramsQuery);
$processos = $stmt->fetchAll();

// ── KPIs gerais ──
$kpiStmt = $pdo->prepare("
    SELECT
        COUNT(DISTINCT cs.id) as total_com_numero,
        COUNT(DISTINCT CASE WHEN cs.datajud_ultima_sync IS NOT NULL THEN cs.id END) as ja_sincronizados,
        COUNT(DISTINCT CASE WHEN cs.datajud_erro IS NOT NULL AND cs.datajud_sincronizado = 0 THEN cs.id END) as com_erro,
        (SELECT COUNT(*) FROM case_andamentos WHERE tipo_origem = 'datajud' AND created_at >= ?) as movimentos_periodo
    FROM cases cs
    WHERE cs.case_number IS NOT NULL AND cs.case_number != '' AND cs.status NOT IN ('arquivado','cancelado')
");
$kpiStmt->execute(array($dataCorte));
$kpi = $kpiStmt->fetch();

// ── Ultimas movimentacoes importadas (feed) ──
$feedStmt = $pdo->prepare("
    SELECT ca.id, ca.case_id, ca.data_andamento, ca.tipo, ca.descricao, ca.created_at,
           cs.title as case_title, cs.case_number,
           c.name as client_name
    FROM case_andamentos ca
    JOIN cases cs ON cs.id = ca.case_id
    LEFT JOIN clients c ON c.id = cs.client_id
    WHERE ca.tipo_origem = 'datajud'
    AND ca.created_at >= ?
    ORDER BY ca.created_at DESC
    LIMIT 50
");
$feedStmt->execute(array($dataCorte));
$movimentos = $feedStmt->fetchAll();

$pageTitle = 'Monitoramento DataJud';
require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.dj-kpi-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:1rem; margin-bottom:1.5rem; }
.dj-kpi { background:#fff; border:1px solid var(--border); border-radius:12px; padding:1.2rem 1.4rem; display:flex; flex-direction:column; gap:.3rem; }
body.dark-mode .dj-kpi { background:var(--bg-card); }
.dj-kpi .valor { font-size:2rem; font-weight:800; line-height:1; }
.dj-kpi .label { font-size:.75rem; color:var(--text-muted); font-weight:500; }
.dj-kpi.verde .valor { color:#059669; }
.dj-kpi.azul .valor { color:#3b82f6; }
.dj-kpi.vermelho .valor { color:#dc2626; }
.dj-kpi.cobre .valor { color:#B87333; }

.dj-badge { font-size:.65rem; font-weight:700; padding:2px 7px; border-radius:4px; display:inline-flex; align-items:center; gap:3px; }
.dj-badge.novo { background:#eff6ff; color:#3b82f6; }
.dj-badge.erro { background:#fef2f2; color:#dc2626; }
.dj-badge.ok { background:#ecfdf5; color:#059669; }
.dj-badge.nunca { background:#f8fafc; color:#94a3b8; }

.dj-filtros { display:flex; gap:.6rem; flex-wrap:wrap; align-items:center; margin-bottom:1.2rem; }
.dj-filtros select, .dj-filtros input { font-size:.82rem; padding:.35rem .7rem; border:1px solid var(--border); border-radius:8px; background:#fff; }

.dj-table { width:100%; border-collapse:collapse; font-size:.82rem; }
.dj-table th { background:#f8fafc; padding:.6rem .8rem; text-align:left; font-size:.72rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; border-bottom:2px solid var(--border); }
body.dark-mode .dj-table th { background:var(--bg-secondary); }
.dj-table td { padding:.65rem .8rem; border-bottom:1px solid var(--border); vertical-align:middle; }
.dj-table tr:hover td { background:#f8fafc; }
body.dark-mode .dj-table tr:hover td { background:var(--bg-secondary); }

.dj-feed { display:flex; flex-direction:column; gap:.6rem; max-height:480px; overflow-y:auto; }
.dj-feed-item { background:#fff; border:1px solid var(--border); border-left:3px solid #3b82f6; border-radius:8px; padding:.8rem 1rem; }
body.dark-mode .dj-feed-item { background:var(--bg-card); }
.dj-feed-item .meta { font-size:.68rem; color:var(--text-muted); margin-bottom:.25rem; }
.dj-feed-item .desc { font-size:.82rem; color:var(--text); white-space:pre-wrap; }
.dj-feed-item .case-link { font-size:.75rem; font-weight:700; color:#052228; text-decoration:none; }
body.dark-mode .dj-feed-item .case-link { color:var(--text); }
.dj-feed-item .case-link:hover { text-decoration:underline; }

.tipo-icon { display:inline-flex; align-items:center; justify-content:center; width:22px; height:22px; border-radius:50%; font-size:11px; background:#eff6ff; }

.btn-sync-one { background:none; border:1px solid #052228; color:#052228; border-radius:6px; padding:2px 8px; font-size:.7rem; cursor:pointer; transition:.15s; }
.btn-sync-one:hover { background:#052228; color:#fff; }
.btn-sync-one:disabled { opacity:.4; cursor:not-allowed; }

@keyframes spin { to { transform:rotate(360deg); } }

@media (max-width:900px) {
    .dj-layout { grid-template-columns:1fr !important; }
}
</style>

<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;margin-bottom:1.5rem;">
    <div>
        <h2 style="margin:0;font-size:1.3rem;color:var(--petrol-900);display:flex;align-items:center;gap:.5rem;">
            Monitoramento DataJud
        </h2>
        <p style="margin:.2rem 0 0;font-size:.8rem;color:var(--text-muted);">Movimentacoes importadas automaticamente do CNJ</p>
    </div>
    <div style="display:flex;gap:.5rem;align-items:center;">
        <a href="<?= module_url('admin', 'datajud.php') ?>" class="btn btn-outline btn-sm">Painel Simples</a>
        <form method="POST" style="display:inline;" id="formSyncAll">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="sync_all">
            <button type="button" onclick="syncAllMonitor()" class="btn btn-primary btn-sm" id="btnSyncAll">Sincronizar Todos Agora</button>
        </form>
    </div>
</div>

<?php if ($msgSync === 'sucesso'): ?>
<div style="background:#ecfdf5;border:1px solid #059669;border-radius:8px;padding:.8rem 1.2rem;margin-bottom:1rem;font-size:.85rem;color:#059669;font-weight:600;">
    Sincronizacao disparada com sucesso! Os processos serao atualizados em instantes.
</div>
<?php endif; ?>

<!-- KPIs -->
<div class="dj-kpi-grid">
    <div class="dj-kpi azul">
        <span class="valor"><?= $kpi['total_com_numero'] ?></span>
        <span class="label">Processos com numero</span>
    </div>
    <div class="dj-kpi verde">
        <span class="valor"><?= $kpi['ja_sincronizados'] ?></span>
        <span class="label">Ja sincronizados</span>
    </div>
    <div class="dj-kpi cobre">
        <span class="valor"><?= $kpi['movimentos_periodo'] ?></span>
        <span class="label">Movimentos importados (<?= $dias ?>d)</span>
    </div>
    <div class="dj-kpi vermelho">
        <span class="valor"><?= $kpi['com_erro'] ?></span>
        <span class="label">Com erro / nao encontrado</span>
    </div>
</div>

<!-- Layout dois paineis -->
<div class="dj-layout" style="display:grid;grid-template-columns:1fr 380px;gap:1.2rem;align-items:start;">

    <!-- Painel esquerdo: tabela de processos -->
    <div class="card" style="padding:1.2rem;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;flex-wrap:wrap;gap:.6rem;">
            <h3 style="margin:0;font-size:1rem;">Processos Monitorados (<?= count($processos) ?>)</h3>
        </div>
        <form method="GET" class="dj-filtros">
            <select name="periodo" onchange="this.form.submit()">
                <option value="1" <?= $dias==1?'selected':'' ?>>Hoje</option>
                <option value="7" <?= $dias==7?'selected':'' ?>>Ultimos 7 dias</option>
                <option value="15" <?= $dias==15?'selected':'' ?>>Ultimos 15 dias</option>
                <option value="30" <?= $dias==30?'selected':'' ?>>Ultimos 30 dias</option>
                <option value="90" <?= $dias==90?'selected':'' ?>>Ultimos 90 dias</option>
            </select>
            <select name="status" onchange="this.form.submit()">
                <option value="todos" <?= $status_filtro=='todos'?'selected':'' ?>>Todos</option>
                <option value="com_novidades" <?= $status_filtro=='com_novidades'?'selected':'' ?>>Com novidades</option>
                <option value="erro" <?= $status_filtro=='erro'?'selected':'' ?>>Com erro</option>
                <option value="nunca" <?= $status_filtro=='nunca'?'selected':'' ?>>Nunca sincronizados</option>
            </select>
            <input type="text" name="busca" placeholder="Buscar processo, cliente..." value="<?= e($busca) ?>" style="width:180px;">
            <button type="submit" class="btn btn-sm btn-outline">Filtrar</button>
        </form>

        <?php if (empty($processos)): ?>
            <p style="text-align:center;color:var(--text-muted);padding:2rem;font-size:.85rem;">Nenhum processo encontrado com os filtros aplicados.</p>
        <?php else: ?>
        <div style="overflow-x:auto;">
            <table class="dj-table">
                <thead>
                    <tr>
                        <th>Processo / Cliente</th>
                        <th>Ultima Sync</th>
                        <th>Status</th>
                        <th style="text-align:center;">Novos (<?= $dias ?>d)</th>
                        <th style="text-align:center;">Total DJ</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($processos as $p):
                    $temNovos = (int)$p['novos_periodo'] > 0;
                    $temErro = !empty($p['datajud_erro']) && !$p['datajud_sincronizado'];
                    $nuncaSync = empty($p['datajud_ultima_sync']);
                ?>
                    <tr>
                        <td>
                            <a href="<?= module_url('operacional', 'caso_ver.php?id=' . $p['id']) ?>" style="font-weight:600;color:var(--petrol-900);text-decoration:none;font-size:.82rem;">
                                <?= e($p['title'] ?: 'Sem titulo') ?>
                            </a>
                            <div style="font-size:.7rem;color:var(--text-muted);">
                                <?= e($p['client_name'] ?? '') ?>
                                <?php if ($p['case_number']): ?>
                                    &middot; <span style="font-family:monospace;"><?= e($p['case_number']) ?></span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td style="font-size:.75rem;color:var(--text-muted);white-space:nowrap;">
                            <?= $p['datajud_ultima_sync'] ? date('d/m/Y H:i', strtotime($p['datajud_ultima_sync'])) : '—' ?>
                        </td>
                        <td>
                            <?php if ($temErro): ?>
                                <span class="dj-badge erro" title="<?= e($p['datajud_erro']) ?>">Erro</span>
                            <?php elseif ($nuncaSync): ?>
                                <span class="dj-badge nunca">Nunca</span>
                            <?php elseif ($temNovos): ?>
                                <span class="dj-badge novo">Novidades</span>
                            <?php else: ?>
                                <span class="dj-badge ok">Atualizado</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:center;">
                            <?php if ($temNovos): ?>
                                <span style="font-weight:800;color:#3b82f6;font-size:.9rem;"><?= $p['novos_periodo'] ?></span>
                            <?php else: ?>
                                <span style="color:#94a3b8;">0</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:center;font-size:.8rem;color:var(--text-muted);"><?= $p['total_datajud'] ?></td>
                        <td>
                            <button class="btn-sync-one" onclick="syncOne(<?= $p['id'] ?>, this)">Sync</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- Painel direito: feed de movimentos recentes -->
    <div class="card" style="padding:1.2rem;">
        <h3 style="margin:0 0 1rem;font-size:1rem;display:flex;align-items:center;gap:.4rem;">
            Movimentos Importados
            <span style="font-size:.7rem;font-weight:400;color:var(--text-muted);">(ultimos <?= $dias ?>d)</span>
        </h3>
        <?php if (empty($movimentos)): ?>
            <p style="color:var(--text-muted);font-size:.82rem;text-align:center;padding:1.5rem 0;">Nenhuma movimentacao importada neste periodo.</p>
        <?php else: ?>
        <div class="dj-feed">
            <?php
            $tipoIcons = array('movimentacao'=>'📋','despacho'=>'📤','decisao'=>'⚖️','sentenca'=>'🏛️',
                'audiencia'=>'🎤','peticao_juntada'=>'📎','intimacao'=>'📬','citacao'=>'📨',
                'acordo'=>'🤝','recurso'=>'📑','cumprimento'=>'✅','diligencia'=>'🔍','observacao'=>'💬');
            foreach ($movimentos as $mv):
                $ic = isset($tipoIcons[$mv['tipo']]) ? $tipoIcons[$mv['tipo']] : '📋';
                $desc = mb_strlen($mv['descricao']) > 120 ? mb_substr($mv['descricao'], 0, 120) . '...' : $mv['descricao'];
            ?>
            <div class="dj-feed-item">
                <div class="meta">
                    <span class="tipo-icon"><?= $ic ?></span>
                    <a href="<?= module_url('operacional', 'caso_ver.php?id=' . $mv['case_id']) ?>" class="case-link">
                        <?= e($mv['case_title'] ?: 'Caso #' . $mv['case_id']) ?>
                    </a>
                    &middot; <span><?= e($mv['client_name'] ?? '') ?></span>
                    <br>
                    <span style="color:#3b82f6;font-weight:600;">DataJud</span>
                    &middot; <?= date('d/m/Y', strtotime($mv['data_andamento'])) ?>
                    &middot; importado <?= date('d/m H:i', strtotime($mv['created_at'])) ?>
                </div>
                <div class="desc"><?= e($desc) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

</div>

<script>
var _djCsrf = '<?= generate_csrf_token() ?>';

function syncAllMonitor() {
    if (!confirm('Disparar sincronizacao de todos os processos agora?\n\nIsso pode levar alguns minutos.')) return;
    var btn = document.getElementById('btnSyncAll');
    btn.disabled = true;
    btn.innerHTML = '<span style="display:inline-block;width:14px;height:14px;border:2px solid #fff;border-top-color:transparent;border-radius:50%;animation:spin .6s linear infinite;vertical-align:middle;margin-right:4px;"></span> Sincronizando...';
    document.getElementById('formSyncAll').submit();
}

function syncOne(caseId, btn) {
    btn.disabled = true;
    btn.innerHTML = '...';
    var fd = new FormData();
    fd.append('action', 'sync_one');
    fd.append('case_id', caseId);
    fd.append('<?= CSRF_TOKEN_NAME ?>', _djCsrf);

    var xhr = new XMLHttpRequest();
    xhr.open('POST', window.location.href.split('?')[0]);
    xhr.onload = function() {
        try {
            var r = JSON.parse(xhr.responseText);
            if (r.csrf) _djCsrf = r.csrf;
            if (r.status === 'sucesso') {
                btn.innerHTML = (r.novos > 0 ? r.novos + ' novos' : 'OK');
                btn.style.borderColor = '#059669';
                btn.style.color = '#059669';
            } else {
                btn.innerHTML = 'Erro';
                btn.style.borderColor = '#dc2626';
                btn.style.color = '#dc2626';
            }
        } catch(e) {
            btn.innerHTML = 'Falha';
        }
        setTimeout(function() { btn.disabled = false; btn.innerHTML = 'Sync'; btn.style.borderColor = ''; btn.style.color = ''; }, 3000);
    };
    xhr.onerror = function() { btn.innerHTML = 'Falha'; btn.disabled = false; };
    xhr.send(fd);
}
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
