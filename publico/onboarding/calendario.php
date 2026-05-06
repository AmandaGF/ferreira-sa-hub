<?php
/**
 * Página pública: Calendário/Reuniões da colaboradora.
 *
 * Mostra:
 *  - Próximos 60 dias da agenda do escritório onde ela é responsável
 *    OU mencionada nos participantes (match por nome ou e-mail).
 *  - Botão pra pedir reunião 1:1 (manda pra /solicitacoes.php com tipo
 *    pré-selecionado 'feedback').
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

$primeiroNome = explode(' ', $reg['nome_completo'])[0];

// Tenta achar o user_id da colaboradora pelo email institucional
$userIdColab = 0;
if (!empty($reg['email_institucional'])) {
    try {
        $stU = $pdo->prepare("SELECT id FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1");
        $stU->execute(array($reg['email_institucional']));
        $userIdColab = (int)$stU->fetchColumn();
    } catch (Exception $e) {}
}

// Mês alvo (default mês atual)
$mesFiltro = $_GET['mes'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $mesFiltro)) $mesFiltro = date('Y-m');
$mesIni = $mesFiltro . '-01';
$mesFim = date('Y-m-t', strtotime($mesIni));
$mesAnt = date('Y-m', strtotime($mesIni . ' -1 month'));
$mesProx = date('Y-m', strtotime($mesIni . ' +1 month'));

// Busca eventos do mês: responsavel_id OU participantes contém nome OU email
$eventos = array();
try {
    $likeNome = '%' . $reg['nome_completo'] . '%';
    $likeEmail = !empty($reg['email_institucional']) ? '%' . $reg['email_institucional'] . '%' : '%@@@inexistente@@@%';
    $params = array(
        $userIdColab ?: -1,
        $likeNome,
        $likeEmail,
        $mesIni . ' 00:00:00',
        $mesFim . ' 23:59:59',
    );
    $st = $pdo->prepare(
        "SELECT id, titulo, tipo, modalidade, data_inicio, data_fim, dia_todo,
                local, meet_link, descricao, status, participantes
         FROM agenda_eventos
         WHERE (responsavel_id = ? OR participantes LIKE ? OR participantes LIKE ?)
           AND data_inicio BETWEEN ? AND ?
           AND status NOT IN ('cancelado', 'excluido')
         ORDER BY data_inicio ASC"
    );
    $st->execute($params);
    $eventos = $st->fetchAll();
} catch (Exception $e) {}

// Próximos 7 dias (resumo destacado)
$proximos = array();
try {
    $likeNome = '%' . $reg['nome_completo'] . '%';
    $likeEmail = !empty($reg['email_institucional']) ? '%' . $reg['email_institucional'] . '%' : '%@@@inexistente@@@%';
    $st = $pdo->prepare(
        "SELECT id, titulo, tipo, data_inicio, dia_todo, local, meet_link
         FROM agenda_eventos
         WHERE (responsavel_id = ? OR participantes LIKE ? OR participantes LIKE ?)
           AND data_inicio BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)
           AND status NOT IN ('cancelado', 'excluido')
         ORDER BY data_inicio ASC LIMIT 5"
    );
    $st->execute(array($userIdColab ?: -1, $likeNome, $likeEmail));
    $proximos = $st->fetchAll();
} catch (Exception $e) {}

$tipoLabels = array(
    'reuniao_cliente'    => array('lbl' => 'Reunião com cliente', 'icon' => '🤝', 'cor' => '#3b82f6'),
    'reuniao_interna'    => array('lbl' => 'Reunião interna',     'icon' => '👥', 'cor' => '#6366f1'),
    'reuniao_feedback'   => array('lbl' => 'Reunião de feedback', 'icon' => '💬', 'cor' => '#10b981'),
    'audiencia'          => array('lbl' => 'Audiência',           'icon' => '⚖️', 'cor' => '#dc2626'),
    'prazo'              => array('lbl' => 'Prazo',               'icon' => '⏰', 'cor' => '#f59e0b'),
    'treinamento'        => array('lbl' => 'Treinamento',         'icon' => '🎓', 'cor' => '#8b5cf6'),
    'onboarding'         => array('lbl' => 'Onboarding',          'icon' => '👋', 'cor' => '#06b6d4'),
);

$meses = array('janeiro','fevereiro','março','abril','maio','junho','julho','agosto','setembro','outubro','novembro','dezembro');
$mesLabel = $meses[(int)substr($mesFiltro, 5, 2) - 1] . ' de ' . substr($mesFiltro, 0, 4);

function fmt_data_evento($iso, $diaTodo) {
    if (!$iso) return '';
    $dt = new DateTime($iso);
    $diasSemana = array('dom','seg','ter','qua','qui','sex','sáb');
    $diaSem = $diasSemana[(int)$dt->format('w')];
    if ($diaTodo) return $diaSem . ', ' . $dt->format('d/m');
    return $diaSem . ', ' . $dt->format('d/m \à\s H:i');
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>📅 Calendário — Ferreira e Sá</title>
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

.container { max-width:880px; margin:1.5rem auto 3rem; padding:0 1.2rem; }
.card-block { background:#fff; border-radius:14px; box-shadow:0 4px 18px rgba(0,0,0,.06); padding:1.5rem 1.5rem; margin-bottom:1.2rem; }
.card-block h2 { font-size:1.2rem; margin-bottom:.6rem; }

.acoes-rapidas { display:flex; gap:.6rem; flex-wrap:wrap; margin-bottom:1rem; }
.acao-btn { background:var(--nude-light); border:1.5px solid var(--nude); color:var(--cobre); padding:.7rem 1.1rem; border-radius:10px; font-weight:700; text-decoration:none; font-size:.85rem; transition:all .15s; }
.acao-btn:hover { background:var(--nude); transform:translateY(-1px); }
.acao-btn.primary { background:linear-gradient(135deg,var(--petrol-900),var(--petrol-700)); color:#fff; border-color:var(--petrol-900); }
.acao-btn.primary:hover { background:var(--petrol-700); }

.proximo-card { background:linear-gradient(135deg,#ecfdf5,#d1fae5); border:1.5px solid #6ee7b7; border-radius:12px; padding:1rem 1.2rem; margin-bottom:.7rem; display:flex; gap:.8rem; align-items:center; }
.proximo-emoji { font-size:1.8rem; line-height:1; flex-shrink:0; }
.proximo-titulo { font-weight:700; color:#065f46; font-size:.98rem; }
.proximo-meta { font-size:.78rem; color:#047857; margin-top:.2rem; }

.mes-nav { display:flex; align-items:center; justify-content:space-between; gap:.5rem; background:#fff; border-radius:14px; padding:.85rem 1.1rem; margin-bottom:1rem; box-shadow:0 2px 8px rgba(0,0,0,.04); }
.mes-nav a { background:var(--nude-light); color:var(--cobre); padding:.45rem .9rem; border-radius:8px; text-decoration:none; font-weight:700; font-size:.85rem; }
.mes-nav .central { font-weight:800; color:var(--petrol-900); text-transform:capitalize; font-size:1.05rem; }

.evento-item { background:#fff; border:1.5px solid #e5e7eb; border-radius:12px; padding:1rem 1.2rem; margin-bottom:.7rem; display:flex; gap:.8rem; align-items:flex-start; transition:all .15s; }
.evento-item:hover { border-color:var(--cobre-light); transform:translateX(2px); }
.evento-icone { font-size:1.6rem; line-height:1; flex-shrink:0; padding-top:.1rem; }
.evento-titulo { font-weight:700; color:var(--petrol-900); font-size:.98rem; }
.evento-meta { font-size:.78rem; color:#6b7280; margin-top:.25rem; }
.evento-tipo-pill { display:inline-block; padding:.15rem .55rem; border-radius:10px; font-size:.65rem; font-weight:700; color:#fff; margin-right:.4rem; }
.evento-link { display:inline-block; margin-top:.4rem; font-size:.78rem; color:var(--cobre-light); text-decoration:none; font-weight:700; }
.evento-link:hover { text-decoration:underline; }

.empty-state { text-align:center; padding:2.5rem 1rem; color:#6b7280; }
.empty-emoji { font-size:3rem; margin-bottom:.5rem; }
</style>
</head>
<body>

<div class="toolbar">
    <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap;">
        <a href="./?token=<?= htmlspecialchars($token) ?>" class="btn-back">← Página principal</a>
        <h1>📅 Meu Calendário</h1>
    </div>
    <span style="font-size:.78rem;opacity:.85;">Olá, <?= htmlspecialchars($primeiroNome) ?> 💜</span>
</div>

<div class="container">

    <!-- Ações rápidas -->
    <div class="acoes-rapidas">
        <a href="solicitacoes.php?token=<?= htmlspecialchars($token) ?>&pre_tipo=feedback" class="acao-btn primary">💬 Pedir reunião 1:1 / feedback</a>
        <a href="solicitacoes.php?token=<?= htmlspecialchars($token) ?>" class="acao-btn">📩 Outras solicitações</a>
    </div>

    <!-- Próximos 7 dias destacados -->
    <?php if (!empty($proximos)): ?>
    <div class="card-block">
        <h2>⏰ Seus próximos 7 dias</h2>
        <p style="color:#6b7280;font-size:.85rem;margin-bottom:1rem;">Eventos agendados onde você participa.</p>
        <?php foreach ($proximos as $p):
            $info = $tipoLabels[$p['tipo']] ?? array('icon' => '📌', 'lbl' => $p['tipo'], 'cor' => '#6b7280');
        ?>
            <div class="proximo-card">
                <div class="proximo-emoji"><?= $info['icon'] ?></div>
                <div style="flex:1;">
                    <div class="proximo-titulo"><?= htmlspecialchars($p['titulo']) ?></div>
                    <div class="proximo-meta">
                        📅 <?= htmlspecialchars(fmt_data_evento($p['data_inicio'], $p['dia_todo'])) ?>
                        <?php if (!empty($p['local'])): ?> &middot; 📍 <?= htmlspecialchars($p['local']) ?><?php endif; ?>
                        <?php if (!empty($p['meet_link'])): ?> &middot; 💻 <a href="<?= htmlspecialchars($p['meet_link']) ?>" target="_blank" style="color:#047857;font-weight:700;">Entrar na reunião</a><?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Navegação mensal -->
    <div class="mes-nav">
        <a href="?token=<?= urlencode($token) ?>&mes=<?= $mesAnt ?>">◀ <?= htmlspecialchars($meses[(int)substr($mesAnt,5,2)-1]) ?>/<?= substr($mesAnt,0,4) ?></a>
        <span class="central"><?= htmlspecialchars($mesLabel) ?></span>
        <a href="?token=<?= urlencode($token) ?>&mes=<?= $mesProx ?>"><?= htmlspecialchars($meses[(int)substr($mesProx,5,2)-1]) ?>/<?= substr($mesProx,0,4) ?> ▶</a>
    </div>

    <!-- Lista de eventos do mês -->
    <div class="card-block">
        <h2>🗓️ Eventos em <?= htmlspecialchars($mesLabel) ?> (<?= count($eventos) ?>)</h2>
        <?php if (empty($eventos)): ?>
            <div class="empty-state">
                <div class="empty-emoji">📭</div>
                <p style="font-size:1rem;color:var(--petrol-900);font-weight:700;margin-bottom:.4rem;">Nenhum evento agendado neste mês</p>
                <p style="font-size:.85rem;">Reuniões e eventos aparecem aqui automaticamente quando você for incluída.</p>
                <p style="font-size:.85rem;margin-top:.6rem;">Quer marcar uma reunião 1:1? Use o botão verde lá em cima ↑</p>
            </div>
        <?php else: ?>
            <?php foreach ($eventos as $ev):
                $info = $tipoLabels[$ev['tipo']] ?? array('icon' => '📌', 'lbl' => ucfirst($ev['tipo']), 'cor' => '#6b7280');
            ?>
                <div class="evento-item">
                    <div class="evento-icone"><?= $info['icon'] ?></div>
                    <div style="flex:1;">
                        <div>
                            <span class="evento-tipo-pill" style="background:<?= $info['cor'] ?>;"><?= htmlspecialchars($info['lbl']) ?></span>
                            <span class="evento-titulo"><?= htmlspecialchars($ev['titulo']) ?></span>
                        </div>
                        <div class="evento-meta">
                            📅 <?= htmlspecialchars(fmt_data_evento($ev['data_inicio'], $ev['dia_todo'])) ?>
                            <?php if (!empty($ev['data_fim']) && !$ev['dia_todo']): ?>
                                até <?= htmlspecialchars(date('H:i', strtotime($ev['data_fim']))) ?>
                            <?php endif; ?>
                            <?php if (!empty($ev['local'])): ?>
                                <br>📍 <?= htmlspecialchars($ev['local']) ?>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($ev['descricao'])): ?>
                            <div style="font-size:.82rem;color:#374151;margin-top:.4rem;background:#fafafa;padding:.5rem .7rem;border-radius:6px;border-left:3px solid <?= $info['cor'] ?>;">
                                <?= nl2br(htmlspecialchars($ev['descricao'])) ?>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($ev['meet_link'])): ?>
                            <a href="<?= htmlspecialchars($ev['meet_link']) ?>" target="_blank" class="evento-link">💻 Entrar na reunião →</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

</div>

</body>
</html>
