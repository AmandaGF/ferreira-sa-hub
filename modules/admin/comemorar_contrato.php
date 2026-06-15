<?php
/**
 * Ferreira & Sá Hub — Admin: Comemorar Contrato Assinado
 * Configuração do "🔔 sino" — mensagem automática no grupo WhatsApp do
 * escritório quando um lead vai pra stage contrato_assinado.
 *
 * Amanda 15/06/2026.
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();
if (!has_min_role('admin')) {
    flash_set('error', 'Acesso restrito.');
    redirect(url('modules/dashboard/index.php'));
}

require_once APP_ROOT . '/core/functions_comemoracao.php';

$pdo = db();
$pageTitle = '🔔 Comemorar Contrato';

// Helper salvar config
function _comemo_set($pdo, $chave, $valor) {
    $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES (?, ?)
                   ON DUPLICATE KEY UPDATE valor = VALUES(valor)")
        ->execute(array($chave, $valor));
}

// ── POST ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) { flash_set('error', 'CSRF inválido'); redirect($_SERVER['REQUEST_URI']); }
    $act = $_POST['action'] ?? '';

    if ($act === 'salvar') {
        _comemo_set($pdo, 'comemoracao_contrato_ativada', !empty($_POST['ativada']) ? '1' : '0');
        $canal = $_POST['canal'] ?? '21';
        if (!in_array($canal, array('21','24'), true)) $canal = '21';
        _comemo_set($pdo, 'comemoracao_contrato_canal', $canal);
        _comemo_set($pdo, 'comemoracao_contrato_grupo_id', trim($_POST['grupo_id'] ?? ''));
        _comemo_set($pdo, 'comemoracao_contrato_template', trim($_POST['template'] ?? ''));
        flash_set('success', 'Configuração salva. Teste enviando uma mensagem teste antes de ativar de vez!');
        redirect($_SERVER['REQUEST_URI']);
    }

    if ($act === 'teste') {
        $fakeLead = array(
            'name' => 'CLIENTE TESTE (apenas teste do sino)',
            'case_type' => 'Pensão Alimentícia',
            'estimated_value_cents' => 350000,
            'honorarios_cents' => 350000,
            'assigned_to' => current_user_id(),
        );
        $r = comemorar_contrato_assinado($fakeLead);
        if (!empty($r['ok'])) {
            flash_set('success', '✓ Mensagem teste enviada! Confira o grupo no WhatsApp.');
        } else {
            flash_set('error', '⚠️ Falhou: ' . ($r['erro'] ?? 'desconhecido'));
        }
        redirect($_SERVER['REQUEST_URI']);
    }
}

// ── Estado atual ───────────────────────────────────────────
$cfg = comemoracao_get_config();
$hist = array();
try {
    $j = (string)$pdo->query("SELECT valor FROM configuracoes WHERE chave='comemoracao_contrato_log'")->fetchColumn();
    $hist = json_decode($j, true) ?: array();
} catch (Throwable $e) {}

require_once APP_ROOT . '/templates/layout_start.php';
?>

<a href="<?= url('modules/dashboard/index.php') ?>" class="btn btn-outline btn-sm mb-2">&larr; Voltar</a>

<div class="card mb-2">
    <div class="card-header">
        <h3>🔔 Comemorar Contrato Assinado no grupo WhatsApp</h3>
    </div>
    <div class="card-body">
        <p style="font-size:.88rem;color:#475569;margin-bottom:1rem;">
            Toda vez que um lead vai pra <strong>contrato assinado</strong> no Pipeline, o sistema manda
            automaticamente uma mensagem de comemoração no grupo do WhatsApp do escritório.
            Use as variáveis no template: <code style="background:#f3f4f6;padding:1px 5px;border-radius:3px;">[cliente]</code>
            <code style="background:#f3f4f6;padding:1px 5px;border-radius:3px;">[comercial]</code>
            <code style="background:#f3f4f6;padding:1px 5px;border-radius:3px;">[valor]</code>
            <code style="background:#f3f4f6;padding:1px 5px;border-radius:3px;">[tipo_caso]</code>
            <code style="background:#f3f4f6;padding:1px 5px;border-radius:3px;">[hoje]</code>.
        </p>

        <form method="POST">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="salvar">

            <div class="form-group">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:600;">
                    <input type="checkbox" name="ativada" value="1" <?= $cfg['ativada'] === '1' ? 'checked' : '' ?>>
                    <span style="font-size:1rem;">🔔 Ativar comemoração automática</span>
                </label>
                <small style="color:#6b7280;font-size:.74rem;">Quando desligado, o gatilho fica inerte mas você ainda pode fazer testes.</small>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Canal Z-API que envia</label>
                    <select name="canal" class="form-input">
                        <option value="21" <?= $cfg['canal']==='21'?'selected':'' ?>>📞 (21) Comercial</option>
                        <option value="24" <?= $cfg['canal']==='24'?'selected':'' ?>>📞 (24) CX / Operacional</option>
                    </select>
                    <small style="color:#6b7280;font-size:.74rem;">Esse número precisa estar dentro do grupo do WhatsApp.</small>
                </div>
                <div class="form-group">
                    <label class="form-label">ID do grupo WhatsApp</label>
                    <input type="text" name="grupo_id" class="form-input"
                           value="<?= e($cfg['grupo_id']) ?>"
                           placeholder="Ex.: 5524999999999-1234567890@g.us">
                    <small style="color:#6b7280;font-size:.74rem;">
                        Como descobrir: abra o grupo no Hub (WhatsApp → busque pelo nome do grupo), depois copie o telefone que aparece no chip
                        (algo terminado em <code>@g.us</code>). Ou peça pra alguém mandar uma msg no grupo e olhe o webhook.
                    </small>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Mensagem (template)</label>
                <textarea name="template" class="form-input" rows="8"
                          style="font-family:ui-monospace,Consolas,monospace;font-size:.85rem;"><?= e($cfg['template']) ?></textarea>
                <small style="color:#6b7280;font-size:.74rem;">
                    Use *negrito* (asteriscos) e _itálico_ (underline) pra formatação do WhatsApp. Variáveis: [cliente], [comercial], [valor], [tipo_caso], [hoje].
                </small>
            </div>

            <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
                <button type="submit" class="btn btn-primary">💾 Salvar configuração</button>
            </div>
        </form>

        <hr style="margin:1.2rem 0;border:none;border-top:1px solid #e5e7eb;">

        <form method="POST" onsubmit="return confirm('Mandar uma mensagem TESTE no grupo agora? Vai aparecer pra todos do grupo.');" style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="teste">
            <button type="submit" class="btn btn-outline" style="background:#fef3c7;border-color:#f59e0b;color:#7c2d12;">🧪 Enviar mensagem TESTE no grupo</button>
            <small style="color:#6b7280;font-size:.74rem;">Usa cliente fictício "CLIENTE TESTE" + valor R$ 3.500,00.</small>
        </form>
    </div>
</div>

<?php if (!empty($hist)): ?>
<div class="card">
    <div class="card-header"><h3>📜 Últimas tentativas (10 últimas)</h3></div>
    <div class="card-body" style="padding:0;">
        <table style="width:100%;border-collapse:collapse;font-size:.82rem;">
            <thead>
                <tr style="background:#f8fafc;border-bottom:1px solid #e5e7eb;">
                    <th style="padding:.5rem .75rem;text-align:left;">Data/Hora</th>
                    <th style="padding:.5rem .75rem;text-align:left;">Cliente</th>
                    <th style="padding:.5rem .75rem;text-align:left;">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($hist as $h): ?>
                <tr style="border-bottom:1px solid #f3f4f6;">
                    <td style="padding:.5rem .75rem;color:#6b7280;font-family:ui-monospace,monospace;font-size:.78rem;"><?= e($h['em']) ?></td>
                    <td style="padding:.5rem .75rem;"><?= e($h['cliente']) ?></td>
                    <td style="padding:.5rem .75rem;">
                        <?php if (!empty($h['ok'])): ?>
                            <span style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:10px;font-size:.7rem;font-weight:600;">✓ enviada</span>
                        <?php else: ?>
                            <span style="background:#fee2e2;color:#991b1b;padding:2px 8px;border-radius:10px;font-size:.7rem;font-weight:600;" title="<?= e($h['erro']) ?>">⚠️ falhou</span>
                            <small style="color:#991b1b;font-size:.7rem;margin-left:6px;"><?= e($h['erro']) ?></small>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
