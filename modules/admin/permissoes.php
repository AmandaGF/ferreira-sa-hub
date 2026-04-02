<?php
/**
 * Ferreira & Sá Hub — Gerenciamento de Permissões por Usuário
 * Acesso: SOMENTE admin
 */
require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/database.php';
require_once __DIR__ . '/../../core/auth.php';
require_once __DIR__ . '/../../core/middleware.php';

require_login();
require_role('admin');

$pageTitle = 'Permissoes';
$pdo = db();

// Buscar todos os usuários ativos
$users = $pdo->query("SELECT id, name, email, role, setor FROM users WHERE is_active = 1 ORDER BY FIELD(role,'admin','gestao','comercial','cx','operacional','estagiario','colaborador'), name")->fetchAll();

// Módulos e labels
$moduleLabels = module_permission_labels();
$defaults = _permission_defaults();

require_once APP_ROOT . '/templates/layout_start.php';
?>
    <h1 style="margin-bottom:1.5rem;">Permissoes por Usuario</h1>

    <table>
        <thead>
            <tr>
                <th>Usuario</th>
                <th>Perfil</th>
                <th>E-mail</th>
                <th style="width:180px;">Acoes</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $u): ?>
            <tr>
                <td><strong><?= e($u['name']) ?></strong></td>
                <td><?= role_badge($u['role']) ?></td>
                <td style="font-size:.85rem;color:#6b7280;"><?= e($u['email']) ?></td>
                <td>
                    <?php if ($u['role'] === 'admin'): ?>
                        <span style="font-size:.8rem;color:#94a3b8;">Acesso total</span>
                    <?php else: ?>
                        <button class="btn btn-outline" style="font-size:.8rem;padding:4px 12px;" onclick="openPerms(<?= $u['id'] ?>, '<?= e($u['name']) ?>', '<?= e($u['role']) ?>')">
                            Gerenciar Permissoes
                        </button>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

<!-- Modal de Permissões -->
<div class="modal-overlay" id="permModal" style="display:none;">
    <div style="background:#fff;border-radius:12px;max-width:700px;width:95%;max-height:90vh;overflow-y:auto;margin:auto;position:relative;top:50%;transform:translateY(-50%);box-shadow:0 20px 60px rgba(0,0,0,.3);">
        <div style="background:linear-gradient(135deg,#052228,#0d3640);color:#fff;padding:1.2rem 1.5rem;border-radius:12px 12px 0 0;">
            <div style="display:flex;justify-content:space-between;align-items:center;">
                <div>
                    <h2 style="margin:0;font-size:1.1rem;" id="pmTitle">Permissoes</h2>
                    <div style="font-size:.8rem;opacity:.7;margin-top:.2rem;" id="pmRole"></div>
                </div>
                <button onclick="closePerms()" style="background:none;border:none;color:#fff;font-size:1.4rem;cursor:pointer;">X</button>
            </div>
        </div>

        <div style="padding:1.2rem 1.5rem;">
            <p style="font-size:.8rem;color:#6b7280;margin-bottom:1rem;">
                Verde = permitido pelo perfil | Vermelho = bloqueado pelo perfil<br>
                Use os toggles para criar overrides individuais.
            </p>

            <div id="pmGrid"></div>

            <div style="display:flex;gap:.5rem;margin-top:1.5rem;justify-content:space-between;">
                <button onclick="resetPerms()" class="btn btn-outline" style="font-size:.8rem;color:#dc2626;border-color:#dc2626;">
                    Resetar para padrao do perfil
                </button>
                <div style="display:flex;gap:.5rem;">
                    <button onclick="closePerms()" class="btn btn-outline" style="font-size:.8rem;">Cancelar</button>
                    <button onclick="savePerms()" class="btn btn-primary" style="font-size:.8rem;">Salvar</button>
                </div>
            </div>

            <div id="pmMsg" style="display:none;margin-top:.8rem;padding:.5rem;border-radius:6px;font-size:.8rem;text-align:center;"></div>
        </div>
    </div>
</div>

<script>
var pmUserId = 0;
var pmUserRole = '';
var pmDefaults = <?= json_encode($defaults) ?>;
var pmLabels = <?= json_encode($moduleLabels) ?>;
var pmOverrides = {};
var pmApiUrl = '<?= url('modules/admin/permissoes_api.php') ?>';

function openPerms(userId, userName, userRole) {
    pmUserId = userId;
    pmUserRole = userRole;
    document.getElementById('pmTitle').textContent = 'Permissoes — ' + userName;
    document.getElementById('pmRole').textContent = 'Perfil: ' + userRole.charAt(0).toUpperCase() + userRole.slice(1);
    document.getElementById('pmMsg').style.display = 'none';

    // Buscar overrides atuais do usuário
    var x = new XMLHttpRequest();
    x.open('GET', pmApiUrl + '?action=get&user_id=' + userId);
    x.onload = function() {
        try {
            var r = JSON.parse(x.responseText);
            pmOverrides = r.overrides || {};
        } catch(e) { pmOverrides = {}; }
        renderGrid();
        document.getElementById('permModal').style.display = 'flex';
    };
    x.send();
}

