<?php
/**
 * Ferreira & Sá Hub — Clientes em Risco (visão exploratória)
 *
 * Tela complementar ao card "🌡️ Clientes precisam de atenção" do Painel do Dia.
 * Permite filtrar/buscar pra ALÉM dos critérios automáticos: ver adiados,
 * ver todos os ativos com score, busca por nome, ordenação custom.
 *
 * Acesso: admin/gestao (mesmo critério do card do painel).
 */
// Captura QUALQUER fatal e grava no log com stack — bug 500 sem display_errors
set_exception_handler(function($e) {
    $msg = date('Y-m-d H:i:s') . " | " . $e->getMessage() . "\n  in " . $e->getFile() . ':' . $e->getLine() . "\n  trace:\n" . $e->getTraceAsString() . "\n\n";
    @file_put_contents(__DIR__ . '/../../files/em_risco_erro.log', $msg, FILE_APPEND);
    if (!headers_sent()) { http_response_code(500); header('Content-Type: text/plain; charset=utf-8'); }
    echo "Erro: " . $e->getMessage() . "\n(detalhes em /files/em_risco_erro.log)";
    exit;
});
register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) {
        $msg = date('Y-m-d H:i:s') . " | FATAL " . $err['message'] . "\n  in " . $err['file'] . ':' . $err['line'] . "\n\n";
        @file_put_contents(__DIR__ . '/../../files/em_risco_erro.log', $msg, FILE_APPEND);
    }
});

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/database.php';
require_once __DIR__ . '/../../core/auth.php';
require_once __DIR__ . '/../../core/middleware.php';
require_once __DIR__ . '/../../core/functions_utils.php';
require_once __DIR__ . '/../../core/functions_caso_visual.php';

require_login();
require_access('clientes');

$pdo = db();
$pageTitle = '🌡️ Clientes em Risco';

// Self-heal das colunas do detector (caso o migrar_ia.php não tenha rodado)
try { $pdo->exec("ALTER TABLE clients ADD COLUMN esfriando_score INT DEFAULT 0"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE clients ADD COLUMN esfriando_motivos TEXT NULL"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE clients ADD COLUMN esfriando_em DATETIME NULL"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE clients ADD COLUMN esfriando_snooze_ate DATE NULL"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE clients ADD COLUMN esfriando_snooze_por INT NULL"); } catch (Exception $e) {}

// Filtros
$filtro    = $_GET['filtro']    ?? 'em_risco';  // em_risco | risco_real | esfriando | adiados | todos_ativos
$busca     = trim((string)($_GET['q'] ?? ''));

$where = array("1=1");
$params = array();

// Filtra clientes que têm pelo menos 1 case ativo (universo do detector)
$where[] = "EXISTS (SELECT 1 FROM cases cs WHERE cs.client_id = c.id
                    AND cs.status NOT IN ('arquivado','renunciamos','finalizado','concluido','cancelado')
                    AND COALESCE(cs.kanban_oculto,0) = 0 AND COALESCE(cs.acompanhamento_externo,0) = 0)";

if ($filtro === 'em_risco') {
    $where[] = "COALESCE(c.esfriando_score,0) >= 40";
    $where[] = "(c.esfriando_snooze_ate IS NULL OR c.esfriando_snooze_ate < CURDATE())";
} elseif ($filtro === 'risco_real') {
    $where[] = "COALESCE(c.esfriando_score,0) >= 80";
    $where[] = "(c.esfriando_snooze_ate IS NULL OR c.esfriando_snooze_ate < CURDATE())";
} elseif ($filtro === 'esfriando') {
    $where[] = "COALESCE(c.esfriando_score,0) BETWEEN 40 AND 79";
    $where[] = "(c.esfriando_snooze_ate IS NULL OR c.esfriando_snooze_ate < CURDATE())";
} elseif ($filtro === 'adiados') {
    $where[] = "c.esfriando_snooze_ate IS NOT NULL AND c.esfriando_snooze_ate >= CURDATE()";
}
// 'todos_ativos' não adiciona filtro — pega todos do universo

if ($busca !== '') {
    $where[] = "(c.name LIKE ? OR c.cpf LIKE ? OR c.phone LIKE ?)";
    $params[] = '%' . $busca . '%';
    $params[] = '%' . preg_replace('/\D/', '', $busca) . '%';
    $params[] = '%' . preg_replace('/\D/', '', $busca) . '%';
}

$whereSql = implode(' AND ', $where);

