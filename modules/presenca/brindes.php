<?php
/**
 * Presença — Catálogo de Brindes.
 * CRUD dos brindes + galeria de mockups (upload) + composição de kits +
 * estoque inline (saldo atual + mínimo).
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
require_access('presenca');

$pdo = db();
$pageTitle = 'Presença — Catálogo de Brindes';

$UPLOAD_DIR = APP_ROOT . '/files/presenca/mockups';
$UPLOAD_URL = url('files/presenca/mockups');

// ── POST ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) { flash_set('error','Sessão expirada.'); redirect(module_url('presenca','brindes.php')); }
    $acao = $_POST['acao'] ?? '';

    if ($acao === 'salvar_brinde') {
        $id       = (int)($_POST['id'] ?? 0);
        $nome     = clean_str($_POST['nome'] ?? '', 120);
        $categoria = clean_str($_POST['categoria'] ?? '', 40);
        $desc     = clean_str($_POST['descricao'] ?? '', 2000);
        $embalagem = clean_str($_POST['embalagem'] ?? '', 255);
        $qtdRef   = max(1, (int)($_POST['qtd_compra_referencia'] ?? 1));
        $ehKit    = !empty($_POST['eh_kit']) ? 1 : 0;
        $ativo    = !empty($_POST['ativo']) ? 1 : 0;
        $estAtual = (int)($_POST['estoque_atual'] ?? 0);
        $estMin   = (int)($_POST['estoque_minimo'] ?? 0);
        if (!$nome || !$categoria) { flash_set('error','Nome e categoria são obrigatórios.'); redirect(module_url('presenca','brindes.php')); }

        if ($id > 0) {
            $pdo->prepare("UPDATE presenca_brinde SET nome=?, categoria=?, descricao=?, embalagem=?, qtd_compra_referencia=?, eh_kit=?, ativo=? WHERE id=?")
                ->execute(array($nome,$categoria,$desc,$embalagem,$qtdRef,$ehKit,$ativo,$id));
            audit_log('presenca_brinde_edit','presenca_brinde',$id,$nome);
        } else {
            $pdo->prepare("INSERT INTO presenca_brinde (nome,categoria,descricao,embalagem,qtd_compra_referencia,eh_kit,ativo) VALUES (?,?,?,?,?,?,?)")
                ->execute(array($nome,$categoria,$desc,$embalagem,$qtdRef,$ehKit,$ativo));
            $id = (int)$pdo->lastInsertId();
            audit_log('presenca_brinde_new','presenca_brinde',$id,$nome);
        }
        // Upsert estoque
        $pdo->prepare("INSERT INTO presenca_estoque (brinde_id,estoque_atual,estoque_minimo) VALUES (?,?,?)
                       ON DUPLICATE KEY UPDATE estoque_atual=VALUES(estoque_atual), estoque_minimo=VALUES(estoque_minimo)")
            ->execute(array($id,$estAtual,$estMin));

        // Upload de imagens (múltiplas)
        if (!empty($_FILES['imagens']) && is_array($_FILES['imagens']['tmp_name'])) {
            if (!is_dir($UPLOAD_DIR)) @mkdir($UPLOAD_DIR, 0755, true);
            $tipo = in_array($_POST['tipo_imagem'] ?? 'mockup', array('mockup','foto_real','embalagem')) ? $_POST['tipo_imagem'] : 'mockup';
            $qtdImgs = count($_FILES['imagens']['tmp_name']);
            $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : null;
            for ($i = 0; $i < $qtdImgs; $i++) {
                if ($_FILES['imagens']['error'][$i] !== UPLOAD_ERR_OK) continue;
                $tmp = $_FILES['imagens']['tmp_name'][$i];
                $mime = $finfo ? finfo_file($finfo, $tmp) : ($_FILES['imagens']['type'][$i] ?? '');
                $extMap = array('image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp');
                if (!isset($extMap[$mime])) continue;
                if ($_FILES['imagens']['size'][$i] > 5*1024*1024) continue;
                $hash = bin2hex(random_bytes(8));
                $fn = 'br' . $id . '_' . $hash . '.' . $extMap[$mime];
                if (move_uploaded_file($tmp, $UPLOAD_DIR . '/' . $fn)) {
                    @chmod($UPLOAD_DIR . '/' . $fn, 0644);
                    $pdo->prepare("INSERT INTO presenca_brinde_imagem (brinde_id,arquivo,tipo,principal,ordem) VALUES (?,?,?,0,?)")
                        ->execute(array($id, $fn, $tipo, 0));
                }
            }
            if ($finfo) finfo_close($finfo);
            // Se ainda não tem imagem principal, marca a primeira
            $stChk = $pdo->prepare("SELECT COUNT(*) FROM presenca_brinde_imagem WHERE brinde_id = ? AND principal = 1");
            $stChk->execute(array($id));
            if ((int)$stChk->fetchColumn() === 0) {
                $pdo->prepare("UPDATE presenca_brinde_imagem SET principal = 1 WHERE brinde_id = ? ORDER BY id ASC LIMIT 1")->execute(array($id));
            }
        }

        // Componentes do kit (se eh_kit)
        if ($ehKit) {
            $pdo->prepare("DELETE FROM presenca_brinde_componente WHERE kit_id = ?")->execute(array($id));
            if (!empty($_POST['componentes']) && is_array($_POST['componentes'])) {
                $stC = $pdo->prepare("INSERT INTO presenca_brinde_componente (kit_id,componente_id,quantidade) VALUES (?,?,?)");
                foreach ($_POST['componentes'] as $cid) {
                    $cid = (int)$cid;
                    $q = max(1, (int)($_POST['comp_qtd'][$cid] ?? 1));
                    if ($cid > 0 && $cid !== $id) $stC->execute(array($id, $cid, $q));
                }
            }
        }

        flash_set('success', $id ? 'Brinde salvo.' : 'Brinde criado.');
        redirect(module_url('presenca','brindes.php').'?editar='.$id);
    }

    if ($acao === 'excluir_imagem') {
        $imgId = (int)($_POST['imagem_id'] ?? 0);
        $stI = $pdo->prepare("SELECT arquivo, brinde_id FROM presenca_brinde_imagem WHERE id = ?");
        $stI->execute(array($imgId));
        $row = $stI->fetch();
        if ($row) {
            $abs = $UPLOAD_DIR . '/' . basename($row['arquivo']);
            if (is_file($abs)) @unlink($abs);
            $pdo->prepare("DELETE FROM presenca_brinde_imagem WHERE id = ?")->execute(array($imgId));
            audit_log('presenca_brinde_img_del','presenca_brinde_imagem',$imgId,'');
            redirect(module_url('presenca','brindes.php').'?editar='.(int)$row['brinde_id']);
        }
        redirect(module_url('presenca','brindes.php'));
    }

    if ($acao === 'principal_imagem') {
        $imgId = (int)($_POST['imagem_id'] ?? 0);
        $stI = $pdo->prepare("SELECT brinde_id FROM presenca_brinde_imagem WHERE id = ?");
        $stI->execute(array($imgId));
        $brId = (int)$stI->fetchColumn();
        if ($brId) {
            $pdo->prepare("UPDATE presenca_brinde_imagem SET principal = 0 WHERE brinde_id = ?")->execute(array($brId));
            $pdo->prepare("UPDATE presenca_brinde_imagem SET principal = 1 WHERE id = ?")->execute(array($imgId));
            redirect(module_url('presenca','brindes.php').'?editar='.$brId);
        }
        redirect(module_url('presenca','brindes.php'));
    }

    if ($acao === 'toggle_ativo_brinde') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $pdo->prepare("UPDATE presenca_brinde SET ativo = 1 - ativo WHERE id = ?")->execute(array($id));
            audit_log('presenca_brinde_toggle','presenca_brinde',$id,'');
        }
        redirect(module_url('presenca','brindes.php'));
    }
}

// ── Lista + Editar ──
$editar = null;
$imagens = array();
$componentes = array();
if (isset($_GET['editar']) && (int)$_GET['editar'] > 0) {
    $st = $pdo->prepare("SELECT b.*, e.estoque_atual, e.estoque_minimo FROM presenca_brinde b LEFT JOIN presenca_estoque e ON e.brinde_id = b.id WHERE b.id = ?");
    $st->execute(array((int)$_GET['editar']));
    $editar = $st->fetch(PDO::FETCH_ASSOC);
    if ($editar) {
        $stI = $pdo->prepare("SELECT * FROM presenca_brinde_imagem WHERE brinde_id = ? ORDER BY principal DESC, ordem ASC, id ASC");
        $stI->execute(array((int)$editar['id']));
        $imagens = $stI->fetchAll(PDO::FETCH_ASSOC);
        $stC = $pdo->prepare("SELECT c.*, b.nome AS componente_nome FROM presenca_brinde_componente c JOIN presenca_brinde b ON b.id = c.componente_id WHERE c.kit_id = ?");
        $stC->execute(array((int)$editar['id']));
        $componentes = $stC->fetchAll(PDO::FETCH_ASSOC);
    }
}
$compIds = array_column($componentes, 'componente_id');

$brindes = $pdo->query("
    SELECT b.*, e.estoque_atual, e.estoque_minimo,
           (SELECT arquivo FROM presenca_brinde_imagem i WHERE i.brinde_id = b.id ORDER BY principal DESC, id ASC LIMIT 1) AS capa
    FROM presenca_brinde b
    LEFT JOIN presenca_estoque e ON e.brinde_id = b.id
    ORDER BY b.ativo DESC, b.nome ASC
")->fetchAll(PDO::FETCH_ASSOC);
$brindesTodosMap = array();
foreach ($brindes as $b) $brindesTodosMap[$b['id']] = $b['nome'];

$csrf = generate_csrf_token();
require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.pb-hero { display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; flex-wrap:wrap; gap:10px; }
.pb-hero h1 { margin:0; font-family:'Cormorant Garamond',Georgia,serif; font-size:1.6rem; font-weight:600; color:#0E2E36; }
.pb-hero .sub { font-size:.85rem; color:#6b7280; margin-top:4px; }
.pb-back { display:inline-flex; align-items:center; gap:5px; padding:5px 12px; background:#fff; border:1px solid #e5e7eb; border-radius:8px; text-decoration:none; color:#0E2E36; font-size:.78rem; font-weight:600; }

.pb-form { background:#fff; border:1.5px solid #d7ab90; border-radius:12px; padding:20px 24px; margin-bottom:24px; box-shadow:0 4px 12px rgba(215,171,144,.15); }
.pb-form h3 { margin:0 0 14px; font-size:1.05rem; color:#0E2E36; display:flex; justify-content:space-between; align-items:center; }
.pb-form-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:12px; margin-bottom:12px; }
.pb-form label { display:block; font-size:.72rem; font-weight:700; color:#6b7280; margin-bottom:4px; text-transform:uppercase; letter-spacing:.03em; }
.pb-form input[type=text], .pb-form input[type=number], .pb-form select, .pb-form textarea { width:100%; border:1.5px solid #e5e7eb; border-radius:8px; padding:8px 10px; font-size:.88rem; font-family:inherit; }
.pb-form textarea { min-height:70px; resize:vertical; }
.pb-form input:focus, .pb-form select:focus, .pb-form textarea:focus { outline:none; border-color:#B87333; box-shadow:0 0 0 3px rgba(184,115,51,.15); }

.pb-section { margin-top:20px; padding-top:16px; border-top:1.5px dashed #e5e7eb; }
.pb-section h4 { font-size:.9rem; color:#0E2E36; margin:0 0 10px; font-weight:800; }

.pb-galeria { display:grid; grid-template-columns:repeat(auto-fill,minmax(140px,1fr)); gap:10px; }
.pb-img-card { position:relative; background:#fafafa; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden; }
.pb-img-card img { width:100%; height:140px; object-fit:cover; display:block; }
.pb-img-card.principal { border-color:#B87333; box-shadow:0 0 0 2px rgba(184,115,51,.3); }
.pb-img-card .badge-principal { position:absolute; top:6px; left:6px; background:#B87333; color:#fff; padding:2px 8px; border-radius:999px; font-size:.62rem; font-weight:700; letter-spacing:.05em; text-transform:uppercase; }
.pb-img-card .actions { position:absolute; top:6px; right:6px; display:flex; gap:4px; }
.pb-img-card .actions form { display:inline; }
.pb-img-card .actions button { background:rgba(0,0,0,.6); color:#fff; border:none; border-radius:6px; padding:4px 8px; font-size:.68rem; cursor:pointer; }
.pb-img-card .actions button.warn { background:rgba(220,38,38,.85); }
.pb-img-card .tipo { position:absolute; bottom:0; left:0; right:0; background:rgba(0,0,0,.7); color:#fff; padding:4px 8px; font-size:.65rem; text-align:center; text-transform:uppercase; letter-spacing:.05em; }

.pb-form-actions { display:flex; gap:8px; margin-top:20px; }
.pb-form-actions button, .pb-form-actions .btn-link { background:#0E2E36; color:#fff; border:none; border-radius:8px; padding:9px 18px; font-size:.85rem; font-weight:700; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; gap:5px; }
.pb-form-actions .btn-link { background:#fff; color:#0E2E36; border:1px solid #d1d5db; }

.pb-comp-row { display:flex; gap:8px; align-items:center; padding:6px 10px; background:#fafafa; border:1px solid #e5e7eb; border-radius:8px; margin-bottom:6px; }
.pb-comp-row input[type=checkbox] { margin:0; }
.pb-comp-row .nome { flex:1; font-size:.85rem; color:#0E2E36; }
.pb-comp-row input[type=number] { width:80px; }

.pb-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(260px,1fr)); gap:14px; }
.pb-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,.05); display:flex; flex-direction:column; }
.pb-card.inativo { opacity:.55; }
.pb-card .capa { width:100%; height:140px; object-fit:cover; background:#f5ede3; display:block; }
.pb-card .capa-vazio { width:100%; height:140px; background:#f5ede3; display:flex; align-items:center; justify-content:center; font-size:2.5rem; color:#d7ab90; }
.pb-card .body { padding:14px 16px; flex:1; }
.pb-card .nome { font-weight:800; color:#0E2E36; font-size:.95rem; margin-bottom:4px; }
.pb-card .categ { font-size:.7rem; color:#6b7280; text-transform:uppercase; letter-spacing:.05em; margin-bottom:8px; }
.pb-card .kit-tag { display:inline-block; background:#f3e8ff; color:#7c3aed; padding:2px 8px; border-radius:999px; font-size:.65rem; font-weight:700; margin-right:6px; }
.pb-card .estoque { font-size:.78rem; color:#0E2E36; margin-top:8px; }
.pb-card .estoque.risco { color:#dc2626; font-weight:700; }
.pb-card-footer { padding:8px 14px; border-top:1px solid #f3f4f6; display:flex; gap:6px; background:#fafafa; }
.pb-btn { background:#fff; color:#0E2E36; border:1px solid #d1d5db; border-radius:6px; padding:5px 10px; font-size:.72rem; font-weight:700; cursor:pointer; text-decoration:none; }
.pb-btn:hover { border-color:#B87333; color:#B87333; }
.pb-btn.warn { color:#dc2626; border-color:#fecaca; }
</style>

<div class="pb-hero">
    <div>
        <h1>🎁 Catálogo de Brindes</h1>
        <div class="sub">CRUD + galeria de mockups + composição de kit + estoque</div>
    </div>
    <a href="<?= module_url('presenca') ?>" class="pb-back">← Voltar</a>
</div>

<div class="pb-form">
    <h3>
        <?= $editar ? '✏️ Editando: ' . e($editar['nome']) : '➕ Novo brinde' ?>
        <?php if ($editar): ?><a href="<?= module_url('presenca','brindes.php') ?>" class="pb-back">+ Novo</a><?php endif; ?>
    </h3>

    <form method="POST" enctype="multipart/form-data">
        <?= csrf_input() ?>
        <input type="hidden" name="acao" value="salvar_brinde">
        <?php if ($editar): ?><input type="hidden" name="id" value="<?= (int)$editar['id'] ?>"><?php endif; ?>

        <div class="pb-form-grid">
            <div style="grid-column:span 2;">
                <label>Nome *</label>
                <input type="text" name="nome" required maxlength="120" value="<?= e($editar['nome'] ?? '') ?>" placeholder="Ex: Vela Votiva + Cartão">
            </div>
            <div>
                <label>Categoria *</label>
                <select name="categoria" required>
                    <?php
                    $categorias = array('vela','planta','difusor','ecobag','kit','cartao','doce','livro','outro');
                    $catAtual = $editar['categoria'] ?? '';
                    foreach ($categorias as $c) {
                        echo '<option value="'.e($c).'"'.($catAtual===$c?' selected':'').'>'.e(ucfirst($c)).'</option>';
                    }
                    if ($catAtual && !in_array($catAtual, $categorias)) {
                        echo '<option value="'.e($catAtual).'" selected>'.e(ucfirst($catAtual)).' (legado)</option>';
                    }
                    ?>
                </select>
            </div>
            <div>
                <label>Qtd. compra referência</label>
                <input type="number" name="qtd_compra_referencia" min="1" value="<?= (int)($editar['qtd_compra_referencia'] ?? 20) ?>">
            </div>
            <div>
                <label>É kit?</label>
                <div style="padding-top:10px;">
                    <input type="checkbox" name="eh_kit" id="ehKit" <?= !empty($editar['eh_kit']) ? 'checked' : '' ?> onchange="document.getElementById('secKit').style.display=this.checked?'block':'none'">
                    <label for="ehKit" style="display:inline;font-size:.85rem;color:#0E2E36;font-weight:600;text-transform:none;letter-spacing:0;margin-left:4px;">Sim (composto por outros brindes)</label>
                </div>
            </div>
            <div>
                <label>Ativo?</label>
                <div style="padding-top:10px;">
                    <input type="checkbox" name="ativo" id="ativoBr" <?= empty($editar) || !empty($editar['ativo']) ? 'checked' : '' ?>>
                    <label for="ativoBr" style="display:inline;font-size:.85rem;color:#0E2E36;font-weight:600;text-transform:none;letter-spacing:0;margin-left:4px;">Sim</label>
                </div>
            </div>
            <div style="grid-column:span 2;">
                <label>Embalagem</label>
                <input type="text" name="embalagem" maxlength="255" value="<?= e($editar['embalagem'] ?? '') ?>" placeholder="Ex: Saco kraft com fita, lacre de cera e cartão">
            </div>
            <div style="grid-column:1 / -1;">
                <label>Descrição / observações</label>
                <textarea name="descricao" maxlength="2000"><?= e($editar['descricao'] ?? '') ?></textarea>
            </div>
        </div>

        <!-- Estoque inline -->
        <div class="pb-section">
            <h4>📦 Estoque</h4>
            <div class="pb-form-grid">
                <div>
                    <label>Saldo atual</label>
                    <input type="number" name="estoque_atual" min="0" value="<?= (int)($editar['estoque_atual'] ?? 0) ?>">
                </div>
                <div>
                    <label>Estoque mínimo (alerta se abaixo)</label>
                    <input type="number" name="estoque_minimo" min="0" value="<?= (int)($editar['estoque_minimo'] ?? 0) ?>">
                </div>
            </div>
        </div>

        <!-- Componentes do kit -->
        <div class="pb-section" id="secKit" style="<?= !empty($editar['eh_kit']) ? '' : 'display:none;' ?>">
            <h4>🧩 Componentes do kit</h4>
            <div style="font-size:.78rem;color:#6b7280;margin-bottom:8px;">Marque os brindes que compõem este kit + quantidade de cada.</div>
            <?php foreach ($brindes as $b):
                if ($editar && $b['id'] == $editar['id']) continue; // não pode ser componente de si mesmo
                $marcado = in_array($b['id'], $compIds);
                $qtd = 1;
                foreach ($componentes as $c) if ($c['componente_id'] == $b['id']) $qtd = (int)$c['quantidade'];
            ?>
            <div class="pb-comp-row">
                <input type="checkbox" name="componentes[]" value="<?= (int)$b['id'] ?>" id="comp<?= (int)$b['id'] ?>" <?= $marcado?'checked':'' ?>>
                <label for="comp<?= (int)$b['id'] ?>" class="nome" style="cursor:pointer;text-transform:none;letter-spacing:0;color:#0E2E36;font-weight:500;"><?= e($b['nome']) ?></label>
                <input type="number" name="comp_qtd[<?= (int)$b['id'] ?>]" min="1" value="<?= $qtd ?>" title="Quantidade deste componente por kit">
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Galeria + upload -->
        <?php if ($editar): ?>
        <div class="pb-section">
            <h4>🖼 Galeria de mockups</h4>
            <?php if (empty($imagens)): ?>
                <div style="color:#6b7280;font-size:.85rem;font-style:italic;">Nenhuma imagem ainda. Faça upload abaixo.</div>
            <?php else: ?>
            <div class="pb-galeria">
                <?php foreach ($imagens as $img): ?>
                <div class="pb-img-card <?= $img['principal'] ? 'principal' : '' ?>">
                    <?php if ($img['principal']): ?><div class="badge-principal">Principal</div><?php endif; ?>
                    <img src="<?= e($UPLOAD_URL . '/' . $img['arquivo']) ?>" alt="">
                    <div class="actions">
                        <?php if (!$img['principal']): ?>
                        <form method="POST"><?= csrf_input() ?><input type="hidden" name="acao" value="principal_imagem"><input type="hidden" name="imagem_id" value="<?= (int)$img['id'] ?>"><button type="submit" title="Marcar como principal">⭐</button></form>
                        <?php endif; ?>
                        <form method="POST" onsubmit="return confirm('Excluir esta imagem?')"><?= csrf_input() ?><input type="hidden" name="acao" value="excluir_imagem"><input type="hidden" name="imagem_id" value="<?= (int)$img['id'] ?>"><button type="submit" class="warn" title="Excluir">✕</button></form>
                    </div>
                    <div class="tipo"><?= e($img['tipo']) ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div style="margin-top:14px;padding:12px;background:#fafafa;border-radius:8px;">
                <label>Upload de nova(s) imagem(ns) — JPG/PNG/WebP até 5MB</label>
                <div style="display:flex;gap:8px;align-items:center;margin-top:6px;flex-wrap:wrap;">
                    <select name="tipo_imagem" style="width:auto;">
                        <option value="mockup">Mockup</option>
                        <option value="foto_real">Foto real</option>
                        <option value="embalagem">Embalagem</option>
                    </select>
                    <input type="file" name="imagens[]" multiple accept="image/jpeg,image/png,image/webp" style="flex:1;">
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="pb-form-actions">
            <button type="submit">💾 <?= $editar ? 'Salvar alterações' : 'Criar brinde' ?></button>
            <?php if ($editar): ?><a href="<?= module_url('presenca','brindes.php') ?>" class="btn-link">Cancelar</a><?php endif; ?>
        </div>
    </form>
</div>

<h3 style="font-family:'Cormorant Garamond',Georgia,serif;color:#0E2E36;font-size:1.3rem;margin:24px 0 10px;font-weight:600;">📚 Catálogo (<?= count($brindes) ?>)</h3>
<div class="pb-grid">
    <?php foreach ($brindes as $b):
        $emRisco = ($b['estoque_atual'] !== null && $b['estoque_minimo'] !== null && (int)$b['estoque_atual'] < (int)$b['estoque_minimo']);
    ?>
    <div class="pb-card <?= $b['ativo'] ? '' : 'inativo' ?>">
        <?php if (!empty($b['capa'])): ?>
            <img class="capa" src="<?= e($UPLOAD_URL . '/' . $b['capa']) ?>" alt="">
        <?php else: ?>
            <div class="capa-vazio">🎁</div>
        <?php endif; ?>
        <div class="body">
            <div class="nome"><?= e($b['nome']) ?></div>
            <div class="categ"><?= e($b['categoria']) ?><?php if (!empty($b['eh_kit'])): ?> · <span class="kit-tag">KIT</span><?php endif; ?></div>
            <div style="font-size:.75rem;color:#6b7280;">Compra ref.: <?= (int)$b['qtd_compra_referencia'] ?> un.</div>
            <div class="estoque <?= $emRisco?'risco':'' ?>">
                📦 <?= (int)($b['estoque_atual'] ?? 0) ?> / mín. <?= (int)($b['estoque_minimo'] ?? 0) ?>
                <?php if ($emRisco): ?> ⚠<?php endif; ?>
            </div>
        </div>
        <div class="pb-card-footer">
            <a href="?editar=<?= (int)$b['id'] ?>" class="pb-btn">✏️ Editar</a>
            <form method="POST" style="display:inline;">
                <?= csrf_input() ?>
                <input type="hidden" name="acao" value="toggle_ativo_brinde">
                <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                <button type="submit" class="pb-btn <?= $b['ativo'] ? 'warn' : '' ?>"><?= $b['ativo'] ? '⏸' : '▶' ?></button>
            </form>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
