<?php
/**
 * Ferreira & Sá Hub — Importar Processos via CSV
 * Vincula processos a clientes existentes pelo nome ou CPF
 */

require_once __DIR__ . '/../../core/middleware.php';
require_access('crm');

$pageTitle = 'Importar Processos';
$pdo = db();

$result = null;
$preview = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    if (!validate_csrf()) {
        flash_set('error', 'Token inválido.');
        redirect(module_url('crm', 'importar_processos.php'));
    }

    $file = $_FILES['csv_file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        flash_set('error', 'Erro no upload.');
        redirect(module_url('crm', 'importar_processos.php'));
    }

    $content = file_get_contents($file['tmp_name']);
    $encoding = mb_detect_encoding($content, array('UTF-8', 'ISO-8859-1', 'Windows-1252'), true);
    if ($encoding && $encoding !== 'UTF-8') {
        $content = mb_convert_encoding($content, 'UTF-8', $encoding);
    }

    $firstLine = strtok($content, "\n");
    $sep = (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';

    $lines = explode("\n", $content);
    $header = str_getcsv(array_shift($lines), $sep);
    $header = array_map('trim', $header);
    $header = array_map('mb_strtolower', $header);

    // Mapear colunas
    $fieldMap = array(
        // Identificação do cliente
        'cliente' => 'client_name', 'nome' => 'client_name', 'nome do cliente' => 'client_name', 'parte' => 'client_name', 'name' => 'client_name',
        'cpf' => 'client_cpf', 'cpf do cliente' => 'client_cpf', 'cpf/cnpj' => 'client_cpf',
        // Dados do processo
        'numero' => 'case_number', 'numero do processo' => 'case_number', 'nº processo' => 'case_number', 'processo' => 'case_number', 'n processo' => 'case_number', 'num_processo' => 'case_number',
        'tipo' => 'case_type', 'tipo de acao' => 'case_type', 'tipo de ação' => 'case_type', 'area' => 'case_type', 'materia' => 'case_type', 'matéria' => 'case_type',
        'titulo' => 'title', 'título' => 'title', 'assunto' => 'title', 'descricao' => 'title', 'descrição' => 'title',
        'vara' => 'court', 'tribunal' => 'court', 'foro' => 'court', 'orgao' => 'court', 'órgão' => 'court', 'comarca' => 'court',
        'status' => 'status', 'situacao' => 'status', 'situação' => 'status',
        'prioridade' => 'priority',
        'responsavel' => 'responsible', 'responsável' => 'responsible', 'advogado' => 'responsible',
        'prazo' => 'deadline', 'data limite' => 'deadline', 'vencimento' => 'deadline',
        'observacao' => 'notes', 'observação' => 'notes', 'obs' => 'notes', 'notas' => 'notes',
        'pasta' => 'drive_folder_url', 'link pasta' => 'drive_folder_url', 'drive' => 'drive_folder_url', 'url pasta' => 'drive_folder_url',
    );

    $colIndex = array();
    foreach ($header as $i => $col) {
        $col = trim(strtolower($col));
        if (isset($fieldMap[$col])) {
            $colIndex[$fieldMap[$col]] = $i;
        }
    }

    if (!isset($colIndex['client_name']) && !isset($colIndex['client_cpf'])) {
        flash_set('error', 'Coluna "Cliente" ou "CPF" não encontrada. Colunas: ' . implode(', ', $header));
        redirect(module_url('crm', 'importar_processos.php'));
    }

    $action = isset($_POST['action']) ? $_POST['action'] : 'preview';

    // Carregar clientes existentes para match
    $clientsByName = array();
    $clientsByCpf = array();
    $allClients = $pdo->query("SELECT id, name, cpf FROM clients")->fetchAll();
    foreach ($allClients as $c) {
        $clientsByName[mb_strtolower(trim($c['name']))] = (int)$c['id'];
        if ($c['cpf']) {
            $cpfClean = preg_replace('/\D/', '', $c['cpf']);
            $clientsByCpf[$cpfClean] = (int)$c['id'];
        }
    }

    // Carregar users para match de responsável
    $usersByName = array();
    $allUsers = $pdo->query("SELECT id, name FROM users WHERE is_active = 1")->fetchAll();
    foreach ($allUsers as $u) {
        $usersByName[mb_strtolower(trim($u['name']))] = (int)$u['id'];
        // Também primeiro nome
        $primeiro = mb_strtolower(explode(' ', trim($u['name']))[0]);
        $usersByName[$primeiro] = (int)$u['id'];
    }

    $rows = array();
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        $cols = str_getcsv($line, $sep);
        $row = array();
        foreach ($colIndex as $field => $idx) {
            $row[$field] = isset($cols[$idx]) ? trim($cols[$idx]) : '';
        }

        // Tentar vincular ao cliente
        $row['_client_id'] = null;
        $row['_client_match'] = 'Não encontrado';

        if (!empty($row['client_cpf'])) {
            $cpf = preg_replace('/\D/', '', $row['client_cpf']);
            if (isset($clientsByCpf[$cpf])) {
                $row['_client_id'] = $clientsByCpf[$cpf];
                $row['_client_match'] = 'CPF';
            }
        }
        if (!$row['_client_id'] && !empty($row['client_name'])) {
            $nameLower = mb_strtolower(trim($row['client_name']));
            if (isset($clientsByName[$nameLower])) {
                $row['_client_id'] = $clientsByName[$nameLower];
                $row['_client_match'] = 'Nome exato';
            }
        }

        // Match responsável
        $row['_responsible_id'] = null;
        if (!empty($row['responsible'])) {
            $respLower = mb_strtolower(trim($row['responsible']));
            if (isset($usersByName[$respLower])) {
                $row['_responsible_id'] = $usersByName[$respLower];
            }
        }

        if (!empty($row['client_name']) || !empty($row['case_number'])) {
            $rows[] = $row;
        }
    }

    if ($action === 'preview') {
        $preview = array('mapped' => $colIndex, 'rows' => array_slice($rows, 0, 15), 'total' => count($rows));
        $preview['matched'] = 0;
        $preview['unmatched'] = 0;
        foreach ($rows as $r) {
            if ($r['_client_id']) $preview['matched']++;
            else $preview['unmatched']++;
        }
    } elseif ($action === 'importar') {
        $imported = 0;
        $skipped = 0;
        $clientsCreated = 0;

        foreach ($rows as $row) {
            $clientId = $row['_client_id'];

            // Se não encontrou cliente, criar novo
            if (!$clientId && !empty($row['client_name'])) {
                $pdo->prepare("INSERT INTO clients (name, cpf, source, created_at) VALUES (?, ?, 'importacao', NOW())")
                    ->execute(array($row['client_name'], !empty($row['client_cpf']) ? $row['client_cpf'] : null));
                $clientId = (int)$pdo->lastInsertId();
                $clientsCreated++;
                // Atualizar cache
                $clientsByName[mb_strtolower(trim($row['client_name']))] = $clientId;
            }

            if (!$clientId) { $skipped++; continue; }

            // Verificar duplicata de processo pelo número
            if (!empty($row['case_number'])) {
                $dup = $pdo->prepare("SELECT id FROM cases WHERE case_number = ?");
                $dup->execute(array($row['case_number']));
                if ($dup->fetch()) { $skipped++; continue; }
            }

            // Formatar deadline
            $deadline = null;
            if (!empty($row['deadline'])) {
                if (preg_match('/^(\d{2})[\/\-](\d{2})[\/\-](\d{4})$/', $row['deadline'], $m)) {
                    $deadline = $m[3] . '-' . $m[2] . '-' . $m[1];
                } elseif (preg_match('/^\d{4}/', $row['deadline'])) {
                    $deadline = $row['deadline'];
                }
            }

            // Mapear status
            $status = 'ativo';
            if (!empty($row['status'])) {
                $sLower = mb_strtolower($row['status']);
                $statusMap = array(
                    'ativo' => 'ativo', 'em andamento' => 'em_andamento', 'aguardando' => 'aguardando_docs',
                    'concluido' => 'concluido', 'concluído' => 'concluido', 'arquivado' => 'arquivado',
                    'suspenso' => 'suspenso', 'cancelado' => 'arquivado',
                );
                foreach ($statusMap as $key => $val) {
                    if (strpos($sLower, $key) !== false) { $status = $val; break; }
                }
            }

            $title = !empty($row['title']) ? $row['title'] : (!empty($row['case_type']) ? $row['case_type'] . ' — ' . $row['client_name'] : 'Processo — ' . $row['client_name']);

            $pdo->prepare(
                "INSERT INTO cases (client_id, title, case_type, case_number, court, status, priority,
                 responsible_user_id, deadline, drive_folder_url, notes, opened_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
            )->execute(array(
                $clientId,
                $title,
                !empty($row['case_type']) ? $row['case_type'] : 'outro',
                !empty($row['case_number']) ? $row['case_number'] : null,
                !empty($row['court']) ? $row['court'] : null,
                $status,
                !empty($row['priority']) ? $row['priority'] : 'normal',
                $row['_responsible_id'],
                $deadline,
                !empty($row['drive_folder_url']) ? $row['drive_folder_url'] : null,
                !empty($row['notes']) ? $row['notes'] : null,
            ));
            $imported++;
        }

        $result = array('imported' => $imported, 'skipped' => $skipped, 'clients_created' => $clientsCreated, 'total' => count($rows));
        audit_log('cases_imported', 'case', null, "importados: $imported, clientes criados: $clientsCreated");
        notify_admins('Importação de processos', "$imported processos importados ($clientsCreated novos clientes criados).", 'sucesso', url('modules/operacional/'), '📥');
    }
}

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.import-steps { display:flex; gap:1rem; margin-bottom:1.5rem; }
.import-step { flex:1; background:var(--bg-card); border-radius:var(--radius-lg); border:1px solid var(--border); padding:1rem; text-align:center; }
.import-step .num { font-size:1.5rem; font-weight:800; color:var(--rose); }
.import-step .lbl { font-size:.78rem; color:var(--text-muted); margin-top:.25rem; }
.import-step.active { border-color:var(--rose); background:rgba(215,171,144,.05); }

