<?php
/**
 * Admin — gestão das solicitações dos colaboradores.
 * Acesso: SOMENTE admin.
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
require_role('admin');

$pdo = db();

// Self-heal
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS colaboradores_solicitacoes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        colaborador_id INT NOT NULL,
        tipo VARCHAR(30) NOT NULL,
        titulo VARCHAR(200) NOT NULL,
        descricao TEXT NULL,
        data_inicio DATE NULL, data_fim DATE NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pendente',
        resposta_admin TEXT NULL,
        respondido_por INT NULL, respondido_em DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_col (colaborador_id), INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && validate_csrf()) {
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    if ($id && in_array($action, array('aprovar','recusar'), true)) {
        $resposta = trim($_POST['resposta'] ?? '');
        $novoStatus = $action === 'aprovar' ? 'aprovada' : 'recusada';
        try {
            $pdo->prepare("UPDATE colaboradores_solicitacoes
                SET status = ?, resposta_admin = ?, respondido_por = ?, respondido_em = NOW()
                WHERE id = ?")
                ->execute(array($novoStatus, $resposta, current_user_id(), $id));
            flash_set('success', 'Solicitação ' . ($action === 'aprovar' ? 'aprovada' : 'recusada') . '.');
        } catch (Exception $e) {
            flash_set('error', 'Erro: ' . $e->getMessage());
        }
        redirect(module_url('admin', 'onboarding_solicitacoes.php'));
    }
}

$tiposLabel = array(
    'folga' => '🏖️ Folga / Ausência',
    'material' => '📦 Material / Equipamento',
    'doenca' => '🤒 Aviso de doença',
    'prova' => '📚 Prova / Avaliação',
    'reembolso' => '💰 Reembolso',
    'feedback' => '💬 Reunião de feedback',
    'outro' => '✨ Outro',
);

$filtro = $_GET['filtro'] ?? 'pendente';
$where = '1=1';
if ($filtro === 'pendente') $where = "s.status = 'pendente'";
elseif ($filtro === 'respondida') $where = "s.status IN ('aprovada','recusada')";

$lista = array();
try {
    $lista = $pdo->query("SELECT s.*, c.nome_completo
                          FROM colaboradores_solicitacoes s
                          LEFT JOIN colaboradores_onboarding c ON c.id = s.colaborador_id
                          WHERE $where
                          ORDER BY s.created_at DESC LIMIT 200")->fetchAll();
} catch (Exception $e) {}

$totPend = (int)$pdo->query("SELECT COUNT(*) FROM colaboradores_solicitacoes WHERE status = 'pendente'")->fetchColumn();

$pageTitle = 'Solicitações dos Colaboradores';
require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.s-card { background:#fff; border-radius:14px; padding:1.5rem 1.6rem; margin-bottom:1.2rem; box-shadow:0 2px 8px rgba(0,0,0,.04); border:1px solid #e5e7eb; }
.s-card h3 { font-size:1.05rem; color:#052228; padding-bottom:.5rem; border-bottom:2px solid #d7ab90; margin-bottom:1rem; }
.s-filtros { display:flex; gap:.5rem; flex-wrap:wrap; margin-bottom:1rem; }
.s-filtros a { padding:.45rem 1rem; border-radius:20px; background:#fff; border:1.5px solid #e5e7eb; color:#6b7280; font-size:.82rem; font-weight:700; text-decoration:none; }
.s-filtros a.ativo { background:#052228; color:#fff; border-color:#052228; }

.s-item { background:#fff; border-radius:12px; padding:1.1rem 1.3rem; margin-bottom:.85rem; border:1.5px solid #e5e7eb; }
.s-item.pendente { border-color:#fcd34d; background:#fffbeb; }
.s-item.aprovada { border-color:#34d399; background:#ecfdf5; }
.s-item.recusada { border-color:#fca5a5; background:#fef2f2; }
.s-item .head { display:flex; justify-content:space-between; align-items:flex-start; gap:.6rem; flex-wrap:wrap; }
.s-item .titulo { font-weight:700; color:#052228; font-size:.95rem; }
.s-item .meta { font-size:.74rem; color:#6b7280; margin-top:.2rem; }
.s-item .desc { background:#fff; border:1px solid #e5e7eb; border-radius:8px; padding:.6rem .85rem; margin-top:.6rem; font-size:.85rem; color:#374151; line-height:1.5; }
.s-item .resposta { background:#f3f4f6; border-left:4px solid #B87333; padding:.55rem .85rem; border-radius:0 6px 6px 0; margin-top:.6rem; font-size:.85rem; }
.status-pill { display:inline-block; padding:.18rem .65rem; border-radius:12px; font-size:.7rem; font-weight:700; }
.status-pill.pendente { background:#fef3c7; color:#92400e; }
.status-pill.aprovada { background:#d1fae5; color:#065f46; }
.status-pill.recusada { background:#fee2e2; color:#991b1b; }
.s-acoes { margin-top:.7rem; display:flex; gap:.4rem; flex-wrap:wrap; }
.s-acoes textarea { width:100%; padding:.5rem; border:1.5px solid #e5e7eb; border-radius:8px; font-size:.82rem; font-family:inherit; min-height:60px; }
.s-acoes form { background:#fafafa; border:1px dashed #d1d5db; border-radius:8px; padding:.7rem; margin-top:.5rem; flex:1; min-width:280px; }
.btn-aprovar { background:#059669; color:#fff; border:0; padding:.45rem 1rem; border-radius:8px; font-weight:700; cursor:pointer; font-size:.78rem; margin-top:.4rem; }
.btn-recusar { background:#dc2626; color:#fff; border:0; padding:.45rem 1rem; border-radius:8px; font-weight:700; cursor:pointer; font-size:.78rem; margin-top:.4rem; }
</style>

<div class="card">
    <div class="card-header">
        <h3>📩 Solicitações dos colaboradores <?php if ($totPend > 0): ?><span style="background:#fcd34d;color:#92400e;padding:.15rem .55rem;border-radius:12px;font-size:.75rem;font-weight:700;margin-left:.5rem;"><?= $totPend ?> pendente<?= $totPend !== 1 ? 's' : '' ?></span><?php endif; ?></h3>
        <p style="font-size:.82rem;color:#6b7280;margin-top:.3rem;">Pedidos de folga, material, aviso de doença, etc. Você aprova ou recusa com mensagem.</p>
    </div>
</div>

<div class="s-card" style="margin-top:1rem;">
    <div class="s-filtros">
        <a href="?filtro=pendente" class="<?= $filtro === 'pendente' ? 'ativo' : '' ?>">⏳ Pendentes</a>
        <a href="?filtro=respondida" class="<?= $filtro === 'respondida' ? 'ativo' : '' ?>">✓ Respondidas</a>
        <a href="?filtro=todas" class="<?= $filtro === 'todas' ? 'ativo' : '' ?>">📋 Todas</a>
    </div>

    <?php if (empty($lista)): ?>
        <p style="color:#6b7280;font-size:.85rem;text-align:center;padding:2rem;">Nenhuma solicitação <?= e($filtro === 'pendente' ? 'pendente' : ($filtro === 'respondida' ? 'respondida' : '')) ?>.</p>
    <?php else: ?>
        <?php foreach ($lista as $s):
            $tipoLbl = $tiposLabel[$s['tipo']] ?? $s['tipo'];
        ?>
        <div class="s-item <?= e($s['status']) ?>">
            <div class="head">
                <div style="flex:1;min-width:260px;">
                    <div class="titulo">
                        <span style="margin-right:.4rem;"><?= e($tipoLbl) ?></span>
                        <span><?= e($s['titulo']) ?></span>
                    </div>
                    <div class="meta">
                        👤 <strong><?= e($s['nome_completo']) ?></strong> &middot;
                        <?= e(date('d/m/Y H:i', strtotime($s['created_at']))) ?>
                        <?php if ($s['data_inicio']): ?>
                            &middot; 📅 <?= e(date('d/m/Y', strtotime($s['data_inicio']))) ?>
                            <?php if ($s['data_fim'] && $s['data_fim'] !== $s['data_inicio']): ?>
                                a <?= e(date('d/m/Y', strtotime($s['data_fim']))) ?>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <span class="status-pill <?= e($s['status']) ?>">
                    <?php if ($s['status'] === 'aprovada'): ?>✓ Aprovada
                    <?php elseif ($s['status'] === 'recusada'): ?>✕ Recusada
                    <?php else: ?>⏳ Pendente<?php endif; ?>
                </span>
            </div>
            <?php if ($s['descricao']): ?>
                <div class="desc"><strong>📝 Descrição:</strong><br><?= nl2br(e($s['descricao'])) ?></div>
            <?php endif; ?>

            <?php if ($s['status'] === 'pendente'): ?>
                <div class="s-acoes">
                    <form method="POST">
                        <?= csrf_input() ?>
                        <input type="hidden" name="action" value="aprovar">
                        <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                        <textarea name="resposta" placeholder="Mensagem de retorno (opcional)..."></textarea>
                        <button type="submit" class="btn-aprovar">✓ Aprovar</button>
                    </form>
                    <form method="POST">
                        <?= csrf_input() ?>
                        <input type="hidden" name="action" value="recusar">
                        <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                        <textarea name="resposta" placeholder="Justificativa (opcional)..."></textarea>
                        <button type="submit" class="btn-recusar">✕ Recusar</button>
                    </form>
                </div>
            <?php elseif (!empty($s['resposta_admin'])): ?>
                <div class="resposta">
                    <strong>Sua resposta:</strong> <?= nl2br(e($s['resposta_admin'])) ?>
                    <?php if ($s['respondido_em']): ?>
                        <div style="font-size:.7rem;color:#6b7280;margin-top:.2rem;">em <?= e(date('d/m/Y H:i', strtotime($s['respondido_em']))) ?></div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
