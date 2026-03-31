<?php
/**
 * Ferreira & Sá Hub — Importar CSV
 * Suporta importação para Operacional (cases) e Pipeline (pipeline_leads)
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
if (!has_min_role('gestao')) { flash_set('error', 'Sem permissão.'); redirect(url('modules/dashboard/')); }

$pageTitle = 'Importar CSV';
$pdo = db();

$users = $pdo->query("SELECT id, name FROM users WHERE is_active = 1 ORDER BY name")->fetchAll();
$userMap = array();
foreach ($users as $u) {
    $userMap[mb_strtolower(trim($u['name']))] = (int)$u['id'];
    // Também mapear pelo primeiro nome
    $firstName = mb_strtolower(trim(explode(' ', $u['name'])[0]));
    if (!isset($userMap[$firstName])) $userMap[$firstName] = (int)$u['id'];
    // Iniciais comuns
    $initials = mb_strtoupper(mb_substr($u['name'], 0, 2));
    if (!isset($userMap[mb_strtolower($initials)])) $userMap[mb_strtolower($initials)] = (int)$u['id'];
}

$step = isset($_POST['step']) ? $_POST['step'] : (isset($_GET['step']) ? $_GET['step'] : '1');
$destino = isset($_POST['destino']) ? $_POST['destino'] : (isset($_GET['destino']) ? $_GET['destino'] : 'operacional');
$resultado = null;
$uploadError = '';

// Diretório para arquivos temporários
$tmpDir = __DIR__ . '/../../uploads';
if (!is_dir($tmpDir)) @mkdir($tmpDir, 0755, true);

// ─── STEP 2: Preview do CSV ──────────────────────────
if ($step === '2' && isset($_FILES['csv_file'])) {
    $file = $_FILES['csv_file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errorMessages = array(1=>'Arquivo excede o limite do servidor',2=>'Arquivo excede o limite do formulário',3=>'Upload incompleto',4=>'Nenhum arquivo enviado',6=>'Sem pasta temporária',7=>'Falha ao gravar');
        $uploadError = isset($errorMessages[$file['error']]) ? $errorMessages[$file['error']] : 'Erro ' . $file['error'];
        $step = '1';
    } elseif ($file['size'] === 0) {
        $uploadError = 'Arquivo vazio.';
        $step = '1';
    } else {
        // Detectar separador
        $content = file_get_contents($file['tmp_name']);
        if (!$content) {
            $uploadError = 'Não foi possível ler o arquivo.';
            $step = '1';
        } else {
            // Tentar corrigir encoding
            if (!mb_check_encoding($content, 'UTF-8')) {
                $content = mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1,Windows-1252');
            }
            $firstLine = strtok($content, "\n");
            $sep = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';

            $tmpPath = $tmpDir . '/import_' . session_id() . '_' . time() . '.csv';
            file_put_contents($tmpPath, $content);

            // Ler primeiras 12 linhas para preview
            $handle = fopen($tmpPath, 'r');
            $preview = array();
            $lineNum = 0;
            while (($row = fgetcsv($handle, 0, $sep)) !== false && $lineNum < 5) {
                $preview[] = $row;
                $lineNum++;
            }
            fclose($handle);

            // Contar total
            $totalLines = count(file($tmpPath)) - 1;

            $_SESSION['import_file'] = $tmpPath;
            $_SESSION['import_sep'] = $sep;
            $_SESSION['import_destino'] = $destino;
        }
    }
}

// ─── STEP 3: Executar importação ─────────────────────
if ($step === '3' && validate_csrf()) {
    $tmpPath = $_SESSION['import_file'] ?? '';
    $sep = $_SESSION['import_sep'] ?? ',';
    $destino = $_SESSION['import_destino'] ?? 'operacional';

    if (!$tmpPath || !file_exists($tmpPath)) {
        flash_set('error', 'Arquivo não encontrado. Faça upload novamente.');
        redirect(module_url('planilha', 'importar.php'));
    }

    // Mapeamento de colunas (índices base-0)
    $colTitle = isset($_POST['col_title']) ? (int)$_POST['col_title'] : -1;
    $colDate = isset($_POST['col_date']) ? (int)$_POST['col_date'] : -1;
    $colResp = isset($_POST['col_resp']) ? (int)$_POST['col_resp'] : -1;
    $colDrive = isset($_POST['col_drive']) ? (int)$_POST['col_drive'] : -1;
    $colObs = isset($_POST['col_obs']) ? (int)$_POST['col_obs'] : -1;
    $colPrazo = isset($_POST['col_prazo']) ? (int)$_POST['col_prazo'] : -1;
    $colPhone = isset($_POST['col_phone']) ? (int)$_POST['col_phone'] : -1;
    $colType = isset($_POST['col_type']) ? (int)$_POST['col_type'] : -1;
    $colStatus = isset($_POST['col_status']) ? (int)$_POST['col_status'] : -1;
    $skipHeader = isset($_POST['skip_header']) ? true : false;
    $defaultStatus = isset($_POST['default_status']) ? $_POST['default_status'] : 'em_elaboracao';

    $handle = fopen($tmpPath, 'r');
    $inserted = 0;
    $skipped = 0;
    $errors = array();
    $lineNum = 0;

    while (($row = fgetcsv($handle, 0, $sep)) !== false) {
        $lineNum++;
        if ($skipHeader && $lineNum === 1) continue;

        $title = ($colTitle >= 0 && isset($row[$colTitle])) ? trim($row[$colTitle]) : '';
        if (!$title) { $skipped++; continue; }

        // Parsear data
        $dateStr = ($colDate >= 0 && isset($row[$colDate])) ? trim($row[$colDate]) : '';
        $parsedDate = null;
        if ($dateStr) {
            // Formato ISO (2025-01-30T03:00:00.000Z)
            if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $dateStr, $dm)) {
                $parsedDate = $dm[1];
            }
            // Formato BR (30/01/2025)
            elseif (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})/', $dateStr, $dm)) {
                $parsedDate = $dm[3] . '-' . $dm[2] . '-' . $dm[1];
            }
        }

        // Prazo
        $prazoStr = ($colPrazo >= 0 && isset($row[$colPrazo])) ? trim($row[$colPrazo]) : '';
        $parsedPrazo = null;
        if ($prazoStr && preg_match('/^(\d{4}-\d{2}-\d{2})/', $prazoStr, $dm)) {
            $parsedPrazo = $dm[1];
        } elseif ($prazoStr && preg_match('/^(\d{2})\/(\d{2})\/(\d{4})/', $prazoStr, $dm)) {
            $parsedPrazo = $dm[3] . '-' . $dm[2] . '-' . $dm[1];
        }

        // Responsável
        $respStr = ($colResp >= 0 && isset($row[$colResp])) ? trim($row[$colResp]) : '';
        $respId = null;
        if ($respStr) {
            $respLower = mb_strtolower(trim($respStr));
            if (isset($userMap[$respLower])) $respId = $userMap[$respLower];
        }

        // Drive
        $driveUrl = ($colDrive >= 0 && isset($row[$colDrive])) ? trim($row[$colDrive]) : '';
        if ($driveUrl && strpos($driveUrl, 'http') !== 0) $driveUrl = '';

        // Observações
        $obs = ($colObs >= 0 && isset($row[$colObs])) ? trim($row[$colObs]) : '';

        // Telefone / Tipo / Status
        $phone = ($colPhone >= 0 && isset($row[$colPhone])) ? trim($row[$colPhone]) : '';
        $caseType = ($colType >= 0 && isset($row[$colType])) ? trim($row[$colType]) : '';
        $status = ($colStatus >= 0 && isset($row[$colStatus])) ? trim($row[$colStatus]) : $defaultStatus;

        // Extrair tipo da ação do título (se não tem coluna dedicada)
        if (!$caseType && strpos($title, ' x ') !== false) {
            $parts = explode(' x ', $title, 2);
            if (isset($parts[1])) $caseType = trim($parts[1]);
        }

        try {
            if ($destino === 'operacional') {
                // Buscar ou criar client pelo nome
                $clientName = $title;
                if (strpos($title, ' x ') !== false) {
                    $clientName = trim(explode(' x ', $title)[0]);
                } elseif (strpos($title, ' - ') !== false) {
                    $clientName = trim(explode(' - ', $title)[0]);
                }

                // Verificar se já existe case com mesmo título
                $exists = $pdo->prepare("SELECT id FROM cases WHERE title = ?");
                $exists->execute(array($title));
                if ($exists->fetch()) { $skipped++; continue; }

                // Buscar client
                $clientStmt = $pdo->prepare("SELECT id FROM clients WHERE name LIKE ? LIMIT 1");
                $clientStmt->execute(array('%' . $clientName . '%'));
                $clientRow = $clientStmt->fetch();
                $clientId = $clientRow ? (int)$clientRow['id'] : null;

                // Se não encontrou, criar client
                if (!$clientId) {
                    $pdo->prepare("INSERT INTO clients (name, source, client_status, created_at) VALUES (?, 'outro', 'ativo', ?)")
                        ->execute(array($clientName, $parsedDate ?: date('Y-m-d H:i:s')));
                    $clientId = (int)$pdo->lastInsertId();
                }

                $pdo->prepare(
                    "INSERT INTO cases (client_id, title, case_type, status, responsible_user_id, drive_folder_url, deadline, notes, opened_at, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)"
                )->execute(array(
                    $clientId, $title, $caseType ?: 'outro', $defaultStatus,
                    $respId, $driveUrl ?: null, $parsedPrazo,
                    $obs ?: null, $parsedDate, $parsedDate ?: date('Y-m-d H:i:s')
                ));
                $inserted++;

            } elseif ($destino === 'pipeline') {
                // Verificar duplicata
                $exists = $pdo->prepare("SELECT id FROM pipeline_leads WHERE name = ? AND stage NOT IN ('finalizado','cancelado','perdido')");
                $exists->execute(array($title));
                if ($exists->fetch()) { $skipped++; continue; }

                // Buscar client
                $clientName = $title;
                if (strpos($title, ' x ') !== false) {
                    $clientName = trim(explode(' x ', $title)[0]);
                }
                $clientStmt = $pdo->prepare("SELECT id FROM clients WHERE name LIKE ? LIMIT 1");
                $clientStmt->execute(array('%' . $clientName . '%'));
                $clientRow = $clientStmt->fetch();
                $clientId = $clientRow ? (int)$clientRow['id'] : null;

                $pdo->prepare(
                    "INSERT INTO pipeline_leads (client_id, name, phone, stage, case_type, assigned_to, notes, source, created_at) VALUES (?,?,?,?,?,?,?,'outro',?)"
                )->execute(array(
                    $clientId, $title, $phone ?: null, $defaultStatus,
                    $caseType ?: null, $respId, $obs ?: null,
                    $parsedDate ?: date('Y-m-d H:i:s')
                ));
                $inserted++;
            }
        } catch (Exception $e) {
            $errors[] = "Linha $lineNum: " . mb_substr($e->getMessage(), 0, 100);
            if (count($errors) > 10) break;
        }
    }
    fclose($handle);
    @unlink($tmpPath);
    unset($_SESSION['import_file'], $_SESSION['import_sep'], $_SESSION['import_destino']);

    $resultado = array('inserted' => $inserted, 'skipped' => $skipped, 'errors' => $errors);
}

require_once __DIR__ . '/../../templates/layout_start.php';
?>

<div class="page-header">
    <h1>Importar CSV</h1>
    <a href="<?= module_url('planilha') ?>" class="btn btn-secondary btn-sm">Voltar</a>
</div>

<?php if ($resultado): ?>
<!-- STEP 3: Resultado -->
<div class="card" style="margin-bottom:1rem;">
    <div class="card-body" style="text-align:center;padding:2rem;">
        <div style="font-size:2rem;margin-bottom:.5rem;"><?= $resultado['inserted'] > 0 ? '✅' : '⚠️' ?></div>
        <h2 style="font-size:1.1rem;margin-bottom:.5rem;">Importação concluída</h2>
        <div style="display:flex;gap:1.5rem;justify-content:center;margin:1rem 0;">
            <div><div style="font-size:1.5rem;font-weight:800;color:var(--success);"><?= $resultado['inserted'] ?></div><div style="font-size:.75rem;color:var(--text-muted);">Inseridos</div></div>
            <div><div style="font-size:1.5rem;font-weight:800;color:var(--text-muted);"><?= $resultado['skipped'] ?></div><div style="font-size:.75rem;color:var(--text-muted);">Ignorados (duplicatas/vazios)</div></div>
            <div><div style="font-size:1.5rem;font-weight:800;color:<?= count($resultado['errors']) ? 'var(--danger)' : 'var(--text-muted)' ?>;"><?= count($resultado['errors']) ?></div><div style="font-size:.75rem;color:var(--text-muted);">Erros</div></div>
        </div>
        <?php if ($resultado['errors']): ?>
        <div style="text-align:left;background:#fef2f2;border-radius:8px;padding:.75rem;margin-top:.75rem;font-size:.75rem;color:#dc2626;">
            <?php foreach ($resultado['errors'] as $err): ?>
                <div><?= e($err) ?></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <div style="margin-top:1rem;">
            <a href="<?= module_url('planilha', 'importar.php') ?>" class="btn btn-primary btn-sm">Nova importação</a>
            <a href="<?= module_url($destino) ?>" class="btn btn-secondary btn-sm">Ver <?= $destino === 'operacional' ? 'Operacional' : 'Pipeline' ?></a>
        </div>
    </div>
</div>

<?php elseif ($step === '2' && isset($preview)): ?>
<!-- STEP 2: Preview + Mapeamento -->
<div class="card" style="margin-bottom:1rem;">
    <div class="card-header"><strong>Preview do arquivo</strong> — <?= $totalLines ?> linhas | Separador: "<?= $sep === ';' ? ';' : ',' ?>" (mostrando 5 primeiras)</div>
    <div class="card-body" style="overflow-x:auto;max-height:220px;overflow-y:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:.72rem;">
            <thead>
                <tr>
                    <?php if (!empty($preview[0])): ?>
                        <?php foreach ($preview[0] as $i => $col): ?>
                            <th style="border:1px solid #ddd;padding:4px 6px;background:#f0f0f0;white-space:nowrap;">Col <?= $i ?></th>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($preview as $ri => $row): ?>
                <tr style="<?= $ri === 0 ? 'background:#fff8e1;font-weight:600;' : '' ?>">
                    <?php foreach ($row as $cell): ?>
                        <td style="border:1px solid #ddd;padding:3px 6px;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= e($cell) ?>"><?= e(mb_substr($cell, 0, 40)) ?></td>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="step" value="3">
    <input type="hidden" name="destino" value="<?= e($destino) ?>">

    <div class="card">
        <div class="card-header"><strong>Mapeamento de colunas</strong> — Informe qual coluna corresponde a cada campo</div>
        <div class="card-body">
            <p style="font-size:.78rem;color:var(--text-muted);margin-bottom:1rem;">Olhe o preview acima e informe o número da coluna (Col 0, Col 1, etc.). Deixe -1 para ignorar.</p>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:.75rem;">
                <div class="form-group">
                    <label style="font-size:.78rem;font-weight:700;">Título/Nome do caso *</label>
                    <input type="number" name="col_title" value="1" class="form-input" min="-1" required>
                </div>
                <div class="form-group">
                    <label style="font-size:.78rem;font-weight:700;">Data do cadastro</label>
                    <input type="number" name="col_date" value="2" class="form-input" min="-1">
                </div>
                <div class="form-group">
                    <label style="font-size:.78rem;font-weight:700;">Prazo</label>
                    <input type="number" name="col_prazo" value="3" class="form-input" min="-1">
                </div>
                <div class="form-group">
                    <label style="font-size:.78rem;font-weight:700;">Executante/Responsável</label>
                    <input type="number" name="col_resp" value="5" class="form-input" min="-1">
                </div>
                <div class="form-group">
                    <label style="font-size:.78rem;font-weight:700;">Link Drive</label>
                    <input type="number" name="col_drive" value="6" class="form-input" min="-1">
                </div>
                <div class="form-group">
                    <label style="font-size:.78rem;font-weight:700;">Observações</label>
                    <input type="number" name="col_obs" value="7" class="form-input" min="-1">
                </div>
                <?php if ($destino === 'pipeline'): ?>
                <div class="form-group">
                    <label style="font-size:.78rem;font-weight:700;">Telefone</label>
                    <input type="number" name="col_phone" value="-1" class="form-input" min="-1">
                </div>
                <?php endif; ?>
                <div class="form-group">
                    <label style="font-size:.78rem;font-weight:700;">Tipo de ação</label>
                    <input type="number" name="col_type" value="-1" class="form-input" min="-1">
                    <small style="font-size:.65rem;color:var(--text-muted);">-1 = extrair do título (parte após " x ")</small>
                </div>
            </div>

            <div style="display:flex;gap:1rem;margin-top:1rem;align-items:center;flex-wrap:wrap;">
                <label style="display:flex;align-items:center;gap:.35rem;font-size:.78rem;cursor:pointer;">
                    <input type="checkbox" name="skip_header" value="1" checked>
                    Pular primeira linha (cabeçalho)
                </label>
                <div>
                    <label style="font-size:.78rem;font-weight:700;">Status padrão:</label>
                    <?php if ($destino === 'operacional'): ?>
                    <select name="default_status" class="form-input" style="display:inline;width:auto;font-size:.78rem;">
                        <option value="em_elaboracao">Pasta Apta</option>
                        <option value="aguardando_docs">Aguardando Docs</option>
                        <option value="em_andamento">Em Execução</option>
                        <option value="distribuido">Distribuído</option>
                    </select>
                    <?php else: ?>
                    <select name="default_status" class="form-input" style="display:inline;width:auto;font-size:.78rem;">
                        <option value="cadastro_preenchido">Cadastro Preenchido</option>
                        <option value="elaboracao_docs">Elaboração Docs</option>
                        <option value="pasta_apta">Pasta Apta</option>
                    </select>
                    <?php endif; ?>
                </div>
            </div>

            <div style="margin-top:1.25rem;display:flex;gap:.75rem;align-items:center;padding:1rem;background:var(--success-bg);border-radius:var(--radius);border:2px solid var(--success);">
                <button type="submit" class="btn btn-primary" style="padding:10px 28px;font-size:.95rem;font-weight:700;background:var(--success);border:none;">Importar <?= $totalLines ?> linhas</button>
                <a href="<?= module_url('planilha', 'importar.php') ?>" class="btn btn-secondary">Cancelar</a>
                <span style="font-size:.78rem;color:var(--text-muted);margin-left:.5rem;">Verifique o mapeamento acima antes de confirmar</span>
            </div>
        </div>
    </div>
</form>

<?php else: ?>
<!-- STEP 1: Upload -->
<div class="card">
    <div class="card-body" style="max-width:500px;margin:0 auto;padding:2rem;">
        <div style="text-align:center;margin-bottom:1.5rem;">
            <div style="font-size:2.5rem;margin-bottom:.5rem;">📂</div>
            <h2 style="font-size:1rem;">Importar planilha CSV</h2>
            <p style="font-size:.78rem;color:var(--text-muted);">Exporte sua planilha do Excel como CSV (UTF-8) e faça upload aqui.</p>
        </div>

        <?php if ($uploadError): ?>
            <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:.75rem;margin-bottom:1rem;font-size:.82rem;color:#dc2626;font-weight:600;">
                <?= e($uploadError) ?>
            </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="step" value="2">

            <div class="form-group">
                <label style="font-weight:700;">Destino da importação</label>
                <select name="destino" class="form-input">
                    <option value="operacional">Operacional (cases)</option>
                    <option value="pipeline">Pipeline (leads)</option>
                </select>
            </div>

            <div class="form-group">
                <label style="font-weight:700;">Arquivo CSV</label>
                <input type="file" name="csv_file" accept=".csv,.txt" class="form-input" required>
                <small style="font-size:.72rem;color:var(--text-muted);">No Excel: Arquivo > Salvar como > CSV UTF-8 (delimitado por vírgulas)</small>
            </div>

            <button type="submit" class="btn btn-primary" style="width:100%;">Enviar e pré-visualizar</button>
        </form>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
