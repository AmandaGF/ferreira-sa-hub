<?php
/**
 * Ferreira & Sá Hub — Onboarding de Colaboradores
 * Cadastro de novos colaboradores + geração de link único de boas-vindas.
 * Acesso: SOMENTE admin
 */

require_once __DIR__ . '/../../core/middleware.php';
require_once __DIR__ . '/../../core/onboarding_docs_schema.php';
require_login();
require_role('admin');

$pageTitle = 'Onboarding de Colaboradores';
$pdo = db();

// ── AJAX: autocomplete de nome ────────────────────────────
// Busca em colaboradores_onboarding (re-cadastro) e em clients
// (caso a colaboradora ja tenha sido cliente do escritorio).
if (isset($_GET['ajax']) && $_GET['ajax'] === 'buscar_nome') {
    header('Content-Type: application/json; charset=utf-8');
    $q = trim($_GET['q'] ?? '');
    if (mb_strlen($q) < 2) { echo json_encode(array()); exit; }
    $like = '%' . $q . '%';
    $resultados = array();

    // Onboarding existente (re-cadastro / atualizacao)
    try {
        $st = $pdo->prepare("SELECT nome_completo, data_nascimento, genero, cpf, email_institucional, telefone_whatsapp, cargo, setor
                             FROM colaboradores_onboarding
                             WHERE nome_completo LIKE ? AND status != 'arquivado'
                             ORDER BY nome_completo LIMIT 5");
        $st->execute(array($like));
        foreach ($st->fetchAll() as $r) {
            $resultados[] = array(
                'fonte' => 'onboarding',
                'nome'  => $r['nome_completo'],
                'data_nascimento' => $r['data_nascimento'],
                'genero' => $r['genero'] ?: '',
                'cpf'   => $r['cpf'] ?: '',
                'email' => $r['email_institucional'] ?: '',
                'whatsapp' => $r['telefone_whatsapp'] ?: '',
                'cargo' => $r['cargo'] ?: '',
                'setor' => $r['setor'] ?: '',
            );
        }
    } catch (Exception $e) {}

    // Clients (ex-cliente que virou colaborador) — puxa whatsapp e demais dados
    try {
        $st = $pdo->prepare("SELECT name, birth_date, cpf, email, phone
                             FROM clients
                             WHERE name LIKE ?
                             ORDER BY name LIMIT 5");
        $st->execute(array($like));
        foreach ($st->fetchAll() as $r) {
            $resultados[] = array(
                'fonte' => 'cliente',
                'nome'  => $r['name'],
                'data_nascimento' => $r['birth_date'],
                'genero' => '',
                'cpf'   => $r['cpf'] ?: '',
                'email' => $r['email'] ?: '',
                'whatsapp' => preg_replace('/\D/', '', $r['phone'] ?: ''),
                'cargo' => '',
                'setor' => '',
            );
        }
    } catch (Exception $e) {}

    echo json_encode($resultados);
    exit;
}

// ── Self-heal: cria tabela ────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS colaboradores_onboarding (
        id INT AUTO_INCREMENT PRIMARY KEY,
        token VARCHAR(48) NOT NULL UNIQUE,
        nome_completo VARCHAR(150) NOT NULL,
        data_nascimento DATE NOT NULL,
        cpf VARCHAR(14) NULL,
        email_institucional VARCHAR(150) NULL,
        senha_inicial VARCHAR(80) NULL,
        kit_descricao TEXT NULL,
        modalidade VARCHAR(20) NULL,
        dias_trabalho VARCHAR(150) NULL,
        horario_inicio TIME NULL,
        horario_fim TIME NULL,
        setor VARCHAR(150) NULL,
        cargo VARCHAR(150) NULL,
        tipo_remuneracao VARCHAR(30) NULL,
        valor_remuneracao DECIMAL(10,2) NULL,
        data_pagamento VARCHAR(50) NULL,
        beneficios TEXT NULL,
        mensagem_pessoal TEXT NULL,
        link_contrato_url VARCHAR(500) NULL,
        aceite_em DATETIME NULL,
        aceite_ip VARCHAR(45) NULL,
        ultimo_acesso_em DATETIME NULL,
        status ENUM('pendente','ativo','aceito','arquivado') NOT NULL DEFAULT 'pendente',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        created_by INT NULL,
        INDEX idx_status (status),
        INDEX idx_token (token)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

// Self-heal coluna CPF caso a tabela ja existisse de antes
try { $pdo->exec("ALTER TABLE colaboradores_onboarding ADD COLUMN cpf VARCHAR(14) NULL AFTER data_nascimento"); } catch (Exception $e) {}

// Self-heal: coluna perfil_cargo (estagiario / advogado_associado / clt / sociedade / outro)
// Usada pra sugerir quais documentos a colaboradora deve assinar.
try { $pdo->exec("ALTER TABLE colaboradores_onboarding ADD COLUMN perfil_cargo VARCHAR(40) NULL AFTER cargo"); } catch (Exception $e) {}

// Self-heal: genero (F/M) — pra ajustar concordancia nas mensagens
try { $pdo->exec("ALTER TABLE colaboradores_onboarding ADD COLUMN genero VARCHAR(1) NULL AFTER data_nascimento"); } catch (Exception $e) {}

// Self-heal: foto_path — caminho relativo da foto da colaboradora (baixada do WhatsApp)
try { $pdo->exec("ALTER TABLE colaboradores_onboarding ADD COLUMN foto_path VARCHAR(300) NULL AFTER genero"); } catch (Exception $e) {}

// Self-heal: telefone_whatsapp — usado pra buscar foto de perfil via Z-API
try { $pdo->exec("ALTER TABLE colaboradores_onboarding ADD COLUMN telefone_whatsapp VARCHAR(20) NULL AFTER foto_path"); } catch (Exception $e) {}

// Self-heal: local_presencial (endereço onde a colaboradora trabalha quando presencial/híbrido)
try { $pdo->exec("ALTER TABLE colaboradores_onboarding ADD COLUMN local_presencial VARCHAR(300) NULL AFTER modalidade"); } catch (Exception $e) {}

// Self-heal: tamanho_camisa (P / M / G / GG) — escolhido pela propria colaboradora
try { $pdo->exec("ALTER TABLE colaboradores_onboarding ADD COLUMN tamanho_camisa VARCHAR(4) NULL"); } catch (Exception $e) {}

// Self-heal: tabela de documentos vinculados a cada colaborador.
// Aceita qualquer tipo de documento (Termo de Compromisso, Confidencialidade,
// Checklist, POP, Contrato de Associacao, etc) via campo `tipo` + JSONs com
// dados especificos preenchidos por admin/colaborador.
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS colaboradores_documentos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        colaborador_id INT NOT NULL,
        tipo VARCHAR(60) NOT NULL,
        status VARCHAR(40) NOT NULL DEFAULT 'pendente',
        dados_admin_json LONGTEXT NULL,
        dados_estagiario_json LONGTEXT NULL,
        assinatura_estagiario_em DATETIME NULL,
        assinatura_estagiario_ip VARCHAR(45) NULL,
        assinatura_estagiario_nome VARCHAR(200) NULL,
        assinatura_admin_em DATETIME NULL,
        assinatura_admin_user_id INT NULL,
        pdf_drive_file_id VARCHAR(120) NULL,
        pdf_drive_url VARCHAR(500) NULL,
        pdf_html_snapshot LONGTEXT NULL,
        criado_por INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_col (colaborador_id),
        INDEX idx_tipo (tipo),
        INDEX idx_status (status),
        UNIQUE KEY uniq_colab_tipo (colaborador_id, tipo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

/**
 * Gera senha padrao do escritorio: CPF completo (11 digitos sem ponto/traço) + "@".
 * Ex: CPF 123.456.789-00 → "12345678900@"
 */
function gerar_senha_padrao_fsa($cpf) {
    $digits = preg_replace('/\D/', '', (string)$cpf);
    if (strlen($digits) !== 11) return '';
    return $digits . '@';
}

/**
 * Sincroniza os documentos vinculados ao colaborador.
 * Cria registros novos para tipos marcados que ainda nao existem;
 * NAO apaga os existentes (mesmo se desmarcados) para preservar
 * historico de assinaturas — admin tem que arquivar manualmente.
 */
function sincronizar_docs_vinculados($pdo, $colaboradorId, $tiposMarcados) {
    if (!is_array($tiposMarcados)) $tiposMarcados = array();
    global $ONBOARDING_DOC_SCHEMAS;
    foreach ($tiposMarcados as $tipo) {
        $tipo = trim((string)$tipo);
        if (!isset($ONBOARDING_DOC_SCHEMAS[$tipo])) continue;
        try {
            // INSERT IGNORE pra criar so se nao existe (uniq_colab_tipo previne duplicata)
            $pdo->prepare("INSERT IGNORE INTO colaboradores_documentos
                           (colaborador_id, tipo, status, criado_por)
                           VALUES (?, ?, 'pendente', ?)")
                ->execute(array($colaboradorId, $tipo, current_user_id()));
        } catch (Exception $e) {
            error_log('[onboarding] erro vinculando doc ' . $tipo . ': ' . $e->getMessage());
        }
    }
}

// ── Handlers POST ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && validate_csrf()) {
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    if ($action === 'salvar') {
        $cpfInput = trim($_POST['cpf'] ?? '');
        $senhaInput = trim($_POST['senha_inicial'] ?? '');
        // Se admin nao preencheu senha mas preencheu CPF, gera padrao FSA
        if ($senhaInput === '' && $cpfInput !== '') {
            $senhaInput = gerar_senha_padrao_fsa($cpfInput);
        }
        $dados = array(
            'nome_completo'        => trim($_POST['nome_completo'] ?? ''),
            'data_nascimento'      => trim($_POST['data_nascimento'] ?? ''),
            'cpf'                  => $cpfInput,
            'email_institucional'  => trim($_POST['email_institucional'] ?? ''),
            'senha_inicial'        => $senhaInput,
            'kit_descricao'        => trim($_POST['kit_descricao'] ?? ''),
            'modalidade'           => trim($_POST['modalidade'] ?? ''),
            'dias_trabalho'        => trim($_POST['dias_trabalho'] ?? ''),
            'horario_inicio'       => trim($_POST['horario_inicio'] ?? '') ?: null,
            'horario_fim'          => trim($_POST['horario_fim'] ?? '') ?: null,
            'setor'                => trim($_POST['setor'] ?? ''),
            'cargo'                => trim($_POST['cargo'] ?? ''),
            'tipo_remuneracao'     => trim($_POST['tipo_remuneracao'] ?? ''),
            'valor_remuneracao'    => preg_replace('/[^\d.]/', '', str_replace(',', '.', $_POST['valor_remuneracao'] ?? '')) ?: null,
            'data_pagamento'       => trim($_POST['data_pagamento'] ?? ''),
            'beneficios'           => trim($_POST['beneficios'] ?? ''),
            'mensagem_pessoal'     => trim($_POST['mensagem_pessoal'] ?? ''),
            'link_contrato_url'    => trim($_POST['link_contrato_url'] ?? ''),
            'perfil_cargo'         => trim($_POST['perfil_cargo'] ?? ''),
            'genero'               => in_array(($_POST['genero'] ?? ''), array('F','M'), true) ? $_POST['genero'] : null,
            'telefone_whatsapp'    => preg_replace('/\D/', '', trim($_POST['telefone_whatsapp'] ?? '')) ?: null,
            'local_presencial'     => trim($_POST['local_presencial'] ?? ''),
        );

        // Se admin informou WhatsApp da colaboradora, tenta buscar foto de perfil
        // via Z-API (canal 24 — colaborativo). Se houver foto, baixa e salva
        // em /files/onboarding_fotos/. Se nao houver, deixa null (sem foto).
        if (!empty($dados['telefone_whatsapp'])) {
            try {
                require_once APP_ROOT . '/core/functions_zapi.php';
                $fotoUrl = zapi_fetch_profile_picture('24', $dados['telefone_whatsapp']);
                if ($fotoUrl) {
                    $imgRaw = @file_get_contents($fotoUrl);
                    if ($imgRaw && strlen($imgRaw) > 200) {
                        $uploadDir = APP_ROOT . '/files/onboarding_fotos';
                        if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);
                        $nomeArq = 'wa_' . time() . '_' . bin2hex(random_bytes(4)) . '.jpg';
                        $destino = $uploadDir . '/' . $nomeArq;
                        if (file_put_contents($destino, $imgRaw)) {
                            $dados['foto_path'] = '/files/onboarding_fotos/' . $nomeArq;
                        }
                    }
                }
            } catch (Exception $e) {
                error_log('[onboarding] erro buscando foto WA: ' . $e->getMessage());
            }
        }

        if (!$dados['nome_completo'] || !$dados['data_nascimento']) {
            flash_set('error', 'Nome completo e data de nascimento são obrigatórios.');
        } else {
            try {
                if ($id > 0) {
                    $sets = array();
                    $vals = array();
                    foreach ($dados as $k => $v) { $sets[] = "$k = ?"; $vals[] = $v; }
                    $vals[] = $id;
                    $pdo->prepare("UPDATE colaboradores_onboarding SET " . implode(', ', $sets) . " WHERE id = ?")
                        ->execute($vals);
                    sincronizar_docs_vinculados($pdo, $id, $_POST['docs_vinculados'] ?? array());
                    flash_set('success', 'Cadastro atualizado.');
                } else {
                    $token = bin2hex(random_bytes(16));
                    $cols = array_merge(array_keys($dados), array('token', 'created_by'));
                    $place = implode(',', array_fill(0, count($cols), '?'));
                    $vals = array_merge(array_values($dados), array($token, current_user_id()));
                    $pdo->prepare("INSERT INTO colaboradores_onboarding (" . implode(',', $cols) . ") VALUES ($place)")
                        ->execute($vals);
                    $newId = $pdo->lastInsertId();
                    sincronizar_docs_vinculados($pdo, $newId, $_POST['docs_vinculados'] ?? array());
                    flash_set('success', 'Colaborador(a) cadastrado(a)! Compartilhe o link único de boas-vindas.');
                    redirect(module_url('admin', 'onboarding.php?id=' . $newId));
                }
            } catch (Exception $e) {
                flash_set('error', 'Erro ao salvar: ' . $e->getMessage());
            }
        }
        redirect(module_url('admin', 'onboarding.php' . ($id ? '?id=' . $id : '')));
    }

    if ($action === 'arquivar' && $id) {
        $pdo->prepare("UPDATE colaboradores_onboarding SET status = 'arquivado' WHERE id = ?")->execute(array($id));
        flash_set('success', 'Cadastro arquivado.');
        redirect(module_url('admin', 'onboarding.php'));
    }

    if ($action === 'reativar' && $id) {
        $pdo->prepare("UPDATE colaboradores_onboarding SET status = 'ativo' WHERE id = ?")->execute(array($id));
        flash_set('success', 'Reativado.');
        redirect(module_url('admin', 'onboarding.php'));
    }

    if ($action === 'excluir' && $id) {
        // Apaga em cascata: documentos vinculados primeiro, depois o cadastro.
        // Apaga tambem foto local da colaboradora se houver.
        try {
            $stF = $pdo->prepare("SELECT foto_path FROM colaboradores_onboarding WHERE id = ?");
            $stF->execute(array($id));
            $fp = $stF->fetchColumn();
            if ($fp && strpos($fp, '/files/onboarding_fotos/') === 0) {
                $abs = APP_ROOT . $fp;
                if (file_exists($abs)) @unlink($abs);
            }
        } catch (Exception $e) {}
        try { $pdo->prepare("DELETE FROM colaboradores_documentos WHERE colaborador_id = ?")->execute(array($id)); } catch (Exception $e) {}
        $pdo->prepare("DELETE FROM colaboradores_onboarding WHERE id = ?")->execute(array($id));
        flash_set('success', 'Cadastro excluído permanentemente.');
        redirect(module_url('admin', 'onboarding.php'));
    }

    if ($action === 'regenerar_token' && $id) {
        $token = bin2hex(random_bytes(16));
        $pdo->prepare("UPDATE colaboradores_onboarding SET token = ?, aceite_em = NULL, aceite_ip = NULL WHERE id = ?")
            ->execute(array($token, $id));
        flash_set('success', 'Novo link gerado. O anterior deixa de funcionar.');
        redirect(module_url('admin', 'onboarding.php?id=' . $id));
    }
}

// ── Carrega registro pra edição ──────────────────────────
$editId = (int)($_GET['id'] ?? 0);
$reg = null;
$docsVinculados = array(); // tipos de documento ja vinculados a este colaborador
if ($editId) {
    $st = $pdo->prepare("SELECT * FROM colaboradores_onboarding WHERE id = ?");
    $st->execute(array($editId));
    $reg = $st->fetch();
    if ($reg) {
        try {
            $stD = $pdo->prepare("SELECT tipo, status, dados_admin_json, assinatura_estagiario_em
                                  FROM colaboradores_documentos WHERE colaborador_id = ?");
            $stD->execute(array($editId));
            foreach ($stD->fetchAll() as $d) {
                $docsVinculados[$d['tipo']] = $d;
            }
        } catch (Exception $e) {}
    }
}

// ── Lista todos pendentes/ativos ─────────────────────────
$lista = array();
try {
    $lista = $pdo->query("SELECT id, nome_completo, email_institucional, status, aceite_em, created_at, token, tamanho_camisa
                          FROM colaboradores_onboarding
                          WHERE status != 'arquivado'
                          ORDER BY created_at DESC")->fetchAll();
} catch (Exception $e) {}

// URL pública do link de boas-vindas
$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
$basePath = rtrim(dirname(dirname(dirname($_SERVER['PHP_SELF']))), '/');
$urlPublica = function($token) use ($baseUrl, $basePath) {
    return $baseUrl . $basePath . '/publico/onboarding/?token=' . $token;
};

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.ob-card { background:#fff; border-radius:14px; border:1px solid #e5e7eb; padding:1.5rem; margin-bottom:1.2rem; box-shadow:0 2px 8px rgba(0,0,0,.04); }
.ob-card h3 { font-size:1rem; color:#052228; margin-bottom:1rem; padding-bottom:.5rem; border-bottom:2px solid #d7ab90; }
.ob-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:.85rem; }
.ob-grid label { font-size:.78rem; font-weight:700; color:#052228; display:block; margin-bottom:.25rem; }
.ob-grid input, .ob-grid select, .ob-grid textarea { width:100%; padding:.55rem .75rem; border:1.5px solid #e5e7eb; border-radius:8px; font-size:.85rem; font-family:inherit; }
.ob-grid input:focus, .ob-grid select:focus, .ob-grid textarea:focus { outline:none; border-color:#B87333; box-shadow:0 0 0 3px rgba(184,115,51,.15); }
.ob-link-box { background:linear-gradient(135deg,#fff7ed,#ffe9d3); border:2px solid #d7ab90; border-radius:12px; padding:1rem 1.2rem; margin:.75rem 0; }
.ob-link-box code { background:#fff; padding:.4rem .6rem; border-radius:6px; border:1px solid #d7ab90; display:block; word-break:break-all; font-size:.8rem; color:#6a3c2c; margin-top:.5rem; }
.ob-table { width:100%; border-collapse:collapse; margin-top:.5rem; }
.ob-table th, .ob-table td { padding:.65rem .75rem; text-align:left; border-bottom:1px solid #f0f0f0; font-size:.85rem; }
.ob-table th { background:#fafafa; color:#052228; font-weight:700; font-size:.72rem; text-transform:uppercase; letter-spacing:.04em; }
.ob-status { display:inline-block; padding:.18rem .6rem; border-radius:12px; font-size:.7rem; font-weight:700; }
.ob-status.pendente { background:#fef3c7; color:#92400e; }
.ob-status.ativo { background:#dbeafe; color:#1e40af; }
.ob-status.aceito { background:#d1fae5; color:#065f46; }
</style>

<div class="card">
    <div class="card-header">
        <h3>👋 Onboarding de Colaboradores</h3>
        <p style="font-size:.82rem;color:#6b7280;margin-top:.3rem;">Cadastre nova colaboradora/colaborador e envie o link de boas-vindas. Acesso pela colaboradora via <strong>nome completo + data de nascimento</strong>.</p>
    </div>
</div>

<?php if ($reg && $reg['token']): ?>
<div class="ob-card">
    <h3>🔗 Link de boas-vindas — <?= e($reg['nome_completo']) ?></h3>
    <div class="ob-link-box">
        <strong style="color:#6a3c2c;">Compartilhe esse link com a colaboradora:</strong>
        <code id="onboardingLink"><?= e($urlPublica($reg['token'])) ?></code>
        <div style="display:flex;gap:.5rem;margin-top:.5rem;flex-wrap:wrap;">
            <button class="btn btn-primary btn-sm" onclick="copiarLink()">📋 Copiar link</button>
            <a class="btn btn-outline btn-sm" target="_blank" href="<?= e($urlPublica($reg['token'])) ?>">👁 Visualizar</a>
            <form method="POST" style="display:inline;" onsubmit="return confirm('Gerar novo link? O atual deixa de funcionar.');">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="regenerar_token">
                <input type="hidden" name="id" value="<?= (int)$reg['id'] ?>">
                <button class="btn btn-outline btn-sm" type="submit" style="background:#fef3c7;border-color:#fcd34d;color:#92400e;">🔄 Regenerar</button>
            </form>
        </div>
        <p style="font-size:.75rem;color:#6a3c2c;margin-top:.6rem;margin-bottom:0;">
            <strong>Acesso:</strong> a colaboradora digita o nome completo (igual ao cadastro) + data de nascimento (DD/MM/AAAA).
        </p>
    </div>
</div>
<?php endif; ?>

<div class="ob-card">
    <h3><?= $reg ? '✏️ Editar Cadastro' : '➕ Novo Cadastro' ?></h3>
    <form method="POST">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="salvar">
        <?php if ($reg): ?><input type="hidden" name="id" value="<?= (int)$reg['id'] ?>"><?php endif; ?>

        <h4 style="font-size:.85rem;color:#6a3c2c;margin:1rem 0 .5rem;">👤 Dados pessoais</h4>
        <div class="ob-grid">
            <div style="position:relative;">
                <label>Nome completo *</label>
                <input name="nome_completo" id="nomeCompletoInput" required value="<?= e($reg['nome_completo'] ?? '') ?>" placeholder="Ex: Ana Beatriz Ferreira de Sá" autocomplete="off" oninput="onNomeChange(this.value)">
                <div id="nomeAutocomplete" style="display:none;position:absolute;top:100%;left:0;right:0;background:#fff;border:1px solid #d7ab90;border-radius:8px;max-height:240px;overflow-y:auto;z-index:20;box-shadow:0 6px 16px rgba(0,0,0,.12);margin-top:2px;"></div>
            </div>
            <div>
                <label>Data de nascimento *</label>
                <input name="data_nascimento" type="date" required value="<?= e($reg['data_nascimento'] ?? '') ?>">
            </div>
            <div>
                <label>CPF</label>
                <input name="cpf" id="cpfInput" value="<?= e($reg['cpf'] ?? '') ?>" placeholder="000.000.000-00" oninput="onCpfChange()">
            </div>
            <div>
                <label>Gênero <span style="color:#6a3c2c;font-size:.7rem;font-weight:400;">(concordância nas mensagens)</span></label>
                <div style="display:flex;gap:.6rem;margin-top:.3rem;">
                    <label style="flex:1;display:flex;align-items:center;gap:.3rem;cursor:pointer;padding:.4rem .7rem;border:1.5px solid #e5e7eb;border-radius:8px;font-size:.82rem;background:<?= ($reg['genero'] ?? '') === 'F' ? '#fdf2f8' : '#fff' ?>;border-color:<?= ($reg['genero'] ?? '') === 'F' ? '#db2777' : '#e5e7eb' ?>;">
                        <input type="radio" name="genero" value="F" <?= ($reg['genero'] ?? '') === 'F' ? 'checked' : '' ?>> 👩 Feminino
                    </label>
                    <label style="flex:1;display:flex;align-items:center;gap:.3rem;cursor:pointer;padding:.4rem .7rem;border:1.5px solid #e5e7eb;border-radius:8px;font-size:.82rem;background:<?= ($reg['genero'] ?? '') === 'M' ? '#eff6ff' : '#fff' ?>;border-color:<?= ($reg['genero'] ?? '') === 'M' ? '#2563eb' : '#e5e7eb' ?>;">
                        <input type="radio" name="genero" value="M" <?= ($reg['genero'] ?? '') === 'M' ? 'checked' : '' ?>> 👨 Masculino
                    </label>
                </div>
            </div>
            <div>
                <label>WhatsApp da colaboradora <span style="color:#6a3c2c;font-size:.7rem;font-weight:400;">(busca foto de perfil automática)</span></label>
                <input name="telefone_whatsapp" value="<?= e($reg['telefone_whatsapp'] ?? '') ?>" placeholder="Ex: 24992050096">
                <?php if (!empty($reg['foto_path'])): ?>
                    <div style="margin-top:.4rem;display:flex;align-items:center;gap:.5rem;font-size:.72rem;color:#059669;">
                        <img src="<?= e($reg['foto_path']) ?>" style="width:36px;height:36px;border-radius:50%;object-fit:cover;border:1.5px solid #d7ab90;">
                        ✓ Foto carregada do WhatsApp
                    </div>
                <?php endif; ?>
            </div>
            <div>
                <label>Cargo / Função</label>
                <input name="cargo" value="<?= e($reg['cargo'] ?? '') ?>" placeholder="Ex: Estagiária">
            </div>
            <div>
                <label>Setor / Área de atuação</label>
                <input name="setor" value="<?= e($reg['setor'] ?? '') ?>" placeholder="Ex: Família e Sucessões">
            </div>
            <div>
                <label>Perfil de cargo <span style="color:#6a3c2c;font-size:.7rem;font-weight:400;">(define documentos a assinar)</span></label>
                <select name="perfil_cargo" id="perfilCargoSelect" onchange="atualizarDocsDisponiveis()">
                    <option value="">— Selecione —</option>
                    <?php foreach (onboarding_perfis_cargo() as $k => $lbl): ?>
                        <option value="<?= e($k) ?>" <?= ($reg['perfil_cargo'] ?? '') === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <h4 style="font-size:.85rem;color:#6a3c2c;margin:1.2rem 0 .5rem;">📧 Acesso institucional</h4>
        <div class="ob-grid">
            <div>
                <label>E-mail institucional <span style="color:#6a3c2c;font-size:.7rem;font-weight:400;">(auto: primeiro+último nome)</span></label>
                <input name="email_institucional" id="emailInstInput" type="email" value="<?= e($reg['email_institucional'] ?? '') ?>" placeholder="nomesobrenome@ferreiraesa.com.br">
            </div>
            <div>
                <label>Senha inicial <span style="color:#6a3c2c;font-size:.7rem;font-weight:400;">(auto: CPF completo + @)</span></label>
                <input name="senha_inicial" id="senhaInicialInput" value="<?= e($reg['senha_inicial'] ?? '') ?>" placeholder="Preenche sozinha pelo CPF completo (11 dígitos) + @">
            </div>
        </div>

        <h4 style="font-size:.85rem;color:#6a3c2c;margin:1.2rem 0 .5rem;">⏰ Jornada de trabalho</h4>
        <div class="ob-grid">
            <div>
                <label>Modalidade</label>
                <select name="modalidade">
                    <option value="">— Selecione —</option>
                    <?php foreach (array('Presencial','Remoto','Híbrido') as $m): ?>
                        <option value="<?= e($m) ?>" <?= ($reg['modalidade'] ?? '') === $m ? 'selected' : '' ?>><?= e($m) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Local presencial <span style="color:#6a3c2c;font-size:.7rem;font-weight:400;">(quando híbrido/presencial)</span></label>
                <input name="local_presencial" value="<?= e($reg['local_presencial'] ?? '') ?>" placeholder="Ex: Barra Mansa/RJ — Rua Dr. Aldrovando, 140 — Ano Bom">
            </div>
            <div>
                <label>Dias de trabalho</label>
                <input name="dias_trabalho" value="<?= e($reg['dias_trabalho'] ?? '') ?>" placeholder="Ex: Segunda a sexta">
            </div>
            <div>
                <label>Horário início</label>
                <input name="horario_inicio" type="time" value="<?= e($reg['horario_inicio'] ?? '') ?>">
            </div>
            <div>
                <label>Horário fim</label>
                <input name="horario_fim" type="time" value="<?= e($reg['horario_fim'] ?? '') ?>">
            </div>
        </div>

        <h4 style="font-size:.85rem;color:#6a3c2c;margin:1.2rem 0 .5rem;">💰 Remuneração</h4>
        <div class="ob-grid">
            <div>
                <label>Tipo</label>
                <select name="tipo_remuneracao">
                    <option value="">— Selecione —</option>
                    <?php foreach (array('Bolsa de estágio','Auxílio (PJ)','CLT','Honorários','Sociedade') as $t): ?>
                        <option value="<?= e($t) ?>" <?= ($reg['tipo_remuneracao'] ?? '') === $t ? 'selected' : '' ?>><?= e($t) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Valor (R$)</label>
                <input name="valor_remuneracao" value="<?= e($reg['valor_remuneracao'] ?? '') ?>" placeholder="1500.00">
            </div>
            <div>
                <label>Data de pagamento</label>
                <input name="data_pagamento" value="<?= e($reg['data_pagamento'] ?? '') ?>" placeholder="Ex: Todo dia 5 do mês seguinte">
            </div>
        </div>

        <h4 style="font-size:.85rem;color:#6a3c2c;margin:1.2rem 0 .5rem;">🎁 Kit + Benefícios</h4>
        <div class="ob-grid">
            <div style="grid-column:1/-1;">
                <label>Kit de boas-vindas (descrição)</label>
                <textarea name="kit_descricao" rows="2" placeholder="Ex: Caneca + caderno + caneta + camiseta. Será entregue em até 7 dias."><?= e($reg['kit_descricao'] ?? '') ?></textarea>
            </div>
            <div style="grid-column:1/-1;">
                <label>Benefícios (1 por linha)</label>
                <textarea name="beneficios" rows="3" placeholder="Vale-transporte&#10;Vale-refeição&#10;Plano de saúde após período de experiência&#10;Day-off no aniversário"><?= e($reg['beneficios'] ?? '') ?></textarea>
            </div>
        </div>

        <h4 style="font-size:.85rem;color:#6a3c2c;margin:1.2rem 0 .5rem;">📝 Mensagem pessoal + Contrato</h4>
        <div class="ob-grid">
            <div style="grid-column:1/-1;">
                <label>Mensagem pessoal de boas-vindas (opcional)</label>
                <textarea name="mensagem_pessoal" rows="3" placeholder="Texto livre que aparecerá em destaque na página da colaboradora. Deixe em branco para usar o texto padrão."><?= e($reg['mensagem_pessoal'] ?? '') ?></textarea>
            </div>
            <div style="grid-column:1/-1;">
                <label>Link do contrato para assinatura (URL externa, ex: D4Sign / Asaas)</label>
                <input name="link_contrato_url" type="url" value="<?= e($reg['link_contrato_url'] ?? '') ?>" placeholder="https://...">
            </div>
        </div>

        <h4 style="font-size:.85rem;color:#6a3c2c;margin:1.2rem 0 .5rem;">📄 Documentos para preencher e assinar</h4>
        <div id="docsVincularWrap" style="background:#fff7ed;border:1px dashed #d7ab90;border-radius:10px;padding:1rem;">
            <div id="docsVincularLista" style="display:flex;flex-direction:column;gap:.6rem;">
                <p id="docsVincularEmpty" style="font-size:.82rem;color:#6b7280;margin:0;">Selecione um <strong>perfil de cargo</strong> acima para ver os documentos disponíveis.</p>
            </div>
            <p style="font-size:.7rem;color:#6a3c2c;margin-top:.6rem;margin-bottom:0;">
                💡 Os campos administrativos de cada documento (modalidade, datas, valores, etc.) serão preenchidos depois — esta página só vincula os documentos. Os campos pessoais ficam para a colaboradora preencher na página de boas-vindas.
            </p>
        </div>

        <?php if ($reg && !empty($docsVinculados)): ?>
        <h4 style="font-size:.85rem;color:#6a3c2c;margin:1.4rem 0 .5rem;">📑 Status dos documentos vinculados</h4>
        <div style="display:flex;flex-direction:column;gap:.5rem;">
            <?php foreach ($docsVinculados as $tipoDoc => $docInfo):
                $sch = onboarding_doc_schema($tipoDoc);
                if (!$sch) continue;
                $statusReal = $docInfo['status'] ?? 'pendente';
                $assinadoEm = $docInfo['assinatura_estagiario_em'] ?? null;
                $jaAssinou = !empty($assinadoEm);
                $statusBg = $jaAssinou ? '#d1fae5' : ($statusReal === 'em_preenchimento' ? '#fed7aa' : '#fef3c7');
                $statusCor = $jaAssinou ? '#065f46' : ($statusReal === 'em_preenchimento' ? '#9a3412' : '#92400e');
                $statusTxt = $jaAssinou ? '✓ Assinado em ' . date('d/m/Y H:i', strtotime($assinadoEm))
                    : ($statusReal === 'em_preenchimento' ? '⏳ Em preenchimento' : '📋 Pendente');
            ?>
            <div style="background:#fff;border:1.5px solid <?= $jaAssinou ? '#34d399' : '#e5e7eb' ?>;border-radius:10px;padding:.7rem 1rem;display:flex;align-items:center;gap:.7rem;flex-wrap:wrap;">
                <div style="font-size:1.4rem;line-height:1;flex-shrink:0;"><?= e($sch['icon']) ?></div>
                <div style="flex:1;min-width:200px;">
                    <strong style="font-size:.92rem;color:#052228;"><?= e($sch['label']) ?></strong>
                    <div><span style="display:inline-block;background:<?= $statusBg ?>;color:<?= $statusCor ?>;padding:.12rem .55rem;border-radius:10px;font-size:.7rem;font-weight:700;margin-top:.2rem;"><?= e($statusTxt) ?></span></div>
                </div>
                <div style="display:flex;gap:.4rem;flex-wrap:wrap;">
                    <?php if (!empty($sch['campos_admin'])): ?>
                        <a href="<?= module_url('admin', 'onboarding_doc.php?id=' . (int)$docInfo['id']) ?>" class="btn btn-outline btn-sm">⚙️ Campos admin</a>
                    <?php endif; ?>
                    <a href="<?= module_url('admin', 'onboarding_doc_view.php?id=' . (int)$docInfo['id']) ?>" target="_blank" class="btn btn-outline btn-sm">👁 Ver</a>
                    <?php if ($jaAssinou): ?>
                        <a href="<?= module_url('admin', 'onboarding_doc_view.php?id=' . (int)$docInfo['id']) ?>" target="_blank" class="btn btn-outline btn-sm" style="background:#d1fae5;border-color:#34d399;color:#065f46;">📥 PDF</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <script>
        // Schemas dos documentos por perfil — carregados do PHP
        var DOC_SCHEMAS_PHP = <?= json_encode(onboarding_perfis_cargo()) ?>;
        var DOCS_BY_PERFIL = <?php
            $byPerfil = array();
            foreach (onboarding_perfis_cargo() as $perfilKey => $_) {
                $byPerfil[$perfilKey] = array();
                foreach (onboarding_docs_por_perfil($perfilKey) as $tipoDoc => $schema) {
                    $byPerfil[$perfilKey][] = array(
                        'tipo' => $tipoDoc,
                        'label' => $schema['label'],
                        'icon' => $schema['icon'],
                        'descricao' => $schema['descricao'],
                    );
                }
            }
            echo json_encode($byPerfil);
        ?>;
        var DOCS_VINCULADOS = <?= json_encode(array_keys($docsVinculados)) ?>;

        function atualizarDocsDisponiveis() {
            var perfil = document.getElementById('perfilCargoSelect').value;
            var lista = document.getElementById('docsVincularLista');
            var empty = document.getElementById('docsVincularEmpty');
            if (!perfil) {
                lista.innerHTML = '<p id="docsVincularEmpty" style="font-size:.82rem;color:#6b7280;margin:0;">Selecione um <strong>perfil de cargo</strong> acima para ver os documentos disponíveis.</p>';
                return;
            }
            var docs = DOCS_BY_PERFIL[perfil] || [];
            if (!docs.length) {
                lista.innerHTML = '<p style="font-size:.82rem;color:#6b7280;margin:0;">Nenhum documento cadastrado ainda para este perfil.</p>';
                return;
            }
            var html = '';
            docs.forEach(function(d) {
                var jaVinculado = DOCS_VINCULADOS.indexOf(d.tipo) !== -1;
                html += '<label style="display:flex;align-items:flex-start;gap:.55rem;padding:.6rem .8rem;background:#fff;border:1.5px solid ' + (jaVinculado ? '#059669' : '#e5e7eb') + ';border-radius:8px;cursor:pointer;font-size:.85rem;">'
                      + '<input type="checkbox" name="docs_vinculados[]" value="' + d.tipo + '" ' + (jaVinculado ? 'checked' : '') + ' style="margin-top:3px;">'
                      + '<div style="flex:1;">'
                      + '<strong>' + d.icon + ' ' + d.label + '</strong>'
                      + (jaVinculado ? ' <span style="background:#d1fae5;color:#065f46;font-size:.65rem;padding:1px 6px;border-radius:4px;font-weight:700;">VINCULADO</span>' : '')
                      + '<br><span style="font-size:.74rem;color:#6b7280;">' + d.descricao + '</span>'
                      + '</div>'
                      + '</label>';
            });
            lista.innerHTML = html;
        }

        document.addEventListener('DOMContentLoaded', atualizarDocsDisponiveis);
        </script>

        <div style="margin-top:1.2rem;display:flex;gap:.5rem;flex-wrap:wrap;">
            <button type="submit" class="btn btn-primary">💾 <?= $reg ? 'Atualizar cadastro' : 'Cadastrar e gerar link' ?></button>
            <?php if ($reg): ?>
                <a href="<?= module_url('admin', 'onboarding.php') ?>" class="btn btn-outline">Novo cadastro</a>
                <form method="POST" style="display:inline;margin-left:auto;" onsubmit="return confirm('Arquivar este cadastro?');">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="arquivar">
                    <input type="hidden" name="id" value="<?= (int)$reg['id'] ?>">
                    <button type="submit" class="btn btn-outline" style="color:#dc2626;border-color:#fca5a5;">🗄 Arquivar</button>
                </form>
            <?php endif; ?>
        </div>
    </form>
</div>

<div class="ob-card">
    <h3>📋 Cadastros ativos (<?= count($lista) ?>)</h3>
    <?php if (empty($lista)): ?>
        <p style="color:#6b7280;font-size:.85rem;">Nenhum cadastro ativo. Crie o primeiro acima ☝️</p>
    <?php else: ?>
        <table class="ob-table">
            <thead>
                <tr>
                    <th>Nome</th>
                    <th>E-mail institucional</th>
                    <th>Status</th>
                    <th>👕 Camisa</th>
                    <th>Cadastrado em</th>
                    <th>Aceite</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($lista as $r): ?>
                <tr>
                    <td><strong><?= e($r['nome_completo']) ?></strong></td>
                    <td><?= e($r['email_institucional'] ?: '—') ?></td>
                    <td><span class="ob-status <?= e($r['status']) ?>"><?= e($r['status']) ?></span></td>
                    <td><?= !empty($r['tamanho_camisa']) ? '<strong style="color:#9f1239;">' . e($r['tamanho_camisa']) . '</strong>' : '<span style="color:#9ca3af;">—</span>' ?></td>
                    <td><?= e(data_hora_br($r['created_at'])) ?></td>
                    <td><?= $r['aceite_em'] ? '✓ ' . e(data_hora_br($r['aceite_em'])) : '—' ?></td>
                    <td style="white-space:nowrap;">
                        <a class="btn btn-outline btn-sm" href="<?= module_url('admin', 'onboarding.php?id=' . (int)$r['id']) ?>">✏️ Editar</a>
                        <a class="btn btn-outline btn-sm" target="_blank" href="<?= e($urlPublica($r['token'])) ?>">👁 Ver página</a>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('⚠ Excluir PERMANENTEMENTE o cadastro de <?= e($r['nome_completo']) ?>?\n\nIsso vai apagar tambem todos os documentos vinculados e a foto.\nA acao nao pode ser desfeita.');">
                            <?= csrf_input() ?>
                            <input type="hidden" name="action" value="excluir">
                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                            <button type="submit" class="btn btn-outline btn-sm" style="color:#dc2626;border-color:#fca5a5;">🗑 Excluir</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<script>
// Autocomplete pelo nome — busca em colaboradores existentes + clients
var _nomeTimer;
function onNomeChange(q) {
    clearTimeout(_nomeTimer);
    var box = document.getElementById('nomeAutocomplete');
    if (q.length < 2) { box.style.display = 'none'; return; }
    _nomeTimer = setTimeout(function() {
        fetch('?ajax=buscar_nome&q=' + encodeURIComponent(q))
            .then(function(r) { return r.json(); })
            .then(function(arr) {
                if (!arr.length) { box.style.display = 'none'; return; }
                var html = '';
                arr.forEach(function(p, i) {
                    var fonteTag = p.fonte === 'onboarding'
                        ? '<span style="background:#d7ab90;color:#052228;padding:1px 6px;border-radius:4px;font-size:.65rem;font-weight:700;">RE-CADASTRO</span>'
                        : '<span style="background:#bae6fd;color:#075985;padding:1px 6px;border-radius:4px;font-size:.65rem;font-weight:700;">CLIENTE</span>';
                    html += '<div data-idx="' + i + '" onclick="aplicarSugestao(' + i + ')" '
                          + 'style="padding:.55rem .8rem;cursor:pointer;border-bottom:1px solid #f0e9e0;font-size:.85rem;" '
                          + 'onmouseover="this.style.background=\'#fff7ed\'" onmouseout="this.style.background=\'\'">'
                          + '<div style="display:flex;align-items:center;gap:.5rem;">'
                          + '<strong>' + escapeHtml(p.nome) + '</strong> ' + fonteTag
                          + '</div>'
                          + '<div style="font-size:.72rem;color:#6b7280;margin-top:2px;">'
                          + (p.cpf ? 'CPF ' + escapeHtml(p.cpf) : '')
                          + (p.cpf && p.data_nascimento ? ' · ' : '')
                          + (p.data_nascimento ? '🎂 ' + formatDataBR(p.data_nascimento) : '')
                          + (p.email ? ' · ✉ ' + escapeHtml(p.email) : '')
                          + '</div></div>';
                });
                box.innerHTML = html;
                box.style.display = 'block';
                box._sugestoes = arr;
            })
            .catch(function() { box.style.display = 'none'; });
    }, 250);
}

function aplicarSugestao(idx) {
    var box = document.getElementById('nomeAutocomplete');
    var p = box._sugestoes && box._sugestoes[idx];
    if (!p) return;
    document.getElementById('nomeCompletoInput').value = p.nome || '';
    if (p.data_nascimento) {
        var inp = document.querySelector('input[name="data_nascimento"]');
        if (inp) inp.value = p.data_nascimento;
    }
    if (p.cpf) {
        var cpf = document.getElementById('cpfInput');
        if (cpf) { cpf.value = p.cpf; onCpfChange(); }
    }
    // E-mail institucional: SOMENTE se for ferreiraesa.com.br; senão gera pelo nome
    if (p.email && /@ferreiraesa\.com\.br$/i.test(p.email)) {
        var em = document.getElementById('emailInstInput');
        if (em && !em.value) em.value = p.email;
    } else {
        gerarEmailInstitucional(p.nome);
    }
    if (p.whatsapp) {
        var wa = document.querySelector('input[name="telefone_whatsapp"]');
        if (wa && !wa.value) wa.value = p.whatsapp;
    }
    if (p.genero) {
        var rb = document.querySelector('input[name="genero"][value="' + p.genero + '"]');
        if (rb) rb.checked = true;
    }
    if (p.cargo) {
        var cg = document.querySelector('input[name="cargo"]');
        if (cg && !cg.value) cg.value = p.cargo;
    }
    if (p.setor) {
        var st = document.querySelector('input[name="setor"]');
        if (st && !st.value) st.value = p.setor;
    }
    box.style.display = 'none';
}

// Gera e-mail institucional a partir do nome completo:
// "Maria Eduarda Soares Lima" -> "marialima@ferreiraesa.com.br"
function gerarEmailInstitucional(nomeCompleto) {
    var em = document.getElementById('emailInstInput');
    if (!em || em.value) return; // não sobrescreve se já tem
    if (!nomeCompleto) return;
    // Remove acentos + minúsculas
    var clean = nomeCompleto.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
    // Tira símbolos/espaços extra, partes simples
    var partes = clean.replace(/[^a-z\s]/g, '').trim().split(/\s+/).filter(Boolean);
    if (partes.length < 1) return;
    var primeiro = partes[0];
    var ultimo = partes.length > 1 ? partes[partes.length - 1] : '';
    var login = primeiro + ultimo;
    if (!login) return;
    em.value = login + '@ferreiraesa.com.br';
}

function escapeHtml(s) {
    if (s == null) return '';
    return String(s).replace(/[&<>"']/g, function(c) {
        return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
}
function formatDataBR(iso) {
    if (!iso) return '';
    var p = iso.split('-');
    return p.length === 3 ? p[2] + '/' + p[1] + '/' + p[0] : iso;
}

// Fecha autocomplete ao clicar fora
document.addEventListener('click', function(e) {
    if (!e.target.closest('#nomeCompletoInput, #nomeAutocomplete')) {
        var box = document.getElementById('nomeAutocomplete');
        if (box) box.style.display = 'none';
    }
});

// Mascara CPF + auto-preenchimento da senha padrao do escritorio
function onCpfChange() {
    var inp = document.getElementById('cpfInput');
    if (!inp) return;
    var v = inp.value.replace(/\D/g, '').slice(0, 11);
    // Aplica mascara 000.000.000-00
    var fmt = v;
    if (v.length > 9)      fmt = v.slice(0,3) + '.' + v.slice(3,6) + '.' + v.slice(6,9) + '-' + v.slice(9);
    else if (v.length > 6) fmt = v.slice(0,3) + '.' + v.slice(3,6) + '.' + v.slice(6);
    else if (v.length > 3) fmt = v.slice(0,3) + '.' + v.slice(3);
    inp.value = fmt;
    // Auto-gera senha (CPF completo 11 digitos + @) se a senha estiver vazia OU
    // se a senha atual seguir o padrao auto-gerado (9 ou 11 digitos + @)
    var senha = document.getElementById('senhaInicialInput');
    if (senha && v.length === 11) {
        var nova = v + '@';
        var atual = senha.value.trim();
        if (atual === '' || /^\d{9,11}@$/.test(atual)) {
            senha.value = nova;
        }
    }
}
// Roda no load tambem caso CPF ja esteja preenchido
document.addEventListener('DOMContentLoaded', onCpfChange);

// Mascara telefone WhatsApp: (00) 00000-0000 ou (00) 0000-0000
function onWhatsappChange(inp) {
    if (!inp) inp = document.querySelector('input[name="telefone_whatsapp"]');
    if (!inp) return;
    var v = inp.value.replace(/\D/g, '').slice(0, 11);
    var fmt = v;
    if (v.length > 10)      fmt = '(' + v.slice(0,2) + ') ' + v.slice(2,7) + '-' + v.slice(7);
    else if (v.length > 6)  fmt = '(' + v.slice(0,2) + ') ' + v.slice(2,6) + '-' + v.slice(6);
    else if (v.length > 2)  fmt = '(' + v.slice(0,2) + ') ' + v.slice(2);
    else if (v.length > 0)  fmt = '(' + v;
    inp.value = fmt;
}

// Mascara valor BR: 1.234,56
function onValorChange(inp) {
    if (!inp) return;
    var v = inp.value.replace(/\D/g, '');
    if (!v) { inp.value = ''; return; }
    // Centavos
    while (v.length < 3) v = '0' + v;
    var inteiro = v.slice(0, v.length - 2);
    var cents = v.slice(-2);
    // Remove zeros à esquerda
    inteiro = inteiro.replace(/^0+/, '') || '0';
    // Pontos a cada 3 dígitos
    inteiro = inteiro.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    inp.value = inteiro + ',' + cents;
}

// Trigger e-mail auto ao sair do campo de nome
document.addEventListener('DOMContentLoaded', function() {
    var nm = document.getElementById('nomeCompletoInput');
    if (nm) {
        nm.addEventListener('blur', function() {
            gerarEmailInstitucional(nm.value);
        });
    }
    var wa = document.querySelector('input[name="telefone_whatsapp"]');
    if (wa) {
        wa.addEventListener('input', function() { onWhatsappChange(wa); });
        onWhatsappChange(wa);
    }
    var val = document.querySelector('input[name="valor_remuneracao"]');
    if (val) {
        val.addEventListener('input', function() { onValorChange(val); });
        if (val.value) onValorChange(val);
    }
});

function copiarLink() {
    var el = document.getElementById('onboardingLink');
    if (!el) return;
    var t = el.textContent.trim();
    navigator.clipboard.writeText(t).then(function() {
        alert('✓ Link copiado!\n\n' + t);
    }, function() {
        prompt('Copie o link manualmente:', t);
    });
}
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
