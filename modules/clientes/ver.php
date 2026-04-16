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

// Peças geradas vinculadas ao cliente
$peticoes = array();
try {
    $stmtPet = $pdo->prepare(
        "SELECT cd.*, u.name as user_name, cs.title as case_title
         FROM case_documents cd
         LEFT JOIN users u ON u.id = cd.gerado_por
         LEFT JOIN cases cs ON cs.id = cd.case_id
         WHERE cd.client_id = ?
         ORDER BY cd.created_at DESC"
    );
    $stmtPet->execute(array($clientId));
    $peticoes = $stmtPet->fetchAll();
} catch (Exception $e) { /* tabela pode não existir */ }

// Compromissos da agenda vinculados ao cliente
$compromissos = array();
try {
    $stmtAg = $pdo->prepare(
        "SELECT e.*, u.name as responsavel_name
         FROM agenda_eventos e
         LEFT JOIN users u ON u.id = e.responsavel_id
         WHERE e.client_id = ? AND e.status != 'cancelado'
         ORDER BY e.data_inicio DESC"
    );
    $stmtAg->execute(array($clientId));
    $compromissos = $stmtAg->fetchAll();
} catch (Exception $e) { /* tabela pode não existir */ }

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
.info-item span { font-size:.9rem; color:var(--text); overflow:hidden; text-overflow:ellipsis; display:block; white-space:nowrap; max-width:100%; }
</style>

