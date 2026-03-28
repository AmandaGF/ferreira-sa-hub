<?php
/**
 * Ferreira & Sá Hub — Gerar Documento (com edição antes de enviar)
 */

require_once __DIR__ . '/../../core/middleware.php';
require_min_role('gestao');

$pdo = db();
$tipo = $_GET['tipo'] ?? '';
$clientId = (int)($_GET['client_id'] ?? 0);

$validTypes = array('procuracao', 'procuracao_menor', 'contrato', 'hipossuficiencia', 'isencao_ir');
if (!in_array($tipo, $validTypes) || !$clientId) {
    flash_set('error', 'Selecione tipo e cliente.');
    redirect(module_url('documentos'));
}

$stmt = $pdo->prepare('SELECT * FROM clients WHERE id = ?');
$stmt->execute(array($clientId));
$client = $stmt->fetch();
if (!$client) { flash_set('error', 'Cliente não encontrado.'); redirect(module_url('documentos')); }

$typeLabels = array(
    'procuracao' => 'Procuração',
    'procuracao_menor' => 'Procuração (em nome do menor)',
    'contrato' => 'Contrato de Honorários Advocatícios',
    'hipossuficiencia' => 'Declaração de Hipossuficiência',
    'isencao_ir' => 'Declaração de Isenção de Imposto de Renda',
);
$pageTitle = $typeLabels[$tipo] ?? 'Documento';

// Dados do cliente
$nome = $client['name'] ?: '';
$cpf = $client['cpf'] ?: '';
$rg = $client['rg'] ?: '';
$nascimento = $client['birth_date'] ? data_br($client['birth_date']) : '';
$email = $client['email'] ?: '';
$phone = $client['phone'] ?: '';
$endereco = $client['address_street'] ?: '';
$cidade = $client['address_city'] ?: '';
$uf = $client['address_state'] ?: '';
$cep = $client['address_zip'] ?: '';
$profissao = $client['profession'] ?: '';
$estadoCivil = $client['marital_status'] ?: '';
$enderecoCompleto = $endereco . ($cidade ? ', ' . $cidade : '') . ($uf ? '/' . $uf : '') . ($cep ? ' — CEP: ' . $cep : '');
if (trim($enderecoCompleto) === '—' || trim($enderecoCompleto) === '') $enderecoCompleto = '';

// Buscar dados dos filhos (do formulário de convivência, se houver)
$childName = '';
$childBirth = '';
$childData = $pdo->prepare("SELECT payload_json FROM form_submissions WHERE linked_client_id = ? AND form_type IN ('convivencia','gastos_pensao') ORDER BY created_at DESC LIMIT 1");
$childData->execute(array($clientId));
$childForm = $childData->fetch();
if ($childForm) {
    $payload = json_decode($childForm['payload_json'], true);
    if (isset($payload['children']) && is_array($payload['children']) && !empty($payload['children'])) {
        $firstChild = $payload['children'][0];
        $childName = isset($firstChild['name']) ? $firstChild['name'] : '';
        $childBirth = isset($firstChild['dob']) ? $firstChild['dob'] : '';
    }
    if (!$childName && isset($payload['nome_filho_referente'])) {
        $childName = $payload['nome_filho_referente'];
    }
}

// Data por extenso
$meses = array('','janeiro','fevereiro','março','abril','maio','junho','julho','agosto','setembro','outubro','novembro','dezembro');
$hoje = date('d') . ' de ' . $meses[(int)date('m')] . ' de ' . date('Y');
$cidadeHoje = ($cidade ?: 'Resende') . '/' . ($uf ?: 'RJ') . ', ' . $hoje;

// Se for POST = campos foram editados
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome = $_POST['nome'] ?? $nome;
    $cpf = $_POST['cpf'] ?? $cpf;
    $rg = $_POST['rg'] ?? $rg;
    $profissao = $_POST['profissao'] ?? $profissao;
    $estadoCivil = $_POST['estado_civil'] ?? $estadoCivil;
    $enderecoCompleto = $_POST['endereco_completo'] ?? $enderecoCompleto;
    $phone = $_POST['phone'] ?? $phone;
    $email = $_POST['email'] ?? $email;
    $childName = $_POST['child_name'] ?? $childName;
    $childBirth = $_POST['child_birth'] ?? $childBirth;
    $cidadeHoje = $_POST['cidade_hoje'] ?? $cidadeHoje;
}

