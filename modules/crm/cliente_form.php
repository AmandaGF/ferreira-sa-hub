<?php
/**
 * Ferreira & Sá Hub — Formulário de Cliente (Criar/Editar)
 */

require_once __DIR__ . '/../../core/middleware.php';
require_access('crm');

$pdo = db();
$errors = [];
$client = null;

$editId = (int)($_GET['id'] ?? 0);
if ($editId) {
    $stmt = $pdo->prepare('SELECT * FROM clients WHERE id = ?');
    $stmt->execute([$editId]);
    $client = $stmt->fetch();
    if (!$client) {
        flash_set('error', 'Cliente não encontrado.');
        redirect(module_url('crm'));
    }
    $pageTitle = 'Editar Cliente';
} else {
    $pageTitle = 'Novo Cliente';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) { $errors[] = 'Token inválido.'; }

    $f = [
        'name'           => clean_str($_POST['name'] ?? '', 150),
        'cpf'            => clean_str($_POST['cpf'] ?? '', 18),
        'rg'             => clean_str($_POST['rg'] ?? '', 20),
        'birth_date'     => $_POST['birth_date'] ?? null,
        'email'          => trim($_POST['email'] ?? ''),
        'phone'          => clean_str($_POST['phone'] ?? '', 40),
        'phone2'         => clean_str($_POST['phone2'] ?? '', 40),
        'address_street' => clean_str($_POST['address_street'] ?? '', 255),
        'address_city'   => clean_str($_POST['address_city'] ?? '', 100),
        'address_state'  => clean_str($_POST['address_state'] ?? '', 2),
        'address_zip'    => clean_str($_POST['address_zip'] ?? '', 10),
        'profession'     => clean_str($_POST['profession'] ?? '', 100),
        'marital_status' => clean_str($_POST['marital_status'] ?? '', 30),
        'gender'         => clean_str($_POST['gender'] ?? '', 20),
        'has_children'   => isset($_POST['has_children']) ? (int)$_POST['has_children'] : null,
        'children_names' => clean_str($_POST['children_names'] ?? '', 500),
        'pix_key'        => clean_str($_POST['pix_key'] ?? '', 100),
        'nacionalidade'  => clean_str($_POST['nacionalidade'] ?? '', 50),
        'source'         => $_POST['source'] ?? 'outro',
        'notes'          => clean_str($_POST['notes'] ?? '', 2000),
    ];

    if (empty($f['name'])) $errors[] = 'Nome é obrigatório.';
    if ($f['birth_date'] === '') $f['birth_date'] = null;

    // CPF duplicado
    if (!empty($f['cpf']) && empty($errors)) {
        $stmt = $pdo->prepare('SELECT id FROM clients WHERE cpf = ? AND id != ?');
        $stmt->execute([$f['cpf'], $editId]);
        if ($stmt->fetch()) $errors[] = 'CPF já cadastrado.';
    }

    if (empty($errors)) {
        if ($editId) {
            $pdo->prepare(
                'UPDATE clients SET name=?, cpf=?, rg=?, birth_date=?, email=?, phone=?, phone2=?,
                 address_street=?, address_city=?, address_state=?, address_zip=?,
                 profession=?, marital_status=?, gender=?, has_children=?, children_names=?,
                 pix_key=?, nacionalidade=?, source=?, notes=?, updated_at=NOW() WHERE id=?'
            )->execute([
                $f['name'], $f['cpf'] ?: null, $f['rg'] ?: null, $f['birth_date'],
                $f['email'] ?: null, $f['phone'] ?: null, $f['phone2'] ?: null,
                $f['address_street'] ?: null, $f['address_city'] ?: null,
                $f['address_state'] ?: null, $f['address_zip'] ?: null,
                $f['profession'] ?: null, $f['marital_status'] ?: null,
                $f['gender'] ?: null, $f['has_children'], $f['children_names'] ?: null,
                $f['pix_key'] ?: null, $f['nacionalidade'] ?: null,
                $f['source'], $f['notes'] ?: null, $editId
            ]);
            audit_log('client_updated', 'client', $editId);
            flash_set('success', 'Cliente atualizado.');
        } else {
            $pdo->prepare(
                'INSERT INTO clients (name, cpf, rg, birth_date, email, phone, phone2,
                 address_street, address_city, address_state, address_zip,
                 profession, marital_status, gender, has_children, children_names,
                 pix_key, nacionalidade, source, notes, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $f['name'], $f['cpf'] ?: null, $f['rg'] ?: null, $f['birth_date'],
                $f['email'] ?: null, $f['phone'] ?: null, $f['phone2'] ?: null,
                $f['address_street'] ?: null, $f['address_city'] ?: null,
                $f['address_state'] ?: null, $f['address_zip'] ?: null,
                $f['profession'] ?: null, $f['marital_status'] ?: null,
                $f['gender'] ?: null, $f['has_children'], $f['children_names'] ?: null,
                $f['pix_key'] ?: null, $f['nacionalidade'] ?: null,
                $f['source'], $f['notes'] ?: null, current_user_id()
            ]);
            $newId = (int)$pdo->lastInsertId();
            audit_log('client_created', 'client', $newId);
            flash_set('success', 'Cliente cadastrado.');
            redirect(module_url('crm', 'cliente_ver.php?id=' . $newId));
        }
        redirect(module_url('crm', 'cliente_ver.php?id=' . $editId));
    }
} else {
    $f = [
        'name'           => $client['name'] ?? '',
        'cpf'            => $client['cpf'] ?? '',
        'rg'             => $client['rg'] ?? '',
        'birth_date'     => $client['birth_date'] ?? '',
        'email'          => $client['email'] ?? '',
        'phone'          => $client['phone'] ?? '',
        'phone2'         => $client['phone2'] ?? '',
        'address_street' => $client['address_street'] ?? '',
        'address_city'   => $client['address_city'] ?? '',
        'address_state'  => $client['address_state'] ?? '',
        'address_zip'    => $client['address_zip'] ?? '',
        'profession'     => $client['profession'] ?? '',
        'marital_status' => $client['marital_status'] ?? '',
        'gender'         => $client['gender'] ?? '',
        'has_children'   => $client['has_children'] ?? null,
        'children_names' => $client['children_names'] ?? '',
        'pix_key'        => $client['pix_key'] ?? '',
        'nacionalidade'  => $client['nacionalidade'] ?? '',
        'source'         => $client['source'] ?? 'outro',
        'notes'          => $client['notes'] ?? '',
    ];
}