.preview-table { width:100%; border-collapse:collapse; font-size:.75rem; margin-top:1rem; }
.preview-table th { background:var(--petrol-900); color:#fff; padding:.45rem .5rem; text-align:left; font-size:.68rem; text-transform:uppercase; }
.preview-table td { padding:.4rem .5rem; border-bottom:1px solid var(--border); }
.preview-table tr:hover { background:rgba(215,171,144,.04); }
.match-ok { color:var(--success); font-weight:600; }
.match-fail { color:var(--danger); font-weight:600; }

.result-box { text-align:center; padding:2rem; }
.result-box .big { font-size:3rem; margin-bottom:.5rem; }
.result-stats { display:flex; gap:1.5rem; justify-content:center; margin-top:1rem; flex-wrap:wrap; }
.result-stat { text-align:center; }
.result-stat .val { font-size:1.5rem; font-weight:800; }
.result-stat .lbl { font-size:.72rem; color:var(--text-muted); }
</style>

<?php if ($result): ?>
<div class="card">
    <div class="card-body result-box">
        <div class="big">✅</div>
        <h3>Importação de processos concluída!</h3>
        <div class="result-stats">
            <div class="result-stat"><div class="val" style="color:var(--success);"><?= $result['imported'] ?></div><div class="lbl">Processos importados</div></div>
            <div class="result-stat"><div class="val" style="color:var(--info);"><?= $result['clients_created'] ?></div><div class="lbl">Novos clientes criados</div></div>
            <div class="result-stat"><div class="val" style="color:var(--warning);"><?= $result['skipped'] ?></div><div class="lbl">Duplicados/ignorados</div></div>
        </div>
        <div style="margin-top:1.5rem;">
            <a href="<?= module_url('operacional') ?>" class="btn btn-primary">Ver Operacional</a>
            <a href="<?= module_url('crm') ?>" class="btn btn-outline" style="margin-left:.5rem;">Ver Clientes</a>
        </div>
    </div>
</div>

<?php elseif ($preview): ?>
<div class="card">
    <div class="card-header"><h3>Pré-visualização — <?= $preview['total'] ?> processos</h3></div>
    <div class="card-body">
        <div style="display:flex;gap:1rem;margin-bottom:1rem;">
            <div style="background:rgba(5,150,105,.1);padding:.5rem 1rem;border-radius:var(--radius);font-size:.82rem;">
                <strong style="color:var(--success);"><?= $preview['matched'] ?></strong> vinculados a clientes
            </div>
            <div style="background:rgba(239,68,68,.1);padding:.5rem 1rem;border-radius:var(--radius);font-size:.82rem;">
                <strong style="color:var(--danger);"><?= $preview['unmatched'] ?></strong> sem cliente (serão criados)
            </div>
        </div>

        <div style="overflow-x:auto;">
        <table class="preview-table">
            <thead><tr>
                <th>Cliente</th>
                <th>Match</th>
                <th>Nº Processo</th>
                <th>Tipo</th>
                <th>Vara/Tribunal</th>
                <th>Status</th>
                <th>Responsável</th>
            </tr></thead>
            <tbody>
                <?php foreach ($preview['rows'] as $row): ?>
                <tr>
                    <td><?= e(isset($row['client_name']) ? $row['client_name'] : '—') ?></td>
                    <td class="<?= $row['_client_id'] ? 'match-ok' : 'match-fail' ?>"><?= $row['_client_match'] ?></td>
                    <td><?= e(isset($row['case_number']) ? $row['case_number'] : '—') ?></td>
                    <td><?= e(isset($row['case_type']) ? $row['case_type'] : '—') ?></td>
                    <td><?= e(isset($row['court']) ? $row['court'] : '—') ?></td>
                    <td><?= e(isset($row['status']) ? $row['status'] : '—') ?></td>
                    <td><?= e(isset($row['responsible']) ? $row['responsible'] : '—') ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if ($preview['total'] > 15): ?>
                <tr><td colspan="7" style="text-align:center;color:var(--text-muted);">... e mais <?= $preview['total'] - 15 ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        </div>

        <div id="confirmImport" style="margin-top:1.5rem;text-align:center;">
            <p style="font-size:.85rem;color:var(--petrol-900);font-weight:600;margin-bottom:.75rem;">
                <?= $preview['unmatched'] ?> clientes serão criados automaticamente.
            </p>
            <form method="POST" enctype="multipart/form-data">
                <?= csrf_input() ?>
                <input type="file" name="csv_file" accept=".csv,.txt" required style="margin-bottom:.75rem;">
                <input type="hidden" name="action" value="importar">
                <p style="font-size:.72rem;color:var(--text-muted);margin-bottom:.75rem;">Selecione o mesmo arquivo para confirmar.</p>
                <a href="<?= module_url('crm', 'importar_processos.php') ?>" class="btn btn-outline">Cancelar</a>
                <button type="submit" class="btn btn-primary" style="margin-left:.5rem;">Confirmar Importação</button>
            </form>
        </div>
    </div>
</div>

<?php else: ?>
<div class="import-steps">
    <div class="import-step active"><div class="num">1</div><div class="lbl">Exporte processos do LegalOne em CSV</div></div>
    <div class="import-step"><div class="num">2</div><div class="lbl">Upload aqui</div></div>
    <div class="import-step"><div class="num">3</div><div class="lbl">Confira e importe</div></div>
</div>

<div class="card">
    <div class="card-header"><h3>Upload do Arquivo CSV de Processos</h3></div>
    <div class="card-body">
        <form method="POST" enctype="multipart/form-data">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="preview">
            <div class="form-group">
                <label class="form-label">Arquivo CSV</label>
                <input type="file" name="csv_file" accept=".csv,.txt" required class="form-input">
                <small style="color:var(--text-muted);font-size:.75rem;">
                    Excel: Arquivo → Salvar como → CSV. O sistema vincula automaticamente ao cliente pelo nome ou CPF.
                </small>
            </div>
            <button type="submit" class="btn btn-primary" style="margin-top:1rem;">Pré-visualizar</button>
        </form>

        <div style="margin-top:2rem;padding:1.25rem;background:var(--bg);border-radius:var(--radius);font-size:.82rem;">
            <h4 style="margin-bottom:.75rem;color:var(--petrol-900);">Colunas aceitas:</h4>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:.25rem;font-size:.75rem;">
                <span><strong>Cliente/Nome</strong> (obrigatório)</span>
                <span><strong>CPF</strong> (para vincular)</span>
                <span><strong>Nº Processo</strong></span>
                <span><strong>Tipo de Ação</strong></span>
                <span><strong>Vara/Tribunal</strong></span>
                <span><strong>Status</strong></span>
                <span><strong>Prioridade</strong></span>
                <span><strong>Responsável</strong></span>
                <span><strong>Prazo</strong></span>
                <span><strong>Link Pasta</strong> (Drive)</span>
                <span><strong>Observação</strong></span>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
