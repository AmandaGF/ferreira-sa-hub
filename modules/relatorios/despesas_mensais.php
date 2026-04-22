<?php
/**
 * Relatório: Despesas Mensais
 * Lista submissions do formulário público de despesas + detalhe visual de cada uma.
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
if (!has_min_role('estagiario')) { redirect(url('modules/dashboard/')); }

$pdo = db();
$pageTitle = 'Despesas Mensais';

// Grupos de despesa (prefixo no payload_json → label + ícone + cor)
$GRUPOS = array(
    'moradia' => array('label' => 'Moradia',      'icon' => '🏠', 'cor' => '#0ea5e9', 'rateado' => true),
    'alim'    => array('label' => 'Alimentação',  'icon' => '🍽️', 'cor' => '#f59e0b'),
    'saude'   => array('label' => 'Saúde',        'icon' => '❤️', 'cor' => '#ef4444'),
    'educ'    => array('label' => 'Educação',     'icon' => '📚', 'cor' => '#8b5cf6'),
    'transp'  => array('label' => 'Transporte',   'icon' => '🚗', 'cor' => '#10b981'),
    'vest'    => array('label' => 'Vestuário',    'icon' => '👕', 'cor' => '#ec4899'),
    'lazer'   => array('label' => 'Lazer',        'icon' => '🎮', 'cor' => '#06b6d4'),
    'tech'    => array('label' => 'Tecnologia',   'icon' => '💻', 'cor' => '#6366f1'),
    'cuid'    => array('label' => 'Cuidados',     'icon' => '🧸', 'cor' => '#d97706'),
    'outros'  => array('label' => 'Outros',       'icon' => '📦', 'cor' => '#6b7280'),
);

// Extrai valor numérico do payload (strings tipo "R$ 1.234,56" → 1234.56)
function dm_parse_valor($v) {
    if ($v === null || $v === '') return 0.0;
    $s = preg_replace('/[^\d,.-]/', '', (string)$v);
    $s = str_replace('.', '', $s);
    $s = str_replace(',', '.', $s);
    return (float)$s;
}

// Soma todos os campos de um grupo (prefixo) no payload
function dm_soma_grupo($payload, $prefixo) {
    $total = 0.0;
    foreach ($payload as $k => $v) {
        if (strpos($k, $prefixo . '_') === 0) {
            $total += dm_parse_valor($v);
        }
    }
    return $total;
}

// ═══ DETALHE ═══
$detalheId = (int)($_GET['id'] ?? 0);
if ($detalheId > 0) {
    $st = $pdo->prepare("SELECT * FROM form_submissions WHERE id = ? AND form_type = 'despesas_mensais'");
    $st->execute(array($detalheId));
    $sub = $st->fetch();
    if (!$sub) { flash_set('error', 'Submission não encontrada.'); redirect(module_url('relatorios', 'despesas_mensais.php')); }
    $payload = json_decode($sub['payload_json'], true) ?: array();

    // KPIs por grupo
    $totaisGrupo = array();
    foreach ($GRUPOS as $pfx => $meta) {
        $tot = dm_soma_grupo($payload, $pfx);
        // Moradia é rateada entre moradores
        if (!empty($meta['rateado'])) {
            $moradores = max(1, (int)($payload['moradores'] ?? 1));
            $tot = $tot / $moradores;
        }
        $totaisGrupo[$pfx] = $tot;
    }
    $totalGeral = array_sum($totaisGrupo);

    require_once APP_ROOT . '/templates/layout_start.php';
    ?>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;flex-wrap:wrap;gap:.5rem;">
        <div>
            <a href="?" class="btn btn-outline btn-sm">← Voltar</a>
            <h2 style="display:inline-block;margin:0 0 0 1rem;font-size:1.1rem;color:var(--petrol-900);">💰 <?= e($sub['client_name']) ?></h2>
        </div>
        <div style="font-size:.75rem;color:var(--text-muted);">
            Protocolo <code><?= e($sub['protocol']) ?></code> · <?= date('d/m/Y H:i', strtotime($sub['created_at'])) ?>
            <?php if ($sub['updated_at'] && $sub['updated_at'] !== $sub['created_at']): ?>
                · atualizado <?= date('d/m/Y H:i', strtotime($sub['updated_at'])) ?>
            <?php endif; ?>
        </div>
    </div>

    <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:1rem;margin-bottom:1rem;">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:.75rem;font-size:.82rem;">
            <div><div style="font-size:.65rem;color:var(--text-muted);text-transform:uppercase;">Responsável</div><div style="font-weight:700;"><?= e($sub['client_name']) ?></div></div>
            <div><div style="font-size:.65rem;color:var(--text-muted);text-transform:uppercase;">WhatsApp</div><div><?= e($sub['client_phone']) ?></div></div>
            <?php if (!empty($payload['cpf'])): ?><div><div style="font-size:.65rem;color:var(--text-muted);text-transform:uppercase;">CPF</div><div><?= e($payload['cpf']) ?></div></div><?php endif; ?>
            <?php if (!empty($payload['nome_filho_referente']) || !empty($payload['sem_filhos'])): ?>
            <div><div style="font-size:.65rem;color:var(--text-muted);text-transform:uppercase;">Filho referente</div><div><?= !empty($payload['sem_filhos']) && $payload['sem_filhos']==='sim' ? '<span style="color:var(--text-muted);">— não tem filhos —</span>' : e($payload['nome_filho_referente']) ?></div></div>
            <?php endif; ?>
            <?php if (!empty($payload['fonte_renda'])): ?><div><div style="font-size:.65rem;color:var(--text-muted);text-transform:uppercase;">Fonte de renda</div><div><?= e($payload['fonte_renda']) ?></div></div><?php endif; ?>
            <?php if (!empty($payload['renda_mensal'])): ?><div><div style="font-size:.65rem;color:var(--text-muted);text-transform:uppercase;">Renda mensal</div><div style="font-weight:700;color:#059669;"><?= e($payload['renda_mensal']) ?></div></div><?php endif; ?>
            <?php if (!empty($payload['moradores'])): ?><div><div style="font-size:.65rem;color:var(--text-muted);text-transform:uppercase;">Moradores na casa</div><div><?= (int)$payload['moradores'] ?></div></div><?php endif; ?>
            <?php if (!empty($payload['qtd_filhos'])): ?><div><div style="font-size:.65rem;color:var(--text-muted);text-transform:uppercase;">Qtd. filhos</div><div><?= (int)$payload['qtd_filhos'] ?></div></div><?php endif; ?>
            <?php if (!empty($payload['recebe_pensao'])): ?><div><div style="font-size:.65rem;color:var(--text-muted);text-transform:uppercase;">Recebe pensão?</div><div><?= e($payload['recebe_pensao']) ?></div></div><?php endif; ?>
        </div>
    </div>

    <!-- Total geral -->
    <div style="background:linear-gradient(135deg,#052228,#164e52);border-radius:var(--radius-lg);padding:1.25rem;margin-bottom:1rem;color:#fff;">
        <div style="font-size:.68rem;text-transform:uppercase;letter-spacing:.5px;opacity:.75;">Total geral de despesas do(a) filho(a)</div>
        <div style="font-size:2rem;font-weight:800;line-height:1.1;margin-top:.2rem;">R$ <?= number_format($totalGeral, 2, ',', '.') ?></div>
        <?php if (!empty($payload['renda_mensal'])):
            $renda = dm_parse_valor($payload['renda_mensal']);
            if ($renda > 0):
                $perc = round($totalGeral / $renda * 100, 1);
        ?>
            <div style="font-size:.78rem;opacity:.85;margin-top:.3rem;"><?= $perc ?>% da renda mensal declarada</div>
        <?php endif; endif; ?>
    </div>

    <!-- KPIs por grupo -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:.6rem;margin-bottom:1rem;">
        <?php foreach ($GRUPOS as $pfx => $meta): $v = $totaisGrupo[$pfx]; ?>
        <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:12px;padding:.75rem;border-left:4px solid <?= $meta['cor'] ?>;">
            <div style="font-size:1.3rem;"><?= $meta['icon'] ?></div>
            <div style="font-size:.62rem;color:var(--text-muted);text-transform:uppercase;margin-top:.2rem;"><?= $meta['label'] ?><?= !empty($meta['rateado']) ? ' (rateada)' : '' ?></div>
            <div style="font-size:1rem;font-weight:800;color:var(--petrol-900);">R$ <?= number_format($v, 2, ',', '.') ?></div>
            <?php if ($totalGeral > 0 && $v > 0): ?>
                <div style="font-size:.62rem;color:var(--text-muted);"><?= round($v/$totalGeral*100, 1) ?>% do total</div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Gráfico -->
    <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:1rem;margin-bottom:1rem;">
        <h4 style="margin:0 0 .75rem;font-size:.9rem;color:var(--petrol-900);">📊 Distribuição por categoria</h4>
        <canvas id="chartDespesas" style="max-height:280px;"></canvas>
    </div>

    <!-- Itens detalhados por grupo -->
    <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:1rem;">
        <h4 style="margin:0 0 .75rem;font-size:.9rem;color:var(--petrol-900);">📋 Detalhamento completo</h4>
        <?php foreach ($GRUPOS as $pfx => $meta):
            $itens = array();
            foreach ($payload as $k => $v) {
                if (strpos($k, $pfx . '_') === 0 && dm_parse_valor($v) > 0) {
                    $label = str_replace($pfx . '_', '', $k);
                    $label = ucfirst(str_replace('_', ' ', $label));
                    $itens[$label] = dm_parse_valor($v);
                }
            }
            if (empty($itens)) continue;
        ?>
        <div style="margin-bottom:1rem;">
            <div style="font-weight:700;color:<?= $meta['cor'] ?>;font-size:.85rem;margin-bottom:.4rem;"><?= $meta['icon'] ?> <?= $meta['label'] ?></div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:.3rem;font-size:.78rem;">
                <?php foreach ($itens as $lbl => $val): ?>
                <div style="display:flex;justify-content:space-between;padding:.25rem .5rem;background:#f9fafb;border-radius:6px;">
                    <span><?= e($lbl) ?></span>
                    <span style="font-weight:700;">R$ <?= number_format($val, 2, ',', '.') ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <?php if (!empty($payload['obs_identificacao'])): ?>
        <div style="background:#fffbeb;border:1px solid #fbbf24;border-radius:8px;padding:.6rem .8rem;font-size:.8rem;margin-top:.6rem;">
            <b>📝 Observações:</b> <?= nl2br(e($payload['obs_identificacao'])) ?>
        </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script>
    (function(){
        var dados = <?= json_encode(array_values(array_map(function($pfx,$meta) use($totaisGrupo){
            return array('label' => $meta['label'], 'valor' => round($totaisGrupo[$pfx], 2), 'cor' => $meta['cor']);
        }, array_keys($GRUPOS), $GRUPOS))) ?>;
        var filtered = dados.filter(function(d){ return d.valor > 0; });
        if (!filtered.length) { document.getElementById('chartDespesas').style.display='none'; return; }
        new Chart(document.getElementById('chartDespesas'), {
            type: 'doughnut',
            data: {
                labels: filtered.map(function(d){ return d.label; }),
                datasets: [{
                    data: filtered.map(function(d){ return d.valor; }),
                    backgroundColor: filtered.map(function(d){ return d.cor; }),
                    borderWidth: 2, borderColor: '#fff'
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'right', labels: { font:{size:11} } },
                    tooltip: { callbacks: { label: function(ctx){
                        return ctx.label + ': R$ ' + ctx.raw.toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2});
                    }}}
                }
            }
        });
    })();
    </script>
    <?php
    require_once APP_ROOT . '/templates/layout_end.php';
    exit;
}

// ═══ LISTA ═══
$filtroStatus = $_GET['status'] ?? '';
$filtroMes = $_GET['mes'] ?? '';
$busca = trim($_GET['q'] ?? '');

$where = array("form_type = 'despesas_mensais'");
$params = array();
if ($filtroStatus) { $where[] = "status = ?"; $params[] = $filtroStatus; }
if ($filtroMes && preg_match('/^\d{4}-\d{2}$/', $filtroMes)) {
    $where[] = "DATE_FORMAT(created_at, '%Y-%m') = ?"; $params[] = $filtroMes;
}
if ($busca !== '') {
    $where[] = "(client_name LIKE ? OR client_phone LIKE ? OR protocol LIKE ?)";
    $params[] = "%$busca%"; $params[] = "%$busca%"; $params[] = "%$busca%";
}
$wh = implode(' AND ', $where);

$st = $pdo->prepare("SELECT id, protocol, client_name, client_phone, status, payload_json, created_at, updated_at FROM form_submissions WHERE $wh ORDER BY created_at DESC LIMIT 200");
$st->execute($params);
$rows = $st->fetchAll();

$meses = $pdo->query("SELECT DISTINCT DATE_FORMAT(created_at, '%Y-%m') AS m FROM form_submissions WHERE form_type = 'despesas_mensais' ORDER BY m DESC")->fetchAll(PDO::FETCH_COLUMN);
$totalGeral = (int)$pdo->query("SELECT COUNT(*) FROM form_submissions WHERE form_type = 'despesas_mensais'")->fetchColumn();

require_once APP_ROOT . '/templates/layout_start.php';
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;flex-wrap:wrap;gap:.5rem;">
    <h2 style="font-size:1.1rem;margin:0;color:var(--petrol-900);">💰 Despesas Mensais — Relatório</h2>
    <div style="font-size:.75rem;color:var(--text-muted);"><?= $totalGeral ?> submission(s) no total</div>
</div>

<form method="GET" style="display:flex;gap:.5rem;margin-bottom:1rem;flex-wrap:wrap;align-items:center;padding:.65rem;background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);">
    <input type="text" name="q" placeholder="🔍 Nome, WhatsApp ou protocolo..." value="<?= e($busca) ?>" style="flex:1;min-width:220px;padding:6px 10px;border:1.5px solid var(--border);border-radius:8px;font-size:.82rem;">
    <select name="mes" style="padding:6px 10px;border:1.5px solid var(--border);border-radius:8px;font-size:.82rem;">
        <option value="">Todos os meses</option>
        <?php foreach ($meses as $m):
            $yy = substr($m,0,4); $mm = substr($m,5,2);
            $nm = array('01'=>'Jan','02'=>'Fev','03'=>'Mar','04'=>'Abr','05'=>'Mai','06'=>'Jun','07'=>'Jul','08'=>'Ago','09'=>'Set','10'=>'Out','11'=>'Nov','12'=>'Dez')[$mm] ?? $mm;
        ?>
            <option value="<?= e($m) ?>" <?= $m === $filtroMes ? 'selected' : '' ?>><?= $nm ?>/<?= $yy ?></option>
        <?php endforeach; ?>
    </select>
    <select name="status" style="padding:6px 10px;border:1.5px solid var(--border);border-radius:8px;font-size:.82rem;">
        <option value="">Todos status</option>
        <option value="novo" <?= $filtroStatus === 'novo' ? 'selected' : '' ?>>Novo</option>
        <option value="analisado" <?= $filtroStatus === 'analisado' ? 'selected' : '' ?>>Analisado</option>
    </select>
    <button type="submit" class="btn btn-primary btn-sm" style="font-size:.78rem;">Filtrar</button>
    <?php if ($busca || $filtroStatus || $filtroMes): ?>
        <a href="?" class="btn btn-outline btn-sm" style="font-size:.78rem;">× Limpar</a>
    <?php endif; ?>
</form>

<?php if (empty($rows)): ?>
    <div style="text-align:center;padding:3rem;color:var(--text-muted);background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);">
        Nenhuma submission encontrada.
    </div>
<?php else: ?>
<div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);overflow:hidden;">
    <table style="width:100%;border-collapse:collapse;font-size:.82rem;">
        <thead>
            <tr style="background:linear-gradient(180deg,var(--petrol-900),var(--petrol-700));color:#fff;">
                <th style="padding:8px 10px;text-align:left;font-size:.7rem;text-transform:uppercase;">Cliente</th>
                <th style="padding:8px 10px;text-align:left;font-size:.7rem;text-transform:uppercase;">WhatsApp</th>
                <th style="padding:8px 10px;text-align:left;font-size:.7rem;text-transform:uppercase;">Filho referente</th>
                <th style="padding:8px 10px;text-align:right;font-size:.7rem;text-transform:uppercase;">Total</th>
                <th style="padding:8px 10px;text-align:left;font-size:.7rem;text-transform:uppercase;">Protocolo</th>
                <th style="padding:8px 10px;text-align:left;font-size:.7rem;text-transform:uppercase;">Enviado em</th>
                <th style="padding:8px 10px;text-align:center;font-size:.7rem;text-transform:uppercase;">Ver</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $r):
                $pl = json_decode($r['payload_json'], true) ?: array();
                $total = 0.0;
                foreach ($GRUPOS as $pfx => $meta) {
                    $t = dm_soma_grupo($pl, $pfx);
                    if (!empty($meta['rateado'])) {
                        $mor = max(1, (int)($pl['moradores'] ?? 1));
                        $t = $t / $mor;
                    }
                    $total += $t;
                }
                $filho = !empty($pl['sem_filhos']) && $pl['sem_filhos'] === 'sim' ? '—' : ($pl['nome_filho_referente'] ?? '—');
            ?>
            <tr style="border-bottom:1px solid #f0f0f0;">
                <td style="padding:8px 10px;font-weight:700;color:var(--petrol-900);"><?= e($r['client_name']) ?></td>
                <td style="padding:8px 10px;font-family:monospace;font-size:.78rem;"><?= e($r['client_phone']) ?></td>
                <td style="padding:8px 10px;color:var(--text-muted);"><?= e($filho) ?></td>
                <td style="padding:8px 10px;text-align:right;font-weight:700;color:#059669;">R$ <?= number_format($total, 2, ',', '.') ?></td>
                <td style="padding:8px 10px;font-family:monospace;font-size:.7rem;color:var(--text-muted);"><?= e($r['protocol']) ?></td>
                <td style="padding:8px 10px;font-size:.75rem;color:var(--text-muted);"><?= date('d/m/Y H:i', strtotime($r['created_at'])) ?></td>
                <td style="padding:8px 10px;text-align:center;"><a href="?id=<?= (int)$r['id'] ?>" class="btn btn-primary btn-sm" style="font-size:.72rem;">Ver →</a></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
