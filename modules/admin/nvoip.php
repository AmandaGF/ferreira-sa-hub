<?php
/**
 * modules/admin/nvoip.php — Painel de configuração da integração Nvoip
 */
require_once __DIR__ . '/../../core/middleware.php';
require_once __DIR__ . '/../../core/functions_nvoip.php';
require_login();
if (!has_min_role('admin')) { flash_set('error', 'Só admin.'); redirect(url('modules/dashboard/')); }

$pdo = db();
$pageTitle = 'Nvoip — Configuração VoIP';

$napi = nvoip_cfg_get('nvoip_napikey');
$sip  = nvoip_cfg_get('nvoip_numbersip');
$ut   = nvoip_cfg_get('nvoip_user_token');
$tokenOk = nvoip_cfg_get('nvoip_access_token') !== '';
$expiry  = nvoip_cfg_get('nvoip_token_expiry');

// Users e ramais
$users = $pdo->query("SELECT id, name, nvoip_ramal FROM users WHERE is_active = 1 ORDER BY name")->fetchAll();

// Filtros do histórico
$fUser   = (int)($_GET['user_id'] ?? 0);
$fStatus = trim($_GET['status'] ?? '');
$fDini   = trim($_GET['dini'] ?? '');
$fDfim   = trim($_GET['dfim'] ?? '');

$where = array(); $params = array();
if ($fUser)   { $where[] = 'l.atendente_id = ?'; $params[] = $fUser; }
if ($fStatus) { $where[] = 'l.status = ?';       $params[] = $fStatus; }
if ($fDini && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fDini)) { $where[] = 'l.iniciada_em >= ?'; $params[] = $fDini . ' 00:00:00'; }
if ($fDfim && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fDfim)) { $where[] = 'l.iniciada_em <= ?'; $params[] = $fDfim . ' 23:59:59'; }
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$sql = "SELECT l.id, l.call_id, l.telefone_destino, l.duracao_segundos, l.status,
               l.iniciada_em, l.gravacao_local, l.resumo_ia,
               u.name AS atendente_nome,
               c.name AS cliente_nome
        FROM ligacoes_historico l
        LEFT JOIN users u ON u.id = l.atendente_id
        LEFT JOIN clients c ON c.id = l.client_id
        $whereSql
        ORDER BY l.iniciada_em DESC LIMIT 100";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$ligacoes = $stmt->fetchAll();

