<?php
/**
 * Mural de Avisos do Onboarding — administração.
 *
 * Permite admin publicar avisos/parabéns/conquistas/políticas
 * pra UM colaborador específico ou pra TODOS.
 *
 * Acesso: /modules/admin/onboarding_avisos.php
 * Acesso: SOMENTE admin
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
require_role('admin');

$pdo = db();

// Self-heal da tabela
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS colaboradores_avisos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        colaborador_id INT NULL COMMENT 'NULL = global (todos)',
        tipo VARCHAR(20) NOT NULL DEFAULT 'aviso',
        titulo VARCHAR(200) NOT NULL,
        mensagem TEXT NOT NULL,
        icone VARCHAR(8) NULL,
        cor VARCHAR(20) NULL,
        ativo TINYINT(1) NOT NULL DEFAULT 1,
        criado_por INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_col (colaborador_id),
        INDEX idx_ativo (ativo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

// Tipos de aviso
$tiposAviso = array(
    'parabens'  => array('label' => '🎉 Parabéns', 'icone' => '🎉', 'cor' => 'verde'),
    'aviso'     => array('label' => '📋 Aviso geral', 'icone' => '📋', 'cor' => 'azul'),
    'politica'  => array('label' => '📜 Política / Procedimento', 'icone' => '📜', 'cor' => 'cobre'),
    'conquista' => array('label' => '🏆 Conquista', 'icone' => '🏆', 'cor' => 'dourado'),
    'meta'      => array('label' => '🎯 Meta atingida', 'icone' => '🎯', 'cor' => 'rosa'),
    'mensagem'  => array('label' => '💜 Mensagem', 'icone' => '💜', 'cor' => 'roxo'),
);

// Handlers POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && validate_csrf()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'publicar') {
        $colabId = (int)($_POST['colaborador_id'] ?? 0) ?: null;
        $tipo = $_POST['tipo'] ?? 'aviso';
        $titulo = trim($_POST['titulo'] ?? '');
        $mensagem = trim($_POST['mensagem'] ?? '');
        if (!$titulo || !$mensagem) {
            flash_set('error', 'Título e mensagem são obrigatórios.');
        } elseif (!isset($tiposAviso[$tipo])) {
            flash_set('error', 'Tipo inválido.');
        } else {
            $icone = $tiposAviso[$tipo]['icone'];
            $cor = $tiposAviso[$tipo]['cor'];
            $pdo->prepare("INSERT INTO colaboradores_avisos
                (colaborador_id, tipo, titulo, mensagem, icone, cor, criado_por)
                VALUES (?,?,?,?,?,?,?)")
                ->execute(array($colabId, $tipo, $titulo, $mensagem, $icone, $cor, current_user_id()));
            flash_set('success', 'Aviso publicado com sucesso!');
        }
        redirect(module_url('admin', 'onboarding_avisos.php'));
    }

    if ($action === 'desativar') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $pdo->prepare("UPDATE colaboradores_avisos SET ativo = 0 WHERE id = ?")->execute(array($id));
            flash_set('success', 'Aviso ocultado.');
        }
        redirect(module_url('admin', 'onboarding_avisos.php'));
    }

    if ($action === 'reativar') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $pdo->prepare("UPDATE colaboradores_avisos SET ativo = 1 WHERE id = ?")->execute(array($id));
            flash_set('success', 'Aviso reativado.');
        }
        redirect(module_url('admin', 'onboarding_avisos.php'));
    }

    if ($action === 'excluir') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $pdo->prepare("DELETE FROM colaboradores_avisos WHERE id = ?")->execute(array($id));
            flash_set('success', 'Aviso excluído permanentemente.');
        }
        redirect(module_url('admin', 'onboarding_avisos.php'));
    }
}

// Carrega colaboradores ativos pra select
$colabs = array();
try {
    $colabs = $pdo->query("SELECT id, nome_completo FROM colaboradores_onboarding
                           WHERE status != 'arquivado' ORDER BY nome_completo")->fetchAll();
} catch (Exception $e) {}

// Carrega lista de avisos
$avisos = array();
try {
    $avisos = $pdo->query("SELECT a.*, c.nome_completo, u.name AS autor_nome
                           FROM colaboradores_avisos a
                           LEFT JOIN colaboradores_onboarding c ON c.id = a.colaborador_id
                           LEFT JOIN users u ON u.id = a.criado_por
                           ORDER BY a.created_at DESC LIMIT 100")->fetchAll();
} catch (Exception $e) {}

$pageTitle = 'Mural de Avisos';
require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.av-card { background:#fff; border-radius:14px; padding:1.4rem 1.6rem; margin-bottom:1.2rem; box-shadow:0 2px 8px rgba(0,0,0,.04); border:1px solid #e5e7eb; }
.av-card h3 { font-size:1.05rem; color:#052228; padding-bottom:.5rem; border-bottom:2px solid #d7ab90; margin-bottom:1rem; }
.av-grid { display:grid; gap:.85rem; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); }
.av-grid label { display:block; font-size:.78rem; font-weight:700; color:#052228; margin-bottom:.25rem; }
.av-grid input, .av-grid select, .av-grid textarea {
    width:100%; padding:.55rem .75rem; border:1.5px solid #e5e7eb; border-radius:8px;
    font-size:.85rem; font-family:inherit;
}
.av-grid .full { grid-column:1/-1; }
.av-tipo-grid { display:grid; gap:.5rem; grid-template-columns:repeat(auto-fit, minmax(160px, 1fr)); }
.av-tipo-grid label {
    display:flex; align-items:center; gap:.4rem; padding:.55rem .8rem; border:1.5px solid #e5e7eb;
    border-radius:10px; cursor:pointer; font-size:.85rem; transition:all .15s; background:#fff;
}
.av-tipo-grid label:hover { border-color:#d7ab90; }
.av-tipo-grid input[type=radio] { display:none; }
.av-tipo-grid input[type=radio]:checked + span { font-weight:800; }
.av-tipo-grid label.sel { border-color:#B87333; background:#fff7ed; }

.aviso-item { border:1.5px solid #e5e7eb; border-radius:12px; padding:1rem 1.2rem; margin-bottom:.7rem; display:flex; gap:.8rem; align-items:flex-start; }
.aviso-item.tipo-verde { background:#ecfdf5; border-color:#34d399; }
.aviso-item.tipo-azul { background:#eff6ff; border-color:#60a5fa; }
.aviso-item.tipo-cobre { background:#fff7ed; border-color:#d7ab90; }
.aviso-item.tipo-dourado { background:#fefce8; border-color:#facc15; }
.aviso-item.tipo-rosa { background:#fdf2f8; border-color:#f9a8d4; }
.aviso-item.tipo-roxo { background:#faf5ff; border-color:#c084fc; }
.aviso-item.inativo { opacity:.55; }
.aviso-item .icone { font-size:2rem; line-height:1; flex-shrink:0; }
.aviso-item h4 { font-family:'Open Sans',sans-serif; font-size:.95rem; color:#052228; margin-bottom:.2rem; }
.aviso-item p { font-size:.85rem; color:#374151; margin:0; line-height:1.5; }
.aviso-meta { font-size:.7rem; color:#6b7280; margin-top:.4rem; }
.aviso-acoes { margin-top:.5rem; display:flex; gap:.4rem; flex-wrap:wrap; }
.aviso-acoes button, .aviso-acoes .btn { font-size:.72rem; padding:.25rem .55rem; }
</style>

<div class="card">
    <div class="card-header">
        <h3>📰 Mural de Avisos</h3>
        <p style="font-size:.82rem;color:#6b7280;margin-top:.3rem;">
            Publique avisos, parabéns, novas políticas ou mensagens pra um colaborador específico OU pra todos os ativos.
            O aviso aparece automaticamente na página exclusiva da pessoa.
        </p>
    </div>
</div>

<div class="av-card" style="margin-top:1rem;">
    <h3>➕ Publicar novo aviso</h3>
    <form method="POST">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="publicar">

        <div style="margin-bottom:1rem;">
            <label style="font-size:.8rem;font-weight:700;color:#052228;display:block;margin-bottom:.3rem;">Tipo *</label>
            <div class="av-tipo-grid">
                <?php foreach ($tiposAviso as $k => $info): ?>
                <label class="<?= $k === 'aviso' ? 'sel' : '' ?>">
                    <input type="radio" name="tipo" value="<?= e($k) ?>" <?= $k === 'aviso' ? 'checked' : '' ?> onclick="document.querySelectorAll('.av-tipo-grid label').forEach(function(l){l.classList.remove('sel');});this.parentNode.classList.add('sel');">
                    <span><?= e($info['label']) ?></span>
                </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="av-grid">
            <div>
                <label>Destinatário *</label>
                <select name="colaborador_id" required>
                    <option value="">— 📢 Todos os colaboradores ativos —</option>
                    <?php foreach ($colabs as $c): ?>
                        <option value="<?= (int)$c['id'] ?>"><?= e($c['nome_completo']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Título *</label>
                <input name="titulo" required maxlength="200" placeholder="Ex: Parabéns pela primeira indicação fechada!">
            </div>
            <div class="full">
                <label>Mensagem *</label>
                <textarea name="mensagem" required rows="3" placeholder="Texto que vai aparecer no mural da colaboradora. Pode usar emojis 💜"></textarea>
            </div>
        </div>

        <div style="margin-top:1rem;">
            <button type="submit" class="btn btn-primary">📰 Publicar no mural</button>
        </div>
    </form>
</div>

<div class="av-card">
    <h3>📋 Avisos publicados (<?= count($avisos) ?>)</h3>
    <?php if (empty($avisos)): ?>
        <p style="color:#6b7280;font-size:.85rem;">Nenhum aviso publicado ainda. Use o form acima ☝️</p>
    <?php else: ?>
        <?php foreach ($avisos as $a):
            $tipoCor = $a['cor'] ?: 'azul';
            $destino = $a['nome_completo'] ? '👤 ' . htmlspecialchars($a['nome_completo']) : '📢 Todos';
            $autor = $a['autor_nome'] ?: '—';
        ?>
        <div class="aviso-item tipo-<?= e($tipoCor) ?> <?= !$a['ativo'] ? 'inativo' : '' ?>">
            <div class="icone"><?= e($a['icone'] ?: '📋') ?></div>
            <div style="flex:1;">
                <h4><?= e($a['titulo']) ?></h4>
                <p><?= nl2br(e($a['mensagem'])) ?></p>
                <div class="aviso-meta">
                    <strong><?= e($destino) ?></strong> &middot;
                    publicado por <?= e(explode(' ', $autor)[0]) ?> em <?= e(date('d/m/Y H:i', strtotime($a['created_at']))) ?>
                    <?php if (!$a['ativo']): ?> &middot; <span style="color:#dc2626;">desativado</span><?php endif; ?>
                </div>
                <div class="aviso-acoes">
                    <?php if ($a['ativo']): ?>
                        <form method="POST" style="display:inline;">
                            <?= csrf_input() ?>
                            <input type="hidden" name="action" value="desativar">
                            <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                            <button class="btn btn-outline btn-sm">🚫 Desativar</button>
                        </form>
                    <?php else: ?>
                        <form method="POST" style="display:inline;">
                            <?= csrf_input() ?>
                            <input type="hidden" name="action" value="reativar">
                            <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                            <button class="btn btn-outline btn-sm" style="color:#059669;border-color:#34d399;">↩ Reativar</button>
                        </form>
                    <?php endif; ?>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('Excluir permanentemente este aviso?');">
                        <?= csrf_input() ?>
                        <input type="hidden" name="action" value="excluir">
                        <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                        <button class="btn btn-outline btn-sm" style="color:#dc2626;border-color:#fca5a5;">🗑 Excluir</button>
                    </form>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
