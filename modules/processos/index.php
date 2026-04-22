<?php
/**
 * Ferreira & Sá Hub — Processos Judiciais
 * Somente demandas com número de processo judicial
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();

$pageTitle = 'Processos Judiciais';
$pdo = db();
$isColaborador = has_role('colaborador');

// Filtros
$filterStatus = isset($_GET['status']) ? $_GET['status'] : '';
$filterType = isset($_GET['type']) ? $_GET['type'] : '';
$filterUser = isset($_GET['user']) ? $_GET['user'] : '';
$search = isset($_GET['q']) ? trim($_GET['q']) : '';

$statusLabels = array(
    'ativo' => 'Ativo', 'aguardando_docs' => 'Aguardando Docs', 'em_elaboracao' => 'Em Elaboração',
    'em_andamento' => 'Em Andamento', 'aguardando_prazo' => 'Aguardando Prazo',
    'distribuido' => 'Distribuído', 'concluido' => 'Concluído', 'arquivado' => 'Arquivado', 'suspenso' => 'Suspenso',
);
$statusBadge = array(
    'ativo' => 'info', 'aguardando_docs' => 'warning', 'em_elaboracao' => 'info',
    'em_andamento' => 'info', 'aguardando_prazo' => 'warning', 'distribuido' => 'success',
    'concluido' => 'success', 'arquivado' => 'gestao', 'suspenso' => 'danger',
);
$priorityBadge = array('urgente' => 'danger', 'alta' => 'warning', 'normal' => 'gestao', 'baixa' => 'colaborador');

// Query — só processos judiciais (com case_number OU is_judicial = 1)
$where = array("(cs.case_number IS NOT NULL AND cs.case_number != '')");
$params = array();

if ($isColaborador) {
    $where[] = "cs.responsible_user_id = ?";
    $params[] = current_user_id();
}
// Por default, só mostra processos em andamento (esconde arquivados/cancelados/concluídos).
// `?ver_arquivados=1` mostra todos. Se filtro status explícito foi escolhido, respeita.
$verArquivados = (($_GET['ver_arquivados'] ?? '') === '1');
if ($filterStatus) {
    $where[] = "cs.status = ?"; $params[] = $filterStatus;
} elseif (!$verArquivados) {
    $where[] = "cs.status NOT IN ('arquivado','cancelado','concluido')";
}
if ($filterType) { $where[] = "cs.case_type = ?"; $params[] = $filterType; }
if ($filterUser && !$isColaborador) { $where[] = "cs.responsible_user_id = ?"; $params[] = (int)$filterUser; }
if ($search) {
    $where[] = "(cs.title LIKE ? OR cs.case_number LIKE ? OR c.name LIKE ? OR cs.court LIKE ?)";
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}
$filterVinculo = isset($_GET['vinculo']) ? $_GET['vinculo'] : '';
if ($filterVinculo === 'principais') { $where[] = "(cs.is_incidental = 0 OR cs.is_incidental IS NULL)"; }
elseif ($filterVinculo === 'incidentais') { $where[] = "cs.is_incidental = 1 AND (cs.tipo_vinculo IS NULL OR cs.tipo_vinculo != 'recurso')"; }
elseif ($filterVinculo === 'recursos') { $where[] = "cs.is_incidental = 1 AND cs.tipo_vinculo = 'recurso'"; }

// Filtros especiais
$filterEspecial = isset($_GET['especial']) ? $_GET['especial'] : '';
if ($filterEspecial === 'sem_andamento_30d') {
    $where[] = "((SELECT MAX(a.data_andamento) FROM case_andamentos a WHERE a.case_id = cs.id) < DATE_SUB(CURDATE(), INTERVAL 30 DAY) OR NOT EXISTS (SELECT 1 FROM case_andamentos a2 WHERE a2.case_id = cs.id))";
    $where[] = "cs.status NOT IN ('concluido','arquivado','cancelado')";
}
if ($filterEspecial === 'audiencia_marcada') {
    $where[] = "EXISTS (SELECT 1 FROM agenda_eventos ae WHERE ae.case_id = cs.id AND ae.tipo = 'audiencia' AND ae.data_inicio >= NOW() AND ae.status != 'cancelado')";
}
if ($filterEspecial === 'prazo_proximo') {
    $where[] = "EXISTS (SELECT 1 FROM prazos_processuais pp WHERE pp.case_id = cs.id AND pp.concluido = 0 AND pp.prazo_fatal BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 15 DAY))";
}
if ($filterEspecial === 'segredo_justica') {
    $where[] = "cs.segredo_justica = 1";
}
if ($filterEspecial === 'doc_faltante') {
    $where[] = "cs.status = 'doc_faltante'";
}

// Ordenação — suporta presets do select antigo (alfa/recentes/atualizados/prazo) +
// clique nos headers (numero/cliente/titulo/tipo/vara/status/prioridade/responsavel/ultimo/prazo)
$orderBy = isset($_GET['ordem']) ? $_GET['ordem'] : 'alfa';
$orderDir = (isset($_GET['dir']) && strtolower($_GET['dir']) === 'desc') ? 'DESC' : 'ASC';
$orderMap = array(
    'alfa'        => array('sql' => 'c.name ASC, cs.title ASC', 'default_dir' => 'ASC'),
    'recentes'    => array('sql' => 'cs.created_at DESC', 'default_dir' => 'DESC'),
    'atualizados' => array('sql' => 'cs.updated_at DESC', 'default_dir' => 'DESC'),
    'numero'      => array('col' => 'cs.case_number'),
    'cliente'     => array('col' => 'c.name'),
    'titulo'      => array('col' => 'cs.title'),
    'tipo'        => array('col' => 'cs.case_type'),
    'vara'        => array('col' => 'cs.court'),
    'status'      => array('col' => 'cs.status'),
    'prioridade'  => array('col' => "FIELD(cs.priority,'urgente','alta','normal','baixa')"),
    'responsavel' => array('col' => 'u.name'),
    'ultimo'      => array('col' => '(SELECT MAX(a.data_andamento) FROM case_andamentos a WHERE a.case_id = cs.id)', 'default_dir' => 'DESC'),
    'prazo'       => array('col' => 'cs.deadline'),
);
if (isset($orderMap[$orderBy])) {
    $info = $orderMap[$orderBy];
    if (isset($info['sql'])) {
        $orderSql = $info['sql'];
    } else {
        $orderSql = $info['col'] . ' ' . $orderDir . ', c.name ASC';
    }
} else {
    $orderBy = 'alfa';
    $orderSql = 'c.name ASC, cs.title ASC';
}

// Helper pra montar URL de sort no header
function sort_link($col, $orderBy, $orderDir, $label) {
    $qs = $_GET;
    $qs['ordem'] = $col;
    $isActive = ($orderBy === $col);
    $nextDir = ($isActive && $orderDir === 'ASC') ? 'desc' : 'asc';
    $qs['dir'] = $nextDir;
    unset($qs['page']);
    $indicator = $isActive ? ($orderDir === 'ASC' ? ' ▲' : ' ▼') : '';
    return '<a href="?' . http_build_query($qs) . '" style="color:inherit;text-decoration:none;display:block;">' . htmlspecialchars($label) . '<span style="font-size:.6rem;opacity:' . ($isActive ? '1' : '.35') . ';">' . ($indicator ?: ' ⇅') . '</span></a>';
}

$whereStr = implode(' AND ', $where);

// ── PAGINAÇÃO ──
$perPage = 15;
$pageNum = max(1, (int)($_GET['page'] ?? 1));
$offset = ($pageNum - 1) * $perPage;

// Total pra calcular número de páginas
$stmtCount = $pdo->prepare(
    "SELECT COUNT(*)
     FROM cases cs
     LEFT JOIN clients c ON c.id = cs.client_id
     LEFT JOIN users u ON u.id = cs.responsible_user_id
     WHERE $whereStr"
);
$stmtCount->execute($params);
$totalProcessos = (int)$stmtCount->fetchColumn();
$totalPaginas = max(1, (int)ceil($totalProcessos / $perPage));
if ($pageNum > $totalPaginas) { $pageNum = $totalPaginas; $offset = ($pageNum - 1) * $perPage; }

$stmt = $pdo->prepare(
    "SELECT cs.*, c.name as client_name, c.phone as client_phone, c.cpf as client_cpf,
     u.name as responsible_name,
     (SELECT MAX(a.data_andamento) FROM case_andamentos a WHERE a.case_id = cs.id) as ultimo_andamento,
     (SELECT COUNT(*) FROM agenda_eventos ae WHERE ae.case_id = cs.id AND ae.tipo = 'audiencia' AND ae.data_inicio >= NOW() AND ae.status != 'cancelado') as audiencias_futuras,
     (SELECT MIN(ae2.data_inicio) FROM agenda_eventos ae2 WHERE ae2.case_id = cs.id AND ae2.tipo = 'audiencia' AND ae2.data_inicio >= NOW() AND ae2.status != 'cancelado') as prox_audiencia,
     (SELECT GROUP_CONCAT(DISTINCT cl2.name SEPARATOR ', ') FROM case_partes cp2 INNER JOIN clients cl2 ON cl2.id = cp2.client_id WHERE cp2.case_id = cs.id AND cp2.client_id IS NOT NULL AND cp2.client_id != cs.client_id) as outros_clientes
     FROM cases cs
     LEFT JOIN clients c ON c.id = cs.client_id
     LEFT JOIN users u ON u.id = cs.responsible_user_id
     WHERE $whereStr ORDER BY $orderSql LIMIT $perPage OFFSET $offset"
);
$stmt->execute($params);
$processos = $stmt->fetchAll();

// ── ÚLTIMAS INTIMAÇÕES / DISTRIBUÍDOS (quadrinho no topo) ──
// Intimações vêm da tabela case_publicacoes (alimentada pelo Claudin/DJEN)
$ultimasIntimacoes = array();
$ultimosDistribuidos = array();
try {
    // Só intimações pendentes (não confirmadas/descartadas) — feed de "o que precisa de atenção"
    $stmtInt = $pdo->query(
        "SELECT cp.id AS pub_id, cp.case_id, cp.data_disponibilizacao, cp.tipo_publicacao, cp.resumo_ia,
                cp.status_prazo, cs.title, cs.case_number, c.name AS client_name
         FROM case_publicacoes cp
         INNER JOIN cases cs ON cs.id = cp.case_id
         LEFT JOIN clients c ON c.id = cs.client_id
         WHERE cp.status_prazo = 'pendente'
         ORDER BY cp.data_disponibilizacao DESC, cp.id DESC
         LIMIT 5"
    );
    $ultimasIntimacoes = $stmtInt->fetchAll();

    $stmtDist = $pdo->query(
        "SELECT cs.id, cs.title, cs.updated_at, cs.case_number, c.name AS client_name, u.name AS responsible_name
         FROM cases cs
         LEFT JOIN clients c ON c.id = cs.client_id
         LEFT JOIN users u ON u.id = cs.responsible_user_id
         WHERE cs.status = 'distribuido'
         ORDER BY cs.updated_at DESC
         LIMIT 3"
    );
    $ultimosDistribuidos = $stmtDist->fetchAll();
} catch (Exception $e) {}

// Identificar quais processos são "principais" (têm filhos vinculados)
$principaisIds = array();
try {
    $allIds = array_column($processos, 'id');
    if (!empty($allIds)) {
        $inPlaceholders = implode(',', array_fill(0, count($allIds), '?'));
        $stmtPrinc = $pdo->prepare("SELECT DISTINCT processo_principal_id FROM cases WHERE processo_principal_id IN ($inPlaceholders)");
        $stmtPrinc->execute($allIds);
        $principaisIds = $stmtPrinc->fetchAll(PDO::FETCH_COLUMN);
    }
} catch (Exception $e) {}

// KPIs
$totalJudiciais = (int)$pdo->query("SELECT COUNT(*) FROM cases WHERE case_number IS NOT NULL AND case_number != ''")->fetchColumn();
$ativosJ = (int)$pdo->query("SELECT COUNT(*) FROM cases WHERE case_number IS NOT NULL AND case_number != '' AND status NOT IN ('concluido','arquivado')")->fetchColumn();
$semAndamento30d = (int)$pdo->query(
    "SELECT COUNT(*) FROM cases cs
     WHERE cs.case_number IS NOT NULL AND cs.case_number != ''
     AND cs.status NOT IN ('concluido','arquivado','cancelado')
     AND (
         (SELECT MAX(a.data_andamento) FROM case_andamentos a WHERE a.case_id = cs.id) < DATE_SUB(CURDATE(), INTERVAL 30 DAY)
         OR NOT EXISTS (SELECT 1 FROM case_andamentos a2 WHERE a2.case_id = cs.id)
     )"
)->fetchColumn();
$comAudiencia = (int)$pdo->query("SELECT COUNT(DISTINCT ae.case_id) FROM agenda_eventos ae JOIN cases cs ON cs.id = ae.case_id WHERE cs.case_number IS NOT NULL AND cs.case_number != '' AND ae.tipo = 'audiencia' AND ae.data_inicio >= NOW() AND ae.status != 'cancelado'")->fetchColumn();

$tipos = $pdo->query("SELECT DISTINCT case_type FROM cases WHERE case_number IS NOT NULL AND case_number != '' AND case_type IS NOT NULL AND case_type != '' ORDER BY case_type")->fetchAll(PDO::FETCH_COLUMN);
$users = $pdo->query("SELECT id, name FROM users WHERE is_active = 1 ORDER BY name")->fetchAll();

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.proc-stats { display:flex; gap:.75rem; margin-bottom:1.25rem; flex-wrap:wrap; }
.proc-stat { background:var(--bg-card); border-radius:var(--radius-lg); border:1px solid var(--border); padding:.75rem 1.25rem; display:flex; align-items:center; gap:.75rem; min-width:140px; }
.proc-stat-icon { font-size:1.2rem; }
.proc-stat-val { font-size:1.4rem; font-weight:800; color:var(--petrol-900); }
.proc-stat-lbl { font-size:.68rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:.3px; }

.proc-toolbar { display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; flex-wrap:wrap; gap:.75rem; }
.proc-filters { display:flex; gap:.4rem; flex-wrap:wrap; align-items:center; }
.proc-filter-sel { font-size:.75rem; padding:.35rem .5rem; border:1.5px solid var(--border); border-radius:var(--radius); background:var(--bg-card); }

.proc-table { width:100%; border-collapse:collapse; font-size:.82rem; table-layout:fixed; }
.proc-table th { background:var(--petrol-900); color:#fff; padding:.55rem .35rem; text-align:center; font-size:.7rem; text-transform:uppercase; letter-spacing:.5px; cursor:pointer; }
.proc-table th a { color:#fff; }
.proc-table th a:hover { color:#fbbf24; }
.proc-table td { padding:.55rem .5rem; border-bottom:1px solid var(--border); vertical-align:middle; word-wrap:break-word; overflow-wrap:break-word; text-align:center; }
/* Cliente (2) e Título (3) ficam alinhados à esquerda — textos longos */
.proc-table th:nth-child(2), .proc-table th:nth-child(3),
.proc-table td:nth-child(2), .proc-table td:nth-child(3) { text-align:left; }
.proc-table tr:hover { background:rgba(215,171,144,.04); }
.proc-number { font-family:monospace; font-size:.68rem; color:var(--petrol-500); font-weight:600; white-space:normal; word-break:break-all; line-height:1.35; display:inline-block; max-width:100%; }
.case-link { color:var(--petrol-900); font-weight:700; text-decoration:none; }
.case-link:hover { color:var(--rose); }
.client-link { color:var(--petrol-500); font-weight:600; text-decoration:none; }
.client-link:hover { color:var(--rose); }
.proc-table tr.vinculo-principal { border-left:4px solid #052228; }
.proc-table tr.vinculo-incidental { border-left:4px solid #6366f1; background:rgba(99,102,241,.03); }
.proc-table tr.vinculo-recurso { border-left:4px solid #d97706; background:rgba(217,119,6,.03); }
.proc-badge-vinc { display:inline-block; padding:1px 6px; border-radius:4px; font-size:.6rem; font-weight:700; color:#fff; margin-left:.35rem; vertical-align:middle; }
</style>

<!-- Últimos processos (quadrinho informativo) -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;margin-bottom:1rem;">
    <div style="background:#fff;border:1px solid var(--border);border-left:3px solid #6366f1;border-radius:var(--radius-md);padding:.6rem .85rem;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.4rem;">
            <strong style="font-size:.78rem;color:var(--petrol-900);">📢 Intimações pendentes</strong>
            <span style="font-size:.65rem;color:#64748b;"><?= count($ultimasIntimacoes) ?> a revisar</span>
        </div>
        <?php if (empty($ultimasIntimacoes)): ?>
            <div style="color:#94a3b8;font-size:.75rem;padding:.3rem 0;">✅ Nenhuma intimação pendente.</div>
        <?php else: ?>
            <?php
            $tipoIntLbl = array(
                'intimacao' => 'Intimação', 'citacao' => 'Citação',
                'despacho' => 'Despacho', 'decisao' => 'Decisão',
                'sentenca' => 'Sentença', 'acordao' => 'Acórdão',
                'edital' => 'Edital', 'outro' => 'Publicação',
            );
            $_csrfIntim = generate_csrf_token();
            $_backUrl = module_url('processos');
            foreach ($ultimasIntimacoes as $ui):
                $dataDisp = $ui['data_disponibilizacao'];
                $agoInt = time() - strtotime($dataDisp);
                if     ($agoInt < 3600)  $agoIntLbl = floor($agoInt/60) . 'min atrás';
                elseif ($agoInt < 86400) $agoIntLbl = floor($agoInt/3600) . 'h atrás';
                elseif ($agoInt < 604800) $agoIntLbl = floor($agoInt/86400) . 'd atrás';
                else                     $agoIntLbl = date('d/m', strtotime($dataDisp));
                $tipoLbl = isset($tipoIntLbl[$ui['tipo_publicacao']]) ? $tipoIntLbl[$ui['tipo_publicacao']] : ucfirst($ui['tipo_publicacao']);
                $resumo = $ui['resumo_ia'] ?: '';
            ?>
                <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:.4rem;padding:6px 0;border-bottom:1px solid #f1f5f9;font-size:.75rem;">
                    <a href="<?= module_url('operacional', 'caso_ver.php?id=' . (int)$ui['case_id']) ?>" style="flex:1;min-width:0;text-decoration:none;color:inherit;">
                        <div style="font-weight:600;color:var(--petrol-900);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                            <span style="background:#eef2ff;color:#4338ca;font-size:.6rem;font-weight:700;padding:1px 6px;border-radius:8px;margin-right:4px;"><?= e($tipoLbl) ?></span>
                            <?= e($ui['title'] ?: 'Processo #' . $ui['case_id']) ?>
                        </div>
                        <?php if ($resumo): ?>
                            <div style="font-size:.66rem;color:#4338ca;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;margin-top:1px;" title="<?= e($resumo) ?>"><?= e($resumo) ?></div>
                        <?php else: ?>
                            <div style="font-size:.66rem;color:#64748b;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                <?= e($ui['client_name'] ?: '—') ?>
                            </div>
                        <?php endif; ?>
                    </a>
                    <div style="display:flex;flex-direction:column;align-items:flex-end;gap:3px;flex-shrink:0;">
                        <span style="font-size:.62rem;color:#6366f1;font-weight:600;white-space:nowrap;"><?= $agoIntLbl ?></span>
                        <div style="display:flex;gap:3px;">
                            <form method="POST" action="<?= module_url('operacional', 'api.php') ?>" onsubmit="return confirm('Confirmar prazo desta intimação? (você vai cumprir)');">
                                <?= csrf_input() ?>
                                <input type="hidden" name="action" value="confirmar_prazo_publicacao">
                                <input type="hidden" name="pub_id" value="<?= (int)$ui['pub_id'] ?>">
                                <input type="hidden" name="case_id" value="<?= (int)$ui['case_id'] ?>">
                                <input type="hidden" name="novo_status" value="confirmado">
                                <input type="hidden" name="_back" value="<?= htmlspecialchars($_backUrl, ENT_QUOTES, 'UTF-8') ?>">
                                <button type="submit" title="Confirmar prazo — vou cumprir" style="background:#dcfce7;border:1px solid #86efac;color:#15803d;font-size:.65rem;padding:2px 6px;border-radius:5px;cursor:pointer;font-weight:700;">✓</button>
                            </form>
                            <form method="POST" action="<?= module_url('operacional', 'api.php') ?>" onsubmit="return confirm('Descartar esta intimação? (marca como: não precisa cumprir)');">
                                <?= csrf_input() ?>
                                <input type="hidden" name="action" value="confirmar_prazo_publicacao">
                                <input type="hidden" name="pub_id" value="<?= (int)$ui['pub_id'] ?>">
                                <input type="hidden" name="case_id" value="<?= (int)$ui['case_id'] ?>">
                                <input type="hidden" name="novo_status" value="descartado">
                                <input type="hidden" name="_back" value="<?= htmlspecialchars($_backUrl, ENT_QUOTES, 'UTF-8') ?>">
                                <button type="submit" title="Não precisa cumprir — fechar" style="background:#fef2f2;border:1px solid #fca5a5;color:#b91c1c;font-size:.65rem;padding:2px 6px;border-radius:5px;cursor:pointer;font-weight:700;">⊘</button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div style="background:#fff;border:1px solid var(--border);border-left:3px solid #15803d;border-radius:var(--radius-md);padding:.6rem .85rem;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.4rem;">
            <strong style="font-size:.78rem;color:var(--petrol-900);">🏛️ Últimos distribuídos</strong>
            <span style="font-size:.65rem;color:#64748b;">3 mais recentes</span>
        </div>
        <?php if (empty($ultimosDistribuidos)): ?>
            <div style="color:#94a3b8;font-size:.75rem;padding:.3rem 0;">Nenhum ainda.</div>
        <?php else: ?>
            <?php foreach ($ultimosDistribuidos as $ud):
                $agoDist = time() - strtotime($ud['updated_at']);
                if     ($agoDist < 3600)  $agoDistLbl = floor($agoDist/60) . 'min atrás';
                elseif ($agoDist < 86400) $agoDistLbl = floor($agoDist/3600) . 'h atrás';
                elseif ($agoDist < 604800) $agoDistLbl = floor($agoDist/86400) . 'd atrás';
                else                      $agoDistLbl = date('d/m', strtotime($ud['updated_at']));
            ?>
                <a href="<?= module_url('operacional', 'caso_ver.php?id=' . (int)$ud['id']) ?>"
                   style="display:flex;justify-content:space-between;align-items:center;gap:.5rem;padding:4px 0;border-bottom:1px solid #f1f5f9;text-decoration:none;color:inherit;font-size:.75rem;">
                    <div style="flex:1;min-width:0;">
                        <div style="font-weight:600;color:var(--petrol-900);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                            <?= e($ud['title'] ?: 'Processo #' . $ud['id']) ?>
                            <?= $ud['case_number'] ? ' <span style="color:#64748b;font-weight:400;font-size:.66rem;">(' . e(substr($ud['case_number'], 0, 20)) . ')</span>' : '' ?>
                        </div>
                        <div style="font-size:.66rem;color:#64748b;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                            <?= e($ud['client_name'] ?: '—') ?>
                            <?= $ud['responsible_name'] ? ' · ' . e(explode(' ', $ud['responsible_name'])[0]) : '' ?>
                        </div>
                    </div>
                    <span style="font-size:.65rem;color:#15803d;font-weight:600;white-space:nowrap;"><?= $agoDistLbl ?></span>
                </a>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
<style>@media (max-width: 700px) { div[style*="grid-template-columns:1fr 1fr"] { grid-template-columns: 1fr !important; } }</style>

<!-- KPIs -->
<div class="proc-stats">
    <div class="proc-stat">
        <span class="proc-stat-icon">⚖️</span>
        <div><div class="proc-stat-val"><?= $totalJudiciais ?></div><div class="proc-stat-lbl">Processos</div></div>
    </div>
    <div class="proc-stat">
        <span class="proc-stat-icon">⚙️</span>
        <div><div class="proc-stat-val"><?= $ativosJ ?></div><div class="proc-stat-lbl">Ativos</div></div>
    </div>
    <a href="?especial=sem_andamento_30d" class="proc-stat" style="text-decoration:none;<?= $filterEspecial === 'sem_andamento_30d' ? 'border-color:#dc2626;background:#fef2f2;' : '' ?>">
        <span class="proc-stat-icon">⚠️</span>
        <div><div class="proc-stat-val" style="color:#dc2626;"><?= $semAndamento30d ?></div><div class="proc-stat-lbl">Sem andamento 30d+</div></div>
    </a>
    <a href="?especial=audiencia_marcada" class="proc-stat" style="text-decoration:none;<?= $filterEspecial === 'audiencia_marcada' ? 'border-color:#e67e22;background:#fef3c7;' : '' ?>">
        <span class="proc-stat-icon">🎤</span>
        <div><div class="proc-stat-val" style="color:#e67e22;"><?= $comAudiencia ?></div><div class="proc-stat-lbl">Com audiência marcada</div></div>
    </a>
</div>

<!-- Toolbar -->
<div class="proc-toolbar">
    <form method="GET" class="proc-filters">
        <input type="text" name="q" value="<?= e($search) ?>" placeholder="Buscar nº processo, cliente, vara..." style="font-size:.8rem;padding:.4rem .75rem;border:1.5px solid var(--border);border-radius:var(--radius);width:250px;">
        <select name="status" class="proc-filter-sel" onchange="this.form.submit()">
            <option value="">Status</option>
            <?php foreach ($statusLabels as $k => $v): ?>
                <option value="<?= $k ?>" <?= $filterStatus === $k ? 'selected' : '' ?>><?= $v ?></option>
            <?php endforeach; ?>
        </select>
        <select name="type" class="proc-filter-sel" onchange="this.form.submit()">
            <option value="">Tipo de ação</option>
            <?php foreach ($tipos as $t): ?>
                <option value="<?= e($t) ?>" <?= $filterType === $t ? 'selected' : '' ?>><?= e($t) ?></option>
            <?php endforeach; ?>
        </select>
        <?php if (!$isColaborador): ?>
        <select name="user" class="proc-filter-sel" onchange="this.form.submit()">
            <option value="">Responsável</option>
            <?php foreach ($users as $u): ?>
                <option value="<?= $u['id'] ?>" <?= $filterUser == $u['id'] ? 'selected' : '' ?>><?= e(explode(' ', $u['name'])[0]) ?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <select name="especial" class="proc-filter-sel" onchange="this.form.submit()">
            <option value="">Filtro especial</option>
            <option value="sem_andamento_30d" <?= $filterEspecial === 'sem_andamento_30d' ? 'selected' : '' ?>>Sem andamento 30d+</option>
            <option value="audiencia_marcada" <?= $filterEspecial === 'audiencia_marcada' ? 'selected' : '' ?>>Com audiência marcada</option>
            <option value="prazo_proximo" <?= $filterEspecial === 'prazo_proximo' ? 'selected' : '' ?>>Prazo nos próximos 15d</option>
            <option value="segredo_justica" <?= $filterEspecial === 'segredo_justica' ? 'selected' : '' ?>>Segredo de justiça</option>
            <option value="doc_faltante" <?= $filterEspecial === 'doc_faltante' ? 'selected' : '' ?>>Doc faltante</option>
        </select>
        <select name="vinculo" class="proc-filter-sel" onchange="this.form.submit()">
            <option value="">Vínculos</option>
            <option value="principais" <?= $filterVinculo === 'principais' ? 'selected' : '' ?>>Só principais</option>
            <option value="incidentais" <?= $filterVinculo === 'incidentais' ? 'selected' : '' ?>>Só incidentais</option>
            <option value="recursos" <?= $filterVinculo === 'recursos' ? 'selected' : '' ?>>Só recursos</option>
        </select>
        <select name="ordem" class="proc-filter-sel" onchange="this.form.submit()">
            <option value="alfa" <?= $orderBy === 'alfa' ? 'selected' : '' ?>>A → Z</option>
            <option value="recentes" <?= $orderBy === 'recentes' ? 'selected' : '' ?>>Mais recentes</option>
            <option value="atualizados" <?= $orderBy === 'atualizados' ? 'selected' : '' ?>>Último andamento</option>
            <option value="prazo" <?= $orderBy === 'prazo' ? 'selected' : '' ?>>Por prazo</option>
        </select>
        <button type="submit" class="btn btn-outline btn-sm">🔍</button>
        <?php if ($filterStatus || $filterType || $filterUser || $search || $filterVinculo || $filterEspecial || $orderBy !== 'alfa'): ?>
            <a href="<?= module_url('processos') ?>" class="btn btn-outline btn-sm">Limpar</a>
        <?php endif; ?>
    </form>
    <div style="display:flex;gap:.5rem;">
        <?php
        // Toggle arquivados: preserva todos os outros filtros na URL
        $qsSemArq = $_GET; unset($qsSemArq['ver_arquivados']);
        $qsComArq = $qsSemArq; $qsComArq['ver_arquivados'] = '1';
        ?>
        <?php if ($verArquivados): ?>
            <a href="?<?= http_build_query($qsSemArq) ?>" class="btn btn-outline btn-sm" style="border-color:#94a3b8;color:#475569;" title="Voltar a mostrar só em andamento">👁️ Só ativos</a>
        <?php else: ?>
            <a href="?<?= http_build_query($qsComArq) ?>" class="btn btn-outline btn-sm" style="border-color:#94a3b8;color:#475569;" title="Incluir processos arquivados/cancelados/concluídos na lista">📦 Ver arquivados</a>
        <?php endif; ?>
        <?php if (has_min_role('gestao')): ?>
            <a href="<?= module_url('crm', 'importar_processos.php') ?>" class="btn btn-outline btn-sm">Importar CSV</a>
        <?php endif; ?>
        <a href="<?= module_url('operacional', 'caso_novo.php') ?>" class="btn btn-primary btn-sm">+ Novo Processo</a>
    </div>
</div>

<!-- Tabela -->
<div class="card" style="overflow-x:auto;">
    <?php if (empty($processos)): ?>
        <div class="card-body" style="text-align:center;padding:3rem;">
            <div style="font-size:2rem;margin-bottom:.5rem;">⚖️</div>
            <h3>Nenhum processo judicial</h3>
            <p style="color:var(--text-muted);font-size:.85rem;">Importe do LegalOne ou cadastre pelo Operacional com número de processo.</p>
        </div>
    <?php else: ?>
        <table class="proc-table">
            <colgroup>
                <col style="width:13%;">  <!-- Nº Processo (mais largo pra CNJ não vazar) -->
                <col style="width:11%;">  <!-- Cliente -->
                <col style="width:14%;">  <!-- Título -->
                <col style="width:10%;">  <!-- Tipo -->
                <col style="width:11%;">  <!-- Vara/Tribunal -->
                <col style="width:9%;">   <!-- Status -->
                <col style="width:8%;">   <!-- Prioridade -->
                <col style="width:7%;">   <!-- Responsável -->
                <col style="width:8%;">   <!-- Último Andamento -->
                <col style="width:4.5%;"> <!-- Audiência -->
                <col style="width:4.5%;"> <!-- Prazo -->
            </colgroup>
            <thead><tr>
                <th><?= sort_link('numero', $orderBy, $orderDir, 'Nº Processo') ?></th>
                <th><?= sort_link('cliente', $orderBy, $orderDir, 'Cliente') ?></th>
                <th><?= sort_link('titulo', $orderBy, $orderDir, 'Título') ?></th>
                <th><?= sort_link('tipo', $orderBy, $orderDir, 'Tipo') ?></th>
                <th><?= sort_link('vara', $orderBy, $orderDir, 'Vara / Tribunal') ?></th>
                <th><?= sort_link('status', $orderBy, $orderDir, 'Status') ?></th>
                <th><?= sort_link('prioridade', $orderBy, $orderDir, 'Prioridade') ?></th>
                <th><?= sort_link('responsavel', $orderBy, $orderDir, 'Resp.') ?></th>
                <th><?= sort_link('ultimo', $orderBy, $orderDir, 'Último And.') ?></th>
                <th>Aud.</th>
                <th><?= sort_link('prazo', $orderBy, $orderDir, 'Prazo') ?></th>
            </tr></thead>
            <tbody>
                <?php foreach ($processos as $p):
                    $isOverdue = $p['deadline'] && $p['deadline'] < date('Y-m-d');
                    $isRecursoRow = (!empty($p['is_incidental']) && isset($p['tipo_vinculo']) && $p['tipo_vinculo'] === 'recurso');
                    $isIncidentalRow = (!empty($p['is_incidental']) && !$isRecursoRow);
                    $isPrincipalRow = in_array($p['id'], $principaisIds);
                    $vincClass = $isRecursoRow ? 'vinculo-recurso' : ($isIncidentalRow ? 'vinculo-incidental' : ($isPrincipalRow ? 'vinculo-principal' : ''));
                ?>
                <tr class="<?= $vincClass ?>">
                    <td><a href="<?= module_url('operacional', 'caso_ver.php?id=' . $p['id']) ?>" class="proc-number" style="text-decoration:none;color:inherit;cursor:pointer;" title="Abrir pasta do processo"><?= e(format_cnj($p['case_number'])) ?></a></td>
                    <td>
                        <?php if ($p['client_id']): ?>
                            <a href="<?= module_url('crm', 'cliente_ver.php?id=' . $p['client_id']) ?>" class="client-link"><?= e($p['client_name']) ?></a>
                            <?php if (!empty($p['outros_clientes'])): ?>
                                <div style="font-size:.7rem;color:var(--text-muted);margin-top:2px;"><?= e($p['outros_clientes']) ?></div>
                            <?php endif; ?>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td>
                        <a href="<?= module_url('operacional', 'caso_ver.php?id=' . $p['id']) ?>" class="case-link"><?= e($p['title'] ? $p['title'] : 'Processo #' . $p['id']) ?></a>
                        <?php if ($isRecursoRow): ?>
                            <span class="proc-badge-vinc" style="background:#d97706;">📜 Recurso</span>
                        <?php elseif ($isIncidentalRow): ?>
                            <span class="proc-badge-vinc" style="background:#6366f1;">📎 Incidental</span>
                        <?php elseif ($isPrincipalRow): ?>
                            <span class="proc-badge-vinc" style="background:#052228;">⚖️ Principal</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:.78rem;"><?= e($p['case_type'] && $p['case_type'] !== 'outro' ? $p['case_type'] : '—') ?></td>
                    <td style="font-size:.78rem;"><?= e($p['court'] ? $p['court'] : '—') ?></td>
                    <td><span class="badge badge-<?= isset($statusBadge[$p['status']]) ? $statusBadge[$p['status']] : 'gestao' ?>"><?= isset($statusLabels[$p['status']]) ? $statusLabels[$p['status']] : $p['status'] ?></span></td>
                    <td>
                        <select onchange="mudarPrioridade(<?= $p['id'] ?>, this.value, this)" style="font-size:.72rem;padding:2px 4px;border:1px solid #e5e7eb;border-radius:4px;font-weight:600;
                            <?php
                            $cores = array('urgente'=>'background:#fef2f2;color:#dc2626;','alta'=>'background:#fffbeb;color:#d97706;','normal'=>'background:#f0fdf4;color:#059669;','baixa'=>'background:#f8fafc;color:#64748b;');
                            echo isset($cores[$p['priority']]) ? $cores[$p['priority']] : '';
                            ?>">
                            <option value="urgente" <?= $p['priority']==='urgente'?'selected':'' ?>>URGENTE</option>
                            <option value="alta" <?= $p['priority']==='alta'?'selected':'' ?>>ALTA</option>
                            <option value="normal" <?= $p['priority']==='normal'?'selected':'' ?>>NORMAL</option>
                            <option value="baixa" <?= $p['priority']==='baixa'?'selected':'' ?>>BAIXA</option>
                        </select>
                    </td>
                    <td style="font-size:.78rem;"><?= e($p['responsible_name'] ? explode(' ', $p['responsible_name'])[0] : '—') ?></td>
                    <td style="font-size:.75rem;">
                        <?php if ($p['ultimo_andamento']):
                            $diasSemAnd = (int)((time() - strtotime($p['ultimo_andamento'])) / 86400);
                            $corAnd = $diasSemAnd > 30 ? '#dc2626' : ($diasSemAnd > 15 ? '#d97706' : '#059669');
                        ?>
                            <span style="color:<?= $corAnd ?>;font-weight:<?= $diasSemAnd > 30 ? '700' : '400' ?>;"><?= date('d/m', strtotime($p['ultimo_andamento'])) ?> (<?= $diasSemAnd ?>d)</span>
                        <?php else: ?>
                            <span style="color:#dc2626;font-weight:700;">Sem andamento</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:.75rem;">
                        <?php if ($p['prox_audiencia']): ?>
                            <span style="color:#e67e22;font-weight:600;"><?= date('d/m/Y', strtotime($p['prox_audiencia'])) ?></span>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td style="font-size:.78rem;<?= $isOverdue ? 'color:#ef4444;font-weight:700;' : '' ?>"><?= $p['deadline'] ? date('d/m/Y', strtotime($p['deadline'])) : '—' ?><?= $isOverdue ? ' ⚠️' : '' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php if ($totalPaginas > 1): ?>
<!-- Paginação -->
<div style="display:flex;align-items:center;justify-content:space-between;margin-top:1rem;padding:.5rem 0;flex-wrap:wrap;gap:.5rem;">
    <div style="font-size:.78rem;color:var(--text-muted);">
        Mostrando <strong><?= ($offset + 1) ?>–<?= min($offset + $perPage, $totalProcessos) ?></strong> de <strong><?= $totalProcessos ?></strong> processos
    </div>
    <div style="display:flex;gap:4px;flex-wrap:wrap;">
        <?php
        $qsBase = $_GET; unset($qsBase['page']);
        $buildUrl = function($p) use ($qsBase) {
            $qs = array_merge($qsBase, array('page' => $p));
            return '?' . http_build_query($qs);
        };

        // Primeira / Anterior
        if ($pageNum > 1): ?>
            <a href="<?= $buildUrl(1) ?>" style="padding:5px 10px;border:1px solid var(--border);border-radius:6px;font-size:.75rem;text-decoration:none;color:var(--text);background:#fff;">« Primeira</a>
            <a href="<?= $buildUrl($pageNum - 1) ?>" style="padding:5px 10px;border:1px solid var(--border);border-radius:6px;font-size:.75rem;text-decoration:none;color:var(--text);background:#fff;">‹ Anterior</a>
        <?php endif;

        // Números (janela deslizante)
        $inicio = max(1, $pageNum - 2);
        $fim = min($totalPaginas, $pageNum + 2);
        if ($inicio > 1) echo '<span style="padding:5px 6px;color:var(--text-muted);font-size:.75rem;">...</span>';
        for ($p = $inicio; $p <= $fim; $p++):
            $isAtual = $p === $pageNum;
        ?>
            <a href="<?= $buildUrl($p) ?>" style="padding:5px 10px;border:1px solid <?= $isAtual ? 'var(--petrol-900)' : 'var(--border)' ?>;border-radius:6px;font-size:.75rem;text-decoration:none;font-weight:<?= $isAtual ? '700' : '400' ?>;color:<?= $isAtual ? '#fff' : 'var(--text)' ?>;background:<?= $isAtual ? 'var(--petrol-900)' : '#fff' ?>;min-width:32px;text-align:center;">
                <?= $p ?>
            </a>
        <?php endfor;
        if ($fim < $totalPaginas) echo '<span style="padding:5px 6px;color:var(--text-muted);font-size:.75rem;">...</span>';

        // Próxima / Última
        if ($pageNum < $totalPaginas): ?>
            <a href="<?= $buildUrl($pageNum + 1) ?>" style="padding:5px 10px;border:1px solid var(--border);border-radius:6px;font-size:.75rem;text-decoration:none;color:var(--text);background:#fff;">Próxima ›</a>
            <a href="<?= $buildUrl($totalPaginas) ?>" style="padding:5px 10px;border:1px solid var(--border);border-radius:6px;font-size:.75rem;text-decoration:none;color:var(--text);background:#fff;">Última »</a>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php $extraJs = <<<'JSEOF'
function mudarPrioridade(caseId, valor, sel) {
    var cores = {urgente:'background:#fef2f2;color:#dc2626;',alta:'background:#fffbeb;color:#d97706;',normal:'background:#f0fdf4;color:#059669;',baixa:'background:#f8fafc;color:#64748b;'};
    sel.style.cssText = 'font-size:.72rem;padding:2px 4px;border:1px solid #e5e7eb;border-radius:4px;font-weight:600;' + (cores[valor] || '');

    var xhr = new XMLHttpRequest();
    xhr.open('POST', '/conecta/modules/shared/card_actions.php');
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onload = function() {
        try {
            var r = JSON.parse(xhr.responseText);
            if (r.error) { alert(r.error); return; }
            sel.style.outline = '2px solid #059669';
            setTimeout(function() { sel.style.outline = ''; }, 1500);
        } catch(e) { alert('Erro ao salvar'); }
    };
    xhr.send('action=update_field&entity=case&entity_id=' + caseId + '&field=priority&value=' + valor);
}
JSEOF;
?>
<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
