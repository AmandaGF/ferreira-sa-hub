<?php
/**
 * Central VIP F&S — Meus Processos
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
$pdo = sv_db();
$user = salavip_current_user();
$clienteId = salavip_current_cliente_id();

// --- Buscar processos ---
$stmt = $pdo->prepare(
    "SELECT * FROM cases WHERE client_id = ? AND salavip_ativo = 1 ORDER BY opened_at DESC"
);
$stmt->execute([$clienteId]);
$processos = $stmt->fetchAll();

// --- Prepared statements reutilizáveis ---
$stmtAndamento = $pdo->prepare(
    "SELECT data_andamento, descricao FROM case_andamentos
     WHERE case_id = ? AND visivel_cliente = 1
     ORDER BY data_andamento DESC, created_at DESC LIMIT 1"
);
$stmtDocsPend = $pdo->prepare(
    "SELECT COUNT(*) FROM documentos_pendentes
     WHERE case_id = ? AND status = 'pendente' AND visivel_cliente = 1"
);

// --- Contar ativos ---
$totalAtivos = count($processos);

$pageTitle = 'Meus Processos';
require_once __DIR__ . '/../includes/header.php';

// --- Mapa de cores por status (borda esquerda) ---
$statusBorderColors = [
    'em_andamento'    => '#059669',
    'distribuido'     => '#6366f1',
    'aguardando_docs' => '#f59e0b',
    'doc_faltante'    => '#f59e0b',
    'suspenso'        => '#9ca3af',
    'arquivado'       => '#6b7280',
    'cancelado'       => '#6b7280',
];
?>

<style>
/* --- Meus Processos --- */
.sv-processos-summary {
    font-family: 'Playfair Display', serif;
    font-size: 1.15rem;
    color: var(--sv-text);
    margin-bottom: 1.5rem;
    padding-bottom: .75rem;
    border-bottom: 1px solid var(--sv-border);
}
.sv-processos-summary span {
    color: var(--sv-accent);
    font-weight: 700;
}

.sv-processos-grid {
    display: flex;
    flex-direction: column;
    gap: 1.25rem;
}

.sv-card--processo {
    background: var(--sv-bg-card);
    border: 1px solid var(--sv-border);
    border-left: 4px solid var(--sv-accent);
    border-radius: 10px;
    padding: 1.25rem 1.5rem;
    transition: box-shadow .2s, transform .15s;
}
.sv-card--processo:hover {
    box-shadow: 0 4px 20px rgba(0,0,0,.25);
    transform: translateY(-2px);
}

.sv-card__header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: .75rem;
}
.sv-card__title {
    margin: 0;
    font-family: 'Playfair Display', serif;
    font-size: 1.15rem;
    font-weight: 600;
    color: var(--sv-text);
    line-height: 1.3;
}

.sv-card__number {
    display: inline-flex;
    align-items: center;
    gap: .5rem;
    font-family: 'JetBrains Mono', 'Fira Code', 'Courier New', monospace;
    font-size: .82rem;
    color: var(--sv-text-muted);
    background: rgba(255,255,255,.04);
    padding: 4px 10px;
    border-radius: 6px;
    margin-bottom: .75rem;
    letter-spacing: .02em;
}
.sv-card__copy-btn {
    background: none;
    border: none;
    color: var(--sv-text-muted);
    cursor: pointer;
    padding: 2px;
    border-radius: 4px;
    display: inline-flex;
    align-items: center;
    transition: color .15s;
}
.sv-card__copy-btn:hover {
    color: var(--sv-accent);
}
.sv-card__copy-btn svg {
    width: 14px;
    height: 14px;
}

.sv-card__meta {
    display: flex;
    align-items: center;
    gap: .75rem;
    flex-wrap: wrap;
    margin-bottom: .75rem;
}

.sv-pill-type {
    display: inline-block;
    padding: 3px 12px;
    border-radius: 9999px;
    font-size: .72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .04em;
    background: var(--sv-accent-bg);
    color: var(--sv-accent);
}

.sv-card__court {
    display: inline-flex;
    align-items: center;
    gap: .35rem;
    font-size: .82rem;
    color: var(--sv-text-muted);
}
.sv-card__court svg {
    width: 14px;
    height: 14px;
    opacity: .6;
}

.sv-card__andamento {
    display: flex;
    align-items: baseline;
    gap: .5rem;
    font-size: .82rem;
    color: var(--sv-text-muted);
    margin-bottom: .75rem;
    padding: .6rem .75rem;
    background: rgba(255,255,255,.03);
    border-radius: 6px;
    border-left: 2px solid var(--sv-border);
}
.sv-card__andamento-label {
    font-weight: 600;
    color: var(--sv-text);
    white-space: nowrap;
}
.sv-card__andamento-date {
    font-family: 'JetBrains Mono', 'Fira Code', monospace;
    font-size: .78rem;
    white-space: nowrap;
    color: var(--sv-accent);
}
.sv-card__andamento-desc {
    color: var(--sv-text-muted);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    flex: 1;
    min-width: 0;
}

.sv-card__warning {
    display: inline-flex;
    align-items: center;
    gap: .35rem;
    font-size: .78rem;
    font-weight: 600;
    color: #f59e0b;
    margin-bottom: .5rem;
}

.sv-card__footer {
    display: flex;
    justify-content: flex-end;
    padding-top: .5rem;
}

