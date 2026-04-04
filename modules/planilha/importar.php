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
    $colValor = isset($_POST['col_valor']) ? (int)$_POST['col_valor'] : -1;
    $colVencimento = isset($_POST['col_vencimento']) ? (int)$_POST['col_vencimento'] : -1;
    $colPgto = isset($_POST['col_pgto']) ? (int)$_POST['col_pgto'] : -1;
    $colUrgencia = isset($_POST['col_urgencia']) ? (int)$_POST['col_urgencia'] : -1;
    $colNomePasta = isset($_POST['col_nome_pasta']) ? (int)$_POST['col_nome_pasta'] : -1;
    $colPendencias = isset($_POST['col_pendencias']) ? (int)$_POST['col_pendencias'] : -1;
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

        // Telefone / Tipo / Status / Campos comerciais
        $phone = ($colPhone >= 0 && isset($row[$colPhone])) ? trim($row[$colPhone]) : '';
        $caseType = ($colType >= 0 && isset($row[$colType])) ? trim($row[$colType]) : '';
        $status = ($colStatus >= 0 && isset($row[$colStatus])) ? trim($row[$colStatus]) : $defaultStatus;
        $valorAcao = ($colValor >= 0 && isset($row[$colValor])) ? trim($row[$colValor]) : '';
        $vencimento = ($colVencimento >= 0 && isset($row[$colVencimento])) ? trim($row[$colVencimento]) : '';
        $formaPgto = ($colPgto >= 0 && isset($row[$colPgto])) ? trim($row[$colPgto]) : '';
        $urgenciaVal = ($colUrgencia >= 0 && isset($row[$colUrgencia])) ? trim($row[$colUrgencia]) : '';
        $nomePasta = ($colNomePasta >= 0 && isset($row[$colNomePasta])) ? trim($row[$colNomePasta]) : '';
        $pendenciasVal = ($colPendencias >= 0 && isset($row[$colPendencias])) ? trim($row[$colPendencias]) : '';

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

                // Vincular ao contato existente ou criar (anti-duplicação)
                $clientId = find_or_create_client(array('name' => $clientName, 'phone' => $phone));

                $pdo->prepare(
                    "INSERT INTO cases (client_id, title, case_type, status, responsible_user_id, drive_folder_url, deadline, notes, opened_at, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)"
                )->execute(array(
                    $clientId, $title, $caseType ?: 'outro', $defaultStatus,
                    $respId, $driveUrl ?: null, $parsedPrazo,
                    $obs ?: null, $parsedDate, $parsedDate ?: date('Y-m-d H:i:s')
                ));
                $inserted++;

            } elseif ($destino === 'pipeline') {
                // Duplicata: mesmo nome + mesmo tipo = mesmo contrato
                $existsSql = "SELECT id FROM pipeline_leads WHERE name = ?";
                $existsParams = array($title);
                if ($caseType) {
                    $existsSql .= " AND case_type = ?";
                    $existsParams[] = $caseType;
                }
                $existsSql .= " AND stage NOT IN ('finalizado','cancelado','perdido')";
                $exists = $pdo->prepare($existsSql);
                $exists->execute($existsParams);
                if ($exists->fetch()) { $skipped++; continue; }

                // Vincular ao contato existente ou criar (anti-duplicação)
                $clientName = $title;
                if (strpos($title, ' x ') !== false) {
                    $clientName = trim(explode(' x ', $title)[0]);
                } elseif (strpos($title, ' - ') !== false) {
                    $clientName = trim(explode(' - ', $title)[0]);
                }
                $clientId = find_or_create_client(array('name' => $clientName, 'phone' => $phone));
                if (!$clientId) {
                }

                // Mapear status da planilha para stage do Pipeline
                $stageMap = array(
                    'pasta apta' => 'pasta_apta',
                    'cancelado' => 'cancelado',
                    'aguardando envio' => 'elaboracao_docs',
                    'elaboracao' => 'elaboracao_docs',
                );
                $finalStage = $defaultStatus;
                if ($status && $status !== $defaultStatus) {
                    $statusLower = mb_strtolower(trim($status));
                    if (isset($stageMap[$statusLower])) {
                        $finalStage = $stageMap[$statusLower];
                    }
                }

                $honCents = parse_valor_reais($valorAcao);
                $pdo->prepare(
                    "INSERT INTO pipeline_leads (client_id, name, phone, stage, case_type, assigned_to, notes, source, created_at, valor_acao, honorarios_cents, estimated_value_cents, vencimento_parcela, forma_pagamento, urgencia, observacoes, nome_pasta, pendencias) VALUES (?,?,?,?,?,?,?,'outro',?,?,?,?,?,?,?,?,?)"
                )->execute(array(
                    $clientId, $title, $phone ?: null, $finalStage,
                    $caseType ?: null, $respId, $obs ?: null,
                    $parsedDate ?: date('Y-m-d H:i:s'),
                    $valorAcao ?: null, $honCents, $honCents, $vencimento ?: null, $formaPgto ?: null,
                    $urgenciaVal ?: null, $obs ?: null, $nomePasta ?: null, $pendenciasVal ?: null
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
<!-- STEP 2: Preview + Mapeamento (tudo junto) -->
<form method="POST" action="">
    <?= csrf_input() ?>
    <input type="hidden" name="step" value="3">
    <input type="hidden" name="destino" value="<?= e($destino) ?>">

    <div style="background:var(--success-bg);border:2px solid var(--success);border-radius:12px;padding:1rem;margin-bottom:1rem;display:flex;gap:1rem;align-items:center;flex-wrap:wrap;">
        <button type="submit" class="btn btn-primary" style="padding:12px 32px;font-size:1rem;font-weight:700;background:var(--success);border:none;border-radius:10px;">Importar <?= $totalLines ?> linhas</button>
        <span style="font-size:.85rem;color:var(--text);">Arquivo carregado com sucesso! Configure o mapeamento abaixo e clique para importar.</span>
        <a href="<?= module_url('planilha', 'importar.php') ?>" style="margin-left:auto;font-size:.78rem;color:var(--text-muted);">Cancelar</a>
    </div>

    <div class="card" style="margin-bottom:1rem;">
        <div class="card-header"><strong>Mapeamento de colunas</strong> — selecione qual coluna do CSV corresponde a cada campo</div>
        <div class="card-body">
            <?php
            // Detectar nomes das colunas do header
            $headerNames = array();
            if (!empty($preview[0])) {
                foreach ($preview[0] as $i => $val) {
                    $headerNames[$i] = trim($val) ?: 'Col ' . $i;
                }
            }
            // Gerar options de colunas
            function colOptions($headerNames, $selected = -1) {
                $html = '<option value="-1">— Ignorar —</option>';
                foreach ($headerNames as $i => $name) {
                    $sel = ($i == $selected) ? ' selected' : '';
                    $html .= '<option value="' . $i . '"' . $sel . '>Col ' . $i . ': ' . htmlspecialchars(mb_substr($name, 0, 25)) . '</option>';
                }
                return $html;
            }
            // Auto-detectar colunas pelo nome do header
            $autoMap = array('col_title'=>-1,'col_phone'=>-1,'col_date'=>-1,'col_type'=>-1,'col_resp'=>-1,'col_drive'=>-1,'col_obs'=>-1,'col_prazo'=>-1,'col_status'=>-1,'col_valor'=>-1,'col_vencimento'=>-1,'col_pgto'=>-1,'col_urgencia'=>-1,'col_nome_pasta'=>-1,'col_pendencias'=>-1);
            foreach ($headerNames as $i => $name) {
                $n = mb_strtolower($name);
                if (strpos($n, 'nome da pasta') !== false) $autoMap['col_nome_pasta'] = $i;
                elseif ($autoMap['col_title'] === -1 && strpos($n, 'nome') !== false && strpos($n, 'pasta') === false) $autoMap['col_title'] = $i;
                if (strpos($n, 'contato') !== false || strpos($n, 'telefone') !== false) $autoMap['col_phone'] = $i;
                if (strpos($n, 'data') !== false && (strpos($n, 'fechamento') !== false || strpos($n, 'cadastro') !== false)) $autoMap['col_date'] = $i;
                if (strpos($n, 'tipo') !== false) $autoMap['col_type'] = $i;
                if (strpos($n, 'valor') !== false) $autoMap['col_valor'] = $i;
                if (strpos($n, 'vencimento') !== false) $autoMap['col_vencimento'] = $i;
                if (strpos($n, 'forma') !== false && strpos($n, 'pagamento') !== false) $autoMap['col_pgto'] = $i;
                if (strpos($n, 'respons') !== false || strpos($n, 'executante') !== false) $autoMap['col_resp'] = $i;
                if (strpos($n, 'urg') !== false) $autoMap['col_urgencia'] = $i;
                if (strpos($n, 'drive') !== false || strpos($n, 'link') !== false) $autoMap['col_drive'] = $i;
                if (strpos($n, 'observa') !== false) $autoMap['col_obs'] = $i;
                if (strpos($n, 'prazo') !== false || strpos($n, 'entrega') !== false) $autoMap['col_prazo'] = $i;
                if (strpos($n, 'estado') !== false || strpos($n, 'status') !== false) $autoMap['col_status'] = $i;
                if (strpos($n, 'pend') !== false) $autoMap['col_pendencias'] = $i;
            }
            // Se tem "NOME DA PASTA" usa esse como título; senão NOME
            if ($autoMap['col_title'] === -1 && !empty($headerNames)) $autoMap['col_title'] = 0;
            ?>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:.6rem;">
                <div><label style="font-size:.75rem;font-weight:700;">Título/Nome do caso *</label><select name="col_title" class="form-input" required><?= colOptions($headerNames, $autoMap['col_title']) ?></select></div>
                <div><label style="font-size:.75rem;font-weight:700;">Telefone/Contato</label><select name="col_phone" class="form-input"><?= colOptions($headerNames, $autoMap['col_phone']) ?></select></div>
                <div><label style="font-size:.75rem;font-weight:700;">Data do cadastro</label><select name="col_date" class="form-input"><?= colOptions($headerNames, $autoMap['col_date']) ?></select></div>
                <div><label style="font-size:.75rem;font-weight:700;">Tipo de ação</label><select name="col_type" class="form-input"><?= colOptions($headerNames, $autoMap['col_type']) ?></select></div>
                <div><label style="font-size:.75rem;font-weight:700;">Responsável</label><select name="col_resp" class="form-input"><?= colOptions($headerNames, $autoMap['col_resp']) ?></select></div>
                <div><label style="font-size:.75rem;font-weight:700;">Link Drive</label><select name="col_drive" class="form-input"><?= colOptions($headerNames, $autoMap['col_drive']) ?></select></div>
                <div><label style="font-size:.75rem;font-weight:700;">Observações</label><select name="col_obs" class="form-input"><?= colOptions($headerNames, $autoMap['col_obs']) ?></select></div>
                <div><label style="font-size:.75rem;font-weight:700;">Prazo/Entrega</label><select name="col_prazo" class="form-input"><?= colOptions($headerNames, $autoMap['col_prazo']) ?></select></div>
                <div><label style="font-size:.75rem;font-weight:700;">Valor da Ação</label><select name="col_valor" class="form-input"><?= colOptions($headerNames, $autoMap['col_valor']) ?></select></div>
                <div><label style="font-size:.75rem;font-weight:700;">Vencimento 1ª Parcela</label><select name="col_vencimento" class="form-input"><?= colOptions($headerNames, $autoMap['col_vencimento']) ?></select></div>
                <div><label style="font-size:.75rem;font-weight:700;">Forma de Pagamento</label><select name="col_pgto" class="form-input"><?= colOptions($headerNames, $autoMap['col_pgto']) ?></select></div>
                <div><label style="font-size:.75rem;font-weight:700;">Urgência</label><select name="col_urgencia" class="form-input"><?= colOptions($headerNames, $autoMap['col_urgencia']) ?></select></div>
                <div><label style="font-size:.75rem;font-weight:700;">Nome da Pasta</label><select name="col_nome_pasta" class="form-input"><?= colOptions($headerNames, $autoMap['col_nome_pasta']) ?></select></div>
                <div><label style="font-size:.75rem;font-weight:700;">Estado/Status</label><select name="col_status" class="form-input"><?= colOptions($headerNames, $autoMap['col_status']) ?></select></div>
                <div><label style="font-size:.75rem;font-weight:700;">Pendências</label><select name="col_pendencias" class="form-input"><?= colOptions($headerNames, $autoMap['col_pendencias']) ?></select></div>
            </div>
            <div style="display:flex;gap:1rem;margin-top:.75rem;align-items:center;flex-wrap:wrap;">
                <label style="display:flex;align-items:center;gap:.3rem;font-size:.78rem;cursor:pointer;"><input type="checkbox" name="skip_header" value="1" checked> Pular cabeçalho</label>
                <div>
                    <label style="font-size:.75rem;font-weight:700;">Status padrão:</label>
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
        </div>
    </div>

    <div class="card">
        <div class="card-header"><strong>Preview</strong> — <?= $totalLines ?> linhas | Separador: "<?= $sep === ';' ? ';' : ',' ?>"</div>
        <div class="card-body" style="overflow-x:auto;max-height:200px;overflow-y:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:.7rem;">
                <thead><tr>
                    <?php if (!empty($preview[0])): foreach ($preview[0] as $i => $col): ?>
                        <th style="border:1px solid #ddd;padding:3px 5px;background:#f0f0f0;white-space:nowrap;font-size:.65rem;">Col <?= $i ?></th>
                    <?php endforeach; endif; ?>
                </tr></thead>
                <tbody>
                    <?php foreach ($preview as $ri => $row): ?>
                    <tr style="<?= $ri === 0 ? 'background:#fff8e1;font-weight:600;' : '' ?>">
                        <?php foreach ($row as $cell): ?>
                            <td style="border:1px solid #ddd;padding:2px 4px;max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.65rem;" title="<?= e($cell) ?>"><?= e(mb_substr($cell, 0, 30)) ?></td>
                        <?php endforeach; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
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
