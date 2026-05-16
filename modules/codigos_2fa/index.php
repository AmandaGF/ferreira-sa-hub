<?php
/**
 * Ferreira & Sá Hub — Códigos 2FA centralizados
 *
 * Mostra cards com o código TOTP atual de cada sistema cadastrado (eproc 1g,
 * eproc 2g, PJe, TRF2, etc.), com timer visual de renovação. Cada visualização
 * fica registrada no audit_log pra rastreabilidade.
 *
 * Whitelist (functions_auth.php → can_access_codigos_2fa()): Amanda, Luiz,
 * Naiara, Carina. Admin (criar/editar/excluir sistemas) só Amanda + Luiz.
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();
require_once __DIR__ . '/../../core/functions_totp.php';

if (!can_access_codigos_2fa()) {
    flash_set('error', 'Sem permissão pra acessar Códigos 2FA.');
    redirect(url('modules/dashboard/'));
}

$pdo = db();
totp_ensure_schema($pdo);

$isAdmin = can_admin_codigos_2fa();
$pageTitle = 'Códigos 2FA';

$sistemas = $pdo->query("SELECT * FROM sistemas_2fa ORDER BY ordem ASC, nome ASC")->fetchAll();

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.c2-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:1rem; }
.c2-card { background:#fff; border:2px solid #e5e7eb; border-radius:14px; padding:1rem 1.1rem; box-shadow:var(--shadow-sm); transition:all var(--transition); }
.c2-card:hover { box-shadow:var(--shadow-md); border-color:#B87333; }
.c2-card-head { display:flex; align-items:center; justify-content:space-between; gap:.5rem; margin-bottom:.6rem; }
.c2-card-title { font-size:.95rem; font-weight:700; color:var(--petrol-900); display:flex; align-items:center; gap:.4rem; }
.c2-card-icon { font-size:1.2rem; }
.c2-card-actions { display:flex; gap:.3rem; }
.c2-card-actions button { background:none; border:none; cursor:pointer; padding:.25rem; color:#94a3b8; font-size:.85rem; }
.c2-card-actions button:hover { color:#052228; }
.c2-code { font-family:'Courier New', monospace; font-size:2.5rem; font-weight:800; letter-spacing:.5rem; color:#052228; text-align:center; padding:.75rem 0; cursor:pointer; user-select:all; }
.c2-code:hover { color:#B87333; }
.c2-timer-wrap { background:#f3f4f6; border-radius:8px; height:6px; overflow:hidden; margin-bottom:.5rem; }
.c2-timer-bar { height:100%; background:linear-gradient(90deg, #059669, #10b981); transition:width 1s linear; }
.c2-timer-bar.urgent { background:linear-gradient(90deg, #dc2626, #ef4444); }
.c2-timer-text { font-size:.7rem; color:#6b7280; text-align:center; margin-bottom:.5rem; }
.c2-card-buttons { display:flex; gap:.4rem; }
.c2-card-buttons button, .c2-card-buttons a { flex:1; padding:.45rem .6rem; border-radius:8px; font-size:.75rem; font-weight:600; cursor:pointer; border:none; text-align:center; text-decoration:none; display:inline-flex; align-items:center; justify-content:center; gap:.3rem; }
.c2-btn-copy { background:#052228; color:#fff; }
.c2-btn-copy.copied { background:#059669; }
.c2-btn-open { background:#f3f4f6; color:#052228; border:1px solid #e5e7eb; }
.c2-btn-open:hover { background:#e5e7eb; }
.c2-empty { background:#fff; border:2px dashed #e5e7eb; border-radius:14px; padding:3rem; text-align:center; color:#94a3b8; }
.c2-aviso { background:#fef3c7; border:1px solid #fbbf24; color:#92400e; padding:.75rem 1rem; border-radius:10px; margin-bottom:1.25rem; font-size:.8rem; display:flex; align-items:flex-start; gap:.5rem; }
.c2-aviso strong { color:#78350f; }
</style>

<div style="max-width:1200px;margin:0 auto;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem;flex-wrap:wrap;gap:.75rem;">
        <h2 style="margin:0;font-size:1.2rem;color:var(--petrol-900);">🔐 Códigos 2FA — Tribunais</h2>
        <?php if ($isAdmin): ?>
        <button onclick="c2AbrirNovo()" class="btn btn-primary btn-sm" style="font-size:.78rem;">+ Adicionar sistema</button>
        <?php endif; ?>
    </div>

    <div class="c2-aviso">
        <span style="font-size:1rem;">⚠</span>
        <div>
            <strong>Atenção:</strong> os códigos abaixo dão acesso aos sistemas como se fosse você logando.
            Cada visualização fica registrada (auditoria). Não compartilhe códigos por mensagem — quem precisa, entra aqui no Hub.
            O código muda a cada 30 segundos.
        </div>
    </div>

    <?php if (empty($sistemas)): ?>
        <div class="c2-empty">
            <div style="font-size:2.5rem;margin-bottom:.5rem;">🔐</div>
            <h3 style="margin:0 0 .3rem;color:#052228;">Nenhum sistema cadastrado</h3>
            <p style="font-size:.85rem;margin:0;">
                <?php if ($isAdmin): ?>
                    Clique em <strong>"+ Adicionar sistema"</strong> pra cadastrar o primeiro.
                <?php else: ?>
                    Apenas Amanda e Luiz Eduardo podem cadastrar sistemas. Peça pra um deles configurar.
                <?php endif; ?>
            </p>
        </div>
    <?php else: ?>
        <div class="c2-grid">
            <?php foreach ($sistemas as $s):
                $icone = $s['icone'] ?: '⚖';
            ?>
            <div class="c2-card" data-sistema-id="<?= (int)$s['id'] ?>">
                <div class="c2-card-head">
                    <div class="c2-card-title">
                        <span class="c2-card-icon"><?= e($icone) ?></span>
                        <span><?= e($s['nome']) ?></span>
                    </div>
                    <?php if ($isAdmin): ?>
                    <div class="c2-card-actions">
                        <button onclick="c2Editar(<?= (int)$s['id'] ?>)" title="Editar">✏</button>
                        <button onclick="c2Excluir(<?= (int)$s['id'] ?>, <?= htmlspecialchars(json_encode($s['nome'], JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)" title="Excluir">🗑</button>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="c2-code" id="c2Code<?= (int)$s['id'] ?>" onclick="c2Copiar(<?= (int)$s['id'] ?>)" title="Clique pra copiar">- - -   - - -</div>
                <div class="c2-timer-wrap"><div class="c2-timer-bar" id="c2Bar<?= (int)$s['id'] ?>" style="width:100%;"></div></div>
                <div class="c2-timer-text" id="c2Timer<?= (int)$s['id'] ?>">Renova em <span>30</span>s</div>
                <div class="c2-card-buttons">
                    <button class="c2-btn-copy" onclick="c2Copiar(<?= (int)$s['id'] ?>, this)">📋 Copiar</button>
                    <?php if ($s['url_login']): ?>
                    <a class="c2-btn-open" href="<?= e($s['url_login']) ?>" target="_blank">🌐 Abrir login</a>
                    <?php endif; ?>
                </div>
                <?php if ($s['notas']): ?>
                <div style="margin-top:.5rem;padding-top:.5rem;border-top:1px solid #f3f4f6;font-size:.7rem;color:#6b7280;"><?= nl2br(e($s['notas'])) ?></div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php if ($isAdmin): ?>
<!-- Modal Adicionar/Editar -->
<div id="c2Modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:14px;max-width:520px;width:95%;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.2);">
        <div style="background:linear-gradient(135deg,#052228,#0d3640);color:#fff;padding:1rem 1.2rem;border-radius:14px 14px 0 0;display:flex;justify-content:space-between;align-items:center;">
            <h3 style="margin:0;font-size:1rem;" id="c2ModalTit">Adicionar sistema</h3>
            <button onclick="c2FecharModal()" style="background:none;border:none;color:#fff;font-size:1.2rem;cursor:pointer">×</button>
        </div>
        <div style="padding:1.25rem;">
            <input type="hidden" id="c2Id" value="0">
            <div style="margin-bottom:.75rem;">
                <label style="font-size:.72rem;font-weight:600;color:#6b7280;display:block;margin-bottom:.2rem;">Nome do sistema *</label>
                <input id="c2Nome" class="form-input" style="font-size:.85rem;" placeholder="Ex: eproc 1º Grau TJRJ">
            </div>
            <div style="display:grid;grid-template-columns:80px 1fr;gap:.6rem;margin-bottom:.75rem;">
                <div>
                    <label style="font-size:.72rem;font-weight:600;color:#6b7280;display:block;margin-bottom:.2rem;">Ícone</label>
                    <input id="c2Icone" class="form-input" style="font-size:.85rem;text-align:center;" placeholder="⚖" maxlength="3">
                </div>
                <div>
                    <label style="font-size:.72rem;font-weight:600;color:#6b7280;display:block;margin-bottom:.2rem;">URL de login (opcional)</label>
                    <input id="c2Url" class="form-input" style="font-size:.85rem;" placeholder="https://eproc1g.tjrj.jus.br/eproc/">
                </div>
            </div>
            <div style="margin-bottom:.75rem;">
                <label style="font-size:.72rem;font-weight:600;color:#6b7280;display:block;margin-bottom:.2rem;">Chave secreta (Base32, do QR code) *</label>
                <input id="c2Chave" class="form-input" style="font-size:.85rem;font-family:monospace;" placeholder="JBSWY3DPEHPK3PXP..." autocomplete="off">
                <small style="color:#94a3b8;font-size:.68rem;display:block;margin-top:.2rem;">
                    No tribunal, ative o 2FA. Quando aparecer o QR code, geralmente tem um link "Não consigo escanear" ou "Mostrar chave em texto" — copie a string e cole aqui.
                </small>
            </div>
            <div style="margin-bottom:.75rem;">
                <label style="font-size:.72rem;font-weight:600;color:#6b7280;display:block;margin-bottom:.2rem;">Notas (opcional)</label>
                <textarea id="c2Notas" class="form-input" style="font-size:.8rem;min-height:60px;" placeholder="Ex: Usuário CPF Amanda. Renovar 2FA em mai/2027."></textarea>
            </div>
            <div id="c2Preview" style="display:none;background:#ecfdf5;border:1px solid #86efac;border-radius:8px;padding:.6rem;margin-bottom:.75rem;text-align:center;">
                <div style="font-size:.7rem;color:#065f46;margin-bottom:.2rem;">Código de teste (para confirmar que a chave está certa):</div>
                <div id="c2PreviewCode" style="font-family:monospace;font-size:1.5rem;font-weight:800;color:#065f46;letter-spacing:.3rem;">------</div>
            </div>
            <div style="display:flex;gap:.5rem;justify-content:space-between;">
                <button onclick="c2Testar()" class="btn btn-outline btn-sm" style="font-size:.78rem;">🧪 Testar chave</button>
                <div style="display:flex;gap:.5rem;">
                    <button onclick="c2FecharModal()" class="btn btn-outline btn-sm" style="font-size:.78rem;">Cancelar</button>
                    <button onclick="c2Salvar()" class="btn btn-primary btn-sm" style="font-size:.78rem;">💾 Salvar</button>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
var C2_API = '<?= module_url('codigos_2fa', 'api.php') ?>';
var C2_CSRF = '<?= generate_csrf_token() ?>';

// Codigos sao gerados via XHR (servidor decifra a chave) — UI atualiza a cada renovacao.
// Cache local pra evitar bater o servidor 1x/seg: pegamos uma vez, e o JS recalcula o
// timer + zera o display quando passa de step. No proximo step, busca de novo.
var c2Cache = {}; // {id: {codigo, expiraEm}}

function c2BuscarCodigo(id, cb) {
    var fd = new FormData();
    fd.append('action', 'gerar_codigo');
    fd.append('sistema_id', id);
    fd.append('csrf_token', C2_CSRF);
    fetch(C2_API, { method:'POST', body:fd, credentials:'same-origin', headers:{ 'X-Requested-With':'XMLHttpRequest' } })
        .then(function(r){ return r.json(); })
        .then(function(j){
            if (j.csrf) C2_CSRF = j.csrf;
            if (j.ok) {
                c2Cache[id] = { codigo: j.codigo, expiraEm: Date.now() + (j.segundos_restantes * 1000) };
                if (cb) cb(j.codigo);
            } else {
                var el = document.getElementById('c2Code' + id);
                if (el) el.textContent = 'erro';
            }
        });
}

function c2AtualizarUI() {
    var cards = document.querySelectorAll('.c2-card[data-sistema-id]');
    cards.forEach(function(card) {
        var id = card.getAttribute('data-sistema-id');
        var codeEl = document.getElementById('c2Code' + id);
        var barEl  = document.getElementById('c2Bar' + id);
        var timerEl = document.getElementById('c2Timer' + id);
        var cache = c2Cache[id];

        if (!cache || Date.now() >= cache.expiraEm) {
            // Expirou (ou nunca buscou) — busca novo codigo
            c2BuscarCodigo(id, function(codigo){
                var formatted = codigo.substring(0,3) + '   ' + codigo.substring(3);
                codeEl.textContent = formatted;
            });
            return;
        }

        // Atualiza timer
        var ms = cache.expiraEm - Date.now();
        var seg = Math.max(0, Math.ceil(ms / 1000));
        var pct = Math.max(0, (ms / 30000) * 100);
        if (barEl) {
            barEl.style.width = pct + '%';
            if (seg <= 5) barEl.classList.add('urgent'); else barEl.classList.remove('urgent');
        }
        if (timerEl) timerEl.querySelector('span').textContent = seg;
    });
}

function c2Copiar(id, btn) {
    var cache = c2Cache[id];
    if (!cache || Date.now() >= cache.expiraEm) {
        c2BuscarCodigo(id, function(codigo){ c2CopiarTexto(codigo, btn); });
        return;
    }
    c2CopiarTexto(cache.codigo, btn);
}

function c2CopiarTexto(codigo, btn) {
    if (navigator.clipboard) {
        navigator.clipboard.writeText(codigo).then(function(){ c2FeedbackCopia(btn); });
    } else {
        var ta = document.createElement('textarea');
        ta.value = codigo;
        ta.style.position = 'fixed';
        ta.style.left = '-9999px';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); c2FeedbackCopia(btn); } catch(e) {}
        document.body.removeChild(ta);
    }
}

function c2FeedbackCopia(btn) {
    if (!btn || !btn.classList) return;
    var original = btn.innerHTML;
    btn.classList.add('copied');
    btn.innerHTML = '✓ Copiado!';
    setTimeout(function(){ btn.classList.remove('copied'); btn.innerHTML = original; }, 1800);
}

// Polling: atualiza a UI a cada segundo (recalcula timer + busca novo codigo quando expira)
setInterval(c2AtualizarUI, 1000);
c2AtualizarUI(); // primeira execucao imediata

<?php if ($isAdmin): ?>
function c2AbrirNovo() {
    document.getElementById('c2ModalTit').textContent = 'Adicionar sistema';
    document.getElementById('c2Id').value = '0';
    ['c2Nome','c2Icone','c2Url','c2Chave','c2Notas'].forEach(function(id){ document.getElementById(id).value = ''; });
    document.getElementById('c2Preview').style.display = 'none';
    document.getElementById('c2Modal').style.display = 'flex';
    document.getElementById('c2Nome').focus();
}

function c2Editar(id) {
    var fd = new FormData();
    fd.append('action', 'buscar_sistema');
    fd.append('sistema_id', id);
    fd.append('csrf_token', C2_CSRF);
    fetch(C2_API, { method:'POST', body:fd, credentials:'same-origin', headers:{'X-Requested-With':'XMLHttpRequest'} })
        .then(function(r){ return r.json(); })
        .then(function(j){
            if (j.csrf) C2_CSRF = j.csrf;
            if (!j.ok) { alert('Erro: ' + (j.erro || 'falha desconhecida')); return; }
            document.getElementById('c2ModalTit').textContent = 'Editar sistema';
            document.getElementById('c2Id').value = j.sistema.id;
            document.getElementById('c2Nome').value = j.sistema.nome || '';
            document.getElementById('c2Icone').value = j.sistema.icone || '';
            document.getElementById('c2Url').value = j.sistema.url_login || '';
            document.getElementById('c2Chave').value = j.sistema.chave || '';
            document.getElementById('c2Notas').value = j.sistema.notas || '';
            document.getElementById('c2Preview').style.display = 'none';
            document.getElementById('c2Modal').style.display = 'flex';
        });
}

function c2FecharModal() { document.getElementById('c2Modal').style.display = 'none'; }

function c2Testar() {
    var chave = document.getElementById('c2Chave').value.trim();
    if (!chave) { alert('Cole a chave secreta primeiro.'); return; }
    var fd = new FormData();
    fd.append('action', 'testar_chave');
    fd.append('chave', chave);
    fd.append('csrf_token', C2_CSRF);
    fetch(C2_API, { method:'POST', body:fd, credentials:'same-origin', headers:{'X-Requested-With':'XMLHttpRequest'} })
        .then(function(r){ return r.json(); })
        .then(function(j){
            if (j.csrf) C2_CSRF = j.csrf;
            if (j.ok && j.codigo) {
                document.getElementById('c2PreviewCode').textContent = j.codigo;
                document.getElementById('c2Preview').style.display = 'block';
            } else {
                alert('Chave inválida: ' + (j.erro || 'verifique se a string está completa'));
            }
        });
}

function c2Salvar() {
    var fd = new FormData();
    fd.append('action', 'salvar_sistema');
    fd.append('id', document.getElementById('c2Id').value);
    fd.append('nome', document.getElementById('c2Nome').value);
    fd.append('icone', document.getElementById('c2Icone').value);
    fd.append('url_login', document.getElementById('c2Url').value);
    fd.append('chave', document.getElementById('c2Chave').value);
    fd.append('notas', document.getElementById('c2Notas').value);
    fd.append('csrf_token', C2_CSRF);
    fetch(C2_API, { method:'POST', body:fd, credentials:'same-origin', headers:{'X-Requested-With':'XMLHttpRequest'} })
        .then(function(r){ return r.json(); })
        .then(function(j){
            if (j.csrf) C2_CSRF = j.csrf;
            if (j.ok) location.reload();
            else alert('Erro: ' + (j.erro || 'falha desconhecida'));
        });
}

function c2Excluir(id, nome) {
    if (!confirm('Excluir o sistema "' + nome + '"? Essa ação não pode ser desfeita.')) return;
    var fd = new FormData();
    fd.append('action', 'excluir_sistema');
    fd.append('sistema_id', id);
    fd.append('csrf_token', C2_CSRF);
    fetch(C2_API, { method:'POST', body:fd, credentials:'same-origin', headers:{'X-Requested-With':'XMLHttpRequest'} })
        .then(function(r){ return r.json(); })
        .then(function(j){
            if (j.csrf) C2_CSRF = j.csrf;
            if (j.ok) location.reload();
            else alert('Erro: ' + (j.erro || 'falha desconhecida'));
        });
}
<?php endif; ?>
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
