<?php
/**
 * Ferreira & Sá Hub — Gerar Documento (modelo real do escritório)
 */

require_once __DIR__ . '/../../core/middleware.php';
require_min_role('gestao');

$pdo = db();
$tipo = $_GET['tipo'] ?? '';
$clientId = (int)($_GET['client_id'] ?? 0);
$tipoAcao = $_GET['tipo_acao'] ?? '';
$outorgante = $_GET['outorgante'] ?? 'proprio';

$validTypes = array('procuracao', 'contrato', 'hipossuficiencia', 'isencao_ir');
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
    'hipossuficiencia' => 'Declaração de Hipossuficiência',
    'isencao_ir' => 'Declaração de Isenção de Imposto de Renda',
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

$pageTitle = $typeLabels[$tipo] ?? 'Documento';
$acaoTexto = isset($acaoLabels[$tipoAcao]) ? $acaoLabels[$tipoAcao] : '';

// Dados do cliente
$nome = $client['name'] ?: '';
$cpf = $client['cpf'] ?: '';
$rg = $client['rg'] ?: '';
$email = $client['email'] ?: '';
$phone = $client['phone'] ?: '';
$endereco = $client['address_street'] ?: '';
$cidade = $client['address_city'] ?: '';
$uf = $client['address_state'] ?: '';
$cep = $client['address_zip'] ?: '';
$profissao = $client['profession'] ?: '';
$estadoCivil = $client['marital_status'] ?: '';
$enderecoCompleto = $endereco . ($cidade ? ', ' . $cidade : '') . ($uf ? ' – ' . $uf : '') . ($cep ? ', CEP ' . $cep : '');
if (!trim(str_replace(array(',', '–', 'CEP'), '', $enderecoCompleto))) $enderecoCompleto = '';

// Buscar dados do filho
$childName = ''; $childBirth = '';
$childData = $pdo->prepare("SELECT payload_json FROM form_submissions WHERE linked_client_id = ? AND form_type IN ('convivencia','gastos_pensao') ORDER BY created_at DESC LIMIT 1");
$childData->execute(array($clientId));
$childForm = $childData->fetch();
if ($childForm) {
    $payload = json_decode($childForm['payload_json'], true);
    if (isset($payload['children'][0])) {
        $childName = isset($payload['children'][0]['name']) ? $payload['children'][0]['name'] : '';
        $childBirth = isset($payload['children'][0]['dob']) ? $payload['children'][0]['dob'] : '';
    }
    if (!$childName && isset($payload['nome_filho_referente'])) $childName = $payload['nome_filho_referente'];
}

// Data
$meses = array('','janeiro','fevereiro','março','abril','maio','junho','julho','agosto','setembro','outubro','novembro','dezembro');
$hoje = date('d') . ' de ' . $meses[(int)date('m')] . ' de ' . date('Y');

// Campos para contrato
$valorHonorarios = ''; $valorExtenso = ''; $formaPagamento = ''; $numParcelas = ''; $vencimento1a = ''; $tipoServicoCustom = '';

// POST = editor submetido
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome = $_POST['nome'] ?? $nome;
    $cpf = $_POST['cpf'] ?? $cpf;
    $rg = $_POST['rg'] ?? $rg;
    $email = $_POST['email'] ?? $email;
    $phone = $_POST['phone'] ?? $phone;
    $profissao = $_POST['profissao'] ?? $profissao;
    $estadoCivil = $_POST['estado_civil'] ?? $estadoCivil;
    $enderecoCompleto = $_POST['endereco_completo'] ?? $enderecoCompleto;
    $childName = $_POST['child_name'] ?? $childName;
    $childBirth = $_POST['child_birth'] ?? $childBirth;
    $valorHonorarios = $_POST['valor_honorarios'] ?? '';
    $valorExtenso = $_POST['valor_extenso'] ?? '';
    $formaPagamento = $_POST['forma_pagamento'] ?? '';
    $numParcelas = $_POST['num_parcelas'] ?? '';
    $vencimento1a = $_POST['vencimento_1a'] ?? '';
    $tipoServicoCustom = $_POST['tipo_servico_custom'] ?? '';
    if ($tipoServicoCustom) $acaoTexto = strtoupper($tipoServicoCustom);
}

