<?php
/**
 * Sala VIP F&S — Meus Dados
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
$pdo = sv_db();
$user = salavip_current_user();
$clienteId = salavip_current_cliente_id();

// --- Dados do cliente ---
$stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ?");
$stmt->execute([$clienteId]);
$cliente = $stmt->fetch();

$pageTitle = 'Meus Dados';
require_once __DIR__ . '/../includes/header.php';
?>

<!-- Alerta de atualização -->
<div style="background:rgba(220,38,38,.12);border:1.5px solid rgba(220,38,38,.3);border-radius:12px;padding:1rem 1.25rem;margin-bottom:1.25rem;display:flex;align-items:flex-start;gap:.75rem;">
    <span style="font-size:1.3rem;flex-shrink:0;">⚠️</span>
    <div>
        <strong style="color:#fca5a5;font-size:.9rem;">Atenção:</strong>
        <span style="color:#e2e8f0;font-size:.88rem;"> é imprescindível manter seus dados atualizados no processo para evitar perdas de prazos.</span>
    </div>
</div>

<?php
// Buscar foto atual
$fotoAtual = null;
try { $stmtFoto = $pdo->prepare("SELECT foto_path FROM clients WHERE id = ?"); $stmtFoto->execute([$clienteId]); $fotoAtual = $stmtFoto->fetchColumn(); } catch(Exception $e) {}
?>

<!-- Foto de Perfil -->
<div class="sv-card" style="margin-bottom:1.5rem;text-align:center;">
    <h3>Foto de Perfil</h3>
    <form id="formFoto" action="<?= sv_url('api/dados_atualizar.php?acao=foto') ?>" method="POST" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= salavip_gerar_csrf() ?>">
        <div style="margin:1rem 0;">
            <?php if ($fotoAtual): ?>
                <img src="<?= sv_url('uploads/' . $fotoAtual) ?>" alt="Sua foto" class="sv-avatar" id="fotoPreview" style="width:120px;height:120px;">
            <?php else: ?>
                <div class="sv-avatar-placeholder" id="fotoPlaceholder" style="width:120px;height:120px;font-size:2.5rem;margin:0 auto;">&#x1F464;</div>
                <img src="" alt="" class="sv-avatar" id="fotoPreview" style="width:120px;height:120px;display:none;">
            <?php endif; ?>
        </div>
        <label class="sv-btn sv-btn-outline" style="cursor:pointer;display:inline-flex;align-items:center;gap:6px;">
            &#x1F4F7; <?= $fotoAtual ? 'Alterar foto' : 'Enviar foto' ?>
            <input type="file" name="foto" accept="image/jpeg,image/png,image/webp" onchange="previewFoto(this)" style="display:none;">
        </label>
        <p style="color:var(--sv-text-muted);font-size:.75rem;margin-top:.5rem;">JPG, PNG ou WebP &mdash; m&aacute;ximo 5MB</p>
    </form>
</div>

<!-- Dados Pessoais -->
<div class="sv-card" style="margin-bottom:1.5rem;">
    <h3>Dados Pessoais</h3>
    <?php if ($cliente): ?>
        <div style="display:flex;flex-direction:column;gap:1rem;">
            <div>
                <div style="color:#94a3b8;font-size:.8rem;margin-bottom:.15rem;">Nome</div>
                <div style="color:#e2e8f0;"><?= sv_e($cliente['name'] ?? '-') ?></div>
            </div>
            <div>
                <div style="color:#94a3b8;font-size:.8rem;margin-bottom:.15rem;">CPF</div>
                <div style="color:#e2e8f0;"><?= !empty($cliente['cpf']) ? sv_e(sv_formatar_cpf($cliente['cpf'])) : '-' ?></div>
            </div>
            <div>
                <div style="color:#94a3b8;font-size:.8rem;margin-bottom:.15rem;">E-mail</div>
                <div style="color:#e2e8f0;"><?= sv_e($cliente['email'] ?? '-') ?></div>
            </div>
            <div>
                <div style="color:#94a3b8;font-size:.8rem;margin-bottom:.15rem;">Telefone</div>
                <div style="color:#e2e8f0;"><?= sv_e($cliente['phone'] ?? '-') ?></div>
            </div>
            <div>
                <div style="color:#94a3b8;font-size:.8rem;margin-bottom:.15rem;">Data de Nascimento</div>
                <div style="color:#e2e8f0;"><?= !empty($cliente['birth_date']) ? sv_formatar_data($cliente['birth_date']) : '-' ?></div>
            </div>
            <?php
            $enderecoParts = [];
            if (!empty($cliente['address_street']))  $enderecoParts[] = $cliente['address_street'];
            if (!empty($cliente['address_city']))    $enderecoParts[] = $cliente['address_city'];
            if (!empty($cliente['address_state']))   $enderecoParts[] = $cliente['address_state'];
            if (!empty($cliente['address_zip']))     $enderecoParts[] = 'CEP ' . $cliente['address_zip'];
            $enderecoCompleto = !empty($enderecoParts) ? implode(', ', $enderecoParts) : '-';
            ?>
            <div>
                <div style="color:#94a3b8;font-size:.8rem;margin-bottom:.15rem;">Endereço</div>
                <div style="color:#e2e8f0;"><?= sv_e($enderecoCompleto) ?></div>
            </div>
        </div>
        <div style="margin-top:1.25rem;display:flex;gap:.75rem;align-items:center;flex-wrap:wrap;">
            <span style="color:#94a3b8;font-size:.85rem;">Para alterar seus dados:</span>
            <a href="https://wa.me/5524992050096?text=Ol%C3%A1!%20Preciso%20atualizar%20meus%20dados%20cadastrais." target="_blank"
               style="display:inline-flex;align-items:center;gap:6px;background:#25D366;color:#fff;padding:8px 16px;border-radius:8px;text-decoration:none;font-size:.85rem;font-weight:600;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="#fff"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/><path d="M12 2C6.477 2 2 6.477 2 12c0 1.89.525 3.66 1.438 5.168L2 22l4.832-1.438A9.955 9.955 0 0012 22c5.523 0 10-4.477 10-10S17.523 2 12 2zm0 18a8 8 0 01-4.29-1.234l-.307-.184-2.87.852.852-2.87-.184-.307A8 8 0 1112 20z"/></svg>
                Enviar WhatsApp
            </a>
        </div>
    <?php else: ?>
        <p class="sv-empty">Dados n&atilde;o encontrados.</p>
    <?php endif; ?>
</div>

<!-- Alterar Senha -->
<div class="sv-card">
    <h3>Alterar Senha</h3>
    <form action="<?= sv_url('api/dados_atualizar.php?acao=senha') ?>" method="POST">
        <input type="hidden" name="csrf_token" value="<?= salavip_gerar_csrf() ?>">

        <div class="form-group">
            <label class="form-label">Senha Atual *</label>
            <input type="password" name="senha_atual" class="form-input" required autocomplete="current-password">
        </div>

        <div class="form-group">
            <label class="form-label">Nova Senha *</label>
            <input type="password" name="nova_senha" id="novaSenha" class="form-input" required minlength="8" autocomplete="new-password" oninput="atualizarForca(this.value)">
            <div style="margin-top:.5rem;height:6px;background:#1e293b;border-radius:3px;overflow:hidden;">
                <div id="forcaBarra" style="height:100%;width:0;transition:width .3s,background .3s;border-radius:3px;"></div>
            </div>
            <div id="forcaTexto" style="font-size:.75rem;margin-top:.25rem;color:#64748b;"></div>
        </div>

        <div class="form-group">
            <label class="form-label">Confirmar Nova Senha *</label>
            <input type="password" name="confirmar_senha" class="form-input" required minlength="8" autocomplete="new-password">
        </div>

        <div style="margin-top:1rem;">
            <button type="submit" class="sv-btn sv-btn-gold">Alterar Senha</button>
        </div>
    </form>
</div>

<script>
function atualizarForca(senha) {
    var forca = 0;
    if (senha.length >= 8)  forca++;
    if (senha.length >= 12) forca++;
    if (/[A-Z]/.test(senha)) forca++;
    if (/[0-9]/.test(senha)) forca++;
    if (/[^A-Za-z0-9]/.test(senha)) forca++;

    var barra = document.getElementById('forcaBarra');
    var texto = document.getElementById('forcaTexto');
    var niveis = [
        { pct: '0%',   cor: '#dc2626', label: '' },
        { pct: '20%',  cor: '#dc2626', label: 'Muito fraca' },
        { pct: '40%',  cor: '#f59e0b', label: 'Fraca' },
        { pct: '60%',  cor: '#f59e0b', label: 'Razo\u00e1vel' },
        { pct: '80%',  cor: '#059669', label: 'Forte' },
        { pct: '100%', cor: '#059669', label: 'Muito forte' }
    ];
    var n = niveis[forca];
    barra.style.width = n.pct;
    barra.style.background = n.cor;
    texto.textContent = n.label;
    texto.style.color = n.cor;
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
