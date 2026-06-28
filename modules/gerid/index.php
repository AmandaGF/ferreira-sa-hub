<?php
/**
 * Pesquisa GERID — vínculo empregatício.
 *
 * Pedido pra pesquisar no GERID/INSS Digital se uma parte (pai/mãe) possui
 * vínculo empregatício (útil pra direcionar pensão alimentícia ao empregador).
 * Pode ser criado daqui ou pelo botão na pasta do processo (caso_ver).
 * Ao criar: avisa o Luiz Eduardo + abre tarefa na pasta. Resultado é registrado aqui.
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();
require_access('gerid');

$pdo = db();
$pageTitle = 'Pesquisa GERID — Vínculo';

// Self-heal
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS gerid_pesquisas (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, case_id INT UNSIGNED NULL, client_id INT UNSIGNED NULL,
        parte_nome VARCHAR(160) NOT NULL, parte_cpf VARCHAR(20) NULL, parente ENUM('pai','mae','outro') NULL,
        observacao TEXT NULL, status ENUM('pendente','concluida') NOT NULL DEFAULT 'pendente',
        tem_vinculo TINYINT(1) NULL, resultado TEXT NULL, task_id INT UNSIGNED NULL,
        created_by INT UNSIGNED NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        pesquisado_por INT UNSIGNED NULL, pesquisado_em DATETIME NULL,
        INDEX idx_status (status), INDEX idx_case (case_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

/** id do Luiz Eduardo (quem faz a pesquisa no GERID). */
function gerid_luiz_id($pdo) {
    try {
        $id = $pdo->query("SELECT id FROM users WHERE is_active=1 AND name LIKE 'Luiz Eduardo%' ORDER BY id LIMIT 1")->fetchColumn();
        return $id ? (int)$id : 0;
    } catch (Exception $e) { return 0; }
}

// AJAX: busca cliente (form avulso)
if (($_GET['ajax'] ?? '') === 'buscar_cliente') {
    header('Content-Type: application/json; charset=utf-8');
    $q = trim($_GET['q'] ?? ''); if (mb_strlen($q) < 2) { echo '[]'; exit; }
    $st = $pdo->prepare("SELECT id, name, cpf FROM clients WHERE name LIKE ? ORDER BY name LIMIT 15");
    $st->execute(array('%' . $q . '%'));
    echo json_encode($st->fetchAll()); exit;
}

// POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) { flash_set('error', 'Sessão expirada.'); redirect(module_url('gerid')); }
    $acao = $_POST['acao'] ?? '';

    if ($acao === 'solicitar') {
        $parteNome = clean_str($_POST['parte_nome'] ?? '', 160);
        $parteCpf  = clean_str($_POST['parte_cpf'] ?? '', 20);
        $parente   = in_array(($_POST['parente'] ?? ''), array('pai','mae','outro'), true) ? $_POST['parente'] : null;
        $obs       = clean_str($_POST['observacao'] ?? '', 2000);
        $caseId    = (int)($_POST['case_id'] ?? 0) ?: null;
        $clientId  = (int)($_POST['client_id'] ?? 0) ?: null;
        if ($parteNome === '') { flash_set('error', 'Informe o nome completo da parte a pesquisar.'); redirect(module_url('gerid')); }

        $pdo->prepare("INSERT INTO gerid_pesquisas (case_id, client_id, parte_nome, parte_cpf, parente, observacao, created_by)
                       VALUES (?,?,?,?,?,?,?)")
            ->execute(array($caseId, $clientId, $parteNome, $parteCpf ?: null, $parente, $obs ?: null, current_user_id()));
        $pesqId = (int)$pdo->lastInsertId();

        // referência do processo (pra mensagem/tarefa)
        $procRef = '';
        if ($caseId) {
            try { $c = $pdo->prepare("SELECT case_number, title, responsible_user_id FROM cases WHERE id=?"); $c->execute(array($caseId)); $cr = $c->fetch(); if ($cr) $procRef = $cr['case_number'] ?: $cr['title']; } catch (Exception $e) {}
        }
        $corpo = 'Pesquisar vínculo de ' . $parteNome . ($parteCpf ? ' (CPF ' . $parteCpf . ')' : '') . ($parente ? ' [' . $parente . ']' : '') . ($procRef ? ' — processo ' . $procRef : '') . '.';

        // avisa o Luiz Eduardo (ou gestão se não achar)
        $luiz = gerid_luiz_id($pdo);
        $titulo = '🔎 Pesquisa GERID de vínculo';
        if ($luiz) {
            notify($luiz, $titulo, $corpo, 'pendencia', url('modules/gerid/'), '🔎');
            if (function_exists('push_notify')) { try { push_notify($luiz, $titulo, $corpo, '/conecta/modules/gerid/', false); } catch (Exception $e) {} }
        } elseif (function_exists('notify_gestao')) {
            notify_gestao($titulo, $corpo, 'pendencia', url('modules/gerid/'), '🔎');
        }

        // tarefa na pasta do processo
        if ($caseId) {
            $pdo->prepare("INSERT INTO case_tasks (case_id, title, tipo, descricao, assigned_to, due_date, prioridade, status, sort_order, created_at)
                           VALUES (?,?,?,?,?,?,?,?,?,NOW())")
                ->execute(array($caseId, '🔎 Pesquisar vínculo no GERID — ' . $parteNome, 'outros',
                                $corpo . ' Verificar se possui vínculo empregatício e qual o empregador.',
                                $luiz ?: null, date('Y-m-d', strtotime('+2 days')), 'alta', 'a_fazer', 0));
            $taskId = (int)$pdo->lastInsertId();
            $pdo->prepare("UPDATE gerid_pesquisas SET task_id=? WHERE id=?")->execute(array($taskId, $pesqId));
        }
        audit_log('gerid_solicitar', 'gerid', $pesqId, $parteNome);

        flash_set('success', 'Pedido de pesquisa GERID registrado. O Luiz Eduardo foi avisado' . ($caseId ? ' e uma tarefa foi aberta na pasta.' : '.'));
        $vc = (int)($_POST['voltar_caso'] ?? 0);
        redirect($vc ? module_url('operacional', 'caso_ver.php?id=' . $vc) : module_url('gerid'));
    }

    if ($acao === 'resultado') {
        $id  = (int)($_POST['id'] ?? 0);
        $tem = !empty($_POST['tem_vinculo']) ? 1 : 0;
        $res = clean_str($_POST['resultado'] ?? '', 2000);
        $g = $pdo->prepare("SELECT * FROM gerid_pesquisas WHERE id=?"); $g->execute(array($id)); $row = $g->fetch();
        if ($row) {
            $pdo->prepare("UPDATE gerid_pesquisas SET status='concluida', tem_vinculo=?, resultado=?, pesquisado_por=?, pesquisado_em=NOW() WHERE id=?")
                ->execute(array($tem, $res ?: null, current_user_id(), $id));
            // fecha a tarefa
            if (!empty($row['task_id'])) {
                try { $pdo->prepare("UPDATE case_tasks SET status='concluido', completed_at=NOW() WHERE id=?")->execute(array($row['task_id'])); } catch (Exception $e) {}
            }
            // andamento na pasta
            if (!empty($row['case_id'])) {
                try {
                    $pdo->prepare("INSERT INTO case_andamentos (case_id, data_andamento, tipo, descricao, created_by, visivel_cliente, created_at) VALUES (?,?,?,?,?,0,NOW())")
                        ->execute(array($row['case_id'], date('Y-m-d'), 'gerid',
                            'Pesquisa GERID (' . $row['parte_nome'] . '): ' . ($tem ? 'POSSUI vínculo empregatício' : 'sem vínculo localizado') . ($res ? ' — ' . $res : '')));
                } catch (Exception $e) {}
            }
            // avisa quem pediu
            if (!empty($row['created_by']) && (int)$row['created_by'] !== current_user_id()) {
                notify((int)$row['created_by'], '🔎 Resultado da pesquisa GERID',
                    $row['parte_nome'] . ': ' . ($tem ? 'POSSUI vínculo' : 'sem vínculo') . ($res ? ' — ' . $res : ''),
                    'info', url('modules/gerid/'), '🔎');
            }
            audit_log('gerid_resultado', 'gerid', $id, $tem ? 'com vinculo' : 'sem vinculo');
            flash_set('success', 'Resultado registrado.');
        }
        redirect(module_url('gerid'));
    }

    redirect(module_url('gerid'));
}

$pendentes = $pdo->query("SELECT g.*, cl.name AS client_name, c.case_number, c.title AS case_title, u.name AS reg_por
                          FROM gerid_pesquisas g
                          LEFT JOIN clients cl ON cl.id=g.client_id
                          LEFT JOIN cases c ON c.id=g.case_id
                          LEFT JOIN users u ON u.id=g.created_by
                          WHERE g.status='pendente' ORDER BY g.created_at ASC LIMIT 300")->fetchAll();
$concluidas = $pdo->query("SELECT g.*, cl.name AS client_name, c.case_number, c.title AS case_title,
                                  u.name AS reg_por, p.name AS pesq_por
                           FROM gerid_pesquisas g
                           LEFT JOIN clients cl ON cl.id=g.client_id
                           LEFT JOIN cases c ON c.id=g.case_id
                           LEFT JOIN users u ON u.id=g.created_by
                           LEFT JOIN users p ON p.id=g.pesquisado_por
                           WHERE g.status='concluida' ORDER BY g.pesquisado_em DESC LIMIT 200")->fetchAll();
$csrf = generate_csrf_token();
require_once APP_ROOT . '/templates/layout_start.php';
?>
<style>
.gd-card { background:#fff;border-radius:12px;padding:16px 18px;box-shadow:0 1px 3px rgba(0,0,0,.06);margin-bottom:14px; }
.gd-input,.gd-text { width:100%;border:1px solid #ddd;border-radius:8px;padding:8px 10px;font-size:.9rem;font-family:inherit; }
.gd-text { min-height:48px;resize:vertical; }
.gd-row { display:flex;gap:12px;flex-wrap:wrap;margin-bottom:10px; }
.gd-row > div { flex:1;min-width:180px; }
.gd-label { font-size:.78rem;font-weight:700;color:#444;display:block;margin-bottom:4px; }
.gd-btn { background:#0e7490;color:#fff;border:none;border-radius:8px;padding:9px 16px;font-weight:700;cursor:pointer;font-size:.9rem; }
.gd-item { border-left:4px solid #0e7490; }
.gd-chip { display:inline-block;padding:2px 9px;border-radius:999px;font-size:.72rem;font-weight:700; }
.gd-sim { background:#dcfce7;color:#15803d; } .gd-nao { background:#fee2e2;color:#b91c1c; }
.gd-results { position:relative; } .gd-rbox { position:absolute;z-index:30;left:0;right:0;background:#fff;border:1px solid #ddd;border-radius:0 0 8px 8px;max-height:200px;overflow:auto;display:none;box-shadow:0 6px 14px rgba(0,0,0,.08); }
.gd-rbox div { padding:8px 10px;cursor:pointer;border-bottom:1px solid #f0f0f0;font-size:.85rem; } .gd-rbox div:hover { background:#ecfeff; }
.gd-empty { text-align:center;padding:30px;color:#999; }
</style>

<div class="page-header" style="margin-bottom:.6rem;">
  <h1 style="margin:0;">🔎 Pesquisa GERID — Vínculo Empregatício</h1>
  <p style="color:#777;margin:4px 0 0;">Peça pra descobrir, via GERID/INSS Digital, se a parte (pai/mãe) tem vínculo de emprego. O Luiz Eduardo é avisado e a pesquisa é registrada aqui.</p>
</div>

<details class="gd-card" style="max-width:760px;">
  <summary style="font-weight:700;color:#0e7490;cursor:pointer;">➕ Nova pesquisa (avulsa)</summary>
  <form method="post" action="<?= module_url('gerid') ?>" style="margin-top:12px;">
    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
    <input type="hidden" name="acao" value="solicitar">
    <input type="hidden" name="client_id" id="gdClientId">
    <div class="gd-row">
      <div><label class="gd-label">Nome completo da parte *</label><input type="text" class="gd-input" name="parte_nome" required></div>
      <div><label class="gd-label">CPF</label><input type="text" class="gd-input" name="parte_cpf" placeholder="000.000.000-00"></div>
    </div>
    <div class="gd-row">
      <div><label class="gd-label">É o(a)…</label>
        <select class="gd-input" name="parente"><option value="">—</option><option value="pai">Pai</option><option value="mae">Mãe</option><option value="outro">Outro</option></select>
      </div>
      <div><label class="gd-label">Cliente (opcional)</label>
        <div class="gd-results"><input type="text" class="gd-input" id="gdBuscaCli" placeholder="Vincular a um cliente…" autocomplete="off" onkeyup="gdBuscarCli(this.value)"><div class="gd-rbox" id="gdCliBox"></div></div>
        <div id="gdCliSel" style="font-size:.82rem;margin-top:4px;"></div>
      </div>
    </div>
    <div class="gd-row"><div style="flex:1 1 100%;"><label class="gd-label">Observação</label><textarea class="gd-text" name="observacao" placeholder="Algum detalhe…"></textarea></div></div>
    <button type="submit" class="gd-btn">🔎 Solicitar pesquisa</button>
  </form>
</details>

<h3 style="margin:18px 0 8px;">⏳ Pendentes (<?= count($pendentes) ?>)</h3>
<?php if (!$pendentes): ?><div class="gd-empty">Nenhuma pesquisa pendente. 🎉</div><?php else: foreach ($pendentes as $g):
  $proc = $g['case_number'] ?: $g['case_title']; ?>
<div class="gd-card gd-item">
  <div style="font-weight:700;color:#0e7490;">👤 <?= e($g['parte_nome']) ?><?= $g['parte_cpf'] ? ' · CPF ' . e($g['parte_cpf']) : '' ?><?= $g['parente'] ? ' · ' . e($g['parente']) : '' ?></div>
  <div style="color:#666;font-size:.83rem;margin-top:3px;">
    <?= $proc ? '📄 ' . e($proc) . ' · ' : '' ?><?= $g['client_name'] ? '👥 ' . e($g['client_name']) . ' · ' : '' ?>
    pedido por <?= e($g['reg_por'] ?: '—') ?> em <?= date('d/m/Y', strtotime($g['created_at'])) ?>
  </div>
  <?php if ($g['observacao']): ?><div style="font-size:.83rem;margin-top:5px;color:#444;"><?= e($g['observacao']) ?></div><?php endif; ?>
  <form method="post" action="<?= module_url('gerid') ?>" style="margin-top:10px;border-top:1px solid #f0f0f0;padding-top:10px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
    <input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="acao" value="resultado"><input type="hidden" name="id" value="<?= (int)$g['id'] ?>">
    <span style="font-size:.82rem;font-weight:700;">Resultado:</span>
    <label style="font-size:.85rem;display:flex;align-items:center;gap:5px;"><input type="radio" name="tem_vinculo" value="1" required> ✅ Tem vínculo</label>
    <label style="font-size:.85rem;display:flex;align-items:center;gap:5px;"><input type="radio" name="tem_vinculo" value="0"> ❌ Sem vínculo</label>
    <input type="text" name="resultado" class="gd-input" style="flex:1;min-width:200px;width:auto;" placeholder="Empregador / detalhes (opcional)">
    <button type="submit" class="gd-btn" style="padding:7px 12px;">Registrar</button>
  </form>
</div>
<?php endforeach; endif; ?>

<h3 style="margin:22px 0 8px;">✅ Concluídas</h3>
<?php if (!$concluidas): ?><div class="gd-empty">Nenhuma ainda.</div><?php else: ?>
<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.06);">
  <thead><tr style="background:#fafafa;font-size:.72rem;text-transform:uppercase;color:#888;"><th style="padding:9px 11px;text-align:left;">Parte</th><th style="padding:9px 11px;text-align:left;">Processo</th><th style="padding:9px 11px;text-align:left;">Vínculo</th><th style="padding:9px 11px;text-align:left;">Detalhe</th><th style="padding:9px 11px;text-align:left;">Pesquisado por</th></tr></thead>
  <tbody>
  <?php foreach ($concluidas as $g): ?>
    <tr style="border-bottom:1px solid #f0f0f0;font-size:.85rem;">
      <td style="padding:9px 11px;"><?= e($g['parte_nome']) ?><?= $g['parte_cpf'] ? '<br><span style="color:#999;font-size:.78rem;">' . e($g['parte_cpf']) . '</span>' : '' ?></td>
      <td style="padding:9px 11px;"><?= e($g['case_number'] ?: ($g['case_title'] ?: '—')) ?></td>
      <td style="padding:9px 11px;"><span class="gd-chip <?= $g['tem_vinculo'] ? 'gd-sim' : 'gd-nao' ?>"><?= $g['tem_vinculo'] ? 'POSSUI' : 'Sem vínculo' ?></span></td>
      <td style="padding:9px 11px;"><?= e($g['resultado'] ?: '—') ?></td>
      <td style="padding:9px 11px;"><?= e($g['pesq_por'] ?: '—') ?><br><span style="color:#999;font-size:.78rem;"><?= $g['pesquisado_em'] ? date('d/m/Y', strtotime($g['pesquisado_em'])) : '' ?></span></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>

<script>
var GD_URL = '<?= module_url('gerid') ?>';
var gdT=null;
function gdBuscarCli(q){
  clearTimeout(gdT); var box=document.getElementById('gdCliBox');
  if(q.length<2){box.style.display='none';return;}
  gdT=setTimeout(function(){
    fetch(GD_URL+'?ajax=buscar_cliente&q='+encodeURIComponent(q)).then(function(r){return r.json();}).then(function(arr){
      var h=''; arr.forEach(function(c){ h+='<div onclick="gdSelCli('+c.id+',&quot;'+(c.name||'').replace(/"/g,'')+'&quot;)">'+(c.name||'')+(c.cpf?' · '+c.cpf:'')+'</div>'; });
      box.innerHTML=h||'<div style="color:#999;cursor:default;">Nenhum</div>'; box.style.display='block';
    });
  },250);
}
function gdSelCli(id,name){ document.getElementById('gdClientId').value=id; document.getElementById('gdBuscaCli').value=''; document.getElementById('gdCliBox').style.display='none'; document.getElementById('gdCliSel').innerHTML='👥 '+name+' <a href="javascript:void(0)" onclick="gdLimparCli()" style="color:#b91c1c;">×</a>'; }
function gdLimparCli(){ document.getElementById('gdClientId').value=''; document.getElementById('gdCliSel').innerHTML=''; }
</script>
<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
