<?php
/**
 * Página pública: solicitações da colaboradora.
 *
 * A colaboradora pode pedir: folga, material, avisar doença,
 * provas (se estagiária), reembolso, ou outro tipo livre.
 *
 * Admin vê tudo na subpágina /modules/admin/onboarding_solicitacoes.php
 * e responde aprovando ou recusando com mensagem.
 *
 * Acesso: ?token=XXX (mesma session da página principal)
 */
require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/database.php';

@session_start();

$pdo = db();
$token = isset($_GET['token']) ? trim($_GET['token']) : '';
if (!$token || !preg_match('/^[a-f0-9]{16,48}$/', $token)) {
    http_response_code(404); exit('Link inválido.');
}

$st = $pdo->prepare("SELECT * FROM colaboradores_onboarding WHERE token = ? AND status != 'arquivado'");
$st->execute(array($token));
$reg = $st->fetch();
if (!$reg) { http_response_code(404); exit('Link inválido.'); }

$sessKey = 'onb_auth_' . $token;
if (empty($_SESSION[$sessKey])) {
    header('Location: ./?token=' . urlencode($token)); exit;
}

// Self-heal
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS colaboradores_solicitacoes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        colaborador_id INT NOT NULL,
        tipo VARCHAR(30) NOT NULL,
        titulo VARCHAR(200) NOT NULL,
        descricao TEXT NULL,
        data_inicio DATE NULL,
        data_fim DATE NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pendente',
        resposta_admin TEXT NULL,
        respondido_por INT NULL,
        respondido_em DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_col (colaborador_id), INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

$tiposSolicitacao = array(
    'folga'      => array('label' => '🏖️ Folga / Ausência', 'cor' => '#3b82f6'),
    'material'   => array('label' => '📦 Material / Equipamento', 'cor' => '#8b5cf6'),
    'doenca'     => array('label' => '🤒 Aviso de doença', 'cor' => '#f59e0b'),
    'prova'      => array('label' => '📚 Prova / Avaliação acadêmica', 'cor' => '#10b981'),
    'reembolso'  => array('label' => '💰 Reembolso', 'cor' => '#06b6d4'),
    'feedback'   => array('label' => '💬 Reunião de feedback', 'cor' => '#ec4899'),
    'outro'      => array('label' => '✨ Outro', 'cor' => '#6b7280'),
);

// Handler POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao_criar'])) {
    $tipo = $_POST['tipo'] ?? '';
    $titulo = trim($_POST['titulo'] ?? '');
    $desc = trim($_POST['descricao'] ?? '');
    $dataIni = trim($_POST['data_inicio'] ?? '') ?: null;
    $dataFim = trim($_POST['data_fim'] ?? '') ?: null;
    if (!isset($tiposSolicitacao[$tipo]) || !$titulo) {
        $erroForm = 'Preencha tipo e título.';
    } else {
        try {
            $pdo->prepare("INSERT INTO colaboradores_solicitacoes
                (colaborador_id, tipo, titulo, descricao, data_inicio, data_fim)
                VALUES (?,?,?,?,?,?)")
                ->execute(array($reg['id'], $tipo, $titulo, $desc, $dataIni, $dataFim));
            // Notifica admins
            try {
                require_once __DIR__ . '/../../core/functions_notify.php';
                if (function_exists('notify_admins')) {
                    notify_admins(
                        '📩 Nova solicitação',
                        $reg['nome_completo'] . ': ' . $tiposSolicitacao[$tipo]['label'] . ' — ' . $titulo,
                        null
                    );
                }
            } catch (Exception $e) {}
            header('Location: ?token=' . urlencode($token) . '&ok=1');
            exit;
        } catch (Exception $e) {
            $erroForm = 'Erro ao salvar: ' . $e->getMessage();
        }
    }
}

