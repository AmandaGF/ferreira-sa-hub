<?php
/**
 * Ferreira & Sá Hub — Prazos Processuais
 * Alertas: 10d, 5d, 1d antes do prazo fatal
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
if (!has_role('admin','gestao','operacional')) { redirect(url('modules/dashboard/')); }

$pageTitle = 'Prazos Processuais';
$pdo = db();

// Ações
if ($_SERVER['REQUEST_METHOD'] === 'POST' && validate_csrf()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $clientId = (int)($_POST['client_id'] ?? 0) ?: null;
        $caseId = (int)($_POST['case_id'] ?? 0) ?: null;
        $numProcesso = clean_str($_POST['numero_processo'] ?? '', 50);
        $descricao = clean_str($_POST['descricao_acao'] ?? '', 250);
        $prazoFatal = $_POST['prazo_fatal'] ?? '';

        if ($descricao && $prazoFatal) {
            $pdo->prepare("INSERT INTO prazos_processuais (client_id, case_id, numero_processo, descricao_acao, prazo_fatal, usuario_id) VALUES (?,?,?,?,?,?)")
                ->execute(array($clientId, $caseId, $numProcesso ?: null, $descricao, $prazoFatal, current_user_id()));

            // Gerar tarefa automática 3 dias antes do prazo fatal
            if ($caseId) {
                $prazoSeguranca = date('Y-m-d', strtotime($prazoFatal . ' -3 days'));
                // Não criar se prazo de segurança já passou
                if ($prazoSeguranca >= date('Y-m-d')) {
                    try {
                        // Buscar responsável do caso
                        $stmtR = $pdo->prepare("SELECT responsible_user_id FROM cases WHERE id = ?");
                        $stmtR->execute(array($caseId));
                        $respId = (int)$stmtR->fetchColumn() ?: null;

                        $pdo->prepare(
                            "INSERT INTO case_tasks (case_id, title, tipo, status, due_date, prazo_alerta, assigned_to, prioridade, sort_order) VALUES (?,?,?,?,?,?,?,?,?)"
                        )->execute(array(
                            $caseId,
                            '⏰ PRAZO: ' . $descricao . ' (fatal ' . date('d/m/Y', strtotime($prazoFatal)) . ')',
                            'prazo',
                            'a_fazer',
                            $prazoSeguranca,
                            $prazoSeguranca,
                            $respId,
                            'urgente',
                            0
                        ));
                    } catch (Exception $e) { /* silenciar se tabela incompatível */ }
                }
            }

            flash_set('success', 'Prazo cadastrado!' . ($caseId ? ' Tarefa criada automaticamente para 3 dias antes.' : ''));
        }
        redirect(module_url('prazos'));
    }

    if ($action === 'concluir') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("UPDATE prazos_processuais SET concluido = 1, concluido_em = NOW() WHERE id = ?")
                ->execute(array($id));
            flash_set('success', 'Prazo concluído!');
        } elseif ($id < 0) {
            // id negativo = agenda_eventos (visto que a tela unifica as 2 fontes)
            $pdo->prepare("UPDATE agenda_eventos SET status = 'realizado' WHERE id = ?")
                ->execute(array(abs($id)));
            flash_set('success', 'Prazo (agenda) marcado como realizado.');
        }
        redirect(module_url('prazos'));
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("DELETE FROM prazos_processuais WHERE id = ?")->execute(array($id));
            flash_set('success', 'Prazo removido.');
        } elseif ($id < 0) {
            $pdo->prepare("UPDATE agenda_eventos SET status = 'cancelado' WHERE id = ?")
                ->execute(array(abs($id)));
            flash_set('success', 'Prazo (agenda) cancelado.');
        }
        redirect(module_url('prazos'));
    }
}

// Filtro
$filtro = isset($_GET['filtro']) ? $_GET['filtro'] : 'pendentes';
// 30/06/2026 Amanda: aba por tipo de prazo (classificação por regex no
// descricao_acao). Categorias decididas com ela: DJEN/Publicação, Recurso,
// Contestação, Alegações Finais, Provas, Outros.
$tipoSel = isset($_GET['tipo']) ? $_GET['tipo'] : 'todos';

