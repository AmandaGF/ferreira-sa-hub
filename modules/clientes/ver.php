<?php
/**
 * Ferreira & Sá Hub — Perfil do Contato/Cliente (módulo Clientes)
 * Separado do CRM — aqui é a ficha cadastral completa
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();

$pdo = db();
$clientId = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM clients WHERE id = ?');
$stmt->execute(array($clientId));
$client = $stmt->fetch();

if (!$client) {
    flash_set('error', 'Contato não encontrado.');
    redirect(module_url('clientes'));
}

$pageTitle = $client['name'];

// Processos do cliente
$cases = $pdo->prepare(
    'SELECT cs.*, u.name as responsible_name FROM cases cs
     LEFT JOIN users u ON u.id = cs.responsible_user_id
     WHERE cs.client_id = ? ORDER BY cs.created_at DESC'
);
$cases->execute(array($clientId));
$cases = $cases->fetchAll();

$statusLabels = array(
    'aguardando_docs' => 'Aguardando docs', 'em_elaboracao' => 'Em elaboração',
    'aguardando_prazo' => 'Aguardando prazo', 'distribuido' => 'Distribuído',
    'em_andamento' => 'Em andamento', 'concluido' => 'Concluído',
    'arquivado' => 'Arquivado', 'suspenso' => 'Suspenso', 'ativo' => 'Ativo',
);
$statusBadge = array(
    'aguardando_docs' => 'warning', 'em_elaboracao' => 'info', 'aguardando_prazo' => 'warning',
    'distribuido' => 'success', 'em_andamento' => 'info', 'concluido' => 'success',
    'arquivado' => 'gestao', 'suspenso' => 'danger', 'ativo' => 'info',
);

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.cli-profile-header { display:flex; align-items:flex-start; justify-content:space-between; gap:1rem; flex-wrap:wrap; margin-bottom:1.5rem; }
.cli-profile-name { font-size:1.3rem; font-weight:800; color:var(--petrol-900); }
.cli-profile-meta { font-size:.82rem; color:var(--text-muted); margin-top:.15rem; }
.cli-profile-actions { display:flex; gap:.5rem; flex-wrap:wrap; }
.info-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:1rem; }
.info-item label { font-size:.7rem; text-transform:uppercase; letter-spacing:.5px; color:var(--text-muted); font-weight:700; display:block; margin-bottom:.15rem; }
.info-item span { font-size:.9rem; color:var(--text); }
</style>

<!-- Header -->
<div class="cli-profile-header">
    <div>
        <a href="<?= module_url('clientes') ?>" class="text-sm text-muted" style="display:inline-block;margin-bottom:.25rem;">← Voltar aos Clientes</a>
        <div class="cli-profile-name"><?= e($client['name']) ?></div>
        <div class="cli-profile-meta">
            <?php if ($client['cpf']): ?>CPF: <?= e($client['cpf']) ?> · <?php endif; ?>
            <?php if ($client['source']): ?>Origem: <?= e($client['source']) ?> · <?php endif; ?>
            Cadastrado em <?= $client['created_at'] ? date('d/m/Y', strtotime($client['created_at'])) : '—' ?>
        </div>
    </div>
    <div class="cli-profile-actions">
        <?php if ($client['phone']): ?>
            <a href="https://wa.me/55<?= preg_replace('/\D/', '', $client['phone']) ?>" target="_blank" class="btn btn-success btn-sm">💬 WhatsApp</a>
        <?php endif; ?>
        <?php if (has_min_role('gestao')): ?>
            <a href="<?= module_url('crm', 'cliente_form.php?id=' . $client['id']) ?>" class="btn btn-outline btn-sm">✏️ Editar</a>
            <form method="POST" action="<?= module_url('crm', 'api.php') ?>" style="display:inline;">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="delete_client">
                <input type="hidden" name="client_id" value="<?= $client['id'] ?>">
                <button type="submit" class="btn btn-outline btn-sm" style="color:var(--danger);border-color:var(--danger);" data-confirm="EXCLUIR '<?= e(addslashes($client['name'])) ?>' permanentemente? Todos os dados serão apagados.">🗑️ Excluir</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<!-- Dados cadastrais -->
<div class="card" style="margin-bottom:1.25rem;">
    <div class="card-header"><h3>Dados Cadastrais</h3></div>
    <div class="card-body">
        <div class="info-grid">
            <div class="info-item"><label>Nome</label><span><?= e($client['name']) ?></span></div>
            <div class="info-item"><label>CPF</label><span><?= e($client['cpf'] ? $client['cpf'] : '—') ?></span></div>
            <div class="info-item"><label>RG</label><span><?= e(isset($client['rg']) && $client['rg'] ? $client['rg'] : '—') ?></span></div>
            <div class="info-item"><label>Nascimento</label><span><?= $client['birth_date'] ? date('d/m/Y', strtotime($client['birth_date'])) : '—' ?></span></div>
            <div class="info-item"><label>Telefone</label><span><?= e($client['phone'] ? $client['phone'] : '—') ?></span></div>
            <div class="info-item"><label>E-mail</label><span><?= e($client['email'] ? $client['email'] : '—') ?></span></div>
            <div class="info-item"><label>Profissão</label><span><?= e(isset($client['profession']) && $client['profession'] ? $client['profession'] : '—') ?></span></div>
            <div class="info-item"><label>Estado Civil</label><span><?= e(isset($client['marital_status']) && $client['marital_status'] ? $client['marital_status'] : '—') ?></span></div>
            <div class="info-item"><label>Sexo</label><span><?= e(isset($client['gender']) && $client['gender'] ? $client['gender'] : '—') ?></span></div>
            <div class="info-item"><label>Filhos</label><span><?= isset($client['has_children']) && $client['has_children'] !== null ? ($client['has_children'] ? 'Sim' : 'Não') : '—' ?></span></div>
            <?php if (isset($client['children_names']) && $client['children_names']): ?>
                <div class="info-item" style="grid-column:1/-1;"><label>Nome(s) dos filhos</label><span><?= e($client['children_names']) ?></span></div>
            <?php endif; ?>
            <div class="info-item"><label>Chave PIX</label><span><?= e(isset($client['pix_key']) && $client['pix_key'] ? $client['pix_key'] : '—') ?></span></div>
        </div>
    </div>
</div>

<!-- Endereço -->
<div class="card" style="margin-bottom:1.25rem;">
    <div class="card-header"><h3>Endereço</h3></div>
    <div class="card-body">
        <div class="info-grid">
            <div class="info-item" style="grid-column:1/-1;"><label>Logradouro</label><span><?= e(isset($client['address_street']) && $client['address_street'] ? $client['address_street'] : '—') ?></span></div>
            <div class="info-item"><label>Cidade</label><span><?= e(isset($client['address_city']) && $client['address_city'] ? $client['address_city'] : '—') ?></span></div>
            <div class="info-item"><label>UF</label><span><?= e(isset($client['address_state']) && $client['address_state'] ? $client['address_state'] : '—') ?></span></div>
            <div class="info-item"><label>CEP</label><span><?= e(isset($client['address_zip']) && $client['address_zip'] ? $client['address_zip'] : '—') ?></span></div>
        </div>
    </div>
</div>

<!-- Observações -->
<?php if (isset($client['notes']) && $client['notes']): ?>
<div class="card" style="margin-bottom:1.25rem;">
    <div class="card-header"><h3>Observações</h3></div>
    <div class="card-body">
        <p style="font-size:.88rem;white-space:pre-wrap;"><?= e($client['notes']) ?></p>
    </div>
</div>
<?php endif; ?>

<!-- Processos vinculados -->
<div class="card">
    <div class="card-header"><h3>Processos / Demandas (<?= count($cases) ?>)</h3></div>
    <?php if (empty($cases)): ?>
        <div class="card-body" style="text-align:center;padding:2rem;color:var(--text-muted);">
            Nenhum processo vinculado a este contato.
        </div>
    <?php else: ?>
        <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:.82rem;">
            <thead><tr style="background:var(--petrol-900);color:#fff;">
                <th style="padding:.5rem .75rem;text-align:left;font-size:.72rem;text-transform:uppercase;">Título</th>
                <th style="padding:.5rem .75rem;text-align:left;font-size:.72rem;text-transform:uppercase;">Tipo</th>
                <th style="padding:.5rem .75rem;text-align:left;font-size:.72rem;text-transform:uppercase;">Nº Processo</th>
                <th style="padding:.5rem .75rem;text-align:left;font-size:.72rem;text-transform:uppercase;">Status</th>
                <th style="padding:.5rem .75rem;text-align:left;font-size:.72rem;text-transform:uppercase;">Responsável</th>
            </tr></thead>
            <tbody>
                <?php foreach ($cases as $cs): ?>
                <tr style="border-bottom:1px solid var(--border);">
                    <td style="padding:.55rem .75rem;font-weight:700;">
                        <a href="<?= module_url('operacional', 'caso_ver.php?id=' . $cs['id']) ?>" style="color:var(--petrol-900);text-decoration:none;"><?= e($cs['title'] ? $cs['title'] : 'Caso #' . $cs['id']) ?></a>
                    </td>
                    <td style="padding:.55rem .75rem;"><?= e($cs['case_type'] ? $cs['case_type'] : '—') ?></td>
                    <td style="padding:.55rem .75rem;font-family:monospace;font-size:.78rem;"><?= e($cs['case_number'] ? $cs['case_number'] : (isset($cs['internal_number']) && $cs['internal_number'] ? $cs['internal_number'] : '—')) ?></td>
                    <td style="padding:.55rem .75rem;"><span class="badge badge-<?= isset($statusBadge[$cs['status']]) ? $statusBadge[$cs['status']] : 'gestao' ?>"><?= isset($statusLabels[$cs['status']]) ? $statusLabels[$cs['status']] : $cs['status'] ?></span></td>
                    <td style="padding:.55rem .75rem;font-size:.78rem;"><?= e($cs['responsible_name'] ? $cs['responsible_name'] : '—') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
