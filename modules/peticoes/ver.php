<?php
require_once __DIR__ . '/../../core/middleware.php';
require_login();
$pdo = db();
$docId = (int)($_GET['id'] ?? 0);

// Self-heal ANTES do prepare (Amanda 07/07): o prepare com JOIN em coluna
// inexistente lança exception ANTES do execute — o try/catch antigo só cobria
// o execute e o self-heal nunca rodava. Efeito: botão "Ver" de qualquer peça
// gerada abria página de erro.
try { $pdo->exec("ALTER TABLE case_documents ADD COLUMN editado_em DATETIME NULL"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE case_documents ADD COLUMN editado_por INT NULL"); } catch (Exception $e) {}

$stmt = $pdo->prepare("SELECT cd.*, u.name as user_name, ue.name as editado_por_name
                       FROM case_documents cd
                       LEFT JOIN users u ON u.id = cd.gerado_por
                       LEFT JOIN users ue ON ue.id = cd.editado_por
                       WHERE cd.id = ?");
$stmt->execute(array($docId));
$doc = $stmt->fetch();
if (!$doc) { die('Documento não encontrado.'); }
$podeEditar = has_min_role('gestao') || has_role('cx') || has_role('operacional');
$pageTitle = $doc['titulo'];
require_once APP_ROOT . '/templates/layout_start.php';
?>
<div style="max-width:800px;">
    <a href="<?= module_url('peticoes', 'index.php?case_id=' . $doc['case_id']) ?>" class="btn btn-outline btn-sm" style="margin-bottom:.75rem;">← Voltar</a>
    <div class="card">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;">
            <div>
                <h3 style="font-size:.95rem;margin:0;"><?= e($doc['titulo']) ?></h3>
                <span style="font-size:.72rem;color:var(--text-muted);">
                    <?= date('d/m/Y H:i', strtotime($doc['created_at'])) ?> — <?= e($doc['user_name'] ?? '') ?>
                    <?php if (!empty($doc['editado_em'])): ?>
                        · <span style="color:#059669;font-weight:600;">✏️ Editada em <?= date('d/m/Y H:i', strtotime($doc['editado_em'])) ?><?= !empty($doc['editado_por_name']) ? ' por ' . e($doc['editado_por_name']) : '' ?></span>
                    <?php endif; ?>
                </span>
            </div>
            <?php if ($podeEditar): ?>
            <div style="display:flex;gap:.4rem;align-items:center;flex-wrap:wrap;">
                <button id="btnEditarV" onclick="toggleEdicaoV()" class="btn btn-outline btn-sm" style="border-color:#059669;color:#059669;">✏️ Editar texto</button>
                <button id="btnSalvarV" onclick="salvarEdicaoV()" class="btn btn-primary btn-sm" style="display:none;background:#059669;border-color:#059669;">💾 Salvar</button>
                <button id="btnCancelarV" onclick="cancelarEdicaoV()" class="btn btn-secondary btn-sm" style="display:none;">↶ Cancelar</button>
                <span id="statusV" style="font-size:.72rem;color:#059669;font-weight:600;"></span>
            </div>
            <?php endif; ?>
        </div>
        <div class="card-body" style="font-family:Calibri,sans-serif;font-size:14px;line-height:1.8;">
            <div id="docHtml" style="outline:none;">
                <?= $doc['conteudo_html'] ?>
            </div>
        </div>
    </div>
</div>

<?php if ($podeEditar): ?>
<script>
var _verSnap = '';
var _verDocId = <?= (int)$doc['id'] ?>;

function toggleEdicaoV() {
    var el = document.getElementById('docHtml');
    _verSnap = el.innerHTML;
    el.contentEditable = 'true';
    el.style.outline = '2px dashed #059669';
    el.style.outlineOffset = '4px';
    el.focus();
    document.getElementById('btnEditarV').style.display = 'none';
    document.getElementById('btnSalvarV').style.display = '';
    document.getElementById('btnCancelarV').style.display = '';
    document.getElementById('statusV').textContent = '✏️ Edite direto na tela. Ctrl+S salva.';
}
function cancelarEdicaoV() {
    var el = document.getElementById('docHtml');
    el.innerHTML = _verSnap;
    el.contentEditable = 'false';
    el.style.outline = '';
    document.getElementById('btnEditarV').style.display = '';
    document.getElementById('btnSalvarV').style.display = 'none';
    document.getElementById('btnCancelarV').style.display = 'none';
    document.getElementById('statusV').textContent = '';
}
function salvarEdicaoV() {
    var el = document.getElementById('docHtml');
    var btn = document.getElementById('btnSalvarV');
    var status = document.getElementById('statusV');
    btn.disabled = true; btn.textContent = '⏳ Salvando...';

    var fd = new FormData();
    fd.append('action', 'salvar_edicao');
    fd.append('doc_id', _verDocId);
    fd.append('html', el.innerHTML);

    fetch('<?= module_url("peticoes", "api.php") ?>', {
        method: 'POST', body: fd, credentials: 'same-origin'
    }).then(function(r) { return r.json(); }).then(function(j) {
        btn.disabled = false; btn.textContent = '💾 Salvar';
        if (j.error) {
            status.style.color = '#dc2626';
            status.textContent = '✗ ' + j.error;
            return;
        }
        el.contentEditable = 'false';
        el.style.outline = '';
        document.getElementById('btnEditarV').style.display = '';
        document.getElementById('btnSalvarV').style.display = 'none';
        document.getElementById('btnCancelarV').style.display = 'none';
        status.style.color = '#059669';
        status.textContent = '✓ Salvo às ' + j.editado_em;
        _verSnap = el.innerHTML;
        setTimeout(function() { status.textContent = ''; }, 4000);
    }).catch(function() {
        btn.disabled = false; btn.textContent = '💾 Salvar';
        status.style.color = '#dc2626';
        status.textContent = '✗ Erro de conexão';
    });
}
document.addEventListener('keydown', function(ev) {
    if ((ev.ctrlKey || ev.metaKey) && ev.key === 's') {
        var el = document.getElementById('docHtml');
        if (el && el.contentEditable === 'true') { ev.preventDefault(); salvarEdicaoV(); }
    }
});
</script>
<?php endif; ?>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
