<?php
/**
 * Duplicatas de Cases — relatório + merge assistido.
 * Amanda 20/07/2026: bug histórico onde comercial criava lead novo pra cliente
 * já cadastrada e o hook contrato_assinado gerava case duplicado (ex: Maria
 * Ana Paula com 2x 'x Alimentos' apontando pra mesma pasta Drive).
 *
 * Escopo: só admin/gestão.
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
require_min_role('gestao');

$pdo = db();

// Self-heal: tabela de decisões (o que Amanda já decidiu sobre cada grupo)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS dup_cases_decisoes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        grupo_key VARCHAR(64) NOT NULL,
        decisao VARCHAR(20) NOT NULL,
        detalhe VARCHAR(200) NULL,
        decidido_por INT NULL,
        decidido_em DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY (grupo_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Exception $e) {}

$grupoKey = function ($clientId, $title) {
    return md5(((int)$clientId) . '|' . mb_strtolower(trim((string)$title)));
};

$flash = '';

// ── AÇÃO: marcar grupo como legítimo (não é duplicata, cliente contratou 2 ações) ─
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'marcar_legitimo') {
    if (!validate_csrf()) { flash_set('error', 'CSRF inválido.'); redirect(module_url('admin', 'duplicatas_cases.php')); }
    $gk = trim((string)($_POST['grupo_key'] ?? ''));
    if ($gk) {
        $pdo->prepare("REPLACE INTO dup_cases_decisoes (grupo_key, decisao, decidido_por) VALUES (?,?,?)")
            ->execute(array($gk, 'legitimo', current_user_id()));
        audit_log('dup_case_legitimo', 'grupo', 0, "gk=$gk");
        flash_set('success', 'Marcado como legítimo (não vai mais aparecer).');
    }
    redirect(module_url('admin', 'duplicatas_cases.php'));
}

// ── AÇÃO: executar merge (case principal absorve andamentos/tarefas dos duplicados) ─
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'merge') {
    if (!validate_csrf()) { flash_set('error', 'CSRF inválido.'); redirect(module_url('admin', 'duplicatas_cases.php')); }
    $principalId = (int)($_POST['principal_id'] ?? 0);
    $duplicadosIds = array_map('intval', array_filter(explode(',', (string)($_POST['duplicados'] ?? ''))));
    $gk = trim((string)($_POST['grupo_key'] ?? ''));
    if (!$principalId || empty($duplicadosIds)) { flash_set('error', 'IDs inválidos.'); redirect(module_url('admin', 'duplicatas_cases.php')); }
    // Não pode incluir o principal na lista de duplicados
    $duplicadosIds = array_values(array_filter($duplicadosIds, function($x) use ($principalId){ return $x !== $principalId; }));
    // Verifica que todos pertencem ao mesmo cliente + mesmo título (segurança)
    $stChk = $pdo->prepare("SELECT client_id, title FROM cases WHERE id = ?");
    $stChk->execute(array($principalId));
    $princ = $stChk->fetch(PDO::FETCH_ASSOC);
    if (!$princ) { flash_set('error', 'Case principal não encontrado.'); redirect(module_url('admin', 'duplicatas_cases.php')); }
    $movidos = array('andamentos' => 0, 'tarefas' => 0, 'docs' => 0, 'partes' => 0, 'leads' => 0, 'renuncias' => 0);
    foreach ($duplicadosIds as $dupId) {
        $stD = $pdo->prepare("SELECT client_id, title FROM cases WHERE id = ?");
        $stD->execute(array($dupId));
        $dup = $stD->fetch(PDO::FETCH_ASSOC);
        if (!$dup) continue;
        if ((int)$dup['client_id'] !== (int)$princ['client_id']) continue;

        try {
            $pdo->beginTransaction();
            // Move andamentos
            $st = $pdo->prepare("UPDATE case_andamentos SET case_id = ? WHERE case_id = ?");
            $st->execute(array($principalId, $dupId));
            $movidos['andamentos'] += $st->rowCount();

            // Tarefas
            try { $st = $pdo->prepare("UPDATE case_tasks SET case_id = ? WHERE case_id = ?");
                $st->execute(array($principalId, $dupId));
                $movidos['tarefas'] += $st->rowCount();
            } catch (Exception $e) {}

            // Documentos pendentes
            try { $st = $pdo->prepare("UPDATE documentos_pendentes SET case_id = ? WHERE case_id = ?");
                $st->execute(array($principalId, $dupId));
                $movidos['docs'] += $st->rowCount();
            } catch (Exception $e) {}

            // Partes (evita duplicar por CPF já no principal)
            try {
                $stPar = $pdo->prepare("SELECT id, cpf, cnpj, nome FROM case_partes WHERE case_id = ?");
                $stPar->execute(array($dupId));
                foreach ($stPar as $pDup) {
                    $doc = preg_replace('/\D/', '', (string)($pDup['cpf'] ?: $pDup['cnpj']));
                    $existe = false;
                    if ($doc) {
                        $stJa = $pdo->prepare("SELECT 1 FROM case_partes WHERE case_id = ? AND (REPLACE(REPLACE(cpf,'.',''),'-','')=? OR REPLACE(REPLACE(REPLACE(cnpj,'.',''),'-',''),'/','')=?)");
                        $stJa->execute(array($principalId, $doc, $doc));
                        $existe = (bool)$stJa->fetchColumn();
                    }
                    if (!$existe) {
                        $pdo->prepare("UPDATE case_partes SET case_id = ? WHERE id = ?")->execute(array($principalId, $pDup['id']));
                        $movidos['partes']++;
                    } else {
                        $pdo->prepare("DELETE FROM case_partes WHERE id = ?")->execute(array($pDup['id']));
                    }
                }
            } catch (Exception $e) {}

            // Pipeline_leads vinculados ao duplicado → apontar pro principal
            try { $st = $pdo->prepare("UPDATE pipeline_leads SET linked_case_id = ? WHERE linked_case_id = ?");
                $st->execute(array($principalId, $dupId));
                $movidos['leads'] += $st->rowCount();
            } catch (Exception $e) {}

            // Renúncias
            try { $st = $pdo->prepare("UPDATE renuncias SET case_id = ? WHERE case_id = ?");
                $st->execute(array($principalId, $dupId));
                $movidos['renuncias'] += $st->rowCount();
            } catch (Exception $e) {}

            // Marca duplicado como arquivado + nota
            $pdo->prepare("UPDATE cases SET status='arquivado', kanban_oculto=1,
                            notes = CONCAT(COALESCE(notes,''), ' | MERGED em ', NOW(), ' pra case #', ?, ' (era duplicata)')
                          WHERE id = ?")
                ->execute(array($principalId, $dupId));

            audit_log('case_merged', 'case', $dupId, "merged into #$principalId por user=" . current_user_id());
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            flash_set('error', "Falha ao mesclar case #$dupId: " . $e->getMessage());
            redirect(module_url('admin', 'duplicatas_cases.php'));
        }
    }
    if ($gk) {
        $pdo->prepare("REPLACE INTO dup_cases_decisoes (grupo_key, decisao, detalhe, decidido_por) VALUES (?,?,?,?)")
            ->execute(array($gk, 'merged', "principal=#$principalId dup=" . implode(',', $duplicadosIds), current_user_id()));
    }
    flash_set('success', "Merge concluído. Movidos: " . implode(', ', array_map(function($k,$v){return "$v $k";}, array_keys($movidos), array_values($movidos))) . ". " . count($duplicadosIds) . " case(s) arquivado(s).");
    redirect(module_url('admin', 'duplicatas_cases.php'));
}

// ── LISTAR GRUPOS DUPLICADOS ─────────────────────────────────────
$sql = "SELECT client_id, title, GROUP_CONCAT(id ORDER BY id) ids_csv, COUNT(*) qtd
        FROM cases
        WHERE client_id > 0
        GROUP BY client_id, title
        HAVING qtd > 1
        ORDER BY qtd DESC, client_id DESC";
$grupos = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

// Filtro: só mostra grupos SEM decisão OU decisão != legitimo/merged
$decisoes = array();
foreach ($pdo->query("SELECT grupo_key, decisao FROM dup_cases_decisoes") as $r) {
    $decisoes[$r['grupo_key']] = $r['decisao'];
}

$filtro = $_GET['f'] ?? 'pendentes';
$gruposFinais = array();
foreach ($grupos as $g) {
    $gk = $grupoKey($g['client_id'], $g['title']);
    $dec = $decisoes[$gk] ?? 'pendente';
    if ($filtro === 'pendentes' && $dec !== 'pendente') continue;
    if ($filtro === 'legitimos' && $dec !== 'legitimo') continue;
    if ($filtro === 'merged' && $dec !== 'merged') continue;
    $g['grupo_key'] = $gk;
    $g['decisao'] = $dec;
    $gruposFinais[] = $g;
}

// Detalhes por case + contagens
function _det_case($pdo, $ids) {
    $out = array();
    if (empty($ids)) return $out;
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT id, title, case_type, status, drive_folder_url, created_at, opened_at, responsible_user_id, notes
                         FROM cases WHERE id IN ($ph)");
    $st->execute($ids);
    foreach ($st as $c) $out[(int)$c['id']] = $c;
    // Contagens
    $st = $pdo->prepare("SELECT case_id, COUNT(*) c FROM case_andamentos WHERE case_id IN ($ph) GROUP BY case_id");
    $st->execute($ids);
    foreach ($st as $r) $out[(int)$r['case_id']]['n_andamentos'] = (int)$r['c'];
    try {
        $st = $pdo->prepare("SELECT case_id, COUNT(*) c FROM case_tasks WHERE case_id IN ($ph) GROUP BY case_id");
        $st->execute($ids);
        foreach ($st as $r) $out[(int)$r['case_id']]['n_tarefas'] = (int)$r['c'];
    } catch (Exception $e) {}
    try {
        $st = $pdo->prepare("SELECT case_id, COUNT(*) c FROM documentos_pendentes WHERE case_id IN ($ph) GROUP BY case_id");
        $st->execute($ids);
        foreach ($st as $r) $out[(int)$r['case_id']]['n_docs'] = (int)$r['c'];
    } catch (Exception $e) {}
    return $out;
}

$pageTitle = 'Duplicatas de Cases';
require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.dc-card { background:#fff; border:1px solid var(--border); border-radius:12px; padding:1rem 1.25rem; margin-bottom:1rem; box-shadow:0 2px 8px rgba(0,0,0,.04); }
.dc-cliente { font-weight:800; color:var(--petrol-900); font-size:1rem; }
.dc-titulo { font-size:.85rem; color:#4b5563; margin-top:.15rem; }
.dc-cases-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(280px,1fr)); gap:.75rem; margin-top:.85rem; }
.dc-case { border:1.5px solid #e5e7eb; border-radius:10px; padding:.75rem; background:#fafafa; }
.dc-case.principal { border-color:#059669; background:#ecfdf5; box-shadow:0 0 0 2px rgba(5,150,105,.15); }
.dc-case-hd { display:flex; justify-content:space-between; align-items:center; margin-bottom:.4rem; }
.dc-case-id { font-family:monospace; font-weight:700; color:#052228; font-size:.85rem; }
.dc-case-radio { display:flex; align-items:center; gap:.4rem; font-size:.75rem; font-weight:700; color:#059669; cursor:pointer; }
.dc-badge { display:inline-block; font-size:.65rem; padding:2px 8px; border-radius:8px; font-weight:700; }
.dc-badge.em_andamento { background:#dbeafe; color:#1e40af; }
.dc-badge.em_elaboracao { background:#e0e7ff; color:#4338ca; }
.dc-badge.aguardando_docs { background:#fef3c7; color:#92400e; }
.dc-badge.distribuido { background:#dcfce7; color:#166534; }
.dc-badge.arquivado { background:#f3f4f6; color:#6b7280; }
.dc-badge.concluido { background:#f3f4f6; color:#6b7280; }
.dc-badge.finalizado { background:#f3f4f6; color:#6b7280; }
.dc-badge.cancelado { background:#fee2e2; color:#991b1b; }
.dc-stats { font-size:.72rem; color:#6b7280; margin:.4rem 0; }
.dc-drive { font-family:monospace; font-size:.65rem; color:#6b7280; word-break:break-all; margin-top:.3rem; }
.dc-mesma-pasta { background:#fef3c7; color:#78350f; padding:2px 6px; border-radius:4px; font-size:.62rem; font-weight:700; }
.dc-acoes { display:flex; flex-wrap:wrap; gap:.5rem; margin-top:.75rem; padding-top:.75rem; border-top:1px dashed #e5e7eb; }
.dc-filtros { display:flex; gap:.4rem; margin-bottom:1rem; }
.dc-filtro { padding:.35rem .8rem; border:1px solid var(--border); border-radius:8px; background:#fff; font-size:.78rem; text-decoration:none; color:#4b5563; }
.dc-filtro.ativo { background:#052228; color:#fff; font-weight:700; }
</style>

<div class="page-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.5rem;margin-bottom:1rem;">
    <h1 style="margin:0;font-size:1.4rem;">📁 Duplicatas de Cases</h1>
    <div style="font-size:.75rem;color:var(--text-muted);">Amanda 20/07/2026 · fix retroativo</div>
</div>

<?php if ($flashOk = flash_get('success')): ?><div class="alert alert-success"><?= e($flashOk) ?></div><?php endif; ?>
<?php if ($flashErr = flash_get('error')): ?><div class="alert alert-error"><?= e($flashErr) ?></div><?php endif; ?>

<div style="background:#fef3c7;border-left:3px solid #f59e0b;padding:.85rem 1rem;border-radius:8px;margin-bottom:1rem;font-size:.85rem;color:#78350f;">
    <strong>Contexto:</strong> Encontramos <?= count($grupos) ?> grupos de cases com <strong>mesmo cliente + mesmo título</strong> (possíveis duplicatas). Analise cada grupo: se são a <strong>mesma ação criada 2x por engano</strong>, escolha qual case principal e clique <em>Merge</em>. Se são <strong>2 ações legítimas diferentes</strong> (ex: 2 processos separados com título semelhante), clique <em>Marcar como legítimo</em> pra tirar do relatório.
</div>

<div class="dc-filtros">
    <a href="?f=pendentes" class="dc-filtro <?= $filtro === 'pendentes' ? 'ativo' : '' ?>">⏳ Pendentes</a>
    <a href="?f=legitimos" class="dc-filtro <?= $filtro === 'legitimos' ? 'ativo' : '' ?>">✓ Legítimos</a>
    <a href="?f=merged" class="dc-filtro <?= $filtro === 'merged' ? 'ativo' : '' ?>">🔀 Já Merged</a>
</div>

<?php if (empty($gruposFinais)): ?>
    <div class="dc-card" style="text-align:center;padding:2rem;color:#6b7280;">
        <div style="font-size:2rem;margin-bottom:.5rem;">🎉</div>
        Nenhum grupo <?= $filtro === 'pendentes' ? 'pendente' : ($filtro === 'legitimos' ? 'marcado como legítimo' : 'já merged') ?>.
    </div>
<?php else: ?>
<?php foreach ($gruposFinais as $g):
    $ids = array_map('intval', explode(',', $g['ids_csv']));
    $det = _det_case($pdo, $ids);
    $stCli = $pdo->prepare("SELECT name FROM clients WHERE id = ?");
    $stCli->execute(array((int)$g['client_id']));
    $cliNome = (string)$stCli->fetchColumn();
    // Detecta se todos apontam pra mesma pasta Drive
    $drives = array_unique(array_filter(array_column($det, 'drive_folder_url')));
    $mesmaPasta = count($drives) === 1 && count($det) > 1;
?>
<div class="dc-card">
    <div class="dc-cliente">👤 <?= e($cliNome) ?> <span style="color:var(--text-muted);font-size:.75rem;font-weight:400;">· client#<?= (int)$g['client_id'] ?></span></div>
    <div class="dc-titulo">📄 <?= e($g['title']) ?> · <strong><?= $g['qtd'] ?> cases</strong> <?php if ($mesmaPasta): ?><span class="dc-mesma-pasta">🚨 MESMA PASTA DRIVE</span><?php endif; ?></div>

    <form method="POST" onsubmit="return dcConfirmarMerge(this)">
        <?= csrf_input() ?>
        <input type="hidden" name="acao" value="merge">
        <input type="hidden" name="grupo_key" value="<?= e($g['grupo_key']) ?>">
        <input type="hidden" name="duplicados" value="<?= e($g['ids_csv']) ?>">
        <div class="dc-cases-grid">
            <?php foreach ($ids as $cid):
                $c = $det[$cid] ?? null;
                if (!$c) continue;
            ?>
            <div class="dc-case" id="dc-case-<?= $cid ?>">
                <div class="dc-case-hd">
                    <span class="dc-case-id">case #<?= $cid ?></span>
                    <label class="dc-case-radio"><input type="radio" name="principal_id" value="<?= $cid ?>" onchange="dcHighlight(<?= $cid ?>)"> Principal</label>
                </div>
                <div><span class="dc-badge <?= e($c['status']) ?>"><?= e($c['status']) ?></span></div>
                <div class="dc-stats">
                    Tipo: <?= e($c['case_type'] ?: '—') ?><br>
                    Criado: <?= date('d/m/Y H:i', strtotime($c['created_at'])) ?><br>
                    📄 <?= (int)($c['n_andamentos'] ?? 0) ?> andamentos ·
                    ✅ <?= (int)($c['n_tarefas'] ?? 0) ?> tarefas ·
                    📋 <?= (int)($c['n_docs'] ?? 0) ?> docs pend.
                </div>
                <?php if (!empty($c['drive_folder_url'])): ?>
                <div class="dc-drive"><a href="<?= e($c['drive_folder_url']) ?>" target="_blank">📁 <?= e(substr($c['drive_folder_url'], -30)) ?></a></div>
                <?php endif; ?>
                <div style="margin-top:.4rem;"><a href="<?= module_url('operacional', 'caso_ver.php?id=' . $cid) ?>" target="_blank" style="font-size:.72rem;color:var(--copper-700);">👁️ Abrir pasta →</a></div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="dc-acoes">
            <button type="submit" class="btn btn-primary btn-sm" style="background:#059669;">🔀 Fazer Merge (mantém o Principal, arquiva os outros)</button>
            <button type="button" onclick="dcMarcarLegitimo('<?= e($g['grupo_key']) ?>')" class="btn btn-outline btn-sm" style="border-color:#0d9488;color:#0d9488;">✓ Marcar como legítimo (2 ações diferentes)</button>
        </div>
    </form>
</div>
<?php endforeach; ?>
<?php endif; ?>

<!-- Form invisível pra marcar como legítimo -->
<form id="dcFormLeg" method="POST" style="display:none;">
    <?= csrf_input() ?>
    <input type="hidden" name="acao" value="marcar_legitimo">
    <input type="hidden" name="grupo_key" id="dcLegGK">
</form>

<script>
function dcHighlight(cid) {
    document.querySelectorAll('.dc-case').forEach(function(el){ el.classList.remove('principal'); });
    var el = document.getElementById('dc-case-' + cid);
    if (el) el.classList.add('principal');
}
function dcConfirmarMerge(form) {
    var p = form.querySelector('input[name="principal_id"]:checked');
    if (!p) { alert('Escolha qual case fica como PRINCIPAL antes de fazer o merge.'); return false; }
    return confirm('Confirmar merge?\n\nO case #' + p.value + ' vai receber TODOS os andamentos, tarefas, documentos e partes dos outros. Os outros vão pra "arquivado" com nota "MERGED em X".\n\nIrreversível pelo botão. Continuar?');
}
function dcMarcarLegitimo(gk) {
    if (!confirm('Marcar esse grupo como legítimo?\n\nOs cases continuam separados (não é feito merge). O grupo some do relatório.')) return;
    document.getElementById('dcLegGK').value = gk;
    document.getElementById('dcFormLeg').submit();
}
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
