<?php
/**
 * Ferreira & Sá Hub — Gerar Documento (usando templates reais)
 */

require_once __DIR__ . '/../../core/middleware.php';
require_access('documentos');
require_once __DIR__ . '/templates.php';

$pdo = db();
$tipo = $_GET['tipo'] ?? '';
$clientId = (int)($_GET['client_id'] ?? 0);
$tipoAcao = $_GET['tipo_acao'] ?? '';
$outorgante = $_GET['outorgante'] ?? 'proprio';

$validTypes = array('procuracao', 'contrato', 'substabelecimento', 'hipossuficiencia', 'isencao_ir', 'residencia', 'acordo', 'juntada', 'ciencia', 'prevjud', 'citacao_whatsapp');
if (!in_array($tipo, $validTypes) || !$clientId) {
    flash_set('error', 'Selecione tipo e cliente.');
    redirect(module_url('documentos'));
}

$stmt = $pdo->prepare('SELECT * FROM clients WHERE id = ?');
$stmt->execute(array($clientId));
$client = $stmt->fetch();
if (!$client) { flash_set('error', 'Cliente não encontrado.'); redirect(module_url('documentos')); }

$typeLabels = array(
    'procuracao' => 'Procuração Ad Judicia Et Extra',
    'contrato' => 'Contrato de Honorários Advocatícios',
    'substabelecimento' => 'Substabelecimento',
    'hipossuficiencia' => 'Declaração de Hipossuficiência',
    'isencao_ir' => 'Declaração de Isenção de IR',
    'residencia' => 'Declaração de Residência',
    'acordo' => 'Termo de Acordo Extrajudicial',
    'juntada' => 'Petição de Juntada de Documentos',
    'ciencia' => 'Petição de Ciência',
    'prevjud' => 'Pesquisa PREVJUD',
    'citacao_whatsapp' => 'Petição de Citação por WhatsApp',
);

$acaoLabels = array(
    'alimentos' => 'PROCESSO DE FIXAÇÃO OU EXECUÇÃO DE PENSÃO ALIMENTÍCIA',
    'divorcio' => 'AÇÃO DE DIVÓRCIO',
    'guarda_convivencia' => 'AÇÃO DE GUARDA E/OU REGULAMENTAÇÃO DE CONVIVÊNCIA',
    'familia' => 'DEMANDA DE DIREITO DE FAMÍLIA',
    'consumidor' => 'AÇÃO DE DIREITO DO CONSUMIDOR',
    'indenizacao' => 'AÇÃO DE INDENIZAÇÃO POR DANOS MORAIS E/OU MATERIAIS',
    'trabalhista' => 'RECLAMAÇÃO TRABALHISTA',
    'inventario' => 'INVENTÁRIO E PARTILHA DE BENS',
    'outro' => '',
);

$pageTitle = isset($typeLabels[$tipo]) ? $typeLabels[$tipo] : 'Documento';
$acaoTexto = isset($acaoLabels[$tipoAcao]) ? $acaoLabels[$tipoAcao] : '';

// Dados do cliente
$nome = $client['name'] ? $client['name'] : '';
$cpf = $client['cpf'] ? $client['cpf'] : '';
$rg = $client['rg'] ? $client['rg'] : '';
$email = $client['email'] ? $client['email'] : '';
$phone = $client['phone'] ? $client['phone'] : '';
$profissao = $client['profession'] ? $client['profession'] : '';
$estadoCivil = $client['marital_status'] ? $client['marital_status'] : '';
$endereco = $client['address_street'] ? $client['address_street'] : '';
$cidade = $client['address_city'] ? $client['address_city'] : '';
$uf = $client['address_state'] ? $client['address_state'] : '';
$cep = $client['address_zip'] ? $client['address_zip'] : '';
$enderecoCompleto = $endereco;
if ($cidade) $enderecoCompleto .= ', ' . $cidade;
if ($uf) $enderecoCompleto .= ' – ' . $uf;
if ($cep) $enderecoCompleto .= ', CEP: ' . $cep;
if (!trim($endereco)) $enderecoCompleto = '';

// Buscar filhos
$childNames = '';
$childData = $pdo->prepare("SELECT payload_json FROM form_submissions WHERE linked_client_id = ? AND form_type IN ('convivencia','gastos_pensao') ORDER BY created_at DESC LIMIT 1");
$childData->execute(array($clientId));
$childForm = $childData->fetch();
if ($childForm) {
    $payload = json_decode($childForm['payload_json'], true);
    if (isset($payload['children']) && is_array($payload['children'])) {
        $names = array();
        foreach ($payload['children'] as $ch) {
            if (isset($ch['name']) && $ch['name']) $names[] = $ch['name'];
        }
        $childNames = implode(' E ', array_map('strtoupper', $names));
    }
    if (!$childNames && isset($payload['nome_filho_referente'])) {
        $childNames = strtoupper($payload['nome_filho_referente']);
    }
}

