<?php
/**
 * Ferreira & Sá Hub — Importar Contatos via CSV
 * Aceita CSV exportado do Excel ou LegalOne
 */

require_once __DIR__ . '/../../core/middleware.php';
require_min_role('gestao');

$pageTitle = 'Importar Contatos';
$pdo = db();

$result = null;
$preview = null;
$errors = array();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    if (!validate_csrf()) {
        flash_set('error', 'Token inválido.');
        redirect(module_url('crm', 'importar.php'));
    }

    $file = $_FILES['csv_file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        flash_set('error', 'Erro no upload do arquivo.');
        redirect(module_url('crm', 'importar.php'));
    }

    // Detectar encoding e converter para UTF-8
    $content = file_get_contents($file['tmp_name']);
    $encoding = mb_detect_encoding($content, array('UTF-8', 'ISO-8859-1', 'Windows-1252'), true);
    if ($encoding && $encoding !== 'UTF-8') {
        $content = mb_convert_encoding($content, 'UTF-8', $encoding);
    }

    // Detectar separador (vírgula ou ponto-e-vírgula)
    $firstLine = strtok($content, "\n");
    $sep = (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';

    $lines = explode("\n", $content);
    $header = str_getcsv(array_shift($lines), $sep);
    $header = array_map('trim', $header);
    $header = array_map('mb_strtolower', $header);

    // Mapear colunas do CSV para campos do banco
    $fieldMap = array(
        'nome' => 'name', 'name' => 'name', 'nome completo' => 'name', 'cliente' => 'name',
        'cpf' => 'cpf', 'cpf/cnpj' => 'cpf', 'documento' => 'cpf',
        'rg' => 'rg',
        'telefone' => 'phone', 'phone' => 'phone', 'celular' => 'phone', 'tel' => 'phone', 'whatsapp' => 'phone',
        'email' => 'email', 'e-mail' => 'email',
        'nascimento' => 'birth_date', 'data de nascimento' => 'birth_date', 'data_nascimento' => 'birth_date', 'dt_nascimento' => 'birth_date',
        'profissao' => 'profession', 'profissão' => 'profession',
        'estado civil' => 'marital_status', 'estado_civil' => 'marital_status',
        'endereco' => 'address_street', 'endereço' => 'address_street', 'rua' => 'address_street', 'logradouro' => 'address_street',
        'cidade' => 'address_city', 'municipio' => 'address_city',
        'estado' => 'address_state', 'uf' => 'address_state',
        'cep' => 'address_zip',
        'observacao' => 'notes', 'observação' => 'notes', 'notas' => 'notes', 'obs' => 'notes',
        'origem' => 'source', 'fonte' => 'source',
    );

    // Mapear índices
    $colIndex = array();
    foreach ($header as $i => $col) {
        $col = trim(strtolower($col));
        if (isset($fieldMap[$col])) {
            $colIndex[$fieldMap[$col]] = $i;
        }
    }

    if (!isset($colIndex['name'])) {
        flash_set('error', 'Coluna "Nome" não encontrada no CSV. Colunas detectadas: ' . implode(', ', $header));
        redirect(module_url('crm', 'importar.php'));
    }

    $action = isset($_POST['action']) ? $_POST['action'] : 'preview';

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
        $preview = array('header' => $header, 'mapped' => $colIndex, 'rows' => array_slice($rows, 0, 10), 'total' => count($rows));
    } elseif ($action === 'importar') {
        $imported = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            // Verificar duplicata por nome+telefone ou nome+email
            $dupCheck = false;
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

            // Formatar data de nascimento
            $birthDate = null;
            if (!empty($row['birth_date'])) {
                $bd = $row['birth_date'];
                // Tentar dd/mm/yyyy ou dd-mm-yyyy
                if (preg_match('/^(\d{2})[\/\-](\d{2})[\/\-](\d{4})$/', $bd, $m)) {
                    $birthDate = $m[3] . '-' . $m[2] . '-' . $m[1];
                } elseif (preg_match('/^(\d{4})[\/\-](\d{2})[\/\-](\d{2})$/', $bd)) {
                    $birthDate = $bd;
                }
            }

            $pdo->prepare(
                "INSERT INTO clients (name, cpf, rg, phone, email, birth_date, profession, marital_status,
                 address_street, address_city, address_state, address_zip, source, notes, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
            )->execute(array(
                $row['name'],
                isset($row['cpf']) ? $row['cpf'] : null,
                isset($row['rg']) ? $row['rg'] : null,
                isset($row['phone']) ? $row['phone'] : null,
                isset($row['email']) ? $row['email'] : null,
                $birthDate,
                isset($row['profession']) ? $row['profession'] : null,
                isset($row['marital_status']) ? $row['marital_status'] : null,
                isset($row['address_street']) ? $row['address_street'] : null,
                isset($row['address_city']) ? $row['address_city'] : null,
                isset($row['address_state']) ? $row['address_state'] : null,
                isset($row['address_zip']) ? $row['address_zip'] : null,
                isset($row['source']) && $row['source'] ? $row['source'] : 'importacao',
                isset($row['notes']) ? $row['notes'] : null,
            ));
            $imported++;
        }

        $result = array('imported' => $imported, 'skipped' => $skipped, 'total' => count($rows));
        audit_log('clients_imported', 'client', null, "importados: $imported, duplicados: $skipped");
        notify_admins('Importação de contatos', "$imported contatos importados ($skipped duplicados ignorados).", 'sucesso', url('modules/crm/'), '📥');
    }
}

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.import-steps { display:flex; gap:1rem; margin-bottom:1.5rem; }
.import-step {
    flex:1; background:var(--bg-card); border-radius:var(--radius-lg);
    border:1px solid var(--border); padding:1rem; text-align:center;
}
.import-step .num { font-size:1.5rem; font-weight:800; color:var(--rose); }
.import-step .lbl { font-size:.78rem; color:var(--text-muted); margin-top:.25rem; }
.import-step.active { border-color:var(--rose); background:rgba(215,171,144,.05); }

