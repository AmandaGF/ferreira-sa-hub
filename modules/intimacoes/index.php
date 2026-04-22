<?php
/**
 * Central de Intimações — lista unificada de todas as publicações DJEN
 * (case_publicacoes já importadas + djen_pending ainda sem pasta).
 *
 * Filtros: período, OAB, tipo, status, busca livre (conteúdo/nome/CNJ).
 * Permite cumprir/descartar prazo e vincular/criar pasta pra órfãs.
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
if (!has_min_role('operacional')) { flash_set('error', 'Sem permissão.'); redirect(url('modules/dashboard/')); }

$pdo = db();
$pageTitle = 'Central de Intimações';

// Self-heal (tabelas e colunas críticas)
try { $pdo->exec("CREATE TABLE IF NOT EXISTS djen_pending (id INT AUTO_INCREMENT PRIMARY KEY, numero_processo VARCHAR(40) NOT NULL, data_disp DATE NULL, tipo_comunicacao VARCHAR(30) NULL, orgao VARCHAR(200) NULL, comarca VARCHAR(100) NULL, partes TEXT NULL, advogados TEXT NULL, conteudo TEXT NOT NULL, resumo TEXT NULL, orientacao TEXT NULL, segredo TINYINT(1) DEFAULT 0, status ENUM('pendente','importado','descartado') DEFAULT 'pendente', case_id INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP, INDEX idx_numero (numero_processo), INDEX idx_status (status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE case_publicacoes ADD COLUMN resumo_ia TEXT NULL"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE case_publicacoes ADD COLUMN orientacao_ia TEXT NULL"); } catch (Exception $e) {}

// Filtros
$fDataIni   = trim($_GET['data_ini'] ?? '');
$fDataFim   = trim($_GET['data_fim'] ?? '');
$fOab       = trim($_GET['oab'] ?? '');
$fTipo      = trim($_GET['tipo'] ?? '');
$fStatus    = trim($_GET['status'] ?? 'todos'); // pendente|confirmado|descartado|orfa|todos
$fBusca     = trim($_GET['q'] ?? '');
$pageNum    = max(1, (int)($_GET['page'] ?? 1));
$perPage    = 25;

$likeB = $fBusca ? '%' . $fBusca . '%' : null;
$likeOab = $fOab ? '%' . preg_replace('/\D/', '', $fOab) . '%' : null;

$tipoLbls = array(
    'intimacao'=>'Intimação','citacao'=>'Citação','despacho'=>'Despacho',
    'decisao'=>'Decisão','sentenca'=>'Sentença','acordao'=>'Acórdão',
    'edital'=>'Edital','outro'=>'Outro',
);

// Monta UNION de case_publicacoes (já importadas) + djen_pending (órfãs)
// Campos unificados: id, origem, data_disp, tipo, cnj, orgao, resumo, orientacao,
//                    conteudo_preview, case_id, client_id, cliente_nome, case_title,
//                    status_prazo, criado_em, advogados_raw
// NOTA: COLLATE utf8mb4_unicode_ci forçado em todas as colunas de texto
// pra evitar 'Illegal mix of collations' no UNION (case_publicacoes e
// djen_pending foram criadas com collations diferentes — unicode_ci vs general_ci)
$COL = "COLLATE utf8mb4_unicode_ci";
$sqlCP = "
  SELECT
    cp.id AS id,
    CAST('pub' AS CHAR) $COL AS origem,
    cp.data_disponibilizacao AS data_disp,
    cp.tipo_publicacao $COL AS tipo,
    cs.case_number $COL AS cnj,
    cp.tribunal $COL AS orgao,
    cp.resumo_ia $COL AS resumo,
    cp.orientacao_ia $COL AS orientacao,
    LEFT(cp.conteudo, 300) $COL AS conteudo_preview,
    cp.conteudo $COL AS conteudo_full,
    cp.case_id AS case_id,
    cs.client_id AS client_id,
    cl.name $COL AS cliente_nome,
    cs.title $COL AS case_title,
    cp.status_prazo $COL AS status_prazo,
    cp.data_prazo_fim AS data_prazo_fim,
    cp.created_at AS criado_em,
    CAST('' AS CHAR) $COL AS advogados_raw,
    CAST('' AS CHAR) $COL AS partes_raw
  FROM case_publicacoes cp
  LEFT JOIN cases cs ON cs.id = cp.case_id
  LEFT JOIN clients cl ON cl.id = cs.client_id
";
$sqlDP = "
  SELECT
    dp.id AS id,
    CAST('pend' AS CHAR) $COL AS origem,
    dp.data_disp AS data_disp,
    dp.tipo_comunicacao $COL AS tipo,
    dp.numero_processo $COL AS cnj,
    dp.orgao $COL AS orgao,
    dp.resumo $COL AS resumo,
    dp.orientacao $COL AS orientacao,
    LEFT(dp.conteudo, 300) $COL AS conteudo_preview,
    dp.conteudo $COL AS conteudo_full,
    dp.case_id AS case_id,
    CAST(NULL AS UNSIGNED) AS client_id,
    CAST(NULL AS CHAR) $COL AS cliente_nome,
    CAST(NULL AS CHAR) $COL AS case_title,
    CAST('orfa' AS CHAR) $COL AS status_prazo,
    CAST(NULL AS DATE) AS data_prazo_fim,
    dp.created_at AS criado_em,
    dp.advogados $COL AS advogados_raw,
    dp.partes $COL AS partes_raw
  FROM djen_pending dp
  WHERE dp.status = 'pendente'
";

// Junta num subquery wrapper pra poder filtrar por conditions unificadas
$wrapped = "($sqlCP) UNION ALL ($sqlDP)";
$where = array();
$params = array();

if ($fDataIni && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fDataIni)) {
    $where[] = 'x.data_disp >= ?'; $params[] = $fDataIni;
}
if ($fDataFim && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fDataFim)) {
    $where[] = 'x.data_disp <= ?'; $params[] = $fDataFim;
}
if ($fTipo && isset($tipoLbls[$fTipo])) {
    $where[] = 'x.tipo = ?'; $params[] = $fTipo;
}
if ($fStatus && $fStatus !== 'todos') {
    if ($fStatus === 'orfa')       $where[] = "x.origem = 'pend'";
    else                            $where[] = "x.origem = 'pub' AND x.status_prazo = ?";
    if ($fStatus !== 'orfa')        $params[] = $fStatus;
}
if ($likeB) {
    $where[] = '(x.cnj LIKE ? OR x.conteudo_full LIKE ? OR x.resumo LIKE ? OR x.cliente_nome LIKE ? OR x.case_title LIKE ? OR x.partes_raw LIKE ?)';
    $params[] = $likeB; $params[] = $likeB; $params[] = $likeB; $params[] = $likeB; $params[] = $likeB; $params[] = $likeB;
}
if ($likeOab) {
    $where[] = '(x.conteudo_full LIKE ? OR x.advogados_raw LIKE ?)';
    $params[] = $likeOab; $params[] = $likeOab;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Total
try {
    $stmtC = $pdo->prepare("SELECT COUNT(*) FROM ($wrapped) x $whereSql");
    $stmtC->execute($params);
    $total = (int)$stmtC->fetchColumn();
} catch (Exception $e) { $total = 0; }

$totalPag = max(1, (int)ceil($total / $perPage));
if ($pageNum > $totalPag) $pageNum = $totalPag;
$offset = ($pageNum - 1) * $perPage;

$items = array();
try {
    $sql = "SELECT x.* FROM ($wrapped) x $whereSql ORDER BY x.data_disp DESC, x.criado_em DESC LIMIT $perPage OFFSET $offset";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll();
} catch (Exception $e) {
    flash_set('error', 'Erro: ' . $e->getMessage());
}

// Contadores rápidos por status (pra chips)
$contadores = array('pendente'=>0,'confirmado'=>0,'descartado'=>0,'orfa'=>0);
try {
    $row = $pdo->query("SELECT status_prazo, COUNT(*) qt FROM case_publicacoes GROUP BY status_prazo")->fetchAll();
    foreach ($row as $r) if (isset($contadores[$r['status_prazo']])) $contadores[$r['status_prazo']] = (int)$r['qt'];
    $contadores['orfa'] = (int)$pdo->query("SELECT COUNT(*) FROM djen_pending WHERE status = 'pendente'")->fetchColumn();
} catch (Exception $e) {}

require_once APP_ROOT . '/templates/layout_start.php';
?>
<style>
.ci-wrap { max-width:1400px; margin:0 auto; }
.ci-head { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:.8rem; margin-bottom:.8rem; }
.ci-head h2 { margin:0; color:var(--petrol-900); font-size:1.2rem; }

.ci-chips { display:flex; flex-wrap:wrap; gap:.4rem; margin-bottom:.8rem; }
.ci-chip { font-size:.75rem; padding:4px 12px; border-radius:14px; border:1.5px solid var(--border); background:#fff; text-decoration:none; color:#052228; font-weight:600; }
.ci-chip.ativo { background:#052228; color:#fff; border-color:#052228; }
.ci-chip.pendente { border-color:#fbbf24; color:#b45309; }
.ci-chip.pendente.ativo { background:#fbbf24; border-color:#fbbf24; color:#fff; }
.ci-chip.orfa { border-color:#6366f1; color:#4338ca; }
.ci-chip.orfa.ativo { background:#6366f1; border-color:#6366f1; color:#fff; }

.ci-filtros { background:var(--bg-card); border:1px solid var(--border); border-radius:10px; padding:.75rem 1rem; margin-bottom:.8rem; }
.ci-filtros form { display:flex; gap:.5rem; flex-wrap:wrap; align-items:flex-end; }
.ci-filtros label { display:flex; flex-direction:column; gap:2px; font-size:.7rem; color:var(--text-muted); font-weight:700; text-transform:uppercase; }
.ci-filtros input, .ci-filtros select { padding:5px 8px; border:1.5px solid var(--border); border-radius:6px; font-size:.82rem; }

.ci-table { width:100%; border-collapse:collapse; background:var(--bg-card); border-radius:10px; overflow:hidden; font-size:.8rem; }
.ci-table th { background:var(--petrol-900); color:#fff; padding:.5rem .6rem; text-align:left; font-size:.68rem; text-transform:uppercase; letter-spacing:.4px; }
.ci-table td { padding:.55rem .6rem; border-bottom:1px solid var(--border); vertical-align:top; }
.ci-table tr.orfa { background:#eef2ff; }
.ci-table tr.pendente { background:#fffbeb; }
.ci-table tr.descartado { opacity:.6; }
.ci-badge { display:inline-block; padding:1px 7px; border-radius:10px; font-size:.6rem; font-weight:700; text-transform:uppercase; letter-spacing:.3px; }
.ci-badge.intimacao,.ci-badge.citacao,.ci-badge.sentenca,.ci-badge.decisao { background:#eef2ff; color:#4338ca; }
.ci-badge.despacho,.ci-badge.acordao,.ci-badge.edital,.ci-badge.outro { background:#f1f5f9; color:#475569; }
.ci-badge.pendente { background:#fef3c7; color:#b45309; }
.ci-badge.confirmado { background:#dcfce7; color:#15803d; }
.ci-badge.descartado { background:#fee2e2; color:#b91c1c; }
.ci-badge.orfa { background:#eef2ff; color:#4338ca; }

.ci-resumo { font-size:.72rem; color:#475569; margin-top:3px; line-height:1.35; }
.ci-preview { font-size:.68rem; color:#94a3b8; margin-top:2px; line-height:1.3; max-width:500px; }
.ci-acoes { display:flex; flex-direction:column; gap:3px; }
.ci-acoes button, .ci-acoes a { font-size:.65rem; padding:2px 6px; border-radius:4px; cursor:pointer; font-weight:600; text-decoration:none; display:inline-block; text-align:center; border:1px solid transparent; }
.ci-btn-ok { background:#dcfce7; color:#15803d; border-color:#86efac; }
.ci-btn-ig { background:#fef2f2; color:#b91c1c; border-color:#fca5a5; }
.ci-btn-ver { background:#052228; color:#fff; }
.ci-btn-vincular { background:#B87333; color:#fff; }
</style>

<div class="ci-wrap">
    <div class="ci-head">
        <div>
            <h2>📢 Central de Intimações</h2>
            <p style="font-size:.78rem;color:var(--text-muted);margin:.2rem 0 0;">Todas as publicações do DJEN — importadas e aguardando vinculação. Total: <strong><?= $total ?></strong></p>
        </div>
        <div style="display:flex;gap:.4rem;">
            <a href="<?= module_url('admin','claudin_dashboard.php') ?>" class="btn btn-outline btn-sm">🤖 Claudin</a>
            <a href="<?= module_url('admin','djen_importar.php') ?>" class="btn btn-outline btn-sm">📥 Colar DJen</a>
        </div>
    </div>

    <!-- Chips de status -->
    <div class="ci-chips">
        <?php
        $statusChips = array(
            'todos' => array('label'=>'Todos','qt'=>$contadores['pendente']+$contadores['confirmado']+$contadores['descartado']+$contadores['orfa']),
            'pendente' => array('label'=>'⏳ Pendentes','qt'=>$contadores['pendente']),
            'orfa' => array('label'=>'🔗 Sem pasta','qt'=>$contadores['orfa']),
            'confirmado' => array('label'=>'✓ Cumpridas','qt'=>$contadores['confirmado']),
            'descartado' => array('label'=>'⊘ Descartadas','qt'=>$contadores['descartado']),
        );
        foreach ($statusChips as $k=>$c):
            $qs = $_GET; $qs['status'] = $k; unset($qs['page']);
            $ativo = $fStatus === $k ? 'ativo' : '';
        ?>
            <a href="?<?= http_build_query($qs) ?>" class="ci-chip <?= $k ?> <?= $ativo ?>">
                <?= e($c['label']) ?> <span style="opacity:.75;">(<?= (int)$c['qt'] ?>)</span>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- Filtros -->
    <div class="ci-filtros">
        <form method="GET">
            <input type="hidden" name="status" value="<?= e($fStatus) ?>">
            <label>De
                <input type="date" name="data_ini" value="<?= e($fDataIni) ?>">
            </label>
            <label>Até
                <input type="date" name="data_fim" value="<?= e($fDataFim) ?>">
            </label>
            <label>Tipo
                <select name="tipo">
                    <option value="">Todos</option>
                    <?php foreach ($tipoLbls as $k=>$v): ?>
                        <option value="<?= $k ?>" <?= $fTipo===$k?'selected':'' ?>><?= $v ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>OAB (número)
                <input type="text" name="oab" value="<?= e($fOab) ?>" placeholder="Ex: 163260">
            </label>
            <label style="flex:1;min-width:220px;">Busca livre (CNJ, cliente, nome das partes, texto)
                <input type="text" name="q" value="<?= e($fBusca) ?>" placeholder="Digite nome, CNJ, palavra...">
            </label>
            <button type="submit" class="btn btn-primary btn-sm">🔍 Filtrar</button>
            <a href="<?= module_url('intimacoes') ?>" class="btn btn-outline btn-sm">Limpar</a>
        </form>
    </div>

    <!-- Tabela -->
    <div style="overflow-x:auto;">
        <table class="ci-table">
            <thead>
                <tr>
                    <th style="width:85px;">Data</th>
                    <th style="width:75px;">Tipo</th>
                    <th style="width:145px;">CNJ</th>
                    <th>Resumo / Cliente / Processo</th>
                    <th style="width:90px;">Status</th>
                    <th style="width:85px;">Prazo fatal</th>
                    <th style="width:95px;">Ação</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($items)): ?>
                    <tr><td colspan="7" style="text-align:center;padding:2rem;color:#94a3b8;">Nenhuma intimação encontrada com esses filtros.</td></tr>
                <?php else: foreach ($items as $it):
                    $orfa = $it['origem'] === 'pend';
                    $trCls = $orfa ? 'orfa' : ($it['status_prazo'] === 'pendente' ? 'pendente' : ($it['status_prazo'] === 'descartado' ? 'descartado' : ''));
                    $tipoLbl = isset($tipoLbls[$it['tipo']]) ? $tipoLbls[$it['tipo']] : ucfirst((string)$it['tipo']);
                ?>
                <tr class="<?= $trCls ?>">
                    <td style="font-size:.72rem;font-family:ui-monospace,monospace;"><?= $it['data_disp'] ? date('d/m/Y', strtotime($it['data_disp'])) : '—' ?></td>
                    <td><span class="ci-badge <?= e($it['tipo']) ?>"><?= e($tipoLbl) ?></span></td>
                    <td style="font-family:ui-monospace,monospace;font-size:.7rem;"><?= e($it['cnj']) ?></td>
                    <td>
                        <?php if ($it['cliente_nome']): ?>
                            <div style="font-weight:700;color:var(--petrol-900);"><?= e($it['cliente_nome']) ?></div>
                        <?php elseif ($it['case_title']): ?>
                            <div style="font-weight:700;color:var(--petrol-900);"><?= e($it['case_title']) ?></div>
                        <?php else: ?>
                            <div style="font-weight:600;color:#4338ca;">⚠️ Sem vinculação</div>
                        <?php endif; ?>
                        <?php if ($it['case_title'] && $it['cliente_nome']): ?>
                            <div style="font-size:.68rem;color:var(--text-muted);"><?= e($it['case_title']) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($it['resumo'])): ?>
                            <div class="ci-resumo">📝 <?= e($it['resumo']) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($it['orientacao'])): ?>
                            <div class="ci-resumo" style="color:#B87333;font-weight:600;">⚖️ <?= e($it['orientacao']) ?></div>
                        <?php endif; ?>
                        <?php if (empty($it['resumo'])): ?>
                            <div class="ci-preview"><?= e(mb_substr($it['conteudo_preview'] ?? '', 0, 180, 'UTF-8')) ?>...</div>
                        <?php endif; ?>
                        <?php if ($it['orgao']): ?>
                            <div style="font-size:.66rem;color:#64748b;margin-top:3px;">🏛️ <?= e($it['orgao']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($orfa): ?>
                            <span class="ci-badge orfa">Sem pasta</span>
                        <?php else: ?>
                            <span class="ci-badge <?= e($it['status_prazo']) ?>"><?= e(ucfirst($it['status_prazo'])) ?></span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:.72rem;">
                        <?= $it['data_prazo_fim'] ? '<strong style="color:#b91c1c;">' . date('d/m/Y', strtotime($it['data_prazo_fim'])) . '</strong>' : '—' ?>
                    </td>
                    <td>
                        <div class="ci-acoes">
                            <?php if ($orfa): ?>
                                <a href="<?= module_url('admin', 'djen_importar.php') ?>" class="ci-btn-vincular" title="Vincular a pasta ou criar nova">🔗 Vincular</a>
                            <?php else: ?>
                                <?php if ($it['case_id']): ?>
                                    <a href="<?= module_url('operacional', 'caso_ver.php?id=' . (int)$it['case_id']) ?>" class="ci-btn-ver">⚖️ Abrir pasta</a>
                                <?php endif; ?>
                                <?php if ($it['status_prazo'] === 'pendente' && (has_min_role('operacional') || has_min_role('gestao'))): ?>
                                    <form method="POST" action="<?= module_url('operacional', 'api.php') ?>" onsubmit="return confirm('Cumprir prazo desta intimação?');" style="margin:0;">
                                        <?= csrf_input() ?>
                                        <input type="hidden" name="action" value="confirmar_prazo_publicacao">
                                        <input type="hidden" name="pub_id" value="<?= (int)$it['id'] ?>">
                                        <input type="hidden" name="case_id" value="<?= (int)$it['case_id'] ?>">
                                        <input type="hidden" name="novo_status" value="confirmado">
                                        <input type="hidden" name="_back" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? module_url('intimacoes'), ENT_QUOTES, 'UTF-8') ?>">
                                        <button type="submit" class="ci-btn-ok" style="width:100%;">✓ Cumprir</button>
                                    </form>
                                    <form method="POST" action="<?= module_url('operacional', 'api.php') ?>" onsubmit="return confirm('Descartar esta intimação? Não precisa fazer nada?');" style="margin:0;">
                                        <?= csrf_input() ?>
                                        <input type="hidden" name="action" value="confirmar_prazo_publicacao">
                                        <input type="hidden" name="pub_id" value="<?= (int)$it['id'] ?>">
                                        <input type="hidden" name="case_id" value="<?= (int)$it['case_id'] ?>">
                                        <input type="hidden" name="novo_status" value="descartado">
                                        <input type="hidden" name="_back" value="<?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? module_url('intimacoes'), ENT_QUOTES, 'UTF-8') ?>">
                                        <button type="submit" class="ci-btn-ig" style="width:100%;">⊘ Dispensar</button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Paginação -->
    <?php if ($totalPag > 1): ?>
    <div style="display:flex;justify-content:center;gap:4px;margin-top:1rem;flex-wrap:wrap;">
        <?php
        $qsBase = $_GET;
        for ($p = 1; $p <= $totalPag; $p++):
            if ($p > 1 && $p < $totalPag && abs($p - $pageNum) > 2 && $p !== 1 && $p !== $totalPag) continue;
            $qsBase['page'] = $p;
            $ativo = $p === $pageNum ? 'background:#052228;color:#fff;' : 'background:#fff;color:#052228;';
        ?>
            <a href="?<?= http_build_query($qsBase) ?>" style="padding:4px 10px;border-radius:5px;text-decoration:none;font-size:.75rem;font-weight:600;border:1px solid var(--border);<?= $ativo ?>"><?= $p ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