require_once APP_ROOT . '/templates/layout_start.php';
?>

<div style="max-width: 720px;">
    <a href="<?= module_url('crm') ?>" class="btn btn-outline btn-sm mb-2">← Voltar</a>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
            <span class="alert-icon">✕</span>
            <div><?= implode('<br>', array_map('e', $errors)) ?></div>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body">
            <form method="POST">
                <?= csrf_input() ?>

                <div class="form-group">
                    <label class="form-label">Nome completo *</label>
                    <input type="text" name="name" class="form-input" value="<?= e($f['name']) ?>" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">CPF / CNPJ</label>
                        <input type="text" name="cpf" class="form-input" value="<?= e($f['cpf']) ?>" placeholder="000.000.000-00" maxlength="18" data-busca-doc data-nome="[name=name]" data-nascimento="[name=birth_date]" data-email="[name=email]" data-telefone="[name=phone]" data-endereco="[name=address_street]" data-cidade="[name=address_city]" data-uf="[name=address_state]" data-cep="[name=address_zip]" data-rg="[name=rg]" data-profissao="[name=profession]" data-estado-civil="[name=marital_status]">
                    </div>
                    <div class="form-group">
                        <label class="form-label">RG</label>
                        <input type="text" name="rg" class="form-input" value="<?= e($f['rg']) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Data de nascimento</label>
                        <input type="date" name="birth_date" class="form-input" value="<?= e($f['birth_date']) ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Telefone / WhatsApp</label>
                        <input type="text" name="phone" class="form-input" value="<?= e($f['phone']) ?>" placeholder="(00) 00000-0000">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Telefone 2</label>
                        <input type="text" name="phone2" class="form-input" value="<?= e($f['phone2']) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">E-mail</label>
                        <input type="email" name="email" class="form-input" value="<?= e($f['email']) ?>">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">CEP</label>
                        <input type="text" name="address_zip" id="cepInput" class="form-input" value="<?= e($f['address_zip']) ?>" placeholder="00000-000" maxlength="9" oninput="formatarCEP(this)" onblur="buscarCEP(this,{endereco:'[name=address_street]',cidade:'[name=address_city]',uf:'[name=address_state]'})">
                        <span class="cep-loading" style="display:none;font-size:.7rem;color:var(--text-muted);">Buscando...</span>
                    </div>
                    <div class="form-group" style="grid-column: span 2;">
                        <label class="form-label">Endereço</label>
                        <input type="text" name="address_street" class="form-input" value="<?= e($f['address_street']) ?>" placeholder="Rua, número, complemento">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Cidade</label>
                        <input type="text" name="address_city" class="form-input" value="<?= e($f['address_city']) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">UF</label>
                        <select name="address_state" class="form-select">
                            <option value="">—</option>
                            <?php foreach (['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'] as $uf): ?>
                                <option value="<?= $uf ?>" <?= $f['address_state'] === $uf ? 'selected' : '' ?>><?= $uf ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Profissão</label>
                        <input type="text" name="profession" class="form-input" value="<?= e($f['profession']) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Estado civil</label>
                        <select name="marital_status" class="form-select">
                            <option value="">—</option>
                            <option value="solteiro" <?= $f['marital_status'] === 'solteiro' ? 'selected' : '' ?>>Solteiro(a)</option>
                            <option value="casado" <?= $f['marital_status'] === 'casado' ? 'selected' : '' ?>>Casado(a)</option>
                            <option value="divorciado" <?= $f['marital_status'] === 'divorciado' ? 'selected' : '' ?>>Divorciado(a)</option>
                            <option value="viuvo" <?= $f['marital_status'] === 'viuvo' ? 'selected' : '' ?>>Viúvo(a)</option>
                            <option value="uniao_estavel" <?= $f['marital_status'] === 'uniao_estavel' ? 'selected' : '' ?>>União estável</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Origem</label>
                        <select name="source" class="form-select">
                            <option value="outro" <?= $f['source'] === 'outro' ? 'selected' : '' ?>>Outro</option>
                            <option value="indicacao" <?= $f['source'] === 'indicacao' ? 'selected' : '' ?>>Indicação</option>
                            <option value="landing" <?= $f['source'] === 'landing' ? 'selected' : '' ?>>Site/Landing</option>
                            <option value="calculadora" <?= $f['source'] === 'calculadora' ? 'selected' : '' ?>>Calculadora</option>
                            <option value="presencial" <?= $f['source'] === 'presencial' ? 'selected' : '' ?>>Presencial</option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Sexo</label>
                        <select name="gender" class="form-select">
                            <option value="">—</option>
                            <option value="masculino" <?= $f['gender'] === 'masculino' ? 'selected' : '' ?>>Masculino</option>
                            <option value="feminino" <?= $f['gender'] === 'feminino' ? 'selected' : '' ?>>Feminino</option>
                            <option value="outro" <?= $f['gender'] === 'outro' ? 'selected' : '' ?>>Outro</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Nacionalidade</label>
                        <input type="text" name="nacionalidade" class="form-input" value="<?= e($f['nacionalidade']) ?>" placeholder="Brasileiro(a)">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Chave PIX</label>
                        <input type="text" name="pix_key" class="form-input" value="<?= e($f['pix_key']) ?>" placeholder="CPF, e-mail, telefone ou chave aleatória">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Tem filhos?</label>
                        <select name="has_children" class="form-select">
                            <option value="">—</option>
                            <option value="1" <?= $f['has_children'] === 1 || $f['has_children'] === '1' ? 'selected' : '' ?>>Sim</option>
                            <option value="0" <?= $f['has_children'] === 0 || $f['has_children'] === '0' ? 'selected' : '' ?>>Não</option>
                        </select>
                    </div>
                    <div class="form-group" style="grid-column: span 2;">
                        <label class="form-label">Nome(s) dos filhos</label>
                        <input type="text" name="children_names" class="form-input" value="<?= e($f['children_names']) ?>" placeholder="Ex: João (5 anos), Maria (3 anos)">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Observações</label>
                    <textarea name="notes" class="form-textarea" rows="3"><?= e($f['notes']) ?></textarea>
                </div>

                <div class="card-footer" style="border-top:none;padding:1rem 0 0;">
                    <a href="<?= module_url('crm') ?>" class="btn btn-outline">Cancelar</a>
                    <button type="submit" class="btn btn-primary"><?= $editId ? 'Salvar' : 'Cadastrar Cliente' ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// ══ Máscara de CPF ══
