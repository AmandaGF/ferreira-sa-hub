<?php
/**
 * Ferreira & Sá Hub — Motor de Fluxos do WhatsApp (lista + criar)
 *
 * UI funcional v1: tabela de fluxos + form de criar novo. Edição
 * detalhada de blocos/arestas vive em fluxo_ver.php?id=N.
 *
 * Acesso: gestão+ (admin, gestao). Cada ação é auditada.
 */

require_once __DIR__ . '/../../core/middleware.php';
require_once __DIR__ . '/../../core/functions_fluxos.php';
require_login();
require_min_role('gestao');

$pageTitle = 'Fluxos WhatsApp';
$pdo = db();

// ── POST handlers ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'criar') {
        $nome = trim($_POST['nome'] ?? '');
        $descricao = trim($_POST['descricao'] ?? '');
        $canal = trim($_POST['canal'] ?? '');
        $gatilho = trim($_POST['gatilho_tipo'] ?? 'manual');
        if ($nome === '') {
            flash_set('error', 'Nome é obrigatório.');
        } else {
            $pdo->prepare(
                "INSERT INTO zapi_fluxo (nome, descricao, canal, gatilho_tipo, ativo, criado_por, criado_em)
                 VALUES (?, ?, ?, ?, 0, ?, NOW())"
            )->execute(array(
                $nome, $descricao ?: null, $canal ?: null, $gatilho, current_user_id()
            ));
            $newId = (int)$pdo->lastInsertId();
            audit_log('zapi_fluxo_criar', 'zapi_fluxo', $newId, $nome);
            flash_set('success', "Fluxo \"$nome\" criado (id=$newId). Está INATIVO — adicione blocos e ative quando pronto.");
            redirect(module_url('whatsapp', 'fluxo_ver.php?id=' . $newId));
        }
        redirect(module_url('whatsapp', 'fluxos.php'));
    }

    if ($action === 'toggle_ativo') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("UPDATE zapi_fluxo SET ativo = 1 - ativo WHERE id = ?")->execute(array($id));
            audit_log('zapi_fluxo_toggle', 'zapi_fluxo', $id);
            flash_set('success', 'Estado alterado.');
        }
        redirect(module_url('whatsapp', 'fluxos.php'));
    }

    if ($action === 'excluir') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $st = $pdo->prepare("SELECT nome FROM zapi_fluxo WHERE id = ?");
            $st->execute(array($id));
            $nome = (string)$st->fetchColumn();
            // ON DELETE CASCADE remove blocos, arestas e execucoes
            $pdo->prepare("DELETE FROM zapi_fluxo WHERE id = ?")->execute(array($id));
            audit_log('zapi_fluxo_excluir', 'zapi_fluxo', $id, $nome);
            flash_set('success', "Fluxo \"$nome\" excluído (cascade: blocos, arestas, execuções).");
        }
        redirect(module_url('whatsapp', 'fluxos.php'));
    }
}

// ── Carrega lista + estado do killswitch ────────────────
$fluxos = $pdo->query(
    "SELECT f.*,
            (SELECT COUNT(*) FROM zapi_fluxo_bloco WHERE fluxo_id = f.id) AS qtd_blocos,
            (SELECT COUNT(*) FROM zapi_fluxo_execucao WHERE fluxo_id = f.id AND estado IN ('em_andamento','aguardando')) AS execucoes_vivas
       FROM zapi_fluxo f
     ORDER BY f.id DESC"
)->fetchAll();

$killswitchAtivo = false;
try {
    $st = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'zapi_fluxo_executor_ativo' LIMIT 1");
    $st->execute();
    $killswitchAtivo = ((string)$st->fetchColumn() === '1');
} catch (Exception $e) {}

