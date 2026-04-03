<?php
/**
 * Ferreira & Sa Hub — Cadastro Manual de Novo Processo
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();

$pdo = db();

// ─── AJAX: busca de clientes ────────────────────────────────────────
if (isset($_GET['ajax_busca_cliente'])) {
    header('Content-Type: application/json; charset=utf-8');
    $q = trim(isset($_GET['q']) ? $_GET['q'] : '');
    if (strlen($q) < 2) { echo '[]'; exit; }
    $stmt = $pdo->prepare(
        "SELECT id, name, cpf, phone FROM clients WHERE name LIKE ? ORDER BY name LIMIT 15"
    );
    $stmt->execute(array('%' . $q . '%'));
    echo json_encode($stmt->fetchAll());
    exit;
}

// ─── POST: gravar novo caso ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) {
        flash_set('error', 'Token CSRF invalido.');
        redirect(module_url('operacional', 'caso_novo.php'));
    }

    $client_id          = (int)($_POST['client_id'] ?? 0);
    $parte_re_nome      = trim($_POST['parte_re_nome'] ?? '');
    $parte_re_cpf_cnpj  = trim($_POST['parte_re_cpf_cnpj'] ?? '');

    // Filhos (para processos de alimentos)
    $filhosJson = null;
    $filhosNomes = $_POST['filho_nome'] ?? array();
    $filhosNasc = $_POST['filho_nascimento'] ?? array();
    $filhosCpf = $_POST['filho_cpf'] ?? array();
    if (!empty($filhosNomes)) {
        $filhos = array();
        for ($fi = 0; $fi < count($filhosNomes); $fi++) {
            $fn = trim($filhosNomes[$fi]);
            if ($fn === '') continue;
            $filhos[] = array(
                'nome' => $fn,
                'nascimento' => isset($filhosNasc[$fi]) ? trim($filhosNasc[$fi]) : '',
                'cpf' => isset($filhosCpf[$fi]) ? trim($filhosCpf[$fi]) : '',
            );
        }
        if (!empty($filhos)) $filhosJson = json_encode($filhos, JSON_UNESCAPED_UNICODE);
    }
    $title              = trim($_POST['title'] ?? '');
    $case_type          = trim($_POST['case_type'] ?? '');
    if ($case_type === 'outro') $case_type = trim($_POST['case_type_outro'] ?? 'Outro');
    $case_number        = trim($_POST['case_number'] ?? '');
    $court              = trim($_POST['court'] ?? '');
    $comarca            = trim($_POST['comarca'] ?? '');
    $comarca_uf         = trim($_POST['comarca_uf'] ?? '');
    $regional           = trim($_POST['regional'] ?? '');
    $sistema_tribunal   = trim($_POST['sistema_tribunal'] ?? '');
    $segredo_justica    = isset($_POST['segredo_justica']) ? 1 : 0;
    $departamento       = trim($_POST['departamento'] ?? 'operacional');
    $category           = in_array(($_POST['category'] ?? ''), array('judicial', 'extrajudicial')) ? $_POST['category'] : 'judicial';
    $distribution_date  = $_POST['distribution_date'] ?? '';
    $priority           = in_array(($_POST['priority'] ?? ''), array('urgente', 'alta', 'normal', 'baixa')) ? $_POST['priority'] : 'normal';
    $responsible_user_id = (int)($_POST['responsible_user_id'] ?? 0);
    $drive_folder_url   = trim($_POST['drive_folder_url'] ?? '');
    $notes              = trim($_POST['notes'] ?? '');
    $status             = trim($_POST['status'] ?? 'em_andamento');

    // Validacoes basicas
    $errors = array();
    if ($title === '') { $errors[] = 'O titulo e obrigatorio.'; }
    if ($client_id < 1) { $errors[] = 'Selecione um cliente.'; }

    if (!empty($errors)) {
        flash_set('error', implode(' ', $errors));
        redirect(module_url('operacional', 'caso_novo.php'));
    }

    $sql = "INSERT INTO cases
        (client_id, parte_re_nome, parte_re_cpf_cnpj, filhos_json, title, case_type, case_number, court, comarca, comarca_uf, regional, sistema_tribunal, segredo_justica, departamento, category, distribution_date, status, priority, responsible_user_id, drive_folder_url, notes, created_at, updated_at)
        VALUES
        (:client_id, :parte_re_nome, :parte_re_cpf_cnpj, :filhos_json, :title, :case_type, :case_number, :court, :comarca, :comarca_uf, :regional, :sistema_tribunal, :segredo_justica, :departamento, :category, :distribution_date, :status, :priority, :responsible_user_id, :drive_folder_url, :notes, NOW(), NOW())";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array(
        ':client_id'          => $client_id,
        ':parte_re_nome'      => $parte_re_nome !== '' ? $parte_re_nome : null,
        ':parte_re_cpf_cnpj'  => $parte_re_cpf_cnpj !== '' ? $parte_re_cpf_cnpj : null,
        ':filhos_json'        => $filhosJson,
        ':title'              => $title,
        ':case_type'          => $case_type,
        ':case_number'        => $case_number,
        ':court'              => $court,
        ':comarca'            => $comarca,
        ':comarca_uf'         => $comarca_uf !== '' ? $comarca_uf : null,
        ':regional'           => $regional !== '' ? $regional : null,
        ':sistema_tribunal'   => $sistema_tribunal !== '' ? $sistema_tribunal : null,
        ':segredo_justica'    => $segredo_justica,
        ':departamento'       => $departamento !== '' ? $departamento : 'operacional',
        ':category'           => $category,
        ':distribution_date'  => $distribution_date !== '' ? $distribution_date : null,
        ':status'             => $status,
        ':priority'           => $priority,
        ':responsible_user_id'=> $responsible_user_id > 0 ? $responsible_user_id : null,
        ':drive_folder_url'   => $drive_folder_url !== '' ? $drive_folder_url : null,
        ':notes'              => $notes !== '' ? $notes : null,
    ));

    $newId = $pdo->lastInsertId();
    flash_set('success', 'Processo cadastrado com sucesso!');
    redirect(module_url('operacional', 'caso_ver.php?id=' . $newId));
}

// ─── GET: exibir formulario ─────────────────────────────────────────
$users = $pdo->query("SELECT id, name FROM users WHERE is_active = 1 ORDER BY name")->fetchAll();

// Pré-carregar cliente se vier via ?client_id=
$preClient = null;
$preFilhos = array();
if (isset($_GET['client_id']) && (int)$_GET['client_id'] > 0) {
    $stmtPre = $pdo->prepare("SELECT id, name, cpf, phone, children_names FROM clients WHERE id = ?");
    $stmtPre->execute(array((int)$_GET['client_id']));
    $preClient = $stmtPre->fetch();

    // Tentar puxar filhos do formulário de convivência/gastos
    if ($preClient) {
        try {
            $stmtFilhos = $pdo->prepare("SELECT payload_json FROM form_submissions WHERE linked_client_id = ? AND form_type IN ('convivencia','gastos_pensao','cadastro_cliente') ORDER BY created_at DESC LIMIT 1");
            $stmtFilhos->execute(array($preClient['id']));
            $formFilhos = $stmtFilhos->fetch();
            if ($formFilhos && $formFilhos['payload_json']) {
                $payload = json_decode($formFilhos['payload_json'], true);
                if (isset($payload['children']) && is_array($payload['children'])) {
                    foreach ($payload['children'] as $ch) {
                        if (isset($ch['name']) && $ch['name']) {
                            $preFilhos[] = array('nome' => $ch['name'], 'nascimento' => isset($ch['birth_date']) ? $ch['birth_date'] : '', 'cpf' => '');
                        }
                    }
                }
                if (empty($preFilhos) && isset($payload['nome_filho_referente']) && $payload['nome_filho_referente']) {
                    $preFilhos[] = array('nome' => $payload['nome_filho_referente'], 'nascimento' => '', 'cpf' => '');
                }
            }
            // Fallback: children_names do cadastro
            if (empty($preFilhos) && $preClient['children_names']) {
                $nomes = preg_split('/[,;eE]/', $preClient['children_names']);
                foreach ($nomes as $n) {
                    $n = trim($n);
                    if ($n) $preFilhos[] = array('nome' => $n, 'nascimento' => '', 'cpf' => '');
                }
            }
        } catch (Exception $e) {}
    }
}

// Status para cadastro MANUAL de processo (não entra no Kanban Operacional)
// Para aparecer no Kanban, o processo deve vir pelo fluxo do Pipeline
$statusLabels = array(
    'em_andamento' => 'Processo em Andamento',
    'suspenso'     => 'Processo Suspenso',
    'arquivado'    => 'Processo Finalizado / Arquivado',
    'renunciamos'  => 'Renunciamos',
);

$departamentos = array(
    'operacional'    => 'Operacional',
    'administrativo' => 'Administrativo',
    'comercial'      => 'Comercial',
    'financeiro'     => 'Financeiro',
);

$sistemasTribunal = array(
    ''      => '— Selecionar —',
    'PJE'   => 'PJe (Processo Judicial Eletrônico)',
    'DCP'   => 'DCP (Distribuição e Controle Processual)',
    'ESAJ'  => 'e-SAJ',
    'EPROC' => 'EPROC',
    'PROJUDI' => 'PROJUDI',
    'TUCUJURIS' => 'TUCUJURIS',
    'SEI'   => 'SEI',
    'OUTRO' => 'Outro',
);

$pageTitle = 'Novo Processo';
require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.form-novo-caso { max-width:780px; margin:0 auto; }
.form-novo-caso .card { margin-bottom:1.25rem; }
.form-novo-caso .form-row { display:flex; gap:1rem; flex-wrap:wrap; margin-bottom:.85rem; }
.form-novo-caso .form-col { flex:1; min-width:220px; }
.form-novo-caso label { display:block; font-size:.78rem; font-weight:700; color:var(--petrol-900); margin-bottom:.3rem; text-transform:uppercase; letter-spacing:.3px; }
.form-novo-caso .form-input,
.form-novo-caso .form-select,
.form-novo-caso textarea { width:100%; }
.form-novo-caso textarea { min-height:80px; resize:vertical; }
.busca-cliente-wrap { position:relative; }
.busca-cliente-results { position:absolute; top:100%; left:0; right:0; background:#fff; border:1px solid var(--border); border-radius:0 0 var(--radius) var(--radius); max-height:220px; overflow-y:auto; z-index:50; display:none; box-shadow:0 4px 16px rgba(0,0,0,.12); }
.busca-cliente-results div:hover { background:rgba(215,171,144,.15); }
.cliente-selecionado { display:inline-flex; align-items:center; gap:.5rem; background:rgba(184,115,51,.1); border:1px solid #B87333; border-radius:8px; padding:.35rem .75rem; font-size:.82rem; font-weight:600; color:#B87333; margin-top:.35rem; }
.cliente-selecionado button { background:none; border:none; color:#dc2626; cursor:pointer; font-size:.9rem; padding:0 2px; }
</style>

<div class="form-novo-caso">
    <a href="<?= module_url('operacional') ?>" class="btn btn-outline btn-sm" style="margin-bottom:1rem;">&#8592; Voltar</a>

    <div class="card">
        <div class="card-header" style="background:linear-gradient(135deg, var(--petrol-900), var(--petrol-500)); color:#fff; border-radius:var(--radius-lg) var(--radius-lg) 0 0;">
            <h3 style="color:#fff;">Cadastrar Novo Processo</h3>
        </div>
        <div class="card-body">
            <form method="POST" action="<?= module_url('operacional', 'caso_novo.php') ?>" id="formNovoCaso">
                <?= csrf_input() ?>

                <!-- Cliente -->
                <div class="form-row">
                    <div class="form-col" style="flex:2;">
                        <label>Cliente *</label>
                        <div class="busca-cliente-wrap">
                            <input type="text" id="buscaCliente" class="form-input" placeholder="Digite o nome do cliente..." autocomplete="off"<?= $preClient ? ' style="display:none;"' : '' ?>>
                            <div id="buscaResultados" class="busca-cliente-results"></div>
                        </div>
                        <input type="hidden" name="client_id" id="clientId" value="<?= $preClient ? $preClient['id'] : '' ?>">
                        <div id="clienteSelecionado">
                            <?php if ($preClient): ?>
                                <span class="cliente-selecionado"><?= e($preClient['name']) ?> <button type="button" onclick="limparCliente()">&times;</button></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Parte Ré -->
                <div class="form-row">
                    <div class="form-col" style="max-width:220px;">
                        <label>CPF/CNPJ da Parte Ré</label>
                        <input type="text" id="parteReCpfCnpj" name="parte_re_cpf_cnpj" class="form-input" placeholder="000.000.000-00" maxlength="18" oninput="formatarCpfCnpj(this)" onblur="buscarCpfCnpj()">
                        <span id="parteReLoading" style="display:none;font-size:.72rem;color:var(--text-muted);">Buscando...</span>
                    </div>
                    <div class="form-col">
                        <label>Nome da Parte Ré</label>
                        <input type="text" id="parteReNome" name="parte_re_nome" class="form-input" placeholder="Nome da parte contrária">
                    </div>
                </div>

                <!-- Filhos (aparece para alimentos) -->
                <div id="secaoFilhos" style="display:none;margin-bottom:.85rem;padding:1rem;background:rgba(184,115,51,.06);border:1.5px solid rgba(184,115,51,.2);border-radius:10px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.6rem;">
                        <label style="margin:0;color:#B87333;">Filho(s) — Requerente(s)</label>
                        <button type="button" onclick="addFilho()" class="btn btn-outline btn-sm" style="font-size:.72rem;color:#B87333;border-color:#B87333;">+ Adicionar filho</button>
                    </div>
                    <div id="listaFilhos">
                        <?php if (!empty($preFilhos)): ?>
                            <?php foreach ($preFilhos as $fi => $f): ?>
                            <div class="form-row filho-row" style="margin-bottom:.5rem;align-items:flex-end;">
                                <div class="form-col"><label style="font-size:.7rem;">Nome completo</label><input type="text" name="filho_nome[]" class="form-input" value="<?= e($f['nome']) ?>" placeholder="Nome do(a) filho(a)"></div>
                                <div class="form-col" style="max-width:160px;"><label style="font-size:.7rem;">Nascimento</label><input type="date" name="filho_nascimento[]" class="form-input" value="<?= e($f['nascimento']) ?>"></div>
                                <div class="form-col" style="max-width:160px;"><label style="font-size:.7rem;">CPF</label><input type="text" name="filho_cpf[]" class="form-input" value="<?= e($f['cpf']) ?>" placeholder="000.000.000-00"></div>
                                <?php if ($fi > 0): ?><button type="button" onclick="this.parentElement.remove();" style="background:none;border:none;color:#dc2626;cursor:pointer;font-size:1rem;padding:0 4px;margin-bottom:6px;">✕</button><?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="form-row filho-row" style="margin-bottom:.5rem;align-items:flex-end;">
                                <div class="form-col"><label style="font-size:.7rem;">Nome completo</label><input type="text" name="filho_nome[]" class="form-input" placeholder="Nome do(a) filho(a)"></div>
                                <div class="form-col" style="max-width:160px;"><label style="font-size:.7rem;">Nascimento</label><input type="date" name="filho_nascimento[]" class="form-input"></div>
                                <div class="form-col" style="max-width:160px;"><label style="font-size:.7rem;">CPF</label><input type="text" name="filho_cpf[]" class="form-input" placeholder="000.000.000-00"></div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Titulo -->
                <div class="form-row">
                    <div class="form-col">
                        <label>Nome da Pasta / Titulo *</label>
                        <input type="text" name="title" class="form-input" required placeholder="Ex: Divorcio Maria x Joao">
                    </div>
                </div>

                <!-- Tipo de acao + Categoria -->
                <div class="form-row">
                    <div class="form-col">
                        <label>Tipo de Acao</label>
                        <select name="case_type" class="form-select" id="selCaseType" onchange="document.getElementById('caseTypeOutro').style.display=this.value==='outro'?'block':'none'">
                            <option value="">Selecione...</option>
                            <optgroup label="Familia">
                                <option value="Alimentos">Alimentos</option>
                                <option value="Revisional de Alimentos">Revisional de Alimentos</option>
                                <option value="Execucao de Alimentos">Execucao de Alimentos</option>
                                <option value="Exoneracao de Alimentos">Exoneracao de Alimentos</option>
                                <option value="Oferta de Alimentos">Oferta de Alimentos</option>
                                <option value="Divorcio Consensual">Divorcio Consensual</option>
                                <option value="Divorcio Litigioso">Divorcio Litigioso</option>
                                <option value="Dissolucao de Uniao Estavel">Dissolucao de Uniao Estavel</option>
                                <option value="Reconhecimento de Uniao Estavel">Reconhecimento de Uniao Estavel</option>
                                <option value="Guarda">Guarda</option>
                                <option value="Guarda Compartilhada">Guarda Compartilhada</option>
                                <option value="Regulamentacao de Convivencia">Regulamentacao de Convivencia</option>
                                <option value="Modificacao de Guarda">Modificacao de Guarda</option>
                                <option value="Busca e Apreensao de Menor">Busca e Apreensao de Menor</option>
                                <option value="Investigacao de Paternidade">Investigacao de Paternidade</option>
                                <option value="Negatoria de Paternidade">Negatoria de Paternidade</option>
                                <option value="Adocao">Adocao</option>
                                <option value="Tutela / Curatela">Tutela / Curatela</option>
                                <option value="Interdicao">Interdicao</option>
                                <option value="Alienacao Parental">Alienacao Parental</option>
                            </optgroup>
                            <optgroup label="Sucessoes">
                                <option value="Inventario Judicial">Inventario Judicial</option>
                                <option value="Inventario Extrajudicial">Inventario Extrajudicial</option>
                                <option value="Alvara Judicial">Alvara Judicial</option>
                                <option value="Arrolamento de Bens">Arrolamento de Bens</option>
                                <option value="Testamento">Testamento</option>
                            </optgroup>
                            <optgroup label="Civel">
                                <option value="Consumidor">Consumidor</option>
                                <option value="Indenizacao / Danos Morais">Indenizacao / Danos Morais</option>
                                <option value="Indenizacao / Danos Materiais">Indenizacao / Danos Materiais</option>
                                <option value="Obrigacao de Fazer">Obrigacao de Fazer</option>
                                <option value="Cobranca">Cobranca</option>
                                <option value="Execucao de Titulo Extrajudicial">Execucao de Titulo Extrajudicial</option>
                                <option value="Cumprimento de Sentenca">Cumprimento de Sentenca</option>
                                <option value="Revisao Contratual">Revisao Contratual</option>
                                <option value="Fraude Bancaria">Fraude Bancaria</option>
                                <option value="Negativacao Indevida">Negativacao Indevida</option>
                            </optgroup>
                            <optgroup label="Imobiliario">
                                <option value="Usucapiao">Usucapiao</option>
                                <option value="Despejo">Despejo</option>
                                <option value="Reintegracao de Posse">Reintegracao de Posse</option>
                                <option value="Imissao na Posse">Imissao na Posse</option>
                                <option value="Adjudicacao Compulsoria">Adjudicacao Compulsoria</option>
                            </optgroup>
                            <optgroup label="Trabalhista">
                                <option value="Reclamacao Trabalhista">Reclamacao Trabalhista</option>
                                <option value="Execucao Trabalhista">Execucao Trabalhista</option>
                            </optgroup>
                            <optgroup label="Previdenciario">
                                <option value="Aposentadoria">Aposentadoria</option>
                                <option value="Auxilio-Doenca / Incapacidade">Auxilio-Doenca / Incapacidade</option>
                                <option value="BPC / LOAS">BPC / LOAS</option>
                                <option value="Pensao por Morte">Pensao por Morte</option>
                                <option value="Revisao de Beneficio">Revisao de Beneficio</option>
                            </optgroup>
                            <optgroup label="Outros">
                                <option value="Medida Protetiva">Medida Protetiva</option>
                                <option value="Habeas Corpus">Habeas Corpus</option>
                                <option value="Mandado de Seguranca">Mandado de Seguranca</option>
                                <option value="outro">Outro (especificar)</option>
                            </optgroup>
                        </select>
                        <input type="text" name="case_type_outro" id="caseTypeOutro" class="form-input" placeholder="Especifique o tipo..." style="display:none;margin-top:4px;">
                    </div>
                    <div class="form-col" style="max-width:220px;">
                        <label>Categoria</label>
                        <select name="category" class="form-select">
                            <option value="judicial">Judicial</option>
                            <option value="extrajudicial">Extrajudicial</option>
                        </select>
                    </div>
                </div>

                <!-- Numero do Processo + Vara -->
                <div class="form-row">
                    <div class="form-col">
                        <label>N. do Processo</label>
                        <input type="text" name="case_number" class="form-input" placeholder="0000000-00.0000.0.00.0000">
                    </div>
                    <div class="form-col">
                        <label>Vara</label>
                        <input type="text" name="court" class="form-input" placeholder="Ex: 1a Vara de Familia">
                    </div>
                </div>

                <!-- UF + Comarca (cidade) + Data de Distribuicao -->
                <div class="form-row">
                    <div class="form-col" style="max-width:120px;">
                        <label>Estado (UF)</label>
                        <select name="comarca_uf" id="comarcaUf" class="form-select" onchange="filtrarCidades()">
                            <option value="">UF</option>
                            <?php
                            $ufs = array('AC','AL','AM','AP','BA','CE','DF','ES','GO','MA','MG','MS','MT','PA','PB','PE','PI','PR','RJ','RN','RO','RR','RS','SC','SE','SP','TO');
                            foreach ($ufs as $uf): ?>
                                <option value="<?= $uf ?>"><?= $uf ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-col">
                        <label>Comarca (Cidade)</label>
                        <input type="text" name="comarca" id="comarcaCidade" class="form-input" placeholder="Digite a cidade..." autocomplete="off" list="listaCidades">
                        <datalist id="listaCidades"></datalist>
                    </div>
                    <div class="form-col" style="max-width:200px;">
                        <label>Data de Distribuicao</label>
                        <input type="date" name="distribution_date" class="form-input">
                    </div>
                </div>

                <!-- Regional (ex: Madureira, Campo Grande, Bangu) -->
                <div class="form-row">
                    <div class="form-col" style="max-width:180px;">
                        <label>Tem Regional?</label>
                        <select id="temRegional" class="form-select" onchange="document.getElementById('campoRegional').style.display=this.value==='sim'?'block':'none';">
                            <option value="nao">Não</option>
                            <option value="sim">Sim</option>
                        </select>
                    </div>
                    <div class="form-col" id="campoRegional" style="display:none;">
                        <label>Qual Regional?</label>
                        <input type="text" name="regional" class="form-input" placeholder="Ex: Madureira, Campo Grande, Bangu...">
                    </div>
                </div>

                <!-- Sistema + Segredo de Justiça -->
                <div class="form-row">
                    <div class="form-col">
                        <label>Sistema do Tribunal</label>
                        <select name="sistema_tribunal" class="form-select">
                            <?php foreach ($sistemasTribunal as $k => $v): ?>
                                <option value="<?= $k ?>"><?= $v ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-col" style="max-width:200px;">
                        <label>Segredo de Justica</label>
                        <div style="display:flex;align-items:center;gap:.5rem;height:42px;">
                            <input type="checkbox" name="segredo_justica" id="segredoJustica" value="1" style="width:18px;height:18px;cursor:pointer;">
                            <label for="segredoJustica" style="font-size:.85rem;font-weight:400;text-transform:none;letter-spacing:0;cursor:pointer;margin:0;">Sim, é segredo</label>
                        </div>
                    </div>
                </div>

                <!-- Status + Prioridade -->
                <div class="form-row">
                    <div class="form-col">
                        <label>Status</label>
                        <select name="status" class="form-select">
                            <?php foreach ($statusLabels as $k => $v): ?>
                                <option value="<?= $k ?>" <?= $k === 'em_andamento' ? 'selected' : '' ?>><?= $v ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-col" style="max-width:200px;">
                        <label>Prioridade</label>
                        <select name="priority" class="form-select">
                            <option value="baixa">Baixa</option>
                            <option value="normal" selected>Normal</option>
                            <option value="alta">Alta</option>
                            <option value="urgente">Urgente</option>
                        </select>
                    </div>
                </div>

                <!-- Departamento + Responsavel -->
                <div class="form-row">
                    <div class="form-col" style="max-width:220px;">
                        <label>Departamento</label>
                        <select name="departamento" class="form-select">
                            <?php foreach ($departamentos as $k => $v): ?>
                                <option value="<?= $k ?>" <?= $k === 'operacional' ? 'selected' : '' ?>><?= $v ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-col" style="max-width:320px;">
                        <label>Responsavel</label>
                        <select name="responsible_user_id" class="form-select">
                            <option value="">-- Selecionar --</option>
                            <?php foreach ($users as $u): ?>
                                <option value="<?= $u['id'] ?>"><?= e($u['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Link Drive -->
                <div class="form-row">
                    <div class="form-col">
                        <label>Link Pasta Google Drive (opcional)</label>
                        <input type="url" name="drive_folder_url" class="form-input" placeholder="https://drive.google.com/drive/folders/...">
                    </div>
                </div>

                <!-- Observacoes -->
                <div class="form-row">
                    <div class="form-col">
                        <label>Observacoes</label>
                        <textarea name="notes" class="form-input" placeholder="Informacoes adicionais sobre o caso..."></textarea>
                    </div>
                </div>

                <!-- Submit -->
                <div style="display:flex;gap:.75rem;justify-content:flex-end;margin-top:1rem;padding-top:1rem;border-top:1px solid var(--border);">
                    <a href="<?= module_url('operacional') ?>" class="btn btn-outline">Cancelar</a>
                    <button type="submit" class="btn btn-primary" style="background:#B87333;min-width:180px;">Cadastrar Processo</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function() {
    var input = document.getElementById('buscaCliente');
    var results = document.getElementById('buscaResultados');
    var hiddenId = document.getElementById('clientId');
    var selDiv = document.getElementById('clienteSelecionado');
    var timer = null;

    input.addEventListener('input', function() {
        clearTimeout(timer);
        var q = this.value.trim();
        if (q.length < 2) { results.style.display = 'none'; return; }
        timer = setTimeout(function() {
            var xhr = new XMLHttpRequest();
            xhr.open('GET', '<?= module_url("operacional", "caso_novo.php") ?>?ajax_busca_cliente=1&q=' + encodeURIComponent(q));
            xhr.onload = function() {
                try {
                    var clientes = JSON.parse(xhr.responseText);
                    if (!clientes.length) {
                        results.innerHTML = '<div style="padding:8px 12px;font-size:.82rem;color:var(--text-muted);">Nenhum encontrado</div>';
                        results.style.display = 'block';
                        return;
                    }
                    var html = '';
                    for (var i = 0; i < clientes.length; i++) {
                        var cl = clientes[i];
                        html += '<div data-id="' + cl.id + '" data-name="' + (cl.name || '').replace(/"/g, '&quot;') + '" style="padding:8px 12px;cursor:pointer;border-bottom:1px solid #eee;font-size:.82rem;">';
                        html += '<strong>' + (cl.name || '') + '</strong>';
                        if (cl.cpf) html += ' <span style="color:var(--text-muted);">— CPF: ' + cl.cpf + '</span>';
                        if (cl.phone) html += ' <span style="color:var(--text-muted);">— ' + cl.phone + '</span>';
                        html += '</div>';
                    }
                    results.innerHTML = html;
                    results.style.display = 'block';

                    // Bind click
                    var items = results.querySelectorAll('div[data-id]');
                    for (var j = 0; j < items.length; j++) {
                        items[j].addEventListener('click', function() {
                            selecionarCliente(this.getAttribute('data-id'), this.getAttribute('data-name'));
                        });
                    }
                } catch(e) { results.style.display = 'none'; }
            };
            xhr.send();
        }, 300);
    });

    // Fechar dropdown ao clicar fora
    document.addEventListener('click', function(ev) {
        if (!input.contains(ev.target) && !results.contains(ev.target)) {
            results.style.display = 'none';
        }
    });

    function selecionarCliente(id, name) {
        hiddenId.value = id;
        input.value = '';
        input.style.display = 'none';
        results.style.display = 'none';
        selDiv.innerHTML = '<span class="cliente-selecionado">' + name + ' <button type="button" onclick="limparCliente()">&times;</button></span>';
    }

    window.limparCliente = function() {
        hiddenId.value = '';
        input.value = '';
        input.style.display = '';
        selDiv.innerHTML = '';
        input.focus();
    };

    // Validacao antes de enviar
    document.getElementById('formNovoCaso').addEventListener('submit', function(ev) {
        if (!hiddenId.value || hiddenId.value === '0') {
            ev.preventDefault();
            alert('Selecione um cliente antes de cadastrar.');
            input.focus();
        }
    });
})();

// ── Filhos (para processos de alimentos) ──
function addFilho() {
    var html = '<div class="form-row filho-row" style="margin-bottom:.5rem;align-items:flex-end;">';
    html += '<div class="form-col"><label style="font-size:.7rem;">Nome completo</label><input type="text" name="filho_nome[]" class="form-input" placeholder="Nome do(a) filho(a)"></div>';
    html += '<div class="form-col" style="max-width:160px;"><label style="font-size:.7rem;">Nascimento</label><input type="date" name="filho_nascimento[]" class="form-input"></div>';
    html += '<div class="form-col" style="max-width:160px;"><label style="font-size:.7rem;">CPF</label><input type="text" name="filho_cpf[]" class="form-input" placeholder="000.000.000-00"></div>';
    html += '<button type="button" onclick="this.parentElement.remove();" style="background:none;border:none;color:#dc2626;cursor:pointer;font-size:1rem;padding:0 4px;margin-bottom:6px;">✕</button>';
    html += '</div>';
    document.getElementById('listaFilhos').insertAdjacentHTML('beforeend', html);
}

// Mostrar seção filhos quando tipo contém "alimentos"
var caseTypeInput = document.querySelector('input[name="case_type"]');
if (caseTypeInput) {
    function checkAlimentos() {
        var val = caseTypeInput.value.toLowerCase();
        var secao = document.getElementById('secaoFilhos');
        if (secao) {
            secao.style.display = (val.indexOf('alimento') !== -1 || val.indexOf('pensão') !== -1 || val.indexOf('pensao') !== -1 || val.indexOf('guarda') !== -1) ? 'block' : 'none';
        }
    }
    caseTypeInput.addEventListener('input', checkAlimentos);
    caseTypeInput.addEventListener('change', checkAlimentos);
    checkAlimentos(); // Verificar estado inicial
}

// ── Máscara CPF/CNPJ + Busca automática ──
function formatarCpfCnpj(el) {
    var v = el.value.replace(/\D/g, '');
    if (v.length <= 11) {
        // CPF: 000.000.000-00
        v = v.replace(/(\d{3})(\d)/, '$1.$2');
        v = v.replace(/(\d{3})(\d)/, '$1.$2');
        v = v.replace(/(\d{3})(\d{1,2})$/, '$1-$2');
    } else {
        // CNPJ: 00.000.000/0000-00
        v = v.replace(/^(\d{2})(\d)/, '$1.$2');
        v = v.replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3');
        v = v.replace(/\.(\d{3})(\d)/, '.$1/$2');
        v = v.replace(/(\d{4})(\d{1,2})$/, '$1-$2');
    }
    el.value = v;
}

function buscarCpfCnpj() {
    var input = document.getElementById('parteReCpfCnpj');
    var nomeInput = document.getElementById('parteReNome');
    var loading = document.getElementById('parteReLoading');
    var doc = input.value.replace(/\D/g, '');

    // Se nome já está preenchido, não buscar
    if (nomeInput.value.trim() !== '') return;

    if (doc.length === 14) {
        // CNPJ — buscar na ReceitaWS (gratuita)
        loading.style.display = 'inline';
        var xhr = new XMLHttpRequest();
        xhr.open('GET', 'https://www.receitaws.com.br/v1/cnpj/' + doc);
        xhr.timeout = 8000;
        xhr.onload = function() {
            loading.style.display = 'none';
            try {
                var data = JSON.parse(xhr.responseText);
                if (data.nome) {
                    nomeInput.value = data.nome;
                    nomeInput.style.borderColor = '#059669';
                    setTimeout(function() { nomeInput.style.borderColor = ''; }, 2000);
                }
            } catch(e) {}
        };
        xhr.onerror = function() { loading.style.display = 'none'; };
        xhr.ontimeout = function() { loading.style.display = 'none'; };
        xhr.send();
    } else if (doc.length === 11) {
        // CPF — buscar na base interna de clientes
        loading.style.display = 'inline';
        var cpfFormatado = doc.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4');
        var xhr2 = new XMLHttpRequest();
        xhr2.open('GET', '<?= module_url("operacional", "caso_novo.php") ?>?ajax_busca_cliente=1&q=' + encodeURIComponent(cpfFormatado));
        xhr2.onload = function() {
            loading.style.display = 'none';
            try {
                var clientes = JSON.parse(xhr2.responseText);
                if (clientes.length > 0) {
                    nomeInput.value = clientes[0].name;
                    nomeInput.style.borderColor = '#059669';
                    setTimeout(function() { nomeInput.style.borderColor = ''; }, 2000);
                }
            } catch(e) {}
        };
        xhr2.onerror = function() { loading.style.display = 'none'; };
        xhr2.send();
    }
}

// ── Busca de cidades por UF (API IBGE) ──
var cidadesCache = {};
function filtrarCidades() {
    var uf = document.getElementById('comarcaUf').value;
    var datalist = document.getElementById('listaCidades');
    var inputCidade = document.getElementById('comarcaCidade');
    datalist.innerHTML = '';
    inputCidade.value = '';
    if (!uf) return;

    if (cidadesCache[uf]) {
        preencherCidades(cidadesCache[uf]);
        return;
    }

    var xhr = new XMLHttpRequest();
    xhr.open('GET', 'https://servicodados.ibge.gov.br/api/v1/localidades/estados/' + uf + '/municipios?orderBy=nome');
    xhr.onload = function() {
        try {
            var cidades = JSON.parse(xhr.responseText);
            var nomes = [];
            for (var i = 0; i < cidades.length; i++) {
                nomes.push(cidades[i].nome);
            }
            cidadesCache[uf] = nomes;
            preencherCidades(nomes);
        } catch(e) {}
    };
    xhr.send();
}

function preencherCidades(nomes) {
    var datalist = document.getElementById('listaCidades');
    datalist.innerHTML = '';
    for (var i = 0; i < nomes.length; i++) {
        var opt = document.createElement('option');
        opt.value = nomes[i];
        datalist.appendChild(opt);
    }
}
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