$showEditor = ($_SERVER['REQUEST_METHOD'] !== 'POST');
$f = function($v, $ph = '_______________') { return $v ? e($v) : $ph; };
$logoUrl = url('assets/img/logo.png');
$isMenor = ($outorgante === 'menor');
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

        .toolbar {
            background:#052228; color:#fff; padding:.6rem 1.5rem;
            display:flex; align-items:center; justify-content:space-between; gap:.5rem;
            position:sticky; top:0; z-index:100; flex-wrap:wrap;
        }
        .toolbar a, .toolbar button {
            color:#fff; background:rgba(255,255,255,.15); border:none;
            padding:.45rem .85rem; border-radius:8px; cursor:pointer;
            font-family:inherit; font-size:.78rem; font-weight:600;
            text-decoration:none; display:inline-flex; align-items:center; gap:.3rem;
        }
        .toolbar a:hover, .toolbar button:hover { background:rgba(255,255,255,.25); }
        .toolbar .btn-zap { background:#25D366; }

        /* Editor */
        .editor { max-width:700px; margin:1.5rem auto; background:#fff; padding:1.5rem; border-radius:16px; box-shadow:0 4px 20px rgba(0,0,0,.1); }
        .editor h3 { font-size:1rem; color:#052228; margin-bottom:.75rem; }
        .editor .row { display:grid; grid-template-columns:1fr 1fr; gap:.75rem; margin-bottom:.75rem; }
        .editor label { font-size:.72rem; font-weight:700; color:#6b7280; text-transform:uppercase; display:block; margin-bottom:.2rem; }
        .editor input, .editor textarea, .editor select {
            width:100%; padding:.45rem .65rem; font-family:inherit; font-size:.82rem;
            border:1.5px solid #e5e7eb; border-radius:8px; outline:none;
        }
        .editor input:focus, .editor select:focus { border-color:#d7ab90; }
        .editor .section { margin-top:1rem; padding-top:1rem; border-top:2px solid #d7ab90; }
        .editor .section h4 { font-size:.85rem; color:#6a3c2c; margin-bottom:.5rem; }
        .btn-gen { width:100%; padding:.8rem; background:linear-gradient(135deg,#052228,#173d46); color:#fff; border:none; border-radius:12px; font-size:.9rem; font-weight:700; cursor:pointer; margin-top:1rem; }

        /* Documento */
        .page {
            max-width:210mm; margin:2rem auto; background:#fff;
            padding:50px 65px; box-shadow:0 4px 20px rgba(0,0,0,.15);
            min-height:297mm; line-height:1.7; font-size:12.5px;
        }
        .page-header { text-align:center; margin-bottom:1.5rem; padding-bottom:1rem; border-bottom:2px solid #b8956e; }
        .page-header img { max-width:280px; margin:0 auto .5rem; display:block; }
        .doc-title { text-align:center; font-size:14px; font-weight:800; color:#052228; text-decoration:underline; margin-bottom:1.5rem; letter-spacing:1px; }
        .doc-body { text-align:justify; }
        .doc-body p { margin-bottom:.7rem; }
        .doc-body strong { color:#052228; }
        .doc-body .underline-bold { text-decoration:underline; font-weight:800; }
        .local-data { text-align:center; margin-top:2rem; font-size:12px; }
        .assinatura { text-align:center; margin-top:3rem; }
        .assinatura .linha { border-top:1px solid #1a1a1a; width:320px; margin:0 auto .4rem; }
        .assinatura .nome-ass { font-weight:700; font-size:12px; }
        .page-footer {
            margin-top:auto; padding-top:1rem; border-top:2px solid #b8956e;
            text-align:center; font-size:10px; color:#6b7280;
            position:relative; bottom:0;
        }
        .page-footer .locations { font-size:9px; margin-top:.3rem; }

        @media print {
            body { background:#fff; }
            .toolbar, .editor { display:none !important; }
            .page { box-shadow:none; margin:0; padding:40px 55px; }
        }
    </style>
</head>
<body>

<?php if ($showEditor): ?>
<div class="toolbar">
    <a href="<?= module_url('documentos') ?>">← Voltar</a>
    <span style="font-size:.78rem;opacity:.7;"><?= e($pageTitle) ?><?= $acaoTexto ? ' — ' . e($acaoTexto) : '' ?></span>
</div>

<div class="editor">
    <h3>✏️ Revise os dados antes de gerar</h3>
    <form method="POST">
        <div class="row">
            <div><label>Nome completo</label><input name="nome" value="<?= e($nome) ?>"></div>
            <div><label>CPF</label><input name="cpf" value="<?= e($cpf) ?>" placeholder="000.000.000-00"></div>
        </div>
        <div class="row">
            <div><label>Profissão</label><input name="profissao" value="<?= e($profissao) ?>"></div>
            <div><label>Estado civil</label><input name="estado_civil" value="<?= e($estadoCivil) ?>"></div>
        </div>
        <div style="margin-bottom:.75rem;">
            <label>Endereço completo</label>
            <input name="endereco_completo" value="<?= e($enderecoCompleto) ?>">
        </div>
        <div class="row">
            <div><label>E-mail</label><input name="email" value="<?= e($email) ?>"></div>
            <div><label>Telefone</label><input name="phone" value="<?= e($phone) ?>"></div>
        </div>

        <?php if ($isMenor): ?>
        <div class="section">
            <h4>👶 Dados do menor (outorgante)</h4>
            <div class="row">
                <div><label>Nome da criança</label><input name="child_name" value="<?= e($childName) ?>"></div>
                <div><label>Data de nascimento</label><input name="child_birth" value="<?= e($childBirth) ?>" placeholder="aaaa-mm-dd"></div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($tipo === 'contrato'): ?>
        <div class="section">
            <h4>📝 Dados do contrato</h4>
            <?php if (!$acaoTexto): ?>
            <div style="margin-bottom:.75rem;">
                <label>Tipo do serviço (especificar)</label>
                <input name="tipo_servico_custom" value="" placeholder="Ex: Ação de Divórcio Consensual">
            </div>
            <?php endif; ?>
            <div class="row">
                <div><label>Valor (R$)</label><input name="valor_honorarios" value="<?= e($valorHonorarios) ?>" placeholder="3.000,00"></div>
                <div><label>Valor por extenso</label><input name="valor_extenso" value="<?= e($valorExtenso) ?>" placeholder="três mil reais"></div>
            </div>
            <div class="row">
                <div>
                    <label>Forma de pagamento</label>
                    <select name="forma_pagamento">
                        <option value="">— Selecionar —</option>
                        <option value="À vista, via PIX">À vista, via PIX</option>
                        <option value="À vista, em dinheiro">À vista, em dinheiro</option>
                        <option value="Parcelado em cartão de crédito">Parcelado em cartão</option>
                        <option value="Parcelado via boleto">Parcelado via boleto</option>
                        <option value="Parcelado via PIX">Parcelado via PIX</option>
                        <option value="Entrada + parcelas">Entrada + parcelas</option>
                    </select>
                </div>
                <div><label>Nº parcelas</label><input name="num_parcelas" value="" placeholder="3"></div>
            </div>
            <div class="row">
                <div><label>Vencimento 1ª parcela</label><input type="date" name="vencimento_1a"></div>
                <div></div>
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
        <?php if ($phone): ?>
            <a href="https://wa.me/55<?= preg_replace('/\D/', '', $phone) ?>" target="_blank" class="btn-zap">💬 WhatsApp</a>
        <?php endif; ?>
    </div>
</div>

<div class="page">
    <!-- Papel timbrado -->
    <div class="page-header">
        <img src="<?= $logoUrl ?>" alt="Ferreira &amp; Sá" onerror="this.outerHTML='<h2 style=color:#052228>FERREIRA &amp; SÁ</h2><p style=font-size:10px;color:#6b7280>ADVOCACIA ESPECIALIZADA</p>'">
    </div>

    <?php if ($tipo === 'procuracao'): ?>
    <div class="doc-title">PROCURAÇÃO <em>AD JUDICIA ET EXTRA</em></div>
    <div class="doc-body">
        <?php if ($isMenor): ?>
            <?php
            $childBirthFmt = $childBirth;
            if ($childBirth && preg_match('/^\d{4}-\d{2}-\d{2}$/', $childBirth)) $childBirthFmt = date('d/m/Y', strtotime($childBirth));
            ?>
            <p><strong>OUTORGANTE:</strong> <strong><?= $f($childName) ?></strong>, nascido(a) em <?= $f($childBirthFmt, '___/___/______') ?>, representado(a)/assistido(a) por <strong><?= $f($nome) ?></strong>, inscrita(o) no CPF sob o n. <strong><?= $f($cpf, '___.___.___-__') ?></strong>, residente e domiciliada(o) na <?= $f($enderecoCompleto) ?>, e-mail n. <?= $f($email) ?> e telefone n. <?= $f($phone) ?>.</p>
        <?php else: ?>
            <p><strong>OUTORGANTE:</strong> <strong><?= $f($nome) ?></strong>, inscrita(o) no CPF sob o n. <strong><?= $f($cpf, '___.___.___-__') ?></strong>, residente e domiciliada(o) na <?= $f($enderecoCompleto) ?>, e-mail n. <?= $f($email) ?> e telefone n. <?= $f($phone) ?>.</p>
        <?php endif; ?>

        <p><strong>OUTORGADA:</strong> <strong>FERREIRA &amp; SÁ ADVOCACIA</strong>, inscrita no CNPJ sob o n. 51.294.223/0001-40, Registro da Sociedade OAB 5.987/2023, e-mail: contato@ferreiraesa.com.br, whatsapp (24) 99205-0096, com escritório profissional localizado na Rua Jorge Gonçalves Pereira, n. 35 0 - Volta Redonda – RJ, neste ato representada por seus advogados sócios-administradores, <strong>AMANDA GUEDES FERREIRA</strong>, inscrita na OAB-RJ sob o n. 163.260 e <strong>LUIZ EDUARDO DE SÁ SILVA MARCELINO</strong>, inscrito na OAB-RJ sob o n. 248.755.</p>

        <p><strong>PODERES GERAIS:</strong> pelo presente documento, a parte outorgante designa e confia à parte outorgada a função de sua procuradora <u>judicial e extrajudicial</u>, concedendo-lhe plenos, gerais e ilimitados poderes para representá-la adequadamente em todas as instâncias judiciais e extrajudiciais, conforme estabelecido na cláusula <em>ad judicia et extra</em> e <em>ad negocia</em>, especialmente para atuar em <strong class="underline-bold"><?= $f($acaoTexto, '________________________________') ?></strong>. Isso inclui a autorização para subestabelecer esses poderes, com ou sem reserva, conforme necessário, possibilitando a realização de todos os atos essenciais para o desenvolvimento e execução eficazes deste mandato, em conformidade com o artigo 105 do Código de Processo Civil.</p>

        <p>Entre os poderes atribuídos, estão a capacidade recorrer, negociar acordos, contestar, receber notificações (<strong>EXCETO CITAÇÃO</strong>), assinar documentos variados, promover medidas cautelares, produzir provas, examinar processos, lidar com custas e despesas processuais, efetuar defesas e alegações, organizar documentos, solicitar perícias, entre outras atividades necessárias à representação efetiva perante qualquer esfera do Judiciário, órgãos públicos e entidades da administração direta ou indireta, em todos os níveis governamentais, garantindo o cumprimento integral deste mandato.</p>

        <p><strong>PODERES ESPECIAIS:</strong> esse instrumento também confere poderes específicos para atos como <strong>confessar, admitir</strong> a procedência de pedidos, <strong>negociar (acordar), desistir, renunciar</strong> a direitos subjacentes à ação, <strong>receber valores, emitir recibos e dar quitação, representar em audiência de conciliação e sessão de mediação, solicitar isenção de custas judiciais (gratuidade de justiça) e renunciar a valores excedentes (JEF)</strong>.</p>

        <div class="local-data">Rio de Janeiro, <?= $hoje ?>.</div>

        <div class="assinatura">
            <div class="linha"></div>
            <div class="nome-ass"><?= $f($nome) ?></div>
        </div>
    </div>

    <?php elseif ($tipo === 'contrato'): ?>
    <div class="doc-title">CONTRATO DE HONORÁRIOS ADVOCATÍCIOS</div>
    <div class="doc-body">
        <p><strong>CONTRATANTE:</strong> <strong><?= $f($nome) ?></strong>, inscrito(a) no CPF sob o n. <strong><?= $f($cpf, '___.___.___-__') ?></strong>, residente e domiciliado(a) na <?= $f($enderecoCompleto) ?>, e-mail: <?= $f($email) ?>, telefone: <?= $f($phone) ?>.</p>

        <p><strong>CONTRATADA:</strong> <strong>FERREIRA &amp; SÁ ADVOCACIA</strong>, inscrita no CNPJ sob o n. 51.294.223/0001-40, com sede na Rua Jorge Gonçalves Pereira, n. 35 0 - Volta Redonda – RJ, representada por <strong>AMANDA GUEDES FERREIRA</strong>, OAB-RJ 163.260 e <strong>LUIZ EDUARDO DE SÁ SILVA MARCELINO</strong>, OAB-RJ 248.755.</p>

        <p><strong>CLÁUSULA 1ª — DO OBJETO</strong><br>O presente contrato tem por objeto a prestação de serviços advocatícios pela CONTRATADA em favor do(a) CONTRATANTE, para <strong><?= $f($acaoTexto ?: $tipoServicoCustom, '________________________________') ?></strong>.</p>

        <p><strong>CLÁUSULA 2ª — DOS HONORÁRIOS</strong><br>Pelos serviços prestados, o(a) CONTRATANTE pagará à CONTRATADA o valor de <strong>R$ <?= $f($valorHonorarios, '_________') ?></strong> (<?= $f($valorExtenso, '___________________') ?>)<?php if ($formaPagamento): ?>, a ser pago da seguinte forma: <strong><?= e($formaPagamento) ?></strong><?php if ($numParcelas): ?> em <strong><?= e($numParcelas) ?> parcela(s)</strong><?php endif; ?><?php if ($vencimento1a): ?>, com vencimento da primeira parcela em <strong><?= data_br($vencimento1a) ?></strong><?php endif; ?><?php else: ?>, conforme combinado entre as partes<?php endif; ?>.</p>

        <p><strong>CLÁUSULA 3ª — DAS DESPESAS</strong><br>As despesas processuais correrão por conta do(a) CONTRATANTE.</p>

        <p><strong>CLÁUSULA 4ª — DA VIGÊNCIA</strong><br>O presente contrato vigorará até a conclusão definitiva dos serviços.</p>

        <p><strong>CLÁUSULA 5ª — DA RESCISÃO</strong><br>Poderá ser rescindido por qualquer das partes, mediante comunicação por escrito, resguardados os honorários proporcionais.</p>

        <p><strong>CLÁUSULA 6ª — DO FORO</strong><br>Fica eleito o Foro da Comarca de Volta Redonda/RJ.</p>

        <div class="local-data">Rio de Janeiro, <?= $hoje ?>.</div>

        <div style="display:flex;justify-content:space-around;margin-top:3rem;">
            <div class="assinatura" style="margin:0;"><div class="linha" style="width:240px;"></div><div class="nome-ass"><?= $f($nome) ?></div><div style="font-size:10px;color:#6b7280;">CONTRATANTE</div></div>
            <div class="assinatura" style="margin:0;"><div class="linha" style="width:240px;"></div><div class="nome-ass">Ferreira &amp; Sá Advocacia</div><div style="font-size:10px;color:#6b7280;">CONTRATADA</div></div>
        </div>
    </div>

    <?php elseif ($tipo === 'hipossuficiencia'): ?>
    <div class="doc-title">DECLARAÇÃO DE HIPOSSUFICIÊNCIA</div>
    <div class="doc-body">
        <p>Eu, <strong><?= $f($nome) ?></strong>, inscrito(a) no CPF sob o n. <strong><?= $f($cpf, '___.___.___-__') ?></strong>, residente e domiciliado(a) na <?= $f($enderecoCompleto) ?>,</p>
        <p><strong>DECLARO</strong>, para os devidos fins de direito, sob as penas da lei, que sou pessoa hipossuficiente, não possuindo condições financeiras de arcar com as custas processuais e honorários advocatícios sem prejuízo do meu sustento e de minha família.</p>
        <p>A presente declaração atende ao disposto no artigo 98 e seguintes do CPC (Lei nº 13.105/2015) e artigo 5º, inciso LXXIV, da Constituição Federal.</p>
        <div class="local-data">Rio de Janeiro, <?= $hoje ?>.</div>
        <div class="assinatura"><div class="linha"></div><div class="nome-ass"><?= $f($nome) ?></div></div>
    </div>

    <?php elseif ($tipo === 'isencao_ir'): ?>
    <div class="doc-title">DECLARAÇÃO DE ISENÇÃO DE IMPOSTO DE RENDA</div>
    <div class="doc-body">
        <p>Eu, <strong><?= $f($nome) ?></strong>, inscrito(a) no CPF sob o n. <strong><?= $f($cpf, '___.___.___-__') ?></strong>, residente e domiciliado(a) na <?= $f($enderecoCompleto) ?>,</p>
        <p><strong>DECLARO</strong>, para os devidos fins de direito, que estou isento(a) da obrigação de apresentar Declaração de Imposto de Renda Pessoa Física (DIRPF) referente ao exercício de <?= date('Y') ?>, por não me enquadrar em nenhuma das hipóteses de obrigatoriedade previstas na legislação tributária vigente.</p>
        <div class="local-data">Rio de Janeiro, <?= $hoje ?>.</div>
        <div class="assinatura"><div class="linha"></div><div class="nome-ass"><?= $f($nome) ?></div></div>
    </div>
    <?php endif; ?>

    <!-- Rodapé timbrado -->
    <div class="page-footer">
        <div class="locations">📍 Rio de Janeiro / RJ &nbsp;&nbsp; Barra Mansa / RJ &nbsp;&nbsp; Volta Redonda / RJ &nbsp;&nbsp; Resende / RJ &nbsp;&nbsp; São Paulo / SP</div>
        <div>(24) 9.9205-0096 / (11) 2110-5438</div>
        <div>🌐 www.ferreiraesa.com.br &nbsp;&nbsp; ✉ contato@ferreiraesa.com.br</div>
    </div>
</div>
<?php endif; ?>

</body>
</html>
