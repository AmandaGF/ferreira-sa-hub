<?php
/**
 * Newsletter — Listagem de Campanhas + Configuração Brevo
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
require_access('formularios');

$pageTitle = 'Newsletter';
$pdo = db();

// KPIs
$total = (int)$pdo->query("SELECT COUNT(*) FROM newsletter_campanhas")->fetchColumn();
$enviadas = (int)$pdo->query("SELECT COUNT(*) FROM newsletter_campanhas WHERE status IN ('enviado','enviando')")->fetchColumn();
$descadastros = (int)$pdo->query("SELECT COUNT(*) FROM newsletter_descadastros")->fetchColumn();
$contatosEmail = (int)$pdo->query("SELECT COUNT(*) FROM clients WHERE email IS NOT NULL AND email != ''")->fetchColumn();

// Campanhas
$campanhas = $pdo->query("SELECT c.*, u.name as criado_por FROM newsletter_campanhas c LEFT JOIN users u ON u.id=c.created_by ORDER BY c.created_at DESC LIMIT 30")->fetchAll();

// Config Brevo
$brevoKey = '';
$brevoEmail = 'contato@ferreiraesa.com.br';
$brevoName = 'Ferreira & Sá Advocacia';
try {
    $rows = $pdo->query("SELECT chave, valor FROM configuracoes WHERE chave LIKE 'brevo_%'")->fetchAll();
    foreach ($rows as $r) {
        if ($r['chave'] === 'brevo_api_key') $brevoKey = $r['valor'];
        if ($r['chave'] === 'brevo_sender_email') $brevoEmail = $r['valor'];
        if ($r['chave'] === 'brevo_sender_name') $brevoName = $r['valor'];
    }
} catch (Exception $e) {}

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.nl-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:.75rem;margin-bottom:1.2rem}
.nl-kpi{background:var(--bg-card,#fff);border:1.5px solid var(--border);border-radius:12px;padding:.8rem 1rem;text-align:center}
.nl-kpi-n{font-size:1.5rem;font-weight:800;color:var(--petrol-900)}
.nl-kpi-l{font-size:.7rem;color:var(--text-muted);text-transform:uppercase}
.nl-topo{display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;flex-wrap:wrap;gap:.5rem}
.nl-status{display:inline-block;padding:2px 8px;border-radius:4px;font-size:.68rem;font-weight:700;color:#fff}
.nl-config{background:var(--bg-card);border:1.5px solid var(--border);border-radius:12px;padding:1rem;margin-bottom:1.2rem}
</style>

<!-- KPIs -->
<div class="nl-kpis">
    <div class="nl-kpi"><div class="nl-kpi-n"><?= $contatosEmail ?></div><div class="nl-kpi-l">Contatos com e-mail</div></div>
    <div class="nl-kpi"><div class="nl-kpi-n"><?= $total ?></div><div class="nl-kpi-l">Campanhas</div></div>
    <div class="nl-kpi"><div class="nl-kpi-n"><?= $enviadas ?></div><div class="nl-kpi-l">Enviadas</div></div>
    <div class="nl-kpi"><div class="nl-kpi-n"><?= $descadastros ?></div><div class="nl-kpi-l">Descadastros</div></div>
</div>

<!-- Config Brevo -->
<?php if (has_role('admin')): ?>
<div class="nl-config">
    <h4 style="margin:0 0 .5rem;font-size:.85rem;">Configuracao Brevo (e-mail)</h4>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:end;">
        <div style="flex:1;min-width:200px;"><label style="font-size:.7rem;color:var(--text-muted);">API Key</label><input type="password" id="cfgKey" value="<?= e($brevoKey) ?>" class="form-input" style="font-size:.8rem;" placeholder="xkeysib-..."></div>
        <div style="min-width:180px;"><label style="font-size:.7rem;color:var(--text-muted);">E-mail remetente</label><input type="text" id="cfgEmail" value="<?= e($brevoEmail) ?>" class="form-input" style="font-size:.8rem;"></div>
        <div style="min-width:150px;"><label style="font-size:.7rem;color:var(--text-muted);">Nome remetente</label><input type="text" id="cfgName" value="<?= e($brevoName) ?>" class="form-input" style="font-size:.8rem;"></div>
        <button onclick="salvarConfig()" class="btn btn-primary btn-sm">Salvar</button>
        <button onclick="testarBrevo()" class="btn btn-outline btn-sm">Testar</button>
    </div>
    <div id="cfgMsg" style="display:none;margin-top:.5rem;font-size:.78rem;padding:4px 8px;border-radius:4px;"></div>
</div>
<?php endif; ?>

<!-- Topo -->
<div class="nl-topo">
    <h3 style="margin:0;">Campanhas</h3>
    <a href="<?= module_url('newsletter', 'campanha.php') ?>" class="btn btn-primary btn-sm">+ Nova Campanha</a>
</div>

<!-- Tabela -->
<div class="card" style="overflow-x:auto;">
<?php if (empty($campanhas)): ?>
    <div style="text-align:center;padding:2rem;color:var(--text-muted);">Nenhuma campanha criada ainda.</div>
<?php else: ?>
    <table>
        <thead><tr>
            <th>Titulo</th><th>Assunto</th><th>Tipo</th><th>Status</th><th>Destinatarios</th><th>Aberturas</th><th>Data</th><th>Acoes</th>
        </tr></thead>
        <tbody>
        <?php
        $statusCores = array('rascunho'=>'#94a3b8','agendado'=>'#d97706','enviando'=>'#0284c7','enviado'=>'#059669','cancelado'=>'#dc2626');
        foreach ($campanhas as $c):
            $cor = isset($statusCores[$c['status']]) ? $statusCores[$c['status']] : '#888';
            $taxaAbert = $c['total_enviados'] > 0 ? round(($c['total_abertos'] / $c['total_enviados']) * 100) : 0;
        ?>
        <tr>
            <td style="font-weight:600;"><?= e($c['titulo']) ?></td>
            <td style="font-size:.78rem;"><?= e($c['assunto']) ?></td>
            <td style="font-size:.75rem;"><?= e($c['template_tipo']) ?></td>
            <td><span class="nl-status" style="background:<?= $cor ?>"><?= e($c['status']) ?></span></td>
            <td style="text-align:center;"><?= $c['total_destinatarios'] ?></td>
            <td style="text-align:center;"><?= $c['total_abertos'] ?> (<?= $taxaAbert ?>%)</td>
            <td style="font-size:.75rem;"><?= date('d/m/Y H:i', strtotime($c['created_at'])) ?></td>
            <td>
                <?php if ($c['status'] === 'rascunho'): ?>
                <a href="<?= module_url('newsletter', 'campanha.php?id=' . $c['id']) ?>" class="btn btn-outline btn-sm" style="font-size:.72rem;">Editar</a>
                <?php else: ?>
                <span style="font-size:.72rem;color:var(--text-muted);">—</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
</div>

<script>
var API = '<?= module_url("newsletter", "api.php") ?>';
var CSRF = '<?= generate_csrf_token() ?>';

function salvarConfig() {
    var fd = new FormData();
    fd.append('action', 'salvar_config');
    fd.append('csrf_token', CSRF);
    fd.append('brevo_api_key', document.getElementById('cfgKey').value);
    fd.append('brevo_sender_email', document.getElementById('cfgEmail').value);
    fd.append('brevo_sender_name', document.getElementById('cfgName').value);
    var x = new XMLHttpRequest(); x.open('POST', API);
    x.onload = function() {
        try { var r = JSON.parse(x.responseText); if (r.csrf) CSRF = r.csrf;
            var m = document.getElementById('cfgMsg');
            m.textContent = r.ok ? 'Configuracao salva!' : (r.error || 'Erro');
            m.style.background = r.ok ? '#ecfdf5' : '#fef2f2';
            m.style.color = r.ok ? '#059669' : '#dc2626';
            m.style.display = 'block';
            setTimeout(function(){m.style.display='none'},3000);
        } catch(e) {}
    }; x.send(fd);
}

function testarBrevo() {
    var x = new XMLHttpRequest(); x.open('GET', API + '?action=testar_brevo');
    x.onload = function() {
        try { var r = JSON.parse(x.responseText);
            var m = document.getElementById('cfgMsg');
            if (r.error) { m.textContent = 'Erro: ' + r.error; m.style.background='#fef2f2'; m.style.color='#dc2626'; }
            else { m.textContent = 'Conexao OK! Conta: ' + (r.email || '?'); m.style.background='#ecfdf5'; m.style.color='#059669'; }
            m.style.display = 'block';
        } catch(e) { alert('Erro ao testar'); }
    }; x.send();
}
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