<!-- Header -->
<div class="cli-profile-header">
    <div style="display:flex;align-items:center;gap:1rem;">
        <?php if (!empty($client['foto_path'])): ?>
            <img src="/salavip/uploads/<?= e($client['foto_path']) ?>" alt="Foto" style="width:64px;height:64px;border-radius:50%;object-fit:cover;border:2px solid var(--rose);flex-shrink:0;">
        <?php endif; ?>
        <div>
        <a href="<?= module_url('clientes') ?>" class="text-sm text-muted" style="display:inline-block;margin-bottom:.25rem;">← Voltar aos Clientes</a>
        <div class="cli-profile-name"><?= e($client['name']) ?></div>
        <div class="cli-profile-meta">
            <?php if ($client['cpf']): ?><?= strlen(preg_replace('/\D/', '', $client['cpf'])) > 11 ? 'CNPJ' : 'CPF' ?>: <?= e($client['cpf']) ?> · <?php endif; ?>
            <?php if ($client['source']): ?>Origem: <?= e($client['source']) ?> · <?php endif; ?>
            Cadastrado em <?= $client['created_at'] ? date('d/m/Y', strtotime($client['created_at'])) : '—' ?>
        </div>
        <?php
        $csLabels = ['ativo'=>'Ativo','inativo'=>'Inativo','cancelou'=>'Cancelou','parou_responder'=>'Parou de Responder','demitido'=>'Demitimos','prospect'=>'Prospect'];
        $csCores = ['ativo'=>'#059669','inativo'=>'#6b7280','cancelou'=>'#dc2626','parou_responder'=>'#f59e0b','demitido'=>'#dc2626','prospect'=>'#6366f1'];
        $csAtual = $client['client_status'] ?? 'ativo';
        ?>
        <form method="POST" action="<?= module_url('crm', 'api.php') ?>" style="display:inline-flex;align-items:center;gap:.4rem;margin-top:.3rem;">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="update_client_status">
            <input type="hidden" name="client_id" value="<?= $client['id'] ?>">
            <select name="client_status" onchange="this.form.submit()" style="padding:3px 8px;font-size:.75rem;border-radius:6px;border:1.5px solid <?= $csCores[$csAtual] ?? '#888' ?>;background:<?= $csCores[$csAtual] ?? '#888' ?>22;color:<?= $csCores[$csAtual] ?? '#888' ?>;font-weight:700;cursor:pointer;">
                <?php foreach ($csLabels as $k => $v): ?>
                <option value="<?= $k ?>" <?= $csAtual === $k ? 'selected' : '' ?>><?= $v ?></option>
                <?php endforeach; ?>
            </select>
        </form>
        </div>
    </div>
    <div class="cli-profile-actions">
        <?php if ($client['phone']): ?>
            <a href="https://wa.me/55<?= preg_replace('/\D/', '', $client['phone']) ?>" target="_blank" class="btn btn-success btn-sm">💬 WhatsApp</a>
        <?php endif; ?>
        <a href="<?= module_url('operacional', 'caso_novo.php?client_id=' . $client['id']) ?>" class="btn btn-sm" style="background:var(--petrol-900);color:#fff;">+ Novo Processo</a>
        <a href="<?= module_url('clientes', 'ficha_pdf.php?id=' . $client['id']) ?>" target="_blank" class="btn btn-outline btn-sm">🖨️ Ficha PDF</a>
        <?php if (has_min_role('gestao')): ?>
            <?php
            // Verificar se cliente tem acesso Central VIP
            $stmtSv = $pdo->prepare("SELECT id, ativo, token_ativacao, token_expira FROM salavip_usuarios WHERE cliente_id = ? LIMIT 1");
            $svUser = null;
            try { $stmtSv->execute(array($clientId)); $svUser = $stmtSv->fetch(); } catch (Exception $e) {}
            ?>
            <?php if ($svUser): ?>
                <?php if ($svUser['ativo']): ?>
                    <span class="btn btn-sm" style="background:#059669;color:#fff;cursor:default;">🟢 Central VIP Ativa</span>
                    <form method="POST" action="<?= module_url('crm', 'api.php') ?>" style="display:inline;">
                        <?= csrf_input() ?>
                        <input type="hidden" name="action" value="reset_salavip">
                        <input type="hidden" name="client_id" value="<?= $clientId ?>">
                        <button type="submit" class="btn btn-outline btn-sm" style="font-size:.72rem;" title="Gerar novo link de ativação">🔄 Reenviar Link</button>
                    </form>
                <?php else: ?>
                    <span class="btn btn-sm" style="background:#f59e0b;color:#fff;cursor:default;">⏳ Central VIP Pendente</span>
                    <form method="POST" action="<?= module_url('crm', 'api.php') ?>" style="display:inline;">
                        <?= csrf_input() ?>
                        <input type="hidden" name="action" value="reset_salavip">
                        <input type="hidden" name="client_id" value="<?= $clientId ?>">
                        <button type="submit" class="btn btn-outline btn-sm" style="font-size:.72rem;" title="Gerar novo link de ativação">🔄 Reenviar Link</button>
                    </form>
                <?php endif; ?>
            <?php else: ?>
                <form method="POST" action="<?= module_url('crm', 'api.php') ?>" style="display:inline;">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="criar_salavip">
                    <input type="hidden" name="client_id" value="<?= $clientId ?>">
                    <button type="submit" class="btn btn-sm" style="background:#6366f1;color:#fff;">🔑 Criar Acesso Central VIP</button>
                </form>
            <?php endif; ?>
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
            <div class="info-item"><label>CPF / CNPJ</label><span><?= e($client['cpf'] ? $client['cpf'] : '—') ?></span></div>
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
            <div class="info-item"><label>Nacionalidade</label><span><?= e(isset($client['nacionalidade']) && $client['nacionalidade'] ? $client['nacionalidade'] : '—') ?></span></div>
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
    <div class="card-header">
        <h3>Processos / Demandas (<?= count($cases) ?>)</h3>
        <a href="<?= module_url('operacional', 'caso_novo.php?client_id=' . $client['id']) ?>" class="btn btn-outline btn-sm" style="font-size:.72rem;">+ Novo Processo</a>
    </div>
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
                    <td style="padding:.55rem .75rem;font-family:monospace;font-size:.78rem;"><?php if ($cs['case_number']): ?><a href="<?= module_url('operacional', 'caso_ver.php?id=' . $cs['id']) ?>" style="color:var(--petrol-900);text-decoration:none;" title="Abrir pasta"><?= e($cs['case_number']) ?></a><?php else: ?>—<?php endif; ?></td>
                    <td style="padding:.55rem .75rem;"><span class="badge badge-<?= isset($statusBadge[$cs['status']]) ? $statusBadge[$cs['status']] : 'gestao' ?>"><?= isset($statusLabels[$cs['status']]) ? $statusLabels[$cs['status']] : $cs['status'] ?></span></td>
                    <td style="padding:.55rem .75rem;font-size:.78rem;"><?= e($cs['responsible_name'] ? $cs['responsible_name'] : '—') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>

