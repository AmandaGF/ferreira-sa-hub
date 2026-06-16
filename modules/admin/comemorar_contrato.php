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
        // Amanda 16/06/2026: append @g.us se faltar — IDs salvos puros nao
        // funcionam (Z-API gera ID sintetico 3EB0... e nao entrega).
        $g = trim($_POST['grupo_id'] ?? '');
        if ($g !== '' && strpos($g, '@') === false) $g = $g . '@g.us';
        _comemo_set($pdo, 'comemoracao_contrato_grupo_id', $g);
        _comemo_set($pdo, 'comemoracao_contrato_template', trim($_POST['template'] ?? ''));
        flash_set('success', 'Configuração salva. Teste enviando uma mensagem teste antes de ativar de vez!');
        redirect($_SERVER['REQUEST_URI']);
    }

    if ($act === 'teste') {
        // Amanda 16/06/2026: mensagem de teste é uma frase divertida do "Jorjão",
        // separada do template de producao (que tem cliente/vendedor de verdade).
        // Assim a equipe entende que eh teste e nao confunde com contrato real.
        $cfg = comemoracao_get_config();
        if ($cfg['ativada'] !== '1' && empty($_POST['forcar_mesmo_desligado'])) {
            // Permite testar mesmo desligado — eh o objetivo do botao
        }
        if (!$cfg['grupo_id']) {
            flash_set('error', '⚠️ Configure o grupo do WhatsApp primeiro (clique num grupo da lista e salve).');
            redirect($_SERVER['REQUEST_URI']);
        }
        if (!in_array($cfg['canal'], array('21','24'), true)) {
            flash_set('error', '⚠️ Canal inválido. Selecione 21 ou 24 e salve.');
            redirect($_SERVER['REQUEST_URI']);
        }

        require_once APP_ROOT . '/core/functions_zapi.php';
        $msgTeste = "🔔 *Fala pessoal! Jorjão tá na área dnovo!* 🔔\n\nBora lá?! 🚀\n\n_(mensagem de teste do sino de comemoração — está tudo funcionando!)_";
        $r = zapi_send_text($cfg['canal'], $cfg['grupo_id'], $msgTeste);

        if (!empty($r['ok'])) {
            flash_set('success', '✓ Mensagem TESTE do Jorjão enviada! Confira o grupo no WhatsApp.');
        } else {
            flash_set('error', '⚠️ Falhou ao enviar: ' . ($r['erro'] ?? 'desconhecido'));
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

// Amanda 16/06/2026: lista grupos disponiveis por canal, agrupados
$gruposCanal = array('21' => array(), '24' => array());
try {
    $st = $pdo->query("SELECT co.id, co.telefone, co.nome_contato,
                              i.ddd AS canal,
                              (SELECT created_at FROM zapi_mensagens m WHERE m.conversa_id = co.id ORDER BY m.created_at DESC LIMIT 1) AS ultima_msg
                       FROM zapi_conversas co
                       JOIN zapi_instancias i ON i.id = co.instancia_id
                       WHERE COALESCE(co.eh_grupo, 0) = 1
                       ORDER BY co.nome_contato ASC");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $g) {
        $ch = (string)$g['canal'];
        if (!isset($gruposCanal[$ch])) $gruposCanal[$ch] = array();
        $gruposCanal[$ch][] = $g;
    }
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
                    <input type="text" name="grupo_id" id="grupoIdInput" class="form-input"
                           value="<?= e($cfg['grupo_id']) ?>"
                           placeholder="Selecione um grupo da lista abaixo ou cole o ID manualmente">
                    <small style="color:#6b7280;font-size:.74rem;">
                        🎯 <strong>Atalho:</strong> os grupos onde o canal selecionado já participa aparecem na lista abaixo —
                        é só clicar pra preencher.
                    </small>

                    <!-- Lista de grupos disponiveis por canal -->
                    <div style="margin-top:.75rem;">
                        <?php foreach (array('21'=>'(21) Comercial','24'=>'(24) CX / Operacional') as $ch => $lbl): ?>
                            <?php $gs = $gruposCanal[$ch] ?? array(); ?>
                            <div style="margin-bottom:.6rem;">
                                <div style="font-size:.72rem;font-weight:700;color:#475569;margin-bottom:.3rem;">
                                    📞 <?= $lbl ?> <span style="background:#e2e8f0;color:#475569;padding:1px 7px;border-radius:8px;font-size:.65rem;margin-left:4px;"><?= count($gs) ?> grupo<?= count($gs)===1?'':'s' ?></span>
                                </div>
                                <?php if (!$gs): ?>
                                    <div style="font-size:.74rem;color:#94a3b8;font-style:italic;padding:.4rem .6rem;background:#f8fafc;border:1px dashed #cbd5e1;border-radius:6px;">
                                        Nenhum grupo encontrado neste canal. Peça pra alguém adicionar o número (<?= $ch ?>) ao grupo do escritório e mandar uma mensagem lá — depois recarrega esta página.
                                    </div>
                                <?php else: ?>
                                    <div style="display:flex;flex-direction:column;gap:.3rem;">
                                        <?php foreach ($gs as $g): ?>
                                            <button type="button"
                                                    onclick="grupoSelecionar('<?= e($g['telefone']) ?>', this)"
                                                    style="text-align:left;background:#f0f9ff;border:1px solid #bae6fd;padding:.4rem .65rem;border-radius:6px;cursor:pointer;font-size:.78rem;display:flex;justify-content:space-between;align-items:center;gap:.5rem;">
                                                <span>
                                                    <strong style="color:#0c4a6e;">👥 <?= e($g['nome_contato'] ?: '(grupo sem nome)') ?></strong>
                                                    <div style="font-size:.65rem;color:#64748b;font-family:ui-monospace,monospace;margin-top:1px;"><?= e($g['telefone']) ?></div>
                                                </span>
                                                <span style="color:#0ea5e9;font-size:.7rem;font-weight:600;">Usar este ↗</span>
                                            </button>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <script>
                function grupoSelecionar(telefone, btn) {
                    document.getElementById('grupoIdInput').value = telefone;
                    // Marca o botão como selecionado
                    document.querySelectorAll('#grupoIdInput').forEach(function(){});
                    document.querySelectorAll('[onclick^="grupoSelecionar"]').forEach(function(b){
                        b.style.background = '#f0f9ff';
                        b.style.borderColor = '#bae6fd';
                    });
                    btn.style.background = '#dcfce7';
                    btn.style.borderColor = '#10b981';
                    // Rola pro input pra usuário ver
                    document.getElementById('grupoIdInput').scrollIntoView({behavior:'smooth', block:'center'});
                    document.getElementById('grupoIdInput').focus();
                }
                </script>
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
            <small style="color:#6b7280;font-size:.74rem;">Manda uma frase do Jorjão pra equipe saber que é teste: <em>"Fala pessoal! Jorjão tá na área dnovo! Bora lá?!"</em></small>
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