$sql = "SELECT c.id, c.name, c.phone, c.esfriando_score, c.esfriando_motivos, c.esfriando_em,
               c.esfriando_snooze_ate, c.esfriando_snooze_por,
               u.name AS snooze_por_name,
               (SELECT cs.id FROM cases cs WHERE cs.client_id = c.id
                  AND cs.status NOT IN ('arquivado','renunciamos','finalizado','concluido','cancelado')
                  AND COALESCE(cs.kanban_oculto,0)=0 AND COALESCE(cs.acompanhamento_externo,0)=0
                ORDER BY cs.updated_at DESC LIMIT 1) AS principal_case_id,
               (SELECT cs.case_type FROM cases cs WHERE cs.client_id = c.id
                  AND cs.status NOT IN ('arquivado','renunciamos','finalizado','concluido','cancelado')
                  AND COALESCE(cs.kanban_oculto,0)=0 AND COALESCE(cs.acompanhamento_externo,0)=0
                ORDER BY cs.updated_at DESC LIMIT 1) AS principal_case_type,
               (SELECT COUNT(*) FROM cases cs WHERE cs.client_id = c.id
                  AND cs.status NOT IN ('arquivado','renunciamos','finalizado','concluido','cancelado')
                  AND COALESCE(cs.kanban_oculto,0)=0 AND COALESCE(cs.acompanhamento_externo,0)=0) AS qtd_cases_ativos
        FROM clients c
        LEFT JOIN users u ON u.id = c.esfriando_snooze_por
        WHERE $whereSql
        ORDER BY COALESCE(c.esfriando_score,0) DESC, c.name ASC
        LIMIT 200";

$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// Contagens pros chips
$cnt = array();
$baseCnt = "FROM clients c WHERE EXISTS (SELECT 1 FROM cases cs WHERE cs.client_id = c.id
            AND cs.status NOT IN ('arquivado','renunciamos','finalizado','concluido','cancelado')
            AND COALESCE(cs.kanban_oculto,0)=0 AND COALESCE(cs.acompanhamento_externo,0)=0)";
try {
    $cnt['em_risco']     = (int)$pdo->query("SELECT COUNT(*) $baseCnt AND COALESCE(c.esfriando_score,0) >= 40 AND (c.esfriando_snooze_ate IS NULL OR c.esfriando_snooze_ate < CURDATE())")->fetchColumn();
    $cnt['risco_real']   = (int)$pdo->query("SELECT COUNT(*) $baseCnt AND COALESCE(c.esfriando_score,0) >= 80 AND (c.esfriando_snooze_ate IS NULL OR c.esfriando_snooze_ate < CURDATE())")->fetchColumn();
    $cnt['esfriando']    = (int)$pdo->query("SELECT COUNT(*) $baseCnt AND COALESCE(c.esfriando_score,0) BETWEEN 40 AND 79 AND (c.esfriando_snooze_ate IS NULL OR c.esfriando_snooze_ate < CURDATE())")->fetchColumn();
    $cnt['adiados']      = (int)$pdo->query("SELECT COUNT(*) $baseCnt AND c.esfriando_snooze_ate IS NOT NULL AND c.esfriando_snooze_ate >= CURDATE()")->fetchColumn();
    $cnt['todos_ativos'] = (int)$pdo->query("SELECT COUNT(*) $baseCnt")->fetchColumn();
} catch (Exception $e) {}

require_once __DIR__ . '/../../templates/layout_start.php';

function _chip($filtro_atual, $chave, $label, $count, $cor) {
    $ativo = $filtro_atual === $chave;
    $bg    = $ativo ? $cor : '#fff';
    $color = $ativo ? '#fff' : $cor;
    $href  = '?filtro=' . urlencode($chave);
    return '<a href="' . $href . '" style="display:inline-flex;align-items:center;gap:.3rem;padding:.3rem .7rem;border:1px solid ' . $cor . ';background:' . $bg . ';color:' . $color . ';border-radius:20px;font-size:.78rem;font-weight:700;text-decoration:none;">'
         . $label . ' <span style="background:rgba(' . ($ativo ? '255,255,255,.2' : '0,0,0,.05') . ');padding:.05rem .35rem;border-radius:10px;font-size:.7rem;">' . $count . '</span></a>';
}
?>

<div style="max-width:1200px;">
<h1 style="margin-bottom:.3rem;">🌡️ Clientes em Risco</h1>
<p style="color:#6b7280;margin-bottom:1rem;">Visão exploratória ampla do detector de esfriando. Pra ver só os críticos do dia, use o card do <a href="<?= module_url('painel') ?>" style="color:#6366f1;">Painel do Dia</a>.</p>

