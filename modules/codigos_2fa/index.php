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

// Amanda 10/07/2026: self-heal tabela de favoritos por usuario
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS sistemas_2fa_favoritos (
        user_id INT NOT NULL,
        sistema_id INT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, sistema_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Exception $e) {}

// Amanda 10/07/2026: AJAX toggle favorito
if (($_GET['ajax'] ?? '') === 'toggle_favorito' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $sid = (int)($_POST['sistema_id'] ?? 0);
    $uid = (int)current_user_id();
    if (!$sid || !$uid) { echo json_encode(array('ok'=>false,'erro'=>'params')); exit; }
    $chk = $pdo->prepare("SELECT 1 FROM sistemas_2fa_favoritos WHERE user_id=? AND sistema_id=?");
    $chk->execute(array($uid, $sid));
    if ($chk->fetchColumn()) {
        $pdo->prepare("DELETE FROM sistemas_2fa_favoritos WHERE user_id=? AND sistema_id=?")->execute(array($uid, $sid));
        echo json_encode(array('ok'=>true, 'favorito'=>false));
    } else {
        $pdo->prepare("INSERT INTO sistemas_2fa_favoritos (user_id, sistema_id) VALUES (?,?)")->execute(array($uid, $sid));
        echo json_encode(array('ok'=>true, 'favorito'=>true));
    }
    exit;
}

$isAdmin = can_admin_codigos_2fa();
$pageTitle = 'Códigos 2FA';