.sv-btn-outline {
    display: inline-flex;
    align-items: center;
    gap: .35rem;
    padding: .5rem 1.1rem;
    border: 1.5px solid var(--sv-accent);
    color: var(--sv-accent);
    background: transparent;
    border-radius: 8px;
    font-size: .85rem;
    font-weight: 600;
    text-decoration: none;
    transition: background .15s, color .15s;
    cursor: pointer;
}
.sv-btn-outline:hover {
    background: var(--sv-accent);
    color: #0f172a;
}

@media (max-width: 600px) {
    .sv-card--processo { padding: 1rem; }
    .sv-card__header { flex-direction: column; gap: .5rem; }
    .sv-card__andamento { flex-direction: column; gap: .25rem; }
    .sv-card__andamento-desc { white-space: normal; }
}
</style>

<?php if (empty($processos)): ?>
    <div class="sv-empty">Nenhum processo encontrado.</div>
<?php else: ?>

    <div class="sv-processos-summary">
        <span><?= $totalAtivos ?></span> processo<?= $totalAtivos !== 1 ? 's' : '' ?> ativo<?= $totalAtivos !== 1 ? 's' : '' ?>
    </div>

    <div class="sv-processos-grid">
        <?php foreach ($processos as $caso):
            $status = $caso['status'] ?? '';
            $borderColor = $statusBorderColors[$status] ?? 'var(--sv-accent)';

            // Último andamento
            $stmtAndamento->execute([$caso['id']]);
            $ultimoAndamento = $stmtAndamento->fetch();

            // Documentos pendentes
            $stmtDocsPend->execute([$caso['id']]);
            $qtdDocsPend = (int) $stmtDocsPend->fetchColumn();
        ?>
            <div class="sv-card sv-card--processo" style="border-left-color: <?= $borderColor ?>;">

                <!-- Header: título + badge status -->
                <div class="sv-card__header">
                    <h3 class="sv-card__title"><?= sv_e($caso['title']) ?></h3>
                    <?= sv_badge_status_processo($status) ?>
                </div>

                <!-- Número do processo -->
                <?php if (!empty($caso['case_number'])): ?>
                    <div class="sv-card__number">
                        <span id="num-<?= (int)$caso['id'] ?>"><?= sv_e($caso['case_number']) ?></span>
                        <button class="sv-card__copy-btn" onclick="svCopyNum(<?= (int)$caso['id'] ?>)" title="Copiar número">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                        </button>
                    </div>
                <?php endif; ?>

                <!-- Tipo + Vara/Comarca -->
                <div class="sv-card__meta">
                    <?php if (!empty($caso['case_type'])): ?>
                        <span class="sv-pill-type"><?= sv_e(ucfirst($caso['case_type'])) ?></span>
                    <?php endif; ?>

                    <?php if (!empty($caso['court']) || !empty($caso['comarca'])): ?>
                        <span class="sv-card__court">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"/><path d="M5 21V7l7-4 7 4v14"/><path d="M9 21v-4h6v4"/></svg>
                            <?php
                            $infos = [];
                            if (!empty($caso['court']))   $infos[] = $caso['court'];
                            if (!empty($caso['comarca'])) $infos[] = $caso['comarca'];
                            echo sv_e(implode(' — ', $infos));
                            ?>
                        </span>
                    <?php endif; ?>
                </div>

                <!-- Último andamento -->
                <?php if ($ultimoAndamento): ?>
                    <div class="sv-card__andamento">
                        <span class="sv-card__andamento-label">Ultimo andamento</span>
                        <span class="sv-card__andamento-date"><?= date('d/m/Y', strtotime($ultimoAndamento['data_andamento'])) ?></span>
                        <span class="sv-card__andamento-desc"><?= sv_e(mb_strimwidth($ultimoAndamento['descricao'], 0, 120, '...')) ?></span>
                    </div>
                <?php endif; ?>

                <!-- Documentos pendentes -->
                <?php if ($qtdDocsPend > 0): ?>
                    <div class="sv-card__warning">
                        &#9888;&#65039; <?= $qtdDocsPend ?> documento<?= $qtdDocsPend > 1 ? 's' : '' ?> pendente<?= $qtdDocsPend > 1 ? 's' : '' ?>
                    </div>
                <?php endif; ?>

                <!-- Botão -->
                <div class="sv-card__footer">
                    <a href="<?= sv_url('pages/processo_detalhe.php?id=' . (int)$caso['id']) ?>" class="sv-btn sv-btn-outline">
                        Ver Detalhes &rarr;
                    </a>
                </div>

            </div>
        <?php endforeach; ?>
    </div>

<?php endif; ?>

<script>
function svCopyNum(id) {
    var el = document.getElementById('num-' + id);
    if (!el) return;
    var text = el.textContent.trim();
    if (navigator.clipboard) {
        navigator.clipboard.writeText(text).then(function() {
            svCopyFeedback(el);
        });
    } else {
        var ta = document.createElement('textarea');
        ta.value = text;
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
        svCopyFeedback(el);
    }
}
function svCopyFeedback(el) {
    var orig = el.textContent;
    el.textContent = 'Copiado!';
    el.style.color = 'var(--sv-accent)';
    setTimeout(function() {
        el.textContent = orig;
        el.style.color = '';
    }, 1200);
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
