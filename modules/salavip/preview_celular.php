<?php
/**
 * Ferreira & Sa Hub -- Central VIP -- Preview no Celular
 *
 * Carrega a Central VIP num iframe com moldura de smartphone, pra Amanda
 * ver como o cliente ve no aparelho dele sem precisar pegar o celular.
 *
 * Recebe ?token=X (impersonate token ja gerado em acessos.php) + ?sv=ID.
 * O iframe aponta pra https://www.ferreiraesa.com.br/salavip/login_admin.php?token=X
 * que ja faz o login impersonate e redireciona pro index do salavip.
 *
 * Amanda 10/06/2026.
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();

if ((int)current_user_id() !== 1) {
    flash_set('error', 'Acesso restrito.');
    redirect(module_url('salavip', 'acessos.php'));
}

$token = $_GET['token'] ?? '';
$svId  = (int)($_GET['sv'] ?? 0);

if (!$token || !preg_match('/^[a-f0-9]{64}$/', $token) || $svId <= 0) {
    flash_set('error', 'Token invalido. Volte e clique no botao novamente.');
    redirect(module_url('salavip', 'acessos.php'));
}

// Verifica que o token existe e ainda nao expirou (extra defesa)
$pdo = db();
$stTok = $pdo->prepare("SELECT t.token, t.usado_em, t.expira_em, c.name AS cliente_nome, c.id AS cliente_id
                        FROM salavip_impersonate_tokens t
                        JOIN salavip_usuarios su ON su.id = t.salavip_user_id
                        JOIN clients c ON c.id = su.cliente_id
                        WHERE t.token = ? AND t.salavip_user_id = ? AND t.expira_em > NOW() LIMIT 1");
$stTok->execute(array($token, $svId));
$infoTok = $stTok->fetch();
if (!$infoTok) {
    flash_set('error', 'Token expirado ou ja usado. Volte e gere outro.');
    redirect(module_url('salavip', 'acessos.php'));
}

$clienteNome = $infoTok['cliente_nome'];
$ifrUrl = 'https://www.ferreiraesa.com.br/salavip/login_admin.php?token=' . urlencode($token);

$pageTitle = 'Preview celular — ' . $clienteNome;
require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.pc-toolbar { display:flex; align-items:center; gap:.75rem; flex-wrap:wrap; padding:.6rem .9rem; background:#fff; border:1px solid var(--border); border-radius:10px; margin-bottom:1rem; }
.pc-toolbar h2 { font-size:1rem; color:var(--petrol-900); margin:0; }
.pc-toolbar .pc-cliente { font-size:.8rem; color:#6b7280; }
.pc-toolbar select { font-size:.82rem; padding:.35rem .55rem; border:1.5px solid var(--border); border-radius:6px; background:#fff; }
.pc-toolbar .pc-spacer { flex:1; }
.pc-toolbar .pc-info { font-size:.7rem; color:#94a3b8; }

.pc-palco { background:#1e293b; min-height:90vh; padding:1.5rem; border-radius:12px; display:flex; justify-content:center; align-items:flex-start; overflow:auto; }

/* Moldura de smartphone */
.pc-phone {
  position:relative;
  background:#111827;
  border-radius:42px;
  padding:14px 10px;
  box-shadow: 0 30px 60px rgba(0,0,0,.5), 0 0 0 2px #374151 inset;
  transition: width .25s, height .25s;
}
.pc-phone::before { /* notch / pill superior */
  content:''; position:absolute; top:8px; left:50%; transform:translateX(-50%);
  width:110px; height:18px; background:#000; border-radius:14px; z-index:3;
}
.pc-phone::after { /* indicador inferior */
  content:''; position:absolute; bottom:6px; left:50%; transform:translateX(-50%);
  width:120px; height:4px; background:#374151; border-radius:3px; z-index:3;
}
.pc-phone .pc-screen {
  background:#fff; border-radius:32px; overflow:hidden; position:relative;
  width:100%; height:100%;
}
.pc-phone iframe { width:100%; height:100%; border:none; display:block; background:#fff; }

/* Modelos */
.pc-iphone14   { width:412px; height:892px; }
.pc-iphone14 .pc-screen { padding-top:28px; }
.pc-iphonese   { width:396px; height:706px; }
.pc-iphonese .pc-screen { padding-top:22px; }
.pc-galaxy     { width:432px; height:912px; }
.pc-galaxy .pc-screen { padding-top:26px; }
.pc-tablet     { width:780px; height:1040px; border-radius:32px; padding:18px; }
.pc-tablet::before { display:none; } .pc-tablet::after { display:none; }
.pc-tablet .pc-screen { border-radius:22px; padding-top:0; }

/* Rotacao landscape */
.pc-rot { transform: rotate(90deg); transform-origin: center center; }

.pc-toolbar .btn-fechar { background:#dc2626; color:#fff; border:none; padding:.4rem .9rem; border-radius:6px; font-size:.78rem; cursor:pointer; font-weight:600; }
.pc-toolbar .btn-abrir-tudo { background:#7c3aed; color:#fff; border:none; padding:.4rem .9rem; border-radius:6px; font-size:.78rem; cursor:pointer; font-weight:600; text-decoration:none; }
</style>

<div class="pc-toolbar">
    <h2>📱 Preview Central VIP</h2>
    <div class="pc-cliente">como <strong><?= e($clienteNome) ?></strong></div>
    <div class="pc-spacer"></div>
    <label style="font-size:.78rem;font-weight:600;color:#475569;">Modelo:</label>
    <select id="pcModelo" onchange="pcTrocarModelo(this.value)">
        <option value="iphone14">📱 iPhone 14 / 15 (412×892)</option>
        <option value="iphonese">📱 iPhone SE (396×706)</option>
        <option value="galaxy">📱 Galaxy S22+ (432×912)</option>
        <option value="tablet">📲 Tablet (780×1040)</option>
    </select>
    <button type="button" onclick="pcRotacionar()" style="background:#fff;border:1.5px solid var(--border);border-radius:6px;padding:.35rem .65rem;font-size:.78rem;font-weight:600;cursor:pointer;" title="Girar tela">🔄 Girar</button>
    <a href="<?= e($ifrUrl) ?>" target="_blank" class="btn-abrir-tudo" title="Abrir em tela cheia (modo desktop normal)">↗ Tela cheia</a>
    <a href="<?= module_url('salavip', 'acessos.php') ?>" class="btn-fechar">✕ Fechar preview</a>
</div>

<div class="pc-palco">
    <div id="pcPhone" class="pc-phone pc-iphone14">
        <div class="pc-screen">
            <iframe id="pcIfr" src="<?= e($ifrUrl) ?>" allow="clipboard-write"></iframe>
        </div>
    </div>
</div>

<div style="background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;padding:.6rem .9rem;font-size:.78rem;color:#92400e;margin-top:1rem;">
    💡 <strong>Como usar pra ajudar o cliente:</strong> este é o que o cliente vê no celular dele. Compartilhe a tela com ele por WhatsApp/vídeo ou tire prints pra mostrar onde clicar. Trocar de "Modelo" simula tamanhos de tela diferentes.<br>
    ⚠️ Token de 5min, uso único — se sair desta página, gere outro pelo botão 📱 da lista.
</div>

<script>
function pcTrocarModelo(m) {
    var ph = document.getElementById('pcPhone');
    ph.className = 'pc-phone pc-' + m + (ph.classList.contains('pc-rot') ? ' pc-rot' : '');
}
function pcRotacionar() {
    var ph = document.getElementById('pcPhone');
    ph.classList.toggle('pc-rot');
}
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
