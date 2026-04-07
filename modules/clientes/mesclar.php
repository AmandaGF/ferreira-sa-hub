<?php
/**
 * Ferreira & Sá Hub — Mesclar Contatos Duplicados
 * Detecta duplicados por CPF ou nome similar e permite unificar.
 */

require_once __DIR__ . '/../../core/middleware.php';
require_access('crm');

$pdo = db();
$pageTitle = 'Mesclar Contatos Duplicados';

// ═══ AÇÃO: Executar merge ═══
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'merge') {
    if (!validate_csrf()) { flash_set('error', 'Token inválido.'); redirect(module_url('clientes', 'mesclar.php')); }

    $keepId = (int)($_POST['keep_id'] ?? 0);
    $mergeId = (int)($_POST['merge_id'] ?? 0);

    if (!$keepId || !$mergeId || $keepId === $mergeId) {
        flash_set('error', 'Selecione dois contatos diferentes.');
        redirect(module_url('clientes', 'mesclar.php'));
    }

    $keep = $pdo->prepare("SELECT * FROM clients WHERE id = ?"); $keep->execute(array($keepId)); $keep = $keep->fetch();
    $merge = $pdo->prepare("SELECT * FROM clients WHERE id = ?"); $merge->execute(array($mergeId)); $merge = $merge->fetch();

    if (!$keep || !$merge) {
        flash_set('error', 'Contato não encontrado.');
        redirect(module_url('clientes', 'mesclar.php'));
    }

    // 1. Preencher campos vazios do principal com dados do duplicado
    $fields = array('cpf','rg','phone','phone2','email','birth_date','address_street','address_city',
        'address_state','address_zip','profession','marital_status','gender','nacionalidade',
        'has_children','children_names','pix_key','source','notes');

    $updates = array();
    $updateVals = array();
    foreach ($fields as $f) {
        $keepVal = $keep[$f] ?? null;
        $mergeVal = $merge[$f] ?? null;
        if (($keepVal === null || $keepVal === '') && $mergeVal !== null && $mergeVal !== '') {
            $updates[] = "$f = ?";
            $updateVals[] = $mergeVal;
        }
    }

    // Notas: concatenar se ambos têm
    if (!empty($keep['notes']) && !empty($merge['notes']) && $keep['notes'] !== $merge['notes']) {
        $updates[] = "notes = ?";
        $updateVals[] = $keep['notes'] . "\n---\n" . $merge['notes'];
    }

    if (!empty($updates)) {
        $updateVals[] = $keepId;
        $pdo->prepare("UPDATE clients SET " . implode(', ', $updates) . ", updated_at = NOW() WHERE id = ?")->execute($updateVals);
    }

    // 2. Transferir registros vinculados do duplicado para o principal
    $transfers = array(
        "UPDATE cases SET client_id = ? WHERE client_id = ?",
        "UPDATE contacts SET client_id = ? WHERE client_id = ?",
        "UPDATE pipeline_leads SET client_id = ? WHERE client_id = ?",
        "UPDATE form_submissions SET linked_client_id = ? WHERE linked_client_id = ?",
        "UPDATE tickets SET client_id = ? WHERE client_id = ?",
    );

    // Tabelas que podem não existir
    $optionalTransfers = array(
        "UPDATE documentos_pendentes SET client_id = ? WHERE client_id = ?",
        "UPDATE case_partes SET client_id = ? WHERE client_id = ?",
        "UPDATE newsletter_descadastros SET client_id = ? WHERE client_id = ?",
        "UPDATE agenda_eventos SET client_id = ? WHERE client_id = ?",
    );

    foreach ($transfers as $sql) {
        $pdo->prepare($sql)->execute(array($keepId, $mergeId));
    }
    foreach ($optionalTransfers as $sql) {
        try { $pdo->prepare($sql)->execute(array($keepId, $mergeId)); } catch (Exception $e) {}
    }

    // 3. Excluir o duplicado
    $pdo->prepare("DELETE FROM clients WHERE id = ?")->execute(array($mergeId));

    audit_log('client_merged', 'client', $keepId, "Mesclado com #$mergeId (" . ($merge['name'] ?? '') . ")");
    flash_set('success', 'Contatos mesclados! "' . e($merge['name']) . '" foi unido a "' . e($keep['name']) . '".');
    redirect(module_url('clientes', 'mesclar.php'));
}

