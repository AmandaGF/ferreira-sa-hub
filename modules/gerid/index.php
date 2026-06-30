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
// Self-heal 29/06/2026: printscreen do GERID/INSS que o Luiz anexa ao concluir
// + colunas de tracking de tratamento pela Amanda (29/06 r2)
foreach (array(
    "ALTER TABLE gerid_pesquisas ADD COLUMN printscreen_nome VARCHAR(255) NULL",
    "ALTER TABLE gerid_pesquisas ADD COLUMN printscreen_path VARCHAR(255) NULL",
    "ALTER TABLE gerid_pesquisas ADD COLUMN printscreen_mime VARCHAR(80) NULL",
    "ALTER TABLE gerid_pesquisas ADD COLUMN tratado_em DATETIME NULL",
    "ALTER TABLE gerid_pesquisas ADD COLUMN tratado_por INT UNSIGNED NULL",
    "ALTER TABLE gerid_pesquisas ADD COLUMN task_review_id INT UNSIGNED NULL",
) as $_sqlAlter) { try { $pdo->exec($_sqlAlter); } catch (Exception $e) {} }

// Download autenticado do printscreen
if (isset($_GET['baixar'])) {
    $id = (int)$_GET['baixar'];
    $st = $pdo->prepare("SELECT printscreen_path, printscreen_nome, printscreen_mime FROM gerid_pesquisas WHERE id=?");
    $st->execute(array($id));
    $row = $st->fetch();
    $path = $row && $row['printscreen_path'] ? APP_ROOT . '/files/gerid/' . basename($row['printscreen_path']) : '';
    if (!$path || !is_file($path)) { http_response_code(404); die('Arquivo não encontrado.'); }
    header('Content-Type: ' . ($row['printscreen_mime'] ?: 'application/octet-stream'));
    header('Content-Disposition: inline; filename="' . preg_replace('/[^\w.\- ]/', '_', $row['printscreen_nome'] ?: 'gerid_print') . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path); exit;
}

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
    // Endpoint AJAX leve: marcar/desmarcar tratado (resposta JSON, sem redirect)
    if (($_POST['acao'] ?? '') === 'toggle_tratado') {
        header('Content-Type: application/json; charset=utf-8');
        if (!validate_csrf()) { echo json_encode(array('ok' => false, 'erro' => 'CSRF.')); exit; }
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(array('ok' => false, 'erro' => 'id.')); exit; }
        $st = $pdo->prepare("SELECT tratado_em, task_review_id FROM gerid_pesquisas WHERE id = ?");
        $st->execute(array($id));
        $row = $st->fetch();
        if (!$row) { echo json_encode(array('ok' => false, 'erro' => 'não encontrado.')); exit; }
        if ($row['tratado_em']) {
            // Estava tratado → volta pra não-tratado
            $pdo->prepare("UPDATE gerid_pesquisas SET tratado_em = NULL, tratado_por = NULL WHERE id = ?")
                ->execute(array($id));
            $tratado = false;
        } else {
            // Estava não-tratado → marca tratado
            $pdo->prepare("UPDATE gerid_pesquisas SET tratado_em = NOW(), tratado_por = ? WHERE id = ?")
                ->execute(array(current_user_id(), $id));
            // Fecha a tarefa de review se existir
            if (!empty($row['task_review_id'])) {
                try { $pdo->prepare("UPDATE case_tasks SET status='concluido', completed_at=NOW() WHERE id=?")->execute(array((int)$row['task_review_id'])); } catch (Exception $e) {}
            }
            $tratado = true;
        }
        audit_log('gerid_toggle_tratado', 'gerid', $id, $tratado ? 'tratado' : 'destratado');
        echo json_encode(array('ok' => true, 'tratado' => $tratado, 'em' => $tratado ? date('d/m/Y H:i') : null));
        exit;
    }

    if (!validate_csrf()) { flash_set('error', 'Sessão expirada.'); redirect(module_url('gerid')); }
    $acao = $_POST['acao'] ?? '';

    // 29/06/2026 Amanda: excluir pesquisa (duplicada ou erro). Marca task vinculada
    // como cancelada, fecha task_review se houver, registra andamento de remoção.
    if ($acao === 'excluir') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) { flash_set('error', 'ID inválido.'); redirect(module_url('gerid')); }
        $g = $pdo->prepare("SELECT * FROM gerid_pesquisas WHERE id=?");
        $g->execute(array($id));
        $row = $g->fetch();
        if (!$row) { flash_set('error', 'Pesquisa não encontrada.'); redirect(module_url('gerid')); }

        // Permissão: criador, Luiz Eduardo (pesquisador), admin ou gestão
        $userId = current_user_id();
        $role = current_user_role();
        $podeExcluir = in_array($role, array('admin','gestao'), true)
                    || (int)$row['created_by'] === $userId
                    || (int)$row['pesquisado_por'] === $userId;
        if (!$podeExcluir) { flash_set('error', 'Sem permissão pra excluir esta pesquisa.'); redirect(module_url('gerid')); }

        // Cancela tarefas vinculadas (pesquisa + review)
        try {
            if (!empty($row['task_id'])) {
                $pdo->prepare("UPDATE case_tasks SET status='concluido', completed_at=NOW(), descricao=CONCAT(IFNULL(descricao,''), '\n\n[CANCELADA: pesquisa GERID excluída]') WHERE id=?")
                    ->execute(array((int)$row['task_id']));
            }
            if (!empty($row['task_review_id'])) {
                $pdo->prepare("UPDATE case_tasks SET status='concluido', completed_at=NOW(), descricao=CONCAT(IFNULL(descricao,''), '\n\n[CANCELADA: pesquisa GERID excluída]') WHERE id=?")
                    ->execute(array((int)$row['task_review_id']));
            }
        } catch (Exception $e) {}

        // Andamento de remoção
        if (!empty($row['case_id'])) {
            try {
                $pdo->prepare(
                    "INSERT INTO case_andamentos (case_id, data_andamento, tipo, descricao, created_by, visivel_cliente, created_at)
                     VALUES (?, ?, 'gerid', ?, ?, 0, NOW())"
                )->execute(array(
                    (int)$row['case_id'], date('Y-m-d'),
                    '🗑️ Pesquisa GERID de ' . $row['parte_nome'] . ' excluída'
                    . ($row['status'] === 'concluida' ? ' (já estava concluída)' : ' (estava pendente)') . '.',
                    $userId,
                ));
            } catch (Exception $e) {}
        }

        // Remove printscreen do disco se houver
        if (!empty($row['printscreen_path'])) {
            $f = APP_ROOT . '/files/gerid/' . basename($row['printscreen_path']);
            if (is_file($f)) @unlink($f);
        }

        $pdo->prepare("DELETE FROM gerid_pesquisas WHERE id=?")->execute(array($id));
        audit_log('gerid_excluir', 'gerid', $id, $row['parte_nome']);
        flash_set('success', 'Pesquisa GERID excluída.');
        redirect(module_url('gerid'));
    }

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

            // 29/06/2026 Amanda: andamento INTERNO (visivel_cliente=0) registrando o
            // pedido, pra equipe ver na linha do tempo do processo e nao solicitar de
            // novo sem querer (caso Wallace Renan: 2 pedidos iguais no mesmo dia).
            try {
                $descAnd = '🔎 Solicitada pesquisa GERID de vínculo empregatício de ' . $parteNome
                         . ($parteCpf ? ' (CPF ' . $parteCpf . ')' : '')
                         . ($parente ? ' [' . $parente . ']' : '')
                         . '. Luiz Eduardo notificado'
                         . ($obs ? '. Obs: ' . $obs : '') . '.';
                $pdo->prepare(
                    "INSERT INTO case_andamentos (case_id, data_andamento, tipo, descricao, created_by, visivel_cliente, created_at)
                     VALUES (?, ?, 'gerid', ?, ?, 0, NOW())"
                )->execute(array($caseId, date('Y-m-d'), $descAnd, current_user_id()));
            } catch (Exception $e) {}
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
            // Upload printscreen INSS/GERID (opcional, PNG/JPG/PDF até 10MB)
            $psNome = null; $psPath = null; $psMime = null;
            if (!empty($_FILES['printscreen']) && (int)$_FILES['printscreen']['error'] === UPLOAD_ERR_OK) {
                $tmpUp = $_FILES['printscreen']['tmp_name'];
                $orig  = $_FILES['printscreen']['name'];
                $mime  = $_FILES['printscreen']['type'] ?: (function_exists('mime_content_type') ? mime_content_type($tmpUp) : 'application/octet-stream');
                $size  = (int)$_FILES['printscreen']['size'];
                $allowed = array('image/png','image/jpeg','image/jpg','application/pdf');
                if ($size <= 10 * 1024 * 1024 && in_array($mime, $allowed, true)) {
                    $dirUp = APP_ROOT . '/files/gerid';
                    if (!is_dir($dirUp)) @mkdir($dirUp, 0755, true);
                    $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $orig);
                    $stored = 'gerid_' . uniqid('', true) . '_' . $safe;
                    if (move_uploaded_file($tmpUp, $dirUp . '/' . $stored)) {
                        @chmod($dirUp . '/' . $stored, 0644);
                        $psNome = $orig; $psPath = $stored; $psMime = $mime;
                    }
                }
            }
            $pdo->prepare("UPDATE gerid_pesquisas SET status='concluida', tem_vinculo=?, resultado=?, pesquisado_por=?, pesquisado_em=NOW(),
                           printscreen_nome=COALESCE(?, printscreen_nome),
                           printscreen_path=COALESCE(?, printscreen_path),
                           printscreen_mime=COALESCE(?, printscreen_mime) WHERE id=?")
                ->execute(array($tem, $res ?: null, current_user_id(), $psNome, $psPath, $psMime, $id));
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
            // avisa quem pediu (notify + cria TAREFA na pasta pra revisar resultado)
            if (!empty($row['created_by']) && (int)$row['created_by'] !== current_user_id()) {
                $solicitante = (int)$row['created_by'];
                $detalheRes = ($tem ? 'POSSUI vínculo' : 'sem vínculo') . ($res ? ' — ' . $res : '');
                notify($solicitante, '🔎 Resultado da pesquisa GERID',
                    $row['parte_nome'] . ': ' . $detalheRes,
                    'info', url('modules/gerid/'), '🔎');

                // 29/06/2026 Amanda: criar tarefa de REVIEW na pasta pra Amanda
                // tomar providência (decidir próximo passo: pedir penhora de salário,
                // mandar oficio ao empregador, etc.). Só cria quando há case vinculado.
                if (!empty($row['case_id'])) {
                    try {
                        $tituloRev = '👀 Revisar resultado GERID — ' . $row['parte_nome']
                                   . ($tem ? ' (POSSUI vínculo)' : ' (sem vínculo)');
                        $descRev = 'Luiz Eduardo concluiu a pesquisa GERID de ' . $row['parte_nome']
                                 . ($row['parte_cpf'] ? ' (CPF ' . $row['parte_cpf'] . ')' : '')
                                 . '. Resultado: ' . $detalheRes
                                 . '. Decidir próximo passo (ofício ao empregador, penhora salário, arquivar etc.).';
                        $pdo->prepare("INSERT INTO case_tasks (case_id, title, tipo, descricao, assigned_to, due_date, prioridade, status, sort_order, created_at)
                                       VALUES (?,?,?,?,?,?,?,?,?,NOW())")
                            ->execute(array((int)$row['case_id'], $tituloRev, 'outros', $descRev,
                                            $solicitante, date('Y-m-d', strtotime('+3 days')),
                                            $tem ? 'alta' : 'media', 'a_fazer', 0));
                        $taskRevId = (int)$pdo->lastInsertId();
                        $pdo->prepare("UPDATE gerid_pesquisas SET task_review_id = ? WHERE id = ?")
                            ->execute(array($taskRevId, $id));
                    } catch (Exception $e) {}
                }
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
.gd-pend { background:#fef3c7;color:#92400e; }
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
  <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;">
    <div style="font-weight:700;color:#0e7490;flex:1;">👤 <?= e($g['parte_nome']) ?><?= $g['parte_cpf'] ? ' · CPF ' . e($g['parte_cpf']) : '' ?><?= $g['parente'] ? ' · ' . e($g['parente']) : '' ?></div>
    <form method="post" action="<?= module_url('gerid') ?>" onsubmit="return confirm('Excluir definitivamente esta pesquisa GERID? Tarefas vinculadas serão canceladas.');" style="margin:0;">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="acao" value="excluir">
      <input type="hidden" name="id" value="<?= (int)$g['id'] ?>">
      <button type="submit" title="Excluir pesquisa (cancela tarefas vinculadas)" style="background:#fef2f2;color:#b91c1c;border:1px solid #fecaca;border-radius:6px;padding:3px 8px;font-size:.7rem;font-weight:700;cursor:pointer;">🗑️ Excluir</button>
    </form>
  </div>
  <div style="color:#666;font-size:.83rem;margin-top:3px;">
    <?php if ($proc): ?>
        <?php if (!empty($g['case_id'])): ?>
            📄 <a href="<?= module_url('operacional', 'caso_ver.php?id=' . (int)$g['case_id']) ?>" style="color:inherit;text-decoration:underline;text-decoration-style:dotted;" title="Abrir pasta do processo"><?= e($proc) ?></a> ·
        <?php else: ?>
            📄 <?= e($proc) ?> ·
        <?php endif; ?>
    <?php endif; ?>
    <?= $g['client_name'] ? '👥 ' . e($g['client_name']) . ' · ' : '' ?>
    pedido por <?= e($g['reg_por'] ?: '—') ?> em <?= date('d/m/Y', strtotime($g['created_at'])) ?>
  </div>
  <?php if ($g['observacao']): ?><div style="font-size:.83rem;margin-top:5px;color:#444;"><?= e($g['observacao']) ?></div><?php endif; ?>
  <form method="post" action="<?= module_url('gerid') ?>" enctype="multipart/form-data" style="margin-top:10px;border-top:1px solid #f0f0f0;padding-top:10px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
    <input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="acao" value="resultado"><input type="hidden" name="id" value="<?= (int)$g['id'] ?>">
    <span style="font-size:.82rem;font-weight:700;">Resultado:</span>
    <label style="font-size:.85rem;display:flex;align-items:center;gap:5px;"><input type="radio" name="tem_vinculo" value="1" required> ✅ Tem vínculo</label>
    <label style="font-size:.85rem;display:flex;align-items:center;gap:5px;"><input type="radio" name="tem_vinculo" value="0"> ❌ Sem vínculo</label>
    <input type="text" name="resultado" class="gd-input" style="flex:1;min-width:200px;width:auto;" placeholder="Empregador / detalhes (opcional)">
    <label style="font-size:.75rem;display:flex;align-items:center;gap:5px;color:#475569;background:#f1f5f9;border:1px dashed #94a3b8;border-radius:6px;padding:5px 9px;cursor:pointer;">📸 Print INSS:
      <input type="file" name="printscreen" accept="image/*,.pdf" style="font-size:.74rem;max-width:170px;">
    </label>
    <button type="submit" class="gd-btn" style="padding:7px 12px;">Registrar</button>
  </form>
</div>
<?php endforeach; endif; ?>

<h3 style="margin:22px 0 8px;">✅ Concluídas</h3>
<?php if (!$concluidas): ?><div class="gd-empty">Nenhuma ainda.</div><?php else: ?>
<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.06);">
  <thead><tr style="background:#fafafa;font-size:.72rem;text-transform:uppercase;color:#888;"><th style="padding:9px 11px;text-align:left;">Parte</th><th style="padding:9px 11px;text-align:left;">Processo</th><th style="padding:9px 11px;text-align:left;">Vínculo</th><th style="padding:9px 11px;text-align:left;">Detalhe</th><th style="padding:9px 11px;text-align:left;">Pesquisado por</th><th style="padding:9px 11px;text-align:left;">Tratado?</th><th style="padding:9px 11px;text-align:center;width:60px;"></th></tr></thead>
  <tbody>
  <?php foreach ($concluidas as $g): ?>
    <tr style="border-bottom:1px solid #f0f0f0;font-size:.85rem;" id="gd-row-<?= (int)$g['id'] ?>">
      <td style="padding:9px 11px;"><?= e($g['parte_nome']) ?><?= $g['parte_cpf'] ? '<br><span style="color:#999;font-size:.78rem;">' . e($g['parte_cpf']) . '</span>' : '' ?></td>
      <td style="padding:9px 11px;">
        <?php $_procTxt = $g['case_number'] ?: ($g['case_title'] ?: '—'); ?>
        <?php if (!empty($g['case_id']) && $_procTxt !== '—'): ?>
          <a href="<?= module_url('operacional', 'caso_ver.php?id=' . (int)$g['case_id']) ?>" style="color:#0c4a6e;text-decoration:underline;text-decoration-style:dotted;font-weight:600;" title="Abrir pasta do processo"><?= e($_procTxt) ?></a>
        <?php else: ?>
          <?= e($_procTxt) ?>
        <?php endif; ?>
      </td>
      <td style="padding:9px 11px;"><span class="gd-chip <?= $g['tem_vinculo'] ? 'gd-sim' : 'gd-nao' ?>"><?= $g['tem_vinculo'] ? 'POSSUI' : 'Sem vínculo' ?></span></td>
      <td style="padding:9px 11px;"><?= e($g['resultado'] ?: '—') ?>
        <?php if (!empty($g['printscreen_path'])): ?>
          <br><a href="?baixar=<?= (int)$g['id'] ?>" target="_blank" rel="noopener" style="font-size:.72rem;color:#0c4a6e;text-decoration:none;font-weight:600;">📸 Ver print INSS</a>
        <?php endif; ?>
      </td>
      <td style="padding:9px 11px;"><?= e($g['pesq_por'] ?: '—') ?><br><span style="color:#999;font-size:.78rem;"><?= $g['pesquisado_em'] ? date('d/m/Y', strtotime($g['pesquisado_em'])) : '' ?></span></td>
      <td style="padding:9px 11px;">
        <button type="button" id="gd-trat-<?= (int)$g['id'] ?>" onclick="gdToggleTratado(<?= (int)$g['id'] ?>)"
                class="gd-chip <?= $g['tratado_em'] ? 'gd-sim' : 'gd-pend' ?>"
                style="border:none;cursor:pointer;font-family:inherit;">
          <?= $g['tratado_em'] ? '✓ Tratado' : '⏳ Não tratado' ?>
        </button>
        <?php if ($g['tratado_em']): ?>
          <br><span style="color:#999;font-size:.7rem;"><?= date('d/m H:i', strtotime($g['tratado_em'])) ?></span>
        <?php endif; ?>
      </td>
      <td style="padding:9px 11px;text-align:center;">
        <form method="post" action="<?= module_url('gerid') ?>" onsubmit="return confirm('Excluir definitivamente esta pesquisa GERID concluída? Esta ação não pode ser desfeita.');" style="margin:0;">
          <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
          <input type="hidden" name="acao" value="excluir">
          <input type="hidden" name="id" value="<?= (int)$g['id'] ?>">
          <button type="submit" title="Excluir pesquisa" style="background:none;color:#b91c1c;border:none;cursor:pointer;font-size:.95rem;padding:2px 6px;">🗑️</button>
        </form>
      </td>
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

var GD_CSRF = '<?= $csrf ?>';
function gdToggleTratado(id) {
  var btn = document.getElementById('gd-trat-' + id);
  if (!btn) return;
  btn.disabled = true; var textoAntigo = btn.textContent; btn.textContent = '⏳ ...';
  var fd = new FormData();
  fd.append('acao', 'toggle_tratado');
  fd.append('csrf_token', GD_CSRF);
  fd.append('id', id);
  fetch(GD_URL, { method: 'POST', body: fd, credentials: 'same-origin' })
    .then(function(r) {
      if (r.status === 401) { if (window.fsaMostrarSessaoExpirada) window.fsaMostrarSessaoExpirada(); throw new Error('401'); }
      return r.json();
    })
    .then(function(j) {
      btn.disabled = false;
      if (!j.ok) { btn.textContent = textoAntigo; alert('Erro: ' + (j.erro || '?')); return; }
      if (j.tratado) {
        btn.className = 'gd-chip gd-sim';
        btn.style.border = 'none'; btn.style.cursor = 'pointer'; btn.style.fontFamily = 'inherit';
        btn.textContent = '✓ Tratado';
        // Mostra data abaixo
        var par = btn.parentNode;
        var dataLine = par.querySelector('.gd-trat-em');
        if (!dataLine) {
          dataLine = document.createElement('span');
          dataLine.className = 'gd-trat-em';
          dataLine.style.cssText = 'color:#999;font-size:.7rem;display:block;margin-top:2px;';
          par.appendChild(dataLine);
        }
        dataLine.textContent = j.em;
      } else {
        btn.className = 'gd-chip gd-pend';
        btn.style.border = 'none'; btn.style.cursor = 'pointer'; btn.style.fontFamily = 'inherit';
        btn.textContent = '⏳ Não tratado';
        var dataLine = btn.parentNode.querySelector('.gd-trat-em');
        if (dataLine) dataLine.remove();
      }
    })
    .catch(function(e) {
      btn.disabled = false; btn.textContent = textoAntigo;
    });
}
</script>
<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
