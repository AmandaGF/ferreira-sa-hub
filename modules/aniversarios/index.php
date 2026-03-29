<?php
/**
 * Ferreira & Sá Hub — Datas Especiais / Aniversários
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();

$pageTitle = 'Datas Especiais';
$pdo = db();

// Filtros
$filtroTipo = isset($_GET['tipo']) ? $_GET['tipo'] : 'todos';
$filtroMes = isset($_GET['mes']) ? (int)$_GET['mes'] : (int)date('n');

$meses = array('', 'Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro');

// Aniversariantes de hoje
$hoje = array();
try {
    $hoje = $pdo->query(
        "SELECT id, name, phone, email, birth_date,
         TIMESTAMPDIFF(YEAR, birth_date, CURDATE()) as idade
         FROM clients
         WHERE birth_date IS NOT NULL
         AND DATE_FORMAT(birth_date, '%m-%d') = DATE_FORMAT(CURDATE(), '%m-%d')
         ORDER BY name"
    )->fetchAll();
} catch (Exception $e) {}

// Aniversariantes do mês selecionado
$doMes = array();
try {
    $doMes = $pdo->query(
        "SELECT id, name, phone, email, birth_date,
         DATE_FORMAT(birth_date, '%d/%m') as data_fmt,
         TIMESTAMPDIFF(YEAR, birth_date, CURDATE()) as idade,
         DATEDIFF(
            DATE_ADD(birth_date, INTERVAL TIMESTAMPDIFF(YEAR, birth_date, CURDATE()) +
            IF(DATE_FORMAT(birth_date, '%m%d') < DATE_FORMAT(CURDATE(), '%m%d'), 1, 0) YEAR),
            CURDATE()
         ) as dias_faltam
         FROM clients
         WHERE birth_date IS NOT NULL
         AND MONTH(birth_date) = $filtroMes
         ORDER BY DAY(birth_date) ASC"
    )->fetchAll();
} catch (Exception $e) {}

// Estatísticas
$totalComAniversario = 0;
try { $totalComAniversario = (int)$pdo->query("SELECT COUNT(*) FROM clients WHERE birth_date IS NOT NULL")->fetchColumn(); } catch (Exception $e) {}

$totalSemAniversario = 0;
try { $totalSemAniversario = (int)$pdo->query("SELECT COUNT(*) FROM clients WHERE birth_date IS NULL")->fetchColumn(); } catch (Exception $e) {}

// Aniversariantes por mês (gráfico)
$anivPorMes = array_fill(1, 12, 0);
try {
    $rows = $pdo->query("SELECT MONTH(birth_date) as mes, COUNT(*) as total FROM clients WHERE birth_date IS NOT NULL GROUP BY MONTH(birth_date)")->fetchAll();
    foreach ($rows as $r) {
        $anivPorMes[(int)$r['mes']] = (int)$r['total'];
    }
} catch (Exception $e) {}

// Colaboradores com aniversário
$colabHoje = array();
try {
    $colabHoje = $pdo->query(
        "SELECT id, name, email, phone
         FROM users
         WHERE is_active = 1
         AND phone IS NOT NULL AND phone != ''
         AND DATE_FORMAT(STR_TO_DATE(phone, '%d/%m'), '%m-%d') = DATE_FORMAT(CURDATE(), '%m-%d')"
    )->fetchAll();
} catch (Exception $e) {}
// Nota: users não tem birth_date por padrão, mas podemos adicionar depois

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.aniv-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: .75rem; margin-bottom: 1.25rem; }
.aniv-stat {
    background: var(--bg-card); border-radius: var(--radius-lg);
    border: 1px solid var(--border); padding: 1rem 1.25rem;
    text-align: center;
}
.aniv-stat .num { font-size: 1.8rem; font-weight: 800; color: var(--petrol-900); }
.aniv-stat .lbl { font-size: .72rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: .5px; }

.today-section {
    background: linear-gradient(135deg, #052228 0%, #0d3640 100%);
    border-radius: var(--radius-lg);
    padding: 1.5rem;
    margin-bottom: 1.25rem;
    border: 1px solid rgba(215,171,144,.2);
}
.today-section h3 { color: #fff; font-size: 1rem; margin-bottom: 1rem; display: flex; align-items: center; gap: .5rem; }

.today-card {
    background: rgba(255,255,255,.08);
    border-radius: var(--radius);
    padding: 1rem;
    display: flex;
    align-items: center;
    gap: 1rem;
    margin-bottom: .5rem;
}
.today-avatar {
    width: 50px; height: 50px; border-radius: 50%;
    background: linear-gradient(135deg, var(--rose), var(--rose-dark));
    color: #fff; display: flex; align-items: center; justify-content: center;
    font-size: 1rem; font-weight: 700; flex-shrink: 0;
}
.today-info { flex: 1; }
.today-name { font-size: .95rem; font-weight: 700; color: #fff; }
.today-detail { font-size: .78rem; color: rgba(255,255,255,.6); }
.today-actions { display: flex; gap: .5rem; }
.btn-whatsapp {
    background: #25D366; color: #fff; border: none; padding: .4rem .8rem;
    border-radius: var(--radius); font-size: .75rem; font-weight: 600; cursor: pointer;
}
.btn-whatsapp:hover { background: #1da855; }

.month-filter { display: flex; gap: .35rem; flex-wrap: wrap; margin-bottom: 1rem; }
.month-btn {
    padding: .35rem .7rem; font-size: .72rem; font-weight: 600;
    border: 1.5px solid var(--border); border-radius: 100px;
    background: var(--bg-card); color: var(--text-muted); cursor: pointer;
}
.month-btn:hover { border-color: var(--petrol-300); }
.month-btn.active { background: var(--petrol-900); color: #fff; border-color: var(--petrol-900); }

.aniv-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: .75rem; }
.aniv-card {
    background: var(--bg-card); border-radius: var(--radius-lg);
    border: 1px solid var(--border); padding: 1rem;
    display: flex; align-items: center; gap: .75rem;
}
.aniv-card-avatar {
    width: 42px; height: 42px; border-radius: 50%;
    background: var(--petrol-100); color: var(--petrol-500);
    display: flex; align-items: center; justify-content: center;
    font-size: .85rem; font-weight: 700; flex-shrink: 0;
}
.aniv-card-info { flex: 1; min-width: 0; }
.aniv-card-name { font-size: .85rem; font-weight: 700; color: var(--petrol-900); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.aniv-card-meta { font-size: .72rem; color: var(--text-muted); }
.aniv-card-tag {
    font-size: .6rem; font-weight: 700; padding: .15rem .4rem;
    border-radius: 4px; color: #fff; flex-shrink: 0;
}
.tag-today { background: #059669; }
.tag-past { background: #94a3b8; }
.tag-soon { background: #6366f1; }

.chart-section {
    background: var(--bg-card); border-radius: var(--radius-lg);
    border: 1px solid var(--border); padding: 1.25rem;
    margin-bottom: 1.25rem;
}
.chart-section h4 { font-size: .88rem; font-weight: 700; color: var(--petrol-900); margin-bottom: 1rem; }
</style>

<!-- Estatísticas -->
<div class="aniv-stats">
    <div class="aniv-stat">
        <div class="num"><?= count($hoje) ?></div>
        <div class="lbl">Aniversariantes hoje</div>
    </div>
    <div class="aniv-stat">
        <div class="num"><?= count($doMes) ?></div>
        <div class="lbl"><?= $meses[$filtroMes] ?></div>
    </div>
    <div class="aniv-stat">
        <div class="num"><?= $totalComAniversario ?></div>
        <div class="lbl">Com data cadastrada</div>
    </div>
    <div class="aniv-stat">
        <div class="num"><?= $totalSemAniversario ?></div>
        <div class="lbl">Sem data</div>
    </div>
</div>

<!-- Aniversariantes de hoje -->
<?php if (!empty($hoje)): ?>
<div class="today-section">
    <h3>🎉 Aniversariantes de Hoje!</h3>
    <?php foreach ($hoje as $h): ?>
    <div class="today-card">
        <div class="today-avatar"><?= mb_substr($h['name'], 0, 2, 'UTF-8') ?></div>
        <div class="today-info">
            <div class="today-name"><?= e($h['name']) ?></div>
            <div class="today-detail">
                <?= $h['idade'] ? 'Completa ' . $h['idade'] . ' anos' : '' ?>
                <?php if ($h['phone']): ?> · <?= e($h['phone']) ?><?php endif; ?>
            </div>
        </div>
        <div class="today-actions">
            <?php if ($h['phone']): ?>
            <?php
                $phone = preg_replace('/\D/', '', $h['phone']);
                if (strlen($phone) <= 11) $phone = '55' . $phone;
                $msg = urlencode("Olá, " . explode(' ', $h['name'])[0] . "! 🎂\n\nA equipe Ferreira & Sá Advocacia deseja a você um feliz aniversário! Muita saúde, felicidade e conquistas.\n\nUm abraço!");
            ?>
            <a href="https://wa.me/<?= $phone ?>?text=<?= $msg ?>" target="_blank" class="btn-whatsapp">📱 WhatsApp</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Gráfico por mês -->
<div class="chart-section">
    <h4>📊 Aniversariantes por Mês</h4>
    <canvas id="chartAnivMes" style="max-height:180px;"></canvas>
</div>

<!-- Filtro por mês -->
<div class="card" style="margin-bottom:1.25rem;">
    <div class="card-header"><h3>Aniversariantes do Mês</h3></div>
    <div class="card-body">
        <div class="month-filter">
            <?php for ($m = 1; $m <= 12; $m++): ?>
            <a href="?mes=<?= $m ?>" class="month-btn <?= $filtroMes === $m ? 'active' : '' ?>"><?= substr($meses[$m], 0, 3) ?></a>
            <?php endfor; ?>
        </div>

        <?php if (empty($doMes)): ?>
            <p style="color:var(--text-muted);font-size:.85rem;text-align:center;padding:2rem;">Nenhum aniversariante em <?= $meses[$filtroMes] ?>.</p>
        <?php else: ?>
            <div class="aniv-grid">
                <?php foreach ($doMes as $a): ?>
                <?php
                    $isToday = date('m-d') === date('m-d', strtotime($a['birth_date']));
                    $isPast = !$isToday && (int)date('d') > (int)date('d', strtotime($a['birth_date']));
                ?>
                <div class="aniv-card">
                    <div class="aniv-card-avatar" <?= $isToday ? 'style="background:linear-gradient(135deg,var(--rose),var(--rose-dark));color:#fff;"' : '' ?>>
                        <?= mb_substr($a['name'], 0, 2, 'UTF-8') ?>
                    </div>
                    <div class="aniv-card-info">
                        <div class="aniv-card-name"><?= e($a['name']) ?></div>
                        <div class="aniv-card-meta">
                            <?= e($a['data_fmt']) ?>
                            <?= $a['idade'] ? ' · ' . $a['idade'] . ' anos' : '' ?>
                        </div>
                    </div>
                    <?php if ($isToday): ?>
                        <span class="aniv-card-tag tag-today">HOJE</span>
                    <?php elseif ($isPast): ?>
                        <span class="aniv-card-tag tag-past">Passou</span>
                    <?php else: ?>
                        <span class="aniv-card-tag tag-soon"><?= $a['dias_faltam'] ?>d</span>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function() {
    var mesesAbrev = ['Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'];
    var dados = <?= json_encode(array_values($anivPorMes)) ?>;
    var mesAtual = <?= (int)date('n') - 1 ?>;

    var cores = dados.map(function(v, i) {
        return i === mesAtual ? '#d7ab90' : 'rgba(99,102,241,.6)';
    });

    var ctx = document.getElementById('chartAnivMes');
    if (ctx) {
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: mesesAbrev,
                datasets: [{
                    label: 'Aniversariantes',
                    data: dados,
                    backgroundColor: cores,
                    borderRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, ticks: { color: '#94a3b8', stepSize: 1 }, grid: { color: 'rgba(148,163,184,.1)' } },
                    x: { ticks: { color: '#94a3b8' }, grid: { display: false } }
                }
            }
        });
    }
})();
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
