<?php
/**
 * Ferreira & Sá Hub — Painel DataJud (Admin)
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();
if (!has_min_role('gestao')) { flash_set('error', 'Sem permissao.'); redirect(url('modules/dashboard/')); }

$pageTitle = 'DataJud — Sincronizacao';
$pdo = db();

// KPIs
$kpis = array('sincronizados' => 0, 'erros' => 0, 'nao_encontrados' => 0, 'pendentes' => 0);

try {
    $kpis['sincronizados'] = (int)$pdo->query("SELECT COUNT(*) FROM cases WHERE datajud_sincronizado = 1 AND case_number IS NOT NULL AND case_number != ''")->fetchColumn();
    $kpis['erros'] = (int)$pdo->query("SELECT COUNT(*) FROM cases WHERE datajud_erro IS NOT NULL AND datajud_erro != '' AND case_number IS NOT NULL AND case_number != '' AND status NOT IN ('cancelado','arquivado')")->fetchColumn();
    $kpis['nao_encontrados'] = (int)$pdo->query(
        "SELECT COUNT(DISTINCT dsl.case_id) FROM datajud_sync_log dsl
         INNER JOIN cases cs ON cs.id = dsl.case_id
         WHERE dsl.status = 'nao_encontrado'
         AND dsl.id = (SELECT MAX(id) FROM datajud_sync_log WHERE case_id = dsl.case_id)
         AND cs.status NOT IN ('cancelado','arquivado')"
    )->fetchColumn();
    $kpis['pendentes'] = (int)$pdo->query(
        "SELECT COUNT(*) FROM cases
         WHERE case_number IS NOT NULL AND case_number != ''
         AND status NOT IN ('cancelado','arquivado')
         AND (datajud_ultima_sync IS NULL)"
    )->fetchColumn();
} catch (Exception $e) {}

// Lista de processos com status de sync
$processos = array();
try {
    $processos = $pdo->query(
        "SELECT cs.id, cs.title, cs.case_number, cs.status, cs.datajud_sincronizado,
                cs.datajud_ultima_sync, cs.datajud_erro,
                (SELECT movimentos_novos FROM datajud_sync_log WHERE case_id = cs.id ORDER BY created_at DESC LIMIT 1) as ultimos_novos,
                (SELECT status FROM datajud_sync_log WHERE case_id = cs.id ORDER BY created_at DESC LIMIT 1) as ultimo_status
         FROM cases cs
         WHERE cs.case_number IS NOT NULL AND cs.case_number != ''
         AND cs.status NOT IN ('cancelado','arquivado')
         ORDER BY cs.datajud_ultima_sync DESC, cs.title ASC"
    )->fetchAll();
} catch (Exception $e) {}

// Log recente
$logRecente = array();
try {
    $logRecente = $pdo->query(
        "SELECT dsl.*, cs.title as case_title, cs.case_number, u.name as user_name
         FROM datajud_sync_log dsl
         LEFT JOIN cases cs ON cs.id = dsl.case_id
         LEFT JOIN users u ON u.id = dsl.sincronizado_por
         ORDER BY dsl.created_at DESC LIMIT 30"
    )->fetchAll();
} catch (Exception $e) {}

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.dj-kpis { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:1rem; margin-bottom:1.5rem; }
.dj-kpi { background:#fff; border:1px solid var(--border); border-radius:12px; padding:1.2rem; text-align:center; }
body.dark-mode .dj-kpi { background:var(--bg-card); }
.dj-kpi-val { font-size:2rem; font-weight:800; }
.dj-kpi-label { font-size:.75rem; color:var(--text-muted); font-weight:600; text-transform:uppercase; letter-spacing:.5px; margin-top:.25rem; }
.dj-status { display:inline-flex; align-items:center; gap:.25rem; font-size:.72rem; font-weight:600; padding:.15rem .5rem; border-radius:20px; }
.dj-status.sucesso { background:#ecfdf5; color:#059669; }
.dj-status.erro { background:#fef2f2; color:#dc2626; }
.dj-status.nao_encontrado { background:#eff6ff; color:#3b82f6; }
.dj-status.pendente { background:#fef3c7; color:#d97706; }
.dj-sync-all { margin-bottom:1.5rem; }
</style>

<div class="dj-kpis">
    <div class="dj-kpi">
        <div class="dj-kpi-val" style="color:#059669;"><?= $kpis['sincronizados'] ?></div>
        <div class="dj-kpi-label">Sincronizados</div>
    </div>
    <div class="dj-kpi">
        <div class="dj-kpi-val" style="color:#dc2626;"><?= $kpis['erros'] ?></div>
        <div class="dj-kpi-label">Erros</div>
    </div>
    <div class="dj-kpi">
        <div class="dj-kpi-val" style="color:#3b82f6;"><?= $kpis['nao_encontrados'] ?></div>
        <div class="dj-kpi-label">Nao encontrados</div>
    </div>
    <div class="dj-kpi">
        <div class="dj-kpi-val" style="color:#d97706;"><?= $kpis['pendentes'] ?></div>
        <div class="dj-kpi-label">Pendentes</div>
    </div>
</div>

<div class="dj-sync-all">
    <button onclick="syncAll(this)" class="btn btn-primary btn-sm" id="btnSyncAll">Sincronizar todos agora</button>
    <span id="syncAllStatus" style="margin-left:.5rem;font-size:.8rem;color:var(--text-muted);"></span>
</div>

<!-- Tabela de processos -->
<div class="card mb-2">
    <div class="card-header"><h3>Processos (<?= count($processos) ?>)</h3></div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Processo</th>
                    <th>Numero</th>
                    <th>Ultima sync</th>
                    <th>Status</th>
                    <th>Acao</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($processos as $p):
                    $statusSync = 'pendente';
                    if ($p['datajud_sincronizado'] && !$p['datajud_erro']) $statusSync = 'sucesso';
                    elseif ($p['datajud_erro']) $statusSync = 'erro';
                    elseif ($p['ultimo_status'] === 'nao_encontrado') $statusSync = 'nao_encontrado';
                ?>
                <tr>
                    <td><a href="<?= module_url('operacional', 'caso_ver.php?id=' . $p['id']) ?>" style="color:var(--petrol-900);font-weight:600;"><?= e($p['title']) ?></a></td>
                    <td class="text-sm"><?= e($p['case_number']) ?></td>
                    <td class="text-sm text-muted"><?= $p['datajud_ultima_sync'] ? date('d/m H:i', strtotime($p['datajud_ultima_sync'])) : '—' ?></td>
                    <td>
                        <?php if ($statusSync === 'sucesso'): ?>
                            <span class="dj-status sucesso"><?= ($p['ultimos_novos'] > 0) ? $p['ultimos_novos'] . ' nov' : 'OK' ?></span>
                        <?php elseif ($statusSync === 'erro'): ?>
                            <span class="dj-status erro" title="<?= e($p['datajud_erro']) ?>">Erro</span>
                        <?php elseif ($statusSync === 'nao_encontrado'): ?>
                            <span class="dj-status nao_encontrado">N/A</span>
                        <?php else: ?>
                            <span class="dj-status pendente">Pendente</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <button onclick="syncCase(<?= $p['id'] ?>, this)" class="btn btn-outline btn-sm" style="font-size:.7rem;">Sync</button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($processos)): ?>
                <tr><td colspan="5" class="text-center text-muted" style="padding:2rem;">Nenhum processo com numero cadastrado.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Log recente -->
<div class="card">
    <div class="card-header"><h3>Log de sincronizacao (ultimas 30)</h3></div>
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Data</th>
                    <th>Processo</th>
                    <th>Status</th>
                    <th>Novos</th>
                    <th>Mensagem</th>
                    <th>Tipo</th>
                    <th>Usuario</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logRecente as $log): ?>
                <tr>
                    <td class="text-sm"><?= date('d/m H:i', strtotime($log['created_at'])) ?></td>
                    <td><a href="<?= module_url('operacional', 'caso_ver.php?id=' . $log['case_id']) ?>" style="font-size:.8rem;"><?= e($log['case_title'] ?: '#' . $log['case_id']) ?></a></td>
                    <td>
                        <span class="dj-status <?= $log['status'] ?>"><?php
                            $stMap = array('sucesso'=>'OK','erro'=>'Erro','segredo'=>'Segredo','nao_encontrado'=>'N/A');
                            echo $stMap[$log['status']] ?? $log['status'];
                        ?></span>
                    </td>
                    <td class="text-center"><?= $log['movimentos_novos'] ?: '—' ?></td>
                    <td class="text-sm text-muted"><?= e($log['mensagem'] ?: '') ?></td>
                    <td class="text-sm"><?= $log['tipo'] === 'manual' ? 'Manual' : 'Auto' ?></td>
                    <td class="text-sm"><?= e($log['user_name'] ?: 'Cron') ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($logRecente)): ?>
                <tr><td colspan="7" class="text-center text-muted" style="padding:2rem;">Nenhuma sincronizacao realizada.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
var CSRF = '<?= generate_csrf_token() ?>';
var API_SYNC = '<?= url("api/datajud_sync.php") ?>';

function syncCase(caseId, btn) {
    btn.disabled = true;
    btn.textContent = '...';

    var fd = new FormData();
    fd.append('case_id', caseId);
    fd.append('<?= CSRF_TOKEN_NAME ?>', CSRF);

    var xhr = new XMLHttpRequest();
    xhr.open('POST', API_SYNC);
    xhr.onload = function() {
        try {
            var r = JSON.parse(xhr.responseText);
            if (r.csrf) CSRF = r.csrf;
            if (r.status === 'sucesso') {
                btn.textContent = r.novos > 0 ? '+' + r.novos : 'OK';
                btn.style.background = '#059669';
                btn.style.color = '#fff';
                btn.style.borderColor = '#059669';
            } else {
                btn.textContent = 'Erro';
                btn.style.color = '#dc2626';
            }
        } catch(e) {
            btn.textContent = 'Erro';
        }
        setTimeout(function() { btn.disabled = false; btn.textContent = 'Sync'; btn.style = ''; }, 3000);
    };
    xhr.onerror = function() { btn.textContent = 'Erro'; btn.disabled = false; };
    xhr.send(fd);
}

function syncAll(btn) {
    if (!confirm('Sincronizar todos os processos agora? Isso pode levar alguns minutos.')) return;
    btn.disabled = true;
    btn.textContent = 'Sincronizando...';
    document.getElementById('syncAllStatus').textContent = 'Aguarde, executando via cron...';

    var xhr = new XMLHttpRequest();
    xhr.open('GET', '<?= url("api/datajud_cron.php") ?>?key=fsa-hub-deploy-2026');
    xhr.onload = function() {
        btn.disabled = false;
        btn.textContent = 'Sincronizar todos agora';
        document.getElementById('syncAllStatus').textContent = 'Concluido! Recarregue a pagina para ver os resultados.';
        setTimeout(function() { location.reload(); }, 2000);
    };
    xhr.onerror = function() {
        btn.disabled = false;
        btn.textContent = 'Sincronizar todos agora';
        document.getElementById('syncAllStatus').textContent = 'Erro ao executar. Tente novamente.';
    };
    xhr.send();
}
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