// Carrega lista
$lista = array();
try {
    $st = $pdo->prepare("SELECT * FROM colaboradores_solicitacoes
                         WHERE colaborador_id = ? ORDER BY created_at DESC LIMIT 50");
    $st->execute(array($reg['id']));
    $lista = $st->fetchAll();
} catch (Exception $e) {}

$primeiroNome = explode(' ', $reg['nome_completo'])[0];
$genero = isset($reg['genero']) ? $reg['genero'] : 'F';
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>📩 Solicitações — Ferreira e Sá</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700;800&family=Open+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root { --petrol-900:#052228; --petrol-700:#173d46; --cobre:#6a3c2c; --cobre-light:#B87333; --nude:#d7ab90; --nude-light:#fff7ed; --bg:#f8f4ef; }
* { box-sizing:border-box; margin:0; padding:0; }
body { font-family:'Open Sans',sans-serif; background:var(--bg); color:#1a1a1a; min-height:100vh; line-height:1.55; }
h1,h2,h3 { font-family:'Playfair Display',serif; color:var(--petrol-900); }

.toolbar {
    background:linear-gradient(135deg,var(--petrol-900),var(--petrol-700)); color:#fff;
    padding:1rem 1.5rem; display:flex; align-items:center; gap:1rem; flex-wrap:wrap; justify-content:space-between;
    position:sticky; top:0; z-index:100; box-shadow:0 4px 14px rgba(0,0,0,.15);
}
.toolbar h1 { color:#fff; font-size:1.1rem; }
.toolbar a.btn-back { background:rgba(255,255,255,.15); color:#fff; padding:.5rem 1rem; border-radius:8px; text-decoration:none; font-size:.85rem; font-weight:600; }
.toolbar a.btn-back:hover { background:rgba(255,255,255,.25); }

.container { max-width:880px; margin:1.5rem auto 3rem; padding:0 1.2rem; }
.card-block { background:#fff; border-radius:14px; box-shadow:0 4px 18px rgba(0,0,0,.06); padding:1.6rem 1.5rem; margin-bottom:1.2rem; }
.card-block h2 { font-size:1.3rem; margin-bottom:.6rem; }

.tipo-grid { display:grid; gap:.5rem; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); margin-bottom:1rem; }
.tipo-grid label { display:flex; align-items:center; gap:.4rem; padding:.6rem .85rem; border:1.5px solid #e5e7eb; border-radius:10px; cursor:pointer; font-size:.85rem; transition:all .15s; background:#fff; user-select:none; -webkit-user-select:none; }
.tipo-grid label * { cursor:pointer; user-select:none; -webkit-user-select:none; }
.tipo-grid label:hover { border-color:var(--cobre-light); }
.tipo-grid input { display:none; }
.tipo-grid label.sel { border-color:var(--cobre-light); background:var(--nude-light); font-weight:700; }

.form-grid { display:grid; gap:.85rem; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); }
.form-grid label { display:block; font-size:.78rem; font-weight:700; color:var(--petrol-900); margin-bottom:.25rem; }
.form-grid input, .form-grid textarea { width:100%; padding:.6rem .8rem; border:1.5px solid #e5e7eb; border-radius:8px; font-size:.88rem; font-family:inherit; }
.form-grid .full { grid-column:1/-1; }

.btn-primary { background:linear-gradient(135deg,var(--petrol-900),var(--petrol-700)); color:#fff; border:0; padding:.8rem 1.6rem; border-radius:10px; font-weight:700; cursor:pointer; font-family:inherit; font-size:.9rem; }

.solic-item { background:#fff; border:1.5px solid #e5e7eb; border-radius:12px; padding:.85rem 1.1rem; margin-bottom:.6rem; }
.solic-item .titulo { font-size:.95rem; font-weight:700; color:var(--petrol-900); }
.solic-item .meta { font-size:.7rem; color:#6b7280; margin-top:.2rem; }
.solic-item .desc { font-size:.85rem; color:#374151; margin-top:.4rem; line-height:1.5; }
.solic-item .resposta { background:#f3f4f6; border-left:3px solid var(--cobre-light); padding:.5rem .75rem; border-radius:0 6px 6px 0; margin-top:.6rem; font-size:.85rem; }
.solic-item.aprovada { border-color:#34d399; background:#ecfdf5; }
.solic-item.recusada { border-color:#fca5a5; background:#fef2f2; }
.status-pill { display:inline-block; padding:.15rem .55rem; border-radius:10px; font-size:.7rem; font-weight:700; }
.status-pill.pendente { background:#fef3c7; color:#92400e; }
.status-pill.aprovada { background:#d1fae5; color:#065f46; }
.status-pill.recusada { background:#fee2e2; color:#991b1b; }

.sucesso-banner { background:linear-gradient(135deg,#ecfdf5,#d1fae5); border:1.5px solid #34d399; color:#065f46; padding:.85rem 1.1rem; border-radius:10px; margin-bottom:1rem; }
.erro-banner { background:#fef2f2; border:1px solid #fca5a5; color:#991b1b; padding:.8rem 1rem; border-radius:10px; margin-bottom:1rem; }
</style>
</head>
<body>

<div class="toolbar">
    <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap;">
        <a href="./?token=<?= urlencode($token) ?>" class="btn-back">← Página principal</a>
        <h1>📩 Minhas Solicitações</h1>
    </div>
</div>

<div class="container">

<?php if (!empty($_GET['ok'])): ?>
    <div class="sucesso-banner">✓ Solicitação enviada! A Dra. Amanda ou o Dr. Luiz Eduardo vão te dar retorno em breve. 💜</div>
<?php endif; ?>
<?php if (!empty($erroForm)): ?>
    <div class="erro-banner">⚠ <?= htmlspecialchars($erroForm) ?></div>
<?php endif; ?>

<div class="card-block">
    <h2>📩 Nova solicitação</h2>
    <p style="color:#6b7280;font-size:.88rem;margin-bottom:1rem;">Precisa de algo? Pede aqui que a gente responde rapidinho.</p>

    <form method="POST">
        <input type="hidden" name="acao_criar" value="1">

        <label style="font-size:.78rem;font-weight:700;color:var(--petrol-900);display:block;margin-bottom:.4rem;">Tipo *</label>
        <div class="tipo-grid">
            <?php foreach ($tiposSolicitacao as $k => $info): ?>
            <label class="<?= $k === 'folga' ? 'sel' : '' ?>">
                <input type="radio" name="tipo" value="<?= htmlspecialchars($k) ?>" <?= $k === 'folga' ? 'checked' : '' ?> onclick="document.querySelectorAll('.tipo-grid label').forEach(function(l){l.classList.remove('sel');});this.parentNode.classList.add('sel');">
                <span><?= htmlspecialchars($info['label']) ?></span>
            </label>
            <?php endforeach; ?>
        </div>

        <div class="form-grid">
            <div class="full">
                <label>Título / Resumo *</label>
                <input name="titulo" required maxlength="200" placeholder="Ex: Folga em 12/05 — consulta médica">
            </div>
            <div>
                <label>Data início (se aplicável)</label>
                <input type="date" name="data_inicio">
            </div>
            <div>
                <label>Data fim (se aplicável)</label>
                <input type="date" name="data_fim">
            </div>
            <div class="full">
                <label>Descrição (detalhes)</label>
                <textarea name="descricao" rows="3" placeholder="Conta mais sobre o pedido pra gente entender melhor..."></textarea>
            </div>
        </div>

        <div style="margin-top:1.2rem;">
            <button type="submit" class="btn-primary">📤 Enviar solicitação</button>
        </div>
    </form>
</div>

<div class="card-block">
    <h2>📋 Minhas solicitações (<?= count($lista) ?>)</h2>
    <?php if (empty($lista)): ?>
        <p style="color:#6b7280;font-size:.88rem;">Você ainda não fez nenhuma solicitação. Use o form acima ☝️</p>
    <?php else: ?>
        <?php foreach ($lista as $s):
            $info = $tiposSolicitacao[$s['tipo']] ?? array('label' => $s['tipo'], 'cor' => '#6b7280');
        ?>
        <div class="solic-item <?= htmlspecialchars($s['status']) ?>">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:.5rem;flex-wrap:wrap;">
                <div style="flex:1;min-width:220px;">
                    <div class="titulo"><?= htmlspecialchars($info['label']) ?> &middot; <?= htmlspecialchars($s['titulo']) ?></div>
                    <div class="meta">
                        Enviada em <?= htmlspecialchars(date('d/m/Y H:i', strtotime($s['created_at']))) ?>
                        <?php if ($s['data_inicio']): ?>
                            &middot; 📅 <?= htmlspecialchars(date('d/m/Y', strtotime($s['data_inicio']))) ?>
                            <?php if ($s['data_fim'] && $s['data_fim'] !== $s['data_inicio']): ?>
                                a <?= htmlspecialchars(date('d/m/Y', strtotime($s['data_fim']))) ?>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <span class="status-pill <?= htmlspecialchars($s['status']) ?>">
                    <?php if ($s['status'] === 'aprovada'): ?>✓ Aprovada
                    <?php elseif ($s['status'] === 'recusada'): ?>✕ Recusada
                    <?php else: ?>⏳ Pendente<?php endif; ?>
                </span>
            </div>
            <?php if ($s['descricao']): ?>
                <div class="desc"><?= nl2br(htmlspecialchars($s['descricao'])) ?></div>
            <?php endif; ?>
            <?php if (!empty($s['resposta_admin'])): ?>
                <div class="resposta">
                    <strong>💬 Resposta:</strong> <?= nl2br(htmlspecialchars($s['resposta_admin'])) ?>
                    <?php if ($s['respondido_em']): ?>
                        <div style="font-size:.7rem;color:#6b7280;margin-top:.2rem;">em <?= htmlspecialchars(date('d/m/Y H:i', strtotime($s['respondido_em']))) ?></div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

</div>
</body>
</html>
