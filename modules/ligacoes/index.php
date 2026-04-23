<?php
/**
 * modules/ligacoes/index.php — Central de Ligações (seção Comercial)
 *
 * - Passo a passo pra fazer chamada via Nvoip + webphone
 * - Credenciais do webphone (senha oculta com toggle, decriptada do banco)
 * - Histórico de ligações com filtros + vínculo com cliente/caso
 * - Links clicáveis pra abrir perfil do cliente ou pasta do processo
 */

require_once __DIR__ . '/../../core/middleware.php';
require_once __DIR__ . '/../../core/functions_nvoip.php';
require_login();

$pdo = db();
$userId = current_user_id();
$pageTitle = 'Ligações';

// Credenciais do webphone (decrypt do banco)
$webEmail = nvoip_cfg_get('nvoip_webphone_email');
$webPass  = '';
$webPassEnc = nvoip_cfg_get('nvoip_webphone_senha');
if ($webPassEnc) {
    try { $webPass = decrypt_value($webPassEnc); } catch (Exception $e) { $webPass = ''; }
}
$webUrl = nvoip_cfg_get('nvoip_webphone_url') ?: 'https://painel.nvoip.com.br/telephony/dids';

// Filtros
$fUser   = (int)($_GET['user_id'] ?? 0);
$fStatus = trim($_GET['status'] ?? '');
$fDini   = trim($_GET['dini'] ?? '');
$fDfim   = trim($_GET['dfim'] ?? '');
$fBusca  = trim($_GET['q'] ?? '');

$where = array('1=1'); $params = array();
if ($fUser)   { $where[] = 'l.atendente_id = ?'; $params[] = $fUser; }
if ($fStatus) { $where[] = 'l.status = ?';       $params[] = $fStatus; }
if ($fDini && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fDini)) { $where[] = 'l.iniciada_em >= ?'; $params[] = $fDini . ' 00:00:00'; }
if ($fDfim && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fDfim)) { $where[] = 'l.iniciada_em <= ?'; $params[] = $fDfim . ' 23:59:59'; }
if ($fBusca) {
    $like = '%' . $fBusca . '%';
    $where[] = '(c.name LIKE ? OR l.telefone_destino LIKE ? OR l.transcricao LIKE ? OR l.resumo_ia LIKE ? OR cs.title LIKE ? OR cs.case_number LIKE ?)';
    $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
}
$whereSql = implode(' AND ', $where);

$sql = "SELECT l.id, l.call_id, l.telefone_destino, l.duracao_segundos, l.status,
               l.iniciada_em, l.gravacao_local, l.resumo_ia, l.transcricao,
               l.client_id, l.case_id, l.lead_id,
               u.name AS atendente_nome, u.wa_color AS atendente_cor,
               c.name AS cliente_nome, c.phone AS cliente_phone,
               cs.title AS case_title, cs.case_number AS case_number
        FROM ligacoes_historico l
        LEFT JOIN users u ON u.id = l.atendente_id
        LEFT JOIN clients c ON c.id = l.client_id
        LEFT JOIN cases cs ON cs.id = l.case_id
        WHERE $whereSql
        ORDER BY l.iniciada_em DESC LIMIT 200";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$ligacoes = $stmt->fetchAll();

// Lista usuários pra filtro
$users = $pdo->query("SELECT id, name FROM users WHERE is_active = 1 ORDER BY name")->fetchAll();