<!-- Chips de filtro -->
<div style="display:flex;flex-wrap:wrap;gap:.45rem;margin-bottom:1rem;">
    <?= _chip($filtro, 'em_risco',     '🌡️ Em risco (40+)',   $cnt['em_risco']     ?? 0, '#f59e0b') ?>
    <?= _chip($filtro, 'risco_real',   '🔴 Risco real (80+)',  $cnt['risco_real']   ?? 0, '#dc2626') ?>
    <?= _chip($filtro, 'esfriando',    '🟡 Esfriando (40-79)', $cnt['esfriando']    ?? 0, '#92400e') ?>
    <?= _chip($filtro, 'adiados',      '💤 Adiados (snooze)',  $cnt['adiados']      ?? 0, '#6366f1') ?>
    <?= _chip($filtro, 'todos_ativos', '👥 Todos ativos',      $cnt['todos_ativos'] ?? 0, '#0e7490') ?>
</div>

<!-- Busca -->
<form method="GET" style="margin-bottom:1rem;display:flex;gap:.5rem;">
    <input type="hidden" name="filtro" value="<?= e($filtro) ?>">
    <input type="text" name="q" value="<?= e($busca) ?>" placeholder="🔎 Buscar por nome, CPF ou telefone..." style="flex:1;padding:.5rem .8rem;border:1px solid #d1d5db;border-radius:6px;font-size:.88rem;">
    <button type="submit" style="background:#6366f1;color:#fff;border:none;padding:.5rem 1.2rem;border-radius:6px;cursor:pointer;font-weight:600;">Buscar</button>
    <?php if ($busca !== ''): ?><a href="?filtro=<?= e($filtro) ?>" style="background:#fff;border:1px solid #cbd5e1;color:#475569;padding:.5rem 1rem;border-radius:6px;text-decoration:none;">✕ Limpar</a><?php endif; ?>
</form>

<!-- Tabela -->
<?php if (empty($rows)): ?>
    <div style="background:#f0fdf4;border:1px solid #86efac;color:#15803d;padding:1.5rem;border-radius:10px;text-align:center;">
        <div style="font-size:1.8rem;margin-bottom:.3rem;">✅</div>
        <strong>Nenhum cliente neste filtro.</strong>
        <?php if ($busca !== ''): ?><br><span style="font-size:.85rem;">Tente buscar por outro termo ou trocar o filtro.</span><?php endif; ?>
    </div>
