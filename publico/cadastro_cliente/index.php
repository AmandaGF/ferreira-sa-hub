<?php
/**
 * Formulário de Cadastro de Clientes — versão PHP (grava direto no TurboCloud)
 * Substitui a versão Firebase
 */

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/database.php';
require_once __DIR__ . '/../../core/functions.php';
require_once __DIR__ . '/../../core/form_handler.php';

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
                'address_zip' => $cep,
            ),
            json_encode($payload, JSON_UNESCAPED_UNICODE)
        );

        $protocol = $result['protocol'];
        $success = true;
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
        </div>
        <?php else: ?>

        <p class="subtitle">Formulário para cadastro no sistema de informações do escritório.</p>

        <?php if ($error): ?>
            <div class="error-msg"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="POST" id="clientForm">

            <div class="section-title">Dados Pessoais</div>

            <label>Nome Completo <span class="obrigat">*</span></label>
            <input type="text" name="nome" required value="<?= e($_POST['nome'] ?? '') ?>">

            <label>CPF <span class="obrigat">*</span></label>
            <input type="text" name="cpf" placeholder="Apenas números" required value="<?= e($_POST['cpf'] ?? '') ?>">

            <label>Data de Nascimento</label>
            <input type="date" name="nascimento" value="<?= e($_POST['nascimento'] ?? '') ?>">

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
            <input type="text" name="celular" required value="<?= e($_POST['celular'] ?? '') ?>">

            <label>E-mail <span class="obrigat">*</span></label>
            <input type="email" name="email" required value="<?= e($_POST['email'] ?? '') ?>">

            <label>CEP <span class="obrigat">*</span></label>
            <input type="text" name="cep" required value="<?= e($_POST['cep'] ?? '') ?>">

            <label>Endereço Completo (Rua, número, Bairro e Cidade) <span class="obrigat">*</span></label>
            <textarea name="endereco" required><?= e($_POST['endereco'] ?? '') ?></textarea>

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
        <?php endif; ?>
    </div>
</body>
</html>