// Stats rápidas (hoje, últimos 7 dias)
$statsHoje = $pdo->query("SELECT COUNT(*) tot, SUM(CASE WHEN status='finished' THEN 1 ELSE 0 END) ok,
    SUM(duracao_segundos) dur
    FROM ligacoes_historico WHERE DATE(iniciada_em) = CURDATE()")->fetch();
$stats7d = $pdo->query("SELECT COUNT(*) tot, SUM(CASE WHEN status='finished' THEN 1 ELSE 0 END) ok
    FROM ligacoes_historico WHERE iniciada_em >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetch();

require_once APP_ROOT . '/templates/layout_start.php';
?>
<style>
.lig-wrap { max-width:1300px; margin:0 auto; }
.lig-topo { display:grid; grid-template-columns:1fr 1fr; gap:1rem; margin-bottom:1rem; }
@media (max-width:900px){ .lig-topo { grid-template-columns:1fr; } }
.lig-card { background:var(--bg-card); border:1px solid var(--border); border-radius:12px; padding:1.1rem 1.3rem; }
.lig-card h3 { margin:0 0 .6rem; font-size:1rem; color:var(--petrol-900); }

.lig-passo { display:flex; gap:12px; align-items:flex-start; padding:8px 0; border-bottom:1px dashed var(--border); }
.lig-passo:last-child { border-bottom:none; }
.lig-passo-num { width:28px; height:28px; border-radius:50%; background:#B87333; color:#fff; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:.82rem; flex-shrink:0; }
.lig-passo-body { flex:1; font-size:.85rem; }
.lig-passo-body strong { color:var(--petrol-900); }

.lig-cred { background:#fef3c7; border:1px solid #fcd34d; border-radius:8px; padding:.7rem .9rem; margin-top:.5rem; font-size:.82rem; }
.lig-cred label { font-size:.68rem; color:#78350f; font-weight:700; text-transform:uppercase; letter-spacing:.3px; display:block; margin-bottom:2px; }
.lig-cred .val { font-family:ui-monospace,monospace; color:#451a03; padding:4px 8px; background:#fff; border-radius:4px; display:inline-flex; align-items:center; gap:8px; font-weight:600; }
.lig-cred button { background:#B87333; color:#fff; border:none; padding:3px 10px; border-radius:4px; cursor:pointer; font-size:.7rem; font-weight:700; }

.lig-stats { display:grid; grid-template-columns:repeat(4,1fr); gap:.6rem; margin-bottom:1rem; }
.lig-stat { background:var(--bg-card); border:1px solid var(--border); border-radius:10px; padding:.7rem .9rem; }
.lig-stat-n { font-size:1.6rem; font-weight:800; color:var(--petrol-900); line-height:1; }
.lig-stat-l { font-size:.7rem; color:var(--text-muted); text-transform:uppercase; font-weight:700; margin-top:4px; }

.lig-filtros { background:var(--bg-card); border:1px solid var(--border); border-radius:10px; padding:.7rem 1rem; margin-bottom:.8rem; }
.lig-filtros form { display:flex; gap:.5rem; flex-wrap:wrap; align-items:flex-end; }
.lig-filtros label { font-size:.68rem; font-weight:700; color:var(--text-muted); display:block; margin-bottom:2px; text-transform:uppercase; }
.lig-filtros input, .lig-filtros select { padding:5px 8px; border:1.5px solid var(--border); border-radius:6px; font-size:.82rem; }

.lig-tabela { width:100%; border-collapse:collapse; background:var(--bg-card); border-radius:10px; overflow:hidden; font-size:.82rem; }
.lig-tabela th { background:var(--petrol-900); color:#fff; padding:.5rem .7rem; text-align:left; font-size:.68rem; text-transform:uppercase; letter-spacing:.4px; }
.lig-tabela td { padding:.55rem .7rem; border-bottom:1px solid var(--border); vertical-align:top; }
.lig-tabela tr:hover { background:#fafafa; }
.lig-badge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:.66rem; font-weight:700; text-transform:uppercase; }
.lig-badge.finished { background:#dcfce7; color:#15803d; }
.lig-badge.noanswer, .lig-badge.busy { background:#fef3c7; color:#b45309; }
.lig-badge.failed { background:#fee2e2; color:#b91c1c; }
.lig-badge.calling, .lig-badge.established { background:#eff6ff; color:#1e40af; }

.lig-link { color:var(--petrol-900); text-decoration:none; font-weight:600; }
.lig-link:hover { text-decoration:underline; color:#B87333; }
</style>

<div class="lig-wrap">
    <h2 style="color:var(--petrol-900);margin-bottom:.2rem;">📞 Central de Ligações</h2>
    <p style="font-size:.85rem;color:var(--text-muted);margin-bottom:1rem;">Siga o passo a passo pra ligar pelo Hub com tudo registrado automaticamente (gravação + transcrição + resumo IA).</p>

    <!-- STATS -->
    <div class="lig-stats">
        <div class="lig-stat"><div class="lig-stat-n"><?= (int)$statsHoje['tot'] ?></div><div class="lig-stat-l">Ligações hoje</div></div>
        <div class="lig-stat"><div class="lig-stat-n" style="color:#15803d;"><?= (int)$statsHoje['ok'] ?></div><div class="lig-stat-l">Atendidas hoje</div></div>
        <div class="lig-stat"><div class="lig-stat-n"><?= (int)$stats7d['tot'] ?></div><div class="lig-stat-l">Últimos 7 dias</div></div>
        <div class="lig-stat"><div class="lig-stat-n" style="color:#B87333;"><?php $m = (int)$statsHoje['dur']; echo floor($m/60) . 'min'; ?></div><div class="lig-stat-l">Tempo total hoje</div></div>
    </div>

    <!-- TOPO: Passo a passo + Credenciais -->
    <div class="lig-topo">

        <div class="lig-card">
            <h3>📝 Passo a passo pra ligar</h3>

            <div class="lig-passo">
                <div class="lig-passo-num">1</div>
                <div class="lig-passo-body"><strong>Abre o WebPhone Nvoip</strong> em outra aba (fica minimizado) e faz login com as credenciais abaixo. Deixa a aba aberta enquanto trabalha.</div>
            </div>

            <div class="lig-passo">
                <div class="lig-passo-num">2</div>
                <div class="lig-passo-body"><strong>No painel Nvoip</strong>, clica no <span style="background:#ff7a00;color:#fff;padding:2px 6px;border-radius:4px;font-size:.7rem;">⋮⋮⋮</span> laranja no canto inferior direito. O WebPhone abre e pede permissão de microfone — <em>permite</em>. Fica registrado no seu ramal.</div>
            </div>

            <div class="lig-passo">
                <div class="lig-passo-num">3</div>
                <div class="lig-passo-body"><strong>Volta pro Hub</strong> e clica em <span style="background:#059669;color:#fff;padding:2px 6px;border-radius:4px;font-size:.72rem;font-weight:700;">📞 Ligar</span> no card/perfil do cliente ou na pasta do processo.</div>
            </div>

            <div class="lig-passo">
                <div class="lig-passo-num">4</div>
                <div class="lig-passo-body"><strong>Seu WebPhone vai tocar</strong> na aba da Nvoip — atende ali. A Nvoip disca pro cliente automaticamente e conecta vocês.</div>
            </div>

            <div class="lig-passo">
                <div class="lig-passo-num">5</div>
                <div class="lig-passo-body"><strong>Conversa normalmente</strong>. Ao desligar, a gravação é baixada, transcrita e resumida por IA em 3 linhas — tudo automático, vinculado ao cliente/processo.</div>
            </div>

            <div class="lig-passo">
                <div class="lig-passo-num">6</div>
                <div class="lig-passo-body"><strong>Consulta depois</strong> aqui nesta página ou na aba 📞 Ligações do drawer do cliente.</div>
            </div>

            <div style="margin-top:.8rem;padding:.6rem .8rem;background:#eff6ff;border:1px solid #bfdbfe;border-radius:6px;font-size:.78rem;color:#1e3a8a;">
                <strong>⚠️ Importante:</strong> se fechar a aba do WebPhone da Nvoip, a ligação vai falhar com <code>RECOVERY_ON_TIMER_EXPIRE</code>. Deixa sempre minimizada no canto.
            </div>
        </div>

        <div class="lig-card">
            <h3>🔑 Acesso ao WebPhone</h3>

            <a href="<?= e($webUrl) ?>" target="_blank" rel="noopener" class="btn btn-primary btn-sm" style="background:#ff7a00;margin-bottom:.6rem;">
                🌐 Abrir painel Nvoip (nova aba)
            </a>

            <?php if ($webEmail || $webPass): ?>
            <div class="lig-cred">
                <label>E-mail</label>
                <div class="val"><?= e($webEmail) ?><button onclick="copiar('<?= e(addslashes($webEmail)) ?>', this)">📋 Copiar</button></div>
            </div>

            <div class="lig-cred">
                <label>Senha</label>
                <div class="val">
                    <span id="sPass" style="font-family:ui-monospace,monospace;">••••••••••</span>
                    <button type="button" onclick="togglePass()">👁️ Ver</button>
                    <button onclick="copiar('<?= e(addslashes($webPass)) ?>', this)">📋 Copiar</button>
                </div>
            </div>

            <div class="lig-cred">
                <label>URL</label>
                <div class="val" style="font-size:.78rem;word-break:break-all;"><?= e($webUrl) ?><button onclick="copiar('<?= e(addslashes($webUrl)) ?>', this)">📋</button></div>
            </div>

            <?php if (has_min_role('admin')): ?>
            <div style="margin-top:.6rem;font-size:.72rem;color:var(--text-muted);">
                <a href="#" onclick="editarCred();return false;" style="color:#B87333;font-weight:600;">✏️ Editar credenciais</a>
            </div>
            <?php endif; ?>

            <?php else: ?>
            <div style="font-size:.8rem;color:var(--text-muted);padding:.6rem;">
                <?php if (has_min_role('admin')): ?>
                    Credenciais ainda não cadastradas. <a href="#" onclick="editarCred();return false;" style="color:#B87333;font-weight:700;">Cadastrar agora</a>.
                <?php else: ?>
                    Credenciais ainda não cadastradas. Peça pro admin configurar.
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- FILTROS -->
    <div class="lig-filtros">
        <form method="GET">
            <div><label>Buscar</label><br><input type="text" name="q" value="<?= e($fBusca) ?>" placeholder="Nome, telefone, processo..." style="min-width:220px;"></div>
            <div><label>Usuário</label><br>
                <select name="user_id">
                    <option value="">Todos</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?= (int)$u['id'] ?>" <?= $fUser === (int)$u['id'] ? 'selected' : '' ?>><?= e(explode(' ', $u['name'])[0]) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div><label>Status</label><br>
                <select name="status">
                    <option value="">Todos</option>
                    <?php foreach (array('finished'=>'✓ Atendida','noanswer'=>'📞 Não atendeu','busy'=>'📵 Ocupado','failed'=>'❌ Falhou','calling'=>'Em andamento') as $k=>$v): ?>
                        <option value="<?= $k ?>" <?= $fStatus === $k ? 'selected' : '' ?>><?= $v ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div><label>De</label><br><input type="date" name="dini" value="<?= e($fDini) ?>"></div>
            <div><label>Até</label><br><input type="date" name="dfim" value="<?= e($fDfim) ?>"></div>
            <button type="submit" class="btn btn-primary btn-sm">🔍 Filtrar</button>
            <a href="<?= module_url('ligacoes') ?>" class="btn btn-outline btn-sm">Limpar</a>
        </form>
    </div>

    <!-- TABELA -->
    <div style="overflow-x:auto;">
    <table class="lig-tabela">
        <thead><tr>
            <th>Data</th>
            <th>Atendente</th>
            <th>Cliente</th>
            <th>Telefone</th>
            <th>Processo</th>
            <th>Dur.</th>
            <th>Status</th>
            <th>Resumo IA</th>
            <th>🎧</th>
        </tr></thead>
        <tbody>
            <?php foreach ($ligacoes as $l):
                $durS = (int)$l['duracao_segundos'];
                $durTxt = sprintf('%d:%02d', floor($durS/60), $durS%60);
            ?>
            <tr>
                <td style="white-space:nowrap;font-size:.72rem;"><?= date('d/m', strtotime($l['iniciada_em'])) ?><br><span style="color:var(--text-muted);"><?= date('H:i', strtotime($l['iniciada_em'])) ?></span></td>
                <td>
                    <?php if ($l['atendente_cor']): ?>
                        <span style="color:<?= e($l['atendente_cor']) ?>;font-weight:700;"><?= e(explode(' ', (string)$l['atendente_nome'])[0]) ?></span>
                    <?php else: ?>
                        <?= e(explode(' ', (string)$l['atendente_nome'])[0]) ?: '—' ?>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($l['client_id']): ?>
                        <a href="<?= module_url('clientes', 'ver.php?id=' . (int)$l['client_id']) ?>" class="lig-link">
                            👤 <?= e($l['cliente_nome'] ?? 'Cliente #' . $l['client_id']) ?>
                        </a>
                    <?php else: ?>
                        <span style="color:var(--text-muted);font-size:.75rem;">Sem vínculo</span>
                    <?php endif; ?>
                </td>
                <td style="font-family:ui-monospace,monospace;font-size:.75rem;"><?= e($l['telefone_destino']) ?></td>
                <td>
                    <?php if ($l['case_id']): ?>
                        <a href="<?= module_url('operacional', 'caso_ver.php?id=' . (int)$l['case_id']) ?>" class="lig-link" style="font-size:.75rem;">
                            📁 <?= e($l['case_title'] ?? 'Processo') ?>
                        </a>
                        <?php if ($l['case_number']): ?><br><span style="font-size:.68rem;color:var(--text-muted);font-family:ui-monospace,monospace;"><?= e($l['case_number']) ?></span><?php endif; ?>
                    <?php else: ?>
                        <span style="color:var(--text-muted);font-size:.72rem;">—</span>
                    <?php endif; ?>
                </td>
                <td style="font-variant-numeric:tabular-nums;"><?= $durS ? $durTxt : '—' ?></td>
                <td><span class="lig-badge <?= e($l['status']) ?>"><?= e($l['status']) ?></span></td>
                <td style="max-width:260px;font-size:.72rem;">
                    <?php if ($l['resumo_ia']): ?>
                        <?= e(mb_substr($l['resumo_ia'], 0, 160, 'UTF-8')) ?><?= mb_strlen($l['resumo_ia'], 'UTF-8') > 160 ? '…' : '' ?>
                    <?php elseif ($l['transcricao']): ?>
                        <span style="color:var(--text-muted);font-style:italic;"><?= e(mb_substr($l['transcricao'], 0, 100, 'UTF-8')) ?>…</span>
                    <?php else: ?>
                        <span style="color:var(--text-muted);">—</span>
                    <?php endif; ?>
                </td>
                <td><?php if ($l['gravacao_local']): ?><audio controls src="<?= url('api/nvoip_api.php?action=audio&id=' . (int)$l['id']) ?>" style="height:28px;max-width:180px;"></audio><?php endif; ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$ligacoes): ?>
            <tr><td colspan="9" style="text-align:center;padding:2rem;color:var(--text-muted);">Nenhuma ligação encontrada.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<?php if (has_min_role('admin')): ?>
<!-- Modal edição credenciais (só admin) -->
<div id="modalCred" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:12px;padding:1.5rem;max-width:440px;width:92%;">
        <h3 style="margin:0 0 .5rem;color:var(--petrol-900);">🔑 Editar credenciais WebPhone</h3>
        <p style="font-size:.78rem;color:var(--text-muted);">Senha é salva encriptada no banco (AES-256).</p>
        <form id="formCred" style="margin-top:.5rem;">
            <div style="margin-bottom:.5rem;"><label style="font-size:.75rem;font-weight:700;">E-mail</label><br><input type="email" name="email" value="<?= e($webEmail) ?>" style="width:100%;padding:6px 10px;border:1.5px solid var(--border);border-radius:6px;"></div>
            <div style="margin-bottom:.5rem;"><label style="font-size:.75rem;font-weight:700;">Senha</label><br><input type="text" name="senha" value="<?= e($webPass) ?>" placeholder="Digite a senha" style="width:100%;padding:6px 10px;border:1.5px solid var(--border);border-radius:6px;font-family:ui-monospace,monospace;"></div>
            <div style="margin-bottom:.8rem;"><label style="font-size:.75rem;font-weight:700;">URL</label><br><input type="text" name="url" value="<?= e($webUrl) ?>" style="width:100%;padding:6px 10px;border:1.5px solid var(--border);border-radius:6px;"></div>
            <div style="display:flex;gap:.4rem;">
                <button type="button" onclick="salvarCred()" class="btn btn-primary btn-sm" style="background:#B87333;">💾 Salvar</button>
                <button type="button" onclick="document.getElementById('modalCred').style.display='none'" class="btn btn-outline btn-sm">Cancelar</button>
                <span id="credMsg" style="font-size:.75rem;margin-left:.5rem;"></span>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
function copiar(txt, btn) {
    navigator.clipboard.writeText(txt).then(function(){
        var o = btn.textContent; btn.textContent = '✓ Copiado'; btn.style.background='#059669';
        setTimeout(function(){ btn.textContent = o; btn.style.background=''; }, 1500);
    });
}
function togglePass() {
    var el = document.getElementById('sPass');
    if (el.textContent.indexOf('•') !== -1) el.textContent = <?= json_encode($webPass) ?>;
    else el.textContent = '••••••••••';
}
<?php if (has_min_role('admin')): ?>
function editarCred() {
    var m = document.getElementById('modalCred'); m.style.display = 'flex';
}
function salvarCred() {
    var f = document.getElementById('formCred');
    var fd = new FormData(f);
    fd.append('action', 'salvar_webphone_cred');
    fd.append('csrf_token', <?= json_encode(generate_csrf_token()) ?>);
    fetch('<?= url('api/nvoip_api.php') ?>', { method:'POST', body:fd, credentials:'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(d){
            var m = document.getElementById('credMsg');
            if (d.ok) { m.textContent = '✓ salvo'; m.style.color='#15803d'; setTimeout(function(){location.reload();},700); }
            else { m.textContent = '❌ ' + (d.error||''); m.style.color='#b91c1c'; }
        });
}
<?php endif; ?>
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