// Data
$meses = array('','janeiro','fevereiro','março','abril','maio','junho','julho','agosto','setembro','outubro','novembro','dezembro');
$hoje = date('d') . ' de ' . $meses[(int)date('m')] . ' de ' . date('Y');
$cidadeData = ($cidade ? $cidade : 'Rio de Janeiro') . ', ' . $hoje;

// Campos do contrato
$valorHonorarios = ''; $valorExtenso = ''; $valorParcela = ''; $numParcelas = '';
$formaPagamento = ''; $diaVencimento = ''; $mesInicio = ''; $cidadeForo = ''; $estadoForo = '';
$dataContrato = ''; $tipoCobranca = 'fixo'; $percentualRisco = '30'; $baseRisco = 'do proveito econômico obtido';

// POST = editor submetido
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome = $_POST['nome'] ?? $nome;
    $cpf = $_POST['cpf'] ?? $cpf;
    $profissao = $_POST['profissao'] ?? $profissao;
    $estadoCivil = $_POST['estado_civil'] ?? $estadoCivil;
    $enderecoCompleto = $_POST['endereco_completo'] ?? $enderecoCompleto;
    $email = $_POST['email'] ?? $email;
    $phone = $_POST['phone'] ?? $phone;
    $childNames = $_POST['child_names'] ?? $childNames;
    $cidadeData = $_POST['cidade_data'] ?? $cidadeData;
    $valorHonorarios = $_POST['valor_honorarios'] ?? '';
    $valorExtenso = $_POST['valor_extenso'] ?? '';
    $valorParcela = $_POST['valor_parcela'] ?? '';
    $numParcelas = $_POST['num_parcelas'] ?? '';
    $formaPagamento = $_POST['forma_pagamento'] ?? '';
    $diaVencimento = $_POST['dia_vencimento'] ?? '';
    $mesInicio = $_POST['mes_inicio'] ?? '';
    $cidadeForo = $_POST['cidade_foro'] ?? '';
    $estadoForo = $_POST['estado_foro'] ?? '';
    $dataContrato = $_POST['data_contrato'] ?? '';
    $tipoCobranca = $_POST['tipo_cobranca'] ?? 'fixo';
    $percentualRisco = $_POST['percentual_risco'] ?? '30';
    $baseRisco = $_POST['base_risco'] ?? 'do proveito econômico obtido';
    if ($acaoTexto === '' && isset($_POST['acao_custom'])) $acaoTexto = strtoupper($_POST['acao_custom']);
}

// Buscar dados do caso/processo se veio do módulo operacional
$caseData = null;
$caseIdDoc = (int)($_GET['case_id'] ?? 0);
if ($caseIdDoc) {
    $stmtCase = $pdo->prepare('SELECT case_number, court, comarca, comarca_uf, regional, parte_re_nome, parte_re_cpf_cnpj, case_type FROM cases WHERE id = ?');
    $stmtCase->execute(array($caseIdDoc));
    $caseData = $stmtCase->fetch();
}

// Campos extras para juntada/ciência — pré-preenchidos com dados do processo
$numeroProcesso = $_POST['numero_processo'] ?? ($_GET['numero_processo'] ?? ($caseData ? ($caseData['case_number'] ?: '') : ''));
// Montar vara com regional: "1ª Vara de Família da Comarca do Rio de Janeiro - Regional de Madureira"
$varaFromCase = '';
if ($caseData) {
    $varaFromCase = $caseData['court'] ?: '';
    if ($varaFromCase && isset($caseData['regional']) && $caseData['regional']) {
        // Se a vara já não menciona regional, adicionar
        if (stripos($varaFromCase, 'regional') === false) {
            $comarcaNome = $caseData['comarca'] ?: '';
            if ($comarcaNome && stripos($varaFromCase, $comarcaNome) === false) {
                $varaFromCase .= ' da Comarca de ' . strtoupper($comarcaNome);
            }
            $varaFromCase .= ' - REGIONAL DE ' . strtoupper($caseData['regional']);
        }
    }
}
$varaJuizo = $_POST['vara_juizo'] ?? ($_GET['vara_juizo'] ?? $varaFromCase);
$listaDocumentos = $_POST['lista_documentos'] ?? '';
$justificativaJuntada = $_POST['justificativa_juntada'] ?? '';
$objetoCiencia = $_POST['objeto_ciencia'] ?? '';
$reservaManifestacao = $_POST['reserva_manifestacao'] ?? 'sim';
$nomeGenitor = $_POST['nome_genitor'] ?? '';
$cpfGenitor = $_POST['cpf_genitor'] ?? '';
$nomeReu = $_POST['nome_reu'] ?? ($caseData ? ($caseData['parte_re_nome'] ?: '') : '');
$whatsappReu = $_POST['whatsapp_reu'] ?? '';
$tipoAcaoCitacao = $_POST['tipo_acao_citacao'] ?? ($caseData ? ($caseData['case_type'] ?: '') : '');
$justificativaCitacao = $_POST['justificativa_citacao'] ?? '';

