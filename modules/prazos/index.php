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

// Amanda 09/07/2026: AJAX combobox de cliente (substitui <select> de 1600 opcoes)
if (($_GET['ajax'] ?? '') === 'buscar_cliente') {
    header('Content-Type: application/json; charset=utf-8');
    $q = trim($_GET['q'] ?? '');
    if (mb_strlen($q) < 2) { echo '[]'; exit; }
    // Normaliza case pra ordenar consistente (bug real: 'Adriana' e 'ADRIANA' misturados)
    $st = $pdo->prepare(
        "SELECT id, name, cpf FROM clients
         WHERE name LIKE ?
         ORDER BY LOWER(name) ASC
         LIMIT 20"
    );
    $st->execute(array('%' . $q . '%'));
    echo json_encode($st->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
    exit;
}

// Amanda 09/07/2026: AJAX autocomplete de processo (num CNJ ou titulo)
if (($_GET['ajax'] ?? '') === 'buscar_processo') {
    header('Content-Type: application/json; charset=utf-8');
    $q = trim($_GET['q'] ?? '');
    if (mb_strlen($q) < 3) { echo '[]'; exit; }
    $qLike = '%' . $q . '%';
    // Digitos do CNJ (permite buscar '10005649320' e achar '1000564-93.2026...')
    $qDig = preg_replace('/\D/', '', $q);
    $qDigLike = '%' . $qDig . '%';
    $st = $pdo->prepare(
        "SELECT cs.id, cs.case_number, cs.title, cs.client_id, cl.name AS client_name
         FROM cases cs
         LEFT JOIN clients cl ON cl.id = cs.client_id
         WHERE cs.stage NOT IN ('arquivado','concluido')
           AND (
               cs.case_number LIKE ?
               OR (LENGTH(?) >= 4 AND REPLACE(REPLACE(REPLACE(cs.case_number,'.',''),'-',''),'/','') LIKE ?)
               OR cs.title LIKE ?
           )
         ORDER BY cs.updated_at DESC
         LIMIT 15"
    );
    $st->execute(array($qLike, $qDig, $qDigLike, $qLike));
    echo json_encode($st->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
    exit;
}

// Amanda 09/07/2026: helper pra redirect preservando filtros da URL de origem
// (bug: contador 'Todos' parecia "nao atualizar" — na verdade o redirect
// jogava a usuaria pra ?filtro=pendentes default, perdendo contexto).
$_redirectPreservando = function(){
    $qs = array();
    foreach (array('filtro','tipo','case_id','voltar_caso') as $k) {
        if (!empty($_POST[$k]))     $qs[$k] = $_POST[$k];
        elseif (!empty($_GET[$k]))  $qs[$k] = $_GET[$k];
    }
    $qs['_t'] = time(); // cache-buster contra SW cache
    return module_url('prazos') . '?' . http_build_query($qs);
};

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
        redirect($_redirectPreservando());
    }

    if ($action === 'concluir') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("UPDATE prazos_processuais SET concluido = 1, concluido_em = NOW() WHERE id = ?")
                ->execute(array($id));
            // 🔔 Jorjão toca sino (silencioso — feature flag decide se envia)
            try {
                require_once APP_ROOT . '/core/functions_jorjao.php';
                jorjao_prazo_cumprido_by_id($id, current_user_id());
            } catch (Exception $e) {}
            flash_set('success', 'Prazo concluído!');
        } elseif ($id < 0) {
            // id negativo = agenda_eventos (visto que a tela unifica as 2 fontes)
            $pdo->prepare("UPDATE agenda_eventos SET status = 'realizado' WHERE id = ?")
                ->execute(array(abs($id)));
            flash_set('success', 'Prazo (agenda) marcado como realizado.');
        }
        redirect($_redirectPreservando());
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
        redirect($_redirectPreservando());
    }
}