function _classificar_prazo($desc) {
    $d = mb_strtolower((string)$desc, 'UTF-8');
    // Publicação DJEN vem com prefixo "publicação:" ou contém "intimação"
    if (preg_match('/^publica[çc][ãa]o:|intima[çc][ãa]o/u', $d)) return 'djen';
    if (preg_match('/recurso|apela[çc][ãa]o|inomin|embarg|agravo/u', $d))    return 'recurso';
    if (preg_match('/contesta[çc][ãa]o|defesa\\b|r[eé]plica/u', $d))         return 'contestacao';
    if (preg_match('/alega[çc][õo]es?\\s*finais?|memori/u', $d)              ) return 'alegacoes';
    if (preg_match('/prova|per[íi]cia|testemunha|diligen[çc]ia/u', $d))      return 'provas';
    return 'outros';
}
$_tipoLabels = array(
    'todos'       => array('label' => 'Todos',           'icon' => '📋'),
    'djen'        => array('label' => 'Publicação DJEN', 'icon' => '📢'),
    'recurso'     => array('label' => 'Recurso',         'icon' => '⚖️'),
    'contestacao' => array('label' => 'Contestação',     'icon' => '🛡️'),
    'alegacoes'   => array('label' => 'Alegações Finais','icon' => '📝'),
    'provas'      => array('label' => 'Provas',          'icon' => '🔍'),
    'outros'      => array('label' => 'Outros',          'icon' => '📌'),
);
if (!isset($_tipoLabels[$tipoSel])) $tipoSel = 'todos';

// 17/06/2026: UNION com agenda_eventos tipo='prazo'. Amanda cria prazos pela
// Agenda hoje em dia, sem isso a tela ficava "mentindo" — prazo do dia HOJE
// nao aparecia aqui. Origem 'agenda' usa id negativo no DOM pra nao colidir
// com ids de prazos_processuais quando renderiza/forma.
if ($filtro === 'todos') {
    $prazos = $pdo->query(
        "SELECT * FROM (
            SELECT p.id, p.client_id, p.case_id,
                   p.numero_processo COLLATE utf8mb4_unicode_ci AS numero_processo,
                   p.descricao_acao COLLATE utf8mb4_unicode_ci AS descricao_acao,
                   p.prazo_fatal, p.concluido, p.concluido_em,
                   c.name COLLATE utf8mb4_unicode_ci AS client_name,
                   cs.title COLLATE utf8mb4_unicode_ci AS case_title,
                   CAST('prazo' AS CHAR) COLLATE utf8mb4_unicode_ci AS origem
            FROM prazos_processuais p
            LEFT JOIN clients c ON c.id = p.client_id
            LEFT JOIN cases cs ON cs.id = p.case_id
            UNION ALL
            SELECT (-ae.id) AS id, ae.client_id, ae.case_id,
                   cs.case_number COLLATE utf8mb4_unicode_ci AS numero_processo,
                   ae.titulo COLLATE utf8mb4_unicode_ci AS descricao_acao,
                   DATE(ae.data_inicio) AS prazo_fatal,
                   CASE WHEN ae.status IN ('realizado','concluido','cancelado') THEN 1 ELSE 0 END AS concluido,
                   ae.updated_at AS concluido_em,
                   c.name COLLATE utf8mb4_unicode_ci AS client_name,
                   cs.title COLLATE utf8mb4_unicode_ci AS case_title,
                   CAST('agenda' AS CHAR) COLLATE utf8mb4_unicode_ci AS origem
            FROM agenda_eventos ae
            LEFT JOIN clients c ON c.id = ae.client_id
            LEFT JOIN cases cs ON cs.id = ae.case_id
            WHERE ae.tipo = 'prazo'
        ) un
        ORDER BY concluido ASC, prazo_fatal ASC"
    )->fetchAll();
} else {
    $prazos = $pdo->query(
        "SELECT * FROM (
            SELECT p.id, p.client_id, p.case_id,
                   p.numero_processo COLLATE utf8mb4_unicode_ci AS numero_processo,
                   p.descricao_acao COLLATE utf8mb4_unicode_ci AS descricao_acao,
                   p.prazo_fatal, p.concluido, p.concluido_em,
                   c.name COLLATE utf8mb4_unicode_ci AS client_name,
                   cs.title COLLATE utf8mb4_unicode_ci AS case_title,
                   CAST('prazo' AS CHAR) COLLATE utf8mb4_unicode_ci AS origem
            FROM prazos_processuais p
            LEFT JOIN clients c ON c.id = p.client_id
            LEFT JOIN cases cs ON cs.id = p.case_id
            WHERE p.concluido = 0
            UNION ALL
            SELECT (-ae.id) AS id, ae.client_id, ae.case_id,
                   cs.case_number COLLATE utf8mb4_unicode_ci AS numero_processo,
                   ae.titulo COLLATE utf8mb4_unicode_ci AS descricao_acao,
                   DATE(ae.data_inicio) AS prazo_fatal,
                   0 AS concluido, NULL AS concluido_em,
                   c.name COLLATE utf8mb4_unicode_ci AS client_name,
                   cs.title COLLATE utf8mb4_unicode_ci AS case_title,
                   CAST('agenda' AS CHAR) COLLATE utf8mb4_unicode_ci AS origem
            FROM agenda_eventos ae
            LEFT JOIN clients c ON c.id = ae.client_id
            LEFT JOIN cases cs ON cs.id = ae.case_id
            WHERE ae.tipo = 'prazo'
              AND ae.status NOT IN ('cancelado','realizado','concluido')
        ) un
        ORDER BY prazo_fatal ASC"
    )->fetchAll();
}

