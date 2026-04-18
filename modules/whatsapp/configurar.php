<?php
/**
 * Ferreira & Sá Hub — Configuração Z-API (Admin/Gestão)
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
if (!has_min_role('gestao')) {
    flash_set('error', 'Acesso restrito.');
    redirect(url('modules/whatsapp/'));
}

require_once APP_ROOT . '/core/functions_zapi.php';
$pdo = db();
$pageTitle = 'Configurar Z-API';

// Garantir tabela configuracoes existe
try { $pdo->query("SELECT 1 FROM configuracoes LIMIT 1"); }
catch (Exception $e) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS configuracoes (
        chave VARCHAR(80) PRIMARY KEY,
        valor TEXT,
        atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// ── POST ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
    $baseUrl     = trim($_POST['base_url'] ?? 'https://api.z-api.io/instances');
    $clientToken = trim($_POST['client_token'] ?? '');

    $upCfg = $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)");
    $upCfg->execute(array('zapi_base_url', $baseUrl));
    $upCfg->execute(array('zapi_client_token', $clientToken));

    // Instâncias por DDD
    $upInst = $pdo->prepare("UPDATE zapi_instancias SET instancia_id = ?, token = ? WHERE ddd = ?");
    foreach (array('21', '24') as $ddd) {
        $iid = trim($_POST["inst_{$ddd}_id"] ?? '');
        $tok = trim($_POST["inst_{$ddd}_token"] ?? '');
        $upInst->execute(array($iid, $tok, $ddd));
    }

    audit_log('zapi_configurar', 'configuracoes', 0, 'Credenciais Z-API atualizadas');
    flash_set('success', 'Credenciais Z-API salvas.');
    redirect(module_url('whatsapp', 'configurar.php'));
}

$cfg = zapi_get_config();
$inst21 = zapi_get_instancia('21') ?: array();
$inst24 = zapi_get_instancia('24') ?: array();

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.zcfg-card { background:#fff;border:1px solid var(--border);border-radius:14px;padding:1.5rem;margin-bottom:1rem; }
.zcfg-card h3 { margin:0 0 .5rem;color:var(--petrol-900); }
.zcfg-webhook { background:#f3f4f6;padding:.5rem .75rem;border-radius:6px;font-family:monospace;font-size:.75rem;user-select:all; }
.zcfg-row { display:grid;grid-template-columns:1fr 1fr;gap:.8rem;margin-bottom:.8rem; }
.zcfg-row label { display:block;font-size:.75rem;font-weight:600;color:var(--text-muted);margin-bottom:.2rem; }
.zcfg-row input { width:100%; }
.zcfg-mask { font-family:monospace;font-size:.8rem; }
</style>

<a href="<?= module_url('whatsapp') ?>" class="btn btn-outline btn-sm mb-2">&larr; Voltar</a>

<h1>⚙️ Configurar Z-API</h1>
<p class="text-sm text-muted">Credenciais ficam no banco (tabela <code>configuracoes</code> + <code>zapi_instancias</code>). Nunca são commitadas no git.</p>

<form method="POST">
    <?= csrf_input() ?>

    <div class="zcfg-card">
        <h3>🌐 Conta Z-API</h3>
        <div class="zcfg-row">
            <div>
                <label>Base URL</label>
                <input type="text" name="base_url" value="<?= e($cfg['base_url']) ?>" class="form-control">
            </div>
            <div>
                <label>Client-Token (conta) <span style="color:var(--danger);">*</span></label>
                <input type="text" name="client_token" value="<?= e($cfg['client_token']) ?>" class="form-control" placeholder="Token de segurança da conta (Z-API → Segurança → item 3)">
            </div>
        </div>
        <p class="text-sm text-muted" style="margin:0;">Se o token de segurança estiver <strong>inativo</strong> na Z-API, deixe em branco — a API aceita chamadas sem ele (menos seguro, mas funciona).</p>
    </div>

    <div class="zcfg-card">
        <h3>📱 Instância DDD 21 (Comercial)</h3>
        <div class="zcfg-webhook">
            Webhook: https://ferreiraesa.com.br/conecta/api/zapi_webhook.php?numero=21
        </div>
        <p class="text-sm text-muted" style="margin:.5rem 0;">Configure essa URL no painel Z-API → Instância 21 → <strong>Webhooks → Ao receber</strong>. Marque "Enviar mensagens recebidas por mim".</p>
        <div class="zcfg-row">
            <div>
                <label>ID da instância</label>
                <input type="text" name="inst_21_id" value="<?= e($inst21['instancia_id'] ?? '') ?>" class="form-control" placeholder="Ex: 3F1DA9B3B392A294C050B20DE66F3711">
            </div>
            <div>
                <label>Token da instância</label>
                <input type="text" name="inst_21_token" value="<?= e($inst21['token'] ?? '') ?>" class="form-control" placeholder="Ex: 766AC01ED7C50D927A5D2A60">
            </div>
        </div>
    </div>

    <div class="zcfg-card">
        <h3>📱 Instância DDD 24 (CX/Operacional)</h3>
        <div class="zcfg-webhook">
            Webhook: https://ferreiraesa.com.br/conecta/api/zapi_webhook.php?numero=24
        </div>
        <p class="text-sm text-muted" style="margin:.5rem 0;">Ainda não criada. Quando criar a segunda instância, preencha aqui.</p>
        <div class="zcfg-row">
            <div>
                <label>ID da instância</label>
                <input type="text" name="inst_24_id" value="<?= e($inst24['instancia_id'] ?? '') ?>" class="form-control" placeholder="(deixar vazio por enquanto)">
            </div>
            <div>
                <label>Token da instância</label>
                <input type="text" name="inst_24_token" value="<?= e($inst24['token'] ?? '') ?>" class="form-control" placeholder="(deixar vazio por enquanto)">
            </div>
        </div>
    </div>

    <button type="submit" class="btn btn-primary">💾 Salvar credenciais</button>
</form>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