var cpfField = document.querySelector('[name=cpf]');
if (cpfField) {
    cpfField.addEventListener('input', function() {
        var v = this.value.replace(/\D/g, '');
        if (v.length > 14) v = v.substr(0, 14);
        if (v.length <= 11) {
            if (v.length > 9) v = v.replace(/(\d{3})(\d{3})(\d{3})(\d{1,2})/, '$1.$2.$3-$4');
            else if (v.length > 6) v = v.replace(/(\d{3})(\d{3})(\d{1,3})/, '$1.$2.$3');
            else if (v.length > 3) v = v.replace(/(\d{3})(\d{1,3})/, '$1.$2');
        } else {
            v = v.replace(/(\d{2})(\d{3})(\d{3})(\d{4})(\d{1,2})/, '$1.$2.$3/$4-$5');
        }
        this.value = v;
    });
}

// ══ Máscara de Telefone ══
document.querySelectorAll('[name=phone],[name=phone2]').forEach(function(el) {
    el.addEventListener('input', function() {
        var v = this.value.replace(/\D/g, '');
        if (v.length > 11) v = v.substr(0, 11);
        if (v.length > 10) v = v.replace(/(\d{2})(\d{5})(\d{4})/, '($1) $2-$3');
        else if (v.length > 6) v = v.replace(/(\d{2})(\d{4})(\d{1,4})/, '($1) $2-$3');
        else if (v.length > 2) v = v.replace(/(\d{2})(\d{1,5})/, '($1) $2');
        this.value = v;
    });
});