// Amanda 10/07/2026: ORDER favoritos do usuario primeiro, depois ordem/nome.
$_uidLogado = (int)current_user_id();
$sistemas = $pdo->prepare(
    "SELECT s.*, CASE WHEN f.sistema_id IS NULL THEN 0 ELSE 1 END AS eh_favorito
     FROM sistemas_2fa s
     LEFT JOIN sistemas_2fa_favoritos f ON f.sistema_id = s.id AND f.user_id = ?
     ORDER BY eh_favorito DESC, s.ordem ASC, s.nome ASC"
);
$sistemas->execute(array($_uidLogado));
$sistemas = $sistemas->fetchAll();

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
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;flex-wrap:wrap;gap:.75rem;">
        <h2 style="margin:0;font-size:1.2rem;color:var(--petrol-900);">🔐 Códigos 2FA e Senhas — Sistemas</h2>
        <?php if ($isAdmin): ?>
        <button onclick="c2AbrirNovo()" class="btn btn-primary btn-sm" style="font-size:.78rem;">+ Adicionar sistema</button>
        <?php endif; ?>
    </div>

    <!-- Amanda 10/07/2026: barra de busca + toggle "só favoritos" -->
    <div style="display:flex;gap:.6rem;align-items:center;flex-wrap:wrap;margin-bottom:1rem;">
        <div style="position:relative;flex:1;min-width:260px;">
            <span style="position:absolute;left:12px;top:50%;transform:translateY(-50%);font-size:.9rem;color:#94a3b8;">🔍</span>
            <input type="text" id="c2Busca" placeholder="Buscar sistema (nome, ícone, notas)…" oninput="c2Filtrar(this.value)" autocomplete="off" style="width:100%;padding:.6rem .6rem .6rem 36px;border:1.5px solid #e5e7eb;border-radius:10px;font-size:.88rem;background:#fff;">
        </div>
        <button type="button" id="c2BtnSoFav" onclick="c2ToggleSoFavoritos()" style="padding:.55rem 1rem;background:#f9fafb;border:1.5px solid #e5e7eb;border-radius:10px;font-size:.82rem;font-weight:600;color:#4b5563;cursor:pointer;display:flex;align-items:center;gap:.4rem;">
            ☆ <span>Só favoritos</span>
        </button>
        <span id="c2ContadorResultado" style="font-size:.75rem;color:#6b7280;"></span>
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
            <?php
            $_tem2fa = !empty($s['chave_encrypted']);
            $_temLogin = !empty($s['login']);
            $_temSenha = !empty($s['senha_encrypted']);
            $_temEmail = !empty($s['email']);
            ?>
            <?php
            $_ehFav = !empty($s['eh_favorito']);
            $_termoBusca = mb_strtolower(($s['nome'] ?? '') . ' ' . ($s['notas'] ?? '') . ' ' . ($s['login'] ?? '') . ' ' . ($s['email'] ?? ''));
            ?>
            <div class="c2-card" data-sistema-id="<?= (int)$s['id'] ?>" data-tem-2fa="<?= $_tem2fa ? '1' : '0' ?>" data-fav="<?= $_ehFav ? '1' : '0' ?>" data-busca="<?= e($_termoBusca) ?>">
                <div class="c2-card-head">
                    <div class="c2-card-title">
                        <button type="button" onclick="c2ToggleFavorito(<?= (int)$s['id'] ?>, this)" title="<?= $_ehFav ? 'Remover dos favoritos' : 'Adicionar aos favoritos (fica no topo da lista)' ?>" style="background:none;border:none;cursor:pointer;font-size:1.05rem;padding:0 2px;color:<?= $_ehFav ? '#f59e0b' : '#d1d5db' ?>;line-height:1;" class="c2-fav-btn"><?= $_ehFav ? '★' : '☆' ?></button>
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
                <?php if ($_tem2fa): ?>
                <div class="c2-code" id="c2Code<?= (int)$s['id'] ?>" onclick="c2Copiar(<?= (int)$s['id'] ?>)" title="Clique pra copiar">- - -   - - -</div>
                <div class="c2-timer-wrap"><div class="c2-timer-bar" id="c2Bar<?= (int)$s['id'] ?>" style="width:100%;"></div></div>
                <div class="c2-timer-text" id="c2Timer<?= (int)$s['id'] ?>">Renova em <span>30</span>s</div>
                <?php else: ?>
                <div style="text-align:center;padding:1.2rem 0 .5rem;color:#6b7280;font-size:.85rem;">
                    <div style="font-size:1.6rem;margin-bottom:.3rem;">🔓</div>
                    <div style="font-weight:600;color:#374151;">Acesso sem 2FA</div>
                    <div style="font-size:.72rem;">Use login e senha abaixo</div>
                </div>
                <?php endif; ?>
                <div class="c2-card-buttons" style="flex-wrap:wrap;">
                    <?php if ($_tem2fa): ?>
                    <button class="c2-btn-copy" onclick="c2Copiar(<?= (int)$s['id'] ?>, this)">📋 Código</button>
                    <?php endif; ?>
                    <?php if ($s['url_login']): ?>
                    <a class="c2-btn-open" href="<?= e($s['url_login']) ?>" target="_blank">🌐 Abrir login</a>
                    <?php endif; ?>
                </div>
                <?php if ($_temLogin || $_temSenha || $_temEmail): ?>
                <div style="margin-top:.6rem;padding-top:.55rem;border-top:1px solid #f3f4f6;display:flex;flex-direction:column;gap:.3rem;">
                    <?php if ($_temLogin): ?>
                    <button onclick="c2CopiarTextoSimples(<?= htmlspecialchars(json_encode($s['login']), ENT_QUOTES) ?>, this)" style="display:flex;align-items:center;gap:.5rem;background:#f9fafb;border:1px solid #e5e7eb;padding:.35rem .55rem;border-radius:6px;cursor:pointer;font-size:.72rem;color:#374151;text-align:left;">
                        <span style="font-weight:700;color:#6b7280;min-width:48px;">👤 Login</span>
                        <span style="flex:1;font-family:monospace;"><?= e($s['login']) ?></span>
                        <span style="color:#9ca3af;font-size:.7rem;">copiar</span>
                    </button>
                    <?php endif; ?>
                    <?php if ($_temSenha): ?>
                    <button onclick="c2CopiarSenha(<?= (int)$s['id'] ?>, this)" style="display:flex;align-items:center;gap:.5rem;background:#fef3c7;border:1px solid #fcd34d;padding:.35rem .55rem;border-radius:6px;cursor:pointer;font-size:.72rem;color:#92400e;text-align:left;">
                        <span style="font-weight:700;min-width:48px;">🔑 Senha</span>
                        <span style="flex:1;font-family:monospace;letter-spacing:.2em;">••••••••</span>
                        <span style="font-size:.7rem;">copiar</span>
                    </button>
                    <?php endif; ?>
                    <?php if ($_temEmail): ?>
                    <button onclick="c2CopiarTextoSimples(<?= htmlspecialchars(json_encode($s['email']), ENT_QUOTES) ?>, this)" style="display:flex;align-items:center;gap:.5rem;background:#f9fafb;border:1px solid #e5e7eb;padding:.35rem .55rem;border-radius:6px;cursor:pointer;font-size:.72rem;color:#374151;text-align:left;">
                        <span style="font-weight:700;color:#6b7280;min-width:48px;">📧 E-mail</span>
                        <span style="flex:1;font-family:monospace;"><?= e($s['email']) ?></span>
                        <span style="color:#9ca3af;font-size:.7rem;">copiar</span>
                    </button>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
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
                <label style="font-size:.72rem;font-weight:600;color:#6b7280;display:block;margin-bottom:.2rem;">Chave secreta 2FA (Base32, do QR code)</label>
                <input id="c2Chave" class="form-input" style="font-size:.85rem;font-family:monospace;" placeholder="JBSWY3DPEHPK3PXP... — deixe em branco se o sistema não tem 2FA" autocomplete="off">
                <small style="color:#94a3b8;font-size:.68rem;display:block;margin-top:.2rem;">
                    Opcional. Se o sistema tem 2FA, ative no tribunal e copie a chave Base32 (link "Não consigo escanear" / "Mostrar chave"). Se for só login+senha sem 2FA, deixe em branco.
                </small>
            </div>
            <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:.75rem;margin-bottom:.75rem;">
                <div style="font-size:.72rem;font-weight:700;color:#374151;margin-bottom:.5rem;">🔑 Credenciais de acesso (opcional)</div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem;margin-bottom:.5rem;">
                    <div>
                        <label style="font-size:.68rem;font-weight:600;color:#6b7280;display:block;margin-bottom:.15rem;">Login / usuário</label>
                        <input id="c2Login" class="form-input" style="font-size:.82rem;font-family:monospace;" placeholder="Ex: RJ163260 ou CPF" autocomplete="off">
                    </div>
                    <div>
                        <label style="font-size:.68rem;font-weight:600;color:#6b7280;display:block;margin-bottom:.15rem;">Senha</label>
                        <input id="c2Senha" type="password" class="form-input" style="font-size:.82rem;font-family:monospace;" placeholder="Senha do sistema" autocomplete="off">
                    </div>
                </div>
                <div>
                    <label style="font-size:.68rem;font-weight:600;color:#6b7280;display:block;margin-bottom:.15rem;">E-mail (se aplicável)</label>
                    <input id="c2Email" class="form-input" style="font-size:.82rem;" placeholder="contato@ferreiraesa.com.br" autocomplete="off">
                </div>
                <small style="color:#94a3b8;font-size:.68rem;display:block;margin-top:.4rem;">A senha é criptografada antes de salvar. Cada vez que alguém visualizar, fica registrado no log de auditoria.</small>
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
    var cards = document.querySelectorAll('.c2-card[data-sistema-id][data-tem-2fa="1"]');
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

