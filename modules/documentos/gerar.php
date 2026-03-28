<?php
/**
 * Ferreira & Sá Hub — Gerar Documento
 */

require_once __DIR__ . '/../../core/middleware.php';
require_min_role('gestao');

$pdo = db();
$tipo = $_GET['tipo'] ?? '';
$clientId = (int)($_GET['client_id'] ?? 0);

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
    'procuracao' => 'Procuração',
    'contrato' => 'Contrato de Honorários Advocatícios',
    'hipossuficiencia' => 'Declaração de Hipossuficiência',
    'isencao_ir' => 'Declaração de Isenção de Imposto de Renda',
);

$pageTitle = $typeLabels[$tipo] ?? 'Documento';

// Dados para preenchimento
$nome = $client['name'] ?? '_______________';
$cpf = $client['cpf'] ?? '___.___.___-__';
$rg = $client['rg'] ?? '______________';
$nascimento = $client['birth_date'] ? data_br($client['birth_date']) : '__/__/____';
$email = $client['email'] ?? '_______________';
$phone = $client['phone'] ?? '_______________';
$endereco = $client['address_street'] ?? '_______________';
$cidade = $client['address_city'] ?? '_______________';
$uf = $client['address_state'] ?? '__';
$cep = $client['address_zip'] ?? '_____-___';
$profissao = $client['profession'] ?? '_______________';
$estadoCivil = $client['marital_status'] ?? '_______________';
$enderecoCompleto = $endereco . ($cidade ? ', ' . $cidade : '') . ($uf ? '/' . $uf : '') . ($cep ? ' — CEP: ' . $cep : '');

$hoje = strftime('%d de %B de %Y');
// Fallback se strftime não funcionar com locale
$meses = array('', 'janeiro','fevereiro','março','abril','maio','junho','julho','agosto','setembro','outubro','novembro','dezembro');
$hoje = date('d') . ' de ' . $meses[(int)date('m')] . ' de ' . date('Y');

