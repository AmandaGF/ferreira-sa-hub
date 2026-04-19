<?php
/**
 * Ferreira & Sá Hub — Configuração Asaas (API Key + Ambiente)
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
if (!has_role('admin')) {
    flash_set('error', 'Acesso restrito a administradores.');
    redirect(url('modules/dashboard/'));
}

$pdo = db();
$pageTitle = 'Configurar Asaas';

// Garantir tabela configuracoes existe
try { $pdo->query("SELECT 1 FROM configuracoes LIMIT 1"); }
catch (Exception $e) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS configuracoes (
        chave VARCHAR(80) PRIMARY KEY,
        valor TEXT,
        atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

$msg = null;
$msgTipo = null;

// ── POST: salvar ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();

    if (($_POST['action'] ?? '') === 'salvar') {
        $key = trim($_POST['asaas_api_key'] ?? '');
        $env = in_array($_POST['asaas_env'] ?? '', array('sandbox','production'), true) ? $_POST['asaas_env'] : 'sandbox';

        $up = $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)");
        if ($key !== '') {
            $up->execute(array('asaas_api_key', $key));
            // Nova chave: registra data de criação + expiração (Asaas: 90 dias)
            $hoje = date('Y-m-d');
            $exp  = date('Y-m-d', strtotime('+90 days'));
            $up->execute(array('asaas_api_key_created_at', $hoje));
            $up->execute(array('asaas_api_key_expires_at', $exp));
        }
        $up->execute(array('asaas_env', $env));

        audit_log('asaas_config', 'configuracoes', 0, "env={$env} key=" . substr($key, 0, 8) . '...');
        flash_set('success', 'Credenciais Asaas salvas. Testando conexão…');
        redirect(module_url('admin', 'asaas_config.php?testar=1'));
    }
    if (($_POST['action'] ?? '') === 'atualizar_expiracao') {
        $exp = trim($_POST['asaas_api_key_expires_at'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $exp)) {
            $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES ('asaas_api_key_expires_at', ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)")
                ->execute(array($exp));
            flash_set('success', 'Data de expiração atualizada.');
        } else {
            flash_set('error', 'Data inválida (formato AAAA-MM-DD)');
        }
        redirect(module_url('admin', 'asaas_config.php'));
    }
}

// ── Ler estado atual ─────────────────────────────────────
$current = array('asaas_api_key' => '', 'asaas_env' => 'sandbox', 'asaas_api_key_created_at' => '', 'asaas_api_key_expires_at' => '');
try {
    $rows = $pdo->query("SELECT chave, valor FROM configuracoes WHERE chave IN ('asaas_api_key','asaas_env','asaas_api_key_created_at','asaas_api_key_expires_at')")->fetchAll();
    foreach ($rows as $r) $current[$r['chave']] = $r['valor'];
} catch (Exception $e) {}

$diasParaExpirar = null;
if ($current['asaas_api_key_expires_at']) {
    $diasParaExpirar = (strtotime($current['asaas_api_key_expires_at']) - time()) / 86400;
    $diasParaExpirar = (int)floor($diasParaExpirar);
}

// ── Teste de conexão (se ?testar=1) ─────────────────────
$testResult = null;
if (isset($_GET['testar']) && $current['asaas_api_key']) {
    $base = $current['asaas_env'] === 'production' ? 'https://api.asaas.com/v3' : 'https://sandbox.asaas.com/api/v3';
    $ch = curl_init($base . '/finance/balance');
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => array('access_token: ' . $current['asaas_api_key'], 'Content-Type: application/json'),
        CURLOPT_SSL_VERIFYPEER => true,
    ));
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) {
        $testResult = array('ok' => false, 'msg' => 'Erro de conexão: ' . $err);
    } elseif ($code === 200) {
        $data = json_decode($body, true);
        $saldo = isset($data['balance']) ? 'R$ ' . number_format($data['balance'], 2, ',', '.') : 'OK';
        $testResult = array('ok' => true, 'msg' => "Conexão OK — Saldo: {$saldo}");
    } elseif ($code === 401) {
        $testResult = array('ok' => false, 'msg' => 'Chave inválida (HTTP 401). Ambiente pode estar errado — se a chave é de produção, mude o ambiente.');
    } else {
        $testResult = array('ok' => false, 'msg' => 'HTTP ' . $code . ' — ' . mb_substr($body, 0, 120));
    }
}

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.ac-card { background:#fff;border:1px solid var(--border);border-radius:14px;padding:1.5rem;margin-bottom:1rem; }
.ac-card h3 { margin:0 0 .5rem;color:var(--petrol-900); }
.ac-row { display:grid;grid-template-columns:1fr 1fr;gap:.8rem;margin-bottom:.8rem; }
.ac-row label { display:block;font-size:.75rem;font-weight:600;color:var(--text-muted);margin-bottom:.2rem; }
.ac-row input, .ac-row select { width:100%; }
.ac-step { padding:.7rem 1rem;background:#f9fafb;border-left:3px solid var(--rose);border-radius:6px;margin-bottom:.5rem;font-size:.85rem; }
.ac-step strong { color:var(--petrol-900); }
.ac-alert { padding:.8rem 1rem;border-radius:8px;margin-bottom:1rem;font-size:.88rem;font-weight:600; }
.ac-alert-ok { background:#dcfce7;color:#166534;border:1px solid #86efac; }
.ac-alert-err { background:#fee2e2;color:#991b1b;border:1px solid #fca5a5; }
</style>

<a href="<?= url('modules/admin/health.php') ?>" class="btn btn-outline btn-sm mb-2">&larr; Voltar ao Health Check</a>

<h1>💰 Configurar Asaas</h1>
<p class="text-sm text-muted">Credenciais salvas em <code>configuracoes</code> (DB). O Hub usa pra cobranças, assinaturas e webhook.</p>

<?php if ($testResult): ?>
    <div class="ac-alert <?= $testResult['ok'] ? 'ac-alert-ok' : 'ac-alert-err' ?>">
        <?= $testResult['ok'] ? '✅' : '❌' ?> <?= e($testResult['msg']) ?>
    </div>
<?php endif; ?>

<?php if ($current['asaas_api_key']): ?>
<div class="ac-card" style="border-left:4px solid <?= $diasParaExpirar !== null && $diasParaExpirar <= 15 ? '#ef4444' : ($diasParaExpirar !== null && $diasParaExpirar <= 30 ? '#f59e0b' : '#22c55e') ?>;">
    <h3>⏰ Validade do Token</h3>
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:1rem;align-items:end;">
        <div>
            <label style="font-size:.75rem;font-weight:600;color:var(--text-muted);">Criado em</label>
            <div style="font-weight:600;"><?= $current['asaas_api_key_created_at'] ? date('d/m/Y', strtotime($current['asaas_api_key_created_at'])) : '—' ?></div>
        </div>
        <form method="POST" style="margin:0;">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="atualizar_expiracao">
            <label style="font-size:.75rem;font-weight:600;color:var(--text-muted);">Expira em (editável)</label>
            <div style="display:flex;gap:.3rem;">
                <input type="date" name="asaas_api_key_expires_at" value="<?= e($current['asaas_api_key_expires_at']) ?>" class="form-control" style="flex:1;">
                <button type="submit" class="btn btn-outline btn-sm">Salvar</button>
            </div>
        </form>
        <div>
            <label style="font-size:.75rem;font-weight:600;color:var(--text-muted);">Faltam</label>
            <div style="font-weight:700;font-size:1.2rem;color:<?= $diasParaExpirar !== null && $diasParaExpirar <= 15 ? '#991b1b' : ($diasParaExpirar !== null && $diasParaExpirar <= 30 ? '#92400e' : '#166534') ?>;">
                <?= $diasParaExpirar !== null ? ($diasParaExpirar >= 0 ? $diasParaExpirar . ' dias' : 'VENCIDO há ' . abs($diasParaExpirar) . ' dias') : '—' ?>
            </div>
        </div>
    </div>
    <p class="text-sm text-muted" style="margin-top:.8rem;">💡 Quando faltar 15 dias, a Amanda recebe alerta automático no topo da tela para gerar uma chave nova.</p>
</div>
<?php endif; ?>

<div class="ac-card" style="border-color:#3b82f6;background:#eff6ff;">
    <h3 style="color:#1e3a8a;">📖 Como pegar sua chave API do Asaas</h3>
    <div class="ac-step">
        <strong>1.</strong> Acessa <a href="https://www.asaas.com" target="_blank" style="color:var(--rose);font-weight:700;">www.asaas.com</a> (ou <a href="https://sandbox.asaas.com" target="_blank" style="color:var(--rose);">sandbox.asaas.com</a> se for teste) e faz login.
    </div>
    <div class="ac-step">
        <strong>2.</strong> No painel, clica no seu <strong>avatar/nome no canto superior direito</strong> → <strong>"Integrações"</strong> (ou "Configurações" → "Integrações").
    </div>
    <div class="ac-step">
        <strong>3.</strong> Procura a seção <strong>"API Access Token"</strong> ou <strong>"Chave de acesso API"</strong>. Clica em <strong>"Gerar nova chave"</strong> (ou copia a existente).
    </div>
    <div class="ac-step">
        <strong>4.</strong> Copia o token completo (começa geralmente com <code>$aact_</code> ou <code>$aact_prod_</code>).
    </div>
    <div class="ac-step">
        <strong>5.</strong> Cola aqui embaixo e escolhe <strong>Produção</strong> (se é a conta real) ou <strong>Sandbox</strong> (se é teste).
    </div>
</div>

<div class="ac-card">
    <h3>🔑 Credenciais</h3>
    <form method="POST">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="salvar">

        <div class="ac-row">
            <div>
                <label>Ambiente</label>
                <select name="asaas_env" class="form-control">
                    <option value="production" <?= $current['asaas_env'] === 'production' ? 'selected' : '' ?>>🟢 Produção (conta real)</option>
                    <option value="sandbox"    <?= $current['asaas_env'] === 'sandbox'    ? 'selected' : '' ?>>🟡 Sandbox (teste)</option>
                </select>
            </div>
            <div>
                <label>Chave atual (últimos 8 caracteres)</label>
                <input type="text" value="<?= $current['asaas_api_key'] ? '...' . substr($current['asaas_api_key'], -8) : '(nenhuma)' ?>" class="form-control" readonly disabled>
            </div>
        </div>

        <div>
            <label>Nova chave API (cola completa aqui)</label>
            <input type="text" name="asaas_api_key" value="" placeholder="$aact_prod_000MzkwODA2MWY2OGM..." class="form-control" style="font-family:monospace;font-size:.85rem;">
            <p class="text-sm text-muted" style="margin-top:.3rem;">Se deixar em branco e salvar, mantém a chave atual e só atualiza o ambiente.</p>
        </div>

        <div style="margin-top:1rem;display:flex;gap:.5rem;">
            <button type="submit" class="btn btn-primary">💾 Salvar e testar conexão</button>
            <?php if ($current['asaas_api_key']): ?>
                <a href="?testar=1" class="btn btn-outline">🧪 Testar conexão atual</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