$showEditor = ($_SERVER['REQUEST_METHOD'] !== 'POST');
$isMenor = ($outorgante === 'menor');
$isDefesa = ($outorgante === 'defesa');
$isIntercorrente = in_array($tipo, array('juntada', 'ciencia', 'prevjud', 'citacao_whatsapp'));
$logoUrl = url('assets/img/logo.png');

// Auto-atualizar cadastro do cliente com dados preenchidos no formulário
if (!$showEditor && $clientId) {
    $updates = array();
    $params_up = array();
    $camposMap = array(
        'cpf' => 'cpf', 'email' => 'email', 'phone' => 'phone',
        'profissao' => 'profession', 'estado_civil' => 'marital_status',
        'rg' => 'rg',
    );
    foreach ($camposMap as $postKey => $dbCol) {
        $val = trim($_POST[$postKey] ?? '');
        if ($val && empty($client[$dbCol])) {
            $updates[] = "$dbCol = ?";
            $params_up[] = $val;
        }
    }
    // Endereço
    $endPost = trim($_POST['endereco_completo'] ?? '');
    if ($endPost && empty($client['address_street'])) {
        $updates[] = "address_street = ?";
        $params_up[] = $endPost;
    }
    if (!empty($updates)) {
        $params_up[] = $clientId;
        $pdo->prepare("UPDATE clients SET " . implode(', ', $updates) . " WHERE id = ?")->execute($params_up);
    }
}

