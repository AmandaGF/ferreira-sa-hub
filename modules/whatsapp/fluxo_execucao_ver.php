<?php
/**
 * Ferreira & Sá Hub — Detalhe de uma execução de fluxo
 *
 * Mostra:
 *  - cabeçalho com fluxo + conversa
 *  - log_json renderizado como timeline
 *  - valores capturados (zapi_conversa_valor)
 *  - botão "cancelar execução"
 *
 * Acesso: gestão+.
 */

require_once __DIR__ . '/../../core/middleware.php';
require_once __DIR__ . '/../../core/functions_fluxos.php';
require_login();
require_min_role('gestao');

$pdo = db();
$execId = (int)($_GET['id'] ?? 0);
if ($execId <= 0) { flash_set('error', 'ID inválido.'); redirect(module_url('whatsapp', 'fluxos.php')); }

// POST: cancelar
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
    if (($_POST['action'] ?? '') === 'cancelar') {
        fluxo_parar($execId, 'cancelado');
        audit_log('zapi_fluxo_exec_cancelar', 'zapi_fluxo_execucao', $execId);
        flash_set('success', "Execução #$execId cancelada.");
        redirect(module_url('whatsapp', 'fluxo_execucao_ver.php?id=' . $execId));
    }
}

// Carrega execução + dados
$st = $pdo->prepare(
    "SELECT e.*, f.nome AS fluxo_nome, c.telefone, c.nome_contato, c.canal AS conv_canal
       FROM zapi_fluxo_execucao e
       LEFT JOIN zapi_fluxo f ON f.id = e.fluxo_id
       LEFT JOIN zapi_conversas c ON c.id = e.conversa_id
      WHERE e.id = ?"
);
$st->execute(array($execId));
$exec = $st->fetch();
if (!$exec) { flash_set('error', "Execução #$execId não encontrada."); redirect(module_url('whatsapp', 'fluxos.php')); }

$pageTitle = 'Execução #' . $execId;

// Decodifica log
$log = array();
if (!empty($exec['log_json'])) {
    $tmp = json_decode($exec['log_json'], true);
    if (is_array($tmp)) $log = $tmp;
}

// Valores capturados nessa conversa
$valores = fluxo_valores_da_conversa((int)$exec['conversa_id']);

