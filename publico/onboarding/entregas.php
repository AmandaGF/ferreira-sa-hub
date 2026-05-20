<?php
/**
 * Página pública: Relatório Mensal de Entregas do PRESTADOR DE SERVIÇOS.
 * - Cadastrar entrega (tipo, descrição, link, data, métrica).
 * - Lista do mês selecionado com nav ◀ ▶.
 * - Status: pendente / aprovada (definido pelo admin no Hub).
 *
 * Acessível só por token (mesmo padrão dos outros forms públicos do onboarding).
 */
require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/database.php';
@session_start();

$pdo = db();
$token = isset($_GET['token']) ? trim($_GET['token']) : '';
if (!$token || !preg_match('/^[a-f0-9]{16,48}$/', $token)) { http_response_code(404); exit('Link inválido.'); }

$st = $pdo->prepare("SELECT * FROM colaboradores_onboarding WHERE token = ? AND status != 'arquivado'");
$st->execute(array($token));
$reg = $st->fetch();
if (!$reg) { http_response_code(404); exit('Link inválido.'); }

$sessKey = 'onb_auth_' . $token;
if (empty($_SESSION[$sessKey])) { header('Location: ./?token=' . urlencode($token)); exit; }

// Self-heal
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS colaboradores_entregas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        colaborador_id INT NOT NULL,
        mes_ref CHAR(7) NOT NULL,                /* YYYY-MM */
        tipo VARCHAR(30) NOT NULL DEFAULT 'outro',
        descricao VARCHAR(500) NOT NULL,
        link VARCHAR(500) NULL,
        data_entrega DATE NULL,
        metricas TEXT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pendente',  /* pendente / aprovada / ajustar */
        comentario_admin TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_col_mes (colaborador_id, mes_ref),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

$colId = (int)$reg['id'];
$err = ''; $ok = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'nova') {
    $tipo = trim($_POST['tipo'] ?? 'outro');
    $desc = trim($_POST['descricao'] ?? '');
    $link = trim($_POST['link'] ?? '');
    $dEnt = trim($_POST['data_entrega'] ?? '');
    $met  = trim($_POST['metricas'] ?? '');
    $tiposPerm = array('post','reels','campanha','relatorio','reuniao','design','outro');
    if (!in_array($tipo, $tiposPerm, true)) $tipo = 'outro';
    if ($desc === '') { $err = 'Descreva a entrega.'; }
    else {
        $dEntOk = $dEnt ?: date('Y-m-d');
        $mesRef = substr($dEntOk, 0, 7);
        $pdo->prepare("INSERT INTO colaboradores_entregas
            (colaborador_id, mes_ref, tipo, descricao, link, data_entrega, metricas)
            VALUES (?,?,?,?,?,?,?)")->execute(array(
                $colId, $mesRef, $tipo, mb_substr($desc, 0, 500), $link ?: null, $dEntOk, $met ?: null
            ));
        $ok = 'Entrega registrada!';
        // notify admins (best-effort)
        try {
            if (function_exists('notify_admins')) {
                notify_admins('📈 Nova entrega — ' . $reg['nome_completo'],
                    '[' . $tipo . '] ' . mb_substr($desc, 0, 100),
                    '/conecta/modules/admin/onboarding_entregas.php?col=' . $colId);
            }
        } catch (Exception $e) {}
    }
}

// Mês visualizado
$mesView = $_GET['m'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $mesView)) $mesView = date('Y-m');
$mesAnt = date('Y-m', strtotime($mesView . '-01 -1 month'));
$mesProx = date('Y-m', strtotime($mesView . '-01 +1 month'));
$mesLabel = strftime ? '' : '';
$mesLabel = (function($m){
    $meses = array(1=>'Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro');
    list($y,$mm) = explode('-', $m); return $meses[(int)$mm] . '/' . $y;
})($mesView);

$st = $pdo->prepare("SELECT * FROM colaboradores_entregas WHERE colaborador_id = ? AND mes_ref = ? ORDER BY data_entrega DESC, id DESC");
$st->execute(array($colId, $mesView));
$entregas = $st->fetchAll();

$cntTotal = count($entregas);
$cntAprov = 0; foreach ($entregas as $e) if ($e['status'] === 'aprovada') $cntAprov++;

