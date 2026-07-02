<?php
/**
 * Ferreira & Sá Hub — Perfil do Contato/Cliente (módulo Clientes)
 * Separado do CRM — aqui é a ficha cadastral completa
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();

$pdo = db();
$clientId = (int)($_GET['id'] ?? 0);

// Self-heal da coluna senha_gov (Sprint 31 — pra casos PREV gov.br)
try { $pdo->exec("ALTER TABLE clients ADD COLUMN senha_gov VARCHAR(100) NULL"); } catch (Exception $e) {}

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
        "SELECT e.*, u.name as responsavel_name,
                cs.title as case_title, cs.drive_folder_url as case_drive_url
         FROM agenda_eventos e
         LEFT JOIN users u ON u.id = e.responsavel_id
         LEFT JOIN cases cs ON cs.id = e.case_id
         WHERE e.client_id = ? AND e.status != 'cancelado'
         ORDER BY e.data_inicio DESC"
    );
    $stmtAg->execute(array($clientId));
    $compromissos = $stmtAg->fetchAll();
} catch (Exception $e) { /* tabela pode não existir */ }

// Processos do cliente — inclui também os em que ele aparece como PARTE do
// nosso lado (mesma regra de caso_ver.php). Antes perdia vínculos (bug Elias).
$cases = $pdo->prepare(
    "SELECT DISTINCT cs.*, u.name as responsible_name FROM cases cs
     LEFT JOIN users u ON u.id = cs.responsible_user_id
     LEFT JOIN case_partes cp ON cp.case_id = cs.id
     WHERE cs.client_id = ?
        OR (cp.client_id = ? AND (cp.papel IN ('autor','litisconsorte_ativo','representante_legal') OR cp.eh_nosso_cliente = 1))
     ORDER BY cs.created_at DESC"
);
$cases->execute(array($clientId, $clientId));
$cases = $cases->fetchAll();