// ══ Busca CEP via ViaCEP ══
function formatarCEP(el) {
    var v = el.value.replace(/\D/g, '');
    if (v.length > 5) v = v.substr(0,5) + '-' + v.substr(5,3);
    el.value = v;
}
function buscarCEP(el, targets) {
    var cep = el.value.replace(/\D/g, '');
    if (cep.length !== 8) return;
    var loading = el.parentElement.querySelector('.cep-loading');
    if (loading) loading.style.display = 'inline';
    var xhr = new XMLHttpRequest();
    xhr.open('GET', 'https://viacep.com.br/ws/' + cep + '/json/', true);
    xhr.onload = function() {
        if (loading) loading.style.display = 'none';
        if (xhr.status === 200) {
            try {
                var d = JSON.parse(xhr.responseText);
                if (!d.erro) {
                    if (targets.endereco) {
                        var f = document.querySelector(targets.endereco);
                        if (f && !f.value) f.value = d.logradouro || '';
                    }
                    if (targets.cidade) {
                        var f = document.querySelector(targets.cidade);
                        if (f) f.value = d.localidade || '';
                    }
                    if (targets.uf) {
                        var f = document.querySelector(targets.uf);
                        if (f) f.value = d.uf || '';
                    }
                }
            } catch(e) {}
        }
    };
    xhr.onerror = function() { if (loading) loading.style.display = 'none'; };
    xhr.send();
}