// Filtro (Amanda 09/07/2026: adicionado 'vencidos' pra deep-link do banner)
$filtro = isset($_GET['filtro']) ? $_GET['filtro'] : 'pendentes';
if (!in_array($filtro, array('pendentes', 'todos', 'vencidos'), true)) $filtro = 'pendentes';
// 30/06/2026 Amanda: aba por tipo de prazo (classificação por regex no
// descricao_acao). Categorias decididas com ela: DJEN/Publicação, Recurso,
// Contestação, Alegações Finais, Provas, Outros.
$tipoSel = isset($_GET['tipo']) ? $_GET['tipo'] : 'todos';

function _classificar_prazo($desc) {
    $d = mb_strtolower((string)$desc, 'UTF-8');
    // Publicação DJEN vem com prefixo "publicação:" ou contém "intimação"
    if (preg_match('/^publica[çc][ãa]o:|intima[çc][ãa]o/u', $d)) return 'djen';
    // Recursos: inclui contrarrazões (peça de resposta a recurso).
    // Bug r1 (30/06): regex contra[\s-]?raz não casava com "contrarrazões" (2 r's
    // no meio: "contra"+"razões" → "contrarrazões"). Fix: aceita 0+ separadores
    // entre "contra" e "raz" (espaço/hífen/r), cobrindo todas variantes:
    // contrarrazões, contra-razões, contra razões.
    if (preg_match('/recurso|apela[çc][ãa]o|inomin|embarg|agravo|contra[r\\s-]*raz[õo]es/u', $d)) return 'recurso';
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
// Amanda 09/07/2026: 'vencidos' reusa query base de pendentes e filtra em PHP
// (menos risco de regressao vs. duplicar a query UNION-ALL toda de novo)
$_filtroQuery = ($filtro === 'vencidos') ? 'pendentes' : $filtro;
if ($_filtroQuery === 'todos') {
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
              -- Amanda 06/07/2026: dedup — salvar_prazo insere na agenda +
              -- prazos_processuais (o insert na agenda faz aparecer na Agenda
              -- diaria). Sem esse filtro, cada prazo aparecia 2x aqui.
              AND NOT EXISTS (
                  SELECT 1 FROM prazos_processuais p2
                  WHERE p2.case_id = ae.case_id
                    AND p2.prazo_fatal = DATE(ae.data_inicio)
              )
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
              -- dedup: idem query 'todos' acima
              AND NOT EXISTS (
                  SELECT 1 FROM prazos_processuais p2
                  WHERE p2.case_id = ae.case_id
                    AND p2.prazo_fatal = DATE(ae.data_inicio)
                    AND p2.concluido = 0
              )
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

// Amanda 09/07/2026: filtro 'vencidos' — so o que passou do prazo e nao esta concluido
if ($filtro === 'vencidos') {
    $_hoje = strtotime(date('Y-m-d'));
    $prazos = array_values(array_filter($prazos, function($p) use ($_hoje) {
        return empty($p['concluido']) && strtotime($p['prazo_fatal']) < $_hoje;
    }));
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

// Amanda 09/07/2026: removido $clients = query 1600+ opcoes. Combobox no
// modal usa AJAX (?ajax=buscar_cliente) — filtro server-side, sem pre-carga.

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
        <a href="<?= $_buildUrl(array('filtro' => 'vencidos')) ?>" class="btn btn-<?= $filtro === 'vencidos' ? 'danger' : 'outline' ?> btn-sm"<?= $filtro !== 'vencidos' ? ' style="color:#b91c1c;border-color:#fca5a5;"' : '' ?>>🚨 Vencidos</a>
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

<!-- Modal: Novo Prazo (Amanda 09/07/2026: combobox cliente + autocomplete processo) -->
<style>
.pz-combo { position:relative; }
.pz-combo-results { position:absolute;top:100%;left:0;right:0;z-index:50;background:#fff;border:1px solid #ddd;border-radius:0 0 8px 8px;max-height:220px;overflow:auto;display:none;box-shadow:0 6px 14px rgba(0,0,0,.12); }
.pz-combo-results.aberto { display:block; }
.pz-combo-item { padding:.5rem .75rem;cursor:pointer;border-bottom:1px solid #f1f5f9;font-size:.86rem; }
.pz-combo-item:hover, .pz-combo-item.focado { background:#ecfeff; }
.pz-combo-item .pz-sub { color:#64748b;font-size:.75rem;display:block;margin-top:1px; }
.pz-combo-vazio { padding:.6rem .75rem;color:#94a3b8;font-size:.82rem;text-align:center; }
.pz-pill-selecionado { display:inline-flex;align-items:center;gap:.4rem;background:#ecfeff;border:1px solid #67e8f9;color:#0e7490;padding:4px 10px;border-radius:999px;font-size:.8rem;font-weight:600;margin-top:6px; }
.pz-pill-selecionado button { background:none;border:none;color:#b91c1c;font-size:1rem;cursor:pointer;padding:0;line-height:1; }
.pz-processo-match { background:#dcfce7;border:1px solid #86efac;color:#15803d;padding:5px 10px;border-radius:6px;font-size:.78rem;margin-top:5px;display:flex;align-items:center;gap:.4rem; }
.pz-processo-nomatch { background:#fef3c7;border:1px solid #fbbf24;color:#78350f;padding:5px 10px;border-radius:6px;font-size:.75rem;margin-top:5px; }
</style>
<div class="modal-overlay" id="modalPrazo">
    <div class="modal">
        <div class="modal-header"><h3>Novo Prazo Processual</h3><button class="modal-close">&times;</button></div>
        <div class="modal-body">
            <form method="POST" id="frmPrazo">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="client_id" id="pzClientId" value="">
                <input type="hidden" name="case_id" id="pzCaseId" value="">
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
                        <div class="pz-combo">
                            <input type="text" name="numero_processo" id="pzProcesso" class="form-input" placeholder="Digite CNJ, título ou parte do número…" autocomplete="off">
                            <div class="pz-combo-results" id="pzProcResults"></div>
                        </div>
                        <div id="pzProcVinculo"></div>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Cliente</label>
                    <div class="pz-combo">
                        <input type="text" id="pzClienteBusca" class="form-input" placeholder="Digite o nome do cliente…" autocomplete="off">
                        <div class="pz-combo-results" id="pzClienteResults"></div>
                    </div>
                    <div id="pzClienteSel"></div>
                </div>
                <div class="modal-footer" style="border:none;padding:1rem 0 0;">
                    <button type="button" class="btn btn-outline" data-modal-close>Cancelar</button>
                    <button type="submit" class="btn btn-primary">Salvar Prazo</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
(function(){
    var PZ_URL = '<?= module_url('prazos') ?>';

    /* Combobox cliente */
    var cliInput = document.getElementById('pzClienteBusca');
    var cliBox   = document.getElementById('pzClienteResults');
    var cliSel   = document.getElementById('pzClienteSel');
    var cliHid   = document.getElementById('pzClientId');
    var cliT;
    cliInput.addEventListener('input', function(){
        clearTimeout(cliT);
        var q = this.value.trim();
        if (q.length < 2) { cliBox.classList.remove('aberto'); return; }
        cliT = setTimeout(function(){
            fetch(PZ_URL + '?ajax=buscar_cliente&q=' + encodeURIComponent(q), {credentials:'same-origin'})
                .then(function(r){ return r.json(); })
                .then(function(arr){
                    if (!arr.length) { cliBox.innerHTML = '<div class="pz-combo-vazio">Nenhum cliente encontrado</div>'; cliBox.classList.add('aberto'); return; }
                    var html = '';
                    arr.forEach(function(c){
                        var cpf = c.cpf ? ' · ' + c.cpf : '';
                        html += '<div class="pz-combo-item" data-id="' + c.id + '" data-name="' + (c.name||'').replace(/"/g,'&quot;') + '">' + (c.name||'') + '<span class="pz-sub">' + cpf + '</span></div>';
                    });
                    cliBox.innerHTML = html;
                    cliBox.classList.add('aberto');
                });
        }, 220);
    });
    cliBox.addEventListener('click', function(e){
        var item = e.target.closest('.pz-combo-item');
        if (!item) return;
        var id = item.dataset.id, name = item.dataset.name;
        cliHid.value = id;
        cliInput.value = '';
        cliInput.style.display = 'none';
        cliBox.classList.remove('aberto');
        cliSel.innerHTML = '<span class="pz-pill-selecionado">👤 ' + name + ' <button type="button" title="Remover">×</button></span>';
        cliSel.querySelector('button').addEventListener('click', function(){
            cliHid.value = '';
            cliSel.innerHTML = '';
            cliInput.style.display = '';
            cliInput.value = '';
            cliInput.focus();
        });
    });
    document.addEventListener('click', function(e){
        if (!e.target.closest('.pz-combo')) cliBox.classList.remove('aberto');
    });

    /* Autocomplete processo */
    var procInput = document.getElementById('pzProcesso');
    var procBox   = document.getElementById('pzProcResults');
    var procVinc  = document.getElementById('pzProcVinculo');
    var procHidCase = document.getElementById('pzCaseId');
    var procT;
    function limparVinculo() {
        procHidCase.value = '';
        procVinc.innerHTML = '';
    }
    procInput.addEventListener('input', function(){
        limparVinculo();
        clearTimeout(procT);
        var q = this.value.trim();
        if (q.length < 3) { procBox.classList.remove('aberto'); return; }
        procT = setTimeout(function(){
            fetch(PZ_URL + '?ajax=buscar_processo&q=' + encodeURIComponent(q), {credentials:'same-origin'})
                .then(function(r){ return r.json(); })
                .then(function(arr){
                    if (!arr.length) {
                        procBox.classList.remove('aberto');
                        // Aviso: nao achou pasta
                        if (q.replace(/\D/g,'').length >= 15) {
                            procVinc.innerHTML = '<div class="pz-processo-nomatch">⚠️ Nenhuma pasta encontrada com este número — o prazo será salvo sem vínculo. Verifique o número ou crie a pasta antes.</div>';
                        }
                        return;
                    }
                    var html = '';
                    arr.forEach(function(c){
                        var cnj = c.case_number || '—';
                        var t = c.title || '';
                        var cl = c.client_name ? ' · 👤 ' + c.client_name : '';
                        html += '<div class="pz-combo-item" data-case-id="' + c.id + '" data-cnj="' + (cnj||'').replace(/"/g,'&quot;') + '" data-title="' + t.replace(/"/g,'&quot;') + '" data-client-id="' + (c.client_id||'') + '" data-client-name="' + (c.client_name||'').replace(/"/g,'&quot;') + '"><strong>' + cnj + '</strong><span class="pz-sub">📂 ' + t + cl + '</span></div>';
                    });
                    procBox.innerHTML = html;
                    procBox.classList.add('aberto');
                });
        }, 220);
    });
    procBox.addEventListener('click', function(e){
        var item = e.target.closest('.pz-combo-item');
        if (!item) return;
        procInput.value = item.dataset.cnj !== '—' ? item.dataset.cnj : '';
        procHidCase.value = item.dataset.caseId;
        procBox.classList.remove('aberto');
        procVinc.innerHTML = '<div class="pz-processo-match">✓ Vinculado à pasta <strong>' + item.dataset.title + '</strong> <button type="button" title="Remover vínculo" style="background:none;border:none;color:#b91c1c;cursor:pointer;font-size:1rem;padding:0;margin-left:auto;">×</button></div>';
        procVinc.querySelector('button').addEventListener('click', limparVinculo);
        // Auto-vincula cliente se ainda nao selecionado
        if (!cliHid.value && item.dataset.clientId) {
            cliHid.value = item.dataset.clientId;
            cliInput.style.display = 'none';
            cliSel.innerHTML = '<span class="pz-pill-selecionado" title="Cliente vinculado à pasta">👤 ' + item.dataset.clientName + ' <button type="button" title="Remover">×</button></span>';
            cliSel.querySelector('button').addEventListener('click', function(){
                cliHid.value = '';
                cliSel.innerHTML = '';
                cliInput.style.display = '';
                cliInput.value = '';
            });
        }
    });
})();
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