// ─── Resumo financeiro (só carrega se usuário tem acesso) ───
$finInfo = null;
if (function_exists('can_access_financeiro') && can_access_financeiro() && !empty($client['asaas_customer_id'])) {
    try {
        $stmtF = $pdo->prepare(
            "SELECT
                SUM(CASE WHEN status IN ('RECEIVED','CONFIRMED','RECEIVED_IN_CASH') THEN valor_pago ELSE 0 END) AS recebido,
                SUM(CASE WHEN status = 'PENDING' THEN valor ELSE 0 END) AS pendente,
                SUM(CASE WHEN status = 'OVERDUE' THEN valor ELSE 0 END) AS vencido,
                COUNT(CASE WHEN status = 'OVERDUE' THEN 1 END) AS qtd_vencidas,
                COUNT(CASE WHEN status = 'PENDING' THEN 1 END) AS qtd_pendentes,
                COUNT(*) AS total_cobrancas,
                MIN(CASE WHEN status = 'OVERDUE' THEN vencimento END) AS primeiro_vencido,
                MAX(CASE WHEN status IN ('RECEIVED','CONFIRMED','RECEIVED_IN_CASH') THEN data_pagamento END) AS ultimo_pagamento
             FROM asaas_cobrancas WHERE client_id = ?"
        );
        $stmtF->execute(array($clientId));
        $finInfo = $stmtF->fetch();
        if ($finInfo && $finInfo['primeiro_vencido']) {
            $finInfo['dias_atraso'] = (int)((time() - strtotime($finInfo['primeiro_vencido'])) / 86400);
        }
    } catch (Exception $e) { $finInfo = null; }
}

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
    <?php
    // 30/06/2026 Amanda: botão "Copiar Dados" — monta o texto formatado aqui
    // e passa pro JS via data-attribute (evita escape complicado no inline).
    $_cpDados = array();
    $_cpDados[] = "👤 " . ($client['name'] ?? '');
    if (!empty($client['cpf'])) $_cpDados[] = "CPF/CNPJ: " . $client['cpf'];
    if (!empty($client['rg']))  $_cpDados[] = "RG: " . $client['rg'];
    if (!empty($client['birth_date'])) $_cpDados[] = "Nascimento: " . date('d/m/Y', strtotime($client['birth_date']));
    if (!empty($client['nacionalidade'])) $_cpDados[] = "Nacionalidade: " . $client['nacionalidade'];
    if (!empty($client['profession'])) $_cpDados[] = "Profissão: " . $client['profession'];
    if (!empty($client['marital_status'])) $_cpDados[] = "Estado civil: " . $client['marital_status'];
    if (!empty($client['gender'])) $_cpDados[] = "Sexo: " . $client['gender'];
    if (isset($client['has_children']) && $client['has_children'] !== null) {
        $_cpDados[] = "Filhos: " . ($client['has_children'] ? 'Sim' : 'Não');
        if (!empty($client['children_names'])) $_cpDados[] = "Nome(s) do(s) filho(s): " . $client['children_names'];
    }
    $_cpDados[] = "";
    $_cpDados[] = "📞 Telefone: " . ($client['phone'] ?? '—');
    if (!empty($client['phone2'])) $_cpDados[] = "📞 Telefone 2: " . $client['phone2'];
    $_cpDados[] = "✉️ E-mail: " . ($client['email'] ?? '—');
    if (!empty($client['pix_key'])) $_cpDados[] = "💳 PIX: " . $client['pix_key'];

    // Endereço (montado em 1 linha + cidade/UF/CEP em outra)
    $_temEnd = !empty($client['address_street']) || !empty($client['address_city']);
    if ($_temEnd) {
        $_cpDados[] = "";
        $_cpDados[] = "📍 Endereço:";
        if (!empty($client['address_street'])) $_cpDados[] = $client['address_street'];
        $_linha2 = array();
        if (!empty($client['address_city']))  $_linha2[] = $client['address_city'];
        if (!empty($client['address_state'])) $_linha2[$_linha2 ? 0 : 0] = ($_linha2 ? $_linha2[0] . '/' . $client['address_state'] : $client['address_state']);
        if ($_linha2) $_cpDados[] = implode('', $_linha2);
        if (!empty($client['address_zip']))   $_cpDados[] = "CEP " . $client['address_zip'];
    }
    $_cpDadosTexto = implode("\n", $_cpDados);
    ?>
    <div class="cli-profile-actions">
        <?php if ($client['phone']): ?>
            <button type="button" onclick="waSenderOpen({telefone:'<?= preg_replace('/[^0-9+]/', '', $client['phone']) ?>',nome:<?= e(json_encode($client['name'])) ?>,clientId:<?= (int)$client['id'] ?>,mensagem:''})" class="btn btn-success btn-sm">💬 WhatsApp</button>
        <?php endif; ?>
        <a href="<?= module_url('operacional', 'caso_novo.php?client_id=' . $client['id']) ?>" class="btn btn-sm" style="background:var(--petrol-900);color:#fff;">+ Novo Processo</a>
        <?php
        // 30/06/2026 Amanda: 'Financeiro' agora liberado pra todos (can_view_cliente_financeiro
        // = qualquer um com acesso a clientes). 'Cobrar Honorários' continua restrito a
        // quem tem acesso ao painel financeiro geral (Amanda/Rodrigo/Luiz) porque cria
        // registro no Kanban e dispara tarefa pro Luiz — ação mais sensível.
        ?>
        <?php if (function_exists('can_view_cliente_financeiro') && can_view_cliente_financeiro()): ?>
        <a href="<?= module_url('financeiro', 'cliente.php?id=' . $client['id']) ?>" class="btn btn-sm" style="background:#059669;color:#fff;" title="Histórico financeiro: cobranças, pagamentos, inadimplência">💰 Financeiro</a>
        <?php endif; ?>
        <?php if (function_exists('can_access_financeiro') && can_access_financeiro()): ?>
        <button type="button" onclick="cobHonAbrir()" class="btn btn-sm" style="background:#1e40af;color:#fff;border:none;" title="Adicionar cliente ao Kanban de Cobrança / iniciar execução de honorários">⚖️ Cobrar Honorários</button>
        <?php endif; ?>
        <button type="button" id="btnCopiarDadosCli" onclick="copiarDadosCliente(this)" data-texto="<?= e($_cpDadosTexto) ?>" class="btn btn-sm" style="background:#0ea5e9;color:#fff;border:none;" title="Copiar dados cadastrais (nome, CPF, RG, endereço, etc) pra colar em outro lugar">📋 Copiar Dados</button>
        <a href="<?= module_url('clientes', 'ficha_pdf.php?id=' . $client['id']) ?>" target="_blank" class="btn btn-outline btn-sm">🖨️ Ficha PDF</a>
        <?php
        // Acesso liberado pra todos (Amanda 25/06/2026): editar cliente + Central VIP.
        // Excluir continua restrito a gestao (ação destrutiva).
        // Verificar se cliente tem acesso Central VIP
        $stmtSv = $pdo->prepare("SELECT id, ativo, token_ativacao, token_expira, senha_hash FROM salavip_usuarios WHERE cliente_id = ? LIMIT 1");
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
                    <form method="POST" action="<?= module_url('crm', 'api.php') ?>" style="display:inline;">
                        <?= csrf_input() ?>
                        <input type="hidden" name="action" value="toggle_salavip">
                        <input type="hidden" name="ativo" value="0">
                        <input type="hidden" name="client_id" value="<?= $clientId ?>">
                        <button type="submit" class="btn btn-outline btn-sm" style="font-size:.72rem;color:var(--danger);border-color:var(--danger);" title="Bloquear o login do cliente na Central VIP (conta e senha são preservadas)" data-confirm="Desabilitar o acesso de '<?= e(addslashes($client['name'])) ?>' à Central VIP? O cliente não conseguirá mais entrar até você reabilitar.">🚫 Desabilitar acesso</button>
                    </form>
                <?php elseif (!empty($svUser['senha_hash'])): ?>
                    <span class="btn btn-sm" style="background:#9ca3af;color:#fff;cursor:default;">🚫 Central VIP Desabilitada</span>
                    <form method="POST" action="<?= module_url('crm', 'api.php') ?>" style="display:inline;">
                        <?= csrf_input() ?>
                        <input type="hidden" name="action" value="toggle_salavip">
                        <input type="hidden" name="ativo" value="1">
                        <input type="hidden" name="client_id" value="<?= $clientId ?>">
                        <button type="submit" class="btn btn-outline btn-sm" style="font-size:.72rem;color:#059669;border-color:#059669;" title="Reabilitar o login (cliente usa a mesma senha de antes)">✅ Reabilitar acesso</button>
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
        <?php if (has_min_role('gestao')): // Excluir = só gestão (ação destrutiva) ?>
            <form method="POST" action="<?= module_url('crm', 'api.php') ?>" style="display:inline;">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="delete_client">
                <input type="hidden" name="client_id" value="<?= $client['id'] ?>">
                <button type="submit" class="btn btn-outline btn-sm" style="color:var(--danger);border-color:var(--danger);" data-confirm="EXCLUIR '<?= e(addslashes($client['name'])) ?>' permanentemente? Todos os dados serão apagados.">🗑️ Excluir</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($finInfo && (int)$finInfo['total_cobrancas'] > 0):
    $temVencido = (float)$finInfo['vencido'] > 0;
    $borda = $temVencido ? '#dc2626' : '#059669';
    $bg    = $temVencido ? 'linear-gradient(135deg,#fef2f2,#fee2e2)' : 'linear-gradient(135deg,#f0fdf4,#dcfce7)';