<!-- Peças Geradas (Fábrica de Petições) -->
<?php if (!empty($peticoes)): ?>
<div class="card" style="margin-top:1.25rem;">
    <div class="card-header">
        <h3>Peças Geradas (<?= count($peticoes) ?>)</h3>
        <a href="<?= module_url('peticoes') ?>" class="btn btn-outline btn-sm" style="font-size:.72rem;">+ Nova petição</a>
    </div>
    <div style="overflow-x:auto;">
    <table style="width:100%;border-collapse:collapse;font-size:.82rem;">
        <thead><tr style="background:var(--petrol-900);color:#fff;">
            <th style="padding:.5rem .75rem;text-align:left;font-size:.72rem;text-transform:uppercase;">Título</th>
            <th style="padding:.5rem .75rem;text-align:left;font-size:.72rem;text-transform:uppercase;">Tipo Ação</th>
            <th style="padding:.5rem .75rem;text-align:left;font-size:.72rem;text-transform:uppercase;">Peça</th>
            <th style="padding:.5rem .75rem;text-align:left;font-size:.72rem;text-transform:uppercase;">Gerado por</th>
            <th style="padding:.5rem .75rem;text-align:left;font-size:.72rem;text-transform:uppercase;">Data</th>
            <th style="padding:.5rem .75rem;text-align:left;font-size:.72rem;text-transform:uppercase;">Tokens</th>
            <th style="padding:.5rem .75rem;text-align:center;font-size:.72rem;text-transform:uppercase;">Ações</th>
        </tr></thead>
        <tbody>
        <?php
        $tiposAcaoLabel = array('alimentos'=>'Alimentos','revisional_alimentos'=>'Revisional','execucao_alimentos'=>'Execução Alimentos','guarda_convivencia'=>'Guarda','investigacao_paternidade'=>'Invest. Paternidade','divorcio_consensual'=>'Divórcio Consensual','divorcio_litigioso'=>'Divórcio Litigioso','inventario'=>'Inventário','consumidor'=>'Consumidor','usucapiao'=>'Usucapião');
        $tiposPecaLabel = array('peticao_inicial'=>'Petição Inicial','tutela_urgencia'=>'Tutela Urgência','contestacao'=>'Contestação','replica'=>'Réplica','memoriais'=>'Memoriais','recurso_inominado'=>'Recurso Inominado','cumprimento_sentenca'=>'Cumprimento Sentença','impugnacao'=>'Impugnação','embargos_execucao'=>'Embargos Execução','manifestacao'=>'Manifestação');
        foreach ($peticoes as $doc): ?>
        <tr style="border-bottom:1px solid var(--border);">
            <td style="padding:.55rem .75rem;font-weight:600;">
                <?= e($doc['titulo'] ? $doc['titulo'] : 'Peça #' . $doc['id']) ?>
                <?php if ($doc['case_title']): ?>
                    <div style="font-size:.7rem;color:var(--text-muted);font-weight:400;">Caso: <?= e($doc['case_title']) ?></div>
                <?php endif; ?>
            </td>
            <td style="padding:.55rem .75rem;">
                <span class="badge badge-info" style="font-size:.7rem;"><?= isset($tiposAcaoLabel[$doc['tipo_acao']]) ? $tiposAcaoLabel[$doc['tipo_acao']] : e($doc['tipo_acao']) ?></span>
            </td>
            <td style="padding:.55rem .75rem;font-size:.78rem;"><?= isset($tiposPecaLabel[$doc['tipo_peca']]) ? $tiposPecaLabel[$doc['tipo_peca']] : e($doc['tipo_peca']) ?></td>
            <td style="padding:.55rem .75rem;font-size:.78rem;"><?= e($doc['user_name'] ? $doc['user_name'] : '—') ?></td>
            <td style="padding:.55rem .75rem;font-size:.78rem;"><?= $doc['created_at'] ? date('d/m/Y H:i', strtotime($doc['created_at'])) : '—' ?></td>
            <td style="padding:.55rem .75rem;font-size:.72rem;color:var(--text-muted);">
                <?php if ($doc['tokens_input'] || $doc['tokens_output']): ?>
                    <?= number_format(($doc['tokens_input'] ?: 0) + ($doc['tokens_output'] ?: 0)) ?>
                    <?php if ($doc['custo_usd']): ?><br>$<?= number_format($doc['custo_usd'], 4) ?><?php endif; ?>
                <?php else: ?>—<?php endif; ?>
            </td>
            <td style="padding:.55rem .75rem;text-align:center;">
                <a href="<?= module_url('peticoes', 'ver.php?id=' . $doc['id']) ?>" class="btn btn-outline btn-sm" style="font-size:.7rem;" target="_blank">Ver</a>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

