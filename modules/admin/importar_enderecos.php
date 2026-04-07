<?php
/**
 * Ferreira & Sá Conecta — Importar Endereços (Novajus)
 * Cole dados tabulados (TSV) exportados do Novajus para atualizar endereços de clientes existentes.
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();

if (!has_min_role('operacional') && !has_min_role('gestao')) {
    flash_set('error', 'Sem permissão.');
    redirect(url('modules/dashboard/'));
}

$pdo = db();
$pageTitle = 'Importar Endereços';

// ── Helpers ──

function format_cep($cep) {
    $cep = preg_replace('/\D/', '', $cep);
    if (strlen($cep) === 8) {
        return substr($cep, 0, 5) . '-' . substr($cep, 5, 3);
    }
    return $cep;
}

function build_address_street($logradouro, $numero, $complemento, $bairro) {
    $parts = trim($logradouro);
    if ($numero !== '') {
        $parts .= ', ' . trim($numero);
    }
    if ($complemento !== '') {
        $parts .= ', ' . trim($complemento);
    }
    if ($bairro !== '') {
        $parts .= ' - ' . trim($bairro);
    }
    return $parts;
}

function find_client($pdo, $name) {
    // Exact match (normalized)
    $stmt = $pdo->prepare("SELECT id, name, address_street, address_city, address_state, address_zip FROM clients WHERE UPPER(TRIM(REPLACE(name, '  ', ' '))) = UPPER(TRIM(REPLACE(?, '  ', ' '))) LIMIT 1");
    $stmt->execute(array($name));
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($client) return $client;

    // Fuzzy: first 3 words with LIKE
    $words = preg_split('/\s+/', trim($name));
    $searchWords = array_slice($words, 0, 3);
    if (count($searchWords) >= 2) {
        $pattern = '%' . implode('%', $searchWords) . '%';
        $stmt2 = $pdo->prepare("SELECT id, name, address_street, address_city, address_state, address_zip FROM clients WHERE UPPER(name) LIKE ? LIMIT 1");
        $stmt2->execute(array(mb_strtoupper($pattern)));
        $client = $stmt2->fetch(PDO::FETCH_ASSOC);
        if ($client) return $client;
    }

    // Try first + last name
    if (count($words) >= 2) {
        $first = $words[0];
        $last = $words[count($words) - 1];
        $pattern2 = '%' . $first . '%' . $last . '%';
        $stmt3 = $pdo->prepare("SELECT id, name, address_street, address_city, address_state, address_zip FROM clients WHERE UPPER(name) LIKE ? LIMIT 1");
        $stmt3->execute(array(mb_strtoupper($pattern2)));
        $client = $stmt3->fetch(PDO::FETCH_ASSOC);
        if ($client) return $client;
    }

    return null;
}

// ── Smart Paste: detect columns ──
function detect_columns($headerLine) {
    $cols = array_map('trim', explode("\t", $headerLine));
    $map = array(
        'nome'        => -1,
        'logradouro'  => -1,
        'numero'      => -1,
        'complemento' => -1,
        'bairro'      => -1,
        'cidade'      => -1,
        'uf'          => -1,
        'cep'         => -1,
    );
    foreach ($cols as $i => $col) {
        $upper = mb_strtoupper(trim($col));
        if (strpos($upper, 'NOME') !== false || strpos($upper, 'RAZ') !== false) $map['nome'] = $i;
        elseif (strpos($upper, 'LOGRADOURO') !== false || $upper === 'ENDERECO' || $upper === 'ENDEREÇO') $map['logradouro'] = $i;
        elseif (strpos($upper, 'NUMERO') !== false || strpos($upper, 'NÚMERO') !== false || $upper === 'N') $map['numero'] = $i;
        elseif (strpos($upper, 'COMPLEMENTO') !== false || strpos($upper, 'COMPL') !== false) $map['complemento'] = $i;
        elseif (strpos($upper, 'BAIRRO') !== false) $map['bairro'] = $i;
        elseif (strpos($upper, 'CIDADE') !== false || strpos($upper, 'MUNICIPIO') !== false || strpos($upper, 'MUNICÍPIO') !== false) $map['cidade'] = $i;
        elseif ($upper === 'UF' || $upper === 'ESTADO') $map['uf'] = $i;
        elseif (strpos($upper, 'CEP') !== false) $map['cep'] = $i;
    }
    return $map;
}

function parse_lines($rawText, $skipFirst) {
    $lines = preg_split('/\r?\n/', trim($rawText));
    if (empty($lines)) return array('rows' => array(), 'smart' => false);

    $smart = false;
    $colMap = null;
    $firstLine = $lines[0];

    // Smart paste detection
    if (mb_stripos($firstLine, 'Nome') !== false && mb_stripos($firstLine, 'Logradouro') === false
        ? false : true) {
        // Check if header contains known Novajus columns
    }
    $upperFirst = mb_strtoupper($firstLine);
    if (strpos($upperFirst, 'NOME') !== false && (strpos($upperFirst, 'LOGRADOURO') !== false || strpos($upperFirst, 'BAIRRO') !== false || strpos($upperFirst, 'CEP') !== false)) {
        $colMap = detect_columns($firstLine);
        $smart = true;
        $skipFirst = true; // always skip header in smart mode
    }

    $rows = array();
    $start = $skipFirst ? 1 : 0;

    for ($i = $start; $i < count($lines); $i++) {
        $line = trim($lines[$i]);
        if ($line === '') continue;

        $cols = explode("\t", $line);

        if ($colMap !== null && $colMap['nome'] >= 0) {
            // Smart mode: use detected columns
            $row = array(
                'nome'        => isset($cols[$colMap['nome']]) ? trim($cols[$colMap['nome']]) : '',
                'logradouro'  => ($colMap['logradouro'] >= 0 && isset($cols[$colMap['logradouro']])) ? trim($cols[$colMap['logradouro']]) : '',
                'numero'      => ($colMap['numero'] >= 0 && isset($cols[$colMap['numero']])) ? trim($cols[$colMap['numero']]) : '',
                'complemento' => ($colMap['complemento'] >= 0 && isset($cols[$colMap['complemento']])) ? trim($cols[$colMap['complemento']]) : '',
                'bairro'      => ($colMap['bairro'] >= 0 && isset($cols[$colMap['bairro']])) ? trim($cols[$colMap['bairro']]) : '',
                'cidade'      => ($colMap['cidade'] >= 0 && isset($cols[$colMap['cidade']])) ? trim($cols[$colMap['cidade']]) : '',
                'uf'          => ($colMap['uf'] >= 0 && isset($cols[$colMap['uf']])) ? trim($cols[$colMap['uf']]) : '',
                'cep'         => ($colMap['cep'] >= 0 && isset($cols[$colMap['cep']])) ? trim($cols[$colMap['cep']]) : '',
            );
        } else {
            // Default order: Name, Logradouro, Número, Complemento, Bairro, Cidade, UF, CEP
            $row = array(
                'nome'        => isset($cols[0]) ? trim($cols[0]) : '',
                'logradouro'  => isset($cols[1]) ? trim($cols[1]) : '',
                'numero'      => isset($cols[2]) ? trim($cols[2]) : '',
                'complemento' => isset($cols[3]) ? trim($cols[3]) : '',
                'bairro'      => isset($cols[4]) ? trim($cols[4]) : '',
                'cidade'      => isset($cols[5]) ? trim($cols[5]) : '',
                'uf'          => isset($cols[6]) ? trim($cols[6]) : '',
                'cep'         => isset($cols[7]) ? trim($cols[7]) : '',
            );
        }

        if ($row['nome'] !== '') {
            $rows[] = $row;
        }
    }

    return array('rows' => $rows, 'smart' => $smart);
}

// ── Process Actions ──
$step = 'paste'; // paste | preview | result
$previewData = array();
$resultStats = array('updated' => 0, 'not_found' => 0, 'already_had' => 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) {
        flash_set('error', 'Token CSRF inválido. Tente novamente.');
        redirect($_SERVER['REQUEST_URI']);
    }

    $action = isset($_POST['action']) ? $_POST['action'] : '';

    // ── STEP 1: Preview ──
    if ($action === 'preview') {
        $rawText = isset($_POST['raw_data']) ? $_POST['raw_data'] : '';
        $skipFirst = isset($_POST['skip_first']) ? true : false;

        if (trim($rawText) === '') {
            flash_set('error', 'Cole os dados na caixa de texto.');
        } else {
            $parsed = parse_lines($rawText, $skipFirst);
            $rows = $parsed['rows'];

            if (empty($rows)) {
                flash_set('error', 'Nenhuma linha válida encontrada. Verifique o formato (colunas separadas por TAB).');
            } else {
                $step = 'preview';
                foreach ($rows as $idx => $row) {
                    $client = find_client($pdo, $row['nome']);
                    $newStreet = build_address_street($row['logradouro'], $row['numero'], $row['complemento'], $row['bairro']);
                    $newCity = $row['cidade'];
                    $newState = strtoupper($row['uf']);
                    $newZip = format_cep($row['cep']);

                    $hasAddress = false;
                    $checked = true;
                    if ($client) {
                        $hasAddress = (!empty($client['address_street']) || !empty($client['address_city']));
                        if ($hasAddress) {
                            $checked = false; // uncheck if already has address
                        }
                    } else {
                        $checked = false;
                    }

                    $previewData[] = array(
                        'idx'          => $idx,
                        'nome'         => $row['nome'],
                        'client'       => $client,
                        'has_address'  => $hasAddress,
                        'checked'      => $checked,
                        'new_street'   => $newStreet,
                        'new_city'     => $newCity,
                        'new_state'    => $newState,
                        'new_zip'      => $newZip,
                        'raw'          => $row,
                    );
                }

                if ($parsed['smart']) {
                    flash_set('success', 'Colunas detectadas automaticamente (Smart Paste). Verifique o preview.');
                }
            }
        }
    }

    // ── STEP 2: Import ──
    if ($action === 'import') {
        $step = 'result';
        $items = isset($_POST['items']) ? $_POST['items'] : array();

        foreach ($items as $item) {
            $clientId = isset($item['client_id']) ? intval($item['client_id']) : 0;
            $include = isset($item['include']) ? true : false;

            if (!$include || $clientId <= 0) {
                $resultStats['not_found']++;
                continue;
            }

            $newStreet = isset($item['new_street']) ? trim($item['new_street']) : '';
            $newCity = isset($item['new_city']) ? trim($item['new_city']) : '';
            $newState = isset($item['new_state']) ? trim($item['new_state']) : '';
            $newZip = isset($item['new_zip']) ? trim($item['new_zip']) : '';

            // Get current data
            $stmt = $pdo->prepare("SELECT address_street, address_city, address_state, address_zip FROM clients WHERE id = ?");
            $stmt->execute(array($clientId));
            $current = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$current) {
                $resultStats['not_found']++;
                continue;
            }

            // Only update fields that are NULL or empty
            $updates = array();
            $params = array();

            if ((empty($current['address_street'])) && $newStreet !== '') {
                $updates[] = 'address_street = ?';
                $params[] = $newStreet;
            }
            if ((empty($current['address_city'])) && $newCity !== '') {
                $updates[] = 'address_city = ?';
                $params[] = $newCity;
            }
            if ((empty($current['address_state'])) && $newState !== '') {
                $updates[] = 'address_state = ?';
                $params[] = $newState;
            }
            if ((empty($current['address_zip'])) && $newZip !== '') {
                $updates[] = 'address_zip = ?';
                $params[] = $newZip;
            }

            if (empty($updates)) {
                $resultStats['already_had']++;
                continue;
            }

            $params[] = $clientId;
            $sql = "UPDATE clients SET " . implode(', ', $updates) . " WHERE id = ?";
            $stmtUp = $pdo->prepare($sql);
            $stmtUp->execute($params);

            audit_log('address_imported', 'client', $clientId, 'Novajus import');
            $resultStats['updated']++;
        }
    }
}

// ── View ──
require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.import-textarea { width: 100%; min-height: 200px; font-family: monospace; font-size: 13px; }
.match-ok { color: #28a745; font-weight: 600; }
.match-fail { color: #dc3545; font-weight: 600; }
.match-warn { color: #ffc107; font-weight: 600; }
.addr-current { color: #6c757d; font-size: 12px; }
.addr-new { color: #007bff; font-size: 12px; }
.preview-table th { font-size: 13px; white-space: nowrap; }
.preview-table td { font-size: 13px; vertical-align: middle; }
.result-box { padding: 20px; border-radius: 8px; text-align: center; }
.result-box h3 { margin-bottom: 15px; }
.format-hint { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 6px; padding: 12px 16px; font-size: 13px; margin-bottom: 16px; }
.format-hint code { background: #e9ecef; padding: 2px 6px; border-radius: 3px; font-size: 12px; }
</style>

<?php if ($step === 'paste'): ?>
<!-- ── STEP: PASTE ── -->
<div class="card">
    <div class="card-header">
        <h3>Importar Endereços do Novajus</h3>
    </div>
    <div class="card-body">
        <div class="format-hint">
            <strong>Formato esperado:</strong> Dados separados por TAB (copie do Excel/planilha).<br>
            <code>Nome[TAB]Logradouro[TAB]Número[TAB]Complemento[TAB]Bairro[TAB]Cidade[TAB]UF[TAB]CEP</code><br><br>
            <strong>Smart Paste:</strong> Se a primeira linha contiver cabeçalhos como "Nome / Razão social", "Logradouro", "Bairro", etc., as colunas serão detectadas automaticamente.
        </div>

        <form method="post">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="preview">

            <div style="margin-bottom: 16px;">
                <label for="raw_data"><strong>Cole os dados aqui:</strong></label>
                <textarea name="raw_data" id="raw_data" class="import-textarea" placeholder="Cole aqui os dados copiados do Novajus (separados por TAB)..."></textarea>
            </div>

            <div style="margin-bottom: 16px;">
                <label>
                    <input type="checkbox" name="skip_first" value="1" checked>
                    Pular primeira linha (cabeçalho)
                </label>
            </div>

            <button type="submit" class="btn btn-primary">Analisar Dados</button>
        </form>
    </div>
</div>

<?php elseif ($step === 'preview'): ?>
<!-- ── STEP: PREVIEW ── -->
<div class="card">
    <div class="card-header">
        <h3>Preview — <?= count($previewData) ?> linha(s) encontrada(s)</h3>
    </div>
    <div class="card-body">
        <?php
        $matchCount = 0;
        $noMatchCount = 0;
        $alreadyCount = 0;
        foreach ($previewData as $p) {
            if ($p['client']) {
                $matchCount++;
                if ($p['has_address']) $alreadyCount++;
            } else {
                $noMatchCount++;
            }
        }
        ?>
        <p style="margin-bottom: 16px;">
            <span class="match-ok"><?= $matchCount ?> encontrado(s)</span> &nbsp;|&nbsp;
            <span class="match-fail"><?= $noMatchCount ?> não encontrado(s)</span> &nbsp;|&nbsp;
            <span class="match-warn"><?= $alreadyCount ?> já com endereço</span>
        </p>

        <form method="post">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="import">

            <div style="overflow-x: auto;">
                <table class="table preview-table">
                    <thead>
                        <tr>
                            <th style="width:30px;">
                                <input type="checkbox" id="checkAll" checked title="Marcar/Desmarcar todos">
                            </th>
                            <th>Nome (arquivo)</th>
                            <th>Cliente encontrado</th>
                            <th>Endereço atual</th>
                            <th>Novo endereço</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($previewData as $i => $p): ?>
                        <tr>
                            <td>
                                <?php if ($p['client']): ?>
                                    <input type="checkbox" name="items[<?= $i ?>][include]" value="1" <?= $p['checked'] ? 'checked' : '' ?> class="row-check">
                                    <input type="hidden" name="items[<?= $i ?>][client_id]" value="<?= $p['client']['id'] ?>">
                                <?php else: ?>
                                    <input type="hidden" name="items[<?= $i ?>][client_id]" value="0">
                                <?php endif; ?>
                                <input type="hidden" name="items[<?= $i ?>][new_street]" value="<?= e($p['new_street']) ?>">
                                <input type="hidden" name="items[<?= $i ?>][new_city]" value="<?= e($p['new_city']) ?>">
                                <input type="hidden" name="items[<?= $i ?>][new_state]" value="<?= e($p['new_state']) ?>">
                                <input type="hidden" name="items[<?= $i ?>][new_zip]" value="<?= e($p['new_zip']) ?>">
                            </td>
                            <td><?= e($p['nome']) ?></td>
                            <td>
                                <?php if ($p['client']): ?>
                                    <span class="match-ok"><?= e($p['client']['name']) ?></span>
                                    <small style="color:#999;">(#<?= $p['client']['id'] ?>)</small>
                                <?php else: ?>
                                    <span class="match-fail">NÃO ENCONTRADO</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($p['client'] && $p['has_address']): ?>
                                    <span class="match-warn">⚠ Já tem endereço</span><br>
                                    <span class="addr-current">
                                        <?= e($p['client']['address_street'] ?: '') ?>
                                        <?php if (!empty($p['client']['address_city'])): ?>, <?= e($p['client']['address_city']) ?><?php endif; ?>
                                        <?php if (!empty($p['client']['address_state'])): ?>/<?= e($p['client']['address_state']) ?><?php endif; ?>
                                        <?php if (!empty($p['client']['address_zip'])): ?> — <?= e($p['client']['address_zip']) ?><?php endif; ?>
                                    </span>
                                <?php elseif ($p['client']): ?>
                                    <span style="color:#999;">Sem endereço</span>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="addr-new">
                                    <?= e($p['new_street']) ?>
                                    <?php if ($p['new_city']): ?>, <?= e($p['new_city']) ?><?php endif; ?>
                                    <?php if ($p['new_state']): ?>/<?= e($p['new_state']) ?><?php endif; ?>
                                    <?php if ($p['new_zip']): ?> — <?= e($p['new_zip']) ?><?php endif; ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div style="margin-top: 16px; display: flex; gap: 12px;">
                <button type="submit" class="btn btn-primary">Importar Selecionados</button>
                <a href="<?= url('modules/admin/importar_enderecos.php') ?>" class="btn btn-secondary">Voltar</a>
            </div>
        </form>
    </div>
</div>

<script>
document.getElementById('checkAll').addEventListener('change', function() {
    var checks = document.querySelectorAll('.row-check');
    for (var i = 0; i < checks.length; i++) {
        checks[i].checked = this.checked;
    }
});
</script>

<?php elseif ($step === 'result'): ?>
<!-- ── STEP: RESULT ── -->
<div class="card">
    <div class="card-header">
        <h3>Resultado da Importação</h3>
    </div>
    <div class="card-body">
        <div class="result-box" style="background: #f8f9fa;">
            <h3>Importação concluída!</h3>
            <p style="font-size: 18px;">
                <span class="match-ok"><?= $resultStats['updated'] ?> atualizado(s)</span><br>
                <span class="match-fail"><?= $resultStats['not_found'] ?> ignorado(s) (não encontrado)</span><br>
                <span class="match-warn"><?= $resultStats['already_had'] ?> ignorado(s) (já tinha endereço)</span>
            </p>
            <div style="margin-top: 20px;">
                <a href="<?= url('modules/admin/importar_enderecos.php') ?>" class="btn btn-primary">Nova Importação</a>
                <a href="<?= url('modules/clientes/') ?>" class="btn btn-secondary">Ver Clientes</a>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
