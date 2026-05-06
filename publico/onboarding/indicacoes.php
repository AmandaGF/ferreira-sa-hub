<?php
/**
 * Página pública: indicações da colaboradora.
 *
 * - A colaboradora cadastra novas indicações (nome + telefone + obs)
 * - Vê 3 cards: total indicações do mês, contratos fechados no mês,
 *   R$ a receber no mês.
 * - Lista detalhada com filtro por mês (◀ ▶).
 * - Botão "Baixar relatório" (CSV) do filtro atual.
 *
 * Ciclo mensal: dia 1 ao último dia do mês (mês de fechamento define
 * o "R$ a receber").
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
    $pdo->exec("CREATE TABLE IF NOT EXISTS colaboradores_indicacoes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        colaborador_id INT NOT NULL,
        indicado_nome VARCHAR(150) NOT NULL,
        indicado_telefone VARCHAR(20) NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'lead',
        valor_contrato DECIMAL(10,2) NULL,
        percentual DECIMAL(5,2) NULL DEFAULT 5.00,
        valor_a_receber DECIMAL(10,2) NULL,
        data_indicacao DATE NOT NULL,
        data_fechamento DATE NULL,
        observacoes TEXT NULL,
        anotacao_admin TEXT NULL,
        criado_por_admin TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_col (colaborador_id),
        INDEX idx_status (status),
        INDEX idx_fech (data_fechamento)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

$statusLabels = array(
    'lead' => array('label' => '👀 Lead', 'cor' => '#fef3c7', 'txt' => '#92400e'),
    'em_negociacao' => array('label' => '💬 Em negociação', 'cor' => '#dbeafe', 'txt' => '#1e40af'),
    'contrato_fechado' => array('label' => '✅ Contrato fechado', 'cor' => '#d1fae5', 'txt' => '#065f46'),
    'perdido' => array('label' => '✕ Perdido', 'cor' => '#fee2e2', 'txt' => '#991b1b'),
);

// Handler: nova indicação
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao_nova_indicacao'])) {
    $nome = trim($_POST['indicado_nome'] ?? '');
    $tel = trim($_POST['indicado_telefone'] ?? '');
    $obs = trim($_POST['observacoes'] ?? '');
    if (!$nome) {
        $erroForm = 'Informe o nome da pessoa indicada.';
    } else {
        try {
            $pdo->prepare("INSERT INTO colaboradores_indicacoes
                (colaborador_id, indicado_nome, indicado_telefone, observacoes, data_indicacao, status)
                VALUES (?, ?, ?, ?, CURDATE(), 'lead')")
                ->execute(array($reg['id'], $nome, $tel, $obs));
            try {
                require_once __DIR__ . '/../../core/functions_notify.php';
                if (function_exists('notify_admins')) {
                    notify_admins(
                        '💸 Nova indicação',
                        $reg['nome_completo'] . ' indicou: ' . $nome . ($tel ? ' (' . $tel . ')' : ''),
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

function strftime_pt($iso) {
    $meses = array('janeiro','fevereiro','março','abril','maio','junho','julho','agosto','setembro','outubro','novembro','dezembro');
    $dt = DateTime::createFromFormat('Y-m-d', $iso);
    return $dt ? $meses[(int)$dt->format('n')-1] . ' de ' . $dt->format('Y') : $iso;
}

// Filtro de mês (default = mês atual)
$mesFiltro = $_GET['mes'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $mesFiltro)) $mesFiltro = date('Y-m');
$mesIni = $mesFiltro . '-01';
$mesFim = date('Y-m-t', strtotime($mesIni));
$mesAnt = date('Y-m', strtotime($mesIni . ' -1 month'));
$mesProx = date('Y-m', strtotime($mesIni . ' +1 month'));
$mesLabel = strftime_pt($mesIni);

// CSV download
if (isset($_GET['csv'])) {
    $st = $pdo->prepare("SELECT * FROM colaboradores_indicacoes
                         WHERE colaborador_id = ?
                         AND ((data_indicacao BETWEEN ? AND ?) OR (data_fechamento BETWEEN ? AND ?))
                         ORDER BY data_indicacao DESC");
    $st->execute(array($reg['id'], $mesIni, $mesFim, $mesIni, $mesFim));
    $rows = $st->fetchAll();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="indicacoes_' . $mesFiltro . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8
    fputcsv($out, array('Indicado', 'Telefone', 'Status', 'Data indicação', 'Data fechamento', 'Valor contrato (R$)', 'Percentual (%)', 'Valor a receber (R$)', 'Observações'), ';');
    foreach ($rows as $r) {
        fputcsv($out, array(
            $r['indicado_nome'],
            $r['indicado_telefone'],
            $statusLabels[$r['status']]['label'] ?? $r['status'],
            $r['data_indicacao'] ? date('d/m/Y', strtotime($r['data_indicacao'])) : '',
            $r['data_fechamento'] ? date('d/m/Y', strtotime($r['data_fechamento'])) : '',
            $r['valor_contrato'] !== null ? number_format($r['valor_contrato'], 2, ',', '.') : '',
            $r['percentual'] !== null ? number_format($r['percentual'], 2, ',', '.') : '',
            $r['valor_a_receber'] !== null ? number_format($r['valor_a_receber'], 2, ',', '.') : '',
            $r['observacoes'],
        ), ';');
    }
    fclose($out);
    exit;
}

// Cards de totais (do mês)
$st = $pdo->prepare("SELECT COUNT(*) FROM colaboradores_indicacoes WHERE colaborador_id = ? AND data_indicacao BETWEEN ? AND ?");
$st->execute(array($reg['id'], $mesIni, $mesFim));
$totalIndicacoesMes = (int)$st->fetchColumn();

$st = $pdo->prepare("SELECT COUNT(*), IFNULL(SUM(valor_a_receber),0)
                     FROM colaboradores_indicacoes
                     WHERE colaborador_id = ? AND status = 'contrato_fechado'
                     AND data_fechamento BETWEEN ? AND ?");
$st->execute(array($reg['id'], $mesIni, $mesFim));
$row = $st->fetch(PDO::FETCH_NUM);
$totalFechadosMes = (int)$row[0];
$totalAReceberMes = (float)$row[1];

// Lista do mês (indicadas OU fechadas no mês)
$st = $pdo->prepare("SELECT * FROM colaboradores_indicacoes
                     WHERE colaborador_id = ?
                     AND ((data_indicacao BETWEEN ? AND ?) OR (data_fechamento BETWEEN ? AND ?))
                     ORDER BY
                        CASE status WHEN 'contrato_fechado' THEN 1 WHEN 'em_negociacao' THEN 2 WHEN 'lead' THEN 3 ELSE 4 END,
                        data_indicacao DESC");
$st->execute(array($reg['id'], $mesIni, $mesFim, $mesIni, $mesFim));
$lista = $st->fetchAll();

$primeiroNome = explode(' ', $reg['nome_completo'])[0];
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>💸 Indicações — Ferreira e Sá</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700;800&family=Open+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root { --petrol-900:#052228; --petrol-700:#173d46; --cobre:#6a3c2c; --cobre-light:#B87333; --nude:#d7ab90; --nude-light:#fff7ed; --bg:#f8f4ef; }
* { box-sizing:border-box; margin:0; padding:0; }
body { font-family:'Open Sans',sans-serif; background:var(--bg); color:#1a1a1a; min-height:100vh; line-height:1.55; }
h1,h2,h3 { font-family:'Playfair Display',serif; color:var(--petrol-900); }

.toolbar { background:linear-gradient(135deg,var(--petrol-900),var(--petrol-700)); color:#fff; padding:1rem 1.5rem; display:flex; align-items:center; gap:1rem; flex-wrap:wrap; justify-content:space-between; position:sticky; top:0; z-index:100; box-shadow:0 4px 14px rgba(0,0,0,.15); }
.toolbar h1 { color:#fff; font-size:1.1rem; }
.toolbar a.btn-back { background:rgba(255,255,255,.15); color:#fff; padding:.5rem 1rem; border-radius:8px; text-decoration:none; font-size:.85rem; font-weight:600; }
.toolbar a.btn-back:hover { background:rgba(255,255,255,.25); }

.container { max-width:980px; margin:1.5rem auto 3rem; padding:0 1.2rem; }
.card-block { background:#fff; border-radius:14px; box-shadow:0 4px 18px rgba(0,0,0,.06); padding:1.5rem 1.5rem; margin-bottom:1.2rem; }
.card-block h2 { font-size:1.3rem; margin-bottom:.5rem; }

.tot-grid { display:grid; gap:.85rem; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); margin-bottom:1.2rem; }
.tot-card { background:#fff; border-radius:14px; padding:1.4rem 1.3rem; box-shadow:0 4px 18px rgba(0,0,0,.06); border-top:4px solid var(--cobre-light); }
.tot-card.verde { border-top-color:#10b981; }
.tot-card.azul { border-top-color:#3b82f6; }
.tot-card .lbl { font-size:.7rem; letter-spacing:2px; font-weight:700; color:var(--cobre); text-transform:uppercase; }
.tot-card .val { font-size:2.2rem; font-weight:900; color:var(--petrol-900); margin-top:.3rem; line-height:1; }
.tot-card.verde .val { color:#047857; }
.tot-card .sub { font-size:.78rem; color:#6b7280; margin-top:.2rem; }

.mes-nav { display:flex; align-items:center; justify-content:space-between; gap:.5rem; background:#fff; border-radius:14px; padding:.85rem 1.1rem; margin-bottom:1rem; box-shadow:0 2px 8px rgba(0,0,0,.04); }
.mes-nav a { background:var(--nude-light); color:var(--cobre); padding:.45rem .9rem; border-radius:8px; text-decoration:none; font-weight:700; font-size:.85rem; }
.mes-nav a:hover { background:var(--nude); color:var(--petrol-900); }
.mes-nav .central { font-weight:800; color:var(--petrol-900); text-transform:capitalize; }

.form-grid { display:grid; gap:.75rem; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); }
.form-grid label { display:block; font-size:.78rem; font-weight:700; color:var(--petrol-900); margin-bottom:.25rem; }
.form-grid input, .form-grid textarea { width:100%; padding:.6rem .8rem; border:1.5px solid #e5e7eb; border-radius:8px; font-size:.88rem; font-family:inherit; }
.form-grid .full { grid-column:1/-1; }
.btn-primary { background:linear-gradient(135deg,var(--petrol-900),var(--petrol-700)); color:#fff; border:0; padding:.75rem 1.5rem; border-radius:10px; font-weight:700; cursor:pointer; font-family:inherit; font-size:.88rem; }
.btn-out { background:#fff; border:1.5px solid var(--cobre-light); color:var(--cobre); padding:.55rem 1rem; border-radius:8px; text-decoration:none; font-weight:700; font-size:.82rem; }

.ind-item { background:#fff; border:1.5px solid #e5e7eb; border-radius:12px; padding:.95rem 1.2rem; margin-bottom:.6rem; }
.ind-item.contrato_fechado { border-color:#34d399; background:#ecfdf5; }
.ind-item.perdido { opacity:.7; }
.ind-item .head { display:flex; justify-content:space-between; align-items:flex-start; gap:.5rem; flex-wrap:wrap; }
.ind-item .nome { font-weight:700; color:var(--petrol-900); font-size:.98rem; }
.ind-item .meta { font-size:.74rem; color:#6b7280; margin-top:.2rem; }
.ind-item .obs { background:#fafafa; border-left:3px solid #d7ab90; padding:.5rem .75rem; border-radius:0 6px 6px 0; margin-top:.5rem; font-size:.84rem; }
.ind-item .receber { background:#d1fae5; color:#065f46; padding:.4rem .8rem; border-radius:8px; font-weight:800; font-size:.95rem; margin-top:.5rem; display:inline-block; }
.status-pill { display:inline-block; padding:.2rem .65rem; border-radius:12px; font-size:.7rem; font-weight:700; }

.sucesso-banner { background:linear-gradient(135deg,#ecfdf5,#d1fae5); border:1.5px solid #34d399; color:#065f46; padding:.8rem 1.1rem; border-radius:10px; margin-bottom:1rem; }
.erro-banner { background:#fef2f2; border:1px solid #fca5a5; color:#991b1b; padding:.8rem 1rem; border-radius:10px; margin-bottom:1rem; }
</style>
</head>
<body>

<div class="toolbar">
    <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap;">
        <a href="./?token=<?= urlencode($token) ?>" class="btn-back">← Página principal</a>
        <h1>💸 Minhas Indicações</h1>
    </div>
</div>

<div class="container">

<?php if (!empty($_GET['ok'])): ?>
    <div class="sucesso-banner">✓ Indicação cadastrada! A gente vai entrar em contato e te avisa do andamento. 💜</div>
<?php endif; ?>
<?php if (!empty($erroForm)): ?>
    <div class="erro-banner">⚠ <?= htmlspecialchars($erroForm) ?></div>
<?php endif; ?>

<!-- Navegação mensal -->
<div class="mes-nav">
    <a href="?token=<?= urlencode($token) ?>&mes=<?= $mesAnt ?>">◀ <?= htmlspecialchars(strftime_pt($mesAnt . '-01')) ?></a>
    <span class="central"><?= htmlspecialchars($mesLabel) ?></span>
    <a href="?token=<?= urlencode($token) ?>&mes=<?= $mesProx ?>"><?= htmlspecialchars(strftime_pt($mesProx . '-01')) ?> ▶</a>
</div>

<!-- Cards de totais -->
<div class="tot-grid">
    <div class="tot-card azul">
        <div class="lbl">Indicações no mês</div>
        <div class="val"><?= $totalIndicacoesMes ?></div>
        <div class="sub">pessoas indicadas</div>
    </div>
    <div class="tot-card">
        <div class="lbl">Contratos fechados</div>
        <div class="val"><?= $totalFechadosMes ?></div>
        <div class="sub">no mês</div>
    </div>
    <div class="tot-card verde">
        <div class="lbl">Você vai receber</div>
        <div class="val">R$ <?= number_format($totalAReceberMes, 2, ',', '.') ?></div>
        <div class="sub">total das indicações fechadas no mês</div>
    </div>
</div>

<!-- Form nova indicação -->
<div class="card-block">
    <h2>➕ Cadastrar nova indicação</h2>
    <p style="color:#6b7280;font-size:.88rem;margin-bottom:1rem;">Conhece alguém que precisa de advogado? Indica pra gente! Quando a indicação virar contrato fechado, você recebe percentual.</p>
    <form method="POST">
        <input type="hidden" name="acao_nova_indicacao" value="1">
        <div class="form-grid">
            <div>
                <label>Nome da pessoa indicada *</label>
                <input name="indicado_nome" required maxlength="150" placeholder="Ex: João Silva">
            </div>
            <div>
                <label>Telefone (opcional)</label>
                <input name="indicado_telefone" placeholder="(00) 00000-0000">
            </div>
            <div class="full">
                <label>Observações (opcional)</label>
                <textarea name="observacoes" rows="2" placeholder="Como conhece, qual demanda, melhor horário pra contato..."></textarea>
            </div>
        </div>
        <div style="margin-top:1rem;">
            <button type="submit" class="btn-primary">📤 Cadastrar indicação</button>
        </div>
    </form>
</div>

<!-- Lista do mês -->
<div class="card-block">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.5rem;margin-bottom:.8rem;">
        <h2 style="margin:0;">📋 Indicações em <?= htmlspecialchars($mesLabel) ?> (<?= count($lista) ?>)</h2>
        <?php if (count($lista) > 0): ?>
            <a href="?token=<?= urlencode($token) ?>&mes=<?= $mesFiltro ?>&csv=1" class="btn-out">📥 Baixar CSV</a>
        <?php endif; ?>
    </div>
    <?php if (empty($lista)): ?>
        <p style="color:#6b7280;font-size:.88rem;text-align:center;padding:2rem;">Nenhuma indicação cadastrada neste mês ainda.</p>
    <?php else: ?>
        <?php foreach ($lista as $i):
            $sInfo = $statusLabels[$i['status']] ?? array('label'=>$i['status'],'cor'=>'#e5e7eb','txt'=>'#6b7280');
        ?>
        <div class="ind-item <?= htmlspecialchars($i['status']) ?>">
            <div class="head">
                <div style="flex:1;min-width:220px;">
                    <div class="nome">👤 <?= htmlspecialchars($i['indicado_nome']) ?></div>
                    <div class="meta">
                        <?php if ($i['indicado_telefone']): ?>📞 <?= htmlspecialchars($i['indicado_telefone']) ?> &middot; <?php endif; ?>
                        Indicada em <?= htmlspecialchars(date('d/m/Y', strtotime($i['data_indicacao']))) ?>
                        <?php if ($i['data_fechamento']): ?>
                            &middot; ✅ Fechado em <?= htmlspecialchars(date('d/m/Y', strtotime($i['data_fechamento']))) ?>
                        <?php endif; ?>
                    </div>
                </div>
                <span class="status-pill" style="background:<?= $sInfo['cor'] ?>;color:<?= $sInfo['txt'] ?>;"><?= htmlspecialchars($sInfo['label']) ?></span>
            </div>
            <?php if (!empty($i['observacoes'])): ?>
                <div class="obs">📝 <?= nl2br(htmlspecialchars($i['observacoes'])) ?></div>
            <?php endif; ?>
            <?php if (!empty($i['anotacao_admin'])): ?>
                <div class="obs" style="background:#eff6ff;border-color:#3b82f6;">
                    💬 <strong>Atualização do escritório:</strong> <?= nl2br(htmlspecialchars($i['anotacao_admin'])) ?>
                </div>
            <?php endif; ?>
            <?php if ($i['status'] === 'contrato_fechado' && $i['valor_a_receber']): ?>
                <div class="receber">💸 Você recebe: R$ <?= number_format($i['valor_a_receber'], 2, ',', '.') ?>
                <?php if ($i['percentual'] && $i['valor_contrato']): ?>
                    <span style="font-weight:500;font-size:.78rem;opacity:.85;">
                        (<?= number_format($i['percentual'], 0, ',', '.') ?>% sobre R$ <?= number_format($i['valor_contrato'], 2, ',', '.') ?>)
                    </span>
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