// Post-process: pra prazos sem case_id mas com numero_processo textual, tenta
// achar a pasta pelo CNJ desformatado. Vinculacao em tempo de leitura — sem
// migrar dados. Amanda 14/05/2026.
$_prazosSemCaseId = array();
foreach ($prazos as $_idx => $_p) {
    if (empty($_p['case_id']) && !empty($_p['numero_processo'])) {
        $_dig = preg_replace('/\D/', '', $_p['numero_processo']);
        if (strlen($_dig) === 20) $_prazosSemCaseId[$_idx] = $_dig;
    }
}
if (!empty($_prazosSemCaseId)) {
    $_unicos = array_values(array_unique($_prazosSemCaseId));
    $_ph = implode(',', array_fill(0, count($_unicos), '?'));
    try {
        $_st = $pdo->prepare(
            "SELECT id, title, REPLACE(REPLACE(REPLACE(case_number,'-',''),'.',''),'/','') AS cnj_digits
             FROM cases
             WHERE REPLACE(REPLACE(REPLACE(case_number,'-',''),'.',''),'/','') IN ($_ph)"
        );
        $_st->execute($_unicos);
        $_mapaCnjCaso = array();
        foreach ($_st->fetchAll() as $_row) {
            if (!isset($_mapaCnjCaso[$_row['cnj_digits']])) {
                $_mapaCnjCaso[$_row['cnj_digits']] = array('id' => $_row['id'], 'title' => $_row['title']);
            }
        }
        foreach ($_prazosSemCaseId as $_idx => $_dig) {
            if (isset($_mapaCnjCaso[$_dig])) {
                $prazos[$_idx]['case_id'] = $_mapaCnjCaso[$_dig]['id'];
                $prazos[$_idx]['case_title'] = $_mapaCnjCaso[$_dig]['title'];
            }
        }
    } catch (Exception $_e) { /* falha silenciosa — sem match, mostra so CNJ como antes */ }
}

// 30/06/2026 Amanda: classifica cada prazo e conta por tipo (pra badge das abas)
$_contagemTipo = array('todos' => 0, 'djen' => 0, 'recurso' => 0, 'contestacao' => 0, 'alegacoes' => 0, 'provas' => 0, 'outros' => 0);
foreach ($prazos as $_i => $_p) {
    $_t = _classificar_prazo($_p['descricao_acao'] ?? '');
    $prazos[$_i]['_tipo_prazo'] = $_t;
    if (empty($_p['concluido'])) {
        $_contagemTipo['todos']++;
        if (isset($_contagemTipo[$_t])) $_contagemTipo[$_t]++;
    }
}
// Filtra pra aba ativa (todos = sem filtro)
if ($tipoSel !== 'todos') {
    $prazos = array_values(array_filter($prazos, function($p) use ($tipoSel) { return ($p['_tipo_prazo'] ?? '') === $tipoSel; }));
}