$cidadeHoje = ($cidade ?: 'Resende') . '/' . ($uf ?: 'RJ') . ', ' . $hoje;
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
            display: flex; align-items: center; justify-content: space-between;
            position: sticky; top: 0; z-index: 100;
        }
        .toolbar a, .toolbar button {
            color: #fff; background: rgba(255,255,255,.15); border: none;
            padding: .5rem 1rem; border-radius: 8px; cursor: pointer;
            font-family: inherit; font-size: .82rem; font-weight: 600;
            text-decoration: none; display: inline-flex; align-items: center; gap: .35rem;
        }
        .toolbar a:hover, .toolbar button:hover { background: rgba(255,255,255,.25); }
        .toolbar .btn-zap { background: #25D366; }

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
        .doc-body .destaque { background: #fffbeb; padding: 2px 4px; border-radius: 3px; }

        .assinatura { margin-top: 3rem; text-align: center; }
        .assinatura .linha { border-top: 1px solid #1a1a1a; width: 300px; margin: 2rem auto .5rem; }
        .assinatura .nome { font-weight: 700; }
        .assinatura .cpf-ass { font-size: 11px; color: #6b7280; }

        .local-data { margin-top: 2rem; text-align: right; font-size: 12px; }

        @media print {
            body { background: #fff; }
            .toolbar { display: none !important; }
            .page { box-shadow: none; margin: 0; padding: 40px 60px; }
        }
    </style>
</head>
<body>

<div class="toolbar">
    <div style="display:flex;gap:.5rem;">
        <a href="<?= module_url('documentos') ?>">← Voltar</a>
        <button onclick="window.print()">🖨️ Imprimir / PDF</button>
        <?php if ($client['phone']): ?>
            <a href="https://wa.me/55<?= preg_replace('/\D/', '', $client['phone']) ?>" target="_blank" class="btn-zap">💬 WhatsApp</a>
        <?php endif; ?>
    </div>
    <span style="font-size:.78rem;opacity:.7;"><?= e($pageTitle) ?> — <?= e($nome) ?></span>
</div>

<div class="page">
    <div class="header-doc">
        <h1><?= e($pageTitle) ?></h1>
        <div class="escritorio">Ferreira &amp; Sá Advocacia Especializada</div>
    </div>

    <div class="doc-body">
    <?php if ($tipo === 'procuracao'): ?>

        <p>Pelo presente instrumento particular de procuração, eu, <strong><?= e($nome) ?></strong>, <?= e($profissao) ?>, <?= e($estadoCivil) ?>, portador(a) do RG nº <strong><?= e($rg) ?></strong> e inscrito(a) no CPF sob nº <strong><?= e($cpf) ?></strong>, residente e domiciliado(a) à <strong><?= e($enderecoCompleto) ?></strong>, telefone <strong><?= e($phone) ?></strong>, e-mail <strong><?= e($email) ?></strong>,</p>

        <p>nomeio e constituo como meu(minha) bastante procurador(a) o(a) advogado(a) <strong>_________________________________</strong>, inscrito(a) na OAB/___ sob nº <strong>________</strong>, com escritório profissional situado à <strong>_________________________________</strong>,</p>

        <p>a quem confiro amplos poderes da cláusula <em>"ad judicia et extra"</em>, para, em meu nome, representar-me em juízo ou fora dele, podendo propor ações de qualquer natureza, contestar, recorrer, reconvir, transigir, desistir, dar e receber quitação, firmar compromissos, substabelecer com ou sem reserva de poderes, e praticar todos os demais atos necessários ao bom e fiel cumprimento do presente mandato.</p>

        <div class="local-data"><?= e($cidadeHoje) ?></div>

        <div class="assinatura">
            <div class="linha"></div>
            <div class="nome"><?= e($nome) ?></div>
            <div class="cpf-ass">CPF: <?= e($cpf) ?></div>
        </div>

    <?php elseif ($tipo === 'contrato'): ?>

        <p class="no-indent"><strong>CONTRATANTE:</strong> <strong><?= e($nome) ?></strong>, <?= e($profissao) ?>, <?= e($estadoCivil) ?>, portador(a) do RG nº <strong><?= e($rg) ?></strong>, inscrito(a) no CPF sob nº <strong><?= e($cpf) ?></strong>, residente e domiciliado(a) à <strong><?= e($enderecoCompleto) ?></strong>, telefone <strong><?= e($phone) ?></strong>, e-mail <strong><?= e($email) ?></strong>.</p>

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

        <div class="local-data"><?= e($cidadeHoje) ?></div>

        <div style="display:flex;justify-content:space-around;margin-top:3rem;">
            <div class="assinatura" style="margin:0;">
                <div class="linha" style="width:250px;"></div>
                <div class="nome"><?= e($nome) ?></div>
                <div class="cpf-ass">CONTRATANTE — CPF: <?= e($cpf) ?></div>
            </div>
            <div class="assinatura" style="margin:0;">
                <div class="linha" style="width:250px;"></div>
                <div class="nome">Ferreira &amp; Sá Advocacia</div>
                <div class="cpf-ass">CONTRATADA</div>
            </div>
        </div>

    <?php elseif ($tipo === 'hipossuficiencia'): ?>

        <p>Eu, <strong><?= e($nome) ?></strong>, <?= e($profissao) ?>, <?= e($estadoCivil) ?>, portador(a) do RG nº <strong><?= e($rg) ?></strong>, inscrito(a) no CPF sob nº <strong><?= e($cpf) ?></strong>, residente e domiciliado(a) à <strong><?= e($enderecoCompleto) ?></strong>,</p>

        <p><strong>DECLARO</strong>, para os devidos fins de direito, sob as penas da lei, que sou pessoa hipossuficiente, não possuindo condições financeiras de arcar com as custas processuais e honorários advocatícios sem prejuízo do meu sustento e de minha família.</p>

        <p>A presente declaração é expressão da verdade, firmada sob as penas do artigo 299 do Código Penal, e atende ao disposto no artigo 98 e seguintes do Código de Processo Civil (Lei nº 13.105/2015) e artigo 5º, inciso LXXIV, da Constituição Federal.</p>

        <div class="local-data"><?= e($cidadeHoje) ?></div>

        <div class="assinatura">
            <div class="linha"></div>
            <div class="nome"><?= e($nome) ?></div>
            <div class="cpf-ass">CPF: <?= e($cpf) ?></div>
        </div>

    <?php elseif ($tipo === 'isencao_ir'): ?>

        <p>Eu, <strong><?= e($nome) ?></strong>, <?= e($profissao) ?>, <?= e($estadoCivil) ?>, portador(a) do RG nº <strong><?= e($rg) ?></strong>, inscrito(a) no CPF sob nº <strong><?= e($cpf) ?></strong>, residente e domiciliado(a) à <strong><?= e($enderecoCompleto) ?></strong>,</p>

        <p><strong>DECLARO</strong>, para os devidos fins de direito e sob as penas da lei, que estou isento(a) da obrigação de apresentar Declaração de Imposto de Renda Pessoa Física (DIRPF) referente ao exercício de <?= date('Y') ?> (ano-calendário <?= date('Y') - 1 ?>), por não me enquadrar em nenhuma das hipóteses de obrigatoriedade previstas na legislação tributária vigente.</p>

        <p>Declaro, ainda, que meus rendimentos tributáveis no ano-calendário de <?= date('Y') - 1 ?> foram inferiores ao limite estabelecido pela Receita Federal do Brasil para obrigatoriedade de entrega da declaração.</p>

        <p>A presente declaração é expressão da verdade, firmada sob as penas do artigo 299 do Código Penal.</p>

        <div class="local-data"><?= e($cidadeHoje) ?></div>

        <div class="assinatura">
            <div class="linha"></div>
            <div class="nome"><?= e($nome) ?></div>
            <div class="cpf-ass">CPF: <?= e($cpf) ?></div>
        </div>

    <?php endif; ?>
    </div>
</div>

</body>
</html>