.preview-table { width:100%; border-collapse:collapse; font-size:.78rem; margin-top:1rem; }
.preview-table th { background:var(--petrol-900); color:#fff; padding:.5rem .6rem; text-align:left; font-size:.7rem; text-transform:uppercase; }
.preview-table td { padding:.45rem .6rem; border-bottom:1px solid var(--border); }
.preview-table tr:hover { background:rgba(215,171,144,.04); }

.mapping-info { background:var(--petrol-100); border-radius:var(--radius); padding:.75rem 1rem; margin:1rem 0; font-size:.8rem; }
.mapping-info strong { color:var(--petrol-900); }
.mapping-info .mapped { color:var(--success); font-weight:600; }
.mapping-info .unmapped { color:var(--text-muted); }

.result-box { text-align:center; padding:2rem; }
.result-box .big { font-size:3rem; margin-bottom:.5rem; }
.result-box h3 { color:var(--petrol-900); margin-bottom:.5rem; }
.result-stats { display:flex; gap:1.5rem; justify-content:center; margin-top:1rem; }
.result-stat { text-align:center; }
.result-stat .val { font-size:1.5rem; font-weight:800; }
.result-stat .lbl { font-size:.72rem; color:var(--text-muted); }
</style>

<?php if ($result): ?>
<!-- Resultado da importação -->
<div class="card">
    <div class="card-body result-box">
        <div class="big">✅</div>
        <h3>Importação concluída!</h3>
        <div class="result-stats">
            <div class="result-stat">
                <div class="val" style="color:var(--success);"><?= $result['imported'] ?></div>
                <div class="lbl">Importados</div>
            </div>
            <div class="result-stat">
                <div class="val" style="color:var(--warning);"><?= $result['skipped'] ?></div>
                <div class="lbl">Duplicados ignorados</div>
            </div>
            <div class="result-stat">
                <div class="val"><?= $result['total'] ?></div>
                <div class="lbl">Total no arquivo</div>
            </div>
        </div>
        <div style="margin-top:1.5rem;">
            <a href="<?= module_url('crm') ?>" class="btn btn-primary">Ver Clientes</a>
            <a href="<?= module_url('crm', 'importar.php') ?>" class="btn btn-outline" style="margin-left:.5rem;">Nova importação</a>
        </div>
    </div>
</div>

<?php elseif ($preview): ?>
<!-- Preview -->
<div class="card">
    <div class="card-header"><h3>Pré-visualização — <?= $preview['total'] ?> contatos encontrados</h3></div>
    <div class="card-body">
        <div class="mapping-info">
            <strong>Colunas mapeadas:</strong>
            <?php
            $allFields = array('name'=>'Nome','cpf'=>'CPF','rg'=>'RG','phone'=>'Telefone','email'=>'E-mail','birth_date'=>'Nascimento','profession'=>'Profissão','marital_status'=>'Estado Civil','address_street'=>'Endereço','address_city'=>'Cidade','address_state'=>'UF','address_zip'=>'CEP','source'=>'Origem','notes'=>'Obs');
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
                    <?php if (isset($preview['mapped'][$k])): ?>
                        <th><?= $v ?></th>
                    <?php endif; ?>
                <?php endforeach; ?>
            </tr></thead>
            <tbody>
                <?php foreach ($preview['rows'] as $row): ?>
                <tr>
                    <?php foreach ($allFields as $k => $v): ?>
                        <?php if (isset($preview['mapped'][$k])): ?>
                            <td><?= e(isset($row[$k]) ? $row[$k] : '') ?></td>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
                <?php if ($preview['total'] > 10): ?>
                <tr><td colspan="<?= count($preview['mapped']) ?>" style="text-align:center;color:var(--text-muted);">... e mais <?= $preview['total'] - 10 ?> contatos</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        </div>

        <form method="POST" enctype="multipart/form-data" style="margin-top:1.5rem;display:flex;gap:.75rem;justify-content:center;">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="importar">
            <input type="file" name="csv_file" accept=".csv,.txt" required style="display:none;" id="reimport">
            <a href="<?= module_url('crm', 'importar.php') ?>" class="btn btn-outline">Cancelar</a>
            <button type="button" class="btn btn-primary btn-lg" onclick="document.getElementById('confirmImport').style.display='block'">
                Importar <?= $preview['total'] ?> contatos
            </button>
        </form>

        <!-- Confirmação -->
        <div id="confirmImport" style="display:none;margin-top:1rem;text-align:center;">
            <p style="color:var(--danger);font-weight:600;font-size:.88rem;">Confirma a importação de <?= $preview['total'] ?> contatos?</p>
            <form method="POST" enctype="multipart/form-data" style="margin-top:.5rem;">
                <?= csrf_input() ?>
                <input type="file" name="csv_file" accept=".csv,.txt" required id="confirmFile" style="margin-bottom:.5rem;">
                <input type="hidden" name="action" value="importar">
                <p style="font-size:.75rem;color:var(--text-muted);margin-bottom:.75rem;">Selecione o mesmo arquivo novamente para confirmar.</p>
                <button type="submit" class="btn btn-primary">Confirmar Importação</button>
            </form>
        </div>
    </div>
</div>

<?php else: ?>
<!-- Formulário de upload -->
<div class="import-steps">
    <div class="import-step active">
        <div class="num">1</div>
        <div class="lbl">Exporte do LegalOne/Excel em CSV</div>
    </div>
    <div class="import-step">
        <div class="num">2</div>
        <div class="lbl">Faça upload aqui</div>
    </div>
    <div class="import-step">
        <div class="num">3</div>
        <div class="lbl">Confira e importe</div>
    </div>
</div>

<div class="card">
    <div class="card-header"><h3>Upload do Arquivo CSV</h3></div>
    <div class="card-body">
        <form method="POST" enctype="multipart/form-data">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="preview">

            <div class="form-group">
                <label class="form-label">Arquivo CSV</label>
                <input type="file" name="csv_file" accept=".csv,.txt" required class="form-input">
                <small style="color:var(--text-muted);font-size:.75rem;">
                    No Excel: Arquivo → Salvar como → CSV (separado por vírgula ou ponto-e-vírgula)
                </small>
            </div>

            <button type="submit" class="btn btn-primary" style="margin-top:1rem;">Pré-visualizar</button>
        </form>

        <div style="margin-top:2rem;padding:1.25rem;background:var(--bg);border-radius:var(--radius);font-size:.82rem;">
            <h4 style="margin-bottom:.75rem;color:var(--petrol-900);">Formato esperado do CSV:</h4>
            <p style="color:var(--text-muted);margin-bottom:.5rem;">O sistema detecta automaticamente as colunas. Use nomes como:</p>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:.25rem;font-size:.75rem;">
                <span><strong>Nome</strong> (obrigatório)</span>
                <span><strong>CPF</strong></span>
                <span><strong>Telefone</strong> ou Celular</span>
                <span><strong>Email</strong></span>
                <span><strong>Data de Nascimento</strong></span>
                <span><strong>Profissão</strong></span>
                <span><strong>Estado Civil</strong></span>
                <span><strong>Endereço</strong></span>
                <span><strong>Cidade</strong></span>
                <span><strong>UF</strong></span>
                <span><strong>CEP</strong></span>
                <span><strong>Observação</strong></span>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
