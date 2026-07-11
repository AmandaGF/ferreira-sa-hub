<?php
/**
 * Presença — Fornecedores + Orçamentos com score de custo-benefício.
 * A comparação é pelo CUSTO TOTAL do lote (não só unitário) + prazo +
 * qualidade + adequação da quantidade mínima ao lote de compra.
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
require_access('presenca');

$pdo = db();
$pageTitle = 'Presença — Fornecedores & Orçamentos';

$UPLOAD_DIR = APP_ROOT . '/files/presenca/orcamentos';
$UPLOAD_URL = url('files/presenca/orcamentos');

// ── Recalcula scores dos orcamentos de um brinde ──
function _pr_recalcular_scores(PDO $pdo, $brindeId) {
    $cfg = array();
    foreach ($pdo->query("SELECT chave, valor FROM presenca_config WHERE chave LIKE 'peso_%'") as $r) $cfg[$r['chave']] = (float)$r['valor'];
    $pP = $cfg['peso_preco'] ?? 0.45; $pT = $cfg['peso_prazo'] ?? 0.20;
    $pQ = $cfg['peso_qualidade'] ?? 0.25; $pM = $cfg['peso_qtd'] ?? 0.10;

    $st = $pdo->prepare("SELECT o.id, o.valor_unitario, o.qtd_minima, o.frete, o.prazo_producao_dias, o.prazo_entrega_dias, o.nota_qualidade,
                                b.qtd_compra_referencia
                         FROM presenca_orcamento o JOIN presenca_brinde b ON b.id = o.brinde_id
                         WHERE o.brinde_id = ?");
    $st->execute(array($brindeId));
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) === 0) return;

    $tot = array(); $prz = array();
    foreach ($rows as $r) {
        $qtdRef = max(1, (int)$r['qtd_compra_referencia']);
        $rows[$r['id']]['custo_total'] = ((float)$r['valor_unitario']) * $qtdRef + (float)$r['frete'];
        $rows[$r['id']]['prazo_total'] = (int)($r['prazo_producao_dias'] ?? 0) + (int)($r['prazo_entrega_dias'] ?? 0);
        $tot[] = $rows[$r['id']]['custo_total'];
        $prz[] = $rows[$r['id']]['prazo_total'];
    }
    // Substitui rows[$id] agrupamentos (o array veio sem chave por id acima; corrige)
    $byId = array();
    foreach ($rows as $r) if (is_array($r) && isset($r['id'])) $byId[$r['id']] = $r;
    $tot = array(); $prz = array();
    foreach ($byId as $r) { $tot[] = $r['custo_total']; $prz[] = $r['prazo_total']; }
    $totMin = min($tot); $totMax = max($tot);
    $przMin = min($prz); $przMax = max($prz);

    $upd = $pdo->prepare("UPDATE presenca_orcamento SET score = ? WHERE id = ?");
    foreach ($byId as $r) {
        // Normalizações (invertido: menor é melhor)
        $normP = ($totMax > $totMin) ? 1 - (($r['custo_total'] - $totMin) / ($totMax - $totMin)) : 1;
        $normT = ($przMax > $przMin) ? 1 - (($r['prazo_total'] - $przMin) / ($przMax - $przMin)) : 1;
        $normQ = ((int)($r['nota_qualidade'] ?? 3)) / 5;
        $qtdRef = max(1, (int)$r['qtd_compra_referencia']);
        $normM = ((int)$r['qtd_minima'] <= $qtdRef) ? 1 : max(0, 1 - (($r['qtd_minima'] - $qtdRef) / max(1, $qtdRef)));

        $score = 100 * ($pP * $normP + $pT * $normT + $pQ * $normQ + $pM * $normM);
        $score = round($score, 2);
        $upd->execute(array($score, $r['id']));
    }
    // Marca escolhido = melhor score
    $pdo->prepare("UPDATE presenca_orcamento SET escolhido = 0 WHERE brinde_id = ?")->execute(array($brindeId));
    $pdo->prepare("UPDATE presenca_orcamento SET escolhido = 1 WHERE brinde_id = ? ORDER BY score DESC LIMIT 1")->execute(array($brindeId));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) { flash_set('error','Sessão expirada.'); redirect(module_url('presenca','fornecedores.php')); }
    $acao = $_POST['acao'] ?? '';

    if ($acao === 'salvar_fornecedor') {
        $id       = (int)($_POST['id'] ?? 0);
        $nome     = clean_str($_POST['nome'] ?? '', 120);
        $cont     = clean_str($_POST['contato_nome'] ?? '', 120);
        $tel      = clean_str($_POST['telefone'] ?? '', 40);
        $email    = clean_str($_POST['email'] ?? '', 120);
        $site     = clean_str($_POST['site'] ?? '', 160);
        $obs      = clean_str($_POST['observacoes'] ?? '', 2000);
        $ativo    = !empty($_POST['ativo']) ? 1 : 0;
        if (!$nome) { flash_set('error','Nome obrigatório.'); redirect(module_url('presenca','fornecedores.php')); }
        if ($id > 0) {
            $pdo->prepare("UPDATE presenca_fornecedor SET nome=?, contato_nome=?, telefone=?, email=?, site=?, observacoes=?, ativo=? WHERE id=?")
                ->execute(array($nome,$cont,$tel,$email,$site,$obs,$ativo,$id));
            audit_log('presenca_fornecedor_edit','presenca_fornecedor',$id,$nome);
        } else {
            $pdo->prepare("INSERT INTO presenca_fornecedor (nome,contato_nome,telefone,email,site,observacoes,ativo) VALUES (?,?,?,?,?,?,?)")
                ->execute(array($nome,$cont,$tel,$email,$site,$obs,$ativo));
            audit_log('presenca_fornecedor_new','presenca_fornecedor',(int)$pdo->lastInsertId(),$nome);
        }
        flash_set('success','Fornecedor salvo.');
        redirect(module_url('presenca','fornecedores.php'));
    }

    if ($acao === 'toggle_ativo') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) $pdo->prepare("UPDATE presenca_fornecedor SET ativo = 1 - ativo WHERE id = ?")->execute(array($id));
        redirect(module_url('presenca','fornecedores.php'));
    }

    if ($acao === 'salvar_orcamento') {
        $brId  = (int)($_POST['brinde_id'] ?? 0);
        $fId   = (int)($_POST['fornecedor_id'] ?? 0);
        $vu    = (float)str_replace(',','.', str_replace('.','', $_POST['valor_unitario'] ?? ''));
        $qmin  = max(1, (int)($_POST['qtd_minima'] ?? 1));
        $frete = (float)str_replace(',','.', str_replace('.','', $_POST['frete'] ?? '0'));
        $prazoP = $_POST['prazo_producao_dias'] === '' ? null : (int)$_POST['prazo_producao_dias'];
        $prazoE = $_POST['prazo_entrega_dias'] === '' ? null : (int)$_POST['prazo_entrega_dias'];
        $nota   = $_POST['nota_qualidade'] === '' ? null : max(1, min(5, (int)$_POST['nota_qualidade']));
        $val    = $_POST['validade_ate'] !== '' ? $_POST['validade_ate'] : null;
        $link   = clean_str($_POST['link_proposta'] ?? '', 255);
        $obs    = clean_str($_POST['observacoes'] ?? '', 2000);
        if (!$brId || !$fId || $vu <= 0) { flash_set('error','Brinde, fornecedor e valor unitário são obrigatórios.'); redirect(module_url('presenca','fornecedores.php')); }

        // Upload proposta
        $arqSalvo = null;
        if (!empty($_FILES['arquivo_proposta']) && $_FILES['arquivo_proposta']['error'] === UPLOAD_ERR_OK) {
            $tmp = $_FILES['arquivo_proposta']['tmp_name'];
            $sz = (int)$_FILES['arquivo_proposta']['size'];
            $mime = function_exists('finfo_open') ? (function() use ($tmp){ $f = finfo_open(FILEINFO_MIME_TYPE); $m = finfo_file($f, $tmp); finfo_close($f); return $m; })() : $_FILES['arquivo_proposta']['type'];
            $extMap = array('application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png');
            if ($sz <= 5*1024*1024 && isset($extMap[$mime])) {
                if (!is_dir($UPLOAD_DIR)) @mkdir($UPLOAD_DIR, 0755, true);
                $fn = 'orc_' . $brId . '_' . bin2hex(random_bytes(6)) . '.' . $extMap[$mime];
                if (move_uploaded_file($tmp, $UPLOAD_DIR . '/' . $fn)) {
                    @chmod($UPLOAD_DIR . '/' . $fn, 0644);
                    $arqSalvo = $fn;
                }
            }
        }

        $pdo->prepare("INSERT INTO presenca_orcamento (brinde_id,fornecedor_id,valor_unitario,qtd_minima,frete,prazo_producao_dias,prazo_entrega_dias,nota_qualidade,validade_ate,link_proposta,arquivo_proposta,observacoes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute(array($brId,$fId,$vu,$qmin,$frete,$prazoP,$prazoE,$nota,$val,$link,$arqSalvo,$obs));
        $orcId = (int)$pdo->lastInsertId();
        _pr_recalcular_scores($pdo, $brId);
        audit_log('presenca_orcamento_new','presenca_orcamento',$orcId,"brinde=$brId forn=$fId");
        flash_set('success','Orçamento registrado. Score recalculado.');
        redirect(module_url('presenca','fornecedores.php').'?brinde='.$brId);
    }

    if ($acao === 'excluir_orcamento') {
        $id = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare("SELECT brinde_id, arquivo_proposta FROM presenca_orcamento WHERE id = ?");
        $st->execute(array($id));
        $row = $st->fetch();
        if ($row) {
            if ($row['arquivo_proposta']) { $abs = $UPLOAD_DIR . '/' . basename($row['arquivo_proposta']); if (is_file($abs)) @unlink($abs); }
            $pdo->prepare("DELETE FROM presenca_orcamento WHERE id = ?")->execute(array($id));
            _pr_recalcular_scores($pdo, (int)$row['brinde_id']);
            audit_log('presenca_orcamento_del','presenca_orcamento',$id,'');
            flash_set('success','Orçamento excluído.');
            redirect(module_url('presenca','fornecedores.php').'?brinde='.(int)$row['brinde_id']);
        }
        redirect(module_url('presenca','fornecedores.php'));
    }
}

$fornecedores = $pdo->query("SELECT f.*, (SELECT COUNT(*) FROM presenca_orcamento WHERE fornecedor_id=f.id) AS orcs FROM presenca_fornecedor f ORDER BY f.ativo DESC, f.nome")->fetchAll(PDO::FETCH_ASSOC);
$brindes = $pdo->query("SELECT id, nome, categoria, qtd_compra_referencia FROM presenca_brinde WHERE ativo=1 ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);

$editar = null;
if (isset($_GET['editar_f']) && (int)$_GET['editar_f'] > 0) {
    $st = $pdo->prepare("SELECT * FROM presenca_fornecedor WHERE id = ?");
    $st->execute(array((int)$_GET['editar_f']));
    $editar = $st->fetch(PDO::FETCH_ASSOC);
}

// Comparativo por brinde
$brindeVer = isset($_GET['brinde']) ? (int)$_GET['brinde'] : 0;
$orcamentos = array();
$brindeInfo = null;
if ($brindeVer > 0) {
    $st = $pdo->prepare("SELECT * FROM presenca_brinde WHERE id = ?");
    $st->execute(array($brindeVer));
    $brindeInfo = $st->fetch(PDO::FETCH_ASSOC);
    $st = $pdo->prepare("SELECT o.*, f.nome AS forn_nome FROM presenca_orcamento o JOIN presenca_fornecedor f ON f.id = o.fornecedor_id WHERE o.brinde_id = ? ORDER BY o.score DESC, o.valor_unitario ASC");
    $st->execute(array($brindeVer));
    $orcamentos = $st->fetchAll(PDO::FETCH_ASSOC);
}

$csrf = generate_csrf_token();
require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.pf-hero { display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; flex-wrap:wrap; gap:10px; }
.pf-hero h1 { margin:0; font-family:'Cormorant Garamond',Georgia,serif; font-size:1.6rem; font-weight:600; color:#0E2E36; }
.pf-back { display:inline-flex; align-items:center; gap:5px; padding:5px 12px; background:#fff; border:1px solid #e5e7eb; border-radius:8px; text-decoration:none; color:#0E2E36; font-size:.78rem; font-weight:600; }

.pf-tabs { display:flex; gap:4px; margin-bottom:16px; border-bottom:2px solid #e5e7eb; padding-bottom:0; }
.pf-tab { padding:10px 20px; background:none; border:none; border-bottom:3px solid transparent; font-size:.85rem; font-weight:700; color:#6b7280; cursor:pointer; margin-bottom:-2px; text-decoration:none; }
.pf-tab.active { color:#B87333; border-bottom-color:#B87333; }

.pf-form { background:#fff; border:1.5px solid #d7ab90; border-radius:12px; padding:20px 24px; margin-bottom:20px; box-shadow:0 4px 12px rgba(215,171,144,.15); }
.pf-form h3 { margin:0 0 14px; font-size:1.05rem; color:#0E2E36; }
.pf-form label { display:block; font-size:.72rem; font-weight:700; color:#6b7280; margin-bottom:4px; text-transform:uppercase; letter-spacing:.03em; }
.pf-form input, .pf-form select, .pf-form textarea { width:100%; border:1.5px solid #e5e7eb; border-radius:8px; padding:8px 10px; font-size:.88rem; font-family:inherit; }
.pf-form input:focus, .pf-form select:focus, .pf-form textarea:focus { outline:none; border-color:#B87333; box-shadow:0 0 0 3px rgba(184,115,51,.15); }
.pf-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:12px; }
.pf-actions { display:flex; gap:8px; margin-top:14px; }
.pf-actions button, .pf-actions .btn-link { background:#0E2E36; color:#fff; border:none; border-radius:8px; padding:9px 18px; font-size:.85rem; font-weight:700; cursor:pointer; text-decoration:none; }
.pf-actions .btn-link { background:#fff; color:#0E2E36; border:1px solid #d1d5db; }

.pf-forn-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(280px,1fr)); gap:12px; }
.pf-forn-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:14px 16px; box-shadow:0 1px 3px rgba(0,0,0,.04); }
.pf-forn-card.inativo { opacity:.55; }
.pf-forn-card .nome { font-weight:800; color:#0E2E36; font-size:1rem; margin-bottom:4px; }
.pf-forn-card .contato { font-size:.78rem; color:#6b7280; margin-bottom:6px; }
.pf-forn-card .info { font-size:.78rem; color:#374151; margin-bottom:4px; }
.pf-forn-card .info a { color:#0E2E36; text-decoration:none; font-weight:600; }
.pf-forn-card .footer { margin-top:10px; padding-top:10px; border-top:1px solid #f3f4f6; display:flex; gap:6px; justify-content:space-between; align-items:center; }
.pf-btn { background:#fff; color:#0E2E36; border:1px solid #d1d5db; border-radius:6px; padding:4px 10px; font-size:.72rem; font-weight:700; cursor:pointer; text-decoration:none; }
.pf-btn:hover { border-color:#B87333; color:#B87333; }
.pf-btn.warn { color:#dc2626; border-color:#fecaca; }
.pf-orcs-badge { background:#f5ede3; color:#78350f; padding:2px 8px; border-radius:999px; font-size:.7rem; font-weight:700; }

.pf-tabela { width:100%; border-collapse:collapse; background:#fff; border-radius:12px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,.05); }
.pf-tabela th, .pf-tabela td { padding:10px 12px; text-align:left; border-bottom:1px solid #f3f4f6; font-size:.82rem; }
.pf-tabela th { background:#fafafa; font-size:.68rem; font-weight:700; color:#6b7280; text-transform:uppercase; letter-spacing:.05em; }
.pf-tabela tr.escolhido { background:linear-gradient(90deg,#f0fdf4,#fff); border-left:4px solid #16a34a; }
.pf-tabela tr.escolhido td:first-child::before { content:'✓ '; color:#16a34a; font-weight:800; }
.pf-tabela .score { display:inline-block; padding:3px 10px; border-radius:999px; font-weight:800; font-size:.75rem; }
.pf-tabela .score.top { background:#16a34a; color:#fff; }
.pf-tabela .score.mid { background:#f5ede3; color:#78350f; }
.pf-tabela .score.low { background:#fee2e2; color:#7f1d1d; }
</style>

<div class="pf-hero">
    <div>
        <h1>🏭 Fornecedores &amp; Orçamentos</h1>
        <div style="font-size:.85rem;color:#6b7280;margin-top:4px;">Score de custo-benefício = preço (peso .45) + prazo (.20) + qualidade (.25) + adequação de quantidade (.10)</div>
    </div>
    <a href="<?= module_url('presenca') ?>" class="pf-back">← Voltar</a>
</div>

<?php $abaAtual = isset($_GET['orc']) || $brindeVer > 0 ? 'orc' : 'forn'; ?>
<div class="pf-tabs">
    <a href="?" class="pf-tab <?= $abaAtual === 'forn' ? 'active' : '' ?>">🏭 Fornecedores</a>
    <a href="?orc=1" class="pf-tab <?= $abaAtual === 'orc' ? 'active' : '' ?>">💰 Comparar orçamentos (por brinde)</a>
</div>

<?php if ($abaAtual === 'forn'): ?>

<div class="pf-form">
    <h3><?= $editar ? '✏️ Editando: ' . e($editar['nome']) : '➕ Novo fornecedor' ?></h3>
    <form method="POST">
        <?= csrf_input() ?>
        <input type="hidden" name="acao" value="salvar_fornecedor">
        <?php if ($editar): ?><input type="hidden" name="id" value="<?= (int)$editar['id'] ?>"><?php endif; ?>
        <div class="pf-grid">
            <div style="grid-column:span 2;"><label>Nome *</label><input type="text" name="nome" required maxlength="120" value="<?= e($editar['nome'] ?? '') ?>"></div>
            <div><label>Pessoa de contato</label><input type="text" name="contato_nome" maxlength="120" value="<?= e($editar['contato_nome'] ?? '') ?>"></div>
            <div><label>Telefone</label><input type="text" name="telefone" maxlength="40" value="<?= e($editar['telefone'] ?? '') ?>"></div>
            <div><label>E-mail</label><input type="text" name="email" maxlength="120" value="<?= e($editar['email'] ?? '') ?>"></div>
            <div><label>Site</label><input type="text" name="site" maxlength="160" value="<?= e($editar['site'] ?? '') ?>" placeholder="https://..."></div>
            <div><label>Ativo?</label><div style="padding-top:10px;"><input type="checkbox" name="ativo" id="ativoFn" <?= empty($editar)||!empty($editar['ativo'])?'checked':'' ?>><label for="ativoFn" style="display:inline;font-size:.85rem;color:#0E2E36;font-weight:600;text-transform:none;letter-spacing:0;margin-left:4px;">Sim</label></div></div>
            <div style="grid-column:1 / -1;"><label>Observações</label><textarea name="observacoes" maxlength="2000"><?= e($editar['observacoes'] ?? '') ?></textarea></div>
        </div>
        <div class="pf-actions">
            <button type="submit">💾 <?= $editar ? 'Salvar' : 'Criar' ?></button>
            <?php if ($editar): ?><a href="<?= module_url('presenca','fornecedores.php') ?>" class="btn-link">Cancelar</a><?php endif; ?>
        </div>
    </form>
</div>

<div class="pf-forn-grid">
    <?php foreach ($fornecedores as $f): ?>
    <div class="pf-forn-card <?= $f['ativo']?'':'inativo' ?>">
        <div class="nome"><?= e($f['nome']) ?></div>
        <?php if (!empty($f['contato_nome'])): ?><div class="contato">👤 <?= e($f['contato_nome']) ?></div><?php endif; ?>
        <?php if (!empty($f['telefone'])): ?><div class="info">📞 <?= e($f['telefone']) ?></div><?php endif; ?>
        <?php if (!empty($f['email'])): ?><div class="info">✉️ <a href="mailto:<?= e($f['email']) ?>"><?= e($f['email']) ?></a></div><?php endif; ?>
        <?php if (!empty($f['site'])): ?><div class="info">🔗 <a href="<?= e($f['site']) ?>" target="_blank" rel="noopener">site</a></div><?php endif; ?>
        <div class="footer">
            <span class="pf-orcs-badge">📄 <?= (int)$f['orcs'] ?> orçamento(s)</span>
            <div style="display:flex;gap:4px;">
                <a href="?editar_f=<?= (int)$f['id'] ?>" class="pf-btn">✏️</a>
                <form method="POST" style="display:inline;"><?= csrf_input() ?><input type="hidden" name="acao" value="toggle_ativo"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>"><button type="submit" class="pf-btn <?= $f['ativo']?'warn':'' ?>"><?= $f['ativo']?'⏸':'▶' ?></button></form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php else: /* aba orçamentos */ ?>

