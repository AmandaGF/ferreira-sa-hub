<?php
/**
 * Ferreira & Sá Hub — Formulário de Cliente (Criar/Editar)
 */

require_once __DIR__ . '/../../core/middleware.php';
require_min_role('gestao');

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
        'cpf'            => clean_str($_POST['cpf'] ?? '', 14),
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
                 profession=?, marital_status=?, source=?, notes=?, updated_at=NOW() WHERE id=?'
            )->execute([
                $f['name'], $f['cpf'] ?: null, $f['rg'] ?: null, $f['birth_date'],
                $f['email'] ?: null, $f['phone'] ?: null, $f['phone2'] ?: null,
                $f['address_street'] ?: null, $f['address_city'] ?: null,
                $f['address_state'] ?: null, $f['address_zip'] ?: null,
                $f['profession'] ?: null, $f['marital_status'] ?: null,
                $f['source'], $f['notes'] ?: null, $editId
            ]);
            audit_log('client_updated', 'client', $editId);
            flash_set('success', 'Cliente atualizado.');
        } else {
            $pdo->prepare(
                'INSERT INTO clients (name, cpf, rg, birth_date, email, phone, phone2,
                 address_street, address_city, address_state, address_zip,
                 profession, marital_status, source, notes, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $f['name'], $f['cpf'] ?: null, $f['rg'] ?: null, $f['birth_date'],
                $f['email'] ?: null, $f['phone'] ?: null, $f['phone2'] ?: null,
                $f['address_street'] ?: null, $f['address_city'] ?: null,
                $f['address_state'] ?: null, $f['address_zip'] ?: null,
                $f['profession'] ?: null, $f['marital_status'] ?: null,
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
                        <label class="form-label">CPF</label>
                        <input type="text" name="cpf" class="form-input" value="<?= e($f['cpf']) ?>" placeholder="000.000.000-00" maxlength="18" oninput="formatarCpfCnpj(this)">
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

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
