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

$validTypes = array('procuracao', 'contrato', 'substabelecimento', 'hipossuficiencia', 'isencao_ir', 'residencia', 'acordo', 'juntada', 'ciencia', 'prevjud', 'citacao_whatsapp', 'habilitacao', 'audiencia_remota', 'mandado_pagamento', 'averbacao_sentenca', 'renuncia_poderes', 'desistencia_acao');
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
    'habilitacao' => 'Petição de Habilitação nos Autos',
    'audiencia_remota' => 'Petição de Audiência Remota/Híbrida',
    'mandado_pagamento' => 'Requerimento de Mandado de Pagamento',
    'averbacao_sentenca' => 'Averbação de Sentença — Divórcio',
    'renuncia_poderes' => 'Renúncia aos Poderes Outorgados',
    'desistencia_acao' => 'Pedido de Desistência da Ação',
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
    'salario_maternidade' => 'AÇÃO PREVIDENCIÁRIA — SALÁRIO-MATERNIDADE',
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
    // Campos específicos do Salário-Maternidade (personalização)
    $smModo = $_POST['sm_modo'] ?? 'padrao';
    $smTipoHonorario = $_POST['sm_tipo_honorario'] ?? 'percentual';
    $smPercentual = $_POST['sm_percentual'] ?? '30';
    $smNumParcelasBeneficio = $_POST['sm_num_parcelas_beneficio'] ?? '4';
    $smValorTotal = $_POST['sm_valor_total'] ?? '';
    $smNumParcelas = $_POST['sm_num_parcelas'] ?? '';
    $smValorParcela = $_POST['sm_valor_parcela'] ?? '';
    $smFormaPagamento = $_POST['sm_forma_pagamento'] ?? 'PIX';
    $smObservacao = $_POST['sm_observacao'] ?? '';
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
                $varaFromCase .= ' da Comarca de ' . mb_strtoupper($comarcaNome, 'UTF-8');
            }
            $varaFromCase .= ' - REGIONAL DE ' . mb_strtoupper($caseData['regional'], 'UTF-8');
        }
    }
}
$varaJuizo = $_POST['vara_juizo'] ?? ($_GET['vara_juizo'] ?? $varaFromCase);
$comarcaDoc = $_POST['comarca_doc'] ?? ($caseData ? ($caseData['comarca'] ?: '') : '');
$comarcaUfDoc = $_POST['comarca_uf_doc'] ?? ($caseData ? ($caseData['comarca_uf'] ?: 'RJ') : 'RJ');

// Campos substabelecimento
$semReserva = isset($_POST['sem_reserva']) && $_POST['sem_reserva'];
$substabelecente = $_POST['substabelecente'] ?? 'amanda_para_luiz';
$substAdvNome = $_POST['subst_adv_nome'] ?? '';
$substAdvOab = $_POST['subst_adv_oab'] ?? '';
$substAdvSeccional = $_POST['subst_adv_seccional'] ?? 'RJ';
$substAdvEmail = $_POST['subst_adv_email'] ?? '';
$substAdvEndereco = $_POST['subst_adv_endereco'] ?? '';
$substAdvNacionalidade = $_POST['subst_adv_nacionalidade'] ?? 'brasileiro(a)';
$substAdvTelefone = $_POST['subst_adv_telefone'] ?? '';

// Campos habilitação
$tipoAcaoHab = $_POST['tipo_acao_hab'] ?? ($caseData ? ($caseData['case_type'] ?: '') : '');
$repLegal = $_POST['rep_legal'] ?? 'nao';
$nomeParteContraria = $_POST['nome_parte_contraria'] ?? ($caseData ? ($caseData['parte_re_nome'] ?: '') : '');
$papelCliente = $_POST['papel_cliente'] ?? 'autor';
$pleiteanteHab = $_POST['pleiteante_hab'] ?? 'proprio';
$qualifMenor = $_POST['qualif_menor'] ?? 'impubere';
$tipoHabProc = $_POST['tipo_hab_proc'] ?? 'plena';

// Campos audiência remota/híbrida (ANTES do buscar_partes_caso para não sobrescrever)
$motivoAudiencia = $_POST['motivo_audiencia'] ?? '';
$modalidadeAudiencia = $_POST['modalidade_audiencia'] ?? 'remota_ou_hibrida';
$emailsAudiencia = $_POST['emails_audiencia'] ?? ($email ? $email : '');
$papelClienteAud = $_POST['papel_cliente_aud'] ?? 'autor';
$pleiteanteAud = $_POST['pleiteante_aud'] ?? 'proprio';
$childNamesAud = $_POST['child_names_aud'] ?? '';
$qualifMenorAud = $_POST['qualif_menor_aud'] ?? 'impubere';
$cidadeAssinaturaAud = $_POST['cidade_assinatura_aud'] ?? '';
if (!in_array($cidadeAssinaturaAud, array('Volta Redonda','Resende'), true)) $cidadeAssinaturaAud = '';
$repLegalAud = $_POST['rep_legal_aud'] ?? 'nao';
$dataAudiencia = $_POST['data_audiencia'] ?? '';

// Buscar próxima audiência do processo para pré-preencher data
if ($caseIdDoc && !$dataAudiencia && !$_POST) {
    try {
        $stmtAud = $pdo->prepare(
            "SELECT data_inicio FROM agenda_eventos
             WHERE case_id = ? AND tipo = 'audiencia'
             AND data_inicio >= NOW()
             AND status NOT IN ('cancelado','remarcado','realizado','nao_compareceu')
             ORDER BY data_inicio ASC LIMIT 1"
        );
        $stmtAud->execute(array($caseIdDoc));
        $proxAud = $stmtAud->fetchColumn();
        if ($proxAud) {
            $dataAudiencia = date('Y-m-d', strtotime($proxAud));
        }
    } catch (Exception $e) {}
}

// Buscar partes do processo para preencher dados automaticamente
$qtdMenores = 0;
if ($caseIdDoc && function_exists('buscar_partes_caso')) {
    $partesDocResult = buscar_partes_caso($caseIdDoc);
    $partesDoc = isset($partesDocResult['todas']) ? $partesDocResult['todas'] : array();
    if (!empty($partesDoc)) {
        $autoresOutros = array(); // Autores que NÃO são o cliente (possíveis menores)
        $clienteEhRepLegal = false;
        foreach ($partesDoc as $p) {
            if (isset($p['client_id']) && $p['client_id'] == $clientId) {
                $papelCliente = $p['papel'] ?: $papelCliente;
                if ($p['papel'] === 'representante_legal') $clienteEhRepLegal = true;
            }
            // Coletar TODOS os autores que NÃO são o cliente (podem ser múltiplos filhos)
            if ($p['papel'] === 'autor' && (!isset($p['client_id']) || $p['client_id'] != $clientId) && $p['nome']) {
                $autoresOutros[] = strtoupper($p['nome']);
            }
            if (in_array($p['papel'], array('reu')) && !$nomeParteContraria) {
                $nomeParteContraria = $p['nome'] ?: '';
            }
        }
        $menorAutor = !empty($autoresOutros) ? implode(' E ', $autoresOutros) : '';
        $qtdMenores = count($autoresOutros);

        // Se cliente é rep. legal e tem menor(es) como autor → pré-selecionar "menor"
        if ($clienteEhRepLegal && $menorAutor && !$_POST) {
            $pleiteanteHab = 'menor';
            $repLegal = 'sim';
            if (!$childNames) $childNames = $menorAutor;
            $papelCliente = 'autor';
            $pleiteanteAud = 'menor';
            $repLegalAud = 'sim';
            if (!$childNamesAud) $childNamesAud = $menorAutor;
            $papelClienteAud = 'autor';
        }
        // Sempre disponibilizar nomes dos autores (mesmo se cliente não é rep_legal)
        if ($menorAutor && !$_POST) {
            if (!$childNamesAud) $childNamesAud = $menorAutor;
            if (!$childNames) $childNames = $menorAutor;
        }
    }
}

// Fallback: se childNamesAud está vazio, usar childNames (form convivência/gastos)
if (!$childNamesAud && $childNames && !$_POST) {
    $childNamesAud = $childNames;
}

// Campos mandado de pagamento
$beneficiarioMandado = $_POST['beneficiario_mandado'] ?? 'escritorio';
$darQuitacao = $_POST['dar_quitacao'] ?? 'sim';
$paginaProcuracao = $_POST['pagina_procuracao'] ?? '';

// Campo averbação sentença
$gratuidadeAvb = $_POST['gratuidade_avb'] ?? 'sim';

// Renuncia aos poderes outorgados
$motivoRenuncia = $_POST['motivo_renuncia'] ?? 'razões particulares';
$reuRenuncia = $_POST['reu_renuncia'] ?? '';