?>
<!-- Card Financeiro / Inadimplência -->
<div class="card" style="margin-bottom:1.25rem;border-left:5px solid <?= $borda ?>;background:<?= $bg ?>;">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;background:transparent;border-bottom:1px solid rgba(0,0,0,.06);">
        <div>
            <h3 style="margin:0;">
                <?= $temVencido ? '⚠️ Situação Financeira do Cliente — INADIMPLENTE' : '💰 Situação Financeira do Cliente' ?>
            </h3>
            <div style="font-size:.7rem;color:var(--text-muted);margin-top:2px;">Histórico consolidado no Asaas — inclui todos os processos do cliente</div>
        </div>
        <a href="<?= module_url('financeiro', 'cliente.php?id=' . $clientId) ?>" class="btn btn-outline btn-sm" style="font-size:.72rem;">Ver extrato completo →</a>
    </div>
    <div class="card-body">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:1rem;">
            <div>
                <div style="font-size:.7rem;color:var(--text-muted);font-weight:700;text-transform:uppercase;letter-spacing:.3px;">💵 Total Recebido</div>
                <div style="font-size:1.3rem;font-weight:800;color:#059669;">R$ <?= number_format((float)$finInfo['recebido'], 2, ',', '.') ?></div>
                <?php if ($finInfo['ultimo_pagamento']): ?>
                    <div style="font-size:.7rem;color:var(--text-muted);">último: <?= date('d/m/Y', strtotime($finInfo['ultimo_pagamento'])) ?></div>
                <?php endif; ?>
            </div>
            <div>
                <div style="font-size:.7rem;color:var(--text-muted);font-weight:700;text-transform:uppercase;letter-spacing:.3px;">📋 Pendente (a vencer)</div>
                <div style="font-size:1.3rem;font-weight:800;color:#b45309;">R$ <?= number_format((float)$finInfo['pendente'], 2, ',', '.') ?></div>
                <div style="font-size:.7rem;color:var(--text-muted);"><?= (int)$finInfo['qtd_pendentes'] ?> parcela(s)</div>
            </div>
            <div>
                <div style="font-size:.7rem;color:var(--text-muted);font-weight:700;text-transform:uppercase;letter-spacing:.3px;">🚨 Vencido (inadimplência)</div>
                <div style="font-size:1.3rem;font-weight:800;color:<?= $temVencido ? '#dc2626' : '#6b7280' ?>;">R$ <?= number_format((float)$finInfo['vencido'], 2, ',', '.') ?></div>
                <?php if ($temVencido): ?>
                    <div style="font-size:.72rem;font-weight:700;color:#991b1b;"><?= (int)$finInfo['qtd_vencidas'] ?> parcela(s) · <?= (int)($finInfo['dias_atraso'] ?? 0) ?> dia(s) de atraso</div>
                <?php else: ?>
                    <div style="font-size:.7rem;color:#166534;font-weight:600;">✓ Em dia</div>
                <?php endif; ?>
            </div>
            <div>
                <div style="font-size:.7rem;color:var(--text-muted);font-weight:700;text-transform:uppercase;letter-spacing:.3px;">📊 Total Histórico</div>
                <div style="font-size:1.3rem;font-weight:800;color:var(--petrol-900);"><?= (int)$finInfo['total_cobrancas'] ?></div>
                <div style="font-size:.7rem;color:var(--text-muted);">cobranças geradas</div>
            </div>
        </div>
        <?php if ($temVencido && $client['phone']): ?>
        <div style="margin-top:1rem;padding-top:.8rem;border-top:1px dashed rgba(220,38,38,.3);display:flex;gap:.5rem;flex-wrap:wrap;">
            <?php $msgCobranca = 'Olá ' . explode(' ', $client['name'])[0] . '! Somos do escritório Ferreira & Sá Advocacia. Identificamos cobranças em aberto no seu cadastro. Pode nos retornar quando possível?'; ?>
            <button type="button" onclick="waSenderOpen({telefone:'<?= preg_replace('/\D/', '', $client['phone']) ?>',nome:<?= e(json_encode($client['name'])) ?>,clientId:<?= (int)$client['id'] ?>,canal:'24',mensagem:<?= e(json_encode($msgCobranca)) ?>})" class="btn btn-sm" style="background:#25d366;color:#fff;border:none;">💬 Cobrar pelo WhatsApp</button>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

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
            <?php $senhaGov = isset($client['senha_gov']) ? (string)$client['senha_gov'] : ''; ?>
            <div class="info-item" id="senhaGovBox" style="grid-column:1/-1;">
                <label>Senha gov.br <span style="font-size:.65rem;color:var(--text-muted);font-weight:400;">(usado em casos previdenciários)</span></label>
                <div id="senhaGovView" style="display:flex;align-items:center;gap:.4rem;flex-wrap:wrap;">
                    <span id="senhaGovTxt" data-senha="<?= e($senhaGov) ?>" data-mascarada="1" style="font-family:monospace;letter-spacing:.05em;<?= $senhaGov === '' ? 'color:var(--text-muted);font-style:italic;' : '' ?>">
                        <?= $senhaGov === '' ? 'Não cadastrada' : '••••••••' ?>
                    </span>
                    <?php if ($senhaGov !== ''): ?>
                    <button type="button" onclick="senhaGovToggle()" id="senhaGovOlho" class="btn btn-sm btn-outline" style="padding:2px 8px;font-size:.7rem;">👁 Mostrar</button>
                    <button type="button" onclick="senhaGovCopiar()" class="btn btn-sm btn-outline" style="padding:2px 8px;font-size:.7rem;">📋 Copiar</button>
                    <?php endif; ?>
                    <button type="button" onclick="senhaGovEditar()" class="btn btn-sm btn-outline" style="padding:2px 8px;font-size:.7rem;"><?= $senhaGov === '' ? '+ Cadastrar' : '✏️ Editar' ?></button>
                </div>
                <div id="senhaGovEdit" style="display:none;align-items:center;gap:.4rem;flex-wrap:wrap;">
                    <input type="text" id="senhaGovInput" value="<?= e($senhaGov) ?>" class="form-input" style="width:240px;font-family:monospace;" placeholder="Senha do gov.br do cliente" maxlength="100">
                    <button type="button" onclick="senhaGovSalvar()" class="btn btn-sm" style="background:var(--petrol-900);color:#fff;border:none;padding:4px 10px;">Salvar</button>
                    <button type="button" onclick="senhaGovCancelar()" class="btn btn-sm btn-outline" style="padding:4px 10px;">Cancelar</button>
                    <span id="senhaGovStatus" style="font-size:.72rem;color:var(--text-muted);"></span>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function(){
    var elTxt    = document.getElementById('senhaGovTxt');
    var elView   = document.getElementById('senhaGovView');
    var elEdit   = document.getElementById('senhaGovEdit');
    var elInput  = document.getElementById('senhaGovInput');
    var elStatus = document.getElementById('senhaGovStatus');

    window.senhaGovToggle = function() {
        var olho = document.getElementById('senhaGovOlho');
        var senha = elTxt.getAttribute('data-senha') || '';
        var mascarada = elTxt.getAttribute('data-mascarada') === '1';
        if (mascarada) {
            elTxt.textContent = senha;
            elTxt.setAttribute('data-mascarada', '0');
            if (olho) olho.textContent = '🙈 Ocultar';
        } else {
            elTxt.textContent = senha === '' ? 'Não cadastrada' : '••••••••';
            elTxt.setAttribute('data-mascarada', '1');
            if (olho) olho.textContent = '👁 Mostrar';
        }
    };

    window.senhaGovCopiar = function() {
        var senha = elTxt.getAttribute('data-senha') || '';
        if (!senha) return;
        if (navigator.clipboard) {
            navigator.clipboard.writeText(senha).then(function(){
                elStatus.textContent = '✓ Copiada';
                setTimeout(function(){ elStatus.textContent = ''; }, 1500);
            });
        }
    };

    window.senhaGovEditar = function() {
        elView.style.display = 'none';
        elEdit.style.display = 'flex';
        elInput.focus();
        elInput.select();
    };

    window.senhaGovCancelar = function() {
        elEdit.style.display = 'none';
        elView.style.display = 'flex';
        elInput.value = elTxt.getAttribute('data-senha') || '';
    };

    window.senhaGovSalvar = function() {
        var nova = elInput.value.trim();
        elStatus.textContent = 'Salvando…';
        var fd = new FormData();
        fd.append('action', 'update_senha_gov');
        fd.append('client_id', '<?= (int)$client['id'] ?>');
        fd.append('senha_gov', nova);
        fd.append('csrf_token', document.querySelector('meta[name=csrf-token]')?.content || '<?= e(generate_csrf_token()) ?>');
        fetch('<?= module_url('crm', 'api.php') ?>', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function(r){
                if (r.status === 401 && window.fsaMostrarSessaoExpirada) { window.fsaMostrarSessaoExpirada(); throw new Error('401'); }
                return r.json();
            })
            .then(function(j){
                if (j && j.ok) {
                    elStatus.textContent = '✓ Salva';
                    elTxt.setAttribute('data-senha', nova);
                    elTxt.setAttribute('data-mascarada', '1');
                    elTxt.textContent = nova === '' ? 'Não cadastrada' : '••••••••';
                    elTxt.style.color  = nova === '' ? 'var(--text-muted)' : '';
                    elTxt.style.fontStyle = nova === '' ? 'italic' : '';
                    setTimeout(function(){ window.location.reload(); }, 600);
                } else {
                    elStatus.textContent = '✕ ' + (j && j.erro ? j.erro : 'Erro ao salvar');
                }
            })
            .catch(function(){ elStatus.textContent = '✕ Erro de rede'; });
    };
})();
</script>

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
        $tipoAgLabels = array('audiencia'=>'Audiência','reuniao_cliente'=>'Reunião','prazo'=>'Prazo','onboarding'=>'Onboarding','reuniao_interna'=>'R. Interna','mediacao_cejusc'=>'Mediação','ligacao'=>'Ligação','pessoal'=>'Pessoal');
        $tipoAgCores = array('audiencia'=>'#052228','reuniao_cliente'=>'#B87333','prazo'=>'#CC0000','onboarding'=>'#2D7A4F','reuniao_interna'=>'#1a3a7a','mediacao_cejusc'=>'#6B4C9A','ligacao'=>'#888880','pessoal'=>'#a855f7');
        foreach ($compromissos as $ev):
            $cor = isset($tipoAgCores[$ev['tipo']]) ? $tipoAgCores[$ev['tipo']] : '#888';
            $lbl = isset($tipoAgLabels[$ev['tipo']]) ? $tipoAgLabels[$ev['tipo']] : $ev['tipo'];
            $isPast = strtotime($ev['data_inicio']) < time();
        ?>
        <tr style="border-bottom:1px solid var(--border);<?= $ev['status'] === 'realizado' ? 'opacity:.6;' : '' ?>">
            <td style="padding:.55rem .75rem;font-weight:600;border-left:3px solid <?= $cor ?>;">
                <?= e($ev['titulo']) ?>
                <?php if ($ev['local']): ?><div style="font-size:.7rem;color:var(--text-muted);font-weight:400;">📍 <?= e($ev['local']) ?></div><?php endif; ?>
                <?php if (!empty($ev['case_id'])): ?><div style="font-size:.7rem;font-weight:400;margin-top:2px;"><a href="<?= module_url('operacional', 'caso_ver.php?id=' . $ev['case_id']) ?>" style="color:var(--petrol-900);text-decoration:none;" title="Abrir pasta do processo">📁 <?= e($ev['case_title'] ? $ev['case_title'] : ('Processo #' . $ev['case_id'])) ?></a></div><?php endif; ?>
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