// Copia texto simples (login, email) - sem audit log (e dado nao secreto)
window.c2CopiarTextoSimples = function(texto, btn) {
    c2CopiarTexto(String(texto || ''), btn);
};

// Copia senha (decifra no servidor + audit log)
window.c2CopiarSenha = function(sistemaId, btn) {
    var fd = new FormData();
    fd.append('action', 'revelar_senha');
    fd.append('sistema_id', sistemaId);
    fd.append('csrf_token', C2_CSRF);
    fetch(C2_API, { method:'POST', body:fd, credentials:'same-origin', headers:{'X-Requested-With':'XMLHttpRequest'} })
        .then(function(r){ return r.json(); })
        .then(function(j){
            if (j.csrf) C2_CSRF = j.csrf;
            if (j.ok) { c2CopiarTexto(j.senha, btn); }
            else { alert('Não foi possível obter a senha: ' + (j.erro || 'erro')); }
        });
};

// Polling: atualiza a UI a cada segundo (recalcula timer + busca novo codigo quando expira)
setInterval(c2AtualizarUI, 1000);
c2AtualizarUI(); // primeira execucao imediata

// Amanda 10/07/2026: busca e favoritos
var c2SoFav = false;
function c2Filtrar(termo) {
    var t = (termo || '').toLowerCase().trim();
    var cards = document.querySelectorAll('.c2-card');
    var visiveis = 0;
    cards.forEach(function(card){
        var busca = card.dataset.busca || '';
        var ehFav = card.dataset.fav === '1';
        var matchTexto = !t || busca.indexOf(t) !== -1;
        var matchFav = !c2SoFav || ehFav;
        if (matchTexto && matchFav) { card.style.display = ''; visiveis++; }
        else { card.style.display = 'none'; }
    });
    var contador = document.getElementById('c2ContadorResultado');
    if (contador) contador.textContent = (t || c2SoFav) ? (visiveis + ' de ' + cards.length + ' sistemas') : '';
}

