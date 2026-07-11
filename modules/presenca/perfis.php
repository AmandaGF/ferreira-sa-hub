<?php
/**
 * Presença — Perfis & Verbas.
 * CRUD dos perfis do cliente (Essencial/Premium/Alta). Cada perfil tem
 * faixa de honorários (ticket_min/max) que define QUEM se encaixa nele
 * automaticamente + faixa de verba (verba_min/max) + cor pra badge.
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
require_access('presenca');

$pdo = db();
$pageTitle = 'Presença — Perfis & Verbas';

// ── POST ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) { flash_set('error','Sessão expirada.'); redirect(module_url('presenca','perfis.php')); }
    $acao = $_POST['acao'] ?? '';

    if ($acao === 'salvar') {
        $id       = (int)($_POST['id'] ?? 0);
        $nome     = clean_str($_POST['nome'] ?? '', 60);
        $slug     = strtolower(preg_replace('/[^a-z0-9]+/i','-', trim($nome)));
        $tMin     = trim($_POST['ticket_min'] ?? '');
        $tMax     = trim($_POST['ticket_max'] ?? '');
        $vMin     = trim($_POST['verba_min'] ?? '0');
        $vMax     = trim($_POST['verba_max'] ?? '0');
        $cor      = preg_match('/^#[0-9A-Fa-f]{6}$/', $_POST['cor_hex'] ?? '') ? $_POST['cor_hex'] : '#0E2E36';
        $ordem    = (int)($_POST['ordem'] ?? 0);
        $ativo    = !empty($_POST['ativo']) ? 1 : 0;

        $numOrNull = function($v) {
            $v = str_replace(array('.',','), array('','.'), (string)$v);
            return $v === '' ? null : (float)$v;
        };
        $tMin = $numOrNull($tMin); $tMax = $numOrNull($tMax);
        $vMin = (float)$numOrNull($vMin); $vMax = (float)$numOrNull($vMax);

        if (empty($nome)) { flash_set('error','Nome obrigatório.'); redirect(module_url('presenca','perfis.php')); }

        if ($id > 0) {
            $st = $pdo->prepare("UPDATE presenca_perfil SET nome=?, slug=?, ticket_min=?, ticket_max=?, verba_min=?, verba_max=?, cor_hex=?, ordem=?, ativo=? WHERE id=?");
            $st->execute(array($nome, $slug, $tMin, $tMax, $vMin, $vMax, $cor, $ordem, $ativo, $id));
            audit_log('presenca_perfil_edit', 'presenca_perfil', $id, $nome);
            flash_set('success','Perfil atualizado.');
        } else {
            $st = $pdo->prepare("INSERT INTO presenca_perfil (nome,slug,ticket_min,ticket_max,verba_min,verba_max,cor_hex,ordem,ativo) VALUES (?,?,?,?,?,?,?,?,?)");
            $st->execute(array($nome, $slug, $tMin, $tMax, $vMin, $vMax, $cor, $ordem, $ativo));
            audit_log('presenca_perfil_new', 'presenca_perfil', (int)$pdo->lastInsertId(), $nome);
            flash_set('success','Perfil criado.');
        }
        redirect(module_url('presenca','perfis.php'));
    }

    if ($acao === 'toggle_ativo') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $pdo->prepare("UPDATE presenca_perfil SET ativo = 1 - ativo WHERE id = ?")->execute(array($id));
            audit_log('presenca_perfil_toggle', 'presenca_perfil', $id, '');
        }
        redirect(module_url('presenca','perfis.php'));
    }
}

// ── Lista ──
$perfis = $pdo->query("SELECT * FROM presenca_perfil ORDER BY ordem ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);

// Contagem de clientes que caem em cada faixa (aproximado — usa a maior faixa de honorários dos cases do cliente)
$clientesPorPerfil = array();
foreach ($perfis as $p) $clientesPorPerfil[$p['id']] = 0;
try {
    $stC = $pdo->query("
        SELECT
          c.id AS client_id,
          COALESCE(MAX(cs.estimated_value_cents)/100, 0) AS honorario
        FROM clients c
        LEFT JOIN cases cs ON cs.client_id = c.id
        WHERE c.id IS NOT NULL
        GROUP BY c.id
    ");
    foreach ($stC as $r) {
        $h = (float)$r['honorario'];
        if ($h <= 0) continue;
        foreach ($perfis as $p) {
            $ok = true;
            if ($p['ticket_min'] !== null && $h < (float)$p['ticket_min']) $ok = false;
            if ($p['ticket_max'] !== null && $h > (float)$p['ticket_max']) $ok = false;
            if ($ok) { $clientesPorPerfil[$p['id']]++; break; }
        }
    }
} catch (Exception $e) {}

$csrf = generate_csrf_token();
require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.pp-hero { display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; flex-wrap:wrap; gap:10px; }
.pp-hero h1 { margin:0; font-family:'Cormorant Garamond',Georgia,serif; font-size:1.6rem; font-weight:600; color:#0E2E36; }
.pp-hero .sub { font-size:.85rem; color:#6b7280; margin-top:4px; }
.pp-back { display:inline-flex; align-items:center; gap:5px; padding:5px 12px; background:#fff; border:1px solid #e5e7eb; border-radius:8px; text-decoration:none; color:#0E2E36; font-size:.78rem; font-weight:600; }
.pp-back:hover { border-color:#B87333; color:#B87333; }

.pp-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(280px,1fr)); gap:14px; margin-bottom:24px; }
.pp-card { background:#fff; border-radius:12px; padding:0; box-shadow:0 1px 3px rgba(0,0,0,.06); overflow:hidden; border-left:6px solid #ccc; }
.pp-card.inativo { opacity:.55; }
.pp-card-head { padding:14px 18px; color:#fff; }
.pp-card-head h3 { margin:0; font-size:1.1rem; font-weight:800; }
.pp-card-head .slug { font-size:.7rem; opacity:.75; font-family:'JetBrains Mono',Consolas,monospace; margin-top:2px; }
.pp-card-body { padding:14px 18px; }
.pp-linha { display:flex; justify-content:space-between; padding:5px 0; border-bottom:1px solid #f3f4f6; font-size:.82rem; }
.pp-linha:last-child { border-bottom:none; }
.pp-linha .lbl { color:#6b7280; }
.pp-linha .val { font-weight:700; color:#0E2E36; }
.pp-card-footer { padding:10px 18px; border-top:1px solid #f3f4f6; display:flex; gap:6px; flex-wrap:wrap; background:#fafafa; }
.pp-btn { background:#fff; color:#0E2E36; border:1px solid #d1d5db; border-radius:6px; padding:5px 10px; font-size:.72rem; font-weight:700; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; gap:4px; }
.pp-btn:hover { border-color:#B87333; color:#B87333; }
.pp-btn.warn { color:#dc2626; border-color:#fecaca; }
.pp-clientes-badge { background:#f5ede3; color:#78350f; padding:3px 10px; border-radius:999px; font-size:.72rem; font-weight:700; }

.pp-form { background:#fff; border:1.5px solid #d7ab90; border-radius:12px; padding:20px 24px; margin-bottom:20px; box-shadow:0 4px 12px rgba(215,171,144,.15); }
.pp-form h3 { margin:0 0 14px; font-size:1.05rem; color:#0E2E36; }
.pp-form-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:12px; }
.pp-form label { display:block; font-size:.72rem; font-weight:700; color:#6b7280; margin-bottom:4px; text-transform:uppercase; letter-spacing:.03em; }
.pp-form input[type=text], .pp-form input[type=number], .pp-form input[type=color] { width:100%; border:1.5px solid #e5e7eb; border-radius:8px; padding:8px 10px; font-size:.88rem; font-family:inherit; }
.pp-form input:focus { outline:none; border-color:#B87333; box-shadow:0 0 0 3px rgba(184,115,51,.15); }
.pp-form-actions { display:flex; gap:8px; margin-top:14px; }
.pp-form-actions button, .pp-form-actions .btn-link { background:#0E2E36; color:#fff; border:none; border-radius:8px; padding:9px 18px; font-size:.85rem; font-weight:700; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; gap:5px; }
.pp-form-actions .btn-link { background:#fff; color:#0E2E36; border:1px solid #d1d5db; }

.pp-explica { background:#f5ede3; border-left:4px solid #B87333; padding:10px 14px; border-radius:6px; margin-bottom:16px; font-size:.82rem; color:#78350f; line-height:1.5; }
</style>

<div class="pp-hero">
    <div>
        <h1>👤 Perfis &amp; Verbas</h1>
        <div class="sub">Faixa de honorários → perfil do cliente (Essencial/Premium/Alta) — determina qual presente/verba se aplica</div>
    </div>
    <a href="<?= module_url('presenca') ?>" class="pp-back">← Voltar</a>
</div>

<div class="pp-explica">
    💡 O perfil do cliente é <strong>derivado automaticamente</strong> da maior faixa de honorários dos processos ativos dele. Você nunca escolhe "Essencial" ou "Premium" na mão — o sistema decide pela faixa. Aqui você ajusta as faixas e a verba de cada perfil.
</div>

<?php $editar = null; if (isset($_GET['editar']) && (int)$_GET['editar'] > 0) {
    $st = $pdo->prepare("SELECT * FROM presenca_perfil WHERE id = ?");
    $st->execute(array((int)$_GET['editar']));
    $editar = $st->fetch(PDO::FETCH_ASSOC);
} ?>

<div class="pp-form">
    <h3><?= $editar ? '✏️ Editando: ' . e($editar['nome']) : '➕ Novo perfil' ?></h3>
    <form method="POST">
        <?= csrf_input() ?>
        <input type="hidden" name="acao" value="salvar">
        <?php if ($editar): ?><input type="hidden" name="id" value="<?= (int)$editar['id'] ?>"><?php endif; ?>

        <div class="pp-form-grid">
            <div>
                <label>Nome *</label>
                <input type="text" name="nome" required maxlength="60" value="<?= e($editar['nome'] ?? '') ?>" placeholder="Ex: Premium">
            </div>
            <div>
                <label>Ordem</label>
                <input type="number" name="ordem" min="0" value="<?= (int)($editar['ordem'] ?? 0) ?>">
            </div>
            <div>
                <label>Cor do badge</label>
                <input type="color" name="cor_hex" value="<?= e($editar['cor_hex'] ?? '#0E2E36') ?>" style="height:40px;padding:2px;">
            </div>
            <div>
                <label>Ticket min. (R$)</label>
                <input type="text" name="ticket_min" value="<?= e(isset($editar['ticket_min']) && $editar['ticket_min'] !== null ? number_format((float)$editar['ticket_min'],2,',','.') : '') ?>" placeholder="Ex: 1.500,00">
            </div>
            <div>
                <label>Ticket máx. (R$) <span style="color:#6b7280;font-weight:400;">— vazio = sem teto</span></label>
                <input type="text" name="ticket_max" value="<?= e(isset($editar['ticket_max']) && $editar['ticket_max'] !== null ? number_format((float)$editar['ticket_max'],2,',','.') : '') ?>" placeholder="Ex: 7.000,00">
            </div>
            <div>
                <label>Verba mín. presente (R$)</label>
                <input type="text" name="verba_min" value="<?= e(number_format((float)($editar['verba_min'] ?? 0),2,',','.')) ?>" placeholder="Ex: 50,00">
            </div>
            <div>
                <label>Verba máx. presente (R$)</label>
                <input type="text" name="verba_max" value="<?= e(number_format((float)($editar['verba_max'] ?? 0),2,',','.')) ?>" placeholder="Ex: 90,00">
            </div>
            <div>
                <label>Ativo?</label>
                <div style="padding-top:10px;">
                    <input type="checkbox" name="ativo" id="ativoChk" <?= empty($editar) || !empty($editar['ativo']) ? 'checked' : '' ?>>
                    <label for="ativoChk" style="display:inline;font-size:.85rem;color:#0E2E36;font-weight:600;text-transform:none;letter-spacing:0;margin-left:4px;">Sim</label>
                </div>
            </div>
        </div>

        <div class="pp-form-actions">
            <button type="submit">💾 <?= $editar ? 'Salvar' : 'Criar' ?></button>
            <?php if ($editar): ?>
                <a href="<?= module_url('presenca','perfis.php') ?>" class="btn-link">Cancelar</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<div class="pp-grid">
    <?php foreach ($perfis as $p):
        $tMin = $p['ticket_min'] !== null ? 'R$ ' . number_format((float)$p['ticket_min'], 0, ',', '.') : 'sem mínimo';
        $tMax = $p['ticket_max'] !== null ? 'R$ ' . number_format((float)$p['ticket_max'], 0, ',', '.') : 'sem teto';
        $vMin = 'R$ ' . number_format((float)$p['verba_min'], 0, ',', '.');
        $vMax = 'R$ ' . number_format((float)$p['verba_max'], 0, ',', '.');
        $qtdCli = $clientesPorPerfil[$p['id']] ?? 0;
    ?>
    <div class="pp-card <?= $p['ativo'] ? '' : 'inativo' ?>" style="border-left-color: <?= e($p['cor_hex']) ?>;">
        <div class="pp-card-head" style="background: <?= e($p['cor_hex']) ?>;">
            <h3><?= e($p['nome']) ?></h3>
            <div class="slug"><?= e($p['slug']) ?></div>
        </div>
        <div class="pp-card-body">
            <div class="pp-linha"><span class="lbl">Ticket</span><span class="val"><?= $tMin ?> — <?= $tMax ?></span></div>
            <div class="pp-linha"><span class="lbl">Verba do presente</span><span class="val"><?= $vMin ?> — <?= $vMax ?></span></div>
            <div class="pp-linha"><span class="lbl">Ordem</span><span class="val"><?= (int)$p['ordem'] ?></span></div>
            <div class="pp-linha"><span class="lbl">Clientes na faixa</span><span class="pp-clientes-badge">👥 <?= $qtdCli ?></span></div>
        </div>
        <div class="pp-card-footer">
            <a href="?editar=<?= (int)$p['id'] ?>" class="pp-btn">✏️ Editar</a>
            <form method="POST" style="display:inline;">
                <?= csrf_input() ?>
                <input type="hidden" name="acao" value="toggle_ativo">
                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                <button type="submit" class="pp-btn <?= $p['ativo'] ? 'warn' : '' ?>"><?= $p['ativo'] ? '⏸ Desativar' : '▶ Ativar' ?></button>
            </form>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
