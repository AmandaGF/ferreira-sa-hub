<?php
/**
 * Novo Ofício pra empregador — pensão alimentícia.
 * Monta e-mail Modelo 1 (solicitar contato RH) e Modelo 2 (envio com dados bancários)
 * com placeholders substituídos pelos dados do caso.
 * Aceita ?case_id=X pra pré-preencher.
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
if (!has_role('admin','gestao','operacional')) { redirect(url('modules/dashboard/')); }

$pageTitle = 'Novo Ofício — Pensão Alimentícia';
$pdo = db();

// Self-heal: campos extras pra registrar os dados do ofício completo
try { $pdo->exec("ALTER TABLE oficios_enviados ADD COLUMN case_id INT UNSIGNED NULL AFTER client_id"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE oficios_enviados ADD COLUMN empresa_cnpj VARCHAR(20) NULL AFTER empregador"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE oficios_enviados ADD COLUMN rh_email VARCHAR(150) NULL AFTER empresa_cnpj"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE oficios_enviados ADD COLUMN rh_contato VARCHAR(50) NULL AFTER rh_email"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE oficios_enviados ADD COLUMN funcionario_nome VARCHAR(150) NULL AFTER rh_contato"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE oficios_enviados ADD COLUMN funcionario_cargo VARCHAR(100) NULL AFTER funcionario_nome"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE oficios_enviados ADD COLUMN funcionario_matricula VARCHAR(30) NULL AFTER funcionario_cargo"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE oficios_enviados ADD COLUMN funcionario_genero CHAR(1) DEFAULT 'M' AFTER funcionario_matricula COMMENT 'M=masculino, F=feminino'"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE oficios_enviados ADD COLUMN conta_banco VARCHAR(100) NULL AFTER funcionario_matricula"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE oficios_enviados ADD COLUMN conta_agencia VARCHAR(20) NULL AFTER conta_banco"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE oficios_enviados ADD COLUMN conta_numero VARCHAR(30) NULL AFTER conta_agencia"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE oficios_enviados ADD COLUMN conta_titular VARCHAR(150) NULL AFTER conta_numero"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE oficios_enviados ADD COLUMN conta_cpf VARCHAR(20) NULL AFTER conta_titular"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE oficios_enviados ADD COLUMN tipo_oficio VARCHAR(30) NULL DEFAULT 'pensao_empregador' AFTER conta_cpf"); } catch (Exception $e) {}
// Status e linha do tempo
try { $pdo->exec("ALTER TABLE oficios_enviados ADD COLUMN status_oficio VARCHAR(40) DEFAULT 'aguardando_contato_rh' AFTER tipo_oficio COMMENT 'aguardando_contato_rh | oficio_enviado | rh_respondeu | em_cobranca | pensao_implantada | sem_resposta | problema | arquivado'"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE oficios_enviados ADD COLUMN ultima_atividade_em DATETIME DEFAULT NULL AFTER status_oficio"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE oficios_enviados ADD COLUMN alerta_cobranca_em DATETIME DEFAULT NULL AFTER ultima_atividade_em COMMENT 'Última vez que o push de cobrança foi disparado pra este oficio'"); } catch (Exception $e) {}
// Tabela de histórico (linha do tempo)
// Tabela simples — sem FK pra evitar falhas silenciosas em ambientes com charset/engine divergente
try { $pdo->exec("CREATE TABLE IF NOT EXISTS oficios_historico (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    oficio_id INT UNSIGNED NOT NULL,
    tipo VARCHAR(40) NOT NULL,
    descricao TEXT,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_oficio (oficio_id, created_at)
)"); } catch (Exception $e) { @error_log('[oficios] CREATE TABLE: ' . $e->getMessage()); }

// ═══ Endpoint AJAX: adicionar evento ao histórico do ofício ═══
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ajax_action'] ?? '') === 'add_historico') {
    // Blindagem total: qualquer warning/notice PHP não pode vazar pra resposta
    while (ob_get_level() > 0) @ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    try {
        if (!validate_csrf()) { echo json_encode(array('error' => 'CSRF expirado — recarregue a página', 'csrf_expired' => true)); exit; }
        $of = (int)($_POST['oficio_id'] ?? 0);
        $tipoEv = trim($_POST['tipo'] ?? '');
        $desc = trim($_POST['descricao'] ?? '');
        $novoStatus = trim($_POST['novo_status'] ?? '');
        $tiposValidos = array('email_inicial','cobranca','rh_respondeu','oficio_formal','confirmado','pensao_implantada','problema','outro');
        if (!$of) { echo json_encode(array('error' => 'oficio_id ausente')); exit; }
        if (!in_array($tipoEv, $tiposValidos, true)) { echo json_encode(array('error' => 'Tipo inválido: ' . $tipoEv)); exit; }
        $descSave = ($desc === '') ? null : $desc;
        $userId = function_exists('current_user_id') ? current_user_id() : null;

        $pdo->prepare("INSERT INTO oficios_historico (oficio_id, tipo, descricao, created_by) VALUES (?, ?, ?, ?)")
            ->execute(array($of, $tipoEv, $descSave, $userId));

        $pdo->prepare("UPDATE oficios_enviados SET ultima_atividade_em = NOW(), alerta_cobranca_em = NULL" . ($novoStatus ? ", status_oficio = ?" : "") . " WHERE id = ?")
            ->execute($novoStatus ? array($novoStatus, $of) : array($of));

        if (function_exists('audit_log')) {
            try { audit_log('oficio_historico_add', 'oficios', $of, $tipoEv . ($desc ? ': ' . mb_substr($desc, 0, 80) : '')); } catch (Exception $e) {}
        }
        echo json_encode(array('ok' => true));
    } catch (Throwable $e) {
        @error_log('[oficios add_historico] ' . $e->getMessage());
        echo json_encode(array('error' => 'Erro interno: ' . $e->getMessage()));
    }
    exit;
}

// Modo: criar novo OU editar existente
$oficioId = (int)($_GET['id'] ?? 0);
$oficioExistente = null;
if ($oficioId > 0) {
    $st = $pdo->prepare("SELECT * FROM oficios_enviados WHERE id = ?");
    $st->execute(array($oficioId));
    $oficioExistente = $st->fetch();
    if (!$oficioExistente) { flash_set('error', 'Ofício não encontrado.'); redirect(module_url('oficios')); }
    $pageTitle = 'Editar Ofício #' . $oficioId;
}

// Dados do caso (se vier via ?case_id=X ou se for edição de ofício com case_id)
$caseId = (int)($_GET['case_id'] ?? ($oficioExistente['case_id'] ?? 0));
$caso = null; $cliente = null;
if ($caseId > 0) {
    $st = $pdo->prepare(
        "SELECT cs.*, cl.name AS client_name, cl.cpf AS client_cpf, cl.phone AS client_phone
         FROM cases cs LEFT JOIN clients cl ON cl.id = cs.client_id
         WHERE cs.id = ?"
    );
    $st->execute(array($caseId));
    $caso = $st->fetch();
    if ($caso) {
        $cliente = array(
            'name' => $caso['client_name'],
            'cpf' => $caso['client_cpf'],
            'phone' => $caso['client_phone'],
            'id' => $caso['client_id'],
        );
    }
}
// Se edição sem case mas com client_id no ofício: busca cliente
if (!$cliente && !empty($oficioExistente['client_id'])) {
    $st = $pdo->prepare("SELECT id, name, cpf, phone FROM clients WHERE id = ?");
    $st->execute(array($oficioExistente['client_id']));
    $cliente = $st->fetch();
}

// Helper pra pegar valor inicial dos campos (preferindo o ofício existente)
function _of($campo, $oficio, $caso = null, $cliente = null, $default = '') {
    if ($oficio && isset($oficio[$campo]) && $oficio[$campo] !== null && $oficio[$campo] !== '') return $oficio[$campo];
    return $default;
}

// Carrega histórico do ofício (se for edição)
$historicoOficio = array();
if ($oficioExistente) {
    $st = $pdo->prepare(
        "SELECT h.*, u.name AS user_name
         FROM oficios_historico h
         LEFT JOIN users u ON u.id = h.created_by
         WHERE h.oficio_id = ?
         ORDER BY h.created_at DESC, h.id DESC"
    );
    $st->execute(array($oficioId));
    $historicoOficio = $st->fetchAll();
}

// Mapa de status → label e cor
$_statusLabels = array(
    'aguardando_contato_rh' => array('label' => '📮 Aguardando contato do RH', 'cor' => '#f59e0b'),
    'oficio_enviado'        => array('label' => '📬 Ofício formal enviado',    'cor' => '#2563eb'),
    'rh_respondeu'          => array('label' => '💬 RH respondeu',             'cor' => '#0ea5e9'),
    'em_cobranca'           => array('label' => '⏰ Em cobrança',               'cor' => '#d97706'),
    'pensao_implantada'     => array('label' => '✅ Pensão implantada',         'cor' => '#059669'),
    'sem_resposta'          => array('label' => '❌ Sem resposta',              'cor' => '#6b7280'),
    'problema'              => array('label' => '⚠️ Problema',                  'cor' => '#dc2626'),
    'arquivado'             => array('label' => '📁 Arquivado',                 'cor' => '#94a3b8'),
);
$_tipoHistIcons = array(
    'email_inicial'     => '📮',
    'cobranca'          => '⏰',
    'rh_respondeu'      => '💬',
    'oficio_formal'     => '📬',
    'confirmado'        => '🤝',
    'pensao_implantada' => '✅',
    'problema'          => '⚠️',
    'outro'             => '📝',
);
$_tipoHistLabels = array(
    'email_inicial'     => 'E-mail inicial enviado',
    'cobranca'          => 'Cobrança enviada',
    'rh_respondeu'      => 'RH respondeu',
    'oficio_formal'     => 'Ofício formal enviado',
    'confirmado'        => 'RH confirmou recebimento',
    'pensao_implantada' => 'Pensão implantada em folha',
    'problema'          => 'Problema / obstáculo',
    'outro'             => 'Outro evento',
);

// SUBMIT
if ($_SERVER['REQUEST_METHOD'] === 'POST' && validate_csrf()) {
    $dados = array(
        'client_id' => (int)($_POST['client_id'] ?? 0) ?: null,
        'case_id' => (int)($_POST['case_id'] ?? 0) ?: null,
        'numero_processo' => clean_str($_POST['numero_processo'] ?? '', 50),
        'empregador' => clean_str($_POST['empregador'] ?? '', 250),
        'empresa_cnpj' => clean_str($_POST['empresa_cnpj'] ?? '', 20),
        'rh_email' => clean_str($_POST['rh_email'] ?? '', 150),
        'rh_contato' => clean_str($_POST['rh_contato'] ?? '', 50),
        'funcionario_nome' => clean_str($_POST['funcionario_nome'] ?? '', 150),
        'funcionario_cargo' => clean_str($_POST['funcionario_cargo'] ?? '', 100),
        'funcionario_matricula' => clean_str($_POST['funcionario_matricula'] ?? '', 30),
        'funcionario_genero' => in_array(($_POST['funcionario_genero'] ?? 'M'), array('M','F'), true) ? $_POST['funcionario_genero'] : 'M',
        'conta_banco' => clean_str($_POST['conta_banco'] ?? '', 100),
        'conta_agencia' => clean_str($_POST['conta_agencia'] ?? '', 20),
        'conta_numero' => clean_str($_POST['conta_numero'] ?? '', 30),
        'conta_titular' => clean_str($_POST['conta_titular'] ?? '', 150),
        'conta_cpf' => clean_str($_POST['conta_cpf'] ?? '', 20),
        'tipo_oficio' => 'pensao_empregador',
        'data_envio' => $_POST['data_envio'] ?: date('Y-m-d'),
        'plataforma' => clean_str($_POST['plataforma'] ?? 'email', 50),
        'observacoes' => clean_str($_POST['observacoes'] ?? '', 500),
    );
    $idEdicao = (int)($_POST['oficio_id'] ?? 0);
    if ($idEdicao > 0) {
        // UPDATE
        $sets = array(); $vals = array();
        foreach ($dados as $k => $v) { $sets[] = "$k = ?"; $vals[] = $v; }
        $vals[] = $idEdicao;
        $pdo->prepare("UPDATE oficios_enviados SET " . implode(',', $sets) . " WHERE id = ?")->execute($vals);
        audit_log('oficio_editado', 'oficios', $idEdicao, 'Empregador: ' . $dados['empregador']);
        flash_set('success', 'Ofício #' . $idEdicao . ' atualizado!');
        redirect($dados['case_id'] ? module_url('operacional', 'caso_ver.php?id=' . $dados['case_id']) : module_url('oficios'));
    } else {
        // INSERT — já marca status inicial + ultima_atividade
        $dados['status_oficio'] = 'aguardando_contato_rh';
        $dados['ultima_atividade_em'] = date('Y-m-d H:i:s');
        $sql = "INSERT INTO oficios_enviados (" . implode(',', array_keys($dados)) . ") VALUES (" . implode(',', array_fill(0, count($dados), '?')) . ")";
        $pdo->prepare($sql)->execute(array_values($dados));
        $oficioId = (int)$pdo->lastInsertId();

        // Primeiro evento do histórico
        try {
            $pdo->prepare("INSERT INTO oficios_historico (oficio_id, tipo, descricao, created_by) VALUES (?, 'email_inicial', ?, ?)")
                ->execute(array($oficioId, 'Ofício cadastrado — e-mail inicial enviado ao empregador ' . $dados['empregador'], current_user_id()));
        } catch (Exception $e) {}

        audit_log('oficio_pensao_registrado', 'oficios', $oficioId, 'Empregador: ' . $dados['empregador']);

        // Andamento automático no processo — somente infos NÃO sensíveis
        // (sem e-mail/telefone do RH, sem CPF do titular, sem dados bancários)
        if ($dados['case_id']) {
            try {
                $linhas = array();
                $linhas[] = '📬 Ofício #' . $oficioId . ' enviado ao empregador — desconto de pensão em folha';
                $linhas[] = '• Empresa: ' . $dados['empregador'] . ($dados['empresa_cnpj'] ? ' (CNPJ ' . $dados['empresa_cnpj'] . ')' : '');
                if (!empty($dados['funcionario_nome'])) {
                    $linhas[] = '• Funcionário: ' . $dados['funcionario_nome']
                        . ($dados['funcionario_cargo'] ? ' — ' . $dados['funcionario_cargo'] : '')
                        . ($dados['funcionario_matricula'] ? ' (matrícula ' . $dados['funcionario_matricula'] . ')' : '');
                }
                $linhas[] = '• Forma de envio: ' . strtoupper($dados['plataforma'] ?: 'email');
                $linhas[] = '• Data do envio: ' . date('d/m/Y', strtotime($dados['data_envio']));
                if (!empty($dados['observacoes'])) $linhas[] = '• Obs: ' . $dados['observacoes'];
                $desc = implode("\n", $linhas);
                $pdo->prepare(
                    "INSERT INTO case_andamentos (case_id, data_andamento, tipo, descricao, created_by, visivel_cliente, created_at) VALUES (?, ?, 'oficio', ?, ?, 0, NOW())"
                )->execute(array($dados['case_id'], $dados['data_envio'], $desc, current_user_id()));
            } catch (Exception $e) {}
        }
        flash_set('success', 'Ofício #' . $oficioId . ' registrado!');
        redirect($dados['case_id'] ? module_url('operacional', 'caso_ver.php?id=' . $dados['case_id']) : module_url('oficios'));
    }
}

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.of-grid { display:grid;grid-template-columns:1fr 1fr;gap:.65rem; }
.of-sec h4 { font-size:.82rem;font-weight:800;color:var(--petrol-900);margin:1rem 0 .5rem;padding-bottom:.25rem;border-bottom:2px solid rgba(184,115,51,.3); }
.of-lab { font-size:.68rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.3px;display:block;margin-bottom:.2rem; }
.of-inp { width:100%;padding:.5rem .65rem;font-size:.85rem;border:1.5px solid #e5e7eb;border-radius:8px;font-family:inherit; }
.of-tpl { background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:.85rem 1rem;margin-bottom:.6rem; }
.of-tpl-head { display:flex;justify-content:space-between;align-items:center;margin-bottom:.4rem; }
.of-tpl-title { font-size:.82rem;font-weight:800;color:var(--petrol-900); }
.of-tpl textarea { width:100%;min-height:170px;padding:.6rem .7rem;font-size:.78rem;border:1.5px solid #e5e7eb;border-radius:8px;font-family:Consolas,monospace;line-height:1.45;background:#fff; }
.of-btn-copy { background:#052228;color:#fff;border:none;padding:4px 10px;border-radius:6px;font-size:.68rem;font-weight:700;cursor:pointer; }
.of-btn-wa { background:#25D366;color:#fff;border:none;padding:4px 10px;border-radius:6px;font-size:.68rem;font-weight:700;cursor:pointer; }
@media (max-width:700px) { .of-grid { grid-template-columns:1fr; } }
</style>

<a href="<?= $caseId ? module_url('operacional','caso_ver.php?id='.$caseId) : module_url('oficios') ?>" class="btn btn-outline btn-sm">&larr; Voltar</a>

<h2 style="margin:.75rem 0 .25rem;font-size:1.2rem;color:var(--petrol-900);">📬 <?= $oficioExistente ? 'Ofício #' . $oficioId : 'Ofício ao empregador — Pensão alimentícia' ?></h2>
<p style="font-size:.85rem;color:var(--text-muted);margin:0 0 1rem;">
    <?php if ($oficioExistente): ?>Ajuste os dados abaixo. Ao salvar, os templates refletem as mudanças e você pode copiar e reenviar se precisar.<?php else: ?>Preencha os dados do funcionário e da empresa. O sistema gera o e-mail pronto nos 2 modelos oficiais — você copia e envia pelo seu Gmail/Outlook.<?php endif; ?>
    <?php if ($caso): ?><br>Processo: <b><?= e($caso['title'] ?: 'Caso #' . $caseId) ?></b><?= $caso['case_number'] ? ' · ' . e($caso['case_number']) : '' ?><?php endif; ?>
</p>

<?php if ($oficioExistente):
    $_st = $oficioExistente['status_oficio'] ?? 'aguardando_contato_rh';
    $_stMeta = $_statusLabels[$_st] ?? array('label' => $_st, 'cor' => '#6b7280');
?>
<div style="max-width:960px;background:#fff;border:1px solid var(--border);border-radius:10px;padding:1rem 1.25rem;margin-bottom:1rem;">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.5rem;margin-bottom:.75rem;">
        <h3 style="margin:0;font-size:.95rem;color:var(--petrol-900);">🕐 Linha do tempo</h3>
        <div>
            <span style="font-size:.7rem;color:var(--text-muted);">Status atual:</span>
            <span style="background:<?= e($_stMeta['cor']) ?>;color:#fff;padding:3px 10px;border-radius:12px;font-size:.72rem;font-weight:700;"><?= e($_stMeta['label']) ?></span>
        </div>
    </div>

    <!-- Form compacto pra adicionar novo evento -->
    <div style="background:#f9fafb;border:1px dashed #d1d5db;border-radius:8px;padding:.7rem .85rem;margin-bottom:1rem;">
        <div style="display:grid;grid-template-columns:200px 1fr 200px auto;gap:.5rem;align-items:end;">
            <div>
                <label style="font-size:.65rem;font-weight:700;color:#6b7280;text-transform:uppercase;display:block;margin-bottom:.2rem;">Tipo do evento</label>
                <select id="histTipo" class="of-inp" style="padding:.4rem .55rem;font-size:.8rem;">
                    <?php foreach ($_tipoHistLabels as $k => $lbl): ?>
                        <option value="<?= e($k) ?>"><?= $_tipoHistIcons[$k] ?? '📝' ?> <?= e($lbl) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label style="font-size:.65rem;font-weight:700;color:#6b7280;text-transform:uppercase;display:block;margin-bottom:.2rem;">O que aconteceu</label>
                <input type="text" id="histDesc" class="of-inp" placeholder="Ex: cobramos resposta por e-mail" style="padding:.4rem .55rem;font-size:.8rem;">
            </div>
            <div>
                <label style="font-size:.65rem;font-weight:700;color:#6b7280;text-transform:uppercase;display:block;margin-bottom:.2rem;">Atualizar status (opcional)</label>
                <select id="histNovoStatus" class="of-inp" style="padding:.4rem .55rem;font-size:.8rem;">
                    <option value="">— Manter status atual —</option>
                    <?php foreach ($_statusLabels as $k => $meta): ?>
                        <option value="<?= e($k) ?>"><?= e($meta['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="button" onclick="addHistorico()" class="btn btn-primary btn-sm" style="background:#B87333;font-size:.78rem;padding:.5rem 1rem;">+ Adicionar</button>
        </div>
    </div>

    <!-- Lista do histórico -->
    <?php if (empty($historicoOficio)): ?>
        <div style="text-align:center;color:var(--text-muted);padding:1rem;font-size:.8rem;">Nenhum evento registrado ainda. Use o formulário acima pra começar a registrar a linha do tempo.</div>
    <?php else: ?>
        <div style="position:relative;padding-left:1.5rem;">
            <div style="position:absolute;left:.5rem;top:.5rem;bottom:.5rem;width:2px;background:#e5e7eb;"></div>
            <?php foreach ($historicoOficio as $h):
                $_ico = $_tipoHistIcons[$h['tipo']] ?? '📝';
                $_lbl = $_tipoHistLabels[$h['tipo']] ?? $h['tipo'];
            ?>
            <div style="position:relative;margin-bottom:.75rem;">
                <div style="position:absolute;left:-1.25rem;top:.15rem;background:#fff;border:2px solid #B87333;border-radius:50%;width:16px;height:16px;display:flex;align-items:center;justify-content:center;font-size:.7rem;"><?= $_ico ?></div>
                <div style="background:#fff;border:1px solid #e5e7eb;border-radius:6px;padding:.5rem .75rem;">
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:.5rem;flex-wrap:wrap;">
                        <span style="font-weight:700;font-size:.82rem;color:var(--petrol-900);"><?= e($_lbl) ?></span>
                        <span style="font-size:.68rem;color:var(--text-muted);"><?= date('d/m/Y H:i', strtotime($h['created_at'])) ?><?= $h['user_name'] ? ' · ' . e(explode(' ', $h['user_name'])[0]) : '' ?></span>
                    </div>
                    <?php if ($h['descricao']): ?>
                        <div style="font-size:.8rem;color:#374151;margin-top:.2rem;white-space:pre-wrap;"><?= e($h['descricao']) ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<form method="POST" style="max-width:960px;">
    <?= csrf_input() ?>
    <?php if ($oficioExistente): ?>
        <input type="hidden" name="oficio_id" value="<?= (int)$oficioId ?>">
    <?php endif; ?>
    <input type="hidden" name="client_id" value="<?= (int)($cliente['id'] ?? $oficioExistente['client_id'] ?? 0) ?>">
    <input type="hidden" name="case_id" value="<?= (int)$caseId ?>">

    <div class="of-sec">
        <h4>🏢 Empresa (empregadora)</h4>
        <div class="of-grid">
            <div><span class="of-lab">Razão social / nome fantasia *</span><input type="text" name="empregador" id="empregador" class="of-inp" required placeholder="Ex: Empresa X Ltda" value="<?= e($oficioExistente['empregador'] ?? '') ?>" oninput="atualizarPreviews()"></div>
            <div><span class="of-lab">CNPJ</span><input type="text" name="empresa_cnpj" id="empresa_cnpj" class="of-inp" placeholder="00.000.000/0000-00" value="<?= e($oficioExistente['empresa_cnpj'] ?? '') ?>" oninput="atualizarPreviews()"></div>
            <div><span class="of-lab">E-mail do RH</span><input type="email" name="rh_email" id="rh_email" class="of-inp" placeholder="rh@empresa.com.br" value="<?= e($oficioExistente['rh_email'] ?? '') ?>" oninput="atualizarPreviews()"></div>
            <div><span class="of-lab">WhatsApp/telefone do RH</span><input type="text" name="rh_contato" id="rh_contato" class="of-inp" placeholder="(24) 99999-0000" value="<?= e($oficioExistente['rh_contato'] ?? '') ?>" oninput="atualizarPreviews()"></div>
        </div>
    </div>

    <div class="of-sec">
        <h4>👤 Funcionário (alimentante)</h4>
        <div class="of-grid">
            <div><span class="of-lab">Nome</span><input type="text" name="funcionario_nome" id="funcionario_nome" class="of-inp" placeholder="Nome completo do funcionário" value="<?= e($oficioExistente['funcionario_nome'] ?? '') ?>" oninput="atualizarPreviews()"></div>
            <div><span class="of-lab">Sexo / Gênero</span><select name="funcionario_genero" id="funcionario_genero" class="of-inp" onchange="atualizarPreviews()">
                <?php $_fg = $oficioExistente['funcionario_genero'] ?? 'M'; ?>
                <option value="M" <?= $_fg === 'M' ? 'selected' : '' ?>>Masculino (pai/genitor/colaborador)</option>
                <option value="F" <?= $_fg === 'F' ? 'selected' : '' ?>>Feminino (mãe/genitora/colaboradora)</option>
            </select></div>
            <div><span class="of-lab">Cargo</span><input type="text" name="funcionario_cargo" id="funcionario_cargo" class="of-inp" value="<?= e($oficioExistente['funcionario_cargo'] ?? '') ?>" oninput="atualizarPreviews()"></div>
            <div><span class="of-lab">Matrícula</span><input type="text" name="funcionario_matricula" id="funcionario_matricula" class="of-inp" value="<?= e($oficioExistente['funcionario_matricula'] ?? '') ?>" oninput="atualizarPreviews()"></div>
        </div>
    </div>

    <div class="of-sec">
        <h4>🏦 Dados para depósito (representante legal)</h4>
        <div class="of-grid">
            <div><span class="of-lab">Titular da conta</span><input type="text" name="conta_titular" id="conta_titular" class="of-inp" value="<?= e($oficioExistente['conta_titular'] ?? $cliente['name'] ?? '') ?>" oninput="atualizarPreviews()"></div>
            <div><span class="of-lab">CPF titular</span><input type="text" name="conta_cpf" id="conta_cpf" class="of-inp" value="<?= e($oficioExistente['conta_cpf'] ?? $cliente['cpf'] ?? '') ?>" oninput="atualizarPreviews()"></div>
            <div><span class="of-lab">Banco</span><input type="text" name="conta_banco" id="conta_banco" class="of-inp" placeholder="Ex: Itaú (341)" value="<?= e($oficioExistente['conta_banco'] ?? '') ?>" oninput="atualizarPreviews()"></div>
            <div><span class="of-lab">Agência</span><input type="text" name="conta_agencia" id="conta_agencia" class="of-inp" value="<?= e($oficioExistente['conta_agencia'] ?? '') ?>" oninput="atualizarPreviews()"></div>
            <div><span class="of-lab">Conta</span><input type="text" name="conta_numero" id="conta_numero" class="of-inp" placeholder="00000-0" value="<?= e($oficioExistente['conta_numero'] ?? '') ?>" oninput="atualizarPreviews()"></div>
            <div><span class="of-lab">Número do processo</span><input type="text" name="numero_processo" id="numero_processo" class="of-inp" value="<?= e($oficioExistente['numero_processo'] ?? $caso['case_number'] ?? '') ?>" oninput="atualizarPreviews()"></div>
        </div>
    </div>

    <div class="of-sec">
        <h4>✉️ E-mail — Modelo 1 (1º contato: pedir e-mail do setor RH)</h4>
        <div class="of-tpl">
            <div class="of-tpl-head">
                <span class="of-tpl-title">Texto pronto pra copiar e colar no Gmail/Outlook</span>
                <span>
                    <button type="button" class="of-btn-copy" onclick="copiarTpl('tplEmail1')">📋 Copiar</button>
                </span>
            </div>
            <textarea id="tplEmail1" readonly></textarea>
        </div>
    </div>

    <div class="of-sec">
        <h4>✉️ E-mail — Modelo 2 (envio formal com dados bancários)</h4>
        <div class="of-tpl">
            <div class="of-tpl-head">
                <span class="of-tpl-title">Envie depois que o RH confirmar o e-mail do setor</span>
                <span>
                    <button type="button" class="of-btn-copy" onclick="copiarTpl('tplEmail2')">📋 Copiar</button>
                </span>
            </div>
            <textarea id="tplEmail2" readonly></textarea>
        </div>
    </div>

    <div class="of-sec">
        <h4>💬 Mensagem WhatsApp (caso o RH só atenda por WhatsApp)</h4>
        <div class="of-tpl">
            <div class="of-tpl-head">
                <span class="of-tpl-title">Texto curto pra abrir a conversa</span>
                <span>
                    <button type="button" class="of-btn-copy" onclick="copiarTpl('tplWa')">📋 Copiar</button>
                    <button type="button" class="of-btn-wa" onclick="enviarWa()">📱 Enviar via Hub</button>
                </span>
            </div>
            <textarea id="tplWa" readonly style="min-height:120px;"></textarea>
        </div>
    </div>

    <div class="of-sec">
        <h4>📝 Registro interno</h4>
        <div class="of-grid">
            <div><span class="of-lab">Data do envio</span><input type="date" name="data_envio" class="of-inp" value="<?= e($oficioExistente['data_envio'] ?? date('Y-m-d')) ?>"></div>
            <div><span class="of-lab">Plataforma</span><select name="plataforma" class="of-inp">
                <?php $_pl = $oficioExistente['plataforma'] ?? 'email'; ?>
                <option value="email" <?= $_pl === 'email' ? 'selected' : '' ?>>E-mail</option>
                <option value="whatsapp" <?= $_pl === 'whatsapp' ? 'selected' : '' ?>>WhatsApp</option>
                <option value="correio" <?= $_pl === 'correio' ? 'selected' : '' ?>>Correios</option>
                <option value="outro" <?= $_pl === 'outro' ? 'selected' : '' ?>>Outro</option>
            </select></div>
        </div>
        <div style="margin-top:.5rem;"><span class="of-lab">Observações</span><textarea name="observacoes" class="of-inp" rows="2"><?= e($oficioExistente['observacoes'] ?? '') ?></textarea></div>
    </div>

    <div style="display:flex;gap:.5rem;justify-content:flex-end;margin-top:1.5rem;padding-top:1rem;border-top:1px solid var(--border);">
        <a href="<?= $caseId ? module_url('operacional','caso_ver.php?id='.$caseId) : module_url('oficios') ?>" class="btn btn-outline btn-sm">Cancelar</a>
        <button type="submit" class="btn btn-primary btn-sm" style="background:#B87333;"><?= $oficioExistente ? '💾 Salvar alterações' : '✓ Registrar envio do ofício' ?></button>
    </div>
</form>

<script>
<?php
// Detecta gênero do usuário logado pra flexionar os templates corretamente
// Prioridade: coluna users.genero (se existir) → heurística (primeiro nome termina em 'a' = feminino)
try { $pdo->exec("ALTER TABLE users ADD COLUMN genero CHAR(1) DEFAULT NULL COMMENT 'M=masculino, F=feminino'"); } catch (Exception $e) {}
$_userRow = current_user();
$_userGenero = $_userRow['genero'] ?? null;
if (!$_userGenero) {
    $_primeiro = strtolower(explode(' ', trim($_userRow['name'] ?? ''))[0] ?? '');
    // Heurística simples: termina em 'a' ou sufixos comuns femininos → F; caso contrário M
    $_userGenero = (preg_match('/a$|ce$/', $_primeiro)) ? 'F' : 'M';
    // Exceções comuns masculinas terminadas em 'a'
    if (in_array($_primeiro, array('luca','joshua','jeremias','elias','tobias','matias','zacarias'), true)) $_userGenero = 'M';
}
?>
var userNome = <?= json_encode($_userRow['name'] ?? 'Amanda Ferreira') ?>;
var userGenero = <?= json_encode($_userGenero) ?>; // 'F' ou 'M'
var _T = userGenero === 'F'
    ? { advg:'advogada', inscr:'inscrita', atu:'atuando' }
    : { advg:'advogado', inscr:'inscrito', atu:'atuando' };
var userOAB = <?= json_encode(current_user()['oab'] ?? 'OAB-RJ 163.260') ?>;
var userTel = <?= json_encode(current_user()['phone'] ?? '(24) 99205-0096') ?>;
var casoInfo = <?= json_encode(array('client_name' => $cliente['name'] ?? '', 'phone' => $cliente['phone'] ?? '', 'client_id' => $cliente['id'] ?? 0)) ?>;

function atualizarPreviews() {
    var emp    = (document.getElementById('empregador').value || '').trim();
    var cnpj   = (document.getElementById('empresa_cnpj').value || '').trim();
    var func   = (document.getElementById('funcionario_nome').value || '[NOME DO COLABORADOR]').trim();
    var cargo  = (document.getElementById('funcionario_cargo').value || '[CARGO]').trim();
    var matr   = (document.getElementById('funcionario_matricula').value || '[MATRÍCULA]').trim();
    var banco  = (document.getElementById('conta_banco').value || '[BANCO]').trim();
    var ag     = (document.getElementById('conta_agencia').value || '[AGÊNCIA]').trim();
    var cc     = (document.getElementById('conta_numero').value || '[CONTA]').trim();
    var tit    = (document.getElementById('conta_titular').value || '[TITULAR]').trim();
    var cpf    = (document.getElementById('conta_cpf').value || '[CPF]').trim();
    var numProc = ((document.getElementById('numero_processo') || {}).value || '').trim();
    var funcG = (document.getElementById('funcionario_genero') || {}).value || 'M';
    // Flexões do funcionário (alimentante): F=feminino, M=masculino
    var _F = funcG === 'F'
        ? { um:'uma',  genitor:'genitora',  colab:'colaboradora', do:'da', oa:'a' }
        : { um:'um',   genitor:'genitor',   colab:'colaborador',  do:'do', oa:'o' };
    // Prefixo obrigatório do assunto: sempre começa com Ref: + nº do processo
    var refPref = 'Ref: processo nº ' + (numProc || '[Nº DO PROCESSO]') + ' — ';

    // Modelo 1 — solicitar contato RH
    var m1 = 'Assunto: ' + refPref + 'Pensão alimentícia — solicitação de contato do RH' + (emp ? ' — ' + emp : '') + '\n\n'
           + 'Prezados, boa tarde!\n\n'
           + 'Meu nome é ' + userNome + ', ' + _T.advg + ' ' + _T.inscr + ' na ' + userOAB + ', e estou atuando em processo de fixação de pensão alimentícia em que ' + _F.um + ' de seus ' + _F.colab + 'es é ' + _F.genitor + ' da criança.\n\n'
           + 'Informo que há decisão judicial determinando o desconto da pensão alimentícia diretamente na folha de pagamento ' + _F.do + ' ' + _F.colab + ' ' + func
           + (cargo !== '[CARGO]' ? ', cargo ' + cargo : '') + (matr !== '[MATRÍCULA]' ? ', matrícula ' + matr : '') + '. '
           + 'Para formalizar a medida, necessito encaminhar o ofício diretamente ao setor de Recursos Humanos.\n\n'
           + 'Assim, solicito, gentilmente, que me informe o endereço de e-mail ou contato do setor responsável, a fim de enviar o referido ofício e cumprir a determinação judicial.\n\n'
           + 'Desde já, agradeço pela atenção e colaboração. Fico à disposição para quaisquer esclarecimentos.\n\n'
           + 'Atenciosamente,\n' + userNome + '\n' + userOAB + '\n' + userTel;

    // Modelo 2 — envio com dados bancários
    var m2 = 'Assunto: ' + refPref + 'Ofício — Desconto de pensão alimentícia em folha' + (func !== '[NOME DO COLABORADOR]' ? ' — ' + func : '') + '\n\n'
           + 'Prezados, bom dia!\n\n'
           + 'Meu nome é ' + userNome + ', ' + _T.advg + ' ' + _T.inscr + ' na ' + userOAB + ', e estou atuando em processo de fixação de pensão alimentícia.\n\n'
           + 'Envio, em anexo, a decisão judicial determinando o desconto da pensão alimentícia diretamente na folha de pagamento ' + _F.do + ' ' + _F.colab + ':\n\n'
           + '• Nome: ' + func + '\n'
           + '• Cargo: ' + cargo + '\n'
           + '• Matrícula: ' + matr + '\n\n'
           + 'DADOS PARA DEPÓSITO:\n'
           + '• Banco: ' + banco + '\n'
           + '• Agência: ' + ag + '\n'
           + '• Conta corrente: ' + cc + '\n'
           + '• Titular: ' + tit + '\n'
           + '• CPF do titular: ' + cpf + '\n\n'
           + 'Solicito, gentilmente, a confirmação do recebimento para que possamos informar ao juízo sobre o cumprimento da decisão.\n\n'
           + 'Desde já, agradeço pela atenção e colaboração. Fico à disposição para quaisquer esclarecimentos.\n\n'
           + 'Atenciosamente,\n' + userNome + '\n' + userOAB + '\n' + userTel;

    // WhatsApp — curto e respeitoso
    var wa = 'Olá! Boa tarde.\n\n'
           + 'Sou ' + userNome + ', ' + _T.advg + ' ' + userOAB + '. Estou tratando de um processo de pensão alimentícia envolvendo ' + _F.um + ' ' + _F.colab + ' de vocês'
           + (emp ? ' da ' + emp : '') + '.\n\n'
           + 'Preciso enviar um ofício ao setor de RH para desconto em folha de pagamento. Poderia me informar o e-mail do setor responsável?\n\n'
           + 'Agradeço desde já. 🙏';

    document.getElementById('tplEmail1').value = m1;
    document.getElementById('tplEmail2').value = m2;
    document.getElementById('tplWa').value = wa;
}
atualizarPreviews();

function copiarTpl(id) {
    var el = document.getElementById(id);
    el.select(); el.setSelectionRange(0, 99999);
    try {
        document.execCommand('copy');
        if (navigator.clipboard) navigator.clipboard.writeText(el.value);
        var btn = event.target;
        var orig = btn.textContent;
        btn.textContent = '✓ Copiado!';
        btn.style.background = '#059669';
        setTimeout(function(){ btn.textContent = orig; btn.style.background = '#052228'; }, 2000);
    } catch(e) { alert('Erro ao copiar: ' + e.message); }
}

function addHistorico() {
    var oficioId = <?= (int)($oficioId ?? 0) ?>;
    if (!oficioId) { alert('Salve o ofício primeiro antes de adicionar eventos ao histórico.'); return; }
    var tipo = document.getElementById('histTipo').value;
    var desc = document.getElementById('histDesc').value.trim();
    var novoStatus = document.getElementById('histNovoStatus').value;
    if (!tipo) { alert('Escolha o tipo do evento.'); return; }

    var fd = new FormData();
    fd.append('ajax_action', 'add_historico');
    fd.append('oficio_id', oficioId);
    fd.append('tipo', tipo);
    fd.append('descricao', desc);
    if (novoStatus) fd.append('novo_status', novoStatus);
    fd.append('csrf_token', <?= json_encode(generate_csrf_token()) ?>);

    fetch(window.location.href, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function(r){ return r.json(); })
        .then(function(j){
            if (j.csrf_expired) { alert('Sessão expirou. Recarregue a página.'); return; }
            if (j.error) { alert('Erro: ' + j.error); return; }
            if (j.ok) location.reload();
        })
        .catch(function(e){ alert('Erro: ' + e.message); });
}

function enviarWa() {
    var numero = (document.getElementById('rh_contato').value || '').replace(/\D/g, '');
    if (!numero) { alert('Informe o WhatsApp do RH no campo acima.'); return; }
    var msg = document.getElementById('tplWa').value;
    // Se tem waSenderOpen (Hub integrado), usa; senão abre wa.me
    if (window.waSenderOpen) {
        waSenderOpen({
            telefone: numero,
            nome: document.getElementById('empregador').value || 'RH',
            canal: '24',
            mensagem: msg
        });
    } else {
        window.open('https://wa.me/55' + numero + '?text=' + encodeURIComponent(msg), '_blank');
    }
}
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
