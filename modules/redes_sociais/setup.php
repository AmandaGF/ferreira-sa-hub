<?php
/**
 * Redes Sociais — Setup Meta (Amanda 04/06/2026 - admin only)
 *
 * Pagina com passo-a-passo do que Amanda precisa fazer no Meta Business
 * Suite + Meta for Developers + App Review pra ativar o Inbox e os
 * Comentarios. Tambem expoe os campos onde ela vai colar App ID, App Secret
 * e Verify Token quando estiverem prontos.
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
require_access('redes_sociais_config');

$pdo = db();
$pageTitle = 'Configuração Meta';

// Carrega config atual
$cfg = array('meta_app_id' => '', 'meta_app_secret' => '', 'meta_verify_token' => '', 'meta_webhook_active' => '');
try {
    foreach ($pdo->query("SELECT chave, valor FROM meta_config")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $cfg[$r['chave']] = $r['valor'];
    }
} catch (Exception $e) {}

// URLs que a Amanda precisa colar no Meta Developers
$baseUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
$webhookUrl = $baseUrl . '/conecta/api/meta_webhook.php';
$privacidadeUrl = $baseUrl . '/conecta/lp/privacidade.php';

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.rs-step { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:1.2rem 1.4rem; margin-bottom:1rem; }
.rs-step h3 { display:flex; align-items:center; gap:.6rem; font-size:1rem; color:var(--petrol-900); margin:0 0 .8rem; }
.rs-step h3 .n { background:var(--rose); color:var(--petrol-900); width:28px; height:28px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; font-weight:800; font-size:.85rem; }
.rs-step ol { padding-left:1.4rem; line-height:1.65; }
.rs-step li { margin-bottom:.4rem; font-size:.88rem; color:#374151; }
.rs-step code { background:#f1f5f9; padding:.18rem .45rem; border-radius:4px; font-size:.82rem; color:#1e40af; font-family:monospace; word-break:break-all; }
.rs-step .url-copy { background:#f1f5f9; border:1px solid #e2e8f0; border-radius:6px; padding:.5rem .7rem; font-family:monospace; font-size:.78rem; color:#1e40af; display:flex; align-items:center; gap:.5rem; word-break:break-all; }
.rs-step .url-copy button { background:#052228; color:#fff; border:none; padding:.3rem .6rem; border-radius:5px; font-size:.7rem; cursor:pointer; flex-shrink:0; }
.rs-warn { background:#fef3c7; border-left:3px solid #f59e0b; padding:.55rem .8rem; border-radius:0 6px 6px 0; font-size:.8rem; color:#92400e; margin-top:.6rem; }
.rs-ok { background:#dcfce7; border-left:3px solid #16a34a; padding:.55rem .8rem; border-radius:0 6px 6px 0; font-size:.8rem; color:#166534; margin-top:.6rem; }
</style>

<div style="max-width:920px;margin:0 auto;padding:1rem;">
    <div style="display:flex;align-items:center;gap:.6rem;margin-bottom:1.2rem;">
        <a href="<?= module_url('redes_sociais') ?>" style="font-size:.78rem;color:#6b7280;text-decoration:none;">← Redes Sociais</a>
    </div>
    <h2 style="margin:0 0 .4rem;color:var(--petrol-900);">⚙️ Configuração da integração Meta</h2>
    <p style="color:#6b7280;font-size:.88rem;margin:0 0 1.4rem;">Esses passos ativam o Inbox Instagram, Inbox Facebook e os Comentários. A maior parte é feita uma vez só. Aperte o App Review da Meta cedo — é o gargalo.</p>

    <!-- ETAPA 1 -->
    <div class="rs-step">
        <h3><span class="n">1</span> Conta Instagram Business vinculada à Página</h3>
        <ol>
            <li>Abre o <strong>Meta Business Suite</strong> (<a href="https://business.facebook.com" target="_blank">business.facebook.com</a>) com a conta do escritório.</li>
            <li>Vai em <strong>Configurações → Contas → Contas do Instagram</strong>.</li>
            <li>Confirma que o Instagram do escritório está como <strong>Conta Profissional / Business</strong> (não Pessoal).</li>
            <li>Confirma que ele está vinculado à <strong>Página do Facebook</strong> do escritório.</li>
        </ol>
        <div class="rs-warn">⚠️ Se o Instagram estiver como "Pessoal", muda pra Profissional dentro do próprio app do IG (Configurações → Conta → Mudar para conta profissional). Demora 2 min.</div>
    </div>

    <!-- ETAPA 2 -->
    <div class="rs-step">
        <h3><span class="n">2</span> Cria o App no Meta for Developers</h3>
        <ol>
            <li>Entra em <a href="https://developers.facebook.com/apps/" target="_blank">developers.facebook.com/apps/</a> → <strong>Criar App</strong>.</li>
            <li>Tipo: <strong>Empresa (Business)</strong>.</li>
            <li>Nome do App: <code>Ferreira & Sá Hub – Inbox</code> (ou similar).</li>
            <li>E-mail de contato: <code>contato@ferreiraesa.com.br</code>.</li>
            <li>Depois de criar, adiciona os <strong>produtos</strong>:
                <ul style="margin:.4rem 0 0 .8rem;">
                    <li><strong>Messenger</strong> (Facebook Messenger)</li>
                    <li><strong>Instagram → Mensagens do Instagram</strong></li>
                    <li><strong>Webhooks</strong></li>
                </ul>
            </li>
        </ol>
    </div>

    <!-- ETAPA 3 -->
    <div class="rs-step">
        <h3><span class="n">3</span> Configura o Webhook do app</h3>
        <p style="font-size:.85rem;color:#374151;">No produto Webhooks dentro do App, configure:</p>
        <div style="font-size:.85rem;color:#374151;margin-bottom:.5rem;"><strong>Callback URL (para onde Meta envia eventos):</strong></div>
        <div class="url-copy">
            <span style="flex:1;"><?= htmlspecialchars($webhookUrl) ?></span>
            <button onclick="copiarTexto(<?= htmlspecialchars(json_encode($webhookUrl), ENT_QUOTES) ?>, this)">📋 Copiar</button>
        </div>
        <div style="font-size:.85rem;color:#374151;margin-top:.7rem;"><strong>Verify Token</strong> (string secreta que você escolhe — qualquer coisa, mas mantenha igual no campo abaixo):</div>
        <div style="font-size:.82rem;color:#6b7280;margin-top:.3rem;">Gera uma string aleatória de 30+ caracteres. Cola na configuração do app E também no campo "Verify Token" abaixo nesta página.</div>
        <p style="font-size:.85rem;color:#374151;margin:.7rem 0 .3rem;"><strong>Eventos pra assinar:</strong></p>
        <ul style="font-size:.82rem;color:#374151;padding-left:1.4rem;">
            <li><strong>page</strong> → messages, messaging_postbacks, feed (este = comentários)</li>
            <li><strong>instagram</strong> → messages, messaging_postbacks</li>
        </ul>
    </div>

    <!-- ETAPA 4 -->
    <div class="rs-step">
        <h3><span class="n">4</span> Política de Privacidade no App</h3>
        <p style="font-size:.85rem;color:#374151;">A Meta exige URL pública de privacidade pra liberar o App Review:</p>
        <div class="url-copy">
            <span style="flex:1;"><?= htmlspecialchars($privacidadeUrl) ?></span>
            <button onclick="copiarTexto(<?= htmlspecialchars(json_encode($privacidadeUrl), ENT_QUOTES) ?>, this)">📋 Copiar</button>
        </div>
        <div style="font-size:.78rem;color:#6b7280;margin-top:.4rem;">Cola no <strong>Painel do App → Configurações → Básico → URL da política de privacidade</strong>.</div>
    </div>

    <!-- ETAPA 5 -->
    <div class="rs-step">
        <h3><span class="n">5</span> Submete o App Review (gargalo!)</h3>
        <p style="font-size:.85rem;color:#374151;">Pra usar os recursos com a conta real do escritório (e não só com usuários de teste), pede revisão das permissões:</p>
        <ul style="font-size:.82rem;color:#374151;padding-left:1.4rem;">
            <li><code>pages_messaging</code> — Inbox Facebook Messenger</li>
            <li><code>instagram_basic</code> — Identidade da conta IG</li>
            <li><code>instagram_manage_messages</code> — Inbox Instagram DMs</li>
            <li><code>pages_read_user_content</code> — Ler comentários</li>
            <li><code>pages_manage_engagement</code> — Responder comentários</li>
            <li><code>pages_show_list</code> — Listar páginas conectadas</li>
        </ul>
        <div class="rs-warn">⏱ Tempo médio: 3 dias a 3 semanas. Meta exige <strong>screencast</strong> mostrando o uso de cada permissão. Use o Hub mesmo (mostra o Inbox vazio + esta tela de setup) e explica como vai usar.</div>
    </div>

    <!-- ETAPA 6 -->
    <div class="rs-step">
        <h3><span class="n">6</span> Cole as credenciais aqui</h3>
        <p style="font-size:.85rem;color:#374151;margin-bottom:.8rem;">Depois que o App estiver criado, copia daqui:</p>
        <form id="rsConfigForm" onsubmit="event.preventDefault(); rsSalvarConfig();">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="salvar_config">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.6rem;margin-bottom:.7rem;">
                <div>
                    <label style="font-size:.72rem;font-weight:700;color:#6b7280;display:block;margin-bottom:.2rem;">App ID</label>
                    <input name="meta_app_id" class="form-input" style="font-size:.85rem;font-family:monospace;" value="<?= htmlspecialchars($cfg['meta_app_id']) ?>" placeholder="123456789012345">
                </div>
                <div>
                    <label style="font-size:.72rem;font-weight:700;color:#6b7280;display:block;margin-bottom:.2rem;">App Secret</label>
                    <input name="meta_app_secret" type="password" class="form-input" style="font-size:.85rem;font-family:monospace;" value="<?= htmlspecialchars($cfg['meta_app_secret']) ?>" placeholder="••••••••••••••••••••">
                </div>
            </div>
            <div style="margin-bottom:.7rem;">
                <label style="font-size:.72rem;font-weight:700;color:#6b7280;display:block;margin-bottom:.2rem;">Verify Token (mesma string usada no Webhook)</label>
                <input name="meta_verify_token" class="form-input" style="font-size:.85rem;font-family:monospace;" value="<?= htmlspecialchars($cfg['meta_verify_token']) ?>" placeholder="Cola aqui a string que você colocou no Webhook">
            </div>
            <div style="margin-bottom:1rem;">
                <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-size:.85rem;color:#374151;">
                    <input type="checkbox" name="meta_webhook_active" value="1" <?= $cfg['meta_webhook_active'] === '1' ? 'checked' : '' ?>>
                    <span>Webhook ativo (deixe desmarcado até a Meta aprovar o App Review)</span>
                </label>
            </div>
            <button type="submit" class="btn btn-primary btn-sm">💾 Salvar configuração</button>
            <span id="rsConfigStatus" style="margin-left:.5rem;font-size:.78rem;"></span>
        </form>
    </div>

    <!-- ETAPA 7 -->
    <div class="rs-step">
        <h3><span class="n">7</span> Conectar a Página do escritório</h3>
        <p style="font-size:.85rem;color:#374151;">Esta etapa <strong>só funciona após Meta aprovar o App Review</strong>. Até lá, prepara o que está acima. Quando aprovar, volta aqui que eu adiciono o botão "Conectar Página" que abre o fluxo OAuth da Meta.</p>
        <div class="rs-ok">✅ Bom saber: assim que conectar a Página, o webhook começa a receber mensagens e comentários automaticamente. Os Inbox vazios viram listas reais.</div>
    </div>

</div>

<script>
function copiarTexto(texto, btn) {
    if (navigator.clipboard) {
        navigator.clipboard.writeText(texto).then(function(){
            var orig = btn.innerHTML;
            btn.innerHTML = '✓ Copiado';
            setTimeout(function(){ btn.innerHTML = orig; }, 1500);
        });
    }
}
function rsSalvarConfig() {
    var form = document.getElementById('rsConfigForm');
    var fd = new FormData(form);
    fetch('<?= module_url('redes_sociais', 'api.php') ?>', { method:'POST', body: fd, credentials:'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(j){
            var el = document.getElementById('rsConfigStatus');
            if (j.ok) { el.style.color = '#16a34a'; el.textContent = '✓ Salvo ('+ j.salvos +' campos)'; }
            else { el.style.color = '#dc2626'; el.textContent = '✗ ' + (j.error || 'erro'); }
            setTimeout(function(){ el.textContent = ''; }, 3500);
        });
}
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
