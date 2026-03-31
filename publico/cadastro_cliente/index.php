<?php
/**
 * Formulário de Cadastro de Clientes — versão PHP (grava direto no TurboCloud)
 * Substitui a versão Firebase
 */

// Detectar se está sendo chamado via require externo (ex: /cadastro/)
$_formBaseDir = realpath(__DIR__);
$_coreDir = $_formBaseDir . '/../../core';

require_once $_coreDir . '/config.php';
require_once $_coreDir . '/database.php';
require_once $_coreDir . '/functions.php';
require_once $_coreDir . '/form_handler.php';

$success = false;
$protocol = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome = clean_str($_POST['nome'] ?? '', 150);
    $cpf = clean_str($_POST['cpf'] ?? '', 14);
    $nascimento = $_POST['nascimento'] ?? '';
    $profissao = clean_str($_POST['profissao'] ?? '', 100);
    $estado_civil = clean_str($_POST['estado_civil'] ?? '', 30);
    $rg = clean_str($_POST['rg'] ?? '', 20);
    $celular = clean_str($_POST['celular'] ?? '', 40);
    $email = trim($_POST['email'] ?? '');
    $cep = clean_str($_POST['cep'] ?? '', 10);
    $endereco = clean_str($_POST['endereco'] ?? '', 500);
    $cidade = clean_str($_POST['cidade'] ?? '', 100);
    $uf = clean_str($_POST['uf'] ?? '', 2);
    $pix = clean_str($_POST['pix'] ?? '', 100);
    $conta_bancaria = clean_str($_POST['conta_bancaria'] ?? '', 200);
    $imposto_renda = $_POST['imposto_renda'] ?? '';
    $clt = $_POST['clt'] ?? '';
    $filhos = $_POST['filhos'] ?? '';
    $nome_filhos = clean_str($_POST['nome_filhos'] ?? '', 500);
    $tipo_atendimento = clean_str($_POST['tipo_atendimento'] ?? '', 30);
    $autoriza_contato = clean_str($_POST['autoriza_contato'] ?? '', 30);
    $fam_saude = clean_str($_POST['fam_saude'] ?? '', 300);
    $fam_escola = clean_str($_POST['fam_escola'] ?? '', 30);
    $fam_pensao_atual = clean_str($_POST['fam_pensao_atual'] ?? '', 200);
    $fam_trabalho_genitor = clean_str($_POST['fam_trabalho_genitor'] ?? '', 200);
    $fam_contato_genitor = clean_str($_POST['fam_contato_genitor'] ?? '', 40);
    $fam_endereco_genitor = clean_str($_POST['fam_endereco_genitor'] ?? '', 500);

    if (empty($nome)) { $error = 'Nome é obrigatório.'; }
    elseif (empty($celular)) { $error = 'Celular é obrigatório.'; }
    else {
        $payload = array(
            'nome' => $nome, 'cpf' => $cpf, 'nascimento' => $nascimento,
            'profissao' => $profissao, 'estado_civil' => $estado_civil, 'rg' => $rg,
            'celular' => $celular, 'email' => $email, 'cep' => $cep, 'endereco' => $endereco,
            'pix' => $pix, 'conta_bancaria' => $conta_bancaria,
            'imposto_renda' => $imposto_renda, 'clt' => $clt,
            'filhos' => $filhos, 'nome_filhos' => $nome_filhos,
            'tipo_atendimento' => $tipo_atendimento, 'autoriza_contato' => $autoriza_contato,
            'fam_saude' => $fam_saude, 'fam_escola' => $fam_escola,
            'fam_pensao_atual' => $fam_pensao_atual, 'fam_trabalho_genitor' => $fam_trabalho_genitor,
            'fam_contato_genitor' => $fam_contato_genitor, 'fam_endereco_genitor' => $fam_endereco_genitor,
        );

        try {
            $result = process_form_submission(
                'cadastro_cliente',
                array(
                    'name' => $nome,
                    'phone' => $celular,
                    'email' => $email,
                    'cpf' => $cpf,
                    'rg' => $rg,
                    'birth_date' => $nascimento ?: null,
                    'profession' => $profissao,
                    'marital_status' => $estado_civil,
                    'address_street' => $endereco,
                    'address_city' => $cidade,
                    'address_state' => $uf,
                    'address_zip' => $cep,
                    'gender' => isset($sexo) ? $sexo : null,
                    'has_children' => ($filhos === 'Sim') ? 1 : (($filhos === 'Não') ? 0 : null),
                    'children_names' => $nome_filhos ?: null,
                ),
                json_encode($payload, JSON_UNESCAPED_UNICODE)
            );

            $protocol = $result['protocol'];
            $success = true;
        } catch (Exception $e) {
            $error = 'Erro ao salvar: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cadastro de Novos Clientes - Ferreira e Sá Advocacia</title>
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family:'Open Sans',sans-serif; background-color:#f4f4f4; color:#5d5c62; display:flex; justify-content:center; padding:30px 15px; margin:0; line-height:1.6; }
        .container { background-color:white; padding:50px; border-radius:8px; box-shadow:0 10px 30px rgba(0,0,0,0.05); width:100%; max-width:750px; border-top:8px solid #052228; }
        .logo-container { text-align:center; margin-bottom:30px; }
        .logo-container img { max-width:350px; width:100%; height:auto; display:block; margin:0 auto; }
        .logo-fallback { font-size:28px; font-weight:bold; color:#052228; text-transform:uppercase; letter-spacing:2px; display:none; }
        p.subtitle { text-align:center; color:#5d5c62; margin-bottom:40px; font-size:16px; font-weight:600; }
        .section-title { padding:10px 0; margin-top:40px; margin-bottom:20px; border-bottom:2px solid #d7ab90; color:#052228; font-size:18px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; }
        label { display:block; margin-top:20px; margin-bottom:8px; font-weight:600; color:#052228; font-size:14px; }
        input, select, textarea { width:100%; padding:14px; border:1px solid #e0e0e0; border-radius:4px; box-sizing:border-box; font-size:15px; font-family:'Open Sans',sans-serif; background-color:#fafafa; color:#333; }
        input:focus, select:focus, textarea:focus { outline:none; border-color:#052228; box-shadow:0 0 0 3px rgba(5,34,40,0.1); background-color:#fff; }
        textarea { min-height:80px; resize:vertical; }
        .radio-group { display:flex; gap:20px; margin-top:5px; }
        .radio-group label { display:flex; align-items:center; gap:8px; margin-top:0; font-weight:400; cursor:pointer; }
        .radio-group input[type="radio"] { width:auto; padding:0; }
        button { display:block; width:100%; padding:16px; margin-top:40px; background-color:#052228; color:white; border:none; border-radius:4px; font-size:16px; font-weight:700; cursor:pointer; font-family:'Open Sans',sans-serif; text-transform:uppercase; letter-spacing:1px; }
        button:hover { background-color:#173d46; }
        .obrigat { color:#d7ab90; }
        .error-msg { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; padding:15px; border-radius:4px; margin-bottom:20px; text-align:center; }
        .success-box { text-align:center; padding:40px 20px; }
        .success-box .check { width:80px; height:80px; background:#d4edda; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; font-size:2.5rem; margin-bottom:20px; }
        .success-box h2 { color:#052228; font-size:24px; margin-bottom:10px; }
        .success-box p { color:#5d5c62; font-size:16px; }
        .success-box .protocol { background:#f0f4f5; padding:10px 20px; border-radius:8px; display:inline-block; font-size:18px; font-weight:700; color:#052228; margin-top:15px; }
        @media (max-width:600px) { .container { padding:25px; } }
    </style>
</head>
<body>
    <div class="container">
        <div class="logo-container">
            <img src="<?= url('assets/img/logo.png') ?>" alt="Ferreira e Sá Advocacia" onerror="this.style.display='none'; document.querySelector('.logo-fallback').style.display='block';">
            <div class="logo-fallback">FERREIRA & SÁ</div>
        </div>

        <?php if ($success): ?>
        <div class="success-box">
            <div class="check">✓</div>
            <h2>Cadastro enviado com sucesso!</h2>
            <p>Seus dados foram recebidos pela equipe do escritório.</p>
            <div class="protocol"><?= e($protocol) ?></div>
            <p style="margin-top:20px;font-size:14px;color:#999;">Guarde este protocolo para referência.</p>

            <div style="display:flex;gap:12px;justify-content:center;margin-top:30px;flex-wrap:wrap;">
                <a href="https://wa.me/5524992050096?text=<?= urlencode('Olá! Acabei de preencher o cadastro no site. Meu protocolo é ' . $protocol . '. Aguardo retorno!') ?>" target="_blank" style="display:inline-flex;align-items:center;gap:8px;padding:14px 24px;background:#25D366;color:#fff;border-radius:8px;font-size:15px;font-weight:700;font-family:'Open Sans',sans-serif;text-decoration:none;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="#fff"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                    Falar no WhatsApp
                </a>
                <a href="https://www.instagram.com/advocaciaferreiraesa" target="_blank" style="display:inline-flex;align-items:center;gap:8px;padding:14px 24px;background:linear-gradient(45deg,#f09433,#e6683c,#dc2743,#cc2366,#bc1888);color:#fff;border-radius:8px;font-size:15px;font-weight:700;font-family:'Open Sans',sans-serif;text-decoration:none;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="#fff"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"/></svg>
                    Siga no Instagram
                </a>
            </div>
        </div>
        <?php else: ?>

        <p class="subtitle">Formulário para cadastro no sistema de informações do escritório.</p>

        <?php if ($error): ?>
            <div class="error-msg"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="POST" id="clientForm">

            <div class="section-title">Dados Pessoais</div>

            <label>CPF <span class="obrigat">*</span></label>
            <div style="position:relative;">
                <input type="text" name="cpf" id="cpfInput" placeholder="000.000.000-00" required value="<?= e($_POST['cpf'] ?? '') ?>" maxlength="14" autocomplete="off">
                <span id="cpfLoading" style="display:none;position:absolute;right:12px;top:50%;transform:translateY(-50%);font-size:12px;color:var(--cobre);">Consultando...</span>
                <span id="cpfOk" style="display:none;position:absolute;right:12px;top:50%;transform:translateY(-50%);font-size:12px;color:#2D7A4F;font-weight:600;">✓ Dados preenchidos</span>
            </div>

            <label>Nome Completo <span class="obrigat">*</span></label>
            <input type="text" name="nome" id="nomeInput" required value="<?= e($_POST['nome'] ?? '') ?>">

            <label>Data de Nascimento <span class="obrigat">*</span></label>
            <input type="date" name="nascimento" id="nascimentoInput" value="<?= e($_POST['nascimento'] ?? '') ?>" required>

            <label>Profissão (se desempregado, também deve informar)</label>
            <input type="text" name="profissao" value="<?= e($_POST['profissao'] ?? '') ?>">

            <label>Estado Civil <span class="obrigat">*</span></label>
            <select name="estado_civil" required>
                <option value="">Selecione...</option>
                <option value="Solteiro" <?= ($_POST['estado_civil'] ?? '') === 'Solteiro' ? 'selected' : '' ?>>Solteiro(a)</option>
                <option value="Casado" <?= ($_POST['estado_civil'] ?? '') === 'Casado' ? 'selected' : '' ?>>Casado(a)</option>
                <option value="Divorciado" <?= ($_POST['estado_civil'] ?? '') === 'Divorciado' ? 'selected' : '' ?>>Divorciado(a)</option>
                <option value="Viúvo" <?= ($_POST['estado_civil'] ?? '') === 'Viúvo' ? 'selected' : '' ?>>Viúvo(a)</option>
                <option value="União Estável" <?= ($_POST['estado_civil'] ?? '') === 'União Estável' ? 'selected' : '' ?>>União Estável</option>
            </select>

            <label>RG</label>
            <input type="text" name="rg" value="<?= e($_POST['rg'] ?? '') ?>">

            <div class="section-title">Contato e Endereço</div>

            <label>Celular (WhatsApp) <span class="obrigat">*</span></label>
            <input type="text" name="celular" placeholder="(00) 00000-0000" required value="<?= e($_POST['celular'] ?? '') ?>" maxlength="15">

            <label>E-mail <span class="obrigat">*</span></label>
            <input type="email" name="email" required value="<?= e($_POST['email'] ?? '') ?>">

            <label>CEP <span class="obrigat">*</span></label>
            <input type="text" name="cep" id="cep" placeholder="00000-000" required value="<?= e($_POST['cep'] ?? '') ?>" maxlength="9">
            <span id="cepStatus" style="font-size:12px;color:#059669;display:none;">✓ Endereço encontrado!</span>

            <label>Rua / Logradouro</label>
            <input type="text" name="rua" id="rua" value="<?= e($_POST['rua'] ?? '') ?>" placeholder="Preenchido automaticamente pelo CEP">

            <label>Número <span class="obrigat">*</span></label>
            <input type="text" name="numero" id="numero" value="<?= e($_POST['numero'] ?? '') ?>" placeholder="Ex: 123" required>

            <label>Complemento</label>
            <input type="text" name="complemento" id="complemento" value="<?= e($_POST['complemento'] ?? '') ?>" placeholder="Apto, Bloco, Sala...">

            <label>Bairro</label>
            <input type="text" name="bairro" id="bairro" value="<?= e($_POST['bairro'] ?? '') ?>" placeholder="Preenchido automaticamente pelo CEP">

            <label>Cidade</label>
            <input type="text" name="cidade" id="cidade" value="<?= e($_POST['cidade'] ?? '') ?>" placeholder="Preenchido automaticamente pelo CEP">

            <label>UF</label>
            <input type="text" name="uf" id="uf" value="<?= e($_POST['uf'] ?? '') ?>" maxlength="2" placeholder="Ex: RJ">

            <input type="hidden" name="endereco" id="enderecoCompleto" value="<?= e($_POST['endereco'] ?? '') ?>">

            <div class="section-title">Dados Bancários</div>

            <label>Chave PIX (se não tiver, escrever 'não possuo') <span class="obrigat">*</span></label>
            <input type="text" name="pix" required value="<?= e($_POST['pix'] ?? '') ?>">

            <label>Conta para depósito (Agência, Conta e Banco)</label>
            <input type="text" name="conta_bancaria" placeholder="Se for pensão alimentícia" value="<?= e($_POST['conta_bancaria'] ?? '') ?>">

            <label>Declara Imposto de Renda? <span class="obrigat">*</span></label>
            <div class="radio-group">
                <label><input type="radio" name="imposto_renda" value="Sim" required <?= ($_POST['imposto_renda'] ?? '') === 'Sim' ? 'checked' : '' ?>> Sim</label>
                <label><input type="radio" name="imposto_renda" value="Não" <?= ($_POST['imposto_renda'] ?? '') === 'Não' ? 'checked' : '' ?>> Não</label>
            </div>

            <label>Trabalha de Carteira Assinada? <span class="obrigat">*</span></label>
            <div class="radio-group">
                <label><input type="radio" name="clt" value="Sim" required <?= ($_POST['clt'] ?? '') === 'Sim' ? 'checked' : '' ?>> Sim</label>
                <label><input type="radio" name="clt" value="Não" <?= ($_POST['clt'] ?? '') === 'Não' ? 'checked' : '' ?>> Não</label>
            </div>

            <div class="section-title">Dados Familiares</div>

            <label>Possui filhos? <span class="obrigat">*</span></label>
            <div class="radio-group">
                <label><input type="radio" name="filhos" value="Sim" required <?= ($_POST['filhos'] ?? '') === 'Sim' ? 'checked' : '' ?>> Sim</label>
                <label><input type="radio" name="filhos" value="Não" <?= ($_POST['filhos'] ?? '') === 'Não' ? 'checked' : '' ?>> Não</label>
            </div>

            <label>Nome(s) completo(s) do(s) filho(s)</label>
            <textarea name="nome_filhos"><?= e($_POST['nome_filhos'] ?? '') ?></textarea>

            <div class="section-title">Preferências de Atendimento</div>

            <label>Prefere atendimento Presencial ou Remoto? <span class="obrigat">*</span></label>
            <select name="tipo_atendimento" required>
                <option value="Remoto">Remoto (Internet)</option>
                <option value="Presencial" <?= ($_POST['tipo_atendimento'] ?? '') === 'Presencial' ? 'selected' : '' ?>>Presencial</option>
            </select>

            <label>Autoriza contato? <span class="obrigat">*</span></label>
            <select name="autoriza_contato" required>
                <option value="Sim">Sim</option>
                <option value="Apenas andamento" <?= ($_POST['autoriza_contato'] ?? '') === 'Apenas andamento' ? 'selected' : '' ?>>Apenas andamento do processo</option>
                <option value="Não" <?= ($_POST['autoriza_contato'] ?? '') === 'Não' ? 'selected' : '' ?>>Não</option>
            </select>

            <div class="section-title">Específico: Direito de Família</div>
            <p style="font-size:13px;color:#5d5c62;margin-top:-15px;">(Preencher apenas se o seu caso for sobre Pensão, Guarda, Divórcio etc)</p>

            <label>Filho(a) faz tratamento de saúde? Qual?</label>
            <input type="text" name="fam_saude" value="<?= e($_POST['fam_saude'] ?? '') ?>">

            <label>Filho(a) estuda em escola pública ou particular?</label>
            <select name="fam_escola">
                <option value="">Selecione...</option>
                <option value="Pública" <?= ($_POST['fam_escola'] ?? '') === 'Pública' ? 'selected' : '' ?>>Pública</option>
                <option value="Particular" <?= ($_POST['fam_escola'] ?? '') === 'Particular' ? 'selected' : '' ?>>Particular</option>
                <option value="Não estuda" <?= ($_POST['fam_escola'] ?? '') === 'Não estuda' ? 'selected' : '' ?>>Não estuda</option>
            </select>

            <label>O outro genitor paga pensão? Qual valor?</label>
            <input type="text" name="fam_pensao_atual" value="<?= e($_POST['fam_pensao_atual'] ?? '') ?>">

            <label>O outro genitor trabalha? (Empresa/Cargo)</label>
            <input type="text" name="fam_trabalho_genitor" value="<?= e($_POST['fam_trabalho_genitor'] ?? '') ?>">

            <label>Celular/WhatsApp do outro Genitor</label>
            <input type="text" name="fam_contato_genitor" value="<?= e($_POST['fam_contato_genitor'] ?? '') ?>">

            <label>Endereço completo do outro Genitor</label>
            <textarea name="fam_endereco_genitor"><?= e($_POST['fam_endereco_genitor'] ?? '') ?></textarea>

            <button type="submit">ENVIAR DADOS</button>
        </form>

        <script>
        function mask(el, pattern) {
            var v = el.value.replace(/\D/g, '');
            var r = '';
            var vi = 0;
            for (var i = 0; i < pattern.length && vi < v.length; i++) {
                if (pattern[i] === '9') {
                    r += v[vi]; vi++;
                } else {
                    r += pattern[i];
                }
            }
            el.value = r;
        }

        // CPF: 000.000.000-00
        document.querySelector('input[name="cpf"]').addEventListener('input', function() {
            mask(this, '999.999.999-99');
        });

        // Celular: (00) 00000-0000
        document.querySelector('input[name="celular"]').addEventListener('input', function() {
            var v = this.value.replace(/\D/g, '');
            if (v.length <= 10) {
                mask(this, '(99) 9999-9999');
            } else {
                mask(this, '(99) 99999-9999');
            }
        });

        // CEP: 00000-000 + busca automática via ViaCEP
        document.querySelector('input[name="cep"]').addEventListener('input', function() {
            mask(this, '99999-999');
            var cep = this.value.replace(/\D/g, '');
            if (cep.length === 8) {
                buscarCEP(cep);
            }
        });

        function buscarCEP(cep) {
            var status = document.getElementById('cepStatus');
            status.style.display = 'none';

            var xhr = new XMLHttpRequest();
            xhr.open('GET', 'https://viacep.com.br/ws/' + cep + '/json/');
            xhr.onload = function() {
                if (xhr.status === 200) {
                    try {
                        var data = JSON.parse(xhr.responseText);
                        if (!data.erro) {
                            document.getElementById('rua').value = data.logradouro || '';
                            document.getElementById('bairro').value = data.bairro || '';
                            document.getElementById('cidade').value = data.localidade || '';
                            document.getElementById('uf').value = data.uf || '';
                            status.style.display = 'inline';
                            // Focar no número
                            document.getElementById('numero').focus();
                        }
                    } catch(e) {}
                }
            };
            xhr.send();
        }

        // RG: formata com pontos (00.000.000-0)
        document.querySelector('input[name="rg"]').addEventListener('input', function() {
            var v = this.value.replace(/\D/g, '');
            if (v.length <= 9) {
                mask(this, '99.999.999-9');
            }
        });

        // Montar endereço completo antes de enviar
        document.getElementById('clientForm').addEventListener('submit', function() {
            var rua = document.getElementById('rua').value;
            var numero = document.getElementById('numero').value;
            var complemento = document.getElementById('complemento').value;
            var bairro = document.getElementById('bairro').value;
            var cidade = document.getElementById('cidade').value;
            var uf = document.getElementById('uf').value;
            var partes = [];
            if (rua) partes.push(rua);
            if (numero) partes.push('nº ' + numero);
            if (complemento) partes.push(complemento);
            if (bairro) partes.push(bairro);
            if (cidade && uf) partes.push(cidade + '/' + uf);
            else if (cidade) partes.push(cidade);
            document.getElementById('enderecoCompleto').value = partes.join(', ');
        });
        </script>
        <?php endif; ?>
    </div>

<script>
// Máscara de CPF
var cpfInput = document.getElementById('cpfInput');
if (cpfInput) {
    cpfInput.addEventListener('input', function() {
        var v = this.value.replace(/\D/g, '');
        if (v.length > 11) v = v.substr(0, 11);
        if (v.length > 9) v = v.replace(/(\d{3})(\d{3})(\d{3})(\d{1,2})/, '$1.$2.$3-$4');
        else if (v.length > 6) v = v.replace(/(\d{3})(\d{3})(\d{1,3})/, '$1.$2.$3');
        else if (v.length > 3) v = v.replace(/(\d{3})(\d{1,3})/, '$1.$2');
        this.value = v;

        // Consultar quando completar 14 chars (000.000.000-00)
        if (v.length === 14) consultarCPF(v);
    });

    cpfInput.addEventListener('blur', function() {
        if (this.value.length === 14) consultarCPF(this.value);
    });
}

function consultarCPF(cpfFormatado) {
    var cpfLimpo = cpfFormatado.replace(/\D/g, '');
    if (cpfLimpo.length !== 11) return;

    var loading = document.getElementById('cpfLoading');
    var ok = document.getElementById('cpfOk');
    loading.style.display = 'inline'; ok.style.display = 'none';

    var xhr = new XMLHttpRequest();
    xhr.open('GET', '/conecta/publico/api_cpf.php?cpf=' + cpfLimpo, true);
    xhr.timeout = 10000;
    xhr.onload = function() {
        loading.style.display = 'none';
        try {
            var data = JSON.parse(xhr.responseText);
            if (data.status === 'ERROR' || !data.nome) return;

            var nomeInput = document.getElementById('nomeInput');
            var nascInput = document.getElementById('nascimentoInput');

            if (data.nome && (!nomeInput.value || nomeInput.value === '')) {
                nomeInput.value = data.nome;
            }
            if (data.nascimento && !nascInput.value) {
                // Formato da API: dd/mm/yyyy -> yyyy-mm-dd
                var parts = data.nascimento.split('/');
                if (parts.length === 3) nascInput.value = parts[2] + '-' + parts[1] + '-' + parts[0];
            }
            ok.style.display = 'inline';
            setTimeout(function() { ok.style.display = 'none'; }, 3000);
        } catch(e) {}
    };
    xhr.onerror = function() { loading.style.display = 'none'; };
    xhr.ontimeout = function() { loading.style.display = 'none'; };
    xhr.send();
}
</script>
</body>
</html>
