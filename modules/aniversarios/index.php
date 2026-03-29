<?php
/**
 * Ferreira & Sá Hub — Datas Especiais / Aniversários (v2)
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();

$pageTitle = 'Datas Especiais';
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
.cal-grid { display:grid; grid-template-columns:repeat(7,1fr); gap:2px; text-align:center; font-size:.7rem; }
.cal-head { font-weight:700; color:var(--text-muted); padding:.3rem; }
.cal-day { padding:.3rem; border-radius:4px; cursor:default; }
.cal-day.empty { }
.cal-day.has-bday { background:rgba(99,102,241,.15); color:#6366f1; font-weight:700; }
.cal-day.today { background:var(--rose); color:#fff; font-weight:700; border-radius:50%; }
.cal-day.today.has-bday { background:#059669; }
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
        <!-- Lista de aniversariantes -->
        <?php if (empty($anivMes)): ?>
            <div class="card" style="text-align:center;padding:2rem;"><div style="font-size:2rem;margin-bottom:.5rem;">🎂</div><h3>Nenhum aniversariante em <?= $meses[$filtroMes] ?></h3></div>
        <?php else: ?>
            <?php foreach ($anivMes as $a):
                $diaAniv = (int)$a['dia'];
                $isToday = ($filtroMes === $currentMonth && $diaAniv === $currentDay);
                $isPast = ($filtroMes < $currentMonth) || ($filtroMes === $currentMonth && $diaAniv < $currentDay);
                $isTomorrow = ($filtroMes === $currentMonth && $diaAniv === $currentDay + 1);
                $isSoon = !$isToday && !$isPast && ($filtroMes === $currentMonth && $diaAniv <= $currentDay + 7);
                $isSent = !empty($a['parabens_enviado']);

                if ($isSent) { $cardClass = 'sent'; $avClass = 'av-sent'; }
                elseif ($isToday) { $cardClass = 'today'; $avClass = 'av-today'; }
                elseif ($isPast) { $cardClass = 'past'; $avClass = 'av-past'; }
                elseif ($isSoon) { $cardClass = 'soon'; $avClass = 'av-soon'; }
                else { $cardClass = 'soon'; $avClass = 'av-soon'; }

                // Para meses futuros, nenhum "passou"
                if ($filtroMes > $currentMonth) { $isPast = false; $cardClass = 'soon'; $avClass = 'av-soon'; }
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
                    <?php elseif ($isPast): ?><span class="aniv-tag tag-passou">Passou</span>
                    <?php elseif ($isSoon): ?><span class="aniv-tag tag-semana"><?= $diaAniv - $currentDay ?>d</span>
                    <?php endif; ?>

                    <?php if (!$isSent): ?>
                        <?php if ($a['phone']): ?>
                        <a href="https://wa.me/55<?= preg_replace('/\D/', '', $a['phone']) ?>?text=<?= urlencode($msgMes ? str_replace('{nome}', explode(' ', $a['name'])[0], $msgMes['body']) : 'Feliz aniversário, ' . explode(' ', $a['name'])[0] . '!') ?>" target="_blank" class="btn btn-sm" style="font-size:.65rem;padding:.2rem .4rem;background:#25D366;color:#fff;border:none;">📱</a>
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

        <!-- Mini calendário -->
        <div class="cal-box">
            <h4>📅 <?= $meses[$filtroMes] ?> <?= $currentYear ?></h4>
            <?php
            $firstDay = mktime(0, 0, 0, $filtroMes, 1, $currentYear);
            $daysInMonth = (int)date('t', $firstDay);
            $startWeekday = (int)date('w', $firstDay); // 0=dom
            $bdayDays = array();
            foreach ($anivMes as $a) { $bdayDays[(int)$a['dia']] = true; }
            ?>
            <div class="cal-grid">
                <div class="cal-head">D</div><div class="cal-head">S</div><div class="cal-head">T</div><div class="cal-head">Q</div><div class="cal-head">Q</div><div class="cal-head">S</div><div class="cal-head">S</div>
                <?php for ($i = 0; $i < $startWeekday; $i++): ?><div class="cal-day empty"></div><?php endfor; ?>
                <?php for ($d = 1; $d <= $daysInMonth; $d++):
                    $classes = 'cal-day';
                    $isT = ($filtroMes === $currentMonth && $d === $currentDay);
                    $hasBday = isset($bdayDays[$d]);
                    if ($isT) $classes .= ' today';
                    if ($hasBday) $classes .= ' has-bday';
                ?>
                <div class="<?= $classes ?>"><?= $d ?></div>
                <?php endfor; ?>
            </div>
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
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
