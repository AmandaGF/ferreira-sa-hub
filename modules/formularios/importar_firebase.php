<?php
/**
 * Importar dados do Firebase para o Hub
 * Esta página acessa o Firebase via JavaScript e envia para o PHP salvar no MySQL
 */

require_once __DIR__ . '/../../core/middleware.php';
require_role('admin');

$pageTitle = 'Importar do Firebase';
$pdo = db();

// Processar importação via POST (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_data'])) {
    header('Content-Type: application/json');

    $data = json_decode($_POST['import_data'], true);
    if (!$data || !is_array($data)) {
        echo json_encode(['error' => 'Dados inválidos']);
        exit;
    }

    $imported = 0;
    $skipped = 0;

    $stmtCheck = $pdo->prepare("SELECT id FROM form_submissions WHERE protocol = ?");
    $stmtInsert = $pdo->prepare(
        "INSERT INTO form_submissions (form_type, protocol, client_name, client_email, client_phone, status, payload_json, created_at)
         VALUES (?, ?, ?, ?, ?, 'processado', ?, ?)"
    );

    foreach ($data as $item) {
        $protocol = $item['protocol'];

        $stmtCheck->execute([$protocol]);
        if ($stmtCheck->fetch()) { $skipped++; continue; }

        $stmtInsert->execute([
            $item['form_type'],
            $protocol,
            $item['client_name'],
            $item['client_email'],
            $item['client_phone'],
            $item['payload_json'],
            $item['created_at'],
        ]);
        $imported++;
    }

    echo json_encode(['imported' => $imported, 'skipped' => $skipped]);
    exit;
}

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.import-box { max-width: 700px; }
.import-status { padding: 1rem; border-radius: var(--radius); margin-top: 1rem; font-size: .88rem; }
.import-status.loading { background: var(--info-bg); color: var(--info); }
.import-status.success { background: var(--success-bg); color: var(--success); }
.import-status.error { background: var(--danger-bg); color: var(--danger); }
.data-preview { max-height: 300px; overflow-y: auto; font-size: .78rem; background: var(--bg); padding: .75rem; border-radius: var(--radius); margin-top: .75rem; }
.data-preview table { font-size: .78rem; }
</style>

<div class="import-box">
    <a href="<?= module_url('formularios') ?>" class="btn btn-outline btn-sm mb-2">← Voltar</a>

    <div class="card mb-2">
        <div class="card-header"><h3>Importar Cadastros e Leads do Firebase</h3></div>
        <div class="card-body">
            <p class="text-sm text-muted mb-2">
                Este botão conecta no Firebase (Calculadora + Cadastro de Clientes),
                lê todos os registros e importa para o banco do Hub.
            </p>

            <button id="btnCarregar" class="btn btn-primary" onclick="carregarFirebase()">
                1. Carregar dados do Firebase
            </button>

            <div id="statusCarregar" class="import-status" style="display:none;"></div>

            <div id="preview" style="display:none;">
                <div class="data-preview" id="previewTable"></div>
                <button id="btnImportar" class="btn btn-success mt-2" onclick="importarParaHub()">
                    2. Importar para o Hub
                </button>
            </div>

            <div id="statusImportar" class="import-status" style="display:none;"></div>
        </div>
    </div>
</div>

<!-- Firebase SDK -->
<script src="https://www.gstatic.com/firebasejs/9.6.1/firebase-app-compat.js"></script>
<script src="https://www.gstatic.com/firebasejs/9.6.1/firebase-firestore-compat.js"></script>

<script>
var firebaseConfig = {
    apiKey: "AIzaSyC1pdyNrjc_5PW-F1zD5IBmZ7F4ALeplAc",
    authDomain: "coleta-clientes.firebaseapp.com",
    projectId: "coleta-clientes",
    storageBucket: "coleta-clientes.firebasestorage.app",
    messagingSenderId: "679808523416",
    appId: "1:679808523416:web:639c40845b7c4abacbc4ed"
};

firebase.initializeApp(firebaseConfig);
var fbDb = firebase.firestore();
var todosRegistros = [];

