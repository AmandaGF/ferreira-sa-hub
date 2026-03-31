<?php
/**
 * Agenda — Importar eventos via CSV
 * Aceita CSV com colunas: título, tipo, data_inicio, data_fim, local, cliente, responsável, observações
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
if (!has_min_role('gestao')) { flash_set('error', 'Sem permissão.'); redirect(url('modules/agenda/')); }

$pdo = db();
$pageTitle = 'Importar Agenda (CSV)';

$users = $pdo->query("SELECT id, name FROM users WHERE is_active = 1 ORDER BY name")->fetchAll();
$userMap = array();
foreach ($users as $u) {
    $userMap[mb_strtolower(trim($u['name']))] = (int)$u['id'];
    $firstName = mb_strtolower(trim(explode(' ', $u['name'])[0]));
    if (!isset($userMap[$firstName])) $userMap[$firstName] = (int)$u['id'];
}

$tiposValidos = array('audiencia','reuniao_cliente','prazo','onboarding','reuniao_interna','mediacao_cejusc','ligacao');
$tipoAliases = array(
    'audiencia' => 'audiencia', 'audiência' => 'audiencia',
    'reuniao' => 'reuniao_cliente', 'reunião' => 'reuniao_cliente', 'reunião com cliente' => 'reuniao_cliente', 'reuniao cliente' => 'reuniao_cliente', 'reunião cliente' => 'reuniao_cliente',
    'prazo' => 'prazo', 'prazo processual' => 'prazo',
    'onboarding' => 'onboarding',
    'reuniao interna' => 'reuniao_interna', 'reunião interna' => 'reuniao_interna', 'interna' => 'reuniao_interna',
    'mediacao' => 'mediacao_cejusc', 'mediação' => 'mediacao_cejusc', 'cejusc' => 'mediacao_cejusc', 'mediação / cejusc' => 'mediacao_cejusc',
    'ligacao' => 'ligacao', 'ligação' => 'ligacao', 'retorno' => 'ligacao', 'ligação / retorno' => 'ligacao',
);

$step = $_POST['step'] ?? '1';
$resultado = null;
$preview = array();
$headers = array();
$totalLines = 0;
$uploadError = '';

$tmpDir = __DIR__ . '/../../uploads';
if (!is_dir($tmpDir)) @mkdir($tmpDir, 0755, true);

// ── STEP 2: Preview ─────────────────────────────────────────
if ($step === '2' && isset($_FILES['csv_file'])) {
    $file = $_FILES['csv_file'];
    if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] === 0) {
        $uploadError = 'Erro no upload. Tente novamente.';
        $step = '1';
    } else {
        $content = file_get_contents($file['tmp_name']);
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1,Windows-1252');
        }
        $firstLine = strtok($content, "\n");
        $sep = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';

        $tmpPath = $tmpDir . '/agenda_import_' . session_id() . '_' . time() . '.csv';
        file_put_contents($tmpPath, $content);

        $handle = fopen($tmpPath, 'r');
        $lineNum = 0;
        while (($row = fgetcsv($handle, 0, $sep)) !== false && $lineNum < 6) {
            if ($lineNum === 0) $headers = $row;
            $preview[] = $row;
            $lineNum++;
        }
        fclose($handle);
        $totalLines = count(file($tmpPath)) - 1;

        $_SESSION['agenda_import_file'] = $tmpPath;
        $_SESSION['agenda_import_sep'] = $sep;
    }
}

// ── STEP 3: Processar importação ─────────────────────────────
if ($step === '3' && !empty($_SESSION['agenda_import_file'])) {
    if (!validate_csrf()) { flash_set('error', 'Token inválido.'); redirect(module_url('agenda', 'importar.php')); }

    $tmpPath = $_SESSION['agenda_import_file'];
    $sep = $_SESSION['agenda_import_sep'];

    // Mapeamento de colunas
    $colTitulo      = (int)($_POST['col_titulo'] ?? -1);
    $colTipo        = (int)($_POST['col_tipo'] ?? -1);
    $colDataInicio  = (int)($_POST['col_data_inicio'] ?? -1);
    $colDataFim     = (int)($_POST['col_data_fim'] ?? -1);
    $colHoraInicio  = (int)($_POST['col_hora_inicio'] ?? -1);
    $colHoraFim     = (int)($_POST['col_hora_fim'] ?? -1);
    $colLocal       = (int)($_POST['col_local'] ?? -1);
    $colCliente     = (int)($_POST['col_cliente'] ?? -1);
    $colResponsavel = (int)($_POST['col_responsavel'] ?? -1);
    $colObs         = (int)($_POST['col_obs'] ?? -1);
    $colStatus      = (int)($_POST['col_status'] ?? -1);

    if ($colTitulo < 0 || $colDataInicio < 0) {
        flash_set('error', 'Mapear pelo menos: Título e Data Início.');
        redirect(module_url('agenda', 'importar.php'));
    }

    $handle = fopen($tmpPath, 'r');
    $firstRow = true;
    $importados = 0;
    $erros = 0;

    $stmt = $pdo->prepare(
        "INSERT INTO agenda_eventos (titulo, tipo, modalidade, data_inicio, data_fim, dia_todo, local, descricao,
         client_id, responsavel_id, status, created_by, created_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())"
    );

    while (($row = fgetcsv($handle, 0, $sep)) !== false) {
        if ($firstRow) { $firstRow = false; continue; } // pular cabeçalho

        $titulo = isset($row[$colTitulo]) ? trim($row[$colTitulo]) : '';
        if (!$titulo) { $erros++; continue; }

        // Tipo
        $tipoRaw = ($colTipo >= 0 && isset($row[$colTipo])) ? mb_strtolower(trim($row[$colTipo])) : '';
        $tipo = isset($tipoAliases[$tipoRaw]) ? $tipoAliases[$tipoRaw] : 'reuniao_cliente';

        // Data início
        $dataRaw = isset($row[$colDataInicio]) ? trim($row[$colDataInicio]) : '';
        $dtInicio = parseDateBR($dataRaw);
        if (!$dtInicio) { $erros++; continue; }

        // Hora início
        if ($colHoraInicio >= 0 && isset($row[$colHoraInicio]) && trim($row[$colHoraInicio])) {
            $horaRaw = trim($row[$colHoraInicio]);
            $horaRaw = preg_replace('/[^0-9:]/', '', $horaRaw);
            if (strlen($horaRaw) <= 2) $horaRaw .= ':00';
            $dtInicio .= ' ' . $horaRaw . ':00';
        } else {
            $dtInicio .= ' 09:00:00';
        }

        // Data fim
        $dtFim = $dtInicio;
        if ($colDataFim >= 0 && isset($row[$colDataFim]) && trim($row[$colDataFim])) {
            $fimRaw = parseDateBR(trim($row[$colDataFim]));
            if ($fimRaw) {
                if ($colHoraFim >= 0 && isset($row[$colHoraFim]) && trim($row[$colHoraFim])) {
                    $hfRaw = preg_replace('/[^0-9:]/', '', trim($row[$colHoraFim]));
                    if (strlen($hfRaw) <= 2) $hfRaw .= ':00';
                    $dtFim = $fimRaw . ' ' . $hfRaw . ':00';
                } else {
                    $dtFim = $fimRaw . ' 10:00:00';
                }
            }
        } elseif ($colHoraFim >= 0 && isset($row[$colHoraFim]) && trim($row[$colHoraFim])) {
            $hfRaw = preg_replace('/[^0-9:]/', '', trim($row[$colHoraFim]));
            if (strlen($hfRaw) <= 2) $hfRaw .= ':00';
            $dtFim = substr($dtInicio, 0, 10) . ' ' . $hfRaw . ':00';
        }

        $diaTodo = ($tipo === 'prazo') ? 1 : 0;

        // Local
        $local = ($colLocal >= 0 && isset($row[$colLocal])) ? trim($row[$colLocal]) : '';

        // Cliente (buscar por nome)
        $clientId = null;
        if ($colCliente >= 0 && isset($row[$colCliente]) && trim($row[$colCliente])) {
            $clientNome = trim($row[$colCliente]);
            $cStmt = $pdo->prepare("SELECT id FROM clients WHERE name LIKE ? LIMIT 1");
            $cStmt->execute(array('%' . $clientNome . '%'));
            $cRow = $cStmt->fetch();
            if ($cRow) $clientId = (int)$cRow['id'];
        }

        // Responsável
        $respId = current_user_id();
        if ($colResponsavel >= 0 && isset($row[$colResponsavel]) && trim($row[$colResponsavel])) {
            $respNome = mb_strtolower(trim($row[$colResponsavel]));
            if (isset($userMap[$respNome])) $respId = $userMap[$respNome];
        }

        // Observações
        $obs = ($colObs >= 0 && isset($row[$colObs])) ? trim($row[$colObs]) : '';

        // Status
        $status = 'agendado';
        if ($colStatus >= 0 && isset($row[$colStatus]) && trim($row[$colStatus])) {
            $statusRaw = mb_strtolower(trim($row[$colStatus]));
            if (strpos($statusRaw, 'realiz') !== false || strpos($statusRaw, 'conclu') !== false) $status = 'realizado';
            elseif (strpos($statusRaw, 'cancel') !== false) $status = 'cancelado';
            elseif (strpos($statusRaw, 'remarc') !== false) $status = 'remarcado';
        }

        try {
            $stmt->execute(array(
                $titulo, $tipo, 'nao_aplicavel', $dtInicio, $dtFim, $diaTodo,
                $local, $obs, $clientId, $respId, $status, current_user_id()
            ));
            $importados++;
        } catch (Exception $ex) {
            $erros++;
        }
    }
    fclose($handle);
    @unlink($tmpPath);
    unset($_SESSION['agenda_import_file'], $_SESSION['agenda_import_sep']);

    $resultado = array('importados' => $importados, 'erros' => $erros);
    $step = 'done';
}

// Função auxiliar: parsear data BR (dd/mm/yyyy ou yyyy-mm-dd)
function parseDateBR($str) {
    $str = trim($str);
    if (!$str) return '';
    // dd/mm/yyyy
    if (preg_match('#^(\d{1,2})[/\-.](\d{1,2})[/\-.](\d{4})$#', $str, $m)) {
        return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    }
    // yyyy-mm-dd
    if (preg_match('#^(\d{4})[/\-.](\d{1,2})[/\-.](\d{1,2})$#', $str, $m)) {
        return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
    }
    // Tentar strtotime
    $ts = strtotime($str);
    if ($ts) return date('Y-m-d', $ts);
    return '';
}

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.imp-container { max-width:900px; }
.imp-card { background:#fff;border:1px solid var(--border);border-radius:12px;padding:24px;margin-bottom:16px; }
body.dark-mode .imp-card { background:var(--bg-card);border-color:var(--border); }
.imp-step-badge { display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:50%;background:var(--petrol-900);color:#fff;font-weight:800;font-size:.82rem;margin-right:8px; }
.imp-step-title { font-size:1rem;font-weight:700;color:var(--petrol-900);display:flex;align-items:center;margin-bottom:14px; }
body.dark-mode .imp-step-title { color:var(--text); }
.imp-preview { overflow-x:auto;margin:12px 0; }
.imp-preview table { width:100%;border-collapse:collapse;font-size:.78rem; }
.imp-preview th { background:var(--petrol-900);color:#fff;padding:6px 10px;text-align:left;white-space:nowrap; }
.imp-preview td { padding:5px 10px;border:1px solid var(--border);white-space:nowrap;max-width:200px;overflow:hidden;text-overflow:ellipsis; }
.imp-preview tr:first-child td { background:rgba(215,171,144,.15);font-weight:600; }
.imp-map-row { display:grid;grid-template-columns:200px 1fr;gap:8px;align-items:center;margin-bottom:8px; }
.imp-map-label { font-size:.82rem;font-weight:600;color:var(--petrol-900); }
body.dark-mode .imp-map-label { color:var(--text); }
.imp-map-label small { font-weight:400;color:var(--text-muted); }
.imp-map-row select { padding:6px 10px;border:1.5px solid var(--border);border-radius:8px;font-family:inherit;font-size:.82rem; }
.imp-result { padding:20px;border-radius:12px;text-align:center; }
.imp-result.ok { background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46; }
.imp-result.err { background:#fef2f2;border:1px solid #fecaca;color:#991b1b; }
.imp-dica { background:rgba(215,171,144,.1);border-left:3px solid var(--rose);padding:10px 14px;border-radius:0 8px 8px 0;font-size:.78rem;color:var(--text-muted);margin-top:10px; }
</style>

<div class="imp-container">
    <a href="<?= module_url('agenda') ?>" class="btn btn-outline btn-sm" style="margin-bottom:12px;">← Voltar à Agenda</a>

    <?php if ($uploadError): ?>
        <div class="alert alert-error"><?= e($uploadError) ?></div>
    <?php endif; ?>

    <?php if ($step === 'done' && $resultado): ?>
        <!-- RESULTADO -->
        <div class="imp-card">
            <div class="imp-step-title"><span class="imp-step-badge">✓</span> Importação concluída</div>
            <div class="imp-result <?= $resultado['importados'] > 0 ? 'ok' : 'err' ?>">
                <div style="font-size:2rem;margin-bottom:8px;">📅</div>
                <div style="font-size:1.1rem;font-weight:700;">
                    <?= $resultado['importados'] ?> evento(s) importado(s)
                </div>
                <?php if ($resultado['erros']): ?>
                    <div style="margin-top:6px;font-size:.85rem;"><?= $resultado['erros'] ?> linha(s) com erro (ignoradas)</div>
                <?php endif; ?>
            </div>
            <div style="margin-top:14px;display:flex;gap:8px;">
                <a href="<?= module_url('agenda') ?>" class="btn btn-primary btn-sm">Ver Agenda</a>
                <a href="<?= module_url('agenda', 'importar.php') ?>" class="btn btn-outline btn-sm">Importar outro</a>
            </div>
        </div>

    <?php elseif ($step === '2' && !empty($preview)): ?>
        <!-- STEP 2: Preview + Mapeamento -->
        <div class="imp-card">
            <div class="imp-step-title"><span class="imp-step-badge">1</span> Preview do arquivo (<?= $totalLines ?> linhas)</div>
            <div class="imp-preview">
                <table>
                    <tr>
                        <?php for ($c = 0; $c < count($preview[0]); $c++): ?>
                            <th>Col <?= $c+1 ?>: <?= e(isset($headers[$c]) ? $headers[$c] : '') ?></th>
                        <?php endfor; ?>
                    </tr>
                    <?php foreach (array_slice($preview, 0, 5) as $row): ?>
                    <tr>
                        <?php foreach ($row as $cell): ?>
                            <td title="<?= e($cell) ?>"><?= e($cell) ?></td>
                        <?php endforeach; ?>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        </div>

        <div class="imp-card">
            <div class="imp-step-title"><span class="imp-step-badge">2</span> Mapear colunas</div>
            <form method="POST">
                <input type="hidden" name="step" value="3">
                <?= csrf_input() ?>

                <?php
                $camposMap = array(
                    array('name'=>'col_titulo',      'label'=>'Título',         'req'=>true),
                    array('name'=>'col_tipo',         'label'=>'Tipo',           'req'=>false),
                    array('name'=>'col_data_inicio',  'label'=>'Data início',    'req'=>true),
                    array('name'=>'col_hora_inicio',  'label'=>'Hora início',    'req'=>false),
                    array('name'=>'col_data_fim',     'label'=>'Data fim',       'req'=>false),
                    array('name'=>'col_hora_fim',     'label'=>'Hora fim',       'req'=>false),
                    array('name'=>'col_local',        'label'=>'Local',          'req'=>false),
                    array('name'=>'col_cliente',      'label'=>'Cliente',        'req'=>false),
                    array('name'=>'col_responsavel',  'label'=>'Responsável',    'req'=>false),
                    array('name'=>'col_obs',          'label'=>'Observações',    'req'=>false),
                    array('name'=>'col_status',       'label'=>'Status',         'req'=>false),
                );
                foreach ($camposMap as $campo):
                ?>
                <div class="imp-map-row">
                    <div class="imp-map-label">
                        <?= $campo['label'] ?>
                        <?php if ($campo['req']): ?><span style="color:#CC0000;">*</span><?php endif; ?>
                        <?php if (!$campo['req']): ?><small>(opcional)</small><?php endif; ?>
                    </div>
                    <select name="<?= $campo['name'] ?>">
                        <option value="-1">— Não importar —</option>
                        <?php for ($c = 0; $c < count($preview[0]); $c++): ?>
                            <option value="<?= $c ?>"
                                <?php
                                // Auto-detectar pelo nome do cabeçalho
                                $hdr = mb_strtolower(trim(isset($headers[$c]) ? $headers[$c] : ''));
                                $auto = false;
                                if ($campo['name'] === 'col_titulo' && (strpos($hdr, 'titulo') !== false || strpos($hdr, 'título') !== false || strpos($hdr, 'assunto') !== false || strpos($hdr, 'compromisso') !== false)) $auto = true;
                                if ($campo['name'] === 'col_tipo' && (strpos($hdr, 'tipo') !== false || strpos($hdr, 'categoria') !== false)) $auto = true;
                                if ($campo['name'] === 'col_data_inicio' && (strpos($hdr, 'data') !== false || strpos($hdr, 'inicio') !== false || strpos($hdr, 'início') !== false)) $auto = true;
                                if ($campo['name'] === 'col_hora_inicio' && (strpos($hdr, 'hora') !== false && strpos($hdr, 'fim') === false)) $auto = true;
                                if ($campo['name'] === 'col_data_fim' && strpos($hdr, 'fim') !== false && strpos($hdr, 'hora') === false) $auto = true;
                                if ($campo['name'] === 'col_hora_fim' && strpos($hdr, 'hora') !== false && strpos($hdr, 'fim') !== false) $auto = true;
                                if ($campo['name'] === 'col_local' && (strpos($hdr, 'local') !== false || strpos($hdr, 'endereco') !== false || strpos($hdr, 'endereço') !== false)) $auto = true;
                                if ($campo['name'] === 'col_cliente' && (strpos($hdr, 'cliente') !== false || strpos($hdr, 'nome') !== false)) $auto = true;
                                if ($campo['name'] === 'col_responsavel' && (strpos($hdr, 'responsavel') !== false || strpos($hdr, 'responsável') !== false || strpos($hdr, 'advogad') !== false)) $auto = true;
                                if ($campo['name'] === 'col_obs' && (strpos($hdr, 'obs') !== false || strpos($hdr, 'nota') !== false || strpos($hdr, 'descri') !== false)) $auto = true;
                                if ($campo['name'] === 'col_status' && strpos($hdr, 'status') !== false) $auto = true;
                                echo $auto ? 'selected' : '';
                                ?>
                            >Col <?= $c+1 ?>: <?= e(isset($headers[$c]) ? $headers[$c] : '(sem nome)') ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <?php endforeach; ?>

                <div class="imp-dica">
                    <strong>Tipos aceitos:</strong> audiência, reunião cliente, prazo, onboarding, reunião interna, mediação / CEJUSC, ligação.<br>
                    <strong>Datas:</strong> aceita dd/mm/aaaa ou aaaa-mm-dd. Hora separada ou junto da data.<br>
                    <strong>Cliente:</strong> o sistema busca pelo nome na Agenda de Contatos.
                </div>

                <div style="margin-top:16px;display:flex;gap:8px;">
                    <button type="submit" class="btn btn-primary">Importar <?= $totalLines ?> evento(s)</button>
                    <a href="<?= module_url('agenda', 'importar.php') ?>" class="btn btn-outline">Cancelar</a>
                </div>
            </form>
        </div>

    <?php else: ?>
        <!-- STEP 1: Upload -->
        <div class="imp-card">
            <div class="imp-step-title"><span class="imp-step-badge">1</span> Selecione o arquivo CSV</div>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="step" value="2">
                <div style="border:2px dashed var(--border);border-radius:10px;padding:32px;text-align:center;cursor:pointer;transition:all .2s;background:var(--bg);"
                     onclick="document.getElementById('csvFile').click()">
                    <div style="font-size:2rem;margin-bottom:8px;">📄</div>
                    <div style="font-size:.88rem;font-weight:600;color:var(--petrol-900);">Clique para selecionar o arquivo CSV</div>
                    <div style="font-size:.75rem;color:var(--text-muted);margin-top:4px;">Formato: .csv separado por ; ou ,</div>
                    <div id="fileName" style="margin-top:8px;font-size:.82rem;color:var(--rose);font-weight:600;"></div>
                </div>
                <input type="file" id="csvFile" name="csv_file" accept=".csv,.txt" style="display:none;" onchange="document.getElementById('fileName').textContent=this.files[0]?this.files[0].name:''">
                <button type="submit" class="btn btn-primary" style="margin-top:12px;">Enviar e visualizar</button>
            </form>

            <div class="imp-dica" style="margin-top:14px;">
                <strong>Formato esperado do CSV:</strong><br>
                O CSV deve ter uma linha de cabeçalho. Exemplo:<br>
                <code style="font-size:.72rem;">Título;Tipo;Data;Hora;Local;Cliente;Responsável;Observações</code><br>
                <code style="font-size:.72rem;">Audiência — João x Alimentos;audiência;10/04/2026;14:00;1ª Vara Família;João Silva;Amanda;Levar docs</code>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
// Drag & drop no upload area
var dropZone = document.querySelector('[onclick*="csvFile"]');
if (dropZone) {
    dropZone.addEventListener('dragover', function(e) { e.preventDefault(); this.style.borderColor = 'var(--rose)'; });
    dropZone.addEventListener('dragleave', function() { this.style.borderColor = 'var(--border)'; });
    dropZone.addEventListener('drop', function(e) {
        e.preventDefault();
        this.style.borderColor = 'var(--border)';
        if (e.dataTransfer.files.length) {
            document.getElementById('csvFile').files = e.dataTransfer.files;
            document.getElementById('fileName').textContent = e.dataTransfer.files[0].name;
        }
    });
}
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