<!-- Agenda / Compromissos -->
<?php if (!empty($compromissos)): ?>
<div class="card" style="margin-top:1.25rem;">
    <div class="card-header"><h3>Compromissos (<?= count($compromissos) ?>)</h3></div>
    <div style="overflow-x:auto;">
    <table style="width:100%;border-collapse:collapse;font-size:.82rem;">
        <thead><tr style="background:var(--petrol-900);color:#fff;">
            <th style="padding:.5rem .75rem;text-align:left;font-size:.72rem;text-transform:uppercase;">Compromisso</th>
            <th style="padding:.5rem .75rem;text-align:left;font-size:.72rem;text-transform:uppercase;">Tipo</th>
            <th style="padding:.5rem .75rem;text-align:left;font-size:.72rem;text-transform:uppercase;">Data/Hora</th>
            <th style="padding:.5rem .75rem;text-align:left;font-size:.72rem;text-transform:uppercase;">Responsável</th>
            <th style="padding:.5rem .75rem;text-align:left;font-size:.72rem;text-transform:uppercase;">Status</th>
        </tr></thead>
        <tbody>
        <?php
        $tipoAgLabels = array('audiencia'=>'Audiência','reuniao_cliente'=>'Reunião','prazo'=>'Prazo','onboarding'=>'Onboarding','reuniao_interna'=>'R. Interna','mediacao_cejusc'=>'Mediação','ligacao'=>'Ligação');
        $tipoAgCores = array('audiencia'=>'#052228','reuniao_cliente'=>'#B87333','prazo'=>'#CC0000','onboarding'=>'#2D7A4F','reuniao_interna'=>'#1a3a7a','mediacao_cejusc'=>'#6B4C9A','ligacao'=>'#888880');
        foreach ($compromissos as $ev):
            $cor = isset($tipoAgCores[$ev['tipo']]) ? $tipoAgCores[$ev['tipo']] : '#888';
            $lbl = isset($tipoAgLabels[$ev['tipo']]) ? $tipoAgLabels[$ev['tipo']] : $ev['tipo'];
            $isPast = strtotime($ev['data_inicio']) < time();
        ?>
        <tr style="border-bottom:1px solid var(--border);<?= $ev['status'] === 'realizado' ? 'opacity:.6;' : '' ?>">
            <td style="padding:.55rem .75rem;font-weight:600;border-left:3px solid <?= $cor ?>;">
                <?= e($ev['titulo']) ?>
                <?php if ($ev['local']): ?><div style="font-size:.7rem;color:var(--text-muted);font-weight:400;">📍 <?= e($ev['local']) ?></div><?php endif; ?>
            </td>
            <td style="padding:.55rem .75rem;">
                <span style="display:inline-block;padding:2px 8px;border-radius:12px;font-size:.7rem;font-weight:600;color:#fff;background:<?= $cor ?>;"><?= $lbl ?></span>
            </td>
            <td style="padding:.55rem .75rem;font-size:.78rem;">
                <?= date('d/m/Y', strtotime($ev['data_inicio'])) ?>
                <?php if ($ev['dia_todo'] != 1): ?> às <?= date('H:i', strtotime($ev['data_inicio'])) ?><?php endif; ?>
            </td>
            <td style="padding:.55rem .75rem;font-size:.78rem;"><?= e($ev['responsavel_name'] ? $ev['responsavel_name'] : '—') ?></td>
            <td style="padding:.55rem .75rem;">
                <?php if ($ev['status'] === 'realizado'): ?>
                    <span class="badge badge-success" style="font-size:.7rem;">Realizado</span>
                <?php elseif ($ev['status'] === 'remarcado'): ?>
                    <span class="badge badge-warning" style="font-size:.7rem;">Remarcado</span>
                <?php elseif ($isPast): ?>
                    <span class="badge badge-danger" style="font-size:.7rem;">Passado</span>
                <?php else: ?>
                    <span class="badge badge-info" style="font-size:.7rem;">Agendado</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
