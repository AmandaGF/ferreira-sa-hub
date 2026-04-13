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
            if (!empty($cliente['address']))      $enderecoParts[] = $cliente['address'];
            if (!empty($cliente['address_number'])) $enderecoParts[] = $cliente['address_number'];
            if (!empty($cliente['complement']))    $enderecoParts[] = $cliente['complement'];
            if (!empty($cliente['neighborhood']))  $enderecoParts[] = $cliente['neighborhood'];
            if (!empty($cliente['city']))           $enderecoParts[] = $cliente['city'];
            if (!empty($cliente['state']))          $enderecoParts[] = $cliente['state'];
            if (!empty($cliente['zip_code']))       $enderecoParts[] = 'CEP ' . $cliente['zip_code'];
            $enderecoCompleto = !empty($enderecoParts) ? implode(', ', $enderecoParts) : '-';
            ?>
            <div>
                <div style="color:#94a3b8;font-size:.8rem;margin-bottom:.15rem;">Endere&ccedil;o</div>
                <div style="color:#e2e8f0;"><?= sv_e($enderecoCompleto) ?></div>
            </div>
        </div>
        <div style="margin-top:1.25rem;padding:.75rem;background:rgba(201,169,78,.08);border:1px solid rgba(201,169,78,.2);border-radius:.5rem;">
            <p style="color:#c9a94e;margin:0;font-size:.85rem;">
                Para alterar seus dados, entre em contato conosco pelo menu Mensagens ou ligue para o escrit&oacute;rio.
            </p>
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