$showEditor = ($_SERVER['REQUEST_METHOD'] !== 'POST');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> — <?= e($nome) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Open Sans', serif; color: #1a1a1a; background: #e5e7eb; }

        .toolbar {
            background: #052228; color: #fff; padding: .75rem 1.5rem;
            display: flex; align-items: center; justify-content: space-between; gap:.5rem;
            position: sticky; top: 0; z-index: 100; flex-wrap:wrap;
        }
        .toolbar a, .toolbar button {
            color: #fff; background: rgba(255,255,255,.15); border: none;
            padding: .5rem 1rem; border-radius: 8px; cursor: pointer;
            font-family: inherit; font-size: .82rem; font-weight: 600;
            text-decoration: none; display: inline-flex; align-items: center; gap: .35rem;
        }
        .toolbar a:hover, .toolbar button:hover { background: rgba(255,255,255,.25); }
        .toolbar .btn-zap { background: #25D366; }
        .toolbar .btn-sign { background: #7c3aed; }
        .toolbar .btn-edit { background: #d97706; }

        .editor {
            max-width: 700px; margin: 1.5rem auto; background: #fff;
            padding: 1.5rem; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,.1);
        }
        .editor h3 { font-size: 1rem; color: #052228; margin-bottom: 1rem; }
        .editor .row { display: grid; grid-template-columns: 1fr 1fr; gap: .75rem; margin-bottom: .75rem; }
        .editor label { font-size: .75rem; font-weight: 700; color: #6b7280; text-transform: uppercase; display: block; margin-bottom: .2rem; }
        .editor input, .editor textarea {
            width: 100%; padding: .5rem .75rem; font-family: inherit; font-size: .85rem;
            border: 1.5px solid #e5e7eb; border-radius: 8px; outline: none;
        }
        .editor input:focus, .editor textarea:focus { border-color: #d7ab90; }
        .editor .btn-gen {
            width: 100%; padding: .85rem; background: linear-gradient(135deg, #052228, #173d46);
            color: #fff; border: none; border-radius: 12px; font-size: .95rem; font-weight: 700;
            cursor: pointer; margin-top: 1rem;
        }

        .page {
            max-width: 210mm; margin: 2rem auto; background: #fff;
            padding: 60px 70px; box-shadow: 0 4px 20px rgba(0,0,0,.15);
            min-height: 297mm; line-height: 1.8; font-size: 13px;
        }
        .header-doc { text-align: center; margin-bottom: 2rem; border-bottom: 2px solid #052228; padding-bottom: 1rem; }
        .header-doc h1 { font-size: 18px; font-weight: 800; color: #052228; text-transform: uppercase; letter-spacing: 2px; }
        .header-doc .escritorio { font-size: 11px; color: #6b7280; margin-top: .25rem; }
        .doc-body p { margin-bottom: .8rem; text-align: justify; text-indent: 2rem; }
        .doc-body .no-indent { text-indent: 0; }
        .doc-body strong { color: #052228; }
        .assinatura { margin-top: 3rem; text-align: center; }
        .assinatura .linha { border-top: 1px solid #1a1a1a; width: 300px; margin: 2rem auto .5rem; }
        .assinatura .nome-ass { font-weight: 700; }
        .assinatura .cpf-ass { font-size: 11px; color: #6b7280; }
        .local-data { margin-top: 2rem; text-align: right; font-size: 12px; }

        @media print {
            body { background: #fff; }
            .toolbar, .editor { display: none !important; }
            .page { box-shadow: none; margin: 0; padding: 40px 60px; }
        }
    </style>
</head>
<body>

<?php if ($showEditor): ?>
<!-- EDITOR: preencher/ajustar dados antes de gerar -->
<div class="toolbar">
    <a href="<?= module_url('documentos') ?>">← Voltar</a>
    <span style="font-size:.82rem;opacity:.7;"><?= e($pageTitle) ?></span>
</div>

<div class="editor">
    <h3>✏️ Revise e complete os dados antes de gerar</h3>
    <form method="POST">
        <div class="row">
            <div><label>Nome do outorgante</label><input name="nome" value="<?= e($nome) ?>"></div>
            <div><label>CPF</label><input name="cpf" value="<?= e($cpf) ?>" placeholder="000.000.000-00"></div>
        </div>
        <div class="row">
            <div><label>RG</label><input name="rg" value="<?= e($rg) ?>"></div>
            <div><label>Profissão</label><input name="profissao" value="<?= e($profissao) ?>"></div>
        </div>
        <div class="row">
            <div><label>Estado Civil</label><input name="estado_civil" value="<?= e($estadoCivil) ?>"></div>
            <div><label>Telefone</label><input name="phone" value="<?= e($phone) ?>"></div>
        </div>
        <div style="margin-bottom:.75rem;">
            <label>Endereço completo</label>
            <input name="endereco_completo" value="<?= e($enderecoCompleto) ?>" placeholder="Rua, nº, bairro, cidade/UF, CEP">
        </div>
        <div class="row">
            <div><label>E-mail</label><input name="email" value="<?= e($email) ?>"></div>
            <div><label>Local e data</label><input name="cidade_hoje" value="<?= e($cidadeHoje) ?>"></div>
        </div>

        <?php if ($tipo === 'procuracao_menor'): ?>
        <div style="margin-top:1rem;padding-top:1rem;border-top:2px solid #d7ab90;">
            <h3 style="font-size:.9rem;color:#6a3c2c;">👶 Dados do menor (outorgante)</h3>
            <div class="row" style="margin-top:.5rem;">
                <div><label>Nome completo da criança</label><input name="child_name" value="<?= e($childName) ?>" placeholder="Nome do menor"></div>
                <div><label>Data de nascimento</label><input name="child_birth" value="<?= e($childBirth) ?>" placeholder="dd/mm/aaaa ou aaaa-mm-dd"></div>
            </div>
        </div>
        <?php endif; ?>

        <button type="submit" class="btn-gen">Gerar Documento →</button>
    </form>
</div>

<?php else: ?>
<!-- DOCUMENTO GERADO -->
<div class="toolbar">
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
        <a href="<?= module_url('documentos') ?>">← Voltar</a>
        <a href="<?= module_url('documentos', 'gerar.php?tipo=' . urlencode($tipo) . '&client_id=' . $clientId) ?>">✏️ Editar dados</a>
        <button onclick="window.print()">🖨️ Imprimir / PDF</button>
        <?php if ($phone): ?>
            <a href="https://wa.me/55<?= preg_replace('/\D/', '', $phone) ?>" target="_blank" class="btn-zap">💬 WhatsApp</a>
        <?php endif; ?>
    </div>
    <form method="POST" action="<?= module_url('documentos', 'zapsign.php') ?>" style="display:inline;">
        <?= csrf_input() ?>
        <input type="hidden" name="tipo" value="<?= e($tipo) ?>">
        <input type="hidden" name="client_id" value="<?= $clientId ?>">
        <button type="submit" class="btn-sign">✍️ Enviar p/ ZapSign</button>
    </form>
</div>

<div class="page">
    <div class="header-doc">
        <h1><?= e($pageTitle) ?></h1>
        <div class="escritorio">Ferreira &amp; Sá Advocacia Especializada</div>
    </div>

    <div class="doc-body">
    <?php
    // Formatar campos vazios
    $f = function($v, $placeholder = '_______________') { return $v ? e($v) : $placeholder; };
    ?>

    <?php if ($tipo === 'procuracao'): ?>

        <p>Pelo presente instrumento particular de procuração, eu, <strong><?= $f($nome) ?></strong>, <?= $f($profissao) ?>, <?= $f($estadoCivil) ?>, portador(a) do RG nº <strong><?= $f($rg) ?></strong> e inscrito(a) no CPF sob nº <strong><?= $f($cpf, '___.___.___-__') ?></strong>, residente e domiciliado(a) à <strong><?= $f($enderecoCompleto) ?></strong>, telefone <strong><?= $f($phone) ?></strong>, e-mail <strong><?= $f($email) ?></strong>,</p>

        <p>nomeio e constituo como meu(minha) bastante procurador(a) o(a) advogado(a) <strong>_________________________________</strong>, inscrito(a) na OAB/___ sob nº <strong>________</strong>, com escritório profissional situado à <strong>_________________________________</strong>,</p>

        <p>a quem confiro amplos poderes da cláusula <em>"ad judicia et extra"</em>, para, em meu nome, representar-me em juízo ou fora dele, podendo propor ações de qualquer natureza, contestar, recorrer, reconvir, transigir, desistir, dar e receber quitação, firmar compromissos, substabelecer com ou sem reserva de poderes, e praticar todos os demais atos necessários ao bom e fiel cumprimento do presente mandato.</p>

        <div class="local-data"><?= $f($cidadeHoje) ?></div>
        <div class="assinatura">
            <div class="linha"></div>
            <div class="nome-ass"><?= $f($nome) ?></div>
            <div class="cpf-ass">CPF: <?= $f($cpf, '___.___.___-__') ?></div>
        </div>

    <?php elseif ($tipo === 'procuracao_menor'): ?>

        <?php
        $childBirthFormatted = $childBirth;
        if ($childBirth && preg_match('/^\d{4}-\d{2}-\d{2}$/', $childBirth)) {
            $childBirthFormatted = date('d/m/Y', strtotime($childBirth));
        }
        ?>

        <p>Pelo presente instrumento particular de procuração, o(a) menor <strong><?= $f($childName) ?></strong><?= $childBirthFormatted ? ', nascido(a) em <strong>' . e($childBirthFormatted) . '</strong>' : '' ?>, neste ato representado(a) por seu(sua) genitor(a) <strong><?= $f($nome) ?></strong>, <?= $f($profissao) ?>, <?= $f($estadoCivil) ?>, portador(a) do RG nº <strong><?= $f($rg) ?></strong> e inscrito(a) no CPF sob nº <strong><?= $f($cpf, '___.___.___-__') ?></strong>, residente e domiciliado(a) à <strong><?= $f($enderecoCompleto) ?></strong>, telefone <strong><?= $f($phone) ?></strong>, e-mail <strong><?= $f($email) ?></strong>,</p>

        <p>nomeia e constitui como seu(sua) bastante procurador(a) o(a) advogado(a) <strong>_________________________________</strong>, inscrito(a) na OAB/___ sob nº <strong>________</strong>, com escritório profissional situado à <strong>_________________________________</strong>,</p>

        <p>a quem confere amplos poderes da cláusula <em>"ad judicia et extra"</em>, para, em nome do(a) menor acima qualificado(a), representá-lo(a) em juízo ou fora dele, especialmente para propor <strong>AÇÃO DE ALIMENTOS</strong> e demais medidas judiciais cabíveis, podendo contestar, recorrer, reconvir, transigir, desistir, dar e receber quitação, firmar compromissos, substabelecer com ou sem reserva de poderes, e praticar todos os demais atos necessários ao bom e fiel cumprimento do presente mandato.</p>

        <div class="local-data"><?= $f($cidadeHoje) ?></div>
        <div class="assinatura">
            <div class="linha"></div>
            <div class="nome-ass"><?= $f($nome) ?></div>
            <div class="cpf-ass">CPF: <?= $f($cpf, '___.___.___-__') ?></div>
            <div class="cpf-ass">Representante legal de <?= $f($childName) ?></div>
        </div>

    <?php elseif ($tipo === 'contrato'): ?>

        <p class="no-indent"><strong>CONTRATANTE:</strong> <strong><?= $f($nome) ?></strong>, <?= $f($profissao) ?>, <?= $f($estadoCivil) ?>, portador(a) do RG nº <strong><?= $f($rg) ?></strong>, inscrito(a) no CPF sob nº <strong><?= $f($cpf, '___.___.___-__') ?></strong>, residente e domiciliado(a) à <strong><?= $f($enderecoCompleto) ?></strong>, telefone <strong><?= $f($phone) ?></strong>, e-mail <strong><?= $f($email) ?></strong>.</p>

        <p class="no-indent"><strong>CONTRATADA:</strong> <strong>FERREIRA &amp; SÁ ADVOCACIA ESPECIALIZADA</strong>, inscrita no CNPJ sob nº <strong>__.___.___/____-__</strong>, com sede à <strong>_________________________________</strong>, neste ato representada por seu(sua) sócio(a)-administrador(a).</p>

        <p class="no-indent" style="margin-top:1.5rem;"><strong>CLÁUSULA 1ª — DO OBJETO</strong></p>
        <p>O presente contrato tem por objeto a prestação de serviços advocatícios pela CONTRATADA em favor do(a) CONTRATANTE, para <strong>_________________________________</strong>.</p>

        <p class="no-indent"><strong>CLÁUSULA 2ª — DOS HONORÁRIOS</strong></p>
        <p>Pelos serviços prestados, o(a) CONTRATANTE pagará à CONTRATADA o valor de <strong>R$ _________</strong> (___________________), a ser pago da seguinte forma: <strong>_________________________________</strong>.</p>

        <p class="no-indent"><strong>CLÁUSULA 3ª — DAS DESPESAS</strong></p>
        <p>As despesas processuais, tais como custas, emolumentos, taxas, perícias e demais encargos, correrão por conta do(a) CONTRATANTE, sendo pagas diretamente ou mediante reembolso à CONTRATADA.</p>

        <p class="no-indent"><strong>CLÁUSULA 4ª — DA VIGÊNCIA</strong></p>
        <p>O presente contrato vigorará até a conclusão definitiva dos serviços contratados, incluindo todas as fases processuais necessárias.</p>

        <p class="no-indent"><strong>CLÁUSULA 5ª — DA RESCISÃO</strong></p>
        <p>O presente contrato poderá ser rescindido por qualquer das partes, mediante comunicação por escrito, ficando resguardados os honorários proporcionais aos serviços já prestados.</p>

        <p class="no-indent"><strong>CLÁUSULA 6ª — DO FORO</strong></p>
        <p>Fica eleito o Foro da Comarca de <?= e($cidade ?: 'Resende') ?>/<?= e($uf ?: 'RJ') ?> para dirimir quaisquer questões oriundas deste contrato.</p>

        <p>E por estarem justas e contratadas, as partes firmam o presente em duas vias de igual teor e forma.</p>

        <div class="local-data"><?= $f($cidadeHoje) ?></div>
        <div style="display:flex;justify-content:space-around;margin-top:3rem;">
            <div class="assinatura" style="margin:0;">
                <div class="linha" style="width:250px;"></div>
                <div class="nome-ass"><?= $f($nome) ?></div>
                <div class="cpf-ass">CONTRATANTE — CPF: <?= $f($cpf, '___.___.___-__') ?></div>
            </div>
            <div class="assinatura" style="margin:0;">
                <div class="linha" style="width:250px;"></div>
                <div class="nome-ass">Ferreira &amp; Sá Advocacia</div>
                <div class="cpf-ass">CONTRATADA</div>
            </div>
        </div>

    <?php elseif ($tipo === 'hipossuficiencia'): ?>

        <p>Eu, <strong><?= $f($nome) ?></strong>, <?= $f($profissao) ?>, <?= $f($estadoCivil) ?>, portador(a) do RG nº <strong><?= $f($rg) ?></strong>, inscrito(a) no CPF sob nº <strong><?= $f($cpf, '___.___.___-__') ?></strong>, residente e domiciliado(a) à <strong><?= $f($enderecoCompleto) ?></strong>,</p>

        <p><strong>DECLARO</strong>, para os devidos fins de direito, sob as penas da lei, que sou pessoa hipossuficiente, não possuindo condições financeiras de arcar com as custas processuais e honorários advocatícios sem prejuízo do meu sustento e de minha família.</p>

        <p>A presente declaração é expressão da verdade, firmada sob as penas do artigo 299 do Código Penal, e atende ao disposto no artigo 98 e seguintes do Código de Processo Civil (Lei nº 13.105/2015) e artigo 5º, inciso LXXIV, da Constituição Federal.</p>

        <div class="local-data"><?= $f($cidadeHoje) ?></div>
        <div class="assinatura">
            <div class="linha"></div>
            <div class="nome-ass"><?= $f($nome) ?></div>
            <div class="cpf-ass">CPF: <?= $f($cpf, '___.___.___-__') ?></div>
        </div>

    <?php elseif ($tipo === 'isencao_ir'): ?>

        <p>Eu, <strong><?= $f($nome) ?></strong>, <?= $f($profissao) ?>, <?= $f($estadoCivil) ?>, portador(a) do RG nº <strong><?= $f($rg) ?></strong>, inscrito(a) no CPF sob nº <strong><?= $f($cpf, '___.___.___-__') ?></strong>, residente e domiciliado(a) à <strong><?= $f($enderecoCompleto) ?></strong>,</p>

        <p><strong>DECLARO</strong>, para os devidos fins de direito e sob as penas da lei, que estou isento(a) da obrigação de apresentar Declaração de Imposto de Renda Pessoa Física (DIRPF) referente ao exercício de <?= date('Y') ?> (ano-calendário <?= date('Y') - 1 ?>), por não me enquadrar em nenhuma das hipóteses de obrigatoriedade previstas na legislação tributária vigente.</p>

        <p>Declaro, ainda, que meus rendimentos tributáveis no ano-calendário de <?= date('Y') - 1 ?> foram inferiores ao limite estabelecido pela Receita Federal do Brasil para obrigatoriedade de entrega da declaração.</p>

        <p>A presente declaração é expressão da verdade, firmada sob as penas do artigo 299 do Código Penal.</p>

        <div class="local-data"><?= $f($cidadeHoje) ?></div>
        <div class="assinatura">
            <div class="linha"></div>
            <div class="nome-ass"><?= $f($nome) ?></div>
            <div class="cpf-ass">CPF: <?= $f($cpf, '___.___.___-__') ?></div>
        </div>

    <?php endif; ?>
    </div>
</div>
<?php endif; ?>

</body>
</html>