<?php else: ?>
<div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;">
<table style="width:100%;border-collapse:collapse;font-size:.85rem;">
    <thead style="background:#f9fafb;">
    <tr style="text-align:left;">
        <th style="padding:.6rem .8rem;">Cliente</th>
        <th style="padding:.6rem .8rem;">Tipo de ação</th>
        <th style="padding:.6rem .8rem;text-align:center;">Score</th>
        <th style="padding:.6rem .8rem;">Motivos</th>
        <th style="padding:.6rem .8rem;text-align:center;">Cases</th>
        <th style="padding:.6rem .8rem;">Status</th>
        <th style="padding:.6rem .8rem;text-align:right;">Ações</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r):
        $score = (int)$r['esfriando_score'];
        $adiado = !empty($r['esfriando_snooze_ate']) && strtotime($r['esfriando_snooze_ate']) >= strtotime(date('Y-m-d'));
        $bg = $score >= 80 ? '#fef2f2' : ($score >= 40 ? '#fffbeb' : '#fff');
        // Visual por tipo de ação (helper compartilhado)
        list($_tEmoji, $_tLabel, $_tCor) = caso_tipo_visual($r['principal_case_type'] ?? '');
    ?>
    <tr style="border-top:1px solid #f3f4f6;background:<?= $bg ?>;border-left:4px solid <?= $_tCor ?>;">
        <td style="padding:.55rem .8rem;">
            <div style="font-weight:600;color:#052228;"><?= e($r['name']) ?></div>
            <?php if ($r['phone']): ?><div style="font-size:.7rem;color:#6b7280;"><?= e($r['phone']) ?></div><?php endif; ?>
        </td>
        <td style="padding:.55rem .8rem;">
            <span style="background:<?= $_tCor ?>;color:#fff;padding:.15rem .5rem;border-radius:4px;font-weight:700;font-size:.72rem;display:inline-block;"><?= $_tEmoji ?> <?= e($_tLabel) ?></span>
        </td>
        <td style="padding:.55rem .8rem;text-align:center;">
            <?php if ($score >= 80): ?>
                <span style="background:#dc2626;color:#fff;padding:.15rem .5rem;border-radius:5px;font-weight:700;">🔴 <?= $score ?></span>
            <?php elseif ($score >= 40): ?>
                <span style="background:#f59e0b;color:#fff;padding:.15rem .5rem;border-radius:5px;font-weight:700;">🟡 <?= $score ?></span>
            <?php elseif ($score > 0): ?>
                <span style="background:#e5e7eb;color:#374151;padding:.15rem .5rem;border-radius:5px;font-weight:700;"><?= $score ?></span>
            <?php else: ?>
                <span style="color:#9ca3af;">—</span>
            <?php endif; ?>
        </td>
        <td style="padding:.55rem .8rem;font-size:.78rem;color:#4b5563;">
            <?= e($r['esfriando_motivos'] ?: '—') ?>
            <?php if ($r['esfriando_em']): ?><div style="font-size:.65rem;color:#9ca3af;margin-top:.2rem;">calc em <?= date('d/m H:i', strtotime($r['esfriando_em'])) ?></div><?php endif; ?>
        </td>
        <td style="padding:.55rem .8rem;text-align:center;font-weight:600;color:#0e7490;"><?= (int)$r['qtd_cases_ativos'] ?></td>
        <td style="padding:.55rem .8rem;font-size:.78rem;">
            <?php if ($adiado): ?>
                <span style="background:#ede9fe;color:#5b21b6;padding:.15rem .45rem;border-radius:4px;font-weight:600;font-size:.72rem;">💤 Adiado até <?= date('d/m', strtotime($r['esfriando_snooze_ate'])) ?></span>
                <?php if ($r['snooze_por_name']): ?><div style="font-size:.65rem;color:#9ca3af;margin-top:.2rem;">por <?= e($r['snooze_por_name']) ?></div><?php endif; ?>
            <?php elseif ($score >= 40): ?>
                <span style="color:#92400e;font-weight:600;">⚠ precisa atenção</span>
            <?php else: ?>
                <span style="color:#15803d;">✓ ok</span>
            <?php endif; ?>
        </td>
        <td style="padding:.55rem .8rem;text-align:right;white-space:nowrap;">
            <?php if ($r['principal_case_id']): ?>
                <a href="<?= module_url('operacional', 'caso_ver.php?id=' . (int)$r['principal_case_id']) ?>" style="background:#0e7490;color:#fff;text-decoration:none;padding:.2rem .55rem;border-radius:5px;font-size:.7rem;font-weight:700;">📂 Pasta</a>
            <?php endif; ?>
            <a href="<?= module_url('clientes', 'ver.php?id=' . (int)$r['id']) ?>" style="background:#fff;border:1px solid #cbd5e1;color:#475569;text-decoration:none;padding:.2rem .55rem;border-radius:5px;font-size:.7rem;font-weight:700;">👤 Perfil</a>
            <?php if ($adiado): ?>
                <button type="button" onclick="cancelarSnooze(<?= (int)$r['id'] ?>, this)" title="Reverter o adiamento e voltar a monitorar este cliente" style="background:#fff;border:1px solid #a78bfa;color:#5b21b6;padding:.2rem .55rem;border-radius:5px;font-size:.7rem;font-weight:700;cursor:pointer;">↩ Voltar a monitorar</button>
            <?php elseif ($score >= 40): ?>
                <button type="button" onclick="adiarSnooze(<?= (int)$r['id'] ?>, this)" title="Adiar este cliente por 7 dias" style="background:#fff;border:1px solid #cbd5e1;color:#475569;padding:.2rem .55rem;border-radius:5px;font-size:.7rem;font-weight:700;cursor:pointer;">💤 Adiar 7d</button>
            <?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<p style="color:#9ca3af;font-size:.72rem;margin-top:.6rem;">Mostrando <?= count($rows) ?> de <?= ($cnt[$filtro] ?? $cnt['todos_ativos'] ?? 0) ?> resultados. Limite de 200 por página.</p>
<?php endif; ?>

</div>

<script>
function adiarSnooze(clientId, btn) {
    if (!confirm('Adiar este cliente do painel por 7 dias?')) return;
    btn.disabled = true; btn.textContent = '⏳';
    var fd = new FormData();
    fd.append('action', 'adiar_esfriando');
    fd.append('client_id', String(clientId));
    fd.append('dias', '7');
    fd.append('csrf_token', '<?= e(generate_csrf_token()) ?>');
    fetch('<?= module_url('painel', 'api.php') ?>', { method:'POST', body:fd, credentials:'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (d.error) { alert(d.error); btn.disabled=false; btn.textContent='💤 Adiar 7d'; return; }
            location.reload();
        });
}
function cancelarSnooze(clientId, btn) {
    if (!confirm('Voltar a monitorar este cliente?')) return;
    btn.disabled = true; btn.textContent = '⏳';
    var fd = new FormData();
    fd.append('action', 'desadiar_esfriando');
    fd.append('client_id', String(clientId));
    fd.append('csrf_token', '<?= e(generate_csrf_token()) ?>');
    fetch('<?= module_url('painel', 'api.php') ?>', { method:'POST', body:fd, credentials:'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (d.error) { alert(d.error); btn.disabled=false; btn.textContent='↩ Voltar a monitorar'; return; }
            location.reload();
        });
}
</script>

<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
