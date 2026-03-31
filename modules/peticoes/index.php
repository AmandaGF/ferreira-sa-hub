<?php
/**
 * Fábrica de Petições — Tela principal
 * Acesso via detalhe do caso (caso_ver.php) ou menu
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
if (!has_min_role('gestao') && !has_role('cx') && !has_role('operacional')) {
    flash_set('error', 'Sem permissão.');
    redirect(url('modules/dashboard/'));
}

require_once __DIR__ . '/system_prompt.php';

$pdo = db();
$caseId = (int)($_GET['case_id'] ?? 0);
$pageTitle = 'Fábrica de Petições';

// Buscar caso
$caso = null;
$cliente = null;
if ($caseId) {
    $stmt = $pdo->prepare(
        "SELECT cs.*, cl.name as client_name, cl.cpf, cl.rg, cl.birth_date,
                cl.address_street, cl.address_city, cl.address_state, cl.address_zip,
                cl.profession, cl.marital_status, cl.phone as client_phone, cl.email as client_email,
                cl.pix_key, cl.children_names
         FROM cases cs LEFT JOIN clients cl ON cl.id = cs.client_id WHERE cs.id = ?"
    );
    $stmt->execute(array($caseId));
    $caso = $stmt->fetch();
}

// Peças já geradas para este caso
$pecasGeradas = array();
if ($caseId) {
    $stmt = $pdo->prepare("SELECT cd.*, u.name as user_name FROM case_documents cd LEFT JOIN users u ON u.id = cd.gerado_por WHERE cd.case_id = ? ORDER BY cd.created_at DESC");
    $stmt->execute(array($caseId));
    $pecasGeradas = $stmt->fetchAll();
}

$tiposAcao = get_tipos_acao();
$tiposPeca = get_tipos_peca();

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.fab-container { max-width:800px; }
.fab-step { display:none; }
.fab-step.active { display:block; }
.fab-step-header { display:flex;align-items:center;gap:.75rem;margin-bottom:1rem; }
.fab-step-num { width:32px;height:32px;border-radius:50%;background:var(--petrol-900);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:.85rem; }
.fab-step-title { font-size:1rem;font-weight:700;color:var(--petrol-900); }
.fab-nav { display:flex;gap:.5rem;margin-top:1.25rem; }
.fab-campo { margin-bottom:.85rem; }
.fab-campo label { font-size:.78rem;font-weight:700;color:var(--text-muted);display:block;margin-bottom:.25rem; }
.fab-campo input,.fab-campo select,.fab-campo textarea { width:100%;padding:.55rem .75rem;font-size:.88rem;border:1.5px solid var(--border);border-radius:8px;font-family:inherit; }
.fab-campo input:focus,.fab-campo select:focus,.fab-campo textarea:focus { border-color:var(--rose);outline:none; }
.fab-preview { background:#fff;border:2px solid var(--border);border-radius:12px;padding:2rem;max-height:60vh;overflow-y:auto;font-family:'Times New Roman',serif;font-size:14px;line-height:1.8; }
.fab-preview h1 { font-size:16px;text-align:center;text-transform:uppercase; }
.fab-preview h2 { font-size:14px;font-weight:bold;border-left:4px solid #052228;padding-left:10px;margin-top:1.5em; }
.fab-loading { text-align:center;padding:3rem; }
.fab-loading .spinner { width:40px;height:40px;border:4px solid var(--border);border-top:4px solid var(--petrol-900);border-radius:50%;animation:spin 1s linear infinite;margin:0 auto 1rem; }
@keyframes spin { to { transform:rotate(360deg); } }
.fab-pecas-lista { margin-top:1rem; }
.fab-peca-item { display:flex;justify-content:space-between;align-items:center;padding:.6rem .8rem;border:1px solid var(--border);border-radius:8px;margin-bottom:.5rem;font-size:.82rem; }
.fab-peca-item:hover { background:var(--bg); }
</style>

<div class="fab-container">
    <?php if ($caseId && $caso): ?>
    <a href="<?= module_url('operacional', 'caso_ver.php?id=' . $caseId) ?>" class="btn btn-outline btn-sm" style="margin-bottom:.75rem;">← Voltar ao caso</a>
    <?php endif; ?>

    <div class="card">
        <div class="card-header">
            <h3>Fábrica de Petições</h3>
            <?php if ($caso): ?>
                <span style="font-size:.78rem;color:var(--text-muted);">Caso: <?= e($caso['title'] ?: $caso['client_name']) ?></span>
            <?php endif; ?>
        </div>
        <div class="card-body">

            <!-- PASSO 1: Tipo de peça -->
            <div class="fab-step active" id="step1">
                <div class="fab-step-header">
                    <div class="fab-step-num">1</div>
                    <div class="fab-step-title">Escolha a peça processual</div>
                </div>
                <div class="fab-campo">
                    <label>Tipo de Ação</label>
                    <select id="tipoAcao" onchange="loadCamposAcao()">
                        <option value="">Selecione...</option>
                        <?php foreach ($tiposAcao as $k => $v): ?>
                            <option value="<?= $k ?>" <?= ($caso && stripos($caso['case_type'] ?? '', substr($v, 0, 5)) !== false) ? 'selected' : '' ?>><?= e($v) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="fab-campo">
                    <label>Tipo de Peça</label>
                    <select id="tipoPeca">
                        <option value="">Selecione...</option>
                        <?php foreach ($tiposPeca as $k => $v): ?>
                            <option value="<?= $k ?>"><?= e($v) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="fab-nav">
                    <button onclick="goStep(2)" class="btn btn-primary">Próximo →</button>
                </div>
            </div>

            <!-- PASSO 2: Dados do cliente -->
            <div class="fab-step" id="step2">
                <div class="fab-step-header">
                    <div class="fab-step-num">2</div>
                    <div class="fab-step-title">Dados do cliente</div>
                </div>
                <p style="font-size:.78rem;color:var(--text-muted);margin-bottom:.75rem;">Pré-preenchidos do cadastro. Complete os faltantes.</p>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem .75rem;">
                    <div class="fab-campo"><label>Nome completo</label><input id="cl_nome" value="<?= e($caso['client_name'] ?? '') ?>"></div>
                    <div class="fab-campo"><label>CPF</label><input id="cl_cpf" value="<?= e($caso['cpf'] ?? '') ?>"></div>
                    <div class="fab-campo"><label>RG</label><input id="cl_rg" value="<?= e($caso['rg'] ?? '') ?>"></div>
                    <div class="fab-campo"><label>Data de nascimento</label><input type="date" id="cl_nascimento" value="<?= e($caso['birth_date'] ?? '') ?>"></div>
                    <div class="fab-campo"><label>Profissão</label><input id="cl_profissao" value="<?= e($caso['profession'] ?? '') ?>"></div>
                    <div class="fab-campo"><label>Estado civil</label><input id="cl_estado_civil" value="<?= e($caso['marital_status'] ?? '') ?>"></div>
                    <div class="fab-campo" style="grid-column:span 2;"><label>Endereço</label><input id="cl_endereco" value="<?= e($caso['address_street'] ?? '') ?>"></div>
                    <div class="fab-campo"><label>Cidade/UF</label><input id="cl_cidade" value="<?= e(($caso['address_city'] ?? '') . '/' . ($caso['address_state'] ?? '')) ?>"></div>
                    <div class="fab-campo"><label>CEP</label><input id="cl_cep" value="<?= e($caso['address_zip'] ?? '') ?>"></div>
                    <div class="fab-campo"><label>Telefone</label><input id="cl_telefone" value="<?= e($caso['client_phone'] ?? '') ?>"></div>
                    <div class="fab-campo"><label>E-mail</label><input id="cl_email" value="<?= e($caso['client_email'] ?? '') ?>"></div>
                </div>
                <div class="fab-nav">
                    <button onclick="goStep(1)" class="btn btn-secondary">← Voltar</button>
                    <button onclick="goStep(3)" class="btn btn-primary">Próximo →</button>
                </div>
            </div>

            <!-- PASSO 3: Dados específicos da ação -->
            <div class="fab-step" id="step3">
                <div class="fab-step-header">
                    <div class="fab-step-num">3</div>
                    <div class="fab-step-title">Dados específicos da ação</div>
                </div>
                <div id="camposAcaoContainer">
                    <p style="color:var(--text-muted);font-size:.82rem;">Selecione o tipo de ação no Passo 1.</p>
                </div>
                <div class="fab-nav">
                    <button onclick="goStep(2)" class="btn btn-secondary">← Voltar</button>
                    <button onclick="gerarPeticao()" class="btn btn-primary" id="btnGerar" style="background:var(--success);">Gerar Petição →</button>
                </div>
            </div>

            <!-- PASSO 4: Resultado -->
            <div class="fab-step" id="step4">
                <div class="fab-step-header">
                    <div class="fab-step-num">✓</div>
                    <div class="fab-step-title">Petição Gerada</div>
                </div>
                <div id="resultadoArea">
                    <div class="fab-loading" id="loadingArea">
                        <div class="spinner"></div>
                        <p style="font-size:.88rem;font-weight:600;color:var(--petrol-900);">Gerando petição com IA...</p>
                        <p style="font-size:.75rem;color:var(--text-muted);">Isso pode levar até 60 segundos.</p>
                    </div>
                    <div id="previewArea" style="display:none;">
                        <div style="display:flex;gap:.5rem;margin-bottom:1rem;flex-wrap:wrap;">
                            <button onclick="copiarPeticao()" class="btn btn-primary btn-sm">📋 Copiar texto</button>
                            <button onclick="window.print()" class="btn btn-outline btn-sm">🖨️ Imprimir</button>
                            <button onclick="goStep(1)" class="btn btn-secondary btn-sm">Nova petição</button>
                            <span id="infoTokens" style="margin-left:auto;font-size:.7rem;color:var(--text-muted);"></span>
                        </div>
                        <div class="fab-preview" id="peticaoHTML"></div>
                    </div>
                    <div id="errorArea" style="display:none;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:1rem;color:#dc2626;font-size:.85rem;"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Peças já geradas -->
    <?php if (!empty($pecasGeradas)): ?>
    <div class="card" style="margin-top:1rem;">
        <div class="card-header"><h3>Peças Geradas</h3></div>
        <div class="card-body fab-pecas-lista">
            <?php foreach ($pecasGeradas as $doc): ?>
            <div class="fab-peca-item">
                <div>
                    <strong><?= e($doc['titulo']) ?></strong><br>
                    <span style="font-size:.72rem;color:var(--text-muted);">
                        <?= date('d/m/Y H:i', strtotime($doc['created_at'])) ?>
                        — <?= e($doc['user_name'] ?? '') ?>
                        <?php if ($doc['tokens_output']): ?>
                            — <?= number_format($doc['tokens_input'] + $doc['tokens_output']) ?> tokens
                        <?php endif; ?>
                    </span>
                </div>
                <button onclick="verPeca(<?= $doc['id'] ?>)" class="btn btn-outline btn-sm" style="font-size:.72rem;">Ver</button>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
var camposAcaoData = <?= json_encode(array(
    'alimentos' => get_campos_acao('alimentos'),
    'revisional_alimentos' => get_campos_acao('revisional_alimentos'),
    'execucao_alimentos' => get_campos_acao('execucao_alimentos'),
)) ?>;

function goStep(n) {
    document.querySelectorAll('.fab-step').forEach(function(s) { s.classList.remove('active'); });
    document.getElementById('step' + n).classList.add('active');
    if (n === 3) loadCamposAcao();
}

function loadCamposAcao() {
    var tipo = document.getElementById('tipoAcao').value;
    var container = document.getElementById('camposAcaoContainer');
    if (!tipo || !camposAcaoData[tipo]) {
        container.innerHTML = '<p style="color:var(--text-muted);font-size:.82rem;">Campos específicos não disponíveis para esta ação ainda. Você pode adicionar informações nas observações.</p><div class="fab-campo"><label>Observações do caso</label><textarea name="observacoes_caso" rows="5" style="width:100%;padding:.55rem .75rem;font-size:.88rem;border:1.5px solid var(--border);border-radius:8px;font-family:inherit;" placeholder="Descreva os detalhes relevantes do caso..."></textarea></div>';
        return;
    }
    var html = '';
    camposAcaoData[tipo].forEach(function(campo) {
        html += '<div class="fab-campo"><label>' + campo.label + '</label>';
        if (campo.type === 'textarea') {
            html += '<textarea name="' + campo.name + '" rows="' + (campo.rows || 3) + '" style="width:100%;padding:.55rem .75rem;font-size:.88rem;border:1.5px solid var(--border);border-radius:8px;font-family:inherit;" placeholder="' + (campo.placeholder || '') + '"></textarea>';
        } else if (campo.type === 'select') {
            html += '<select name="' + campo.name + '" style="width:100%;padding:.55rem .75rem;font-size:.88rem;border:1.5px solid var(--border);border-radius:8px;">';
            for (var k in campo.options) { html += '<option value="' + k + '">' + campo.options[k] + '</option>'; }
            html += '</select>';
        } else {
            html += '<input type="' + campo.type + '" name="' + campo.name + '" style="width:100%;padding:.55rem .75rem;font-size:.88rem;border:1.5px solid var(--border);border-radius:8px;" placeholder="' + (campo.placeholder || '') + '">';
        }
        html += '</div>';
    });
    container.innerHTML = html;
}

function gerarPeticao() {
    var tipoAcao = document.getElementById('tipoAcao').value;
    var tipoPeca = document.getElementById('tipoPeca').value;
    if (!tipoAcao || !tipoPeca) { alert('Selecione o tipo de ação e a peça processual.'); return; }

    goStep(4);
    document.getElementById('loadingArea').style.display = 'block';
    document.getElementById('previewArea').style.display = 'none';
    document.getElementById('errorArea').style.display = 'none';

    var formData = new FormData();
    formData.append('action', 'gerar');
    formData.append('case_id', '<?= $caseId ?>');
    formData.append('tipo_acao', tipoAcao);
    formData.append('tipo_peca', tipoPeca);
    formData.append('csrf_token', '<?= generate_csrf_token() ?>');

    // Campos específicos
    document.querySelectorAll('#camposAcaoContainer input, #camposAcaoContainer select, #camposAcaoContainer textarea').forEach(function(el) {
        if (el.name) formData.append(el.name, el.value);
    });

    var xhr = new XMLHttpRequest();
    xhr.open('POST', '<?= module_url("peticoes", "api.php") ?>');
    xhr.timeout = 120000;
    xhr.onload = function() {
        document.getElementById('loadingArea').style.display = 'none';
        try {
            var resp = JSON.parse(xhr.responseText);
            if (resp.error) {
                document.getElementById('errorArea').textContent = resp.error;
                document.getElementById('errorArea').style.display = 'block';
            } else {
                document.getElementById('peticaoHTML').innerHTML = resp.html;
                document.getElementById('infoTokens').textContent = 'Tokens: ' + (resp.tokens_in + resp.tokens_out) + ' | Custo: $' + resp.custo;
                document.getElementById('previewArea').style.display = 'block';
            }
        } catch(e) {
            document.getElementById('errorArea').textContent = 'Erro ao processar resposta: ' + e.message;
            document.getElementById('errorArea').style.display = 'block';
        }
    };
    xhr.onerror = function() {
        document.getElementById('loadingArea').style.display = 'none';
        document.getElementById('errorArea').textContent = 'Erro de conexão. Tente novamente.';
        document.getElementById('errorArea').style.display = 'block';
    };
    xhr.ontimeout = function() {
        document.getElementById('loadingArea').style.display = 'none';
        document.getElementById('errorArea').textContent = 'Timeout. A geração demorou mais de 2 minutos. Tente novamente.';
        document.getElementById('errorArea').style.display = 'block';
    };
    xhr.send(formData);
}

function copiarPeticao() {
    var el = document.getElementById('peticaoHTML');
    var range = document.createRange();
    range.selectNodeContents(el);
    var sel = window.getSelection();
    sel.removeAllRanges();
    sel.addRange(range);
    document.execCommand('copy');
    sel.removeAllRanges();
    alert('Petição copiada para a área de transferência!');
}

function verPeca(id) {
    // Buscar peça salva e mostrar
    window.open('<?= module_url("peticoes", "ver.php?id=") ?>' + id, '_blank');
}

// Carregar campos se tipo já pré-selecionado
if (document.getElementById('tipoAcao').value) loadCamposAcao();
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