$tipoLabel = array('post'=>'📷 Post','reels'=>'🎥 Reels','campanha'=>'📣 Campanha','relatorio'=>'📊 Relatório','reuniao'=>'🤝 Reunião','design'=>'🎨 Design','outro'=>'• Outro');
$statusLabel = array('pendente'=>array('⏳ Pendente','#f59e0b'),'aprovada'=>array('✓ Aprovada','#059669'),'ajustar'=>array('↻ Ajustar','#dc2626'));
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Entregas Mensais — <?= htmlspecialchars($reg['nome_completo']) ?></title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Inter',system-ui,sans-serif;background:#f6f3ee;color:#1c1c1c;line-height:1.55;padding:20px 16px 60px}
.wrap{max-width:880px;margin:0 auto}
.topbar{display:flex;justify-content:space-between;align-items:center;margin-bottom:1.2rem;flex-wrap:wrap;gap:.6rem}
.back{color:#052228;text-decoration:none;font-size:.85rem;font-weight:600}
h1{font-size:1.6rem;color:#052228;margin-bottom:.3rem}
.sub{color:#666;font-size:.9rem;margin-bottom:1.4rem}
.card{background:#fff;border-radius:14px;box-shadow:0 8px 22px rgba(5,34,40,.06);padding:1.2rem;margin-bottom:1rem;border:1px solid rgba(5,34,40,.08)}
.kpis{display:grid;grid-template-columns:repeat(2,1fr);gap:.7rem;margin-bottom:1rem}
.kpi{background:#fff;border:1px solid rgba(5,34,40,.08);border-radius:12px;padding:.9rem 1rem;text-align:center}
.kpi .n{font-size:1.6rem;font-weight:800;color:#052228;line-height:1}
.kpi .l{font-size:.72rem;color:#6f7370;text-transform:uppercase;letter-spacing:.08em;margin-top:.2rem}
.monthnav{display:flex;align-items:center;justify-content:space-between;gap:.6rem;margin-bottom:1rem}
.monthnav a{background:#fff;border:1px solid rgba(5,34,40,.12);border-radius:8px;padding:.5rem .8rem;text-decoration:none;color:#052228;font-weight:600;font-size:.85rem}
.monthnav .mes{font-weight:700;color:#052228;font-size:1.05rem}
label{display:block;font-size:.75rem;font-weight:600;color:#6f7370;text-transform:uppercase;letter-spacing:.06em;margin:.6rem 0 .3rem}
input,select,textarea{width:100%;padding:.7rem .85rem;border:1px solid rgba(5,34,40,.16);border-radius:8px;font-family:inherit;font-size:.95rem;background:#fafafa}
input:focus,select:focus,textarea:focus{outline:none;border-color:#B87333;background:#fff}
.row{display:grid;grid-template-columns:1fr 1fr;gap:.6rem}
@media(max-width:600px){.row{grid-template-columns:1fr}}
.btn{background:#B87333;color:#fff;border:0;padding:.85rem 1.6rem;border-radius:8px;font-weight:700;cursor:pointer;font-size:.92rem;margin-top:1rem}
.btn:hover{background:#a25a26}
.alert{padding:.7rem 1rem;border-radius:8px;margin-bottom:1rem;font-size:.88rem}
.alert.ok{background:#d1fae5;color:#065f46;border:1px solid #6ee7b7}
.alert.err{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5}
.ent{border-left:4px solid #ccc;padding:.85rem 1rem;margin-bottom:.6rem;background:#fff;border-radius:0 10px 10px 0;border:1px solid rgba(5,34,40,.08)}
.ent.aprov{border-left-color:#059669}
.ent.ajust{border-left-color:#dc2626}
.ent.pend{border-left-color:#f59e0b}
.ent-head{display:flex;justify-content:space-between;align-items:flex-start;gap:.5rem;flex-wrap:wrap;margin-bottom:.3rem}
.ent-tipo{font-size:.7rem;background:#052228;color:#fff;padding:.18rem .5rem;border-radius:5px;font-weight:600}
.ent-status{font-size:.7rem;padding:.18rem .5rem;border-radius:5px;font-weight:700;color:#fff}
.ent-data{font-size:.72rem;color:#6f7370}
.ent-desc{font-size:.95rem;color:#1c1c1c;margin:.3rem 0}
.ent-met{font-size:.82rem;color:#6f7370;font-style:italic;margin-top:.2rem}
.ent-link{font-size:.78rem;color:#B87333}
.ent-com{margin-top:.5rem;padding:.5rem .7rem;background:#fef3c7;border-radius:6px;font-size:.82rem;color:#854d0e}
</style>
</head>
<body>
<div class="wrap">
    <div class="topbar">
        <a class="back" href="./?token=<?= htmlspecialchars($token) ?>">← Voltar pro portal</a>
        <span style="font-size:.85rem;color:#6f7370;">📈 Olá, <strong><?= htmlspecialchars(explode(' ', $reg['nome_completo'])[0]) ?></strong></span>
    </div>

    <h1>📈 Entregas Mensais</h1>
    <p class="sub">Cadastre o que você entregou (posts, campanhas, relatórios, reuniões). Aprovação ou comentário do escritório aparece em cada item.</p>

    <?php if ($ok): ?><div class="alert ok">✓ <?= htmlspecialchars($ok) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="alert err">⚠ <?= htmlspecialchars($err) ?></div><?php endif; ?>

    <div class="card">
        <h2 style="font-size:1.05rem;color:#052228;margin-bottom:.4rem;">➕ Nova entrega</h2>
        <form method="POST">
            <input type="hidden" name="acao" value="nova">
            <div class="row">
                <div>
                    <label>Tipo</label>
                    <select name="tipo" required>
                        <?php foreach ($tipoLabel as $k=>$lbl): ?>
                            <option value="<?= $k ?>"><?= $lbl ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Data da entrega</label>
                    <input type="date" name="data_entrega" value="<?= date('Y-m-d') ?>">
                </div>
            </div>
            <label>Descrição *</label>
            <textarea name="descricao" rows="2" maxlength="500" required placeholder="Ex.: Post sobre Direito de Família — alcance orgânico no Instagram"></textarea>
            <label>Link (opcional)</label>
            <input type="url" name="link" placeholder="https://instagram.com/p/...">
            <label>Métricas / observações (opcional)</label>
            <textarea name="metricas" rows="2" placeholder="Ex.: 1.230 visualizações, 87 curtidas, 12 compartilhamentos"></textarea>
            <button class="btn" type="submit">Registrar entrega</button>
        </form>
    </div>

    <div class="kpis">
        <div class="kpi"><div class="n"><?= $cntTotal ?></div><div class="l">no mês</div></div>
        <div class="kpi"><div class="n"><?= $cntAprov ?></div><div class="l">aprovadas</div></div>
    </div>

    <div class="monthnav">
        <a href="?token=<?= htmlspecialchars($token) ?>&m=<?= $mesAnt ?>">◀</a>
        <span class="mes"><?= $mesLabel ?></span>
        <a href="?token=<?= htmlspecialchars($token) ?>&m=<?= $mesProx ?>">▶</a>
    </div>

    <div class="card">
        <h2 style="font-size:1.05rem;color:#052228;margin-bottom:.7rem;">Entregas de <?= $mesLabel ?></h2>
        <?php if (empty($entregas)): ?>
            <p style="color:#6f7370;font-size:.9rem;">Nenhuma entrega registrada neste mês.</p>
        <?php else: foreach ($entregas as $e):
            $cls = $e['status']==='aprovada'?'aprov':($e['status']==='ajustar'?'ajust':'pend');
            list($sl, $sc) = $statusLabel[$e['status']] ?? array('—','#888');
        ?>
        <div class="ent <?= $cls ?>">
            <div class="ent-head">
                <div>
                    <span class="ent-tipo"><?= htmlspecialchars($tipoLabel[$e['tipo']] ?? $e['tipo']) ?></span>
                    <span class="ent-status" style="background:<?= $sc ?>;"><?= $sl ?></span>
                </div>
                <span class="ent-data"><?= $e['data_entrega'] ? date('d/m/Y', strtotime($e['data_entrega'])) : '' ?></span>
            </div>
            <div class="ent-desc"><?= nl2br(htmlspecialchars($e['descricao'])) ?></div>
            <?php if ($e['metricas']): ?><div class="ent-met"><?= htmlspecialchars($e['metricas']) ?></div><?php endif; ?>
            <?php if ($e['link']): ?><div class="ent-link">🔗 <a href="<?= htmlspecialchars($e['link']) ?>" target="_blank" rel="noopener" style="color:#B87333;"><?= htmlspecialchars(mb_strimwidth($e['link'], 0, 60, '…')) ?></a></div><?php endif; ?>
            <?php if ($e['comentario_admin']): ?><div class="ent-com">💬 Escritório: <?= htmlspecialchars($e['comentario_admin']) ?></div><?php endif; ?>
        </div>
        <?php endforeach; endif; ?>
    </div>
</div>
</body>
</html>
