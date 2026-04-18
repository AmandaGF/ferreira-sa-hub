<?php
/**
 * Ferreira & Sá Hub — WhatsApp CRM (Z-API)
 * Inbox + Chat (placeholder — implementação completa vem no Checkpoint 1.2/1.3)
 */

require_once __DIR__ . '/../../core/middleware.php';
require_access('whatsapp');

$pageTitle = 'WhatsApp CRM';
$pdo = db();
$user = current_user();

// Verificar status de conexão das instâncias
$instancias = array();
try {
    $instancias = $pdo->query("SELECT * FROM zapi_instancias WHERE ativo = 1 ORDER BY ddd ASC")->fetchAll();
} catch (Exception $e) {
    $instancias = array();
}

// Ver se credenciais Z-API já estão definidas
$zapiConfigurado = defined('ZAPI_INSTANCE_21') && defined('ZAPI_TOKEN_21') && ZAPI_INSTANCE_21 !== '' && ZAPI_TOKEN_21 !== '';

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.zapi-setup-card { background:#fff;border:1px solid var(--border);border-radius:14px;padding:1.5rem;margin-bottom:1rem; }
.zapi-setup-card h3 { margin:0 0 .75rem;color:var(--petrol-900);font-size:1rem; }
.zapi-step { padding:.7rem 1rem;background:#f9fafb;border-left:3px solid var(--rose);border-radius:6px;margin-bottom:.5rem;font-size:.85rem; }
.zapi-step strong { color:var(--petrol-900); }
.zapi-badge { display:inline-block;padding:2px 8px;border-radius:10px;font-size:.7rem;font-weight:700; }
.zapi-badge.on { background:#dcfce7;color:#166534; }
.zapi-badge.off { background:#fee2e2;color:#991b1b; }
.zapi-instancia-row { display:flex;align-items:center;justify-content:space-between;padding:.6rem 1rem;border:1px solid var(--border);border-radius:8px;margin-bottom:.4rem;background:#fafafa; }
</style>

<div style="display:flex;gap:1rem;align-items:center;margin-bottom:1rem;flex-wrap:wrap;">
    <h1 style="margin:0;">💬 WhatsApp CRM</h1>
    <span class="badge badge-gestao">Z-API</span>
</div>

<?php if (!$zapiConfigurado): ?>
<div class="zapi-setup-card" style="border-color:#f59e0b;background:#fffbeb;">
    <h3 style="color:#92400e;">⚠️ Configuração pendente</h3>
    <p class="text-sm" style="margin:0 0 1rem;color:#78350f;">Antes de usar o módulo, é necessário criar as instâncias Z-API e configurar as credenciais.</p>

    <div class="zapi-step">
        <strong>1.</strong> Criar conta em <a href="https://z-api.io" target="_blank" style="color:var(--rose);">z-api.io</a> e criar 2 instâncias:
        <em>fs-comercial (DDD 21)</em> e <em>fs-cx (DDD 24)</em>.
    </div>
    <div class="zapi-step">
        <strong>2.</strong> Em cada instância, escanear o QR Code com o celular correspondente para conectar.
    </div>
    <div class="zapi-step">
        <strong>3.</strong> Configurar o webhook de cada instância (painel Z-API → Webhooks) para:<br>
        <code style="font-size:.75rem;">https://ferreiraesa.com.br/conecta/api/zapi_webhook.php?numero=21</code><br>
        <code style="font-size:.75rem;">https://ferreiraesa.com.br/conecta/api/zapi_webhook.php?numero=24</code>
    </div>
    <div class="zapi-step">
        <strong>4.</strong> Adicionar no <code>core/config.php</code> (abaixo de <code>ENCRYPT_KEY</code>):
        <pre style="background:#1f2937;color:#e5e7eb;padding:.75rem;border-radius:6px;font-size:.72rem;margin:.5rem 0 0;overflow-x:auto;">
define('ZAPI_BASE_URL',     'https://api.z-api.io/instances');
define('ZAPI_CLIENT_TOKEN', 'COLE_AQUI_O_CLIENT_TOKEN');
define('ZAPI_INSTANCE_21',  'COLE_AQUI_O_ID_INSTANCIA_21');
define('ZAPI_TOKEN_21',     'COLE_AQUI_O_TOKEN_INSTANCIA_21');
define('ZAPI_INSTANCE_24',  'COLE_AQUI_O_ID_INSTANCIA_24');
define('ZAPI_TOKEN_24',     'COLE_AQUI_O_TOKEN_INSTANCIA_24');</pre>
    </div>
    <div class="zapi-step">
        <strong>5.</strong> Dizer à Claude <em>"já configurei o Z-API, pode seguir pro Checkpoint 1.2"</em>.
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h3>Instâncias WhatsApp</h3>
    </div>
    <div class="card-body">
        <?php if (empty($instancias)): ?>
            <p class="text-muted text-sm">Nenhuma instância cadastrada. Execute a migração: <code>/conecta/migrar_whatsapp_zapi.php?key=fsa-hub-deploy-2026</code></p>
        <?php else: foreach ($instancias as $inst): ?>
            <div class="zapi-instancia-row">
                <div>
                    <strong><?= e($inst['nome']) ?></strong>
                    <span class="text-sm text-muted">— DDD <?= e($inst['ddd']) ?> (<?= e($inst['tipo']) ?>)</span><br>
                    <span class="text-sm text-muted">Número: <?= e($inst['numero']) ?></span>
                </div>
                <div>
                    <?php if ($inst['instancia_id'] === '' || !$zapiConfigurado): ?>
                        <span class="zapi-badge off">⚙️ Não configurado</span>
                    <?php elseif ($inst['conectado']): ?>
                        <span class="zapi-badge on">● Conectado</span>
                    <?php else: ?>
                        <span class="zapi-badge off">● Desconectado</span>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; endif; ?>
    </div>
</div>

<div class="card mt-2">
    <div class="card-header">
        <h3>Inbox de Conversas</h3>
    </div>
    <div class="card-body">
        <p class="text-muted text-sm">🚧 Implementação completa do inbox e chat será entregue no <strong>Checkpoint 1.2</strong> (webhook + lista de conversas) e <strong>1.3</strong> (chat funcional).</p>
        <p class="text-muted text-sm">Por enquanto, o módulo está preparado: banco migrado, menu na sidebar, permissões configuradas.</p>
    </div>
</div>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
