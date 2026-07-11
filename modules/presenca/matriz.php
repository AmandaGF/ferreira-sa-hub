<?php
/**
 * Presença — Matriz de Regras (Perfil × Fase → Brinde + Frase + Verba).
 * Grade editável. Célula vazia = nenhuma sugestão nasce (disciplina do vazio).
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
require_access('presenca');

$pdo = db();
$pageTitle = 'Presença — Matriz de Regras';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) { flash_set('error','Sessão expirada.'); redirect(module_url('presenca','matriz.php')); }
    $acao = $_POST['acao'] ?? '';

    if ($acao === 'salvar_celula') {
        $perfilId = (int)($_POST['perfil_id'] ?? 0);
        $faseId   = (int)($_POST['fase_id'] ?? 0);
        $brindeId = (int)($_POST['brinde_id'] ?? 0) ?: null;
        $fraseId  = (int)($_POST['frase_id'] ?? 0) ?: null;
        $verba    = (float)str_replace(',', '.', str_replace('.', '', $_POST['verba_prevista'] ?? '0'));

        if (!$perfilId || !$faseId) {
            header('Content-Type: application/json');
            echo json_encode(array('ok'=>false,'erro'=>'perfil/fase ausente'));
            exit;
        }

        // Upsert
        $st = $pdo->prepare("SELECT id FROM presenca_regra WHERE perfil_id = ? AND fase_id = ?");
        $st->execute(array($perfilId, $faseId));
        $existente = (int)$st->fetchColumn();

        if ($existente > 0) {
            $pdo->prepare("UPDATE presenca_regra SET brinde_id=?, frase_id=?, verba_prevista=?, ativo=1 WHERE id=?")
                ->execute(array($brindeId, $fraseId, $verba, $existente));
        } else {
            $pdo->prepare("INSERT INTO presenca_regra (perfil_id, fase_id, brinde_id, frase_id, verba_prevista, ativo) VALUES (?,?,?,?,?,1)")
                ->execute(array($perfilId, $faseId, $brindeId, $fraseId, $verba));
            $existente = (int)$pdo->lastInsertId();
        }
        audit_log('presenca_regra_upsert','presenca_regra',$existente,"perfil=$perfilId fase=$faseId brinde=".(int)$brindeId);
        header('Content-Type: application/json');
        echo json_encode(array('ok'=>true,'id'=>$existente,'verba'=>number_format($verba,2,',','.')));
        exit;
    }

    if ($acao === 'limpar_celula') {
        $perfilId = (int)($_POST['perfil_id'] ?? 0);
        $faseId   = (int)($_POST['fase_id'] ?? 0);
        $pdo->prepare("DELETE FROM presenca_regra WHERE perfil_id = ? AND fase_id = ?")->execute(array($perfilId, $faseId));
        audit_log('presenca_regra_del','presenca_regra',0,"perfil=$perfilId fase=$faseId");
        header('Content-Type: application/json');
        echo json_encode(array('ok'=>true));
        exit;
    }
}

$perfis = $pdo->query("SELECT * FROM presenca_perfil WHERE ativo=1 ORDER BY ordem, id")->fetchAll(PDO::FETCH_ASSOC);
$fases  = $pdo->query("SELECT * FROM presenca_fase WHERE ativo=1 ORDER BY ordem, id")->fetchAll(PDO::FETCH_ASSOC);
$brindes = $pdo->query("SELECT id, nome, categoria FROM presenca_brinde WHERE ativo=1 ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
$frases = $pdo->query("SELECT id, texto, fase_id FROM presenca_frase WHERE ativo=1 ORDER BY fase_id IS NULL, fase_id, id")->fetchAll(PDO::FETCH_ASSOC);

$regras = array();
foreach ($pdo->query("SELECT r.*, b.nome AS brinde_nome, f.texto AS frase_texto FROM presenca_regra r LEFT JOIN presenca_brinde b ON b.id=r.brinde_id LEFT JOIN presenca_frase f ON f.id=r.frase_id WHERE r.ativo=1") as $r) {
    $regras[$r['perfil_id'] . '_' . $r['fase_id']] = $r;
}

$csrf = generate_csrf_token();
require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.pm-hero { display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; flex-wrap:wrap; gap:10px; }
.pm-hero h1 { margin:0; font-family:'Cormorant Garamond',Georgia,serif; font-size:1.6rem; font-weight:600; color:#0E2E36; }
.pm-back { display:inline-flex; align-items:center; gap:5px; padding:5px 12px; background:#fff; border:1px solid #e5e7eb; border-radius:8px; text-decoration:none; color:#0E2E36; font-size:.78rem; font-weight:600; }

.pm-explica { background:#f5ede3; border-left:4px solid #B87333; padding:10px 14px; border-radius:6px; margin-bottom:16px; font-size:.82rem; color:#78350f; line-height:1.5; }

.pm-tabela-wrap { overflow-x:auto; background:#fff; border-radius:12px; border:1px solid #e5e7eb; }
.pm-tabela { border-collapse:collapse; width:100%; min-width:900px; }
.pm-tabela th, .pm-tabela td { border:1px solid #f3f4f6; padding:0; vertical-align:top; }
.pm-tabela th { background:#0E2E36; color:#fff; padding:12px 14px; font-size:.78rem; font-weight:700; text-align:left; letter-spacing:.03em; }
.pm-tabela th.perfil-h { background:linear-gradient(135deg,#0E2E36,#173d46); }
.pm-tabela th.fase-h { background:#f5ede3; color:#78350f; text-align:center; font-family:'Cormorant Garamond',Georgia,serif; font-size:1rem; font-weight:700; }
.pm-tabela th.fase-h .gatilho { font-size:.65rem; color:#a0846b; font-weight:400; font-style:italic; margin-top:2px; font-family:'Outfit',sans-serif; }

.pm-perfil-nome { padding:12px 14px; background:#fafafa; font-weight:800; color:#0E2E36; font-size:.9rem; border-right:3px solid transparent; }
.pm-perfil-nome small { display:block; font-weight:400; color:#6b7280; font-size:.72rem; margin-top:2px; }

.pm-celula { padding:8px 10px; min-height:120px; position:relative; }
.pm-celula.preenchida { background:#f0fdf4; }
.pm-celula.vazia { background:#fafafa; }
.pm-celula .lbl { font-size:.62rem; color:#6b7280; font-weight:700; text-transform:uppercase; letter-spacing:.03em; margin-bottom:2px; margin-top:6px; }
.pm-celula select, .pm-celula input { width:100%; border:1px solid #e5e7eb; border-radius:6px; padding:5px 7px; font-size:.75rem; font-family:inherit; background:#fff; }
.pm-celula select:focus, .pm-celula input:focus { outline:none; border-color:#B87333; box-shadow:0 0 0 2px rgba(184,115,51,.15); }
.pm-celula .actions { display:flex; gap:4px; margin-top:8px; }
.pm-celula .actions button { flex:1; background:#0E2E36; color:#fff; border:none; border-radius:6px; padding:4px 8px; font-size:.7rem; font-weight:700; cursor:pointer; }
.pm-celula .actions button.warn { background:#fff; color:#dc2626; border:1px solid #fecaca; }
.pm-celula .saved-flag { position:absolute; top:6px; right:6px; font-size:.7rem; color:#059669; opacity:0; transition:opacity .3s; }
.pm-celula.acabou-de-salvar .saved-flag { opacity:1; }
</style>

<div class="pm-hero">
    <div>
        <h1>🗂️ Matriz de Regras</h1>
        <div style="font-size:.85rem;color:#6b7280;margin-top:4px;">Perfil × Fase → Brinde + Frase + Verba</div>
    </div>
    <a href="<?= module_url('presenca') ?>" class="pm-back">← Voltar</a>
</div>

<div class="pm-explica">
    💡 <strong>Disciplina do vazio.</strong> Célula vazia é proposital — sem regra ativa, nenhuma sugestão nasce daquela combinação. Preencha só onde faz sentido enviar. Salvar acontece automático ao mudar cada campo.
</div>

<div class="pm-tabela-wrap">
    <table class="pm-tabela">
        <thead>
            <tr>
                <th class="perfil-h" style="min-width:180px;">Perfil ↓ · Fase →</th>
                <?php foreach ($fases as $f): ?>
                    <th class="fase-h" style="min-width:220px;"><?= e($f['nome']) ?><?php if (!empty($f['gatilho'])): ?><div class="gatilho"><?= e($f['gatilho']) ?></div><?php endif; ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($perfis as $p): ?>
            <tr>
                <td class="pm-perfil-nome" style="border-right-color: <?= e($p['cor_hex']) ?>;">
                    <?= e($p['nome']) ?>
                    <small>Ticket: R$ <?= $p['ticket_min']!==null?number_format((float)$p['ticket_min'],0,',','.'):'0' ?> — <?= $p['ticket_max']!==null?'R$ '.number_format((float)$p['ticket_max'],0,',','.'):'sem teto' ?></small>
                    <small>Verba: R$ <?= number_format((float)$p['verba_min'],0,',','.') ?> — R$ <?= number_format((float)$p['verba_max'],0,',','.') ?></small>
                </td>
                <?php foreach ($fases as $f):
                    $chave = $p['id'] . '_' . $f['id'];
                    $r = $regras[$chave] ?? null;
                    $preenchida = $r && (!empty($r['brinde_id']) || !empty($r['frase_id']) || (float)$r['verba_prevista'] > 0);
                ?>
                <td class="pm-celula <?= $preenchida?'preenchida':'vazia' ?>" data-perfil="<?= (int)$p['id'] ?>" data-fase="<?= (int)$f['id'] ?>">
                    <div class="saved-flag">✓</div>
                    <div class="lbl">Brinde</div>
                    <select data-campo="brinde_id" onchange="pmSalvar(this)">
                        <option value="0">— nenhum —</option>
                        <?php foreach ($brindes as $b): ?>
                            <option value="<?= (int)$b['id'] ?>" <?= $r && (int)$r['brinde_id']===(int)$b['id']?'selected':'' ?>><?= e($b['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="lbl">Frase</div>
                    <select data-campo="frase_id" onchange="pmSalvar(this)">
                        <option value="0">— nenhuma —</option>
                        <?php foreach ($frases as $fr):
                            if ($fr['fase_id'] !== null && (int)$fr['fase_id'] !== (int)$f['id']) continue;
                            $lbl = mb_substr($fr['texto'], 0, 55) . (mb_strlen($fr['texto']) > 55 ? '…' : '');
                            $lbl = ($fr['fase_id'] === null ? '✨ ' : '') . $lbl;
                        ?>
                            <option value="<?= (int)$fr['id'] ?>" <?= $r && (int)$r['frase_id']===(int)$fr['id']?'selected':'' ?>><?= e($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="lbl">Verba prevista (R$)</div>
                    <input type="text" data-campo="verba_prevista" value="<?= $r ? number_format((float)$r['verba_prevista'],2,',','.') : '' ?>" placeholder="0,00" onblur="pmSalvar(this)">
                    <div class="actions">
                        <?php if ($preenchida): ?>
                            <button type="button" class="warn" onclick="pmLimpar(this)">🗑 Limpar</button>
                        <?php endif; ?>
                    </div>
                </td>
                <?php endforeach; ?>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<script>
var pmCsrf = '<?= $csrf ?>';

function pmCelulaDe(el) {
    var td = el.closest('td.pm-celula');
    return { td: td, perfilId: td.dataset.perfil, faseId: td.dataset.fase };
}

function pmSalvar(el) {
    var info = pmCelulaDe(el);
    var brSel = info.td.querySelector('select[data-campo="brinde_id"]');
    var frSel = info.td.querySelector('select[data-campo="frase_id"]');
    var vInp  = info.td.querySelector('input[data-campo="verba_prevista"]');

    var fd = new FormData();
    fd.append('acao', 'salvar_celula');
    fd.append('perfil_id', info.perfilId);
    fd.append('fase_id', info.faseId);
    fd.append('brinde_id', brSel.value || '0');
    fd.append('frase_id', frSel.value || '0');
    fd.append('verba_prevista', vInp.value || '0');
    fd.append('csrf_token', pmCsrf);

    fetch('<?= module_url('presenca','matriz.php') ?>', {
        method: 'POST', body: fd, credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(function(r){ return r.json(); })
    .then(function(j) {
        if (j.ok) {
            info.td.classList.add('acabou-de-salvar','preenchida');
            info.td.classList.remove('vazia');
            setTimeout(function(){ info.td.classList.remove('acabou-de-salvar'); }, 1200);
        }
    });
}

function pmLimpar(btn) {
    if (!confirm('Limpar esta regra? A célula fica vazia — nenhuma sugestão nasce desta combinação.')) return;
    var info = pmCelulaDe(btn);
    var fd = new FormData();
    fd.append('acao', 'limpar_celula');
    fd.append('perfil_id', info.perfilId);
    fd.append('fase_id', info.faseId);
    fd.append('csrf_token', pmCsrf);
    fetch('<?= module_url('presenca','matriz.php') ?>', {
        method: 'POST', body: fd, credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(function(r){ return r.json(); })
    .then(function(j) {
        if (j.ok) {
            info.td.querySelector('select[data-campo="brinde_id"]').value = '0';
            info.td.querySelector('select[data-campo="frase_id"]').value = '0';
            info.td.querySelector('input[data-campo="verba_prevista"]').value = '';
            info.td.classList.remove('preenchida');
            info.td.classList.add('vazia');
            btn.style.display = 'none';
        }
    });
}
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