require_once APP_ROOT . '/templates/layout_start.php';
?>
<style>
.fxl-card { background:var(--bg-card); border:1px solid var(--border); border-radius:12px; padding:1.25rem; margin-bottom:1rem; }
.fxl-tbl { width:100%; border-collapse:collapse; font-size:.85rem; }
.fxl-tbl th { background:var(--petrol-900); color:#fff; padding:.55rem .75rem; text-align:left; font-size:.7rem; text-transform:uppercase; letter-spacing:.5px; }
.fxl-tbl td { padding:.55rem .75rem; border-bottom:1px solid var(--border); vertical-align:middle; }
.fxl-tbl tr:hover { background:#fafbfc; }
.fxl-badge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:.65rem; font-weight:700; }
.fxl-badge.on { background:#dcfce7; color:#166534; }
.fxl-badge.off { background:#f3f4f6; color:#6b7280; }
.fxl-form input, .fxl-form select, .fxl-form textarea {
    padding:.45rem .65rem; border:1.5px solid var(--border); border-radius:6px; font-size:.85rem; font-family:inherit;
}
</style>

<a href="<?= module_url('whatsapp') ?>" class="btn btn-outline btn-sm mb-2">&larr; Voltar ao WhatsApp</a>

<div style="display:flex;align-items:center;gap:.5rem;margin-bottom:1rem;">
    <h1 style="margin:0;">🌊 Fluxos do WhatsApp</h1>
    <span style="font-size:.7rem;color:#6b7280;font-style:italic;">motor zapi_fluxo*</span>
</div>

<!-- Killswitch -->
<div class="fxl-card" style="<?= $killswitchAtivo ? 'border-color:#0d9488;background:#ecfeff;' : 'border-color:#f59e0b;background:#fffbeb;' ?>">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:.5rem;flex-wrap:wrap;">
        <div>
            <strong style="color:<?= $killswitchAtivo ? '#0f766e' : '#92400e' ?>;">
                <?= $killswitchAtivo ? '🟢 Executor LIGADO' : '🟡 Executor DESLIGADO' ?>
            </strong>
            <p style="margin:.25rem 0 0;font-size:.78rem;color:#475569;">
                <?= $killswitchAtivo
                    ? 'Webhook processa fluxos. Mensagens de clientes em conversas com execução viva avançam o fluxo automaticamente.'
                    : 'Nenhum fluxo é processado pelo webhook. Você pode criar/editar/testar via curl, mas clientes reais não disparam fluxo nenhum.' ?>
            </p>
        </div>
        <div style="display:flex;gap:.4rem;">
            <?php if ($killswitchAtivo): ?>
                <a href="<?= url('toggle_fluxo_executor.php?key=fsa-hub-deploy-2026&off') ?>" class="btn btn-sm" style="background:#dc2626;color:#fff;border:none;" onclick="return confirm('Desligar o executor? Webhook volta a ignorar fluxos.');">⏸ Desligar</a>
            <?php else: ?>
                <a href="<?= url('toggle_fluxo_executor.php?key=fsa-hub-deploy-2026&on') ?>" class="btn btn-primary btn-sm" onclick="return confirm('Ligar o executor? Mensagens de clientes vão começar a disparar fluxos ativos. Recomendado testar antes via curl.');">▶ Ligar</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Criar novo -->
<div class="fxl-card">
    <h3 style="margin:0 0 .75rem;font-size:.95rem;">➕ Novo Fluxo</h3>
    <form method="POST" class="fxl-form" style="display:grid;grid-template-columns:2fr 1fr 1fr auto;gap:.5rem;align-items:end;">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="criar">
        <label style="display:flex;flex-direction:column;gap:.2rem;font-size:.7rem;font-weight:600;color:#374151;">
            Nome <span style="color:#dc2626;">*</span>
            <input type="text" name="nome" maxlength="150" required placeholder="Ex: Captura inicial de Família">
        </label>
        <label style="display:flex;flex-direction:column;gap:.2rem;font-size:.7rem;font-weight:600;color:#374151;">
            Canal
            <select name="canal">
                <option value="">Qualquer</option>
                <option value="21">DDD 21 (Comercial)</option>
                <option value="24">DDD 24 (CX)</option>
            </select>
        </label>
        <label style="display:flex;flex-direction:column;gap:.2rem;font-size:.7rem;font-weight:600;color:#374151;">
            Gatilho
            <select name="gatilho_tipo">
                <option value="manual">Manual</option>
                <option value="primeira_msg">Primeira mensagem</option>
                <option value="palavra_chave">Palavra-chave</option>
            </select>
        </label>
        <button type="submit" class="btn btn-primary">Criar</button>
    </form>
    <p style="margin:.5rem 0 0;font-size:.7rem;color:#6b7280;">
        Fluxo novo entra <strong>INATIVO</strong>. Você adiciona blocos/arestas na próxima tela e ativa quando estiver pronto.
        Gatilhos automáticos (primeira_msg, palavra_chave) ainda não estão roteados no webhook — por enquanto só dispara via
        <code>disparar_fluxo_demo.php</code> ou chamada explícita.
    </p>
</div>

<!-- Lista -->
<div class="fxl-card" style="padding:0;overflow:hidden;">
    <div style="padding:.85rem 1rem;background:#fafbfc;border-bottom:1px solid var(--border);font-size:.8rem;color:var(--text-muted);">
        <strong style="color:var(--petrol-900);"><?= count($fluxos) ?> fluxo(s)</strong> ·
        <?= count(array_filter($fluxos, function($f){return $f['ativo'];})) ?> ativos
    </div>
    <?php if (empty($fluxos)): ?>
        <p style="padding:2rem;text-align:center;color:var(--text-muted);font-size:.9rem;">
            Nenhum fluxo cadastrado ainda. Use o form acima.
        </p>
    <?php else: ?>
    <table class="fxl-tbl">
        <thead>
            <tr>
                <th style="width:50px;">ID</th>
                <th>Nome</th>
                <th style="width:90px;">Canal</th>
                <th style="width:120px;">Gatilho</th>
                <th style="width:80px;text-align:center;">Blocos</th>
                <th style="width:90px;text-align:center;">Execuções<br><span style="font-weight:400;font-size:.6rem;">(vivas / total)</span></th>
                <th style="width:80px;">Status</th>
                <th style="width:200px;">Ações</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($fluxos as $f): ?>
            <tr>
                <td style="font-family:monospace;color:#94a3b8;">#<?= (int)$f['id'] ?></td>
                <td>
                    <a href="<?= module_url('whatsapp', 'fluxo_ver.php?id=' . (int)$f['id']) ?>" style="font-weight:700;color:var(--petrol-900);text-decoration:none;">
                        <?= htmlspecialchars($f['nome']) ?>
                    </a>
                    <?php if ($f['descricao']): ?>
                        <div style="font-size:.7rem;color:#6b7280;margin-top:.1rem;"><?= htmlspecialchars(mb_substr($f['descricao'], 0, 100)) ?><?= mb_strlen($f['descricao']) > 100 ? '…' : '' ?></div>
                    <?php endif; ?>
                </td>
                <td style="font-size:.78rem;color:#475569;"><?= $f['canal'] ? htmlspecialchars($f['canal']) : '—' ?></td>
                <td style="font-size:.78rem;color:#475569;"><?= htmlspecialchars($f['gatilho_tipo']) ?></td>
                <td style="text-align:center;font-weight:700;color:<?= $f['qtd_blocos'] == 0 ? '#dc2626' : '#052228' ?>;"><?= (int)$f['qtd_blocos'] ?></td>
                <td style="text-align:center;font-size:.78rem;">
                    <strong style="color:<?= $f['execucoes_vivas'] > 0 ? '#0d9488' : '#94a3b8' ?>;"><?= (int)$f['execucoes_vivas'] ?></strong>
                    / <?= (int)$f['execucoes'] ?>
                </td>
                <td>
                    <span class="fxl-badge <?= $f['ativo'] ? 'on' : 'off' ?>"><?= $f['ativo'] ? 'ATIVO' : 'INATIVO' ?></span>
                </td>
                <td>
                    <a href="<?= module_url('whatsapp', 'fluxo_ver.php?id=' . (int)$f['id']) ?>" class="btn btn-outline btn-sm">✏️ Editar</a>
                    <form method="POST" style="display:inline;">
                        <?= csrf_input() ?>
                        <input type="hidden" name="action" value="toggle_ativo">
                        <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                        <button type="submit" class="btn btn-outline btn-sm" title="<?= $f['ativo'] ? 'Desativar' : 'Ativar' ?>" <?= $f['qtd_blocos'] == 0 && !$f['ativo'] ? 'disabled style="opacity:.4;cursor:not-allowed;" title="Sem blocos — adicione ao menos 1 antes de ativar"' : '' ?>>
                            <?= $f['ativo'] ? '⏸' : '▶' ?>
                        </button>
                    </form>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('Excluir o fluxo &quot;<?= htmlspecialchars(addslashes($f['nome'])) ?>&quot;?\n\nCascade: blocos, arestas e execuções TODAS serão removidas.');">
                        <?= csrf_input() ?>
                        <input type="hidden" name="action" value="excluir">
                        <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                        <button type="submit" class="btn btn-outline btn-sm" style="color:#dc2626;">🗑</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