function closePerms() {
    document.getElementById('permModal').style.display = 'none';
}

function renderGrid() {
    var html = '<table style="width:100%;font-size:.82rem;"><thead><tr>'
        + '<th style="text-align:left;">Modulo</th>'
        + '<th style="width:80px;text-align:center;">Padrao</th>'
        + '<th style="width:120px;text-align:center;">Override</th>'
        + '</tr></thead><tbody>';

    for (var mod in pmLabels) {
        var defaultAllowed = pmDefaults[mod] && pmDefaults[mod].indexOf(pmUserRole) !== -1;
        var hasOverride = pmOverrides.hasOwnProperty(mod);
        var overrideVal = hasOverride ? parseInt(pmOverrides[mod]) : null;

        // Cor do padrão
        var defaultIcon = defaultAllowed
            ? '<span style="color:#059669;font-weight:700;">&#9989; Permitido</span>'
            : '<span style="color:#dc2626;font-weight:700;">&#10060; Bloqueado</span>';

        // Select de override
        var sel = '<select id="ov_' + mod + '" onchange="markChanged(\'' + mod + '\')" '
            + 'style="font-size:.78rem;padding:3px 6px;border:1.5px solid #e5e7eb;border-radius:5px;width:110px;">';
        sel += '<option value="default"' + (overrideVal === null ? ' selected' : '') + '>Padrao</option>';

        if (defaultAllowed) {
            // Já é permitido → override só pode bloquear
            sel += '<option value="0"' + (overrideVal === 0 ? ' selected' : '') + '>&#128274; Bloquear</option>';
        } else {
            // Já é bloqueado → override só pode liberar
            sel += '<option value="1"' + (overrideVal === 1 ? ' selected' : '') + '>&#128275; Liberar</option>';
        }
        sel += '</select>';

        var rowBg = hasOverride ? 'background:#fffbeb;' : '';
        html += '<tr style="' + rowBg + '">'
            + '<td style="padding:6px 8px;">' + pmLabels[mod] + '</td>'
            + '<td style="text-align:center;padding:6px 4px;">' + defaultIcon + '</td>'
            + '<td style="text-align:center;padding:6px 4px;">' + sel + '</td>'
            + '</tr>';
    }

    html += '</tbody></table>';
    document.getElementById('pmGrid').innerHTML = html;
}

function markChanged(mod) {
    var sel = document.getElementById('ov_' + mod);
    var row = sel.closest('tr');
    if (sel.value === 'default') {
        row.style.background = '';
    } else {
        row.style.background = '#fffbeb';
    }
}

function savePerms() {
    var data = { user_id: pmUserId, overrides: {} };

    for (var mod in pmLabels) {
        var sel = document.getElementById('ov_' + mod);
        if (sel && sel.value !== 'default') {
            data.overrides[mod] = parseInt(sel.value);
        }
    }

    var x = new XMLHttpRequest();
    x.open('POST', pmApiUrl);
    x.setRequestHeader('Content-Type', 'application/json');
    x.onload = function() {
        try {
            var r = JSON.parse(x.responseText);
            var msg = document.getElementById('pmMsg');
            if (r.ok) {
                msg.textContent = 'Permissoes salvas com sucesso!';
                msg.style.background = '#ecfdf5';
                msg.style.color = '#059669';
                msg.style.display = 'block';
                setTimeout(function() { msg.style.display = 'none'; }, 3000);
            } else {
                msg.textContent = r.error || 'Erro ao salvar';
                msg.style.background = '#fef2f2';
                msg.style.color = '#dc2626';
                msg.style.display = 'block';
            }
        } catch(e) {
            alert('Erro ao salvar permissoes');
        }
    };
    x.send(JSON.stringify(data));
}

function resetPerms() {
    if (!confirm('Resetar todas as permissoes para o padrao do perfil?')) return;

    var x = new XMLHttpRequest();
    x.open('POST', pmApiUrl);
    x.setRequestHeader('Content-Type', 'application/json');
    x.onload = function() {
        try {
            var r = JSON.parse(x.responseText);
            if (r.ok) {
                pmOverrides = {};
                renderGrid();
                var msg = document.getElementById('pmMsg');
                msg.textContent = 'Permissoes resetadas para padrao do perfil!';
                msg.style.background = '#ecfdf5';
                msg.style.color = '#059669';
                msg.style.display = 'block';
                setTimeout(function() { msg.style.display = 'none'; }, 3000);
            }
        } catch(e) {}
    };
    x.send(JSON.stringify({ user_id: pmUserId, action: 'reset' }));
}

// Fechar modal com ESC ou clicando no overlay
document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closePerms(); });
document.getElementById('permModal').addEventListener('click', function(e) {
    if (e.target === this) closePerms();
});
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
