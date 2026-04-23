<?php
/**
 * Ferreira & Sá Hub — Central de Treinamento
 * Grid com 23 módulos + progresso pessoal + ranking do treinamento.
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();

$pageTitle = 'Treinamento';
$pdo = db();
$user = current_user();
$userId = (int)$user['id'];
$role = current_user_role();

// Self-heal schema (caso a migração ainda não tenha rodado)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS treinamento_progresso (
        id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL,
        modulo_slug VARCHAR(50) NOT NULL, conteudo_visto TINYINT(1) DEFAULT 0,
        missao_feita TINYINT(1) DEFAULT 0, quiz_concluido TINYINT(1) DEFAULT 0,
        concluido TINYINT(1) DEFAULT 0, quiz_acertos INT DEFAULT 0,
        quiz_tentativas INT DEFAULT 0, pontos_ganhos INT DEFAULT 0,
        concluido_em DATETIME NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_user_modulo (user_id, modulo_slug)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

$filtroPerfil = $_GET['perfil'] ?? 'todos';

$modulos = $pdo->query(
    "SELECT m.*,
            COALESCE(p.conteudo_visto, 0) AS conteudo_visto,
            COALESCE(p.missao_feita, 0) AS missao_feita,
            COALESCE(p.quiz_concluido, 0) AS quiz_concluido,
            COALESCE(p.concluido, 0) AS concluido,
            COALESCE(p.pontos_ganhos, 0) AS pontos_ganhos
     FROM treinamento_modulos m
     LEFT JOIN treinamento_progresso p ON p.modulo_slug = m.slug AND p.user_id = {$userId}
     WHERE m.ativo = 1
     ORDER BY m.ordem ASC"
)->fetchAll();

// Módulos restritos por whitelist (mesma regra do módulo financeiro real)
$slugsFinanceiros = array('financeiro', 'cobranca-honorarios');
if (!can_access_financeiro()) {
    $modulos = array_values(array_filter($modulos, function($m) use ($slugsFinanceiros){
        return !in_array($m['slug'], $slugsFinanceiros, true);
    }));
}

$modulosFiltrados = $modulos;
if ($filtroPerfil !== 'todos') {
    $modulosFiltrados = array_filter($modulos, function($m) use ($filtroPerfil, $role){
        $perfis = json_decode($m['perfis_alvo'], true) ?: array();
        if ($filtroPerfil === 'meus') {
            return in_array('todos', $perfis, true) || in_array($role, $perfis, true);
        }
        return in_array('todos', $perfis, true) || in_array($filtroPerfil, $perfis, true);
    });
}

$total = count($modulos);
$concluidos = count(array_filter($modulos, function($m){ return (int)$m['concluido'] === 1; }));
$pontosTotais = array_sum(array_column($modulos, 'pontos_ganhos'));
$pctProgresso = $total > 0 ? round($concluidos / $total * 100) : 0;

$ranking = $pdo->query(
    "SELECT u.id, u.name,
            COUNT(CASE WHEN p.concluido = 1 THEN 1 END) AS concluidos,
            COALESCE(SUM(p.pontos_ganhos), 0) AS pontos
     FROM users u
     LEFT JOIN treinamento_progresso p ON p.user_id = u.id
     WHERE u.is_active = 1
     GROUP BY u.id, u.name
     HAVING concluidos > 0 OR pontos > 0
     ORDER BY pontos DESC, concluidos DESC
     LIMIT 10"
)->fetchAll();

require_once APP_ROOT . '/templates/layout_start.php';
?>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600;700&family=Outfit:wght@400;600;700&display=swap" rel="stylesheet">

<style>
.tr-wrap { max-width:1400px; margin:0 auto; }
.tr-hero { background:linear-gradient(135deg, #052228, #0a3842); color:#fff; padding:1.5rem 2rem; border-radius:16px; margin-bottom:1.2rem; display:flex; gap:1.5rem; align-items:center; flex-wrap:wrap; }
.tr-hero h1 { margin:0; font-family:'Cormorant Garamond', serif; font-weight:600; font-size:2rem; letter-spacing:.5px; }
.tr-hero .tag { font-family:'Outfit', sans-serif; font-size:.9rem; opacity:.85; margin-top:3px; font-style:italic; color:#D7AB90; }
.tr-prog-bar { flex:1; min-width:260px; background:rgba(255,255,255,.1); border-radius:999px; height:24px; overflow:hidden; position:relative; }
.tr-prog-fill { height:100%; background:linear-gradient(90deg, #B87333, #D7AB90); border-radius:999px; transition:width .5s; }
.tr-prog-text { position:absolute; inset:0; display:flex; align-items:center; justify-content:center; font-size:.78rem; font-weight:700; color:#fff; }
.tr-stats { display:flex; gap:1.3rem; flex-wrap:wrap; }
.tr-stats .stat { background:rgba(255,255,255,.08); padding:.6rem 1rem; border-radius:10px; }
.tr-stats .stat .num { font-size:1.4rem; font-weight:700; font-family:'Outfit',sans-serif; }
.tr-stats .stat .lbl { font-size:.65rem; text-transform:uppercase; letter-spacing:.4px; opacity:.85; }
.tr-filters { display:flex; gap:.4rem; flex-wrap:wrap; margin-bottom:1rem; }
.tr-filter { padding:6px 14px; border-radius:999px; background:#fff; border:1.5px solid #e5e7eb; font-size:.78rem; font-weight:600; cursor:pointer; text-decoration:none; color:#052228; transition:all .15s; }
.tr-filter:hover { border-color:#B87333; color:#B87333; }
.tr-filter.active { background:#052228; color:#fff; border-color:#052228; }
.tr-layout { display:grid; grid-template-columns:1fr 300px; gap:1.5rem; align-items:start; }
@media (max-width:1024px) { .tr-layout { grid-template-columns:1fr; } }
.tr-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(280px, 1fr)); gap:1rem; }
.tr-card { background:#fff; border:1px solid #e5e7eb; border-radius:14px; padding:1.2rem; display:flex; flex-direction:column; gap:.6rem; transition:all .2s; position:relative; text-decoration:none; color:inherit; }
.tr-card:hover { border-color:#B87333; box-shadow:0 6px 20px rgba(184,115,51,.15); transform:translateY(-2px); }
.tr-card.concluido { background:linear-gradient(135deg, #fff 0%, #f5ede3 100%); border-color:#B87333; }
.tr-card .ico { font-size:2.2rem; line-height:1; }
.tr-card h3 { font-family:'Cormorant Garamond', serif; font-size:1.25rem; margin:0; color:#052228; font-weight:600; line-height:1.1; }
.tr-card .desc { font-size:.78rem; color:#6b7280; line-height:1.4; flex:1; }
.tr-card .perfis { display:flex; gap:3px; flex-wrap:wrap; }
.tr-card .perfil-pill { font-size:.58rem; background:#f5ede3; color:#78350f; padding:2px 7px; border-radius:999px; font-weight:700; text-transform:uppercase; letter-spacing:.3px; }
.tr-card .prog { display:flex; gap:3px; margin-top:6px; }
.tr-card .prog-step { flex:1; height:6px; background:#e5e7eb; border-radius:3px; }
.tr-card .prog-step.done { background:#B87333; }
.tr-card .meta { display:flex; justify-content:space-between; align-items:center; font-size:.72rem; color:#6b7280; margin-top:6px; }
.tr-card .pts { font-weight:700; color:#B87333; }
.tr-card .badge { position:absolute; top:10px; right:10px; font-size:.65rem; padding:2px 8px; border-radius:999px; font-weight:700; }
.tr-card .badge.ok { background:#059669; color:#fff; }
.tr-card .badge.andamento { background:#f59e0b; color:#fff; }
.tr-sidebar { background:#fff; border:1px solid #e5e7eb; border-radius:14px; padding:1.2rem; position:sticky; top:20px; }
.tr-sidebar h3 { font-family:'Cormorant Garamond', serif; margin:0 0 .8rem; color:#052228; font-size:1.3rem; }
.tr-rank-item { display:flex; align-items:center; gap:.6rem; padding:.5rem 0; border-bottom:1px solid #f3f4f6; }
.tr-rank-item:last-child { border:none; }
.tr-rank-pos { width:24px; height:24px; border-radius:50%; background:#f3f4f6; display:flex; align-items:center; justify-content:center; font-size:.72rem; font-weight:700; color:#6b7280; flex-shrink:0; }
.tr-rank-pos.p1 { background:linear-gradient(135deg,#fbbf24,#d97706); color:#fff; }
.tr-rank-pos.p2 { background:linear-gradient(135deg,#d1d5db,#9ca3af); color:#fff; }
.tr-rank-pos.p3 { background:linear-gradient(135deg,#d7ab90,#b87333); color:#fff; }
.tr-rank-info { flex:1; min-width:0; }
.tr-rank-nome { font-size:.78rem; font-weight:600; color:#052228; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.tr-rank-pts { font-size:.68rem; color:#6b7280; }
</style>

<div class="tr-wrap">

<div class="tr-hero">
    <div>
        <h1>🎓 Central de Treinamento — F&S Hub</h1>
        <div class="tag">"Domine o sistema. Ganhe pontos. Seja reconhecido."</div>
    </div>
    <div style="flex:1; min-width:260px;">
        <div class="tr-prog-bar">
            <div class="tr-prog-fill" style="width:<?= $pctProgresso ?>%;"></div>
            <div class="tr-prog-text"><?= $concluidos ?>/<?= $total ?> módulos · <?= $pctProgresso ?>%</div>
        </div>
    </div>
    <div class="tr-stats">
        <div class="stat"><div class="num"><?= $pontosTotais ?></div><div class="lbl">🏆 Pontos</div></div>
        <div class="stat"><div class="num"><?= $concluidos ?></div><div class="lbl">✅ Módulos</div></div>
    </div>
</div>

<div class="tr-filters">
    <?php
    $filtros = array(
        'todos' => 'Todos',
        'meus'  => '⭐ Pro meu perfil (' . $role . ')',
        'comercial' => 'Comercial',
        'cx' => 'CX',
        'operacional' => 'Operacional',
        'admin' => 'Admin/Gestão',
    );
    foreach ($filtros as $k => $lbl):
        $active = $filtroPerfil === $k ? 'active' : '';
    ?>
        <a href="?perfil=<?= $k ?>" class="tr-filter <?= $active ?>"><?= e($lbl) ?></a>
    <?php endforeach; ?>
</div>

<div class="tr-layout">
    <div class="tr-grid">
        <?php foreach ($modulosFiltrados as $m):
            $perfis = json_decode($m['perfis_alvo'], true) ?: array();
            $etapas = (int)$m['conteudo_visto'] + (int)$m['missao_feita'] + (int)$m['quiz_concluido'];
            $concluido = (int)$m['concluido'] === 1;
            $cardCls = $concluido ? 'concluido' : ($etapas > 0 ? 'andamento' : '');
        ?>
        <a href="<?= module_url('treinamento', 'modulo.php?slug=' . urlencode($m['slug'])) ?>" class="tr-card <?= $cardCls ?>">
            <?php if ($concluido): ?>
                <span class="badge ok">✓ Concluído</span>
            <?php elseif ($etapas > 0): ?>
                <span class="badge andamento">▶ <?= $etapas ?>/3</span>
            <?php endif; ?>
            <div class="ico"><?= e($m['icone']) ?></div>
            <h3><?= e($m['titulo']) ?></h3>
            <div class="desc"><?= e($m['descricao']) ?></div>
            <div class="perfis">
                <?php foreach ($perfis as $p): ?>
                    <span class="perfil-pill"><?= e($p) ?></span>
                <?php endforeach; ?>
            </div>
            <div class="prog">
                <div class="prog-step <?= $m['conteudo_visto'] ? 'done' : '' ?>" title="Conteúdo"></div>
                <div class="prog-step <?= $m['missao_feita']   ? 'done' : '' ?>" title="Missão"></div>
                <div class="prog-step <?= $m['quiz_concluido'] ? 'done' : '' ?>" title="Quiz"></div>
            </div>
            <div class="meta">
                <span>⏱ ~<?= $m['pontos'] >= 70 ? '15' : ($m['pontos'] >= 50 ? '10' : '5') ?> min</span>
                <span class="pts">+<?= (int)$m['pontos'] ?> pts</span>
            </div>
        </a>
        <?php endforeach; ?>
        <?php if (empty($modulosFiltrados)): ?>
            <div style="grid-column:1/-1;text-align:center;padding:2rem;color:#6b7280;">Nenhum módulo para este filtro.</div>
        <?php endif; ?>
    </div>

    <aside class="tr-sidebar">
        <h3>🏆 Quem mais estudou</h3>
        <?php if (empty($ranking)): ?>
            <p style="color:#6b7280; font-size:.82rem;">Ninguém concluiu módulos ainda. Seja o primeiro!</p>
        <?php else:
            foreach ($ranking as $i => $r):
                $pos = $i + 1;
                $cls = $pos <= 3 ? 'p' . $pos : '';
                $crown = $pos === 1 ? ' 👑' : '';
        ?>
            <div class="tr-rank-item">
                <div class="tr-rank-pos <?= $cls ?>"><?= $pos ?></div>
                <div class="tr-rank-info">
                    <div class="tr-rank-nome"><?= e($r['name']) ?><?= $crown ?></div>
                    <div class="tr-rank-pts"><?= (int)$r['concluidos'] ?> módulos · <?= (int)$r['pontos'] ?> pts</div>
                </div>
            </div>
        <?php endforeach; endif; ?>
        <?php if (has_min_role('gestao')): ?>
            <hr style="border:none; border-top:1px solid #e5e7eb; margin:1rem 0;">
            <a href="<?= module_url('treinamento', 'equipe.php') ?>" style="display:block; padding:8px 12px; background:#052228; color:#fff; border-radius:8px; text-align:center; font-size:.78rem; font-weight:700; text-decoration:none;">📊 Progresso da Equipe</a>
        <?php endif; ?>
    </aside>
</div>

</div>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
