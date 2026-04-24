<?php
/**
 * Ferreira & Sá Hub — Datas Especiais / Aniversários (v2)
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();

$pageTitle = 'Aniversariantes';
$pdo = db();
$currentYear = (int)date('Y');
$currentMonth = (int)date('n');
$currentDay = (int)date('j');

// Filtros
$filtroMes = isset($_GET['mes']) ? (int)$_GET['mes'] : $currentMonth;
$filtroVista = isset($_GET['vista']) ? $_GET['vista'] : 'mes';

$meses = array('','Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro');

// Marcar parabéns enviado
if (isset($_GET['marcar']) && isset($_GET['cid'])) {
    $cid = (int)$_GET['cid'];
    if ($cid) {
        try {
            $pdo->prepare("INSERT IGNORE INTO birthday_greetings (client_id, year, sent_by) VALUES (?, ?, ?)")
                ->execute(array($cid, $currentYear, current_user_id()));
        } catch (Exception $e) {}
        flash_set('success', 'Parabéns marcado como enviado!');
        redirect(module_url('aniversarios', '?mes=' . $filtroMes . '&vista=' . $filtroVista));
    }
}

// Desmarcar
if (isset($_GET['desmarcar']) && isset($_GET['cid'])) {
    $cid = (int)$_GET['cid'];
    if ($cid) {
        try {
            $pdo->prepare("DELETE FROM birthday_greetings WHERE client_id = ? AND year = ?")
                ->execute(array($cid, $currentYear));
        } catch (Exception $e) {}
        redirect(module_url('aniversarios', '?mes=' . $filtroMes . '&vista=' . $filtroVista));
    }
}

// Buscar aniversariantes do mês selecionado
$anivMes = array();
try {
    $anivMes = $pdo->prepare(
        "SELECT c.id, c.name, c.phone, c.email, c.birth_date,
         DAY(c.birth_date) as dia,
         TIMESTAMPDIFF(YEAR, c.birth_date, CURDATE()) as idade,
         (SELECT 1 FROM birthday_greetings bg WHERE bg.client_id = c.id AND bg.year = ?) as parabens_enviado
         FROM clients c
         WHERE c.birth_date IS NOT NULL AND MONTH(c.birth_date) = ?
         ORDER BY DAY(c.birth_date) ASC"
    );
    $anivMes->execute(array($currentYear, $filtroMes));
    $anivMes = $anivMes->fetchAll();
} catch (Exception $e) {}

// Aniversariantes de hoje
$anivHoje = array();
foreach ($anivMes as $a) {
    if ((int)$a['dia'] === $currentDay && $filtroMes === $currentMonth) {
        $anivHoje[] = $a;
    }
}

// Aniversariantes da semana (próximos 7 dias)
$anivSemana = array();
try {
    $anivSemana = $pdo->query(
        "SELECT c.id, c.name, c.phone, c.email, c.birth_date,
         DAY(c.birth_date) as dia, MONTH(c.birth_date) as mes_aniv,
         TIMESTAMPDIFF(YEAR, c.birth_date, CURDATE()) as idade
         FROM clients c
         WHERE c.birth_date IS NOT NULL
         AND (
            (MONTH(c.birth_date) = MONTH(CURDATE()) AND DAY(c.birth_date) BETWEEN DAY(CURDATE()) AND DAY(CURDATE()) + 7)
            OR (MONTH(c.birth_date) = MONTH(CURDATE()) + 1 AND DAY(c.birth_date) <= DAY(CURDATE()) + 7 - DAY(LAST_DAY(CURDATE())))
         )
         ORDER BY MONTH(c.birth_date), DAY(c.birth_date)"
    )->fetchAll();
} catch (Exception $e) {}

// Estatísticas
$totalComAniversario = 0;
try { $totalComAniversario = (int)$pdo->query("SELECT COUNT(*) FROM clients WHERE birth_date IS NOT NULL")->fetchColumn(); } catch (Exception $e) {}

$parabenizadosMes = 0;
try { $parabenizadosMes = (int)$pdo->prepare("SELECT COUNT(*) FROM birthday_greetings WHERE year = ? AND MONTH(sent_at) = ?")->execute(array($currentYear, $filtroMes)); $parabenizadosMes = (int)$pdo->query("SELECT COUNT(*) FROM birthday_greetings WHERE year = $currentYear")->fetchColumn(); } catch (Exception $e) {}

// Mensagem do mês
$msgMes = null;
try {
    $stmt = $pdo->prepare("SELECT * FROM birthday_messages WHERE month = ?");
    $stmt->execute(array($filtroMes));
    $msgMes = $stmt->fetch();
} catch (Exception $e) {}

// Gráfico por mês
$anivPorMes = array_fill(1, 12, 0);
try {
    $rows = $pdo->query("SELECT MONTH(birth_date) as m, COUNT(*) as t FROM clients WHERE birth_date IS NOT NULL GROUP BY MONTH(birth_date)")->fetchAll();
    foreach ($rows as $r) { $anivPorMes[(int)$r['m']] = (int)$r['t']; }
} catch (Exception $e) {}

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.aniv-top { display:flex; gap:1rem; margin-bottom:1.25rem; flex-wrap:wrap; }
.aniv-stat { background:var(--bg-card); border-radius:var(--radius-lg); border:1px solid var(--border); padding:.75rem 1.25rem; display:flex; align-items:center; gap:.75rem; min-width:130px; }
.aniv-stat .num { font-size:1.5rem; font-weight:800; color:var(--petrol-900); }
.aniv-stat .lbl { font-size:.68rem; color:var(--text-muted); text-transform:uppercase; }

.month-nav { display:flex; gap:.3rem; flex-wrap:wrap; margin-bottom:1rem; }
.month-btn { padding:.35rem .65rem; font-size:.72rem; font-weight:600; border:1.5px solid var(--border); border-radius:100px; background:var(--bg-card); color:var(--text-muted); cursor:pointer; text-decoration:none; }
.month-btn:hover { border-color:var(--petrol-300); }
.month-btn.active { background:var(--petrol-900); color:#fff; border-color:var(--petrol-900); }
.month-btn.has-today { border-color:var(--rose); }

.aniv-layout { display:grid; grid-template-columns:1fr 300px; gap:1rem; }
@media(max-width:900px) { .aniv-layout { grid-template-columns:1fr; } }

.aniv-card {
    background:var(--bg-card); border-radius:var(--radius-lg); border:1px solid var(--border);
    padding:.85rem 1rem; display:flex; align-items:center; gap:.75rem;
    margin-bottom:.5rem; transition:all var(--transition);
}
.aniv-card:hover { box-shadow:var(--shadow-sm); }
.aniv-card.today { border-left:4px solid #059669; background:rgba(5,150,105,.04); }
.aniv-card.soon { border-left:4px solid #6366f1; }
.aniv-card.past { border-left:4px solid #d1d5db; opacity:.7; }
.aniv-card.sent { border-left:4px solid var(--rose); }

.aniv-avatar {
    width:42px; height:42px; border-radius:50%;
    display:flex; align-items:center; justify-content:center;
    font-size:.75rem; font-weight:700; flex-shrink:0; color:#fff;
}
.av-today { background:linear-gradient(135deg,#059669,#10b981); }
.av-soon { background:linear-gradient(135deg,#6366f1,#8b5cf6); }
.av-past { background:#9ca3af; }
.av-sent { background:linear-gradient(135deg,var(--rose),var(--rose-dark)); }

.aniv-info { flex:1; min-width:0; }
.aniv-name { font-size:.85rem; font-weight:700; color:var(--petrol-900); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.aniv-detail { font-size:.7rem; color:var(--text-muted); display:flex; gap:.5rem; flex-wrap:wrap; margin-top:.15rem; }
.aniv-actions { display:flex; gap:.25rem; flex-shrink:0; align-items:center; }
.aniv-tag { font-size:.6rem; font-weight:700; padding:.15rem .4rem; border-radius:4px; color:#fff; }
.tag-hoje { background:#059669; }
.tag-amanha { background:#0ea5e9; }
.tag-semana { background:#6366f1; }
.tag-passou { background:#9ca3af; }
.tag-enviado { background:var(--rose); }

.msg-box { background:var(--bg-card); border-radius:var(--radius-lg); border:1px solid var(--border); padding:1rem; margin-bottom:1rem; }
.msg-box h4 { font-size:.85rem; font-weight:700; color:var(--petrol-900); margin-bottom:.5rem; }
.msg-body { font-size:.78rem; color:var(--text-muted); white-space:pre-wrap; line-height:1.5; max-height:200px; overflow-y:auto; background:var(--bg); padding:.75rem; border-radius:var(--radius); }
.msg-actions { display:flex; gap:.35rem; margin-top:.5rem; }

.cal-box { background:var(--bg-card); border-radius:var(--radius-lg); border:1px solid var(--border); padding:1rem; }
.cal-box h4 { font-size:.85rem; font-weight:700; color:var(--petrol-900); margin-bottom:.75rem; }
.cal-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:.75rem; }
.cal-header h4 { margin:0; }
.cal-nav { background:none; border:1px solid var(--border); border-radius:6px; width:28px; height:28px; cursor:pointer; display:flex; align-items:center; justify-content:center; font-size:.75rem; color:var(--text-muted); }
.cal-nav:hover { background:var(--bg); border-color:var(--petrol-300); }
.cal-grid { display:grid; grid-template-columns:repeat(7,1fr); gap:2px; text-align:center; font-size:.7rem; }
.cal-head { font-weight:700; color:var(--text-muted); padding:.3rem; }
.cal-day { padding:.3rem; border-radius:4px; cursor:default; position:relative; }
.cal-day.empty { }
.cal-day.has-bday { background:rgba(99,102,241,.15); color:#6366f1; font-weight:700; cursor:pointer; }
.cal-day.has-bday:hover { background:rgba(99,102,241,.3); }
.cal-day.today { background:var(--rose); color:#fff; font-weight:700; border-radius:50%; }
.cal-day.today.has-bday { background:#059669; cursor:pointer; }
.cal-day.today.has-bday:hover { background:#047857; }
.cal-day.selected { outline:2px solid var(--petrol-900); outline-offset:1px; border-radius:4px; }

.cal-popup { background:var(--bg-card); border:1px solid var(--border); border-radius:var(--radius-lg); box-shadow:var(--shadow-md); padding:.75rem; margin-top:.5rem; display:none; }
.cal-popup.active { display:block; }
.cal-popup h5 { font-size:.78rem; font-weight:700; color:var(--petrol-900); margin-bottom:.5rem; }
.cal-popup-item { display:flex; align-items:center; gap:.5rem; padding:.35rem 0; border-bottom:1px solid var(--border); font-size:.72rem; }
.cal-popup-item:last-child { border-bottom:none; }
.cal-popup-item .name { font-weight:600; color:var(--petrol-900); }
.cal-popup-item .meta { color:var(--text-muted); font-size:.65rem; }
</style>

<!-- Stats -->
<div class="aniv-top">
    <div class="aniv-stat"><span style="font-size:1.2rem;">🎂</span><div><div class="num"><?= count($anivHoje) ?></div><div class="lbl">Hoje</div></div></div>
    <div class="aniv-stat"><span style="font-size:1.2rem;">📅</span><div><div class="num"><?= count($anivMes) ?></div><div class="lbl"><?= $meses[$filtroMes] ?></div></div></div>
    <div class="aniv-stat"><span style="font-size:1.2rem;">👥</span><div><div class="num"><?= $totalComAniversario ?></div><div class="lbl">Com data</div></div></div>
</div>

<!-- Navegação por mês -->
<div class="month-nav">
    <?php for ($m = 1; $m <= 12; $m++): ?>
    <a href="?mes=<?= $m ?>&vista=<?= $filtroVista ?>" class="month-btn <?= $filtroMes === $m ? 'active' : '' ?> <?= $m === $currentMonth ? 'has-today' : '' ?>"><?= substr($meses[$m], 0, 3) ?> <span style="font-size:.6rem;color:inherit;opacity:.7;">(<?= $anivPorMes[$m] ?>)</span></a>
    <?php endfor; ?>
</div>

<!-- Layout principal -->
<div class="aniv-layout">
    <div>
        <?php
        // Separar em próximos e passados
        $anivProximos = array();
        $anivPassados = array();
        foreach ($anivMes as $a) {
            $diaAniv = (int)$a['dia'];
            $isPast = ($filtroMes < $currentMonth) || ($filtroMes === $currentMonth && $diaAniv < $currentDay);
            if ($filtroMes > $currentMonth) $isPast = false;
            if ($isPast) { $anivPassados[] = $a; } else { $anivProximos[] = $a; }
        }
        ?>

        <!-- Abas: Próximos / Anteriores -->
        <div style="display:flex;gap:0;border-bottom:2px solid var(--border);margin-bottom:.75rem;">
            <button onclick="document.getElementById('anivProximos').style.display='block';document.getElementById('anivPassados').style.display='none';this.style.borderBottomColor='#B87333';this.style.color='#B87333';this.nextElementSibling.style.borderBottomColor='transparent';this.nextElementSibling.style.color='var(--text-muted)'" style="padding:.5rem 1.2rem;font-size:.82rem;font-weight:700;background:none;border:none;border-bottom:3px solid #B87333;color:#B87333;margin-bottom:-2px;cursor:pointer;">
                🎂 Próximos / Hoje (<?= count($anivProximos) ?>)
            </button>
            <button onclick="document.getElementById('anivPassados').style.display='block';document.getElementById('anivProximos').style.display='none';this.style.borderBottomColor='#B87333';this.style.color='#B87333';this.previousElementSibling.style.borderBottomColor='transparent';this.previousElementSibling.style.color='var(--text-muted)'" style="padding:.5rem 1.2rem;font-size:.82rem;font-weight:700;background:none;border:none;border-bottom:3px solid transparent;color:var(--text-muted);margin-bottom:-2px;cursor:pointer;">
                📋 Anteriores (<?= count($anivPassados) ?>)
            </button>
        </div>

        <!-- Lista: Próximos -->
        <div id="anivProximos">
        <?php if (empty($anivProximos)): ?>
            <div class="card" style="text-align:center;padding:2rem;"><div style="font-size:2rem;margin-bottom:.5rem;">🎉</div><h3>Todos os aniversários de <?= $meses[$filtroMes] ?> já passaram!</h3><p style="font-size:.82rem;color:var(--text-muted);">Confira a aba "Anteriores" para ver quem já fez aniversário.</p></div>
        <?php else: ?>
            <?php foreach ($anivProximos as $a):
                $diaAniv = (int)$a['dia'];
                $isToday = ($filtroMes === $currentMonth && $diaAniv === $currentDay);
                $isTomorrow = ($filtroMes === $currentMonth && $diaAniv === $currentDay + 1);
                $isSoon = !$isToday && ($filtroMes === $currentMonth && $diaAniv <= $currentDay + 7);
                $isSent = !empty($a['parabens_enviado']);

                if ($isSent) { $cardClass = 'sent'; $avClass = 'av-sent'; }
                elseif ($isToday) { $cardClass = 'today'; $avClass = 'av-today'; }
                elseif ($isSoon) { $cardClass = 'soon'; $avClass = 'av-soon'; }
                else { $cardClass = 'soon'; $avClass = 'av-soon'; }
            ?>
            <div class="aniv-card <?= $cardClass ?>">
                <div class="aniv-avatar <?= $avClass ?>"><?= mb_substr($a['name'], 0, 2, 'UTF-8') ?></div>
                <div class="aniv-info">
                    <div class="aniv-name"><?= e($a['name']) ?></div>
                    <div class="aniv-detail">
                        <span>📅 <?= str_pad($diaAniv, 2, '0', STR_PAD_LEFT) ?>/<?= str_pad($filtroMes, 2, '0', STR_PAD_LEFT) ?></span>
                        <?php if ($a['idade']): ?><span>· <?= $a['idade'] ?> anos</span><?php endif; ?>
                        <?php if ($a['phone']): ?><span>· 📱 <?= e($a['phone']) ?></span><?php endif; ?>
                        <?php if ($a['email']): ?><span>· ✉️ <?= e($a['email']) ?></span><?php endif; ?>
                    </div>
                </div>
                <div class="aniv-actions">
                    <?php if ($isToday): ?><span class="aniv-tag tag-hoje">HOJE</span>
                    <?php elseif ($isTomorrow): ?><span class="aniv-tag tag-amanha">Amanhã</span>
                    <?php elseif ($isSent): ?><span class="aniv-tag tag-enviado">✓ Enviado</span>
                    <?php elseif ($isSoon): ?><span class="aniv-tag tag-semana"><?= $diaAniv - $currentDay ?>d</span>
                    <?php endif; ?>

                    <?php if (!$isSent): ?>
                        <?php if ($a['phone']): ?>
                        <?php $_anivMsg = $msgMes ? str_replace('{nome}', explode(' ', $a['name'])[0], $msgMes['body']) : 'Feliz aniversário, ' . explode(' ', $a['name'])[0] . '!'; ?>
                        <button type="button" onclick="waSenderOpen({telefone:'<?= preg_replace('/\D/', '', $a['phone']) ?>',nome:<?= e(json_encode($a['name'])) ?>,clientId:<?= (int)$a['id'] ?>,mensagem:<?= e(json_encode($_anivMsg)) ?>})" class="btn btn-sm" style="font-size:.72rem;padding:.25rem .5rem;background:#25D366;color:#fff;border:none;border-radius:6px;cursor:pointer;" title="Enviar parabéns via WhatsApp">WhatsApp</button>
                        <?php endif; ?>
                        <a href="?mes=<?= $filtroMes ?>&vista=<?= $filtroVista ?>&marcar=1&cid=<?= $a['id'] ?>" class="btn btn-outline btn-sm" style="font-size:.65rem;padding:.2rem .4rem;" title="Marcar como enviado">✓</a>
                    <?php else: ?>
                        <a href="?mes=<?= $filtroMes ?>&vista=<?= $filtroVista ?>&desmarcar=1&cid=<?= $a['id'] ?>" class="btn btn-outline btn-sm" style="font-size:.6rem;padding:.15rem .35rem;opacity:.5;" title="Desmarcar">↩</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
        </div>

        <!-- Lista: Anteriores (oculta por padrão) -->
        <div id="anivPassados" style="display:none;">
        <?php if (empty($anivPassados)): ?>
            <div class="card" style="text-align:center;padding:2rem;"><div style="font-size:2rem;margin-bottom:.5rem;">📅</div><h3>Nenhum aniversário anterior em <?= $meses[$filtroMes] ?></h3></div>
        <?php else: ?>
            <?php foreach ($anivPassados as $a):
                $diaAniv = (int)$a['dia'];
                $isSent = !empty($a['parabens_enviado']);
                $cardClass = $isSent ? 'sent' : 'past';
                $avClass = $isSent ? 'av-sent' : 'av-past';
            ?>
            <div class="aniv-card <?= $cardClass ?>">
                <div class="aniv-avatar <?= $avClass ?>"><?= mb_substr($a['name'], 0, 2, 'UTF-8') ?></div>
                <div class="aniv-info">
                    <div class="aniv-name"><?= e($a['name']) ?></div>
                    <div class="aniv-detail">
                        <span>📅 <?= str_pad($diaAniv, 2, '0', STR_PAD_LEFT) ?>/<?= str_pad($filtroMes, 2, '0', STR_PAD_LEFT) ?></span>
                        <?php if ($a['idade']): ?><span>· <?= $a['idade'] ?> anos</span><?php endif; ?>
                        <?php if ($a['phone']): ?><span>· 📱 <?= e($a['phone']) ?></span><?php endif; ?>
                    </div>
                </div>
                <div class="aniv-actions">
                    <?php if ($isSent): ?><span class="aniv-tag tag-enviado">✓ Enviado</span>
                    <?php else: ?><span class="aniv-tag tag-passou">Passou</span>
                    <?php endif; ?>

                    <?php if (!$isSent && $a['phone']): ?>
                        <?php $_anivMsgAtr = $msgMes ? str_replace('{nome}', explode(' ', $a['name'])[0], $msgMes['body']) : 'Feliz aniversário atrasado, ' . explode(' ', $a['name'])[0] . '!'; ?>
                        <button type="button" onclick="waSenderOpen({telefone:'<?= preg_replace('/\D/', '', $a['phone']) ?>',nome:<?= e(json_encode($a['name'])) ?>,clientId:<?= (int)$a['id'] ?>,mensagem:<?= e(json_encode($_anivMsgAtr)) ?>})" class="btn btn-sm" style="font-size:.72rem;padding:.25rem .5rem;background:#25D366;color:#fff;border:none;border-radius:6px;cursor:pointer;">WhatsApp</button>
                        <a href="?mes=<?= $filtroMes ?>&vista=<?= $filtroVista ?>&marcar=1&cid=<?= $a['id'] ?>" class="btn btn-outline btn-sm" style="font-size:.65rem;padding:.2rem .4rem;">✓</a>
                    <?php elseif ($isSent): ?>
                        <a href="?mes=<?= $filtroMes ?>&vista=<?= $filtroVista ?>&desmarcar=1&cid=<?= $a['id'] ?>" class="btn btn-outline btn-sm" style="font-size:.6rem;padding:.15rem .35rem;opacity:.5;" title="Desmarcar">↩</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
        </div>
    </div>

        <!-- Datas Comemorativas do mês -->
        <?php
        $datasComem = array(
            1 => array('01/01' => 'Confraternização Universal', '06/01' => 'Dia de Reis'),
            2 => array('14/02' => 'Valentine\'s Day (internacional)'),
            3 => array('08/03' => 'Dia Internacional da Mulher', '15/03' => 'Dia do Consumidor', '19/03' => 'Dia de São José', '21/03' => 'Início do Outono'),
            4 => array('07/04' => 'Dia do Jornalista', '13/04' => 'Dia do Hino Nacional', '19/04' => 'Dia do Índio', '21/04' => 'Tiradentes', '22/04' => 'Descobrimento do Brasil', '23/04' => 'Dia de São Jorge'),
            5 => array('01/05' => 'Dia do Trabalho', '13/05' => 'Abolição da Escravatura', '25/05' => 'Dia da Indústria'),
            6 => array('05/06' => 'Dia do Meio Ambiente', '12/06' => 'Dia dos Namorados', '21/06' => 'Início do Inverno', '24/06' => 'São João', '29/06' => 'Dia de São Pedro'),
            7 => array('02/07' => 'Independência da Bahia', '09/07' => 'Rev. Constitucionalista (SP)', '20/07' => 'Dia do Amigo', '25/07' => 'Dia do Escritor', '28/07' => 'Dia do Agricultor'),
            8 => array('05/08' => 'Dia Nacional da Saúde', '11/08' => 'Dia do Advogado', '15/08' => 'Dia da Informática', '22/08' => 'Dia do Folclore', '25/08' => 'Dia do Soldado'),
            9 => array('07/09' => 'Independência do Brasil', '21/09' => 'Dia da Árvore / Início da Primavera', '22/09' => 'Dia do Contador'),
            10 => array('01/10' => 'Dia do Idoso', '04/10' => 'Dia de São Francisco / Dia dos Animais', '11/10' => 'Dia da Criança (compras)', '12/10' => 'Dia das Crianças / N.S. Aparecida', '15/10' => 'Dia do Professor', '28/10' => 'Dia do Servidor Público', '31/10' => 'Halloween'),
            11 => array('02/11' => 'Finados', '15/11' => 'Proclamação da República', '19/11' => 'Dia da Bandeira', '20/11' => 'Consciência Negra'),
            12 => array('08/12' => 'N.S. da Conceição', '21/12' => 'Início do Verão', '24/12' => 'Véspera de Natal', '25/12' => 'Natal', '31/12' => 'Véspera de Ano Novo'),
        );
        // Dia das Mães (2º domingo de maio) e Dia dos Pais (2º domingo de agosto) — calcular
        $diadasMaes = date('d/m', strtotime('second sunday of may ' . $currentYear));
        $diadosPais = date('d/m', strtotime('second sunday of august ' . $currentYear));
        $datasComem[5][$diadasMaes] = 'Dia das Maes';
        $datasComem[8][$diadosPais] = 'Dia dos Pais';
        // Carnaval (47 dias antes da Páscoa) e Páscoa (variável)
        $pascoa = date('d/m', easter_date($currentYear));
        $carnaval = date('d/m', easter_date($currentYear) - 47 * 86400);
        $sextaSanta = date('d/m', easter_date($currentYear) - 2 * 86400);
        $corpusChristi = date('d/m', easter_date($currentYear) + 60 * 86400);
        $mesCarnaval = (int)date('n', easter_date($currentYear) - 47 * 86400);
        $mesPascoa = (int)date('n', easter_date($currentYear));
        $mesSexta = (int)date('n', easter_date($currentYear) - 2 * 86400);
        $mesCorpus = (int)date('n', easter_date($currentYear) + 60 * 86400);
        if (!isset($datasComem[$mesCarnaval])) $datasComem[$mesCarnaval] = array();
        $datasComem[$mesCarnaval][$carnaval] = 'Carnaval';
        if (!isset($datasComem[$mesPascoa])) $datasComem[$mesPascoa] = array();
        $datasComem[$mesPascoa][$pascoa] = 'Pascoa';
        if (!isset($datasComem[$mesSexta])) $datasComem[$mesSexta] = array();
        $datasComem[$mesSexta][$sextaSanta] = 'Sexta-feira Santa';
        if (!isset($datasComem[$mesCorpus])) $datasComem[$mesCorpus] = array();
        $datasComem[$mesCorpus][$corpusChristi] = 'Corpus Christi';

        $comemorativasMes = isset($datasComem[$filtroMes]) ? $datasComem[$filtroMes] : array();
        ksort($comemorativasMes);
        ?>
        <?php if (!empty($comemorativasMes)): ?>
        <div style="margin-top:1rem;">
            <div style="font-size:.72rem;font-weight:700;color:var(--petrol-900);text-transform:uppercase;letter-spacing:.5px;margin-bottom:.5rem;padding-bottom:.35rem;border-bottom:1px solid var(--border);">Datas Comemorativas — <?= $meses[$filtroMes] ?></div>
            <?php foreach ($comemorativasMes as $dataC => $nomeC):
                $diaC = (int)substr($dataC, 0, 2);
                $isCToday = ($filtroMes === $currentMonth && $diaC === $currentDay);
                $isCPast = ($filtroMes < $currentMonth) || ($filtroMes === $currentMonth && $diaC < $currentDay);
            ?>
            <div style="display:flex;align-items:center;gap:.6rem;padding:.45rem .6rem;margin-bottom:.3rem;border-radius:var(--radius);background:<?= $isCToday ? 'rgba(5,150,105,.08)' : ($isCPast ? 'transparent' : 'var(--bg-card)') ?>;border:1px solid <?= $isCToday ? '#059669' : 'var(--border)' ?>;<?= $isCPast ? 'opacity:.5;' : '' ?>">
                <span style="font-size:.78rem;font-weight:700;color:<?= $isCToday ? '#059669' : 'var(--petrol-900)' ?>;min-width:35px;"><?= $dataC ?></span>
                <span style="font-size:.8rem;color:var(--petrol-900);flex:1;"><?= e($nomeC) ?></span>
                <?php if ($isCToday): ?><span style="font-size:.6rem;font-weight:700;background:#059669;color:#fff;padding:1px 6px;border-radius:4px;">HOJE</span><?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Sidebar: mensagem + calendário -->
    <div>
        <!-- Mensagem do mês -->
        <div class="msg-box">
            <h4>💬 Mensagem de <?= $meses[$filtroMes] ?></h4>
            <?php if ($msgMes): ?>
                <div class="msg-body" id="msgBody"><?= e($msgMes['body']) ?></div>
                <div class="msg-actions">
                    <button class="btn btn-primary btn-sm" style="font-size:.72rem;" onclick="copyBdayMsg()">📋 Copiar</button>
                    <?php if (has_role('admin')): ?>
                        <button class="btn btn-outline btn-sm" style="font-size:.72rem;" onclick="document.getElementById('editMsg').style.display='block'">✏️ Editar</button>
                    <?php endif; ?>
                </div>

                <?php if (has_role('admin')): ?>
                <div id="editMsg" style="display:none;margin-top:.75rem;">
                    <form method="POST" action="<?= module_url('aniversarios', 'api.php') ?>">
                        <?= csrf_input() ?>
                        <input type="hidden" name="action" value="update_message">
                        <input type="hidden" name="month" value="<?= $filtroMes ?>">
                        <textarea name="body" class="form-textarea" rows="6" style="font-size:.78rem;"><?= e($msgMes['body']) ?></textarea>
                        <div style="margin-top:.5rem;display:flex;gap:.35rem;">
                            <button type="submit" class="btn btn-primary btn-sm" style="font-size:.72rem;">Salvar</button>
                            <button type="button" class="btn btn-outline btn-sm" style="font-size:.72rem;" onclick="document.getElementById('editMsg').style.display='none'">Cancelar</button>
                        </div>
                    </form>
                </div>
                <?php endif; ?>
            <?php else: ?>
                <p style="font-size:.78rem;color:var(--text-muted);">Nenhuma mensagem cadastrada para este mês.</p>
            <?php endif; ?>
        </div>

        <!-- Mini calendário interativo -->
        <div class="cal-box">
            <div class="cal-header">
                <button class="cal-nav" onclick="navCal(-1)" title="Mês anterior">◀</button>
                <h4>📅 <span id="calTitle"><?= $meses[$filtroMes] ?> <?= $currentYear ?></span></h4>
                <button class="cal-nav" onclick="navCal(1)" title="Próximo mês">▶</button>
            </div>
            <?php
            // Preparar dados de aniversariantes para todos os meses (para o JS)
            $allBdays = array();
            try {
                $allRows = $pdo->query(
                    "SELECT c.id, c.name, c.phone, c.email, c.birth_date,
                     DAY(c.birth_date) as dia, MONTH(c.birth_date) as mes,
                     TIMESTAMPDIFF(YEAR, c.birth_date, CURDATE()) as idade
                     FROM clients c WHERE c.birth_date IS NOT NULL ORDER BY DAY(c.birth_date)"
                )->fetchAll();
                foreach ($allRows as $r) {
                    $m = (int)$r['mes'];
                    $d = (int)$r['dia'];
                    if (!isset($allBdays[$m])) $allBdays[$m] = array();
                    if (!isset($allBdays[$m][$d])) $allBdays[$m][$d] = array();
                    $allBdays[$m][$d][] = array(
                        'id' => (int)$r['id'],
                        'name' => $r['name'],
                        'phone' => $r['phone'] ? $r['phone'] : '',
                        'email' => $r['email'] ? $r['email'] : '',
                        'idade' => (int)$r['idade']
                    );
                }
            } catch (Exception $e) {}
            ?>
            <div id="calGrid" class="cal-grid"></div>
            <div id="calPopup" class="cal-popup"></div>
        </div>
    </div>
</div>

<script>
function copyBdayMsg() {
    var text = document.getElementById('msgBody').textContent;
    if (navigator.clipboard) { navigator.clipboard.writeText(text); }
    else {
        var ta = document.createElement('textarea');
        ta.value = text; ta.style.position='fixed'; ta.style.opacity='0';
        document.body.appendChild(ta); ta.select(); document.execCommand('copy');
        document.body.removeChild(ta);
    }
    showToast('Mensagem copiada!');
}

// Calendário interativo
var calData = <?= json_encode($allBdays, JSON_UNESCAPED_UNICODE) ?>;
var calMonth = <?= $filtroMes ?>;
var calYear = <?= $currentYear ?>;
var todayMonth = <?= $currentMonth ?>;
var todayDay = <?= $currentDay ?>;
var meses = ['','Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];
var selectedDay = null;

function renderCal() {
    var grid = document.getElementById('calGrid');
    var popup = document.getElementById('calPopup');
    popup.className = 'cal-popup';
    popup.innerHTML = '';
    selectedDay = null;

    document.getElementById('calTitle').textContent = meses[calMonth] + ' ' + calYear;

    var firstDay = new Date(calYear, calMonth - 1, 1).getDay();
    var daysInMonth = new Date(calYear, calMonth, 0).getDate();
    var monthData = calData[calMonth] || {};

    var html = '<div class="cal-head">D</div><div class="cal-head">S</div><div class="cal-head">T</div><div class="cal-head">Q</div><div class="cal-head">Q</div><div class="cal-head">S</div><div class="cal-head">S</div>';

    for (var i = 0; i < firstDay; i++) { html += '<div class="cal-day empty"></div>'; }

    for (var d = 1; d <= daysInMonth; d++) {
        var cls = 'cal-day';
        var isToday = (calMonth === todayMonth && d === todayDay);
        var hasBday = monthData[d] && monthData[d].length > 0;
        if (isToday) cls += ' today';
        if (hasBday) cls += ' has-bday';
        var onclick = hasBday ? ' onclick="showDayPopup(' + d + ', this)"' : '';
        var count = hasBday ? ' title="' + monthData[d].length + ' aniversariante(s)"' : '';
        html += '<div class="' + cls + '"' + onclick + count + '>' + d + '</div>';
    }
    grid.innerHTML = html;
}

function showDayPopup(day, el) {
    var popup = document.getElementById('calPopup');
    var monthData = calData[calMonth] || {};
    var people = monthData[day] || [];

    // Toggle
    if (selectedDay === day) {
        popup.className = 'cal-popup';
        popup.innerHTML = '';
        selectedDay = null;
        var prev = document.querySelector('.cal-day.selected');
        if (prev) prev.classList.remove('selected');
        return;
    }

    // Remove seleção anterior
    var prev = document.querySelector('.cal-day.selected');
    if (prev) prev.classList.remove('selected');
    el.classList.add('selected');
    selectedDay = day;

    var html = '<h5>' + String(day).padStart(2, '0') + '/' + String(calMonth).padStart(2, '0') + ' — ' + people.length + ' aniversariante(s)</h5>';
    for (var i = 0; i < people.length; i++) {
        var p = people[i];
        html += '<div class="cal-popup-item">';
        html += '<span class="name">' + escHtml(p.name) + '</span>';
        html += '<span class="meta">' + (p.idade ? p.idade + ' anos' : '') + '</span>';
        if (p.phone) {
            var fone = p.phone.replace(/\D/g, '');
            html += '<a href="javascript:void(0)" onclick="waSenderOpen({telefone:\'' + fone + '\',nome:' + JSON.stringify(p.name||'').replace(/"/g, '&quot;') + ',clientId:' + (p.id||0) + '})" style="color:#25D366;font-size:.7rem;font-weight:600;">WhatsApp</a>';
        }
        html += '</div>';
    }
    popup.innerHTML = html;
    popup.className = 'cal-popup active';
}

function navCal(dir) {
    calMonth += dir;
    if (calMonth > 12) { calMonth = 1; calYear++; }
    if (calMonth < 1) { calMonth = 12; calYear--; }
    renderCal();
}

function escHtml(s) {
    var d = document.createElement('div');
    d.appendChild(document.createTextNode(s));
    return d.innerHTML;
}

// Renderizar ao carregar
renderCal();
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