function carregarFirebase() {
    var status = document.getElementById('statusCarregar');
    status.style.display = 'block';
    status.className = 'import-status loading';
    status.textContent = 'Conectando ao Firebase...';

    todosRegistros = [];

    // Carregar leads da calculadora
    fbDb.collection('leads_calculadora').get().then(function(snap) {
        snap.forEach(function(doc) {
            var d = doc.data();
            var dataEnvio = d.data_envio && d.data_envio.toDate ? d.data_envio.toDate().toISOString().slice(0,19).replace('T',' ') : new Date().toISOString().slice(0,19).replace('T',' ');
            todosRegistros.push({
                form_type: 'calculadora_lead',
                protocol: 'CALC-' + doc.id.substring(0, 12),
                client_name: d.nome || null,
                client_email: null,
                client_phone: d.whatsapp || d.celular || null,
                payload_json: JSON.stringify(d),
                created_at: dataEnvio,
                _display_tipo: 'Lead Calculadora',
            });
        });

        // Carregar cadastros de clientes
        return fbDb.collection('cadastro_clientes').get();
    }).then(function(snap) {
        snap.forEach(function(doc) {
            var d = doc.data();
            var dataEnvio = d.data_envio && d.data_envio.toDate ? d.data_envio.toDate().toISOString().slice(0,19).replace('T',' ') : new Date().toISOString().slice(0,19).replace('T',' ');
            todosRegistros.push({
                form_type: 'cadastro_cliente',
                protocol: 'CAD-' + doc.id.substring(0, 12),
                client_name: d.nome || null,
                client_email: d.email || null,
                client_phone: d.celular || d.whatsapp || null,
                payload_json: JSON.stringify(d),
                created_at: dataEnvio,
                _display_tipo: 'Cadastro Cliente',
            });
        });

        // Mostrar preview
        status.className = 'import-status success';
        status.textContent = 'Encontrados: ' + todosRegistros.length + ' registros (' +
            todosRegistros.filter(function(r) { return r.form_type === 'calculadora_lead'; }).length + ' leads, ' +
            todosRegistros.filter(function(r) { return r.form_type === 'cadastro_cliente'; }).length + ' cadastros)';

        var html = '<table><thead><tr><th>Tipo</th><th>Nome</th><th>Telefone</th><th>Data</th></tr></thead><tbody>';
        todosRegistros.forEach(function(r) {
            html += '<tr><td>' + r._display_tipo + '</td><td>' + (r.client_name || '—') + '</td><td>' + (r.client_phone || '—') + '</td><td>' + r.created_at + '</td></tr>';
        });
        html += '</tbody></table>';

        document.getElementById('previewTable').innerHTML = html;
        document.getElementById('preview').style.display = 'block';

    }).catch(function(err) {
        status.className = 'import-status error';
        status.textContent = 'Erro: ' + err.message;
    });
}

function importarParaHub() {
    var status = document.getElementById('statusImportar');
    status.style.display = 'block';
    status.className = 'import-status loading';
    status.textContent = 'Importando para o Hub...';

    // Remover campo _display_tipo antes de enviar
    var dataToSend = todosRegistros.map(function(r) {
        var copy = {};
        for (var k in r) { if (k !== '_display_tipo') copy[k] = r[k]; }
        return copy;
    });

    var formData = new FormData();
    formData.append('import_data', JSON.stringify(dataToSend));

    var xhr = new XMLHttpRequest();
    xhr.open('POST', window.location.pathname);
    xhr.onload = function() {
        try {
            var result = JSON.parse(xhr.responseText);
            status.className = 'import-status success';
            status.textContent = 'Importados: ' + result.imported + ' | Já existiam: ' + result.skipped;
            document.getElementById('btnImportar').disabled = true;
            document.getElementById('btnImportar').textContent = 'Importação concluída!';
        } catch(e) {
            status.className = 'import-status error';
            status.textContent = 'Erro na resposta: ' + xhr.responseText.substring(0, 200);
        }
    };
    xhr.onerror = function() {
        status.className = 'import-status error';
        status.textContent = 'Erro de rede';
    };
    xhr.send(formData);
}
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