// Salvar no histórico quando gera o documento final
if (!$showEditor) {
    try {
        $params = $_POST;
        unset($params['csrf_token']);
        $pdo->prepare(
            "INSERT INTO document_history (client_id, doc_type, doc_label, tipo_acao, generated_by, params_json) VALUES (?,?,?,?,?,?)"
        )->execute(array(
            $clientId,
            $tipo,
            isset($typeLabels[$tipo]) ? $typeLabels[$tipo] : $tipo,
            $tipoAcao ?: null,
            function_exists('current_user_id') ? current_user_id() : null,
            json_encode($params, JSON_UNESCAPED_UNICODE)
        ));
    } catch (Exception $e) { /* tabela pode não existir ainda */ }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { box-sizing:border-box; margin:0; padding:0; }
        body { font-family:'Open Sans',serif; color:#1a1a1a; background:#e5e7eb; }
        .toolbar { background:#052228; color:#fff; padding:.6rem 1.5rem; display:flex; align-items:center; justify-content:space-between; gap:.5rem; position:sticky; top:0; z-index:100; flex-wrap:wrap; }
        .toolbar a, .toolbar button { color:#fff; background:rgba(255,255,255,.15); border:none; padding:.45rem .85rem; border-radius:8px; cursor:pointer; font-family:inherit; font-size:.78rem; font-weight:600; text-decoration:none; display:inline-flex; align-items:center; gap:.3rem; }
        .toolbar a:hover, .toolbar button:hover { background:rgba(255,255,255,.25); }
        .toolbar .btn-zap { background:#25D366; }
        .editor { max-width:700px; margin:1.5rem auto; background:#fff; padding:1.5rem; border-radius:16px; box-shadow:0 4px 20px rgba(0,0,0,.1); }
        .editor h3 { font-size:1rem; color:#052228; margin-bottom:.75rem; }
        .editor .row { display:grid; grid-template-columns:1fr 1fr; gap:.75rem; margin-bottom:.75rem; }
        .editor label { font-size:.72rem; font-weight:700; color:#6b7280; text-transform:uppercase; display:block; margin-bottom:.2rem; }
        .editor input, .editor textarea, .editor select { width:100%; padding:.45rem .65rem; font-family:inherit; font-size:.82rem; border:1.5px solid #e5e7eb; border-radius:8px; outline:none; }
        .editor input:focus, .editor select:focus { border-color:#d7ab90; }
        .editor .section { margin-top:1rem; padding-top:1rem; border-top:2px solid #d7ab90; }
        .editor .section h4 { font-size:.85rem; color:#6a3c2c; margin-bottom:.5rem; }
        .btn-gen { width:100%; padding:.8rem; background:linear-gradient(135deg,#052228,#173d46); color:#fff; border:none; border-radius:12px; font-size:.9rem; font-weight:700; cursor:pointer; margin-top:1rem; }
        .page { max-width:210mm; margin:2rem auto; background:#fff; padding:50px 65px; box-shadow:0 4px 20px rgba(0,0,0,.15); line-height:1.7; font-size:12.5px; }
        .page-header { text-align:center; margin-bottom:1.5rem; padding-bottom:1rem; border-bottom:2px solid #b8956e; }
        .page-header img { max-width:280px; margin:0 auto .5rem; display:block; }
        .doc-title { text-align:center; font-size:14px; font-weight:800; color:#052228; text-decoration:underline; margin-bottom:1.5rem; letter-spacing:1px; }
        .doc-body { text-align:justify; }
        .doc-body p { margin-bottom:.7rem; }
        .doc-body strong { color:#052228; }
        .doc-body .no-indent { text-indent:0; }
        .local-data { text-align:center; margin-top:2rem; font-size:12px; }
        .assinatura { text-align:center; margin-top:3rem; }
        .assinatura .linha { border-top:1px solid #1a1a1a; width:320px; margin:0 auto .4rem; }
        .assinatura .nome-ass { font-weight:700; font-size:12px; }
        .page-footer { margin-top:2rem; padding-top:1rem; border-top:2px solid #b8956e; text-align:center; font-size:10px; color:#6b7280; }
        @media print { body{background:#fff;} .toolbar,.editor{display:none !important;} .page{box-shadow:none;margin:0;padding:40px 55px;} }
    </style>
</head>
<body>

<?php if ($showEditor): ?>
<div class="toolbar">
    <a href="<?= module_url('documentos') ?>">← Voltar</a>
    <span style="font-size:.78rem;opacity:.7;"><?= e($pageTitle) ?></span>
</div>

<div class="editor">
    <h3>✏️ Revise os dados antes de gerar</h3>
    <form method="POST">
        <div class="row">
            <div><label>Nome completo</label><input name="nome" value="<?= e($nome) ?>" placeholder="Preencha o nome..." style="<?= $nome ? '' : 'border-color:#d97706;' ?>"></div>
            <div><label>CPF</label><input name="cpf" value="<?= e($cpf) ?>" placeholder="Preencha o CPF..." style="<?= $cpf ? '' : 'border-color:#d97706;' ?>"></div>
        </div>

        <?php if (!$isDefesa && !$isIntercorrente): ?>
        <div style="margin-bottom:.75rem;">
            <label>Endereço completo</label>
            <input name="endereco_completo" value="<?= e($enderecoCompleto) ?>" placeholder="Preencha o endereço..." style="<?= $enderecoCompleto ? '' : 'border-color:#d97706;' ?>">
        </div>
        <div class="row">
            <div><label>E-mail</label><input name="email" value="<?= e($email) ?>" placeholder="Preencha o e-mail..." style="<?= $email ? '' : 'border-color:#d97706;' ?>"></div>
            <div><label>Telefone</label><input name="phone" value="<?= e($phone) ?>" placeholder="Preencha o telefone..." style="<?= $phone ? '' : 'border-color:#d97706;' ?>"></div>
        </div>
        <?php endif; ?>

        <?php if (!$isIntercorrente): ?>
        <div class="row">
            <div><label>Profissão</label><input name="profissao" value="<?= e($profissao) ?>" placeholder="Preencha..." style="<?= $profissao ? '' : 'border-color:#d97706;' ?>"></div>
            <div><label>Estado civil</label><input name="estado_civil" value="<?= e($estadoCivil) ?>" placeholder="Preencha..." style="<?= $estadoCivil ? '' : 'border-color:#d97706;' ?>"></div>
        </div>
        <?php endif; ?>

        <p style="font-size:.7rem;color:#d97706;margin-bottom:.75rem;">⚠ Campos com borda laranja precisam ser preenchidos</p>

        <div style="margin-bottom:.75rem;">
            <label>Local e data</label>
            <input name="cidade_data" value="<?= e($cidadeData) ?>">
        </div>

        <?php if ($isMenor): ?>
        <div class="section">
            <h4>👶 Nome(s) do(s) menor(es) outorgante(s)</h4>
            <div style="margin-bottom:.75rem;">
                <label>Nome(s) da(s) criança(s) — separar por " E " se mais de um</label>
                <input name="child_names" value="<?= e($childNames) ?>" placeholder="Ex: STHEFANY VITÓRIA E MAITÊ SOPHIA">
            </div>
        </div>
        <?php endif; ?>

        <?php if ($tipo === 'substabelecimento'): ?>
        <div class="section">
            <h4>🔄 Tipo de substabelecimento</h4>
            <div style="display:flex;gap:.5rem;margin-bottom:.75rem;">
                <label style="flex:1;display:flex;align-items:center;gap:.3rem;font-size:.82rem;cursor:pointer;padding:.5rem .8rem;border:1.5px solid #e5e7eb;border-radius:8px;">
                    <input type="radio" name="sem_reserva" value="" checked style="width:auto;"> Com reserva de poderes
                </label>
                <label style="flex:1;display:flex;align-items:center;gap:.3rem;font-size:.82rem;cursor:pointer;padding:.5rem .8rem;border:1.5px solid #e5e7eb;border-radius:8px;">
                    <input type="radio" name="sem_reserva" value="1" style="width:auto;"> Sem reserva de poderes
                </label>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!$acaoTexto && ($tipo === 'procuracao' || $tipo === 'contrato')): ?>
        <div class="section">
            <h4>⚖️ Tipo de ação (especificar)</h4>
            <input name="acao_custom" placeholder="Ex: AÇÃO DE DIVÓRCIO CONSENSUAL">
        </div>
        <?php endif; ?>

        <?php if ($tipo === 'contrato'): ?>
        <div class="section">
            <h4>📝 Dados financeiros do contrato</h4>

            <!-- Tipo: fixo ou risco -->
            <div style="margin-bottom:.75rem;">
                <label>Tipo de cobrança</label>
                <div style="display:flex;gap:.5rem;">
                    <label style="display:flex;align-items:center;gap:.3rem;font-size:.82rem;cursor:pointer;padding:.4rem .8rem;border:1.5px solid #e5e7eb;border-radius:8px;" id="lbl_fixo">
                        <input type="radio" name="tipo_cobranca" value="fixo" checked onchange="toggleRisco()"> 💰 Honorários fixos
                    </label>
                    <label style="display:flex;align-items:center;gap:.3rem;font-size:.82rem;cursor:pointer;padding:.4rem .8rem;border:1.5px solid #e5e7eb;border-radius:8px;" id="lbl_risco">
                        <input type="radio" name="tipo_cobranca" value="risco" onchange="toggleRisco()"> 🎯 Contrato de risco
                    </label>
                </div>
            </div>

            <!-- Honorários fixos -->
            <div id="campos_fixo">
                <div class="row">
                    <div><label>Valor total (R$)</label><input name="valor_honorarios" id="valorTotal" placeholder="Digite o valor..." oninput="mascaraReal(this)" style="font-size:.95rem;font-weight:600;"></div>
                    <div><label>Valor por extenso <span style="color:#059669;font-size:.6rem;">(auto)</span></label><input name="valor_extenso" id="valorExtenso" placeholder="Gerado ao digitar o valor..." style="color:#059669;" readonly></div>
                </div>
                <div class="row">
                    <div><label>Nº de parcelas</label><input name="num_parcelas" id="numParcelas" placeholder="Preencha..." type="number" min="1" oninput="calcParcela()"></div>
                    <div><label>Valor parcela <span style="color:#059669;font-size:.6rem;">(auto)</span></label><input name="valor_parcela" id="valorParcela" placeholder="Calculado automaticamente..." style="color:#059669;" readonly></div>
                </div>
                <div class="row">
                    <div>
                        <label>Forma de pagamento</label>
                        <select name="forma_pagamento">
                            <option value="BOLETO BANCÁRIO">Boleto Bancário</option>
                            <option value="PIX">PIX</option>
                            <option value="CARTÃO DE CRÉDITO">Cartão de Crédito</option>
                            <option value="TRANSFERÊNCIA BANCÁRIA">Transferência</option>
                        </select>
                    </div>
                    <div><label>Dia do vencimento</label><input name="dia_vencimento" placeholder="Preencha..." type="number" min="1" max="31"></div>
                </div>
                <div class="row">
                    <div><label>Mês de início</label><input name="mes_inicio" placeholder="Preencha (ex: Abril/2026)"></div>
                    <div></div>
                </div>
            </div>

            <!-- Contrato de risco -->
            <div id="campos_risco" style="display:none;">
                <div class="row">
                    <div><label>Percentual de êxito (%)</label><input name="percentual_risco" value="30" type="number" min="1" max="100"></div>
                    <div><label>Base de cálculo</label><input name="base_risco" value="do proveito econômico obtido" placeholder="do proveito econômico obtido"></div>
                </div>
                <p style="font-size:.75rem;color:#6b7280;margin-top:.25rem;">O escritório receberá o percentual acima sobre o valor efetivamente obtido/recuperado.</p>
            </div>

            <div class="row" style="margin-top:.75rem;">
                <div><label>Cidade do foro</label><input name="cidade_foro" value="<?= e($cidade) ?>" placeholder="Resende"></div>
                <div><label>Estado do foro</label><input name="estado_foro" value="<?= e($uf) ?>" placeholder="RJ"></div>
            </div>
            <div><label>Data do contrato</label><input name="data_contrato" value="<?= e($cidadeData) ?>"></div>
        </div>

        <script>
        function mascaraReal(el) {
            var v = el.value.replace(/\D/g, '');
            if (!v) { el.value = ''; return; }
            v = (parseInt(v) / 100).toFixed(2);
            v = v.replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
            el.value = 'R$ ' + v;
            if (el.id === 'valorTotal') { calcParcela(); gerarExtenso(); }
        }

        function calcParcela() {
            var totalStr = document.getElementById('valorTotal').value.replace(/[R$\s.]/g, '').replace(',', '.');
            var n = parseInt(document.getElementById('numParcelas').value) || 0;
            if (totalStr && n > 0) {
                var total = parseFloat(totalStr);
                var parcela = (total / n).toFixed(2);
                var parcelaFmt = parcela.replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
                document.getElementById('valorParcela').value = 'R$ ' + parcelaFmt;
            }
            gerarExtenso();
        }

        function gerarExtenso() {
            var totalStr = document.getElementById('valorTotal').value.replace(/[R$\s.]/g, '').replace(',', '.');
            var total = parseFloat(totalStr) || 0;
            if (total > 0) {
                document.getElementById('valorExtenso').value = valorPorExtenso(total);
            }
        }

        function valorPorExtenso(valor) {
            var inteiro = Math.floor(valor);
            var centavos = Math.round((valor - inteiro) * 100);

            var un = ['','um','dois','três','quatro','cinco','seis','sete','oito','nove'];
            var d1 = ['dez','onze','doze','treze','quatorze','quinze','dezesseis','dezessete','dezoito','dezenove'];
            var d2 = ['','','vinte','trinta','quarenta','cinquenta','sessenta','setenta','oitenta','noventa'];
            var c = ['','cento','duzentos','trezentos','quatrocentos','quinhentos','seiscentos','setecentos','oitocentos','novecentos'];

            function extenso1a999(n) {
                if (n === 0) return '';
                if (n === 100) return 'cem';
                var partes = [];
                var centena = Math.floor(n / 100);
                var resto = n % 100;
                var dezena = Math.floor(resto / 10);
                var unidade = resto % 10;
                if (centena > 0) partes.push(c[centena]);
                if (resto >= 10 && resto <= 19) {
                    partes.push(d1[resto - 10]);
                } else {
                    if (dezena > 0) partes.push(d2[dezena]);
                    if (unidade > 0) partes.push(un[unidade]);
                }
                return partes.join(' e ');
            }

            if (inteiro === 0 && centavos === 0) return 'zero reais';

            var partes = [];

            var milhoes = Math.floor(inteiro / 1000000);
            var milhares = Math.floor((inteiro % 1000000) / 1000);
            var unidades = inteiro % 1000;

            if (milhoes > 0) {
                partes.push(extenso1a999(milhoes) + (milhoes === 1 ? ' milhão' : ' milhões'));
            }
            if (milhares > 0) {
                partes.push(extenso1a999(milhares) + ' mil');
            }
            if (unidades > 0) {
                partes.push(extenso1a999(unidades));
            }

            var resultado = '';
            if (partes.length === 1) resultado = partes[0];
            else if (partes.length === 2) resultado = partes[0] + (unidades > 0 && unidades < 100 ? ' e ' : ', ') + partes[1];
            else resultado = partes[0] + ', ' + partes[1] + ' e ' + partes[2];

            resultado += inteiro === 1 ? ' real' : ' reais';

            if (centavos > 0) {
                resultado += ' e ' + extenso1a999(centavos) + (centavos === 1 ? ' centavo' : ' centavos');
            }

            return resultado;
        }

        function toggleRisco() {
            var isRisco = document.querySelector('input[name="tipo_cobranca"]:checked').value === 'risco';
            document.getElementById('campos_fixo').style.display = isRisco ? 'none' : 'block';
            document.getElementById('campos_risco').style.display = isRisco ? 'block' : 'none';
        }
        </script>
        <?php endif; ?>

        <?php if ($tipo === 'juntada'): ?>
        <div class="section">
            <h4>Dados da Juntada</h4>
            <div class="row">
                <div><label>Nº do processo</label><input name="numero_processo" value="<?= e($numeroProcesso) ?>" placeholder="0000000-00.0000.0.00.0000"></div>
                <div><label>Vara / Juízo</label><input name="vara_juizo" value="<?= e($varaJuizo) ?>" placeholder="Ex: 1ª Vara de Família de Barra Mansa"></div>
            </div>
            <div style="margin-bottom:.75rem;">
                <label>Lista dos documentos (um por linha)</label>
                <textarea name="lista_documentos" rows="5" placeholder="Ex:&#10;Certidão de nascimento atualizada&#10;Comprovante de residência&#10;Declaração de IR 2025"><?= e($listaDocumentos) ?></textarea>
            </div>
            <div style="margin-bottom:.75rem;">
                <label>Justificativa da juntada</label>
                <textarea name="justificativa_juntada" rows="3" placeholder="Por que esses documentos são relevantes?"><?= e($justificativaJuntada) ?></textarea>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($tipo === 'ciencia'): ?>
        <div class="section">
            <h4>Dados da Ciência</h4>
            <div class="row">
                <div><label>Nº do processo</label><input name="numero_processo" value="<?= e($numeroProcesso) ?>" placeholder="0000000-00.0000.0.00.0000"></div>
                <div><label>Vara / Juízo</label><input name="vara_juizo" value="<?= e($varaJuizo) ?>" placeholder="Ex: 1ª Vara de Família de Barra Mansa"></div>
            </div>
            <div style="margin-bottom:.75rem;">
                <label>Objeto da ciência (decisão, despacho, intimação...)</label>
                <input name="objeto_ciencia" value="<?= e($objetoCiencia) ?>" placeholder="Ex: r. decisão de id. 123456 que deferiu a tutela de urgência">
            </div>
            <div style="margin-bottom:.75rem;">
                <label>Reservar direito de manifestação posterior?</label>
                <select name="reserva_manifestacao">
                    <option value="sim" <?= $reservaManifestacao === 'sim' ? 'selected' : '' ?>>Sim</option>
                    <option value="nao" <?= $reservaManifestacao === 'nao' ? 'selected' : '' ?>>Não</option>
                </select>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($tipo === 'prevjud'): ?>
        <div class="section">
            <h4>Dados da Pesquisa PREVJUD</h4>
            <div class="row">
                <div><label>Nº do processo</label><input name="numero_processo" value="<?= e($numeroProcesso) ?>" placeholder="0000000-00.0000.0.00.0000"></div>
                <div><label>Vara / Juízo</label><input name="vara_juizo" value="<?= e($varaJuizo) ?>" placeholder="Ex: 2ª Vara de Família de Resende"></div>
            </div>
            <div class="row">
                <div><label>Nome completo do(a) pesquisado(a) (genitor/genitora)</label><input name="nome_genitor" value="<?= e($nomeGenitor) ?>" placeholder="Nome de quem paga a pensão" required></div>
                <div><label>CPF do(a) pesquisado(a)</label><input name="cpf_genitor" value="<?= e($cpfGenitor) ?>" placeholder="000.000.000-00" required></div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($tipo === 'citacao_whatsapp'): ?>
        <div class="section">
            <h4>Dados para Citacao por WhatsApp</h4>
            <div class="row">
                <div><label>Nr do processo</label><input name="numero_processo" value="<?= e($numeroProcesso) ?>" placeholder="0000000-00.0000.0.00.0000" required></div>
                <div><label>Vara / Juizo</label><input name="vara_juizo" value="<?= e($varaJuizo) ?>" placeholder="Ex: 2a Vara de Familia de Resende" required></div>
            </div>
            <div class="row">
                <div><label>Nome completo do reu/ra</label><input name="nome_reu" value="<?= e($nomeReu) ?>" placeholder="Nome da parte re" required></div>
                <div><label>Telefone/WhatsApp do reu/ra</label><input name="whatsapp_reu" value="<?= e($whatsappReu) ?>" placeholder="(00) 00000-0000" required></div>
            </div>
            <div class="row">
                <div><label>Tipo de acao</label>
                    <select name="tipo_acao_citacao">
                        <?php
                        $opCit = array(
                            'Alimentos' => 'Alimentos',
                            'Revisional de Alimentos' => 'Revisional de Alimentos',
                            'Execucao de Alimentos' => 'Execução de Alimentos',
                            'Divorcio' => 'Divórcio',
                            'Divorcio Consensual' => 'Divórcio Consensual',
                            'Divorcio Litigioso' => 'Divórcio Litigioso',
                            'Guarda' => 'Guarda',
                            'Guarda Compartilhada' => 'Guarda Compartilhada',
                            'Regulamentacao de Convivencia' => 'Regulamentação de Convivência',
                            'Investigacao de Paternidade' => 'Investigação de Paternidade',
                            'Consumidor' => 'Consumidor',
                            'Indenizacao' => 'Indenização',
                            'Obrigacao de Fazer' => 'Obrigação de Fazer',
                            'Cobranca' => 'Cobrança',
                            'Usucapiao' => 'Usucapião',
                        );
                        foreach ($opCit as $oc => $ocLabel): ?>
                        <option value="<?= e($oc) ?>" <?= $tipoAcaoCitacao === $oc ? 'selected' : '' ?>><?= e($ocLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div><label>Justificativa (opcional)</label><input name="justificativa_citacao" value="" placeholder="Ex: Reu nao encontrado para citacao pessoal"></div>
            </div>
        </div>
        <?php endif; ?>

        <button type="submit" class="btn-gen">Gerar Documento →</button>
    </form>
</div>

<?php else: ?>
<!-- DOCUMENTO FINAL -->
<div class="toolbar">
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
        <a href="<?= module_url('documentos') ?>">← Voltar</a>
        <a href="<?= module_url('documentos', 'gerar.php?tipo=' . urlencode($tipo) . '&client_id=' . $clientId . '&tipo_acao=' . urlencode($tipoAcao) . '&outorgante=' . urlencode($outorgante)) ?>">✏️ Editar</a>
        <button onclick="window.print()">🖨️ Imprimir / PDF</button>
        <button onclick="copiarConteudo()" style="background:#059669;">📋 Copiar conteúdo</button>
        <?php if ($phone): ?>
            <a href="https://wa.me/55<?= preg_replace('/\D/', '', $phone) ?>" target="_blank" class="btn-zap">💬 WhatsApp</a>
        <?php endif; ?>
    </div>
</div>

<div class="page">
    <div class="page-header">
        <img src="<?= $logoUrl ?>" alt="Ferreira &amp; Sá" onerror="this.outerHTML='<h2 style=color:#052228>FERREIRA &amp; SÁ</h2><p style=font-size:10px;color:#6b7280>ADVOCACIA ESPECIALIZADA</p>'">
    </div>

    <div class="doc-body">
    <?php
    $d = array(
        'nome' => $nome, 'cpf' => $cpf, 'rg' => $rg, 'email' => $email, 'phone' => $phone,
        'profissao' => $profissao, 'estado_civil' => $estadoCivil, 'endereco' => $enderecoCompleto,
        'cidade' => $cidade, 'uf' => $uf, 'outorgante' => $outorgante,
        'child_names' => $childNames, 'acao_texto' => $acaoTexto, 'cidade_data' => $cidadeData,
        'valor_honorarios' => $valorHonorarios, 'valor_extenso' => $valorExtenso,
        'valor_parcela' => $valorParcela, 'num_parcelas' => $numParcelas,
        'forma_pagamento' => $formaPagamento, 'dia_vencimento' => $diaVencimento,
        'mes_inicio' => $mesInicio, 'cidade_foro' => $cidadeForo, 'estado_foro' => $estadoForo,
        'data_contrato' => $dataContrato,
        'tipo_cobranca' => $tipoCobranca,
        'percentual_risco' => $percentualRisco,
        'base_risco' => $baseRisco,
        'numero_processo' => $numeroProcesso,
        'vara_juizo' => $varaJuizo,
        'lista_documentos' => $listaDocumentos,
        'justificativa' => $justificativaJuntada,
        'objeto_ciencia' => $objetoCiencia,
        'reserva_manifestacao' => $reservaManifestacao,
        'nome_genitor' => $nomeGenitor,
        'cpf_genitor' => $cpfGenitor,
        'nome_reu' => $nomeReu,
        'whatsapp_reu' => $whatsappReu,
        'tipo_acao_citacao' => $tipoAcaoCitacao,
        'justificativa_citacao' => $justificativaCitacao,
    );

    if ($tipo === 'procuracao') echo template_procuracao($d);
    elseif ($tipo === 'contrato') echo template_contrato($d);
    elseif ($tipo === 'substabelecimento') echo template_substabelecimento($d);
    elseif ($tipo === 'hipossuficiencia') echo template_hipossuficiencia($d);
    elseif ($tipo === 'isencao_ir') echo template_isencao_ir($d);
    elseif ($tipo === 'residencia') echo template_residencia($d);
    elseif ($tipo === 'acordo') echo template_acordo($d);
    elseif ($tipo === 'juntada') echo template_juntada($d);
    elseif ($tipo === 'ciencia') echo template_ciencia($d);
    elseif ($tipo === 'prevjud') echo template_prevjud($d);
    elseif ($tipo === 'citacao_whatsapp') echo template_citacao_whatsapp($d);
    ?>
    </div>

    <div class="page-footer">
        <div>📍 Rio de Janeiro / RJ &nbsp;&nbsp; Barra Mansa / RJ &nbsp;&nbsp; Volta Redonda / RJ &nbsp;&nbsp; Resende / RJ &nbsp;&nbsp; São Paulo / SP</div>
        <div>(24) 9.9205-0096 / (11) 2110-5438</div>
        <div>🌐 www.ferreiraesa.com.br &nbsp;&nbsp; ✉ contato@ferreiraesa.com.br</div>
    </div>
</div>
<?php endif; ?>

<script>
function copiarConteudo() {
    var el = document.querySelector('.doc-body');
    if (!el) return;
    var range = document.createRange();
    range.selectNodeContents(el);
    var sel = window.getSelection();
    sel.removeAllRanges();
    sel.addRange(range);
    document.execCommand('copy');
    sel.removeAllRanges();

    // Feedback visual no botão
    var btns = document.querySelectorAll('.toolbar button');
    btns.forEach(function(b) {
        if (b.textContent.indexOf('Copiar') !== -1) {
            var original = b.innerHTML;
            b.innerHTML = '✓ Copiado!';
            b.style.background = '#065f46';
            setTimeout(function() { b.innerHTML = original; b.style.background = '#059669'; }, 2000);
        }
    });
}
</script>

</body>
</html>