function c2ToggleSoFavoritos() {
    c2SoFav = !c2SoFav;
    var btn = document.getElementById('c2BtnSoFav');
    if (c2SoFav) {
        btn.style.background = '#fef3c7';
        btn.style.borderColor = '#f59e0b';
        btn.style.color = '#92400e';
        btn.innerHTML = '★ <span>Só favoritos</span>';
    } else {
        btn.style.background = '#f9fafb';
        btn.style.borderColor = '#e5e7eb';
        btn.style.color = '#4b5563';
        btn.innerHTML = '☆ <span>Só favoritos</span>';
    }
    c2Filtrar(document.getElementById('c2Busca').value);
}

function c2ToggleFavorito(sistemaId, btn) {
    if (btn.disabled) return;
    btn.disabled = true;
    var textoAntigo = btn.textContent;
    btn.textContent = '⋯';
    var fd = new FormData();
    fd.append('sistema_id', sistemaId);
    fetch('<?= module_url('codigos_2fa') ?>?ajax=toggle_favorito', {
        method: 'POST', body: fd, credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(function(r){ return r.json(); })
    .then(function(j){
        btn.disabled = false;
        if (!j.ok) { btn.textContent = textoAntigo; alert('Erro: ' + (j.erro || 'tente de novo')); return; }
        var card = btn.closest('.c2-card');
        if (j.favorito) {
            btn.textContent = '★';
            btn.style.color = '#f59e0b';
            btn.title = 'Remover dos favoritos';
            if (card) card.dataset.fav = '1';
        } else {
            btn.textContent = '☆';
            btn.style.color = '#d1d5db';
            btn.title = 'Adicionar aos favoritos (fica no topo da lista)';
            if (card) card.dataset.fav = '0';
        }
        // Re-filtra pra aplicar "só favoritos" se estiver ativo
        if (c2SoFav) c2Filtrar(document.getElementById('c2Busca').value);
    })
    .catch(function(){ btn.disabled = false; btn.textContent = textoAntigo; alert('Erro de rede'); });
}

// Atalho de teclado: Ctrl+K / Cmd+K foca na busca
document.addEventListener('keydown', function(e){
    if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
        e.preventDefault();
        var busca = document.getElementById('c2Busca');
        if (busca) { busca.focus(); busca.select(); }
    }
});

<?php if ($isAdmin): ?>
function c2AbrirNovo() {
    document.getElementById('c2ModalTit').textContent = 'Adicionar sistema';
    document.getElementById('c2Id').value = '0';
    ['c2Nome','c2Icone','c2Url','c2Chave','c2Notas','c2Login','c2Senha','c2Email'].forEach(function(id){ var el = document.getElementById(id); if (el) el.value = ''; });
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
            document.getElementById('c2Login').value = j.sistema.login || '';
            document.getElementById('c2Senha').value = j.sistema.senha || '';
            document.getElementById('c2Email').value = j.sistema.email || '';
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
    fd.append('login', document.getElementById('c2Login').value);
    fd.append('senha', document.getElementById('c2Senha').value);
    fd.append('email', document.getElementById('c2Email').value);
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