$clients = $pdo->query("SELECT id, name FROM clients ORDER BY name")->fetchAll();

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.prazo-card { padding:.75rem 1rem; margin-bottom:.4rem; border-radius:var(--radius); border-left:4px solid #ccc; background:var(--bg-card); display:flex; align-items:center; gap:.75rem; transition:transform .12s, box-shadow .12s; }
.prazo-card.urgente { border-left-color:#ef4444; background:#fef2f2; }
.prazo-card.alerta { border-left-color:#f59e0b; background:#fffbeb; }
.prazo-card.normal { border-left-color:#6366f1; }
.prazo-card.concluido { border-left-color:#059669; opacity:.5; }
.prazo-card.clicavel { cursor:pointer; }
.prazo-card.clicavel:hover { transform:translateY(-1px); box-shadow:0 4px 12px rgba(0,0,0,.08); }
.prazo-info { flex:1; }
.prazo-desc { font-size:.85rem; font-weight:700; color:var(--petrol-900); }
.prazo-meta { font-size:.7rem; color:var(--text-muted); margin-top:.1rem; }
.prazo-data { font-size:.82rem; font-weight:700; flex-shrink:0; }
.prazo-data.urgente { color:#ef4444; }
.prazo-data.alerta { color:#f59e0b; }
/* Abas por tipo de prazo */
.prazo-abas { display:flex;gap:.35rem;flex-wrap:wrap;margin-bottom:.8rem;background:#f8fafc;border:1px solid var(--border);border-radius:10px;padding:.4rem; }
.prazo-aba { display:inline-flex;align-items:center;gap:.35rem;padding:.4rem .75rem;border-radius:7px;font-size:.78rem;font-weight:600;text-decoration:none;color:#64748b;background:transparent;border:1.5px solid transparent;transition:all .12s;cursor:pointer; }
.prazo-aba:hover { background:#fff;color:var(--petrol-900);border-color:var(--border); }
.prazo-aba.ativa { background:var(--petrol-900);color:#fff;border-color:var(--petrol-900); }
.prazo-aba-count { display:inline-block;min-width:18px;text-align:center;background:rgba(0,0,0,.08);color:inherit;border-radius:10px;padding:1px 6px;font-size:.68rem;font-weight:700; }
.prazo-aba.ativa .prazo-aba-count { background:rgba(255,255,255,.22); }
</style>

<?php
$voltarCaso = (int)($_GET['voltar_caso'] ?? $_GET['case_id'] ?? 0);
if ($voltarCaso > 0): ?>
<div style="display:flex;gap:.5rem;margin-bottom:.75rem;">
    <a href="<?= module_url('operacional', 'caso_ver.php?id=' . $voltarCaso) ?>" class="btn btn-outline btn-sm">← Analisar processo</a>
    <a href="<?= module_url('agenda') ?>?voltar_caso=<?= $voltarCaso ?>" class="btn btn-outline btn-sm">📅 Agenda</a>
</div>
<?php endif; ?>

<?php
// helper pra montar URL preservando params
$_buildUrl = function($overrides = array()) use ($filtro, $tipoSel, $voltarCaso) {
    $qs = array_merge(array('filtro' => $filtro, 'tipo' => $tipoSel), $overrides);
    if ($voltarCaso) $qs['case_id'] = $voltarCaso;
    $parts = array();
    foreach ($qs as $k => $v) { if ($v !== '' && $v !== null) $parts[] = $k . '=' . urlencode((string)$v); }
    return '?' . implode('&', $parts);
};
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;flex-wrap:wrap;gap:.5rem;">
    <div style="display:flex;gap:.35rem;">
        <a href="<?= $_buildUrl(array('filtro' => 'pendentes')) ?>" class="btn btn-<?= $filtro === 'pendentes' ? 'primary' : 'outline' ?> btn-sm">Pendentes</a>
        <a href="<?= $_buildUrl(array('filtro' => 'todos')) ?>" class="btn btn-<?= $filtro === 'todos' ? 'primary' : 'outline' ?> btn-sm">Todos</a>
    </div>
    <button class="btn btn-primary btn-sm" data-modal="modalPrazo">+ Novo Prazo</button>
</div>

<!-- 30/06/2026 Amanda: abas por tipo de prazo. Classificação por regex no descricao_acao. -->
<div class="prazo-abas">
    <?php foreach ($_tipoLabels as $key => $cfg):
        $_qtd = $_contagemTipo[$key] ?? 0;
        $_ativo = $tipoSel === $key;
    ?>
        <a href="<?= $_buildUrl(array('tipo' => $key)) ?>" class="prazo-aba<?= $_ativo ? ' ativa' : '' ?>" title="<?= e($cfg['label']) ?>">
            <span><?= $cfg['icon'] ?></span>
            <span><?= e($cfg['label']) ?></span>
            <?php if ($_qtd > 0): ?><span class="prazo-aba-count"><?= $_qtd ?></span><?php endif; ?>
        </a>
    <?php endforeach; ?>
</div>

<?php if (empty($prazos)): ?>
    <div class="card"><div class="card-body" style="text-align:center;padding:2rem;"><h3>Nenhum prazo <?= $filtro === 'pendentes' ? 'pendente' : '' ?></h3></div></div>
<?php else: ?>
    <?php foreach ($prazos as $p):
        $diasRestantes = (int)((strtotime($p['prazo_fatal']) - strtotime('today')) / 86400);
        $isVencido = $diasRestantes < 0 && !$p['concluido'];
        $isUrgente = $diasRestantes <= 1 && !$p['concluido'];
        $isAlerta = $diasRestantes <= 5 && !$p['concluido'];
        $cardClass = $p['concluido'] ? 'concluido' : ($isUrgente || $isVencido ? 'urgente' : ($isAlerta ? 'alerta' : 'normal'));
        $dataClass = $isUrgente || $isVencido ? 'urgente' : ($isAlerta ? 'alerta' : '');
    ?>
    <?php $_caseHref = $p['case_id'] ? module_url('operacional', 'caso_ver.php?id=' . $p['case_id']) : ''; ?>
    <div class="prazo-card <?= $cardClass ?><?= $_caseHref ? ' clicavel' : '' ?>"
         <?= $_caseHref ? 'onclick="window.location.href=\'' . e($_caseHref) . '\'" title="Abrir pasta do processo"' : '' ?>>
        <div class="prazo-info">
            <div class="prazo-desc"><?= $p['concluido'] ? '<s>' : '' ?><?= e($p['descricao_acao']) ?><?= $p['concluido'] ? '</s>' : '' ?></div>
            <div class="prazo-meta">
                <?php if ($p['client_name']): ?>👤 <?= e($p['client_name']) ?> · <?php endif; ?>
                <?php if ($p['numero_processo']): ?>🏛️ <?= e($p['numero_processo']) ?> · <?php endif; ?>
                <?php if ($p['case_title']): ?>📂 <span style="color:var(--petrol-900);font-weight:600;"><?= e($p['case_title']) ?></span><?php endif; ?>
            </div>
        </div>
        <div class="prazo-data <?= $dataClass ?>">
            <?php if ($isVencido): ?>⚠️ VENCIDO <?= abs($diasRestantes) ?>d
            <?php elseif ($p['concluido']): ?>✅ <?= date('d/m', strtotime($p['concluido_em'])) ?>
            <?php else: ?><?= $diasRestantes === 0 ? 'HOJE' : $diasRestantes . 'd' ?>
            <?php endif; ?>
            <div style="font-size:.65rem;font-weight:400;color:var(--text-muted);"><?= date('d/m/Y', strtotime($p['prazo_fatal'])) ?></div>
        </div>
        <?php if (!$p['concluido']): ?>
        <form method="POST" style="display:flex;gap:.2rem;" onclick="event.stopPropagation()">
            <?= csrf_input() ?>
            <input type="hidden" name="id" value="<?= $p['id'] ?>">
            <button type="submit" name="action" value="concluir" class="btn btn-success btn-sm" style="font-size:.65rem;padding:.2rem .4rem;" title="Concluir">✓</button>
            <button type="submit" name="action" value="delete" class="btn btn-outline btn-sm" style="font-size:.65rem;padding:.2rem .4rem;opacity:.4;" title="Excluir" data-confirm="Excluir prazo?">🗑️</button>
        </form>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
<?php endif; ?>

<!-- Modal: Novo Prazo -->
<div class="modal-overlay" id="modalPrazo">
    <div class="modal">
        <div class="modal-header"><h3>Novo Prazo Processual</h3><button class="modal-close">&times;</button></div>
        <div class="modal-body">
            <form method="POST">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="create">
                <div class="form-group">
                    <label class="form-label">Descrição da ação *</label>
                    <input type="text" name="descricao_acao" class="form-input" required placeholder="Ex: Contestação, Réplica, Recurso...">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Prazo fatal *</label>
                        <input type="date" name="prazo_fatal" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Nº do processo</label>
                        <input type="text" name="numero_processo" class="form-input" placeholder="0000000-00.0000.0.00.0000">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Cliente</label>
                    <select name="client_id" class="form-select">
                        <option value="">— Opcional —</option>
                        <?php foreach ($clients as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="modal-footer" style="border:none;padding:1rem 0 0;">
                    <button type="button" class="btn btn-outline" data-modal-close>Cancelar</button>
                    <button type="submit" class="btn btn-primary">Salvar Prazo</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