<!-- 30/06/2026 Amanda: copia dados do cliente formatados pro clipboard.
     Disponível pra todos os usuários (não só financeiro). -->
<script>
window.copiarDadosCliente = function(btn) {
    var texto = btn.getAttribute('data-texto') || '';
    var orig = btn.innerHTML;
    var feedback = function(ok) {
        btn.innerHTML = ok ? '✓ Copiado!' : '✗ Falhou';
        btn.style.background = ok ? '#059669' : '#dc2626';
        setTimeout(function(){ btn.innerHTML = orig; btn.style.background = '#0ea5e9'; }, 1800);
    };
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(texto).then(function(){ feedback(true); }).catch(function(){ feedback(false); });
    } else {
        try {
            var ta = document.createElement('textarea');
            ta.value = texto; ta.style.position = 'fixed'; ta.style.left = '-9999px';
            document.body.appendChild(ta); ta.focus(); ta.select();
            var ok = document.execCommand('copy');
            document.body.removeChild(ta);
            feedback(ok);
        } catch (e) { feedback(false); }
    }
};
</script>

<?php if (function_exists('can_access_financeiro') && can_access_financeiro()): ?>
<!-- ════ Modal: Cobrar Honorários (Amanda 29/06/2026) ════
     Cria registro em honorarios_cobranca direto, sem depender do Asaas.
     Útil pra clientes cuja cobrança Asaas foi cancelada (caso Sarah:
     Luiz cancelava pra evitar taxa de notificação). Ao criar, abre
     tarefa pro Luiz Eduardo atualizar valor com correção/juros. -->
