<?php
/**
 * Ferreira & Sa Hub -- Central VIP -- Dashboard / Inbox
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();

$pageTitle = 'Central VIP — Gestao';
$pdo = db();

// ── KPIs ────────────────────────────────────────────────
$totalAcessos = (int)$pdo->query("SELECT COUNT(*) FROM salavip_usuarios WHERE ativo = 1")->fetchColumn();

$msgNaoLidas = (int)$pdo->query(
    "SELECT COUNT(*) FROM salavip_mensagens WHERE origem = 'salavip' AND lida_equipe = 0"
)->fetchColumn();

$docsPendentes = (int)$pdo->query(
    "SELECT COUNT(*) FROM salavip_documentos_cliente WHERE status = 'pendente'"
)->fetchColumn();

$acessosHoje = (int)$pdo->query(
    "SELECT COUNT(*) FROM salavip_log_acesso WHERE DATE(criado_em) = CURDATE()"
)->fetchColumn();

// ── Mensagens nao lidas (inbox) ─────────────────────────
$inbox = $pdo->query(
    "SELECT m.id as msg_id, m.mensagem, m.criado_em as msg_data,
            t.id as thread_id, t.assunto, t.categoria,
            c.name as client_name
     FROM salavip_mensagens m
     JOIN salavip_threads t ON t.id = m.thread_id
     JOIN clients c ON c.id = m.cliente_id
     WHERE m.origem = 'salavip' AND m.lida_equipe = 0
     ORDER BY m.criado_em DESC
     LIMIT 50"
)->fetchAll();

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.sv-stats { display:flex; gap:.75rem; margin-bottom:1.25rem; flex-wrap:wrap; }
.sv-stat { background:var(--bg-card); border-radius:var(--radius-lg); border:1px solid var(--border); padding:.75rem 1.25rem; display:flex; align-items:center; gap:.75rem; min-width:150px; flex:1; }
.sv-stat-icon { font-size:1.3rem; }
.sv-stat-val { font-size:1.4rem; font-weight:800; color:var(--petrol-900); }
.sv-stat-lbl { font-size:.68rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:.3px; }
.sv-links { display:flex; gap:.5rem; flex-wrap:wrap; margin-bottom:1.25rem; }
.sv-inbox-item { display:flex; justify-content:space-between; align-items:flex-start; padding:.75rem 1rem; border-bottom:1px solid var(--border); transition:background .15s; }
.sv-inbox-item:hover { background:rgba(215,171,144,.06); }
.sv-inbox-item:last-child { border-bottom:none; }
.sv-inbox-name { font-weight:700; font-size:.82rem; color:var(--petrol-900); }
.sv-inbox-assunto { font-size:.8rem; color:var(--rose); font-weight:600; }
.sv-inbox-preview { font-size:.78rem; color:var(--text-muted); margin-top:.15rem; }
.sv-inbox-date { font-size:.7rem; color:var(--text-muted); white-space:nowrap; }
</style>

<!-- KPIs -->
<div class="sv-stats">
    <div class="sv-stat">
        <span class="sv-stat-icon">&#128101;</span>
        <div><div class="sv-stat-val"><?= $totalAcessos ?></div><div class="sv-stat-lbl">Clientes com acesso</div></div>
    </div>
    <div class="sv-stat">
        <span class="sv-stat-icon">&#9993;</span>
        <div><div class="sv-stat-val"><?= $msgNaoLidas ?></div><div class="sv-stat-lbl">Msgs nao lidas</div></div>
    </div>
    <div class="sv-stat">
        <span class="sv-stat-icon">&#128196;</span>
        <div><div class="sv-stat-val"><?= $docsPendentes ?></div><div class="sv-stat-lbl">Docs pendentes</div></div>
    </div>
    <div class="sv-stat">
        <span class="sv-stat-icon">&#128065;</span>
        <div><div class="sv-stat-val"><?= $acessosHoje ?></div><div class="sv-stat-lbl">Acessos hoje</div></div>
    </div>
</div>

<!-- Quick Links -->
<div class="sv-links">
    <a href="<?= module_url('salavip', 'acessos.php') ?>" class="btn btn-outline btn-sm">&#128101; Gerenciar Acessos</a>
    <a href="<?= module_url('salavip', 'ged.php') ?>" class="btn btn-outline btn-sm">&#128193; GED</a>
    <a href="<?= module_url('salavip', 'faq_admin.php') ?>" class="btn btn-outline btn-sm">&#10067; FAQ</a>
    <a href="<?= module_url('salavip', 'palavras_bloqueio.php') ?>" class="btn btn-outline btn-sm">&#128683; Palavras Bloqueio</a>
    <a href="<?= module_url('salavip', 'log.php') ?>" class="btn btn-outline btn-sm">&#128220; Log de Acessos</a>
</div>

<!-- Inbox: Mensagens Nao Lidas -->
<div class="card">
    <div class="card-header">
        <h3>Mensagens Nao Lidas</h3>
        <span class="badge badge-danger"><?= $msgNaoLidas ?></span>
    </div>
    <div class="card-body" style="padding:0;">
        <?php if (empty($inbox)): ?>
            <div style="text-align:center;padding:2rem;">
                <p class="text-muted text-sm">Nenhuma mensagem pendente.</p>
            </div>
        <?php else: ?>
            <?php foreach ($inbox as $msg): ?>
                <a href="<?= module_url('salavip', 'ver_mensagem.php?thread_id=' . $msg['thread_id']) ?>" class="sv-inbox-item" style="text-decoration:none;">
                    <div style="flex:1;min-width:0;">
                        <div class="sv-inbox-name"><?= e($msg['client_name']) ?></div>
                        <div class="sv-inbox-assunto"><?= e($msg['assunto']) ?></div>
                        <div class="sv-inbox-preview"><?= e(mb_strimwidth($msg['mensagem'], 0, 120, '...')) ?></div>
                    </div>
                    <div class="sv-inbox-date"><?= date('d/m H:i', strtotime($msg['msg_data'])) ?></div>
                </a>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Documentos Enviados pelos Clientes -->
<?php
$docsClientes = $pdo->query(
    "SELECT dc.*, c.name as client_name, cs.title as processo_titulo
     FROM salavip_documentos_cliente dc
     LEFT JOIN clients c ON c.id = dc.cliente_id
     LEFT JOIN cases cs ON cs.id = dc.processo_id
     WHERE dc.status = 'pendente'
     ORDER BY dc.criado_em DESC LIMIT 20"
)->fetchAll();
?>
<div class="card" style="margin-top:1rem;">
    <div class="card-header">
        <h3>Documentos Enviados pelos Clientes</h3>
        <span class="badge badge-danger"><?= count($docsClientes) ?></span>
    </div>
    <div class="card-body" style="padding:0;">
        <?php if (empty($docsClientes)): ?>
            <div style="text-align:center;padding:2rem;">
                <p class="text-muted text-sm">Nenhum documento pendente.</p>
            </div>
        <?php else: ?>
            <table style="width:100%;border-collapse:collapse;font-size:.82rem;">
                <thead><tr style="background:var(--petrol-900);color:#fff;">
                    <th style="padding:.5rem .75rem;text-align:left;">Cliente</th>
                    <th style="padding:.5rem .75rem;text-align:left;">Título / Arquivo</th>
                    <th style="padding:.5rem .75rem;text-align:left;">Processo</th>
                    <th style="padding:.5rem .75rem;text-align:left;">Data</th>
                    <th style="padding:.5rem .75rem;text-align:center;">Ações</th>
                </tr></thead>
                <tbody>
                <?php foreach ($docsClientes as $dc): ?>
                <tr style="border-bottom:1px solid var(--border);">
                    <td style="padding:.5rem .75rem;font-weight:600;"><?= e($dc['client_name'] ?: '?') ?></td>
                    <td style="padding:.5rem .75rem;">
                        <a href="<?= module_url('salavip', 'download_cliente.php?id=' . $dc['id']) ?>" target="_blank" style="color:var(--rose);font-weight:600;text-decoration:none;">📎 <?= e($dc['titulo']) ?></a>
                        <?php if (!empty($dc['arquivo_nome'])): ?><br><span style="font-size:.72rem;color:var(--text-muted);"><?= e($dc['arquivo_nome']) ?></span><?php endif; ?>
                    </td>
                    <td style="padding:.5rem .75rem;color:var(--text-muted);"><?= e($dc['processo_titulo'] ?: '-') ?></td>
                    <td style="padding:.5rem .75rem;"><?= date('d/m/Y H:i', strtotime($dc['criado_em'])) ?></td>
                    <td style="padding:.5rem .75rem;text-align:center;">
                        <div style="display:flex;gap:.3rem;justify-content:center;">
                            <a href="<?= module_url('salavip', 'download_cliente.php?id=' . $dc['id']) ?>" target="_blank" class="btn btn-outline btn-sm" style="font-size:.7rem;padding:3px 8px;" title="Visualizar">👁</a>
                            <?php if (has_min_role('gestao')): ?>
                            <form method="POST" action="<?= module_url('salavip', 'doc_action.php') ?>" style="display:inline;" onsubmit="return confirm('Aceitar este documento?');">
                                <?= csrf_input() ?>
                                <input type="hidden" name="doc_id" value="<?= $dc['id'] ?>">
                                <input type="hidden" name="acao" value="aceitar">
                                <button type="submit" class="btn btn-sm" style="background:#22c55e;color:#fff;font-size:.7rem;padding:3px 8px;border:none;border-radius:6px;cursor:pointer;" title="Aceitar">✅</button>
                            </form>
                            <form method="POST" action="<?= module_url('salavip', 'doc_action.php') ?>" style="display:inline;" onsubmit="return confirm('Rejeitar este documento?');">
                                <?= csrf_input() ?>
                                <input type="hidden" name="doc_id" value="<?= $dc['id'] ?>">
                                <input type="hidden" name="acao" value="rejeitar">
                                <button type="submit" class="btn btn-sm" style="background:#ef4444;color:#fff;font-size:.7rem;padding:3px 8px;border:none;border-radius:6px;cursor:pointer;" title="Rejeitar">❌</button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