// Export CSV
if (isset($_GET['csv'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ligacoes_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, array('ID','Data','Atendente','Cliente','Telefone','Duração (s)','Status','CallID'));
    foreach ($ligacoes as $l) {
        fputcsv($out, array($l['id'], $l['iniciada_em'], $l['atendente_nome'], $l['cliente_nome'],
            $l['telefone_destino'], $l['duracao_segundos'], $l['status'], $l['call_id']));
    }
    exit;
}

require_once APP_ROOT . '/templates/layout_start.php';
?>
<style>
.nv-sec { background:var(--bg-card); border:1px solid var(--border); border-radius:12px; padding:1.2rem 1.4rem; margin-bottom:1rem; }
.nv-sec h3 { margin:0 0 .8rem; font-size:1rem; color:var(--petrol-900); }
.nv-grid { display:grid; grid-template-columns:1fr 1fr; gap:.75rem; }
.nv-grid label { font-size:.78rem; font-weight:700; color:var(--text-muted); display:block; margin-bottom:3px; }
.nv-grid input { width:100%; padding:6px 10px; border:1.5px solid var(--border); border-radius:6px; font-size:.88rem; }
.nv-tbl { width:100%; border-collapse:collapse; font-size:.82rem; background:var(--bg-card); border-radius:8px; overflow:hidden; }
.nv-tbl th { background:var(--petrol-900); color:#fff; padding:.45rem .6rem; text-align:left; font-size:.7rem; text-transform:uppercase; letter-spacing:.4px; }
.nv-tbl td { padding:.45rem .6rem; border-bottom:1px solid var(--border); }
.nv-badge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:.68rem; font-weight:700; }
.nv-badge.finished { background:#dcfce7; color:#15803d; }
.nv-badge.noanswer, .nv-badge.busy { background:#fef3c7; color:#b45309; }
.nv-badge.failed { background:#fee2e2; color:#b91c1c; }
.nv-badge.calling, .nv-badge.established { background:#eff6ff; color:#1e40af; }
</style>

<div style="max-width:1200px;margin:0 auto;">
    <h2 style="color:var(--petrol-900);">📞 Nvoip — Integração VoIP</h2>

    <!-- STATUS -->
    <div class="nv-sec">
        <h3>Status da conta</h3>
        <div style="display:flex;gap:1.5rem;flex-wrap:wrap;font-size:.85rem;">
            <div>napikey: <strong><?= $napi ? '✓ configurada' : '❌ falta' ?></strong></div>
            <div>numbersip: <strong><?= $sip ?: '❌ falta' ?></strong></div>
            <div>user_token: <strong><?= $ut ? '✓ salvo' : '❌ falta' ?></strong></div>
            <div>OAuth token: <strong><?= $tokenOk ? '✓ ativo' : '—' ?></strong> <?= $expiry ? '(exp ' . date('d/m H:i', strtotime($expiry)) . ')' : '' ?></div>
            <div><button id="btnSaldo" class="btn btn-outline btn-sm" onclick="nvoipSaldo()">💰 Ver saldo</button> <span id="saldoOut"></span></div>
        </div>
    </div>

    <!-- CONFIG -->
    <div class="nv-sec">
        <h3>⚙️ Configurações</h3>
        <p style="font-size:.78rem;color:var(--text-muted);">Deixe em branco o que não quer alterar. Ao salvar, os tokens OAuth anteriores são invalidados (será gerado novo na próxima chamada).</p>
        <form id="formCfg">
            <div class="nv-grid">
                <div><label>napikey</label><input type="text" name="napikey" placeholder="<?= $napi ? str_repeat('•', 8) . ' (preenchida)' : 'Cole a napikey aqui' ?>"></div>
                <div><label>Number SIP</label><input type="text" name="numbersip" placeholder="<?= $sip ?: 'Número SIP principal' ?>" value="<?= e($sip) ?>"></div>
                <div style="grid-column:1/-1;"><label>User token</label><input type="text" name="user_token" placeholder="<?= $ut ? str_repeat('•', 8) . ' (preenchido)' : 'User token pra gerar OAuth' ?>"></div>
            </div>
            <div style="margin-top:.8rem;display:flex;gap:.5rem;">
                <button type="button" onclick="nvoipSalvarCfg()" class="btn btn-primary btn-sm">💾 Salvar</button>
                <button type="button" onclick="nvoipTestar()" class="btn btn-outline btn-sm">🔌 Testar conexão</button>
                <span id="cfgMsg" style="font-size:.8rem;"></span>
            </div>
        </form>
    </div>

    <!-- RAMAIS -->
    <div class="nv-sec">
        <h3>👥 Ramais por usuário</h3>
        <p style="font-size:.78rem;color:var(--text-muted);">Se vazio, usa o Number SIP padrão da conta.</p>
        <form id="formRamais">
            <table class="nv-tbl" style="max-width:520px;">
                <thead><tr><th>Usuário</th><th style="width:150px;">Ramal</th></tr></thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                    <tr>
                        <td><?= e($u['name']) ?></td>
                        <td><input type="text" data-user-id="<?= (int)$u['id'] ?>" value="<?= e($u['nvoip_ramal']) ?>" style="width:100%;padding:4px 8px;border:1px solid var(--border);border-radius:4px;font-size:.82rem;"></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div style="margin-top:.8rem;"><button type="button" onclick="nvoipSalvarRamais()" class="btn btn-primary btn-sm">💾 Salvar ramais</button> <span id="ramaisMsg" style="font-size:.8rem;"></span></div>
        </form>
    </div>

    <!-- HISTÓRICO -->
    <div class="nv-sec">
        <h3>📋 Histórico de ligações</h3>
        <form method="GET" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:flex-end;margin-bottom:.8rem;">
            <div><label style="font-size:.7rem;font-weight:700;color:var(--text-muted);">Usuário</label><br>
                <select name="user_id" style="padding:4px 8px;border:1px solid var(--border);border-radius:4px;">
                    <option value="">Todos</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?= (int)$u['id'] ?>" <?= $fUser === (int)$u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div><label style="font-size:.7rem;font-weight:700;color:var(--text-muted);">Status</label><br>
                <select name="status" style="padding:4px 8px;border:1px solid var(--border);border-radius:4px;">
                    <option value="">Todos</option>
                    <?php foreach (array('finished'=>'Atendida','noanswer'=>'Não atendeu','busy'=>'Ocupado','failed'=>'Falhou','calling'=>'Em andamento','established'=>'Em ligação') as $k=>$v): ?>
                        <option value="<?= $k ?>" <?= $fStatus === $k ? 'selected' : '' ?>><?= $v ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div><label style="font-size:.7rem;font-weight:700;color:var(--text-muted);">De</label><br><input type="date" name="dini" value="<?= e($fDini) ?>" style="padding:4px 8px;border:1px solid var(--border);border-radius:4px;"></div>
            <div><label style="font-size:.7rem;font-weight:700;color:var(--text-muted);">Até</label><br><input type="date" name="dfim" value="<?= e($fDfim) ?>" style="padding:4px 8px;border:1px solid var(--border);border-radius:4px;"></div>
            <button type="submit" class="btn btn-primary btn-sm">Filtrar</button>
            <a href="?<?= http_build_query(array_merge($_GET, array('csv'=>1))) ?>" class="btn btn-outline btn-sm">📥 CSV</a>
        </form>

        <div style="overflow-x:auto;">
        <table class="nv-tbl">
            <thead><tr>
                <th>Data</th><th>Atendente</th><th>Cliente</th><th>Telefone</th>
                <th>Duração</th><th>Status</th><th>Resumo IA</th><th>Áudio</th>
            </tr></thead>
            <tbody>
                <?php foreach ($ligacoes as $l):
                    $durS = (int)$l['duracao_segundos'];
                    $durTxt = sprintf('%d:%02d', floor($durS/60), $durS%60);
                ?>
                <tr>
                    <td style="white-space:nowrap;font-size:.75rem;"><?= date('d/m/Y H:i', strtotime($l['iniciada_em'])) ?></td>
                    <td><?= e($l['atendente_nome'] ?? '—') ?></td>
                    <td><?= e($l['cliente_nome'] ?? '—') ?></td>
                    <td style="font-family:ui-monospace,monospace;font-size:.75rem;"><?= e($l['telefone_destino']) ?></td>
                    <td style="font-variant-numeric:tabular-nums;"><?= $durS ? $durTxt : '—' ?></td>
                    <td><span class="nv-badge <?= e($l['status']) ?>"><?= e($l['status']) ?></span></td>
                    <td style="max-width:280px;font-size:.72rem;"><?= $l['resumo_ia'] ? e(mb_substr($l['resumo_ia'], 0, 140, 'UTF-8')) : '—' ?></td>
                    <td><?php if ($l['gravacao_local']): ?><audio controls src="<?= url('api/nvoip_api.php?action=audio&id=' . (int)$l['id']) ?>" style="height:28px;max-width:200px;"></audio><?php endif; ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$ligacoes): ?>
                <tr><td colspan="8" style="text-align:center;padding:1.5rem;color:var(--text-muted);">Nenhuma ligação encontrada.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<script>
var NVOIP_API = '<?= url('api/nvoip_api.php') ?>';
function csrf() { return (window._FSA_CSRF || window.FSA_CSRF || ''); }

function nvoipSaldo() {
    var out = document.getElementById('saldoOut');
    out.textContent = '⏳ consultando...';
    out.style.color = '#6b7280';
    fetch(NVOIP_API + '?action=saldo', { credentials:'same-origin' }).then(function(r){ return r.json(); }).then(function(d){
        if (d && d.error) { out.textContent = '❌ ' + d.error; out.style.color = '#b91c1c'; return; }
        var valor = (d && typeof d.balance !== 'undefined') ? d.balance : null;
        if (valor === null || isNaN(parseFloat(valor))) { out.textContent = '—'; return; }
        out.textContent = '💰 R$ ' + parseFloat(valor).toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        out.style.color = parseFloat(valor) < 10 ? '#b91c1c' : '#15803d';
        out.style.fontWeight = '700';
    }).catch(function(){
        out.textContent = '❌ erro de rede'; out.style.color = '#b91c1c';
    });
}

function nvoipSalvarCfg() {
    var f = document.getElementById('formCfg');
    var fd = new FormData(f);
    fd.append('action', 'salvar_config');
    fd.append('csrf_token', csrf());
    fetch(NVOIP_API, { method:'POST', body:fd, credentials:'same-origin' }).then(function(r){ return r.json(); }).then(function(d){
        var m = document.getElementById('cfgMsg');
        if (d.ok) { m.textContent = '✓ salvo'; m.style.color='#15803d'; setTimeout(function(){location.reload();},800); }
        else { m.textContent = '❌ ' + (d.error||''); m.style.color='#b91c1c'; }
    });
}

function nvoipTestar() {
    var m = document.getElementById('cfgMsg');
    m.textContent = 'testando...'; m.style.color='#6b7280';
    var fd = new FormData();
    fd.append('action', 'testar_conexao');
    fd.append('csrf_token', csrf());
    fetch(NVOIP_API, { method:'POST', body:fd, credentials:'same-origin' }).then(function(r){ return r.json(); }).then(function(d){
        if (d.ok) { m.textContent = d.mensagem || '✓ OK'; m.style.color='#15803d'; }
        else { m.textContent = '❌ ' + (d.error||''); m.style.color='#b91c1c'; }
    });
}

function nvoipSalvarRamais() {
    var ramais = [];
    document.querySelectorAll('[data-user-id]').forEach(function(inp){
        ramais.push({ user_id: inp.getAttribute('data-user-id'), ramal: inp.value });
    });
    var fd = new FormData();
    fd.append('action', 'salvar_ramais');
    fd.append('csrf_token', csrf());
    ramais.forEach(function(r, i){
        fd.append('ramais['+i+'][user_id]', r.user_id);
        fd.append('ramais['+i+'][ramal]',   r.ramal);
    });
    fetch(NVOIP_API, { method:'POST', body:fd, credentials:'same-origin' }).then(function(r){ return r.json(); }).then(function(d){
        var m = document.getElementById('ramaisMsg');
        if (d.ok) { m.textContent = '✓ ' + (d.atualizados||0) + ' salvos'; m.style.color='#15803d'; }
        else { m.textContent = '❌ ' + (d.error||''); m.style.color='#b91c1c'; }
    });
}
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