// ═══ Detectar duplicados ═══
// 1. Por CPF igual (mais confiável)
$dupsCpf = $pdo->query(
    "SELECT cpf, GROUP_CONCAT(id ORDER BY id) as ids, GROUP_CONCAT(name ORDER BY id SEPARATOR ' | ') as names, COUNT(*) as cnt
     FROM clients WHERE cpf IS NOT NULL AND cpf != '' GROUP BY cpf HAVING cnt > 1 ORDER BY cnt DESC"
)->fetchAll();

// 2. Por nome normalizado (remove espaços duplos, uppercased)
$dupsNome = $pdo->query(
    "SELECT UPPER(TRIM(REPLACE(REPLACE(REPLACE(name, '  ', ' '), '  ', ' '), '  ', ' '))) as nome_norm,
     GROUP_CONCAT(id ORDER BY id) as ids, GROUP_CONCAT(name ORDER BY id SEPARATOR ' | ') as names, COUNT(*) as cnt
     FROM clients WHERE name IS NOT NULL AND name != ''
     GROUP BY nome_norm HAVING cnt > 1 ORDER BY cnt DESC LIMIT 50"
)->fetchAll();

// 3. Por telefone igual (captura duplicados com nomes ligeiramente diferentes)
$dupsTel = $pdo->query(
    "SELECT REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone,' ',''),'-',''),'(',''),')',''),'+','') as tel_norm,
     GROUP_CONCAT(id ORDER BY id) as ids, GROUP_CONCAT(name ORDER BY id SEPARATOR ' | ') as names, COUNT(*) as cnt
     FROM clients WHERE phone IS NOT NULL AND phone != '' AND LENGTH(phone) >= 8
     GROUP BY tel_norm HAVING cnt > 1 ORDER BY cnt DESC LIMIT 50"
)->fetchAll();

