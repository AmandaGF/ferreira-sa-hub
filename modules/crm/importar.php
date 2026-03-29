<?php
/**
 * Ferreira & Sá Hub — Importar Contatos via CSV ou PDF
 */

require_once __DIR__ . '/../../core/middleware.php';
require_min_role('gestao');

$pageTitle = 'Importar Contatos';
$pdo = db();

$result = null;
$preview = null;
$fileType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['import_file'])) {
    if (!validate_csrf()) {
        flash_set('error', 'Token inválido.');
        redirect(module_url('crm', 'importar.php'));
    }

    $file = $_FILES['import_file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        flash_set('error', 'Erro no upload do arquivo.');
        redirect(module_url('crm', 'importar.php'));
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $action = isset($_POST['action']) ? $_POST['action'] : 'preview';

    // ═══════════════════════════════════════════
    // IMPORTAÇÃO VIA PDF
    // ═══════════════════════════════════════════
    if ($ext === 'pdf') {
        require_once APP_ROOT . '/core/pdf_reader.php';
        $fileType = 'pdf';

        $rawText = pdf_extract_text($file['tmp_name']);

        if (mb_strlen(trim($rawText)) < 10) {
            flash_set('error', 'Não foi possível extrair texto do PDF. Pode ser um PDF escaneado (imagem). Tente exportar como CSV.');
            redirect(module_url('crm', 'importar.php'));
        }

        $rows = pdf_extract_contacts($rawText);

        if (empty($rows)) {
            flash_set('error', 'Nenhum contato detectado no PDF. Texto extraído: ' . mb_substr($rawText, 0, 200));
            redirect(module_url('crm', 'importar.php'));
        }

        if ($action === 'preview') {
            $preview = array('rows' => $rows, 'total' => count($rows), 'type' => 'pdf', 'raw_text' => mb_substr($rawText, 0, 1000));
        } elseif ($action === 'importar') {
            $imported = 0;
            $skipped = 0;

            foreach ($rows as $row) {
                // Verificar duplicata
                if (!empty($row['cpf'])) {
                    $cpfClean = preg_replace('/\D/', '', $row['cpf']);
                    $stmt = $pdo->prepare("SELECT id FROM clients WHERE REPLACE(REPLACE(REPLACE(cpf, '.', ''), '-', ''), ' ', '') = ?");
                    $stmt->execute(array($cpfClean));
                    if ($stmt->fetch()) { $skipped++; continue; }
                }
                if (!empty($row['phone'])) {
                    $phoneClean = preg_replace('/\D/', '', $row['phone']);
                    $stmt = $pdo->prepare("SELECT id FROM clients WHERE REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '(', ''), ')', '') LIKE ?");
                    $stmt->execute(array('%' . $phoneClean));
                    if ($stmt->fetch()) { $skipped++; continue; }
                }
                if (!empty($row['email'])) {
                    $stmt = $pdo->prepare("SELECT id FROM clients WHERE email = ?");
                    $stmt->execute(array($row['email']));
                    if ($stmt->fetch()) { $skipped++; continue; }
                }

                $pdo->prepare(
                    "INSERT INTO clients (name, cpf, phone, email, source, created_at) VALUES (?, ?, ?, ?, 'importacao_pdf', NOW())"
                )->execute(array(
                    $row['name'],
                    !empty($row['cpf']) ? $row['cpf'] : null,
                    !empty($row['phone']) ? $row['phone'] : null,
                    !empty($row['email']) ? $row['email'] : null,
                ));
                $imported++;
            }

            $result = array('imported' => $imported, 'skipped' => $skipped, 'total' => count($rows));
            audit_log('clients_imported_pdf', 'client', null, "PDF: importados=$imported, duplicados=$skipped");
            notify_admins('Importação via PDF', "$imported contatos importados do PDF ($skipped duplicados).", 'sucesso', url('modules/clientes/'), '📥');
        }

    // ═══════════════════════════════════════════
    // IMPORTAÇÃO VIA CSV
    // ═══════════════════════════════════════════
    } elseif (in_array($ext, array('xls', 'xlsx'))) {
        flash_set('error', 'Arquivo Excel (.xlsx) não é suportado diretamente. Por favor, salve como CSV primeiro: No Excel, vá em Arquivo → Salvar como → selecione "CSV (separado por vírgula)" ou "CSV UTF-8".');
        redirect(module_url('crm', 'importar.php'));

    } elseif (in_array($ext, array('csv', 'txt'))) {
        $fileType = 'csv';

        $content = file_get_contents($file['tmp_name']);
        // Remover BOM UTF-8 (ï»¿)
        if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
            $content = substr($content, 3);
        }
        $encoding = mb_detect_encoding($content, array('UTF-8', 'ISO-8859-1', 'Windows-1252'), true);
        if ($encoding && $encoding !== 'UTF-8') {
            $content = mb_convert_encoding($content, 'UTF-8', $encoding);
        }

        $firstLine = strtok($content, "\n");
        $sep = (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';

        $lines = explode("\n", $content);
        // Pular linhas de cabeçalho do relatório (ex: "AmandaGF - 29/03/2026")
        while (!empty($lines)) {
            $firstLine = trim($lines[0]);
            // Se a linha contém "nome" ou "razão" ou "cpf", é o header real
            if (stripos($firstLine, 'nome') !== false || stripos($firstLine, 'cpf') !== false) {
                break;
            }
            array_shift($lines);
        }
        $header = str_getcsv(array_shift($lines), $sep);
        $header = array_map('trim', $header);
        $header = array_map('mb_strtolower', $header);

        $fieldMap = array(
            'nome' => 'name', 'name' => 'name', 'nome completo' => 'name', 'cliente' => 'name',
            'nome / razão social' => 'name', 'nome / razao social' => 'name', 'nome/razão social' => 'name', 'nome/razao social' => 'name', 'razão social' => 'name', 'razao social' => 'name',
            'cpf' => 'cpf', 'cpf/cnpj' => 'cpf', 'documento' => 'cpf',
            'rg' => 'rg',
            'telefone' => 'phone', 'phone' => 'phone', 'celular' => 'phone', 'tel' => 'phone', 'whatsapp' => 'phone',
            'telefones / número' => 'phone', 'telefones / numero' => 'phone',
            'email' => 'email', 'e-mail' => 'email',
            'e-mails / e-mail' => 'email', 'e-mails / email' => 'email',
            'nascimento' => 'birth_date', 'data de nascimento' => 'birth_date', 'data_nascimento' => 'birth_date',
            'profissao' => 'profession', 'profissão' => 'profession', 'profissão/nome fantasia' => 'profession', 'profissao/nome fantasia' => 'profession',
            'grupos' => 'grupos', 'classificações' => 'classificacoes', 'classificacoes' => 'classificacoes', 'tipo' => 'tipo',
            'estado civil' => 'marital_status', 'estado_civil' => 'marital_status',
            'endereco' => 'address_street', 'endereço' => 'address_street', 'rua' => 'address_street', 'logradouro' => 'address_street',
            'endereços / logradouro' => 'address_street', 'enderecos / logradouro' => 'address_street',
            'endereços / número' => 'address_number', 'enderecos / numero' => 'address_number',
            'endereços / bairro' => 'address_neighborhood', 'enderecos / bairro' => 'address_neighborhood',
            'cidade' => 'address_city', 'municipio' => 'address_city',
            'endereços / cidade' => 'address_city', 'enderecos / cidade' => 'address_city',
            'estado' => 'address_state', 'uf' => 'address_state',
            'endereços / uf' => 'address_state', 'enderecos / uf' => 'address_state',
            'cep' => 'address_zip',
            'endereços / cep' => 'address_zip', 'enderecos / cep' => 'address_zip',
            'observações' => 'notes', 'observacoes' => 'notes',
            'observacao' => 'notes', 'observação' => 'notes', 'notas' => 'notes', 'obs' => 'notes',
            'origem' => 'source', 'fonte' => 'source',
        );

        $colIndex = array();
        foreach ($header as $i => $col) {
            $col = trim(strtolower($col));
            if (isset($fieldMap[$col])) {
                $colIndex[$fieldMap[$col]] = $i;
            }
        }

        if (!isset($colIndex['name'])) {
            flash_set('error', 'Coluna "Nome" não encontrada. Colunas detectadas: ' . implode(', ', $header));
            redirect(module_url('crm', 'importar.php'));
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
            if (!empty($row['name'])) {
                $rows[] = $row;
            }
        }

        if ($action === 'preview') {
            $preview = array('header' => $header, 'mapped' => $colIndex, 'rows' => array_slice($rows, 0, 10), 'total' => count($rows), 'type' => 'csv');
        } elseif ($action === 'importar') {
            $imported = 0;
            $skipped = 0;

            foreach ($rows as $row) {
                if (!empty($row['phone'])) {
                    $phone = preg_replace('/\D/', '', $row['phone']);
                    $stmt = $pdo->prepare("SELECT id FROM clients WHERE REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '(', ''), ')', '') LIKE ?");
                    $stmt->execute(array('%' . $phone));
                    if ($stmt->fetch()) { $skipped++; continue; }
                }
                if (!empty($row['email'])) {
                    $stmt = $pdo->prepare("SELECT id FROM clients WHERE email = ?");
                    $stmt->execute(array($row['email']));
                    if ($stmt->fetch()) { $skipped++; continue; }
                }

                $birthDate = null;
                if (!empty($row['birth_date'])) {
                    $bd = $row['birth_date'];
                    if (preg_match('/^(\d{2})[\/\-](\d{2})[\/\-](\d{4})$/', $bd, $m)) {
                        $birthDate = $m[3] . '-' . $m[2] . '-' . $m[1];
                    } elseif (preg_match('/^(\d{4})[\/\-](\d{2})[\/\-](\d{2})$/', $bd)) {
                        $birthDate = $bd;
                    }
                }

                // Determinar source e status a partir das colunas do LegalOne
                $source = 'importacao';
                if (isset($row['source']) && $row['source']) {
                    $source = $row['source'];
                } elseif (isset($row['grupos']) && $row['grupos']) {
                    $source = $row['grupos'];
                }

                $clientStatus = 'ativo';
                if (isset($row['classificacoes']) && $row['classificacoes']) {
                    $class = mb_strtolower($row['classificacoes']);
                    if (strpos($class, 'cliente ativo') !== false) $clientStatus = 'ativo';
                    elseif (strpos($class, 'cliente inativo') !== false) $clientStatus = 'cancelou';
                    elseif (strpos($class, 'contrário') !== false || strpos($class, 'contrario') !== false) $clientStatus = 'ativo';
                    elseif (strpos($class, 'fornecedor') !== false) $clientStatus = 'ativo';
                }

                // Montar notas com informações extras
                $notesArr = array();
                if (isset($row['classificacoes']) && $row['classificacoes']) $notesArr[] = 'Classificação: ' . $row['classificacoes'];
                if (isset($row['grupos']) && $row['grupos']) $notesArr[] = 'Grupo: ' . $row['grupos'];
                if (isset($row['tipo']) && $row['tipo']) $notesArr[] = 'Tipo: ' . $row['tipo'];
                if (isset($row['notes']) && $row['notes'] && !isset($row['classificacoes'])) $notesArr[] = $row['notes'];
                $notesStr = !empty($notesArr) ? implode(' | ', $notesArr) : null;

                // Montar endereço completo (logradouro + número + bairro)
                $streetParts = array();
                if (isset($row['address_street']) && $row['address_street']) $streetParts[] = $row['address_street'];
                if (isset($row['address_number']) && $row['address_number']) $streetParts[] = 'nº ' . $row['address_number'];
                if (isset($row['address_neighborhood']) && $row['address_neighborhood']) $streetParts[] = $row['address_neighborhood'];
                $fullStreet = !empty($streetParts) ? implode(', ', $streetParts) : null;

                $pdo->prepare(
                    "INSERT INTO clients (name, cpf, rg, phone, email, birth_date, profession, marital_status,
                     address_street, address_city, address_state, address_zip, source, notes, client_status, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
                )->execute(array(
                    $row['name'],
                    isset($row['cpf']) ? $row['cpf'] : null,
                    isset($row['rg']) ? $row['rg'] : null,
                    isset($row['phone']) ? $row['phone'] : null,
                    isset($row['email']) ? $row['email'] : null,
                    $birthDate,
                    isset($row['profession']) ? $row['profession'] : null,
                    isset($row['marital_status']) ? $row['marital_status'] : null,
                    $fullStreet,
                    isset($row['address_city']) ? $row['address_city'] : null,
                    isset($row['address_state']) ? $row['address_state'] : null,
                    isset($row['address_zip']) ? $row['address_zip'] : null,
                    $source,
                    $notesStr,
                    $clientStatus,
                ));
                $imported++;
            }

            $result = array('imported' => $imported, 'skipped' => $skipped, 'total' => count($rows));
            audit_log('clients_imported', 'client', null, "CSV: importados=$imported, duplicados=$skipped");
            notify_admins('Importação via CSV', "$imported contatos importados ($skipped duplicados).", 'sucesso', url('modules/clientes/'), '📥');
        }
    } else {
        flash_set('error', 'Formato não suportado. Use CSV ou PDF.');
        redirect(module_url('crm', 'importar.php'));
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

.preview-table { width:100%; border-collapse:collapse; font-size:.78rem; margin-top:1rem; }
.preview-table th { background:var(--petrol-900); color:#fff; padding:.5rem .6rem; text-align:left; font-size:.7rem; text-transform:uppercase; }
.preview-table td { padding:.45rem .6rem; border-bottom:1px solid var(--border); }
.preview-table tr:hover { background:rgba(215,171,144,.04); }

.mapping-info { background:var(--petrol-100); border-radius:var(--radius); padding:.75rem 1rem; margin:1rem 0; font-size:.8rem; }
.mapping-info .mapped { color:var(--success); font-weight:600; }
.mapping-info .unmapped { color:var(--text-muted); }

.result-box { text-align:center; padding:2rem; }
.result-box .big { font-size:3rem; margin-bottom:.5rem; }
.result-stats { display:flex; gap:1.5rem; justify-content:center; margin-top:1rem; }
.result-stat { text-align:center; }
.result-stat .val { font-size:1.5rem; font-weight:800; }
.result-stat .lbl { font-size:.72rem; color:var(--text-muted); }

.raw-text-preview { background:var(--bg); border:1px solid var(--border); border-radius:var(--radius); padding:.75rem; font-size:.72rem; color:var(--text-muted); max-height:150px; overflow-y:auto; white-space:pre-wrap; word-break:break-all; margin-top:.75rem; }

.format-tabs { display:flex; gap:0; margin-bottom:1.5rem; }
.format-tab { flex:1; padding:1rem; text-align:center; cursor:pointer; border:2px solid var(--border); background:var(--bg-card); transition:all var(--transition); }
.format-tab:first-child { border-radius:var(--radius-lg) 0 0 var(--radius-lg); }
.format-tab:last-child { border-radius:0 var(--radius-lg) var(--radius-lg) 0; }
.format-tab:hover { border-color:var(--petrol-300); }
.format-tab.active { border-color:var(--rose); background:rgba(215,171,144,.08); }
.format-tab .icon { font-size:1.5rem; margin-bottom:.35rem; }
.format-tab .title { font-size:.88rem; font-weight:700; color:var(--petrol-900); }
.format-tab .desc { font-size:.72rem; color:var(--text-muted); margin-top:.15rem; }
</style>

<?php if ($result): ?>
<!-- Resultado -->
<div class="card">
    <div class="card-body result-box">
        <div class="big">✅</div>
        <h3>Importação concluída!</h3>
        <div class="result-stats">
            <div class="result-stat"><div class="val" style="color:var(--success);"><?= $result['imported'] ?></div><div class="lbl">Importados</div></div>
            <div class="result-stat"><div class="val" style="color:var(--warning);"><?= $result['skipped'] ?></div><div class="lbl">Duplicados ignorados</div></div>
            <div class="result-stat"><div class="val"><?= $result['total'] ?></div><div class="lbl">Total no arquivo</div></div>
        </div>
        <div style="margin-top:1.5rem;">
            <a href="<?= module_url('clientes') ?>" class="btn btn-primary">Ver Clientes</a>
            <a href="<?= module_url('crm', 'importar.php') ?>" class="btn btn-outline" style="margin-left:.5rem;">Nova importação</a>
        </div>
    </div>
</div>

<?php elseif ($preview && $preview['type'] === 'pdf'): ?>
<!-- Preview PDF -->
<div class="card">
    <div class="card-header"><h3>📄 Contatos extraídos do PDF — <?= $preview['total'] ?> encontrados</h3></div>
    <div class="card-body">
        <div style="overflow-x:auto;">
        <table class="preview-table">
            <thead><tr><th>Nome</th><th>CPF</th><th>Telefone</th><th>E-mail</th></tr></thead>
            <tbody>
                <?php foreach ($preview['rows'] as $row): ?>
                <tr>
                    <td><strong><?= e($row['name']) ?></strong></td>
                    <td><?= e($row['cpf'] ? $row['cpf'] : '—') ?></td>
                    <td><?= e($row['phone'] ? $row['phone'] : '—') ?></td>
                    <td><?= e($row['email'] ? $row['email'] : '—') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>

        <details style="margin-top:1rem;">
            <summary style="cursor:pointer;font-size:.78rem;color:var(--text-muted);">Ver texto extraído do PDF</summary>
            <div class="raw-text-preview"><?= e($preview['raw_text']) ?></div>
        </details>

        <div style="margin-top:1.5rem;text-align:center;">
            <form method="POST" enctype="multipart/form-data" style="display:inline;">
                <?= csrf_input() ?>
                <input type="file" name="import_file" accept=".pdf" required style="margin-bottom:.75rem;">
                <input type="hidden" name="action" value="importar">
                <p style="font-size:.72rem;color:var(--text-muted);margin-bottom:.75rem;">Selecione o mesmo PDF para confirmar.</p>
                <a href="<?= module_url('crm', 'importar.php') ?>" class="btn btn-outline">Cancelar</a>
                <button type="submit" class="btn btn-primary" style="margin-left:.5rem;">Confirmar Importação de <?= $preview['total'] ?> contatos</button>
            </form>
        </div>
    </div>
</div>

<?php elseif ($preview && $preview['type'] === 'csv'): ?>
<!-- Preview CSV -->
<div class="card">
    <div class="card-header"><h3>📊 Pré-visualização CSV — <?= $preview['total'] ?> contatos</h3></div>
    <div class="card-body">
        <div class="mapping-info">
            <strong>Colunas mapeadas:</strong>
            <?php
            $allFields = array('name'=>'Nome','cpf'=>'CPF','rg'=>'RG','phone'=>'Telefone','email'=>'E-mail','birth_date'=>'Nascimento','profession'=>'Profissão','marital_status'=>'Estado Civil','address_street'=>'Logradouro','address_number'=>'Nº','address_neighborhood'=>'Bairro','address_city'=>'Cidade','address_state'=>'UF','address_zip'=>'CEP','grupos'=>'Grupos','classificacoes'=>'Classificações','tipo'=>'Tipo','source'=>'Origem','notes'=>'Obs');
            foreach ($allFields as $k => $v):
                if (isset($preview['mapped'][$k])): ?>
                    <span class="mapped">✓ <?= $v ?></span>
                <?php else: ?>
                    <span class="unmapped">✗ <?= $v ?></span>
                <?php endif;
            endforeach; ?>
        </div>

        <div style="overflow-x:auto;">
        <table class="preview-table">
            <thead><tr>
                <?php foreach ($allFields as $k => $v): ?>
                    <?php if (isset($preview['mapped'][$k])): ?><th><?= $v ?></th><?php endif; ?>
                <?php endforeach; ?>
            </tr></thead>
            <tbody>
                <?php foreach ($preview['rows'] as $row): ?>
                <tr>
                    <?php foreach ($allFields as $k => $v): ?>
                        <?php if (isset($preview['mapped'][$k])): ?><td><?= e(isset($row[$k]) ? $row[$k] : '') ?></td><?php endif; ?>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
                <?php if ($preview['total'] > 10): ?>
                <tr><td colspan="<?= count($preview['mapped']) ?>" style="text-align:center;color:var(--text-muted);">... e mais <?= $preview['total'] - 10 ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        </div>

        <div style="margin-top:1.5rem;text-align:center;">
            <form method="POST" enctype="multipart/form-data" style="display:inline;">
                <?= csrf_input() ?>
                <input type="file" name="import_file" accept=".csv,.txt" required style="margin-bottom:.75rem;">
                <input type="hidden" name="action" value="importar">
                <p style="font-size:.72rem;color:var(--text-muted);margin-bottom:.75rem;">Selecione o mesmo arquivo para confirmar.</p>
                <a href="<?= module_url('crm', 'importar.php') ?>" class="btn btn-outline">Cancelar</a>
                <button type="submit" class="btn btn-primary" style="margin-left:.5rem;">Confirmar Importação</button>
            </form>
        </div>
    </div>
</div>

<?php else: ?>
<!-- Upload -->
<div class="import-steps">
    <div class="import-step active"><div class="num">1</div><div class="lbl">Exporte do LegalOne em CSV ou PDF</div></div>
    <div class="import-step"><div class="num">2</div><div class="lbl">Faça upload aqui</div></div>
    <div class="import-step"><div class="num">3</div><div class="lbl">Confira e importe</div></div>
</div>

<div class="card">
    <div class="card-header"><h3>Upload do Arquivo</h3></div>
    <div class="card-body">
        <form method="POST" enctype="multipart/form-data">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="preview">

            <div class="format-tabs" id="formatTabs">
                <div class="format-tab active" onclick="selectFormat(this)">
                    <div class="icon">📊</div>
                    <div class="title">CSV / Excel</div>
                    <div class="desc">Arquivo .csv exportado do Excel ou LegalOne</div>
                </div>
                <div class="format-tab" onclick="selectFormat(this)">
                    <div class="icon">📄</div>
                    <div class="title">PDF</div>
                    <div class="desc">Relatório em PDF com dados de clientes</div>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Selecione o arquivo</label>
                <input type="file" name="import_file" accept=".csv,.txt,.pdf,.xls,.xlsx" required class="form-input" id="fileInput">
                <small style="color:var(--text-muted);font-size:.75rem;">
                    CSV: Arquivo → Salvar como → CSV | PDF: relatório com nomes, CPF, telefones
                </small>
            </div>

            <div style="margin-top:.75rem;padding:.75rem;background:rgba(249,115,22,.08);border-radius:var(--radius);font-size:.78rem;color:var(--text-muted);">
                <strong style="color:var(--warning);">⚠️ PDF:</strong> Funciona com PDFs que contêm texto selecionável. PDFs escaneados (imagem) não são suportados. O sistema detecta automaticamente nomes, CPF, telefones e e-mails.
            </div>

            <button type="submit" class="btn btn-primary" style="margin-top:1rem;">Pré-visualizar</button>
        </form>

        <div style="margin-top:2rem;padding:1.25rem;background:var(--bg);border-radius:var(--radius);font-size:.82rem;">
            <h4 style="margin-bottom:.75rem;color:var(--petrol-900);">Colunas aceitas (CSV):</h4>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:.25rem;font-size:.75rem;">
                <span><strong>Nome</strong> (obrigatório)</span>
                <span><strong>CPF</strong></span>
                <span><strong>Telefone</strong> / Celular</span>
                <span><strong>Email</strong></span>
                <span><strong>Data de Nascimento</strong></span>
                <span><strong>Profissão</strong></span>
                <span><strong>Estado Civil</strong></span>
                <span><strong>Endereço</strong> / Cidade / UF / CEP</span>
            </div>
        </div>
    </div>
</div>

<script>
function selectFormat(el) {
    document.querySelectorAll('.format-tab').forEach(function(t) { t.classList.remove('active'); });
    el.classList.add('active');
}
</script>
<?php endif; ?>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