// Desistencia da acao
$cenarioDesistencia = $_POST['cenario_desistencia'] ?? 'sem_contestacao';
$reuDesistencia = $_POST['reu_desistencia'] ?? '';

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
$isIntercorrente = in_array($tipo, array('juntada', 'ciencia', 'prevjud', 'citacao_whatsapp', 'habilitacao', 'audiencia_remota', 'mandado_pagamento', 'averbacao_sentenca'));
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
        .page-header { text-align:center; margin-bottom:2rem; padding-bottom:.75rem; border-bottom:3px solid #B87333; }
        .page-header img { max-width:320px; margin:0 auto .75rem; display:block; }
        .doc-title { text-align:center; font-size:14px; font-weight:800; color:#052228; text-decoration:underline; margin-bottom:1.5rem; letter-spacing:1px; }
        .doc-body { text-align:justify; }
        .doc-body p { margin-bottom:.7rem; }
        .doc-body strong { color:#052228; }
        .doc-body .no-indent { text-indent:0; }
        .local-data { text-align:center; margin-top:2rem; font-size:12px; }
        .assinatura { text-align:center; margin-top:3rem; }
        .assinatura .linha { border-top:1px solid #1a1a1a; width:320px; margin:0 auto .4rem; }
        .assinatura .nome-ass { font-weight:700; font-size:12px; }
        .page-footer { margin-top:2.5rem; padding-top:.75rem; border-top:2px solid #B87333; text-align:center; font-size:9.5px; color:#555; font-family:Calibri,sans-serif; }
        @page { size:A4; margin:1.5cm 2cm 1.5cm 2cm; }
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
            <div><label>CPF</label><input name="cpf" value="<?= e($cpf) ?>" placeholder="000.000.000-00" oninput="mascaraCPF(this)" maxlength="14" style="<?= $cpf ? '' : 'border-color:#d97706;' ?>"></div>
        </div>

        <?php if (!$isDefesa && !$isIntercorrente): ?>
        <div style="margin-bottom:.75rem;">
            <label>Endereço completo</label>
            <input name="endereco_completo" value="<?= e($enderecoCompleto) ?>" placeholder="Preencha o endereço..." style="<?= $enderecoCompleto ? '' : 'border-color:#d97706;' ?>">
        </div>
        <div class="row">
            <div><label>E-mail</label><input name="email" value="<?= e($email) ?>" placeholder="Preencha o e-mail..." style="<?= $email ? '' : 'border-color:#d97706;' ?>"></div>
            <div><label>Telefone</label><input name="phone" value="<?= e($phone) ?>" placeholder="(00) 00000-0000" oninput="mascaraTelefone(this)" maxlength="15" style="<?= $phone ? '' : 'border-color:#d97706;' ?>"></div>
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

            <h4 style="margin-top:1rem;">👤 Quem substabelece (advogado outorgante)</h4>
            <div style="display:flex;flex-direction:column;gap:.4rem;margin-bottom:.75rem;">
                <label style="display:flex;align-items:center;gap:.4rem;font-size:.82rem;cursor:pointer;padding:.5rem .8rem;border:1.5px solid #e5e7eb;border-radius:8px;">
                    <input type="radio" name="substabelecente" value="amanda_para_luiz" checked style="width:auto;" onchange="toggleSubstAdvCustom()">
                    <span><strong>Dra. Amanda Guedes Ferreira</strong> substabelece para <strong>Dr. Luiz Eduardo</strong></span>
                </label>
                <label style="display:flex;align-items:center;gap:.4rem;font-size:.82rem;cursor:pointer;padding:.5rem .8rem;border:1.5px solid #e5e7eb;border-radius:8px;">
                    <input type="radio" name="substabelecente" value="luiz_para_amanda" style="width:auto;" onchange="toggleSubstAdvCustom()">
                    <span><strong>Dr. Luiz Eduardo</strong> substabelece para <strong>Dra. Amanda Guedes Ferreira</strong></span>
                </label>
                <label style="display:flex;align-items:center;gap:.4rem;font-size:.82rem;cursor:pointer;padding:.5rem .8rem;border:1.5px solid #e5e7eb;border-radius:8px;">
                    <input type="radio" name="substabelecente" value="amanda_para_outro" style="width:auto;" onchange="toggleSubstAdvCustom()">
                    <span><strong>Dra. Amanda Guedes Ferreira</strong> substabelece para outro advogado(a)</span>
                </label>
                <label style="display:flex;align-items:center;gap:.4rem;font-size:.82rem;cursor:pointer;padding:.5rem .8rem;border:1.5px solid #e5e7eb;border-radius:8px;">
                    <input type="radio" name="substabelecente" value="luiz_para_outro" style="width:auto;" onchange="toggleSubstAdvCustom()">
                    <span><strong>Dr. Luiz Eduardo</strong> substabelece para outro advogado(a)</span>
                </label>
            </div>

            <div id="substAdvCustom" style="display:none;background:#fef3c7;border:1.5px solid #fbbf24;border-radius:10px;padding:.75rem 1rem;margin-bottom:.75rem;">
                <h4 style="margin:0 0 .5rem;color:#92400e;">📋 Dados do(a) advogado(a) substabelecido(a)</h4>
                <div class="row">
                    <div><label>Nome completo *</label><input name="subst_adv_nome" placeholder="Ex: FLAVIANE DA SILVA ASSOMPÇÃO"></div>
                    <div><label>OAB *</label><input name="subst_adv_oab" placeholder="Ex: 230711"></div>
                </div>
                <div class="row">
                    <div><label>Seccional</label><input name="subst_adv_seccional" placeholder="Ex: RJ" value="RJ" maxlength="2"></div>
                    <div><label>E-mail</label><input name="subst_adv_email" placeholder="email@exemplo.com"></div>
                </div>
                <div style="margin-bottom:.5rem;">
                    <label>Endereço profissional</label>
                    <input name="subst_adv_endereco" placeholder="Ex: Rua Albino de Almeida, 119 - Campos Elíseos, Resende-RJ, CEP 27542-040">
                </div>
                <div class="row">
                    <div><label>Nacionalidade</label><input name="subst_adv_nacionalidade" placeholder="brasileiro(a)" value="brasileiro(a)"></div>
                    <div><label>Telefone</label><input name="subst_adv_telefone" placeholder="(00) 00000-0000"></div>
                </div>
            </div>
        </div>

        <script>
        function toggleSubstAdvCustom() {
            var box = document.getElementById('substAdvCustom');
            var v = document.querySelector('input[name="substabelecente"]:checked').value;
            var precisaCustom = (v === 'amanda_para_outro' || v === 'luiz_para_outro');
            box.style.display = precisaCustom ? 'block' : 'none';
        }
        </script>
        <?php endif; ?>

        <?php if (!$acaoTexto && ($tipo === 'procuracao' || $tipo === 'contrato')): ?>
        <div class="section">
            <h4>⚖️ Tipo de ação (especificar)</h4>
            <input name="acao_custom" placeholder="Ex: AÇÃO DE DIVÓRCIO CONSENSUAL">
        </div>
        <?php endif; ?>

        <?php if ($tipo === 'contrato'): ?>
        <?php
        // O subtipo do contrato é derivado direto do tipo de ação:
        // tipo_acao = salario_maternidade  → modelo SM (Previdenciário)
        // qualquer outro                    → modelo Padrão (fixo / risco)
        $isSm = ($tipoAcao === 'salario_maternidade');
        ?>
        <input type="hidden" name="subtipo_contrato" value="<?= $isSm ? 'salario_maternidade' : 'padrao' ?>">
        <div class="section">
            <?php if ($isSm): ?>
            <div style="background:linear-gradient(135deg,#fdf2f8,#fbcfe8);border:1px solid #f9a8d4;border-radius:10px;padding:12px 16px;margin-bottom:.75rem;font-size:.85rem;color:#9f1239;">
                🤰 <strong>Modelo Salário-Maternidade (Previdenciário)</strong> — honorários no padrão são <strong>30% sobre cada uma das 4 parcelas</strong>. Você pode personalizar abaixo se houver acordo diferente com a cliente.
            </div>

            <!-- Escolha: padrão ou personalizado -->
            <div style="margin-bottom:1rem;">
                <label style="font-size:.82rem;font-weight:700;color:#9f1239;">Forma de cobrança</label>
                <div style="display:flex;gap:.6rem;flex-wrap:wrap;margin-top:.4rem;">
                    <label style="flex:1;min-width:240px;display:flex;align-items:center;gap:.4rem;cursor:pointer;padding:.55rem .8rem;border:2px solid #db2777;background:linear-gradient(135deg,#fdf2f8,#fbcfe8);border-radius:10px;font-size:.85rem;color:#9f1239;font-weight:700;" id="sm_modo_padrao_lbl">
                        <input type="radio" name="sm_modo" value="padrao" checked onchange="toggleSmModo()" style="accent-color:#db2777;">
                        🤰 Padrão — 30% sobre 4 parcelas
                    </label>
                    <label style="flex:1;min-width:240px;display:flex;align-items:center;gap:.4rem;cursor:pointer;padding:.55rem .8rem;border:2px solid #e5e7eb;background:#fff;border-radius:10px;font-size:.85rem;color:#6b7280;" id="sm_modo_custom_lbl">
                        <input type="radio" name="sm_modo" value="personalizado" onchange="toggleSmModo()" style="accent-color:#db2777;">
                        ⚙️ Personalizar
                    </label>
                </div>
            </div>

            <!-- Bloco de personalização (oculto por padrão) -->
            <div id="bloco_sm_custom" style="display:none;background:#fff7ed;border:1.5px dashed #d7ab90;border-radius:10px;padding:14px 16px;margin-bottom:1rem;">
                <div style="font-size:.82rem;font-weight:700;color:#6a3c2c;margin-bottom:.6rem;">Personalização do contrato Salário-Maternidade</div>

                <!-- Tipo de honorário: percentual ou valor fixo -->
                <div style="margin-bottom:.75rem;">
                    <label>Tipo de honorário</label>
                    <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
                        <label style="display:flex;align-items:center;gap:.3rem;font-size:.82rem;cursor:pointer;padding:.4rem .8rem;border:1.5px solid #e5e7eb;border-radius:8px;" id="sm_tipo_pct_lbl">
                            <input type="radio" name="sm_tipo_honorario" value="percentual" checked onchange="toggleSmTipo()"> 📊 Percentual sobre parcelas
                        </label>
                        <label style="display:flex;align-items:center;gap:.3rem;font-size:.82rem;cursor:pointer;padding:.4rem .8rem;border:1.5px solid #e5e7eb;border-radius:8px;" id="sm_tipo_fixo_lbl">
                            <input type="radio" name="sm_tipo_honorario" value="fixo" onchange="toggleSmTipo()"> 💰 Valor fixo
                        </label>
                    </div>
                </div>

                <!-- Campos para percentual -->
                <div id="sm_campos_pct">
                    <div class="row">
                        <div><label>Percentual (%)</label><input name="sm_percentual" type="number" min="1" max="100" step="0.01" placeholder="Ex: 30" value="30"></div>
                        <div><label>Nº de parcelas do benefício</label><input name="sm_num_parcelas_beneficio" type="number" min="1" max="12" placeholder="Ex: 4" value="4"></div>
                    </div>
                </div>

                <!-- Campos para valor fixo -->
                <div id="sm_campos_fixo" style="display:none;">
                    <div class="row">
                        <div><label>Valor total (R$)</label><input name="sm_valor_total" id="smValorTotal" placeholder="Digite o valor..." oninput="mascaraReal(this); calcSmParcela();"></div>
                        <div><label>Nº de parcelas</label><input name="sm_num_parcelas" id="smNumParcelas" type="number" min="1" placeholder="Ex: 4" oninput="calcSmParcela()"></div>
                    </div>
                    <div class="row">
                        <div><label>Valor da parcela <span style="color:#059669;font-size:.7rem;">(auto)</span></label><input name="sm_valor_parcela" id="smValorParcela" placeholder="Calculado automaticamente..." style="color:#059669;" readonly></div>
                        <div><label>Forma de pagamento</label>
                            <select name="sm_forma_pagamento">
                                <option value="PIX">PIX</option>
                                <option value="BOLETO BANCÁRIO">Boleto Bancário</option>
                                <option value="TRANSFERÊNCIA BANCÁRIA">Transferência</option>
                                <option value="CARTÃO DE CRÉDITO">Cartão de Crédito</option>
                                <option value="DESCONTO EM PRECATÓRIO/RPV">Desconto em precatório/RPV</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Observação adicional -->
                <div style="margin-top:.5rem;">
                    <label>Observação (opcional)</label>
                    <input name="sm_observacao" placeholder="Ex: 50% no recebimento administrativo + 50% no judicial">
                </div>
            </div>

            <script>
                function toggleSmModo() {
                    var modo = document.querySelector('input[name="sm_modo"]:checked').value;
                    var isCustom = (modo === 'personalizado');
                    document.getElementById('bloco_sm_custom').style.display = isCustom ? 'block' : 'none';
                    // Visual feedback nos cards
                    var lblPad = document.getElementById('sm_modo_padrao_lbl');
                    var lblCus = document.getElementById('sm_modo_custom_lbl');
                    if (isCustom) {
                        lblPad.style.borderColor = '#e5e7eb'; lblPad.style.background = '#fff'; lblPad.style.color = '#6b7280';
                        lblCus.style.borderColor = '#db2777'; lblCus.style.background = 'linear-gradient(135deg,#fdf2f8,#fbcfe8)'; lblCus.style.color = '#9f1239'; lblCus.style.fontWeight = '700';
                    } else {
                        lblPad.style.borderColor = '#db2777'; lblPad.style.background = 'linear-gradient(135deg,#fdf2f8,#fbcfe8)'; lblPad.style.color = '#9f1239';
                        lblCus.style.borderColor = '#e5e7eb'; lblCus.style.background = '#fff'; lblCus.style.color = '#6b7280'; lblCus.style.fontWeight = '500';
                    }
                }
                function toggleSmTipo() {
                    var tipo = document.querySelector('input[name="sm_tipo_honorario"]:checked').value;
                    document.getElementById('sm_campos_pct').style.display = (tipo === 'percentual') ? 'block' : 'none';
                    document.getElementById('sm_campos_fixo').style.display = (tipo === 'fixo') ? 'block' : 'none';
                }
                function calcSmParcela() {
                    var v = document.getElementById('smValorTotal').value.replace(/[^\d,]/g, '').replace(',', '.');
                    var n = parseInt(document.getElementById('smNumParcelas').value) || 0;
                    var total = parseFloat(v) || 0;
                    if (total > 0 && n > 0) {
                        var parcela = total / n;
                        document.getElementById('smValorParcela').value = 'R$ ' + parcela.toFixed(2).replace('.', ',');
                    } else {
                        document.getElementById('smValorParcela').value = '';
                    }
                }
            </script>
            <?php else: ?>
            <h4>💵 Dados financeiros do contrato</h4>
            <?php endif; ?>

            <div id="bloco_financeiro_padrao" <?= $isSm ? 'style="display:none;"' : '' ?>>
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
            </div><!-- /bloco_financeiro_padrao -->
        </div>

        <script>
        function mascaraCPF(el) {
            var v = el.value.replace(/\D/g, '').substring(0, 11);
            if (v.length > 9) v = v.replace(/(\d{3})(\d{3})(\d{3})(\d{1,2})/, '$1.$2.$3-$4');
            else if (v.length > 6) v = v.replace(/(\d{3})(\d{3})(\d{1,3})/, '$1.$2.$3');
            else if (v.length > 3) v = v.replace(/(\d{3})(\d{1,3})/, '$1.$2');
            el.value = v;
        }

        function mascaraTelefone(el) {
            var v = el.value.replace(/\D/g, '').substring(0, 11);
            if (v.length > 6) v = v.replace(/(\d{2})(\d{4,5})(\d{1,4})/, '($1) $2-$3');
            else if (v.length > 2) v = v.replace(/(\d{2})(\d{1,5})/, '($1) $2');
            else if (v.length > 0) v = v.replace(/(\d{1,2})/, '($1');
            el.value = v;
        }

        function mascaraProcesso(el) {
            var v = el.value.replace(/\D/g, '').substring(0, 20);
            // Formato CNJ: 0000000-00.0000.0.00.0000
            if (v.length > 13) v = v.replace(/(\d{7})(\d{2})(\d{4})(\d{1})(\d{2})(\d{1,4})/, '$1-$2.$3.$4.$5.$6');
            else if (v.length > 11) v = v.replace(/(\d{7})(\d{2})(\d{4})(\d{1})(\d{1,2})/, '$1-$2.$3.$4.$5');
            else if (v.length > 10) v = v.replace(/(\d{7})(\d{2})(\d{4})(\d{1,1})/, '$1-$2.$3.$4');
            else if (v.length > 9) v = v.replace(/(\d{7})(\d{2})(\d{1,4})/, '$1-$2.$3');
            else if (v.length > 7) v = v.replace(/(\d{7})(\d{1,2})/, '$1-$2');
            el.value = v;
        }

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
        // Auto-formatar campos ao carregar a página
        document.addEventListener('DOMContentLoaded', function() {
            var cpfEl = document.querySelector('input[name="cpf"]');
            if (cpfEl && cpfEl.value) mascaraCPF(cpfEl);
            var phoneEl = document.querySelector('input[name="phone"]');
            if (phoneEl && phoneEl.value) mascaraTelefone(phoneEl);
            var waEl = document.querySelector('input[name="whatsapp_reu"]');
            if (waEl && waEl.value) mascaraTelefone(waEl);
            var procEls = document.querySelectorAll('input[name="numero_processo"]');
            procEls.forEach(function(el) { if (el.value) mascaraProcesso(el); });
        });
        </script>
        <?php endif; ?>

        <?php if ($tipo === 'juntada'): ?>
        <div class="section">
            <h4>Dados da Juntada</h4>
            <div class="row">
                <div><label>Nº do processo</label><input name="numero_processo" value="<?= e($numeroProcesso) ?>" placeholder="0000000-00.0000.0.00.0000" oninput="mascaraProcesso(this)" maxlength="25"></div>
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
                <div><label>Nº do processo</label><input name="numero_processo" value="<?= e($numeroProcesso) ?>" placeholder="0000000-00.0000.0.00.0000" oninput="mascaraProcesso(this)" maxlength="25"></div>
                <div><label>Vara / Juízo</label><input name="vara_juizo" value="<?= e($varaJuizo) ?>" placeholder="Ex: 1ª Vara de Família de Barra Mansa"></div>
            </div>
            <div style="margin-bottom:.75rem;">
                <label>Do que se dá ciência? <span style="font-size:.7rem;color:#6b7280;">(padrão: "ao acrescido")</span></label>
                <input name="objeto_ciencia" value="<?= e($objetoCiencia) ?>" placeholder="Ex: ao acrescido · à r. decisão · à juntada dos documentos">
                <div style="font-size:.7rem;color:#6b7280;margin-top:3px;">💡 Se deixar em branco, usa "ao acrescido". Não precisa começar com "quanto" — o sistema adiciona automaticamente.</div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($tipo === 'prevjud'): ?>
        <div class="section">
            <h4>Dados da Pesquisa PREVJUD</h4>
            <div class="row">
                <div><label>Nº do processo</label><input name="numero_processo" value="<?= e($numeroProcesso) ?>" placeholder="0000000-00.0000.0.00.0000" oninput="mascaraProcesso(this)" maxlength="25"></div>
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
            <h4>Dados para Citação por WhatsApp</h4>
            <div class="row">
                <div><label>Nº do Processo</label><input name="numero_processo" value="<?= e($numeroProcesso) ?>" placeholder="0000000-00.0000.0.00.0000" oninput="mascaraProcesso(this)" maxlength="25" required></div>
                <div><label>Vara / Juízo</label><input name="vara_juizo" value="<?= e($varaJuizo) ?>" placeholder="Ex: 2ª Vara de Família de Resende" required></div>
            </div>
            <div class="row">
                <div><label>Nome completo do réu/ré</label><input name="nome_reu" value="<?= e($nomeReu) ?>" placeholder="Nome da parte ré" required></div>
                <div><label>Telefone/WhatsApp do réu/ré</label><input name="whatsapp_reu" value="<?= e($whatsappReu) ?>" placeholder="(00) 00000-0000" oninput="mascaraTelefone(this)" maxlength="15" required></div>
            </div>
            <div class="row">
                <div><label>Tipo de ação</label>
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
                <div><label>Justificativa (opcional)</label><input name="justificativa_citacao" value="" placeholder="Ex: Réu não encontrado para citação pessoal"></div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($tipo === 'habilitacao'): ?>
        <div class="section">
            <h4>Dados da Habilitação</h4>
            <div class="row">
                <div><label>Nº do processo</label><input name="numero_processo" value="<?= e($numeroProcesso) ?>" placeholder="0000000-00.0000.0.00.0000" oninput="mascaraProcesso(this)" maxlength="25"></div>
                <div><label>Vara / Juízo</label><input name="vara_juizo" value="<?= e($varaJuizo) ?>" placeholder="Ex: 1ª Vara de Família"></div>
            </div>
            <div class="row">
                <div><label>UF</label>
                    <select name="comarca_uf_doc" id="docUf" onchange="if(typeof ibgeCidades==='function'&&!this._init){this._init=1;ibgeCidades('docUf','docComarca','docListaCidades');}">
                        <option value="">—</option>
                        <?php foreach (array('AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO') as $_uf): ?>
                        <option value="<?= $_uf ?>" <?= ($comarcaUfDoc ?: 'RJ') === $_uf ? 'selected' : '' ?>><?= $_uf ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div><label>Comarca</label><input name="comarca_doc" id="docComarca" value="<?= e($comarcaDoc) ?>" placeholder="Ex: Resende" list="docListaCidades" autocomplete="off"><datalist id="docListaCidades"></datalist></div>
            </div>
            <div class="row">
                <div>
                    <label>Tipo de ação</label>
                    <select name="tipo_acao_hab">
                        <option value="">— Selecionar —</option>
                        <?php
                        $opHab = array(
                            'AÇÃO DE ALIMENTOS' => 'Alimentos',
                            'AÇÃO REVISIONAL DE ALIMENTOS' => 'Revisional de Alimentos',
                            'AÇÃO DE EXECUÇÃO DE ALIMENTOS' => 'Execução de Alimentos',
                            'AÇÃO DE DIVÓRCIO' => 'Divórcio',
                            'AÇÃO DE DIVÓRCIO CONSENSUAL' => 'Divórcio Consensual',
                            'AÇÃO DE DIVÓRCIO LITIGIOSO' => 'Divórcio Litigioso',
                            'AÇÃO DE GUARDA' => 'Guarda',
                            'AÇÃO DE GUARDA COMPARTILHADA' => 'Guarda Compartilhada',
                            'AÇÃO DE REGULAMENTAÇÃO DE CONVIVÊNCIA' => 'Regulamentação de Convivência',
                            'AÇÃO DE INVESTIGAÇÃO DE PATERNIDADE' => 'Investigação de Paternidade',
                            'AÇÃO DE ABANDONO AFETIVO' => 'Abandono Afetivo',
                            'AÇÃO INDENIZATÓRIA' => 'Indenização',
                            'AÇÃO DO CONSUMIDOR' => 'Consumidor',
                            'AÇÃO DE OBRIGAÇÃO DE FAZER' => 'Obrigação de Fazer',
                            'AÇÃO DE COBRANÇA' => 'Cobrança',
                            'AÇÃO DE USUCAPIÃO' => 'Usucapião',
                            'INVENTÁRIO E PARTILHA' => 'Inventário',
                            'CURATELA' => 'Curatela',
                        );
                        foreach ($opHab as $ohVal => $ohLabel): ?>
                        <option value="<?= e($ohVal) ?>" <?= strtoupper($tipoAcaoHab) === $ohVal ? 'selected' : '' ?>><?= e($ohLabel) ?></option>
                        <?php endforeach; ?>
                        <option value="outro">Outro (digitar)</option>
                    </select>
                </div>
                <div>
                    <label>Papel do cliente</label>
                    <select name="papel_cliente">
                        <option value="autor" <?= $papelCliente === 'autor' ? 'selected' : '' ?>>Autor / Requerente</option>
                        <option value="reu" <?= $papelCliente === 'reu' ? 'selected' : '' ?>>Réu / Requerido</option>
                        <option value="representante_legal" <?= $papelCliente === 'representante_legal' ? 'selected' : '' ?>>Representante Legal</option>
                    </select>
                </div>
            </div>
            <div class="row">
                <div><label>Nome da parte contrária</label><input name="nome_parte_contraria" value="<?= e($nomeParteContraria) ?>" placeholder="Nome do réu ou autor"></div>
            </div>
            <div class="row">
                <div>
                    <label>Quem pleiteia a habilitação?</label>
                    <select name="pleiteante_hab" id="pleiteante_hab" onchange="togglePleiteante(this.value)">
                        <option value="proprio" <?= ($d['pleiteante_hab'] ?? 'proprio') === 'proprio' ? 'selected' : '' ?>>O próprio cliente (em nome próprio)</option>
                        <option value="menor" <?= ($d['pleiteante_hab'] ?? '') === 'menor' ? 'selected' : '' ?>>Menor de idade (cliente é representante legal)</option>
                    </select>
                </div>
            </div>
            <div class="row" id="dadosMenorHab" style="<?= ($d['pleiteante_hab'] ?? '') === 'menor' ? '' : 'display:none;' ?>">
                <div style="grid-column:1/-1;">
                    <label>Nome completo do(s) menor(es) *</label>
                    <input name="child_names" value="<?= e($childNames) ?>" placeholder="Ex: JOÃO DA SILVA E MARIA DA SILVA (separe por vírgula se houver mais de um)">
                    <small style="color:#6b7280;font-size:.72rem;">O pedido de habilitação será feito no nome do menor, representado pelo cliente</small>
                </div>
            </div>
            <div class="row">
                <div>
                    <label>Tipo de habilitação</label>
                    <select name="tipo_hab_proc">
                        <option value="plena" <?= ($d['tipo_hab_proc'] ?? 'plena') === 'plena' ? 'selected' : '' ?>>Procuração com poderes para atuação plena</option>
                        <option value="analise" <?= ($d['tipo_hab_proc'] ?? '') === 'analise' ? 'selected' : '' ?>>Apenas para análise dos autos (sem poderes para atuar)</option>
                    </select>
                    <small style="color:#6b7280;font-size:.72rem;">Se "apenas para análise", constará que a atuação efetiva depende de nova juntada de procuração</small>
                </div>
            </div>
            <input type="hidden" name="rep_legal" id="rep_legal_hidden" value="<?= e($repLegal) ?>">
            <script>
            function togglePleiteante(val) {
                document.getElementById('dadosMenorHab').style.display = val === 'menor' ? '' : 'none';
                document.getElementById('rep_legal_hidden').value = val === 'menor' ? 'sim' : 'nao';
            }
            </script>
        </div>
        <?php endif; ?>

        <?php if ($tipo === 'averbacao_sentenca'): ?>
        <div class="section">
            <h4>💔 Averbação Sentença — opções</h4>
            <div style="margin-bottom:.75rem;">
                <label>Cliente tem gratuidade de justiça deferida nos autos?</label>
                <select name="gratuidade_avb">
                    <option value="sim" selected>Sim — incluir parágrafo sobre Malote Digital obrigatório</option>
                    <option value="nao">Não — omitir o parágrafo da gratuidade</option>
                </select>
                <small style="color:#6b7280;">Quando há gratuidade, cartórios geralmente aceitam Malote Digital. Sem gratuidade, talvez ela tenha que pagar a averbação direto.</small>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($tipo === 'audiencia_remota'): ?>
        <div class="section">
            <h4>🖥️ Dados da Audiência Remota/Híbrida</h4>
            <div class="row">
                <div><label>Nº do processo</label><input name="numero_processo" value="<?= e($numeroProcesso) ?>" placeholder="0000000-00.0000.0.00.0000" oninput="mascaraProcesso(this)" maxlength="25"></div>
                <div><label>Vara / Juízo</label><input name="vara_juizo" value="<?= e($varaJuizo) ?>" placeholder="Ex: 4º Juizado Especial Cível"></div>
            </div>
            <div class="row">
                <div><label>UF</label>
                    <select name="comarca_uf_doc" id="docUf" onchange="if(typeof ibgeCidades==='function'&&!this._init){this._init=1;ibgeCidades('docUf','docComarca','docListaCidades');}">
                        <option value="">—</option>
                        <?php foreach (array('AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO') as $_uf): ?>
                        <option value="<?= $_uf ?>" <?= ($comarcaUfDoc ?: 'RJ') === $_uf ? 'selected' : '' ?>><?= $_uf ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div><label>Comarca</label><input name="comarca_doc" id="docComarca" value="<?= e($comarcaDoc) ?>" placeholder="Ex: Nova Iguaçu" list="docListaCidades" autocomplete="off"><datalist id="docListaCidades"></datalist></div>
            </div>
            <div class="row">
                <div>
                    <label>Quem é a parte no processo?</label>
                    <select name="pleiteante_aud" id="pleiteante_aud" onchange="togglePleiteanteAud(this.value)">
                        <option value="proprio" <?= $pleiteanteAud === 'proprio' ? 'selected' : '' ?>>O próprio cliente (em nome próprio)</option>
                        <option value="menor" <?= $pleiteanteAud === 'menor' ? 'selected' : '' ?>>Menor de idade (cliente é representante legal)</option>
                    </select>
                </div>
                <div>
                    <label>Papel no processo</label>
                    <select name="papel_cliente_aud">
                        <option value="autor" <?= $papelClienteAud === 'autor' ? 'selected' : '' ?>>Autor(a) / Requerente</option>
                        <option value="reu" <?= $papelClienteAud === 'reu' ? 'selected' : '' ?>>Réu / Requerido(a)</option>
                    </select>
                </div>
            </div>
            <div class="row" id="dadosMenorAud" style="<?= $pleiteanteAud === 'menor' ? '' : 'display:none;' ?>">
                <div style="grid-column:1/-1;">
                    <label>Nome completo do(s) menor(es) *</label>
                    <input name="child_names_aud" value="<?= e($childNamesAud) ?>" placeholder="Ex: ARTHUR REIS CABRAL (separe por vírgula se houver mais de um)">
                    <small style="color:#6b7280;font-size:.72rem;">A petição será feita em nome do menor, representado pelo cliente. O menor será cadastrado automaticamente como parte do processo.</small>
                </div>
            </div>
            <input type="hidden" name="rep_legal_aud" id="rep_legal_aud_hidden" value="<?= e($repLegalAud) ?>">
            <script>
            function togglePleiteanteAud(val) {
                document.getElementById('dadosMenorAud').style.display = val === 'menor' ? '' : 'none';
                document.getElementById('rep_legal_aud_hidden').value = val === 'menor' ? 'sim' : 'nao';
            }
            </script>
            <div class="row">
                <div>
                    <label>Modalidade requerida</label>
                    <select name="modalidade_audiencia">
                        <option value="remota_ou_hibrida" <?= $modalidadeAudiencia === 'remota_ou_hibrida' ? 'selected' : '' ?>>Remota ou, alternativamente, híbrida</option>
                        <option value="remota" <?= $modalidadeAudiencia === 'remota' ? 'selected' : '' ?>>Somente remota (videoconferência)</option>
                        <option value="hibrida" <?= $modalidadeAudiencia === 'hibrida' ? 'selected' : '' ?>>Somente híbrida</option>
                    </select>
                </div>
                <div>
                    <label>Data da audiência designada</label>
                    <input type="date" name="data_audiencia" value="<?= e($dataAudiencia) ?>">
                </div>
            </div>
            <div class="row">
                <div>
                    <label>Cidade da assinatura</label>
                    <select name="cidade_assinatura_aud">
                        <option value="" <?= $cidadeAssinaturaAud === '' ? 'selected' : '' ?>>— Manter padrão (cidade do cliente) —</option>
                        <option value="Volta Redonda" <?= $cidadeAssinaturaAud === 'Volta Redonda' ? 'selected' : '' ?>>Volta Redonda</option>
                        <option value="Resende" <?= $cidadeAssinaturaAud === 'Resende' ? 'selected' : '' ?>>Resende</option>
                    </select>
                    <small style="color:#6b7280;font-size:.72rem;">Sobrescreve a cidade no fechamento da petição (Bairro Unamar/etc some)</small>
                </div>
            </div>
            <div style="margin-bottom:.75rem;">
                <label>Motivo / Justificativa (opcional — se vazio, gera texto padrão sobre justa causa)</label>
                <div style="display:flex;gap:.5rem;align-items:center;margin-bottom:.4rem;flex-wrap:wrap;">
                    <select id="motivoAudPreset" onchange="aplicarMotivoAudiencia(this.value)" style="flex:1;min-width:240px;padding:.45rem .6rem;border:1.5px solid #d1d5db;border-radius:8px;font-size:.85rem;background:#fff;">
                        <option value="">📋 Escolher um modelo de justificativa pronta…</option>
                        <option value="patrono_docente_interior_rj">🎓 Patrona docente no interior do RJ (Volta Redonda / Resende) — aula na data</option>
                        <option value="patrono_outra_comarca">⚖️ Patrono(a) em audiência/atividade em outra comarca</option>
                        <option value="patrono_audiencia_concomitante">⏰ Patrono(a) com audiência concomitante na mesma data</option>
                        <option value="patrono_saude">🩺 Patrono(a) com questão de saúde / licença médica</option>
                        <option value="cliente_distancia">📍 Cliente reside em outra cidade/estado (distância inviável)</option>
                        <option value="cliente_mobilidade">♿ Cliente com restrição de mobilidade / idade avançada</option>
                        <option value="cliente_filho_menor">👶 Cliente responsável por filho(s) menor(es) sem rede de apoio</option>
                        <option value="cliente_trabalho">💼 Cliente com vínculo empregatício que impede ausência</option>
                        <option value="cliente_vulnerabilidade">💰 Cliente em vulnerabilidade econômica (beneficiário da gratuidade)</option>
                        <option value="comarca_distante">🚗 Comarca distante / sem acesso a transporte</option>
                        <option value="celeridade">⚡ Princípio da celeridade e economia processual</option>
                    </select>
                    <button type="button" onclick="document.getElementById('motivoAudPreset').value='';document.querySelector('textarea[name=motivo_audiencia]').value='';" style="padding:.45rem .8rem;border:1px solid #d1d5db;background:#fff;border-radius:8px;font-size:.78rem;cursor:pointer;color:#6b7280;">✕ limpar</button>
                </div>
                <textarea name="motivo_audiencia" rows="4" placeholder="Escolha um modelo acima ou escreva livremente. Ex: A patrona da Autora exerce atividade docente presencial na cidade de Volta Redonda – RJ na data designada para a audiência..."><?= e($motivoAudiencia) ?></textarea>
                <small style="color:#6b7280;font-size:.72rem;">Os modelos preenchem o campo abaixo — você pode editar livremente antes de gerar.</small>
            </div>
            <script>
            (function() {
                var motivos = {
                    patrono_docente_interior_rj: 'A patrona da Autora exerce atividade docente presencial no interior do Estado do Rio de Janeiro, na cidade de [INFORMAR — Volta Redonda OU Resende], onde leciona em instituição de ensino, com aulas previamente designadas e obrigatórias na data da audiência ora marcada. A distância entre o município em que ministra as aulas e a Comarca onde tramita o feito, somada ao horário de início das atividades letivas, torna materialmente inviável seu deslocamento presencial sem prejuízo aos seus alunos e ao regular cumprimento do calendário acadêmico, razão pela qual se requer a designação da audiência em modalidade remota ou, alternativamente, híbrida, com fundamento no art. 236, §3º, do CPC e na Resolução nº 354/2020 do CNJ.',
                    patrono_outra_comarca: 'A patrona da Autora exerce atividade profissional na comarca de [INFORMAR CIDADE] – [UF] na data designada para a audiência, tendo compromisso anteriormente assumido que torna materialmente inviável seu deslocamento até esta Comarca, em prejuízo do regular exercício da advocacia e do direito da parte ao patrocínio efetivo.',
                    patrono_audiencia_concomitante: 'Na data e horário designados para a audiência, a patrona da Autora possui outro ato processual previamente agendado em audiência concomitante perante o juízo da [INFORMAR VARA / COMARCA], o que impossibilita seu comparecimento simultâneo a este ato, em prejuízo da defesa técnica de ambas as partes representadas.',
                    patrono_saude: 'A patrona encontra-se em tratamento de saúde, conforme atestado médico em anexo, situação que recomenda a participação por videoconferência, preservando-se a integridade física e o regular exercício da advocacia.',
                    cliente_distancia: 'A parte autora reside em [INFORMAR CIDADE] – [UF], distante aproximadamente [INFORMAR KM] km da Comarca onde tramita o feito, sendo o deslocamento financeira e materialmente inviável, especialmente considerando-se a vulnerabilidade econômica da parte.',
                    cliente_mobilidade: 'A parte autora possui restrição de mobilidade decorrente de [INFORMAR MOTIVO — idade avançada / condição de saúde / etc.], conforme documentação anexa, o que torna o deslocamento até a Comarca excessivamente oneroso e prejudicial à sua saúde.',
                    cliente_filho_menor: 'A parte autora é responsável legal por filho(s) menor(es) de idade e não dispõe de rede de apoio para o cuidado da criança no horário designado para a audiência, sendo a participação remota a única forma de viabilizar o ato sem prejuízo à proteção integral do(s) menor(es).',
                    cliente_trabalho: 'A parte autora possui vínculo empregatício que não permite ausência integral no dia/horário designado para a audiência presencial, sendo a participação por videoconferência indispensável à preservação de seu sustento e ao exercício pleno do direito de defesa.',
                    cliente_vulnerabilidade: 'A parte autora é beneficiária da gratuidade de justiça e encontra-se em situação de vulnerabilidade econômica, não dispondo de recursos financeiros para arcar com despesas de deslocamento, alimentação e demais custos inerentes ao comparecimento presencial.',
                    comarca_distante: 'A Comarca onde tramita o feito apresenta acesso restrito por transporte público no dia da audiência designada, e o deslocamento por meio próprio é inviável diante das circunstâncias da parte/patrona, conforme exposto.',
                    celeridade: 'A realização da audiência por videoconferência atende aos princípios da celeridade e economia processual previstos no art. 4º do CPC, sem qualquer prejuízo ao contraditório e à ampla defesa, sendo medida adequada às atuais práticas forenses consolidadas pelo CNJ (Resolução nº 354/2020).'
                };
                window.aplicarMotivoAudiencia = function(key) {
                    if (!key) return;
                    var ta = document.querySelector('textarea[name="motivo_audiencia"]');
                    if (!ta) return;
                    var atual = ta.value.trim();
                    if (atual && !confirm('Já há texto preenchido. Substituir pelo modelo selecionado?')) {
                        document.getElementById('motivoAudPreset').value = '';
                        return;
                    }
                    ta.value = motivos[key] || '';
                    ta.focus();
                };
            })();
            </script>
            <div style="margin-bottom:.75rem;">
                <label>E-mails para envio do link (além dos e-mails do escritório que já são incluídos)</label>
                <input name="emails_audiencia" value="<?= e($emailsAudiencia) ?>" placeholder="Ex: email-pessoal@cliente.com">
            </div>
        </div>
        <?php endif; ?>

        <?php if ($tipo === 'mandado_pagamento'): ?>
        <div class="section">
            <h4>💰 Dados do Mandado de Pagamento</h4>
            <div class="row">
                <div><label>Nº do processo</label><input name="numero_processo" value="<?= e($numeroProcesso) ?>" placeholder="0000000-00.0000.0.00.0000" oninput="mascaraProcesso(this)" maxlength="25"></div>
                <div><label>Vara / Juízo</label><input name="vara_juizo" value="<?= e($varaJuizo) ?>" placeholder="Ex: 1ª Vara de Família"></div>
            </div>
            <div class="row">
                <div><label>UF</label>
                    <select name="comarca_uf_doc" id="docUf">
                        <option value="">—</option>
                        <?php foreach (array('AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO') as $_uf): ?>
                        <option value="<?= $_uf ?>" <?= ($comarcaUfDoc ?: 'RJ') === $_uf ? 'selected' : '' ?>><?= $_uf ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div><label>Comarca</label><input name="comarca_doc" id="docComarca" value="<?= e($comarcaDoc) ?>" placeholder="Ex: Volta Redonda"></div>
            </div>
            <div class="row">
                <div>
                    <label>Beneficiário do pagamento</label>
                    <select name="beneficiario_mandado">
                        <option value="escritorio" <?= $beneficiarioMandado === 'escritorio' ? 'selected' : '' ?>>Escritório (Ferreira & Sá Advocacia)</option>
                        <option value="cliente" <?= $beneficiarioMandado === 'cliente' ? 'selected' : '' ?>>Cliente (conta pessoal)</option>
                    </select>
                </div>
                <div>
                    <label>Página da procuração nos autos</label>
                    <input type="text" name="pagina_procuracao" value="<?= e($paginaProcuracao) ?>" placeholder="Ex: 50">
                </div>
            </div>
            <div class="row">
                <div>
                    <label>Dar quitação após levantamento?</label>
                    <select name="dar_quitacao">
                        <option value="sim" <?= $darQuitacao === 'sim' ? 'selected' : '' ?>>Sim — dar quitação e requerer arquivamento</option>
                        <option value="nao" <?= $darQuitacao === 'nao' ? 'selected' : '' ?>>Não — sem quitação (processo continua)</option>
                    </select>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($isIntercorrente && $tipo !== 'habilitacao' && $tipo !== 'audiencia_remota' && $tipo !== 'mandado_pagamento'): ?>
        <div class="section">
            <h4>Comarca</h4>
            <div class="row">
                <div><label>UF</label>
                    <select name="comarca_uf_doc" id="docUf" onchange="if(typeof ibgeCidades==='function'&&!this._init){this._init=1;ibgeCidades('docUf','docComarca','docListaCidades');}">
                        <option value="">—</option>
                        <?php foreach (array('AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO') as $_uf): ?>
                        <option value="<?= $_uf ?>" <?= ($comarcaUfDoc ?: 'RJ') === $_uf ? 'selected' : '' ?>><?= $_uf ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div><label>Comarca</label><input name="comarca_doc" id="docComarca" value="<?= e($comarcaDoc) ?>" placeholder="Ex: Resende" list="docListaCidades" autocomplete="off"><datalist id="docListaCidades"></datalist></div>
            </div>
        </div>

        <div class="section">
            <h4>👤 Legitimidade — Quem é a parte?</h4>
            <div class="row">
                <div>
                    <label>A petição é em nome de quem?</label>
                    <select name="pleiteante_inter" id="pleiteante_inter" onchange="togglePleiteanteInter(this.value)">
                        <option value="proprio" <?= ($pleiteanteHab === 'proprio' || $pleiteanteHab === '') ? 'selected' : '' ?>>Do próprio cliente (em nome próprio)</option>
                        <option value="menor" <?= $pleiteanteHab === 'menor' ? 'selected' : '' ?>>De menor de idade (cliente é representante legal)</option>
                    </select>
                </div>
                <div>
                    <label>Papel no processo</label>
                    <select name="papel_cliente">
                        <option value="autor" <?= $papelCliente === 'autor' ? 'selected' : '' ?>>Autor(a) / Requerente</option>
                        <option value="reu" <?= $papelCliente === 'reu' ? 'selected' : '' ?>>Réu / Requerido(a)</option>
                        <option value="representante_legal" <?= $papelCliente === 'representante_legal' ? 'selected' : '' ?>>Representante Legal</option>
                    </select>
                </div>
            </div>
            <div class="row" id="dadosMenorInter" style="<?= $pleiteanteHab === 'menor' ? '' : 'display:none;' ?>">
                <div style="grid-column:1/-1;">
                    <label>Nome completo do(s) menor(es) *</label>
                    <input name="child_names" value="<?= e($childNames) ?>" placeholder="Ex: SOFIA COUTO PATRÍCIO, JONAS COUTO PATRÍCIO (separe por vírgula se houver mais de um)">
                    <small style="color:#6b7280;font-size:.72rem;">A petição será feita em nome do menor, representado pelo cliente</small>
                </div>
            </div>
            <input type="hidden" name="rep_legal" id="rep_legal_inter_hidden" value="<?= e($repLegal) ?>">
            <input type="hidden" name="pleiteante_hab" id="pleiteante_hab_hidden" value="<?= e($pleiteanteHab) ?>">
            <script>
            function togglePleiteanteInter(val) {
                document.getElementById('dadosMenorInter').style.display = val === 'menor' ? '' : 'none';
                document.getElementById('rep_legal_inter_hidden').value = val === 'menor' ? 'sim' : 'nao';
                document.getElementById('pleiteante_hab_hidden').value = val;
            }
            </script>
        </div>
        <?php endif; ?>

        <?php if ($tipo === 'renuncia_poderes'): ?>
        <div class="section">
            <h4>🚪 Dados da Renúncia aos Poderes</h4>
            <div class="row">
                <div><label>Nº do processo</label><input name="numero_processo" value="<?= e($numeroProcesso) ?>" placeholder="0000000-00.0000.0.00.0000" oninput="mascaraProcesso(this)" maxlength="25"></div>
                <div><label>Vara / Juízo</label><input name="vara_juizo" value="<?= e($varaJuizo) ?>" placeholder="Ex: 1ª Vara de Família de Volta Redonda"></div>
            </div>
            <div class="row">
                <div><label>Tipo de ação</label><input name="tipo_acao_renuncia" value="<?= e($caseData ? ($caseData['case_type'] ?: '') : '') ?>" placeholder="Ex: Ação de Divórcio"></div>
                <div><label>Parte contrária / Ré (opcional)</label><input name="reu_renuncia" value="<?= e($reuRenuncia ?: ($caseData ? ($caseData['parte_re_nome'] ?: '') : '')) ?>" placeholder="Nome da parte contrária"></div>
            </div>
            <div class="row">
                <div style="grid-column:1/-1;">
                    <label>Motivo da renúncia</label>
                    <select name="motivo_renuncia_select" onchange="document.getElementsByName('motivo_renuncia')[0].value=this.value;">
                        <option value="razões particulares" <?= $motivoRenuncia === 'razões particulares' ? 'selected' : '' ?>>Razões particulares</option>
                        <option value="quebra da relação de confiança entre advogada e constituinte" <?= $motivoRenuncia === 'quebra da relação de confiança entre advogada e constituinte' ? 'selected' : '' ?>>Quebra da relação de confiança</option>
                        <option value="inadimplemento dos honorários advocatícios contratados" <?= $motivoRenuncia === 'inadimplemento dos honorários advocatícios contratados' ? 'selected' : '' ?>>Inadimplemento dos honorários</option>
                        <option value="ausência de comunicação e cooperação por parte do constituinte" <?= $motivoRenuncia === 'ausência de comunicação e cooperação por parte do constituinte' ? 'selected' : '' ?>>Ausência de comunicação/cooperação</option>
                        <option value="divergência quanto à condução da causa" <?= $motivoRenuncia === 'divergência quanto à condução da causa' ? 'selected' : '' ?>>Divergência quanto à condução da causa</option>
                        <option value="outros motivos justificáveis" <?= $motivoRenuncia === 'outros motivos justificáveis' ? 'selected' : '' ?>>Outros (editar abaixo)</option>
                    </select>
                </div>
            </div>
            <div class="row">
                <div style="grid-column:1/-1;">
                    <label>Texto do motivo (editável)</label>
                    <input name="motivo_renuncia" value="<?= e($motivoRenuncia) ?>" placeholder="Texto livre que aparecerá no documento">
                </div>
            </div>
            <div style="background:#fef3c7;border-left:4px solid #f59e0b;border-radius:0 8px 8px 0;padding:.7rem .9rem;margin-top:.6rem;font-size:.78rem;color:#78350f;">
                ⚖️ <strong>Base legal:</strong> Art. 112 do CPC + Art. 5º, §3º do Estatuto da OAB. A advogada permanecerá representando a parte por <strong>10 (dez) dias úteis</strong> após a notificação, salvo se nesse prazo já houver advogado substituto.
            </div>
        </div>
        <?php endif; ?>

        <?php if ($tipo === 'desistencia_acao'): ?>
        <div class="section">
            <h4>🛑 Dados da Desistência da Ação</h4>
            <div class="row">
                <div><label>Nº do processo</label><input name="numero_processo" value="<?= e($numeroProcesso) ?>" placeholder="0000000-00.0000.0.00.0000" oninput="mascaraProcesso(this)" maxlength="25"></div>
                <div><label>Vara / Juízo</label><input name="vara_juizo" value="<?= e($varaJuizo) ?>" placeholder="Ex: 1ª Vara de Família de Volta Redonda"></div>
            </div>
            <div class="row">
                <div><label>Tipo de ação</label><input name="tipo_acao_desistencia" value="<?= e($caseData ? ($caseData['case_type'] ?: '') : '') ?>" placeholder="Ex: Ação de Divórcio"></div>
                <div><label>Parte Ré (nome)</label><input name="reu_desistencia" value="<?= e($reuDesistencia ?: ($caseData ? ($caseData['parte_re_nome'] ?: '') : '')) ?>" placeholder="Nome da parte ré"></div>
            </div>
            <div class="row">
                <div style="grid-column:1/-1;">
                    <label>Cenário processual</label>
                    <div style="display:flex;gap:.6rem;flex-wrap:wrap;margin-top:.4rem;">
                        <label style="flex:1;min-width:280px;display:flex;align-items:flex-start;gap:.4rem;cursor:pointer;padding:.7rem .9rem;border:2px solid <?= $cenarioDesistencia === 'sem_contestacao' ? '#059669' : '#e5e7eb' ?>;background:<?= $cenarioDesistencia === 'sem_contestacao' ? 'linear-gradient(135deg,#ecfdf5,#d1fae5)' : '#fff' ?>;border-radius:10px;font-size:.82rem;">
                            <input type="radio" name="cenario_desistencia" value="sem_contestacao" <?= $cenarioDesistencia === 'sem_contestacao' ? 'checked' : '' ?> style="margin-top:3px;accent-color:#059669;">
                            <div>
                                <strong style="color:#065f46;">✓ Sem contestação ainda</strong><br>
                                <span style="font-size:.74rem;color:#374151;">Dispensa prévia anuência da Ré (interpretação <em>a contrario sensu</em> do art. 485, §4º, CPC)</span>
                            </div>
                        </label>
                        <label style="flex:1;min-width:280px;display:flex;align-items:flex-start;gap:.4rem;cursor:pointer;padding:.7rem .9rem;border:2px solid <?= $cenarioDesistencia === 'com_contestacao' ? '#dc2626' : '#e5e7eb' ?>;background:<?= $cenarioDesistencia === 'com_contestacao' ? 'linear-gradient(135deg,#fef2f2,#fee2e2)' : '#fff' ?>;border-radius:10px;font-size:.82rem;">
                            <input type="radio" name="cenario_desistencia" value="com_contestacao" <?= $cenarioDesistencia === 'com_contestacao' ? 'checked' : '' ?> style="margin-top:3px;accent-color:#dc2626;">
                            <div>
                                <strong style="color:#991b1b;">⚠ Já houve contestação</strong><br>
                                <span style="font-size:.74rem;color:#374151;">Pede intimação da Ré para se manifestar sobre o pedido (art. 485, §4º, CPC)</span>
                            </div>
                        </label>
                    </div>
                </div>
            </div>
            <div style="background:#fef3c7;border-left:4px solid #f59e0b;border-radius:0 8px 8px 0;padding:.7rem .9rem;margin-top:.6rem;font-size:.78rem;color:#78350f;">
                ⚖️ <strong>Base legal:</strong> Art. 485, VIII (homologação da desistência) e §4º (necessidade de consentimento da Ré após contestação) do CPC. Motivo padrão: <strong>foro íntimo</strong> (não exige justificativa adicional).
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
        <button onclick="imprimirLimpo()">🖨️ Imprimir / PDF</button>
        <button onclick="baixarWord()" style="background:#2563eb;">📥 Word (.docx)</button>
        <button onclick="copiarConteudo()" style="background:#059669;">📋 Copiar conteúdo</button>
        <?php if ($phone): ?>
            <button type="button" onclick="waSenderOpen({telefone:'<?= preg_replace('/\D/', '', $phone) ?>',nome:<?= e(json_encode($client['name'] ?? '')) ?>,clientId:<?= (int)$clientId ?>})" class="btn-zap">💬 WhatsApp</button>
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
        // Salário-Maternidade — personalização opcional
        'sm_modo' => $smModo,
        'sm_tipo_honorario' => $smTipoHonorario,
        'sm_percentual' => $smPercentual,
        'sm_num_parcelas_beneficio' => $smNumParcelasBeneficio,
        'sm_valor_total' => $smValorTotal,
        'sm_num_parcelas' => $smNumParcelas,
        'sm_valor_parcela' => $smValorParcela,
        'sm_forma_pagamento' => $smFormaPagamento,
        'sm_observacao' => $smObservacao,
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
        'comarca' => $comarcaDoc,
        'comarca_uf' => $comarcaUfDoc,
        'tipo_acao_hab' => $tipoAcaoHab,
        'rep_legal' => $repLegal,
        'nome_parte_contraria' => $nomeParteContraria,
        'papel_cliente' => $papelCliente,
        'pleiteante_hab' => $pleiteanteHab,
        'qualif_menor' => $qualifMenor,
        'tipo_hab_proc' => $tipoHabProc,
        'nacionalidade' => $client['nacionalidade'] ?? '',
        // Substabelecimento
        'sem_reserva' => $semReserva,
        'substabelecente' => $substabelecente,
        'subst_adv_nome' => $substAdvNome,
        'subst_adv_oab' => $substAdvOab,
        'subst_adv_seccional' => $substAdvSeccional,
        'subst_adv_email' => $substAdvEmail,
        'subst_adv_endereco' => $substAdvEndereco,
        'subst_adv_nacionalidade' => $substAdvNacionalidade,
        'subst_adv_telefone' => $substAdvTelefone,
        // Audiência remota/híbrida
        'motivo_audiencia' => $motivoAudiencia,
        'modalidade_audiencia' => $modalidadeAudiencia,
        'emails_audiencia' => $emailsAudiencia,
        'papel_cliente_aud' => $papelClienteAud,
        'pleiteante_aud' => $pleiteanteAud,
        'child_names_aud' => $childNamesAud,
        'qualif_menor_aud' => $qualifMenorAud,
        'cidade_assinatura_aud' => $cidadeAssinaturaAud,
        'rep_legal_aud' => $repLegalAud,
        'data_audiencia' => $dataAudiencia,
        'beneficiario_mandado' => $beneficiarioMandado,
        'dar_quitacao' => $darQuitacao,
        'pagina_procuracao' => $paginaProcuracao,
        'qtd_menores' => $qtdMenores,
        'gratuidade_avb' => $gratuidadeAvb,
        'motivo_renuncia' => $motivoRenuncia,
        'reu_renuncia' => $reuRenuncia,
        'cenario_desistencia' => $cenarioDesistencia,
        'reu_desistencia' => $reuDesistencia,
    );

    if ($tipo === 'procuracao') echo template_procuracao($d);
    elseif ($tipo === 'contrato') {
        // Sub-tipo: padrão ou Salário-Maternidade (Previdenciário).
        // Tipo de acao = salario_maternidade força SM automaticamente.
        $subtipoContrato = $_POST['subtipo_contrato'] ?? 'padrao';
        if ($tipoAcao === 'salario_maternidade') $subtipoContrato = 'salario_maternidade';
        if ($subtipoContrato === 'salario_maternidade') {
            echo template_contrato_prevjud_sm($d);
        } else {
            echo template_contrato($d);
        }
    }
    elseif ($tipo === 'substabelecimento') echo template_substabelecimento($d);
    elseif ($tipo === 'hipossuficiencia') echo template_hipossuficiencia($d);
    elseif ($tipo === 'isencao_ir') echo template_isencao_ir($d);
    elseif ($tipo === 'residencia') echo template_residencia($d);
    elseif ($tipo === 'acordo') echo template_acordo($d);
    elseif ($tipo === 'juntada') echo template_juntada($d);
    elseif ($tipo === 'ciencia') echo template_ciencia($d);
    elseif ($tipo === 'prevjud') echo template_prevjud($d);
    elseif ($tipo === 'citacao_whatsapp') echo template_citacao_whatsapp($d);
    elseif ($tipo === 'habilitacao') echo template_habilitacao($d);
    elseif ($tipo === 'audiencia_remota') {
        // Salva o(s) menor(es) como parte do processo automaticamente.
        // Roda uma única vez por (case_id + nome). Idempotente.
        if ($caseIdDoc > 0 && $pleiteanteAud === 'menor' && trim($childNamesAud) !== '') {
            try {
                $papelMenor = $papelClienteAud === 'reu' ? 'reu' : 'autor';
                $nomesMenor = array_filter(array_map('trim', explode(',', $childNamesAud)));
                foreach ($nomesMenor as $nomeMenor) {
                    if ($nomeMenor === '') continue;
                    // Evita duplicar (case-insensitive)
                    $stChk = $pdo->prepare("SELECT id FROM case_partes WHERE case_id = ? AND LOWER(nome) = LOWER(?) LIMIT 1");
                    $stChk->execute(array($caseIdDoc, $nomeMenor));
                    if ($stChk->fetchColumn()) continue; // ja existe
                    $pdo->prepare("INSERT INTO case_partes (case_id, papel, tipo_pessoa, nome, observacoes, created_at) VALUES (?, ?, 'fisica', ?, 'Cadastrado automaticamente via Audiência Remota/Híbrida (menor representado)', NOW())")
                        ->execute(array($caseIdDoc, $papelMenor, $nomeMenor));
                }
                if (function_exists('audit_log')) {
                    @audit_log('case_partes_auto', 'case', $caseIdDoc, 'Menor(es) cadastrado(s) via doc audiencia_remota: ' . $childNamesAud);
                }
            } catch (Exception $e) {
                @error_log('[gerar.php audiencia_remota partes] ' . $e->getMessage());
            }
        }
        echo template_audiencia_remota($d);
    }
    elseif ($tipo === 'mandado_pagamento') echo template_mandado_pagamento($d);
    elseif ($tipo === 'averbacao_sentenca') echo template_averbacao_sentenca($d);
    elseif ($tipo === 'renuncia_poderes') echo template_renuncia_poderes($d);
    elseif ($tipo === 'desistencia_acao') echo template_desistencia_acao($d);
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

<?php
// Logo base64 para exportação Word
$logoPathDoc = APP_ROOT . '/assets/img/logo.png';
$logoB64Doc = '';
$logoB64RawDoc = '';
if (file_exists($logoPathDoc)) {
    $logoB64RawDoc = base64_encode(file_get_contents($logoPathDoc));
    $logoB64Doc = 'data:image/png;base64,' . $logoB64RawDoc;
}
$docFileName = str_replace(' ', '_', strtolower($typeLabels[$tipo] ?? $tipo)) . '_' . preg_replace('/\s+/', '_', strtolower($nome));
?>

var _logoB64Raw = '<?= $logoB64RawDoc ?>';
var _logoB64Src = '<?= $logoB64Doc ?>';

var _timbradoTopo = '<div style="text-align:center;margin-bottom:16px;">'
    + '<?php if ($logoB64Doc): ?><img src="<?= $logoB64Doc ?>" style="max-width:380px;height:auto;" alt="Ferreira &amp; Sa"><?php else: ?><h2 style="color:#052228;font-family:Calibri,sans-serif;">FERREIRA &amp; SA</h2><p style="font-size:10px;color:#6b7280;">ADVOCACIA ESPECIALIZADA</p><?php endif; ?>'
    + '</div>'
    + '<div style="border-bottom:3px solid #B87333;margin-bottom:24px;"></div>';

var _timbradoRodape = '<div style="border-top:2px solid #B87333;margin-top:48px;padding-top:10px;text-align:center;font-family:Calibri,sans-serif;font-size:9pt;color:#555;">'
    + '<div style="margin-bottom:3px;"><strong>Rio de Janeiro / RJ &nbsp;&nbsp;&nbsp; Barra Mansa / RJ &nbsp;&nbsp;&nbsp; Volta Redonda / RJ &nbsp;&nbsp;&nbsp; Resende / RJ &nbsp;&nbsp;&nbsp; S\u00e3o Paulo / SP</strong></div>'
    + '<div>(24) 9.9205-0096 / (11) 2110-5438</div>'
    + '<div>www.ferreiraesa.com.br &nbsp;&nbsp;&nbsp; contato@ferreiraesa.com.br</div>'
    + '</div>';

function imprimirLimpo() {
    var conteudo = document.querySelector('.doc-body').innerHTML;
    var win = window.open('', '_blank');
    win.document.write('<!DOCTYPE html><html><head><meta charset="utf-8"><title>Documento</title>'
        + '<style>'
        + '@page{size:A4;margin:1.5cm 2cm 1.5cm 2cm;}'
        + 'body{font-family:Calibri,sans-serif;font-size:12pt;line-height:1.8;color:#1A1A1A;text-align:justify;-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
        + 'p{text-indent:4em;margin-bottom:8pt;}'
        + '.doc-title{text-align:center;font-weight:700;font-size:14pt;margin:20px 0;text-indent:0;}'
        + '.local-data{display:block;text-align:right;margin:24pt 0 16pt;text-indent:0;}'
        // Assinatura forçada em block + .linha com height real (evita colapso em PDF)
        + '.assinatura{display:block;text-align:center;margin-top:40pt;page-break-inside:avoid;}'
        + '.assinatura > div{display:block;}'
        + '.assinatura .linha{display:block;border-bottom:1px solid #333;width:60%;height:1px;margin:0 auto 4pt;line-height:1px;font-size:1px;}'
        + '.nome-ass{display:block;font-weight:700;font-size:11pt;margin-top:2pt;}'
        + 'table{border-collapse:collapse;width:100%;}'
        + 'td,th{border:none;padding:4pt 8pt;}'
        + '</style></head>'
        + '<body>' + _timbradoTopo + conteudo + _timbradoRodape + '</body></html>');
    win.document.close();
    setTimeout(function() { win.print(); }, 500);
}

// Gera DOCX real via endpoint backend (modules/documentos/baixar_docx.php).
// Antes: MHTML disfar\u00e7ado de .doc \u2014 Word abria com fonte/margem/indent quebrados.
// Agora: OOXML leg\u00edtimo, mant\u00e9m Calibri 12pt, justify, line 1.5, indent, timbrado.
function baixarWord() {
    var conteudo = document.querySelector('.doc-body').innerHTML;
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = '<?= module_url("documentos", "baixar_docx.php") ?>';
    form.target = '_blank';

    var fHtml = document.createElement('input');
    fHtml.type = 'hidden'; fHtml.name = 'html'; fHtml.value = conteudo;
    form.appendChild(fHtml);

    var fTit = document.createElement('input');
    fTit.type = 'hidden'; fTit.name = 'titulo'; fTit.value = '<?= addslashes($docFileName) ?>';
    form.appendChild(fTit);

    document.body.appendChild(form);
    form.submit();
    setTimeout(function() { form.remove(); }, 1000);
}
</script>
<script src="<?= url('assets/js/ibge_cidades.js') ?>"></script>
<script>ibgeCidades('docUf', 'docComarca', 'docListaCidades');</script>

</body>
</html>