<div class="pf-form">
    <h3>➕ Novo orçamento</h3>
    <form method="POST" enctype="multipart/form-data">
        <?= csrf_input() ?>
        <input type="hidden" name="acao" value="salvar_orcamento">
        <div class="pf-grid">
            <div style="grid-column:span 2;">
                <label>Brinde *</label>
                <select name="brinde_id" required>
                    <option value="">— Selecionar —</option>
                    <?php foreach ($brindes as $b): ?>
                        <option value="<?= (int)$b['id'] ?>" <?= $brindeVer === (int)$b['id']?'selected':'' ?>><?= e($b['nome']) ?> (ref. compra: <?= (int)$b['qtd_compra_referencia'] ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="grid-column:span 2;">
                <label>Fornecedor *</label>
                <select name="fornecedor_id" required>
                    <option value="">— Selecionar —</option>
                    <?php foreach ($fornecedores as $f): if (!$f['ativo']) continue; ?>
                        <option value="<?= (int)$f['id'] ?>"><?= e($f['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div><label>Valor unitário (R$) *</label><input type="text" name="valor_unitario" required placeholder="24,00"></div>
            <div><label>Quantidade mínima</label><input type="number" name="qtd_minima" min="1" value="1"></div>
            <div><label>Frete (R$)</label><input type="text" name="frete" value="0"></div>
            <div><label>Prazo produção (dias úteis)</label><input type="number" name="prazo_producao_dias" min="0"></div>
            <div><label>Prazo entrega (dias úteis)</label><input type="number" name="prazo_entrega_dias" min="0"></div>
            <div><label>Nota qualidade (1-5)</label><input type="number" name="nota_qualidade" min="1" max="5"></div>
            <div><label>Validade da proposta</label><input type="date" name="validade_ate"></div>
            <div style="grid-column:span 2;"><label>Link da proposta</label><input type="text" name="link_proposta" placeholder="https://..."></div>
            <div style="grid-column:span 2;"><label>Arquivo (PDF/JPG/PNG até 5MB)</label><input type="file" name="arquivo_proposta" accept="application/pdf,image/jpeg,image/png"></div>
            <div style="grid-column:1 / -1;"><label>Observações</label><textarea name="observacoes" maxlength="2000"></textarea></div>
        </div>
        <div class="pf-actions">
            <button type="submit">💾 Registrar orçamento</button>
        </div>
    </form>
</div>

<h3 style="font-family:'Cormorant Garamond',Georgia,serif;color:#0E2E36;font-size:1.3rem;margin:20px 0 10px;">🔍 Comparativo por brinde</h3>
<form method="GET" style="margin-bottom:12px;">
    <input type="hidden" name="orc" value="1">
    <select name="brinde" onchange="this.form.submit()" style="padding:8px 10px;border:1.5px solid #e5e7eb;border-radius:8px;font-size:.88rem;min-width:280px;">
        <option value="0">— Selecione um brinde pra ver os orçamentos —</option>
        <?php foreach ($brindes as $b): ?>
            <option value="<?= (int)$b['id'] ?>" <?= $brindeVer === (int)$b['id']?'selected':'' ?>><?= e($b['nome']) ?></option>
        <?php endforeach; ?>
    </select>
</form>

<?php if ($brindeInfo && !empty($orcamentos)):
    $qtdRef = (int)$brindeInfo['qtd_compra_referencia']; ?>
<div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:14px 18px;margin-bottom:12px;font-size:.85rem;color:#0E2E36;">
    Comparando <strong><?= e($brindeInfo['nome']) ?></strong> — lote de compra: <strong><?= $qtdRef ?> un.</strong>
</div>

<div style="overflow-x:auto;">
<table class="pf-tabela">
    <thead>
        <tr>
            <th>Fornecedor</th><th>Unit.</th><th>Qtd. mín.</th><th>Frete</th>
            <th>Custo total (<?= $qtdRef ?>)</th><th>Prazo</th><th>Qualidade</th><th>Score</th><th>Ações</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($orcamentos as $o):
        $custoTotal = ($o['valor_unitario'] * $qtdRef) + $o['frete'];
        $prazoT = (int)($o['prazo_producao_dias'] ?? 0) + (int)($o['prazo_entrega_dias'] ?? 0);
        $sc = (float)($o['score'] ?? 0);
        $scClass = $sc >= 85 ? 'top' : ($sc >= 65 ? 'mid' : 'low');
    ?>
        <tr class="<?= $o['escolhido']?'escolhido':'' ?>">
            <td style="font-weight:700;color:#0E2E36;"><?= e($o['forn_nome']) ?></td>
            <td>R$ <?= number_format($o['valor_unitario'], 2, ',', '.') ?></td>
            <td><?= (int)$o['qtd_minima'] ?><?php if ((int)$o['qtd_minima'] > $qtdRef): ?> <span style="color:#dc2626;font-weight:700;" title="Mínimo maior que o lote de compra">⚠</span><?php endif; ?></td>
            <td>R$ <?= number_format($o['frete'], 2, ',', '.') ?></td>
            <td style="font-weight:800;">R$ <?= number_format($custoTotal, 2, ',', '.') ?></td>
            <td><?= $prazoT ?> d</td>
            <td><?= $o['nota_qualidade'] ? str_repeat('⭐', (int)$o['nota_qualidade']) : '—' ?></td>
            <td><span class="score <?= $scClass ?>"><?= number_format($sc, 0, ',', '') ?></span></td>
            <td>
                <?php if (!empty($o['arquivo_proposta'])): ?>
                    <a href="<?= e($UPLOAD_URL . '/' . $o['arquivo_proposta']) ?>" target="_blank" rel="noopener" class="pf-btn">📄 PDF</a>
                <?php elseif (!empty($o['link_proposta'])): ?>
                    <a href="<?= e($o['link_proposta']) ?>" target="_blank" rel="noopener" class="pf-btn">🔗 Link</a>
                <?php endif; ?>
                <form method="POST" style="display:inline;" onsubmit="return confirm('Excluir este orçamento? O score será recalculado.')">
                    <?= csrf_input() ?><input type="hidden" name="acao" value="excluir_orcamento"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
                    <button type="submit" class="pf-btn warn">🗑</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php elseif ($brindeVer > 0): ?>
    <div style="background:#fff;border:1px dashed #d1d5db;border-radius:12px;padding:30px;text-align:center;color:#6b7280;">
        Nenhum orçamento cadastrado ainda pra este brinde. Registre um acima.
    </div>
<?php endif; ?>

<?php endif; ?>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