// Contar grupos únicos (evitar contar o mesmo par duas vezes)
$allGroupIds = array();
foreach ($dupsCpf as $d) $allGroupIds[] = $d['ids'];
foreach ($dupsNome as $d) $allGroupIds[] = $d['ids'];
foreach ($dupsTel as $d) $allGroupIds[] = $d['ids'];
$totalDups = count(array_unique($allGroupIds));

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.merge-card { background:var(--bg-card); border:1px solid var(--border); border-radius:var(--radius-lg); padding:1rem; margin-bottom:.75rem; }
.merge-pair { display:grid; grid-template-columns:1fr auto 1fr; gap:.75rem; align-items:start; }
@media(max-width:768px){ .merge-pair{grid-template-columns:1fr;} }
.merge-client { border:2px solid var(--border); border-radius:var(--radius); padding:.75rem; font-size:.82rem; }
.merge-client.selected { border-color:var(--success); background:rgba(5,150,105,.05); }
.merge-field { display:flex; justify-content:space-between; padding:.2rem 0; border-bottom:1px solid #f3f4f6; }
.merge-field .label { color:var(--text-muted); font-size:.72rem; text-transform:uppercase; }
.merge-field .val { font-weight:600; color:var(--petrol-900); max-width:60%; text-align:right; word-break:break-all; }
.merge-field .val.empty { color:#d1d5db; font-weight:400; }
.merge-arrow { display:flex; align-items:center; justify-content:center; font-size:1.5rem; color:var(--text-muted); }
.merge-actions { display:flex; gap:.5rem; margin-top:.75rem; justify-content:center; }
.dup-type { font-size:.65rem; font-weight:700; text-transform:uppercase; padding:.15rem .5rem; border-radius:4px; color:#fff; }
.dup-cpf { background:#ef4444; }
.dup-nome { background:#f59e0b; }
.dup-tel { background:#3b82f6; }
</style>

<a href="<?= module_url('clientes') ?>" class="btn btn-outline btn-sm mb-2">&larr; Voltar</a>

<div class="card mb-2">
    <div class="card-header">
        <h3>Mesclar Contatos Duplicados</h3>
        <span class="badge badge-warning"><?= $totalDups ?> grupo<?= $totalDups != 1 ? 's' : '' ?> encontrado<?= $totalDups != 1 ? 's' : '' ?></span>
    </div>
    <div class="card-body">
        <p style="font-size:.82rem;color:var(--text-muted);margin-bottom:1rem;">
            Escolha qual contato manter como <strong>principal</strong>. Os dados faltantes serão preenchidos com as informações do duplicado, e todos os processos, chamados e formulários serão transferidos.
        </p>
    </div>
</div>

<?php if (empty($dupsCpf) && empty($dupsNome)): ?>
    <div class="card"><div class="card-body" style="text-align:center;padding:3rem;">
        <div style="font-size:2rem;margin-bottom:.5rem;">✅</div>
        <h3>Nenhum duplicado encontrado!</h3>
    </div></div>
<?php endif; ?>

<?php
// Renderizar grupos de duplicados
function renderMergeGroup($pdo, $ids, $type, $matchValue) {
    $idArr = explode(',', $ids);
    if (count($idArr) < 2) return;

    $placeholders = implode(',', array_fill(0, count($idArr), '?'));
    $stmt = $pdo->prepare("SELECT c.*,
        (SELECT COUNT(*) FROM cases WHERE client_id = c.id) as total_processos,
        (SELECT COUNT(*) FROM pipeline_leads WHERE client_id = c.id) as total_leads,
        (SELECT COUNT(*) FROM contacts WHERE client_id = c.id) as total_contatos
        FROM clients c WHERE c.id IN ($placeholders) ORDER BY c.id");
    $stmt->execute($idArr);
    $clients = $stmt->fetchAll();

    if (count($clients) < 2) return;

    $fields = array(
        'cpf' => 'CPF', 'rg' => 'RG', 'phone' => 'Telefone', 'phone2' => 'Tel 2',
        'email' => 'E-mail', 'birth_date' => 'Nascimento', 'address_street' => 'Endereço',
        'address_city' => 'Cidade', 'address_state' => 'UF', 'profession' => 'Profissão',
        'marital_status' => 'Estado Civil', 'source' => 'Origem',
    );

    // Mostrar pares (primeiro com cada subsequente)
    for ($i = 1; $i < count($clients); $i++) {
        $a = $clients[0];
        $b = $clients[$i];
        ?>
        <div class="merge-card">
            <div style="margin-bottom:.5rem;">
                <span class="dup-type <?= $type === 'cpf' ? 'dup-cpf' : ($type === 'tel' ? 'dup-tel' : 'dup-nome') ?>">
                    <?= $type === 'cpf' ? 'CPF: ' . e($matchValue) : ($type === 'tel' ? 'Telefone igual' : 'Nome similar') ?>
                </span>
            </div>
            <div class="merge-pair">
                <!-- Cliente A -->
                <div class="merge-client">
                    <div style="font-weight:700;font-size:.9rem;color:var(--petrol-900);margin-bottom:.4rem;">
                        #<?= $a['id'] ?> — <?= e($a['name']) ?>
                    </div>
                    <?php foreach ($fields as $fk => $fl): ?>
                    <div class="merge-field">
                        <span class="label"><?= $fl ?></span>
                        <span class="val <?= empty($a[$fk]) ? 'empty' : '' ?>"><?= !empty($a[$fk]) ? e($a[$fk]) : '—' ?></span>
                    </div>
                    <?php endforeach; ?>
                    <div style="margin-top:.5rem;font-size:.72rem;color:var(--text-muted);">
                        📁 <?= $a['total_processos'] ?> processo<?= $a['total_processos'] != 1 ? 's' : '' ?> ·
                        📊 <?= $a['total_leads'] ?> lead<?= $a['total_leads'] != 1 ? 's' : '' ?> ·
                        💬 <?= $a['total_contatos'] ?> contato<?= $a['total_contatos'] != 1 ? 's' : '' ?>
                    </div>
                </div>

                <div class="merge-arrow">⇄</div>

                <!-- Cliente B -->
                <div class="merge-client">
                    <div style="font-weight:700;font-size:.9rem;color:var(--petrol-900);margin-bottom:.4rem;">
                        #<?= $b['id'] ?> — <?= e($b['name']) ?>
                    </div>
                    <?php foreach ($fields as $fk => $fl): ?>
                    <div class="merge-field">
                        <span class="label"><?= $fl ?></span>
                        <span class="val <?= empty($b[$fk]) ? 'empty' : '' ?>"><?= !empty($b[$fk]) ? e($b[$fk]) : '—' ?></span>
                    </div>
                    <?php endforeach; ?>
                    <div style="margin-top:.5rem;font-size:.72rem;color:var(--text-muted);">
                        📁 <?= $b['total_processos'] ?> processo<?= $b['total_processos'] != 1 ? 's' : '' ?> ·
                        📊 <?= $b['total_leads'] ?> lead<?= $b['total_leads'] != 1 ? 's' : '' ?> ·
                        💬 <?= $b['total_contatos'] ?> contato<?= $b['total_contatos'] != 1 ? 's' : '' ?>
                    </div>
                </div>
            </div>

            <!-- Botões de ação -->
            <div class="merge-actions">
                <form method="POST" onsubmit="return confirm('Manter #<?= $a['id'] ?> (<?= e(addslashes($a['name'])) ?>) e mesclar #<?= $b['id'] ?> nele?');">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="merge">
                    <input type="hidden" name="keep_id" value="<?= $a['id'] ?>">
                    <input type="hidden" name="merge_id" value="<?= $b['id'] ?>">
                    <button type="submit" class="btn btn-primary btn-sm">← Manter #<?= $a['id'] ?></button>
                </form>
                <form method="POST" onsubmit="return confirm('Manter #<?= $b['id'] ?> (<?= e(addslashes($b['name'])) ?>) e mesclar #<?= $a['id'] ?> nele?');">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="merge">
                    <input type="hidden" name="keep_id" value="<?= $b['id'] ?>">
                    <input type="hidden" name="merge_id" value="<?= $a['id'] ?>">
                    <button type="submit" class="btn btn-primary btn-sm">Manter #<?= $b['id'] ?> →</button>
                </form>
            </div>
        </div>
        <?php
    }
}

// Duplicados por CPF
foreach ($dupsCpf as $dup) {
    renderMergeGroup($pdo, $dup['ids'], 'cpf', $dup['cpf']);
}

// Duplicados por Nome (excluir os que já apareceram por CPF)
$shownIds = array();
foreach ($dupsCpf as $dup) {
    foreach (explode(',', $dup['ids']) as $id) $shownIds[$id] = true;
}
foreach ($dupsNome as $dup) {
    $ids = explode(',', $dup['ids']);
    $allShown = true;
    foreach ($ids as $id) { if (!isset($shownIds[$id])) { $allShown = false; break; } }
    if ($allShown) continue;
    renderMergeGroup($pdo, $dup['ids'], 'nome', '');
    foreach ($ids as $id) $shownIds[$id] = true;
}

// Duplicados por Telefone (excluir os que já apareceram)
foreach ($dupsTel as $dup) {
    $ids = explode(',', $dup['ids']);
    $allShown = true;
    foreach ($ids as $id) { if (!isset($shownIds[$id])) { $allShown = false; break; } }
    if ($allShown) continue;
    renderMergeGroup($pdo, $dup['ids'], 'tel', $dup['tel_norm']);
    foreach ($ids as $id) $shownIds[$id] = true;
}
?>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