<div id="cobHonOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:14px;max-width:560px;width:94%;max-height:92vh;overflow:auto;box-shadow:0 20px 60px rgba(0,0,0,.3);">
    <div style="background:linear-gradient(135deg,#1e40af,#1e3a8a);color:#fff;padding:1rem 1.2rem;border-radius:14px 14px 0 0;display:flex;justify-content:space-between;align-items:center;">
      <h3 style="margin:0;font-size:1rem;">⚖️ Cobrar Honorários — <?= e($client['name']) ?></h3>
      <button onclick="cobHonFechar()" style="background:none;border:none;color:#fff;font-size:1.3rem;cursor:pointer;">×</button>
    </div>
    <div style="padding:1.1rem 1.2rem;">
      <div style="background:#fef3c7;border:1px solid #fcd34d;border-radius:8px;padding:.6rem .8rem;font-size:.78rem;color:#92400e;margin-bottom:.85rem;">
        💡 Esta cobrança vai pro <strong>Kanban de Cobrança de Honorários</strong> direto, sem passar pelo Asaas. Útil quando a cobrança Asaas foi cancelada ou nunca existiu. <strong>Luiz Eduardo recebe tarefa</strong> pra atualizar o valor com correção/juros.
      </div>

      <div style="margin-bottom:.7rem;">
        <label style="font-size:.72rem;font-weight:700;color:#6b7280;display:block;margin-bottom:.25rem;">Caso vinculado</label>
        <select id="cobHonCase" style="width:100%;padding:.55rem .75rem;font-size:.88rem;border:1.5px solid #e5e7eb;border-radius:8px;">
          <option value="">— Sem caso específico (cobrança geral) —</option>
          <?php foreach ($cases as $cs): ?>
            <option value="<?= (int)$cs['id'] ?>"><?= e($cs['title']) ?><?= $cs['case_number'] ? ' (' . e($cs['case_number']) . ')' : '' ?> — <?= e($cs['status']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:.6rem;margin-bottom:.7rem;">
        <div>
          <label style="font-size:.72rem;font-weight:700;color:#6b7280;display:block;margin-bottom:.25rem;">Valor devido (R$) *</label>
          <input type="text" id="cobHonValor" placeholder="Ex: 5.000,00" style="width:100%;padding:.55rem .75rem;font-size:.88rem;border:1.5px solid #e5e7eb;border-radius:8px;">
        </div>
        <div>
          <label style="font-size:.72rem;font-weight:700;color:#6b7280;display:block;margin-bottom:.25rem;">Vencimento (original) *</label>
          <input type="date" id="cobHonVencto" value="<?= date('Y-m-d') ?>" style="width:100%;padding:.55rem .75rem;font-size:.88rem;border:1.5px solid #e5e7eb;border-radius:8px;">
        </div>
      </div>

      <div style="margin-bottom:.7rem;">
        <label style="font-size:.72rem;font-weight:700;color:#6b7280;display:block;margin-bottom:.25rem;">Tipo de débito</label>
        <select id="cobHonTipo" style="width:100%;padding:.55rem .75rem;font-size:.88rem;border:1.5px solid #e5e7eb;border-radius:8px;">
          <option value="honorarios_contratuais">Honorários contratuais</option>
          <option value="honorarios_exito">Honorários de êxito</option>
          <option value="honorarios_arbitrados">Honorários arbitrados</option>
          <option value="custas_processuais">Custas processuais</option>
          <option value="outro">Outro</option>
        </select>
      </div>

      <div style="margin-bottom:.7rem;">
        <label style="font-size:.72rem;font-weight:700;color:#6b7280;display:block;margin-bottom:.35rem;">Em qual estágio entrar?</label>
        <label style="display:flex;align-items:flex-start;gap:.45rem;padding:.55rem .65rem;border:1.5px solid #e5e7eb;border-radius:8px;cursor:pointer;margin-bottom:.3rem;">
          <input type="radio" name="cobHonEst" value="atrasado" style="margin-top:3px;">
          <div><div style="font-weight:700;color:#dc2626;font-size:.82rem;">⚠️ Atrasado</div><div style="font-size:.7rem;color:#6b7280;">Entra no Kanban no início — sem notificação automática</div></div>
        </label>
        <label style="display:flex;align-items:flex-start;gap:.45rem;padding:.55rem .65rem;border:1.5px solid #e5e7eb;border-radius:8px;cursor:pointer;margin-bottom:.3rem;">
          <input type="radio" name="cobHonEst" value="notificado_1" style="margin-top:3px;">
          <div><div style="font-weight:700;color:#d97706;font-size:.82rem;">📱 Notificação amigável</div><div style="font-size:.7rem;color:#6b7280;">Manda 1ª cobrança amigável (WhatsApp/email)</div></div>
        </label>
        <label style="display:flex;align-items:flex-start;gap:.45rem;padding:.55rem .65rem;border:1.5px solid #e5e7eb;border-radius:8px;cursor:pointer;margin-bottom:.3rem;">
          <input type="radio" name="cobHonEst" value="notificado_extrajudicial" style="margin-top:3px;">
          <div><div style="font-weight:700;color:#8b5cf6;font-size:.82rem;">📄 Notificação extrajudicial</div><div style="font-size:.7rem;color:#6b7280;">Notificação formal antes da execução</div></div>
        </label>
        <label style="display:flex;align-items:flex-start;gap:.45rem;padding:.55rem .65rem;border:2px solid #1e40af;background:#eff6ff;border-radius:8px;cursor:pointer;">
          <input type="radio" name="cobHonEst" value="judicial" checked style="margin-top:3px;">
          <div><div style="font-weight:700;color:#1e40af;font-size:.82rem;">⚖️ Direto pra EXECUÇÃO judicial</div><div style="font-size:.7rem;color:#6b7280;">Cliente não respondeu — partir pra ação. Gestão é notificada.</div></div>
        </label>
      </div>

      <div style="margin-bottom:.85rem;">
        <label style="font-size:.72rem;font-weight:700;color:#6b7280;display:block;margin-bottom:.25rem;">Observação interna</label>
        <textarea id="cobHonObs" rows="2" placeholder="Ex: contrato de 12 parcelas, não pagou nenhuma, ignorou contatos..." style="width:100%;padding:.55rem .75rem;font-size:.85rem;border:1.5px solid #e5e7eb;border-radius:8px;font-family:inherit;resize:vertical;"></textarea>
      </div>

      <div style="display:flex;justify-content:flex-end;gap:.5rem;">
        <button onclick="cobHonFechar()" style="background:#e5e7eb;color:#374151;border:none;border-radius:8px;padding:8px 16px;font-size:.85rem;font-weight:600;cursor:pointer;">Cancelar</button>
        <button onclick="cobHonSalvar()" id="cobHonBtnSalvar" style="background:#1e40af;color:#fff;border:none;border-radius:8px;padding:8px 18px;font-size:.85rem;font-weight:700;cursor:pointer;">⚖️ Criar cobrança</button>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
    var CSRF = <?= json_encode(generate_csrf_token()) ?>;
    var CLIENT_ID = <?= (int)$client['id'] ?>;
    var API_URL = <?= json_encode(module_url('financeiro', 'api.php')) ?>;

    window.cobHonAbrir = function() {
        document.getElementById('cobHonOverlay').style.display = 'flex';
        setTimeout(function(){ document.getElementById('cobHonValor').focus(); }, 100);
    };
    window.cobHonFechar = function() {
        document.getElementById('cobHonOverlay').style.display = 'none';
    };
    document.getElementById('cobHonOverlay').addEventListener('click', function(e) {
        if (e.target.id === 'cobHonOverlay') cobHonFechar();
    });

    window.cobHonSalvar = function() {
        var valor = document.getElementById('cobHonValor').value.trim();
        var vencto = document.getElementById('cobHonVencto').value;
        var tipo = document.getElementById('cobHonTipo').value;
        var caseId = document.getElementById('cobHonCase').value;
        var estagio = document.querySelector('input[name=cobHonEst]:checked');
        var obs = document.getElementById('cobHonObs').value.trim();

        if (!valor) { alert('Informe o valor devido.'); return; }
        if (!vencto) { alert('Informe o vencimento original.'); return; }
        if (!estagio) { alert('Escolha o estágio.'); return; }

        var btn = document.getElementById('cobHonBtnSalvar');
        btn.disabled = true; var textoAntigo = btn.textContent; btn.textContent = '⏳ Salvando...';

        var fd = new FormData();
        fd.append('action', 'criar_cobranca_honorarios');
        fd.append('csrf_token', CSRF);
        fd.append('client_id', CLIENT_ID);
        if (caseId) fd.append('case_id', caseId);
        fd.append('valor', valor);
        fd.append('vencimento', vencto);
        fd.append('tipo_debito', tipo);
        fd.append('estagio', estagio.value);
        fd.append('observacao', obs);

        fetch(API_URL, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd
        })
        .then(function(r) {
            if (r.status === 401 && window.fsaMostrarSessaoExpirada) { window.fsaMostrarSessaoExpirada(); throw new Error('401'); }
            return r.json();
        })
        .then(function(j) {
            btn.disabled = false; btn.textContent = textoAntigo;
            if (j.error) { alert('Erro: ' + j.error); return; }
            var msgEst = ({
                atrasado: '⚠️ Atrasado',
                notificado_1: '📱 Notificação amigável',
                notificado_extrajudicial: '📄 Notificação extrajudicial',
                judicial: '⚖️ Execução judicial'
            })[j.estagio] || j.estagio;
            var msg = (j.ja_existia ? '✓ Cobrança ATUALIZADA' : '✓ Cobrança CRIADA') + '\n\nEstágio: ' + msgEst;
            if (j.tarefa_luiz) msg += '\n\n💼 Tarefa criada pro Luiz Eduardo atualizar o valor com correção/juros.';
            if (j.estagio === 'judicial') msg += '\n\n📢 Gestão foi notificada.';
            msg += '\n\nAbrir no Kanban?';
            if (confirm(msg)) window.open(j.kanban_url, '_blank');
            cobHonFechar();
        })
        .catch(function(e) {
            btn.disabled = false; btn.textContent = textoAntigo;
            if (e.message !== '401') alert('Erro: ' + e.message);
        });
    };
})();
</script>
<?php endif; ?>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