require_once APP_ROOT . '/templates/layout_start.php';
?>
<style>
.ex-card { background:var(--bg-card); border:1px solid var(--border); border-radius:12px; padding:1.15rem; margin-bottom:1rem; }
.ex-card h3 { margin:0 0 .75rem; font-size:.95rem; }
.ex-meta { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:.75rem; font-size:.83rem; }
.ex-meta .lbl { font-size:.65rem; color:#6b7280; text-transform:uppercase; letter-spacing:.5px; }
.ex-meta .val { font-weight:600; color:#052228; }
.ex-tl { border-left:3px solid #cbd5e1; margin-left:.5rem; padding-left:1rem; }
.ex-tl-item { position:relative; padding:.4rem 0; }
.ex-tl-item::before { content:''; position:absolute; left:-1.3rem; top:.7rem; width:10px; height:10px; border-radius:50%; background:#94a3b8; border:2px solid #fff; }
.ex-tl-item.entrada::before { background:#0d9488; }
.ex-tl-item.bloco::before { background:#3b82f6; }
.ex-tl-item.fim_implicito::before { background:#15803d; }
.ex-tl-item.erro::before { background:#dc2626; }
.ex-tl-item.saida::before { background:#7c3aed; }
.ex-tl-ts { font-size:.7rem; color:#94a3b8; font-family:monospace; }
.ex-tl-titulo { font-weight:700; font-size:.85rem; color:#052228; margin:.15rem 0; }
.ex-tl-meta { font-size:.75rem; color:#475569; }
.ex-vals { width:100%; border-collapse:collapse; font-size:.83rem; }
.ex-vals th { background:#f3f4f6; padding:.4rem .65rem; text-align:left; font-size:.65rem; text-transform:uppercase; color:#475569; }
.ex-vals td { padding:.45rem .65rem; border-bottom:1px solid #f1f5f9; vertical-align:top; }
.ex-estado-badge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:.65rem; font-weight:700; }
.ex-estado-badge.em_andamento { background:#dbeafe; color:#1e40af; }
.ex-estado-badge.aguardando { background:#fef3c7; color:#92400e; }
.ex-estado-badge.concluido { background:#dcfce7; color:#166534; }
.ex-estado-badge.cancelado { background:#f3f4f6; color:#6b7280; }
.ex-estado-badge.erro { background:#fee2e2; color:#991b1b; }
</style>

<a href="<?= module_url('whatsapp', 'fluxo_ver.php?id=' . (int)$exec['fluxo_id']) ?>" class="btn btn-outline btn-sm mb-2">&larr; Voltar ao fluxo</a>

<div style="display:flex;align-items:center;gap:.5rem;margin-bottom:1rem;flex-wrap:wrap;">
    <h1 style="margin:0;">▶️ Execução #<?= $execId ?></h1>
    <span class="ex-estado-badge <?= htmlspecialchars($exec['estado']) ?>"><?= htmlspecialchars($exec['estado']) ?></span>
    <?php if (in_array($exec['estado'], array('em_andamento','aguardando'), true)): ?>
        <form method="POST" style="margin-left:auto;" onsubmit="return confirm('Cancelar essa execução? Não envia mensagem nenhuma — só marca estado=cancelado.');">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="cancelar">
            <button type="submit" class="btn btn-outline btn-sm" style="color:#dc2626;border-color:#dc2626;">⏹ Cancelar execução</button>
        </form>
    <?php endif; ?>
</div>

<!-- Cabeçalho meta -->
<div class="ex-card">
    <div class="ex-meta">
        <div>
            <div class="lbl">Fluxo</div>
            <div class="val"><a href="<?= module_url('whatsapp', 'fluxo_ver.php?id=' . (int)$exec['fluxo_id']) ?>" style="color:#052228;"><?= htmlspecialchars($exec['fluxo_nome'] ?? '(removido)') ?></a></div>
        </div>
        <div>
            <div class="lbl">Conversa</div>
            <div class="val"><?= htmlspecialchars($exec['nome_contato'] ?: '(sem nome)') ?></div>
            <div style="font-size:.7rem;color:#94a3b8;">conv#<?= (int)$exec['conversa_id'] ?> · canal <?= htmlspecialchars($exec['conv_canal'] ?? '?') ?></div>
        </div>
        <div>
            <div class="lbl">Bloco atual</div>
            <div class="val">#<?= (int)($exec['bloco_atual_id'] ?? 0) ?: '—' ?></div>
        </div>
        <div>
            <div class="lbl">Iniciada em</div>
            <div class="val"><?= date('d/m/Y H:i:s', strtotime($exec['iniciado_em'])) ?></div>
        </div>
        <div>
            <div class="lbl">Atualizada</div>
            <div class="val"><?= date('d/m/Y H:i:s', strtotime($exec['atualizado_em'])) ?></div>
        </div>
        <div>
            <div class="lbl">Tentativas (avancar calls)</div>
            <div class="val"><?= (int)$exec['tentativas'] ?></div>
        </div>
        <?php if ($exec['aguardando_ate']): ?>
        <div>
            <div class="lbl">Aguardando até</div>
            <div class="val" style="color:#92400e;"><?= date('d/m/Y H:i', strtotime($exec['aguardando_ate'])) ?></div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Valores capturados -->
<div class="ex-card">
    <h3>📦 Valores capturados nessa conversa (<?= count($valores) ?>)</h3>
    <?php if (empty($valores)): ?>
        <p style="color:#6b7280;font-style:italic;font-size:.85rem;">Nenhum valor capturado ainda nessa conversa. Blocos <code>capturar</code> gravam aqui.</p>
    <?php else: ?>
    <table class="ex-vals">
        <thead><tr><th>Chave</th><th>Valor</th></tr></thead>
        <tbody>
        <?php foreach ($valores as $k => $v): ?>
            <tr>
                <td><code style="background:#f1f5f9;padding:1px 6px;border-radius:4px;font-size:.78rem;color:#0f766e;"><?= htmlspecialchars($k) ?></code></td>
                <td style="font-family:monospace;font-size:.82rem;"><?= htmlspecialchars($v) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p style="font-size:.7rem;color:#6b7280;margin:.5rem 0 0;">
        Os valores são da CONVERSA (não da execução), então valores de execuções anteriores aparecem aqui também.
    </p>
    <?php endif; ?>
</div>

<!-- Timeline do log -->
<div class="ex-card">
    <h3>🪜 Timeline (<?= count($log) ?> evento(s))</h3>
    <?php if (empty($log)): ?>
        <p style="color:#6b7280;font-style:italic;font-size:.85rem;">
            Sem log registrado. Execuções iniciadas antes desta versão do executor não têm timeline.
        </p>
    <?php else: ?>
    <div class="ex-tl">
        <?php foreach ($log as $item):
            $tipo = (string)($item['evento'] ?? '?');
            $klass = '';
            if ($tipo === 'avancar_entrada') $klass = 'entrada';
            elseif ($tipo === 'bloco') $klass = 'bloco';
            elseif ($tipo === 'fim_implicito') $klass = 'fim_implicito';
            elseif ($tipo === 'erro') $klass = 'erro';
            elseif ($tipo === 'avancar_saida') $klass = 'saida';
        ?>
        <div class="ex-tl-item <?= $klass ?>">
            <div class="ex-tl-ts"><?= htmlspecialchars($item['ts'] ?? '') ?></div>
            <?php if ($tipo === 'avancar_entrada'): ?>
                <div class="ex-tl-titulo">▶ Chamada do executor</div>
                <div class="ex-tl-meta">
                    Bloco inicial: #<?= (int)($item['bloco_inicio'] ?? 0) ?>
                    <?php if ($item['entrada'] !== null): ?>
                        · Entrada do usuário: <code><?= htmlspecialchars($item['entrada']) ?></code>
                    <?php else: ?>
                        · Sem entrada (timeout do cron)
                    <?php endif; ?>
                </div>
            <?php elseif ($tipo === 'bloco'): ?>
                <div class="ex-tl-titulo">
                    Bloco #<?= (int)$item['bloco_id'] ?>
                    <span class="fx-tipo <?= htmlspecialchars($item['tipo'] ?? '') ?>" style="padding:1px 6px;border-radius:10px;font-size:.65rem;font-weight:700;background:#dbeafe;color:#1e40af;"><?= htmlspecialchars($item['tipo'] ?? '?') ?></span>
                </div>
                <div class="ex-tl-meta">
                    Saída: <code><?= htmlspecialchars($item['saida'] ?? '—') ?></code>
                    <?php if (!empty($item['aguardar'])): ?> · ⏸ aguarda<?php endif; ?>
                    <?php if (!empty($item['fim'])): ?> · 🏁 fim<?php endif; ?>
                    <?php if (!empty($item['erro'])): ?> · <span style="color:#dc2626;">⚠ erro: <?= htmlspecialchars($item['erro']) ?></span><?php endif; ?>
                </div>
            <?php elseif ($tipo === 'fim_implicito'): ?>
                <div class="ex-tl-titulo">🏁 Fim implícito</div>
                <div class="ex-tl-meta"><?= htmlspecialchars($item['msg'] ?? '') ?></div>
            <?php elseif ($tipo === 'erro'): ?>
                <div class="ex-tl-titulo" style="color:#991b1b;">⚠ Erro</div>
                <div class="ex-tl-meta"><?= htmlspecialchars($item['msg'] ?? '') ?></div>
            <?php elseif ($tipo === 'avancar_saida'): ?>
                <div class="ex-tl-titulo">⏹ Fim da chamada</div>
                <div class="ex-tl-meta">
                    Estado: <strong><?= htmlspecialchars($item['estado'] ?? '?') ?></strong>
                    · Bloco final: #<?= (int)($item['bloco_final'] ?? 0) ?: '—' ?>
                    · Passos: <?= (int)($item['passos'] ?? 0) ?>
                    <?php if ($item['aguardar_ate']): ?> · aguardando até <?= htmlspecialchars($item['aguardar_ate']) ?><?php endif; ?>
                </div>
            <?php else: ?>
                <div class="ex-tl-titulo"><?= htmlspecialchars($tipo) ?></div>
                <div class="ex-tl-meta"><?= htmlspecialchars(json_encode($item)) ?></div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
