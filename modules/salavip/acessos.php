<?php
/**
 * Ferreira & Sa Hub -- Central VIP -- Gerenciar Acessos de Clientes
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();

if (!has_min_role('gestao')) {
    flash_set('error', 'Acesso restrito.');
    redirect(url('modules/dashboard/index.php'));
}

$pageTitle = 'Clientes com Acesso — Central VIP';
$pdo = db();

// Self-heal: tabela de tokens de impersonate (Amanda entrar como cliente)
try { $pdo->exec("CREATE TABLE IF NOT EXISTS salavip_impersonate_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    token CHAR(64) NOT NULL UNIQUE,
    salavip_user_id INT NOT NULL,
    admin_user_id INT NOT NULL,
    usado_em DATETIME NULL,
    criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
    expira_em DATETIME NOT NULL,
    INDEX idx_token (token),
    INDEX idx_expira (expira_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Exception $e) {}

// ── POST handlers ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    // ── Gerar link de impersonate (SÓ Amanda — user_id=1) ──
    // Cria token de uso único, válido 5min. Amanda clica → entra como cliente.
    if ($action === 'gerar_link_impersonate') {
        if ((int)current_user_id() !== 1) {
            flash_set('error', 'Apenas Amanda pode entrar como cliente.');
            redirect(module_url('salavip', 'acessos.php'));
            exit;
        }
        $stmtU = $pdo->prepare("SELECT id, cliente_id FROM salavip_usuarios WHERE id = ? AND ativo = 1");
        $stmtU->execute(array($id));
        $u = $stmtU->fetch();
        if (!$u) {
            flash_set('error', 'Usuário não encontrado ou inativo.');
            redirect(module_url('salavip', 'acessos.php'));
            exit;
        }
        $token = bin2hex(random_bytes(32));
        $pdo->prepare("INSERT INTO salavip_impersonate_tokens (token, salavip_user_id, admin_user_id, expira_em) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 5 MINUTE))")
            ->execute(array($token, (int)$u['id'], (int)current_user_id()));
        audit_log('salavip_impersonate', 'salavip_usuarios', $id, 'Amanda gerou token de impersonate');
        // Redireciona direto pro fluxo de login admin no salavip
        $url = 'https://www.ferreiraesa.com.br/salavip/login_admin.php?token=' . $token;
        header('Location: ' . $url);
        exit;
    }

    // ── Reenviar Link (regenerar token + enviar e-mail) ─
    if ($action === 'reenviar_link') {
        // Buscar dados do usuário + cliente
        $stmtU = $pdo->prepare(
            "SELECT su.*, c.name as client_name, c.email as client_email
             FROM salavip_usuarios su
             LEFT JOIN clients c ON c.id = su.cliente_id
             WHERE su.id = ?"
        );
        $stmtU->execute(array($id));
        $usrData = $stmtU->fetch();

        if (!$usrData) {
            flash_set('error', 'Usuário não encontrado.');
            redirect(module_url('salavip', 'acessos.php'));
        }

        $emailDest = $usrData['client_email'] ?: $usrData['email'];
        $nomeDest = $usrData['client_name'] ?: $usrData['nome_exibicao'];

        if (!$emailDest) {
            flash_set('error', 'Cliente não tem e-mail cadastrado. Cadastre primeiro no CRM.');
            redirect(module_url('salavip', 'acessos.php'));
        }

        $newToken = bin2hex(random_bytes(32));
        $pdo->prepare(
            "UPDATE salavip_usuarios SET token_ativacao = ?, atualizado_em = NOW() WHERE id = ?"
        )->execute(array($newToken, $id));

        $linkAtivacao = 'https://www.ferreiraesa.com.br/salavip/ativar_conta.php?token=' . $newToken;

        require_once APP_ROOT . '/core/functions_salavip_email.php';
        $enviado = _salavip_enviar_email_ativacao($emailDest, $nomeDest, $linkAtivacao);

        audit_log('salavip_reenviar_link', 'salavip_usuarios', $id, "Reenviado para $emailDest");
        if ($enviado !== false) {
            flash_set('success', '✓ Link reenviado por e-mail para <strong>' . e($emailDest) . '</strong>.<br>Link também disponível (caso precise copiar): <code style="background:#f3f4f6;padding:2px 6px;border-radius:3px;font-size:.75rem;">' . e($linkAtivacao) . '</code>');
        } else {
            flash_set('error', 'Token regenerado mas e-mail não pôde ser enviado (Brevo não configurado?). Copie o link manualmente: <code>' . e($linkAtivacao) . '</code>');
        }
        redirect(module_url('salavip', 'acessos.php'));
    }

    // ── Resetar Senha ───────────────────────────────────
    if ($action === 'resetar_senha') {
        $tempPassword = substr(str_shuffle('abcdefghjkmnpqrstuvwxyz23456789'), 0, 8);
        $hash = password_hash($tempPassword, PASSWORD_DEFAULT);
        $pdo->prepare(
            "UPDATE salavip_usuarios SET senha_hash = ?, atualizado_em = NOW() WHERE id = ?"
        )->execute([$hash, $id]);
        audit_log('salavip_resetar_senha', 'salavip_usuarios', $id);
        flash_set('success', 'Senha resetada. Nova senha temporaria: <strong>' . $tempPassword . '</strong> — Anote antes de sair desta pagina!');
        redirect(module_url('salavip', 'acessos.php'));
    }

    // ── Ativar / Desativar ──────────────────────────────
    if ($action === 'toggle_status') {
        $current = $pdo->prepare("SELECT ativo FROM salavip_usuarios WHERE id = ?");
        $current->execute([$id]);
        $currentAtivo = (int)$current->fetchColumn();

        $newAtivo = $currentAtivo ? 0 : 1;
        $pdo->prepare(
            "UPDATE salavip_usuarios SET ativo = ?, atualizado_em = NOW() WHERE id = ?"
        )->execute([$newAtivo, $id]);
        $label = $newAtivo ? 'ativo' : 'bloqueado';
        audit_log('salavip_toggle_status', 'salavip_usuarios', $id, "Ativo: $currentAtivo -> $newAtivo");
        flash_set('success', 'Status alterado para: ' . $label);
        redirect(module_url('salavip', 'acessos.php'));
    }
}

// ── Auditoria: clientes com docs GED pendentes de acesso ────
// Pega quem tem ao menos 1 doc com visivel_cliente=1 mas a conta VIP nao
// esta ativa (token de ativacao pendente) OU nem existe usuario criado.
$docsPendentes = array(
    'inativos'   => array(),  // tem usuario VIP, mas ativo=0
    'semUsuario' => array(),  // tem doc mas sem usuario VIP
);
try {
    $st = $pdo->query("
        SELECT g.cliente_id, c.name AS cliente_nome, c.email AS cliente_email, c.phone,
               COUNT(*) AS qtd_docs, MAX(g.compartilhado_em) AS ultimo_em
        FROM salavip_ged g
        LEFT JOIN clients c ON c.id = g.cliente_id
        WHERE g.visivel_cliente = 1
        GROUP BY g.cliente_id, c.name, c.email, c.phone
        ORDER BY ultimo_em DESC
    ");
    foreach ($st->fetchAll() as $r) {
        $u = $pdo->prepare("SELECT id, email, ativo FROM salavip_usuarios WHERE cliente_id = ? ORDER BY id DESC LIMIT 1");
        $u->execute(array($r['cliente_id']));
        $uRow = $u->fetch();
        if (!$uRow) {
            $docsPendentes['semUsuario'][] = $r;
        } elseif ((int)$uRow['ativo'] === 0) {
            $r['_user_id'] = (int)$uRow['id'];
            $r['_user_email'] = $uRow['email'] ?: $r['cliente_email'];
            $docsPendentes['inativos'][] = $r;
        }
    }
} catch (Throwable $e) {}

// ── Listar usuarios ─────────────────────────────────────
$search = trim($_GET['q'] ?? '');
$where = '1=1';
$params = array();

if ($search) {
    $where .= " AND (c.name LIKE ? OR c.cpf LIKE ? OR c.email LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$usuarios = $pdo->prepare(
    "SELECT su.*, c.name as client_name, c.cpf, c.email, c.phone
     FROM salavip_usuarios su
     JOIN clients c ON c.id = su.cliente_id
     WHERE $where
     ORDER BY c.name ASC"
);
$usuarios->execute($params);
$usuarios = $usuarios->fetchAll();

// ativo is boolean: 1 = ativo, 0 = bloqueado
$statusBadge = array(1 => 'success', 0 => 'danger');
$statusLabel = array(1 => 'Ativo', 0 => 'Bloqueado');

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.acc-table { width:100%; border-collapse:collapse; font-size:.82rem; }
.acc-table th { background:var(--petrol-900); color:#fff; padding:.5rem .75rem; text-align:left; font-size:.72rem; text-transform:uppercase; letter-spacing:.5px; }
.acc-table td { padding:.5rem .75rem; border-bottom:1px solid var(--border); vertical-align:middle; }
.acc-table tr:hover { background:rgba(215,171,144,.04); }
.acc-cpf { font-size:.72rem; color:var(--text-muted); font-family:monospace; }
</style>

<a href="<?= module_url('salavip') ?>" class="btn btn-outline btn-sm mb-2">&larr; Voltar</a>

<?php
$totPendentes = count($docsPendentes['inativos']) + count($docsPendentes['semUsuario']);
if ($totPendentes > 0):
?>
<div class="card mb-2" style="border:1.5px solid #f59e0b;background:#fffbeb;">
    <div class="card-header" style="background:#fef3c7;border-bottom:1px solid #f59e0b;">
        <h3 style="color:#92400e;">⚠️ <?= $totPendentes ?> cliente(s) com documentos esperando acesso</h3>
    </div>
    <div class="card-body">
        <p style="font-size:.85rem;color:#92400e;margin-bottom:.75rem;">
            Esses clientes <strong>receberam documentos no GED</strong> mas <strong>não conseguem ver na Central VIP</strong> porque a conta deles ainda não foi ativada (não clicaram no link de convite por email).
        </p>

        <?php if (!empty($docsPendentes['inativos'])): ?>
        <h4 style="font-size:.85rem;color:#92400e;margin:.5rem 0;">⏳ <?= count($docsPendentes['inativos']) ?> com conta criada mas não ativada — reenvie o convite:</h4>
        <div style="display:flex;flex-direction:column;gap:.4rem;">
            <?php foreach ($docsPendentes['inativos'] as $p): ?>
                <div style="display:flex;align-items:center;justify-content:space-between;background:#fff;border:1px solid #fcd34d;border-radius:8px;padding:.5rem .75rem;font-size:.82rem;flex-wrap:wrap;gap:.5rem;">
                    <div style="flex:1;min-width:240px;">
                        <strong style="color:#052228;"><?= e($p['cliente_nome']) ?></strong>
                        <span style="background:#fef3c7;color:#92400e;font-size:.7rem;padding:.1rem .45rem;border-radius:10px;margin-left:.4rem;"><?= (int)$p['qtd_docs'] ?> doc(s)</span>
                        <br>
                        <small style="color:#6b7280;font-family:monospace;font-size:.72rem;"><?= e($p['_user_email']) ?: '(sem email)' ?> · último doc: <?= e(date('d/m/Y', strtotime($p['ultimo_em']))) ?></small>
                    </div>
                    <form method="POST" onsubmit="return confirm('Reenviar email de ativação para <?= e($p['cliente_nome']) ?>?');" style="display:flex;gap:.3rem;">
                        <?= csrf_input() ?>
                        <input type="hidden" name="action" value="reenviar_link">
                        <input type="hidden" name="id" value="<?= (int)$p['_user_id'] ?>">
                        <button type="submit" class="btn btn-sm" style="background:#f59e0b;color:#fff;border:none;font-weight:600;padding:.35rem .8rem;border-radius:6px;">📧 Reenviar convite</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($docsPendentes['semUsuario'])): ?>
        <h4 style="font-size:.85rem;color:#7c2d12;margin:1rem 0 .5rem;">🚫 <?= count($docsPendentes['semUsuario']) ?> SEM conta criada — precisa cadastrar acesso na Central VIP primeiro:</h4>
        <div style="display:flex;flex-direction:column;gap:.4rem;">
            <?php foreach ($docsPendentes['semUsuario'] as $p): ?>
                <div style="display:flex;align-items:center;justify-content:space-between;background:#fff;border:1px solid #fca5a5;border-radius:8px;padding:.5rem .75rem;font-size:.82rem;flex-wrap:wrap;gap:.5rem;">
                    <div style="flex:1;min-width:240px;">
                        <strong style="color:#7c2d12;"><?= e($p['cliente_nome']) ?></strong>
                        <span style="background:#fee2e2;color:#7c2d12;font-size:.7rem;padding:.1rem .45rem;border-radius:10px;margin-left:.4rem;"><?= (int)$p['qtd_docs'] ?> doc(s)</span>
                        <br>
                        <small style="color:#6b7280;font-family:monospace;font-size:.72rem;"><?= e($p['cliente_email']) ?: '(sem email)' ?> · 📞 <?= e($p['phone']) ?: '(sem telefone)' ?></small>
                    </div>
                    <a href="<?= module_url('salavip') ?>?cliente_id=<?= (int)$p['cliente_id'] ?>#cadastrar" class="btn btn-sm" style="background:#dc2626;color:#fff;font-weight:600;padding:.35rem .8rem;border-radius:6px;text-decoration:none;">➕ Cadastrar acesso</a>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header" style="justify-content:space-between;flex-wrap:wrap;gap:.5rem;">
        <h3>Clientes com Acesso (<span id="accClientesHeader"><?= count($usuarios) ?></span>)</h3>
        <form method="GET" action="<?= module_url('salavip', 'acessos.php') ?>" style="display:flex;gap:.4rem;" id="accBuscaForm">
            <input type="text" name="q" id="accBuscaInput" value="<?= e($search) ?>" placeholder="Buscar nome, CPF, email..." class="form-control" style="font-size:.78rem;width:220px;" autocomplete="off" oninput="accFiltrarClientside(this.value)">
            <button type="submit" class="btn btn-outline btn-sm">Buscar</button>
            <?php if ($search): ?>
                <a href="<?= module_url('salavip', 'acessos.php') ?>" class="btn btn-outline btn-sm">Limpar</a>
            <?php endif; ?>
        </form>
        <script>
        // Nilce r12 31/05/2026: a Nilce relatou que clicar 'Buscar' nao filtrava.
        // Causa raiz nao confirmada (form parecia OK). Defesa em camadas:
        //   1) action explicito no form (acima)
        //   2) busca client-side instantanea via oninput (abaixo) - filtra as 172 linhas ja no DOM
        //   3) Enter ainda submete pra ?q=X (backend funciona, ja confirmado pela Nilce)
        function accFiltrarClientside(termo) {
            var t = (termo || '').toLowerCase().trim();
            var rows = document.querySelectorAll('table.acc-table tbody tr');
            var visiveis = 0;
            rows.forEach(function(tr) {
                if (!t) { tr.style.display = ''; visiveis++; return; }
                var txt = tr.textContent.toLowerCase();
                if (txt.indexOf(t) !== -1) { tr.style.display = ''; visiveis++; }
                else { tr.style.display = 'none'; }
            });
            // Atualiza contador no header
            var hdr = document.getElementById('accClientesHeader');
            if (hdr) hdr.textContent = visiveis + (t ? ' de ' + rows.length : '');
        }
        </script>
    </div>
    <div class="card-body" style="padding:0;overflow-x:auto;">
        <?php if (empty($usuarios)): ?>
            <div style="text-align:center;padding:2rem;">
                <p class="text-muted text-sm">Nenhum cliente com acesso encontrado.</p>
            </div>
        <?php else: ?>
            <table class="acc-table">
                <thead>
                    <tr>
                        <th>Nome</th>
                        <th>CPF</th>
                        <th>Email</th>
                        <th>Status</th>
                        <th>Ultimo Acesso</th>
                        <th>Acoes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($usuarios as $u): ?>
                        <tr>
                            <td style="font-weight:600;"><?= e($u['client_name']) ?></td>
                            <td class="acc-cpf"><?= e($u['cpf'] ?? '—') ?></td>
                            <td class="text-sm"><?= e($u['email'] ?? '—') ?></td>
                            <td>
                                <span class="badge badge-<?= $statusBadge[(int)$u['ativo']] ?? 'gestao' ?>">
                                    <?= $statusLabel[(int)$u['ativo']] ?? 'Desconhecido' ?>
                                </span>
                            </td>
                            <td class="text-sm text-muted">
                                <?= $u['ultimo_acesso'] ? date('d/m/Y H:i', strtotime($u['ultimo_acesso'])) : '—' ?>
                            </td>
                            <td>
                                <div style="display:flex;gap:.3rem;flex-wrap:wrap;">
                                    <?php if ((int)current_user_id() === 1 && $u['ativo']): ?>
                                    <!-- Entrar como cliente (impersonate) — só Amanda -->
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Entrar na Central VIP como este cliente? (modo admin, ações ficam logadas)');">
                                        <?= csrf_input() ?>
                                        <input type="hidden" name="action" value="gerar_link_impersonate">
                                        <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                        <button type="submit" class="btn btn-sm" title="Entrar como cliente (modo admin)" style="background:#7c3aed;color:#fff;border:none;">&#128065;</button>
                                    </form>
                                    <?php endif; ?>

                                    <!-- Reenviar Link -->
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Regenerar o link de acesso?');">
                                        <?= csrf_input() ?>
                                        <input type="hidden" name="action" value="reenviar_link">
                                        <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                        <button type="submit" class="btn btn-outline btn-sm" title="Reenviar Link">&#128279;</button>
                                    </form>

                                    <!-- Resetar Senha -->
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Resetar a senha deste cliente?');">
                                        <?= csrf_input() ?>
                                        <input type="hidden" name="action" value="resetar_senha">
                                        <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                        <button type="submit" class="btn btn-outline btn-sm" title="Resetar Senha">&#128272;</button>
                                    </form>

                                    <!-- Ativar/Desativar -->
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('<?= $u['ativo'] ? 'Desativar' : 'Ativar' ?> este acesso?');">
                                        <?= csrf_input() ?>
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                        <button type="submit" class="btn btn-outline btn-sm" title="<?= $u['ativo'] ? 'Desativar' : 'Ativar' ?>" style="color:<?= $u['ativo'] ? 'var(--danger)' : 'var(--success)' ?>;">
                                            <?= $u['ativo'] ? '&#9940;' : '&#9989;' ?>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
