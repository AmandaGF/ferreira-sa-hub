<?php
/**
 * Ferreira & Sá Hub — Onboarding de Colaboradores
 * Cadastro de novos colaboradores + geração de link único de boas-vindas.
 * Acesso: SOMENTE admin
 */

require_once __DIR__ . '/../../core/middleware.php';
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
        $st = $pdo->prepare("SELECT nome_completo, data_nascimento, cpf, email_institucional, cargo, setor
                             FROM colaboradores_onboarding
                             WHERE nome_completo LIKE ? AND status != 'arquivado'
                             ORDER BY nome_completo LIMIT 5");
        $st->execute(array($like));
        foreach ($st->fetchAll() as $r) {
            $resultados[] = array(
                'fonte' => 'onboarding',
                'nome'  => $r['nome_completo'],
                'data_nascimento' => $r['data_nascimento'],
                'cpf'   => $r['cpf'] ?: '',
                'email' => $r['email_institucional'] ?: '',
                'cargo' => $r['cargo'] ?: '',
                'setor' => $r['setor'] ?: '',
            );
        }
    } catch (Exception $e) {}

    // Clients (ex-cliente que virou colaborador)
    try {
        $st = $pdo->prepare("SELECT name, birth_date, cpf, email
                             FROM clients
                             WHERE name LIKE ?
                             ORDER BY name LIMIT 5");
        $st->execute(array($like));
        foreach ($st->fetchAll() as $r) {
            $resultados[] = array(
                'fonte' => 'cliente',
                'nome'  => $r['name'],
                'data_nascimento' => $r['birth_date'],
                'cpf'   => $r['cpf'] ?: '',
                'email' => $r['email'] ?: '',
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

/**
 * Gera senha padrao do escritorio: 9 primeiros digitos do CPF + "@".
 * Ex: CPF 123.456.789-00 → "123456789@"
 */
function gerar_senha_padrao_fsa($cpf) {
    $digits = preg_replace('/\D/', '', (string)$cpf);
    if (strlen($digits) < 9) return '';
    return substr($digits, 0, 9) . '@';
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
        );

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
                    flash_set('success', 'Cadastro atualizado.');
                } else {
                    $token = bin2hex(random_bytes(16));
                    $cols = array_merge(array_keys($dados), array('token', 'created_by'));
                    $place = implode(',', array_fill(0, count($cols), '?'));
                    $vals = array_merge(array_values($dados), array($token, current_user_id()));
                    $pdo->prepare("INSERT INTO colaboradores_onboarding (" . implode(',', $cols) . ") VALUES ($place)")
                        ->execute($vals);
                    $newId = $pdo->lastInsertId();
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
if ($editId) {
    $st = $pdo->prepare("SELECT * FROM colaboradores_onboarding WHERE id = ?");
    $st->execute(array($editId));
    $reg = $st->fetch();
}

// ── Lista todos pendentes/ativos ─────────────────────────
$lista = array();
try {
    $lista = $pdo->query("SELECT id, nome_completo, email_institucional, status, aceite_em, created_at, token
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
                <input name="nome_completo" id="nomeCompletoInput" required value="<?= e($reg['nome_completo'] ?? '') ?>" placeholder="Ex: Maria Silva Santos" autocomplete="off" oninput="onNomeChange(this.value)">
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
                <label>Cargo / Função</label>
                <input name="cargo" value="<?= e($reg['cargo'] ?? '') ?>" placeholder="Ex: Estagiária">
            </div>
            <div>
                <label>Setor / Área de atuação</label>
                <input name="setor" value="<?= e($reg['setor'] ?? '') ?>" placeholder="Ex: Família e Sucessões">
            </div>
        </div>

        <h4 style="font-size:.85rem;color:#6a3c2c;margin:1.2rem 0 .5rem;">📧 Acesso institucional</h4>
        <div class="ob-grid">
            <div>
                <label>E-mail institucional</label>
                <input name="email_institucional" type="email" value="<?= e($reg['email_institucional'] ?? '') ?>" placeholder="nome@ferreiraesa.com.br">
            </div>
            <div>
                <label>Senha inicial <span style="color:#6a3c2c;font-size:.7rem;font-weight:400;">(auto-preenchida pelo CPF)</span></label>
                <input name="senha_inicial" id="senhaInicialInput" value="<?= e($reg['senha_inicial'] ?? '') ?>" placeholder="Preenche sozinha pelo CPF (9 dígitos + @)">
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
                    <td><?= e(data_hora_br($r['created_at'])) ?></td>
                    <td><?= $r['aceite_em'] ? '✓ ' . e(data_hora_br($r['aceite_em'])) : '—' ?></td>
                    <td style="white-space:nowrap;">
                        <a class="btn btn-outline btn-sm" href="<?= module_url('admin', 'onboarding.php?id=' . (int)$r['id']) ?>">✏️ Editar</a>
                        <a class="btn btn-outline btn-sm" target="_blank" href="<?= e($urlPublica($r['token'])) ?>">👁 Ver página</a>
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
    if (p.email) {
        var em = document.querySelector('input[name="email_institucional"]');
        if (em && !em.value) em.value = p.email;
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
    // Auto-gera senha (9 primeiros digitos + @) se a senha estiver vazia OU
    // se a senha atual seguir o padrao auto-gerado (entao admin nao mexeu manualmente)
    var senha = document.getElementById('senhaInicialInput');
    if (senha && v.length >= 9) {
        var nova = v.slice(0, 9) + '@';
        var atual = senha.value.trim();
        if (atual === '' || /^\d{9}@$/.test(atual)) {
            senha.value = nova;
        }
    }
}
// Roda no load tambem caso CPF ja esteja preenchido
document.addEventListener('DOMContentLoaded', onCpfChange);

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
