<?php
/**
 * Painel Admin — Progresso de Treinamento da equipe.
 * Visível apenas para Admin e Gestão.
 */
// Força o browser/PWA a SEMPRE pegar versão fresca (sem cache)
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require_once __DIR__ . '/../../core/middleware.php';
require_login();
if (!has_min_role('gestao')) { flash_set('error','Acesso restrito.'); redirect(module_url('treinamento')); }

$pdo = db();
$pageTitle = 'Treinamento — Progresso da Equipe';
$csrf = generate_csrf_token();

$filtroPerfil = $_GET['role'] ?? 'todos';

$where = "WHERE u.is_active = 1";
$params = array();
if ($filtroPerfil !== 'todos') {
    $where .= " AND u.role = ?";
    $params[] = $filtroPerfil;
}

$total = (int)$pdo->query("SELECT COUNT(*) FROM treinamento_modulos WHERE ativo = 1")->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT u.id, u.name, u.role, u.setor,
            COUNT(CASE WHEN p.concluido = 1 THEN 1 END) AS concluidos,
            MAX(p.updated_at) AS ultimo_acesso,
            COALESCE(SUM(p.pontos_ganhos), 0) AS pontos
     FROM users u
     LEFT JOIN treinamento_progresso p ON p.user_id = u.id
     {$where}
     GROUP BY u.id
     ORDER BY concluidos DESC, pontos DESC"
);
$stmt->execute($params);
$equipe = $stmt->fetchAll();

// Export CSV
if (isset($_GET['csv'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="treinamento_equipe_' . date('Ymd') . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, array('Usuário','Perfil','Setor','Módulos concluídos','% progresso','Pontos','Último acesso'), ';');
    foreach ($equipe as $r) {
        $pct = $total > 0 ? round((int)$r['concluidos'] / $total * 100) : 0;
        fputcsv($out, array(
            $r['name'], $r['role'], $r['setor'] ?: '',
            (int)$r['concluidos'] . '/' . $total,
            $pct . '%',
            (int)$r['pontos'],
            $r['ultimo_acesso'] ?: '—',
        ), ';');
    }
    fclose($out); exit;
}

require_once APP_ROOT . '/templates/layout_start.php';
?>

<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600;700&family=Outfit:wght@400;600;700&display=swap" rel="stylesheet">

<style>
.ta-wrap { max-width:1200px; margin:0 auto; }
.ta-hdr { display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; flex-wrap:wrap; gap:1rem; }
.ta-hdr h1 { font-family:'Cormorant Garamond',serif; font-size:1.8rem; margin:0; color:#052228; }
.ta-filters { display:flex; gap:4px; flex-wrap:wrap; }
.ta-filter { padding:5px 12px; border-radius:999px; background:#fff; border:1.5px solid #e5e7eb; font-size:.75rem; text-decoration:none; color:#052228; font-weight:600; }
.ta-filter.active { background:#052228; color:#fff; border-color:#052228; }
.ta-tbl { width:100%; border-collapse:collapse; background:#fff; border:1px solid #e5e7eb; border-radius:12px; overflow:hidden; font-size:.85rem; }
.ta-tbl thead { background:linear-gradient(180deg,#052228,#0a3842); color:#fff; }
.ta-tbl th { padding:10px 14px; font-size:.7rem; text-transform:uppercase; letter-spacing:.3px; text-align:left; font-weight:700; }
.ta-tbl td { padding:10px 14px; border-bottom:1px solid #f3f4f6; vertical-align:middle; }
.ta-tbl tr:hover { background:#fafbfc; }
.ta-prog-bar { width:100%; height:10px; background:#e5e7eb; border-radius:5px; overflow:hidden; }
.ta-prog-fill { height:100%; background:linear-gradient(90deg,#B87333,#D7AB90); border-radius:5px; }
.ta-reset { background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; border-radius:6px; padding:4px 10px; font-size:.7rem; cursor:pointer; font-weight:700; }
.ta-reset:hover { background:#fecaca; }
</style>

<div class="ta-wrap">

<div class="ta-hdr">
    <div>
        <h1>📊 Progresso da Equipe — Treinamento</h1>
        <div style="font-size:.8rem; color:#6b7280; margin-top:3px;">Total de <?= $total ?> módulos · <?= count($equipe) ?> usuários ativos</div>
    </div>
    <div style="display:flex; gap:.4rem;">
        <a href="<?= module_url('treinamento') ?>" class="btn btn-outline btn-sm">← Voltar</a>
        <a href="?<?= http_build_query(array_merge($_GET, array('csv'=>1))) ?>" class="btn btn-primary btn-sm" style="background:#059669;">📥 Exportar CSV</a>
    </div>
</div>

<div class="ta-filters" style="margin-bottom:1rem;">
    <?php
    $filtros = array('todos'=>'Todos','admin'=>'Admin','gestao'=>'Gestão','comercial'=>'Comercial','cx'=>'CX','operacional'=>'Operacional','estagiario'=>'Estagiário','colaborador'=>'Colaborador');
    foreach ($filtros as $k=>$lbl):
        $active = $filtroPerfil === $k ? 'active' : '';
    ?>
        <a href="?role=<?= $k ?>" class="ta-filter <?= $active ?>"><?= e($lbl) ?></a>
    <?php endforeach; ?>
</div>

<table class="ta-tbl">
    <thead>
        <tr>
            <th>Usuário</th><th>Perfil</th><th>Setor</th>
            <th>Progresso</th><th>Pontos</th><th>Último acesso</th><th>Ação</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($equipe as $r):
            $pct = $total > 0 ? round((int)$r['concluidos'] / $total * 100) : 0;
        ?>
        <tr>
            <td><strong><?= e($r['name']) ?></strong></td>
            <td><span style="padding:2px 8px; background:#f3f4f6; border-radius:99px; font-size:.7rem; font-weight:700;"><?= e($r['role']) ?></span></td>
            <td style="color:#6b7280;"><?= e($r['setor'] ?: '—') ?></td>
            <td style="min-width:200px;">
                <div style="display:flex; align-items:center; gap:8px;">
                    <div class="ta-prog-bar"><div class="ta-prog-fill" style="width:<?= $pct ?>%;"></div></div>
                    <span style="font-weight:700; font-size:.78rem; white-space:nowrap;"><?= (int)$r['concluidos'] ?>/<?= $total ?> · <?= $pct ?>%</span>
                </div>
            </td>
            <td><strong style="color:#B87333;"><?= (int)$r['pontos'] ?></strong></td>
            <td style="color:#6b7280; font-size:.78rem;"><?= $r['ultimo_acesso'] ? date('d/m/Y H:i', strtotime($r['ultimo_acesso'])) : '—' ?></td>
            <td>
                <?php if ((int)$r['concluidos'] > 0): ?>
                    <button class="ta-reset" onclick="resetarProgresso(<?= (int)$r['id'] ?>, '<?= e(addslashes($r['name'])) ?>')">🔄 Resetar</button>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

</div>

<script>
function resetarProgresso(uid, nome) {
    if (!confirm('Zerar TODO o progresso de treinamento de ' + nome + '?\n\nEla/ele precisará refazer os módulos do zero. Ação irreversível.')) return;
    var fd = new FormData();
    fd.append('action','resetar_progresso');
    fd.append('csrf_token','<?= e($csrf) ?>');
    fd.append('user_id', uid);
    fetch('<?= module_url('treinamento','api.php') ?>', { method:'POST', body:fd })
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (d.ok) { alert('✓ Progresso resetado.'); location.reload(); }
            else alert(d.error || 'Erro');
        });
}
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