// ══ Auto-busca CEP ao digitar ══
var cepInput = document.getElementById('cepInput');
if (cepInput) {
    cepInput.addEventListener('input', function() {
        formatarCEP(this);
        if (this.value.replace(/\D/g, '').length === 8) {
            buscarCEP(this, {
                endereco: '[name=address_street]',
                cidade: '[name=address_city]',
                uf: '[name=address_state]'
            });
        }
    });
}

// ══ Busca nome por CPF (consulta base interna) ══
if (cpfField) {
    var _cpfTimer = null;
    cpfField.addEventListener('input', function() {
        clearTimeout(_cpfTimer);
        var cpf = this.value.replace(/\D/g, '');
        if (cpf.length === 11) {
            _cpfTimer = setTimeout(function() { consultarCPFInterno(cpf); }, 500);
        }
    });
}
function consultarCPFInterno(cpf) {
    var xhr = new XMLHttpRequest();
    xhr.open('GET', '<?= url("publico/api_cpf.php") ?>?cpf=' + cpf, true);
    xhr.timeout = 8000;
    xhr.onload = function() {
        try {
            var d = JSON.parse(xhr.responseText);
            if (!d || d.status === 'ERROR') return;

            // Helper: preenche campo se estiver vazio
            function fill(sel, val) {
                if (!val) return;
                var el = document.querySelector(sel);
                if (el && !el.value) el.value = val;
            }

            fill('[name=name]', d.nome);
            fill('[name=rg]', d.rg);
            fill('[name=email]', d.email);
            fill('[name=phone]', d.telefone);
            fill('[name=phone2]', d.telefone2);
            fill('[name=profession]', d.profissao);
            fill('[name=address_street]', d.endereco);
            fill('[name=address_city]', d.cidade);
            fill('[name=address_state]', d.uf);
            fill('[name=address_zip]', d.cep);
            fill('[name=pix_key]', d.pix);
            fill('[name=children_names]', d.filhos);

            // Nascimento: converter dd/mm/yyyy ou yyyy-mm-dd
            if (d.nascimento) {
                var b = document.querySelector('[name=birth_date]');
                if (b && !b.value) {
                    var p = d.nascimento.split('/');
                    if (p.length === 3) {
                        b.value = p[2] + '-' + p[1] + '-' + p[0];
                    } else if (d.nascimento.indexOf('-') !== -1) {
                        b.value = d.nascimento;
                    }
                }
            }

            // Selects: estado civil, sexo, nacionalidade
            function fillSelect(sel, val) {
                if (!val) return;
                var el = document.querySelector(sel);
                if (!el || el.value) return;
                var valLow = val.toLowerCase();
                for (var i = 0; i < el.options.length; i++) {
                    if (el.options[i].value.toLowerCase() === valLow || el.options[i].text.toLowerCase() === valLow) {
                        el.selectedIndex = i; return;
                    }
                }
                // Se não encontrou opção exata, preenche se for input
                if (el.tagName === 'INPUT') el.value = val;
            }
            fillSelect('[name=marital_status]', d.estado_civil);
            fillSelect('[name=gender]', d.genero);
            fill('[name=nacionalidade]', d.nacionalidade);

            // Feedback visual
            if (d.nome) {
                cpfField.style.borderColor = '#059669';
                setTimeout(function() { cpfField.style.borderColor = ''; }, 2000);
            }
        } catch(e) {}
    };
    xhr.send();
}
</script>
<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
