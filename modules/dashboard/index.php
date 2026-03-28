<?php
/**
 * Ferreira & Sá Hub — Dashboard Principal
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();

$pageTitle = 'Dashboard';
$user = current_user();
$role = current_user_role();

// ─── KPIs ───────────────────────────────────────────────
$pdo = db();

// Tickets abertos (helpdesk)
$ticketsAbertos = 0;
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM tickets WHERE status IN ('aberto','em_andamento','aguardando')");
    $ticketsAbertos = (int)$stmt->fetchColumn();
} catch (Exception $e) {}

// Clientes cadastrados
$totalClientes = 0;
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM clients");
    $totalClientes = (int)$stmt->fetchColumn();
} catch (Exception $e) {}

// Leads no pipeline
$leadsAtivos = 0;
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM pipeline_leads WHERE stage NOT IN ('contrato','perdido')");
    $leadsAtivos = (int)$stmt->fetchColumn();
} catch (Exception $e) {}

// Casos ativos
$casosAtivos = 0;
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM cases WHERE status = 'ativo'");
    $casosAtivos = (int)$stmt->fetchColumn();
} catch (Exception $e) {}

// Formulários pendentes
$formsPendentes = 0;
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM form_submissions WHERE status = 'novo'");
    $formsPendentes = (int)$stmt->fetchColumn();
} catch (Exception $e) {}

// Tickets urgentes do usuário
$meusUrgentes = 0;
try {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM tickets t
         LEFT JOIN ticket_assignees ta ON ta.ticket_id = t.id
         WHERE t.priority = 'urgente'
         AND t.status IN ('aberto','em_andamento')
         AND (t.requester_id = ? OR ta.user_id = ?)"
    );
    $stmt->execute([$user['id'], $user['id']]);
    $meusUrgentes = (int)$stmt->fetchColumn();
} catch (Exception $e) {}

// Frases motivacionais
$frases = [
    'A excelência não é um ato, mas um hábito.',
    'O sucesso é a soma de pequenos esforços repetidos dia após dia.',
    'Determinação de hoje, resultados de amanhã.',
    'Cada cliente é uma oportunidade de fazer a diferença.',
    'A justiça é o primeiro dever da sociedade.',
    'Trabalho em equipe divide a tarefa e multiplica o resultado.',
    'O direito é a arte do bom e do justo.',
    'Precisão nos detalhes, excelência no resultado.',
];
$fraseHoje = $frases[array_rand($frases)];

require_once APP_ROOT . '/templates/layout_start.php';
?>

<!-- Boas-vindas -->
<div class="card mb-3" style="background: linear-gradient(135deg, var(--petrol-900), var(--petrol-500)); color: #fff; border: none;">
    <div class="card-body" style="padding: 2rem;">
        <h2 style="font-size: 1.3rem; font-weight: 800; margin-bottom: .35rem;">
            Olá, <?= e(explode(' ', $user['name'])[0]) ?>! 👋
        </h2>
        <p style="color: var(--rose); font-size: .9rem; margin-bottom: .5rem;">
            <?= e(role_label($role)) ?> — <?= date('d/m/Y') ?>
        </p>
        <p style="color: rgba(255,255,255,.7); font-size: .85rem; font-style: italic;">
            "<?= e($fraseHoje) ?>"
        </p>
    </div>
</div>

<!-- Stats -->
<div class="stats-grid">
    <?php if (has_min_role('gestao')): ?>
    <div class="stat-card">
        <div class="stat-icon petrol">👥</div>
        <div class="stat-info">
            <div class="stat-value"><?= $totalClientes ?></div>
            <div class="stat-label">Clientes</div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon rose">📈</div>
        <div class="stat-info">
            <div class="stat-value"><?= $leadsAtivos ?></div>
            <div class="stat-label">Leads ativos</div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon success">⚙️</div>
        <div class="stat-info">
            <div class="stat-value"><?= $casosAtivos ?></div>
            <div class="stat-label">Casos ativos</div>
        </div>
    </div>
    <?php endif; ?>

    <div class="stat-card">
        <div class="stat-icon info">🎫</div>
        <div class="stat-info">
            <div class="stat-value"><?= $ticketsAbertos ?></div>
            <div class="stat-label">Tickets abertos</div>
        </div>
    </div>

    <?php if ($meusUrgentes > 0): ?>
    <div class="stat-card">
        <div class="stat-icon danger">🔴</div>
        <div class="stat-info">
            <div class="stat-value"><?= $meusUrgentes ?></div>
            <div class="stat-label">Seus tickets urgentes</div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (has_min_role('gestao') && $formsPendentes > 0): ?>
    <div class="stat-card">
        <div class="stat-icon warning">📋</div>
        <div class="stat-info">
            <div class="stat-value"><?= $formsPendentes ?></div>
            <div class="stat-label">Formulários pendentes</div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Atalhos rápidos -->
<div class="card">
    <div class="card-header">
        <h3>Acesso Rápido</h3>
    </div>
    <div class="card-body">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
            <a href="<?= module_url('portal') ?>" class="btn btn-outline" style="justify-content: flex-start;">
                🔗 Portal de Links
            </a>

            <a href="<?= module_url('helpdesk', 'novo.php') ?>" class="btn btn-outline" style="justify-content: flex-start;">
                🎫 Novo Chamado
            </a>

            <?php if (has_min_role('gestao')): ?>
            <a href="<?= module_url('crm') ?>" class="btn btn-outline" style="justify-content: flex-start;">
                👥 Ver Clientes
            </a>

            <a href="<?= module_url('pipeline') ?>" class="btn btn-outline" style="justify-content: flex-start;">
                📈 Ver Pipeline
            </a>

            <a href="<?= module_url('operacional') ?>" class="btn btn-outline" style="justify-content: flex-start;">
                ⚙️ Operacional
            </a>

            <a href="<?= module_url('formularios') ?>" class="btn btn-outline" style="justify-content: flex-start;">
                📋 Formulários
            </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
