<?php
/**
 * Presença — Pedido de Reposição.
 * Lista itens de estoque abaixo do mínimo, cruzando com o melhor fornecedor
 * (escolhido pelo score) pra montar um pedido pronto. Amanda pode:
 *  - Marcar itens que quer no pedido
 *  - Ver custo total estimado
 *  - Copiar mensagem pronta pra WhatsApp/e-mail do fornecedor
 *  - Registrar que o pedido foi feito (marca `estoque_pedido_em` no item)
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
require_access('presenca');

$pdo = db();
$pageTitle = 'Presença — Pedido de Reposição';

// Marcar itens como "pedido feito" (soft-track)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) { flash_set('error','Sessão expirada.'); redirect(module_url('presenca','reposicao.php')); }
    $acao = $_POST['acao'] ?? '';

    if ($acao === 'marcar_pedido') {
        $ids = $_POST['brinde_ids'] ?? array();
        if (!is_array($ids)) $ids = array();
        $ids = array_filter(array_map('intval', $ids));
        if (count($ids) === 0) { flash_set('warning','Selecione ao menos 1 item.'); redirect(module_url('presenca','reposicao.php')); }
        // Grava audit e nota nas observacoes do estoque
        $now = date('Y-m-d H:i:s');
        $userId = (int)($_SESSION['user_id'] ?? 0);
        foreach ($ids as $bid) {
            try {
                $pdo->prepare("UPDATE presenca_estoque SET observacoes = CONCAT(COALESCE(observacoes,''), '\n[".$now."] Pedido de reposição registrado (user #".$userId.")') WHERE brinde_id = ?")->execute(array($bid));
            } catch (Exception $e) {}
            audit_log('presenca_reposicao_registrada','presenca_estoque',$bid,"user=$userId");
        }
        flash_set('success', count($ids) . ' item(ns) marcado(s) como pedido feito. Reconferir chegada no card Estoque.');
        redirect(module_url('presenca','reposicao.php'));
    }
}

// Puxa itens abaixo do minimo + melhor fornecedor (escolhido=1) de cada brinde
$itens = array();
try {
    $sql = "SELECT b.id, b.nome, b.categoria, b.qtd_compra_referencia,
                   e.estoque_atual, e.estoque_minimo, e.observacoes,
                   o.valor_unitario, o.frete, o.prazo_producao_dias, o.prazo_entrega_dias, o.qtd_minima,
                   f.nome AS forn_nome, f.telefone AS forn_tel, f.email AS forn_email
            FROM presenca_estoque e
            JOIN presenca_brinde b ON b.id = e.brinde_id
            LEFT JOIN presenca_orcamento o ON o.brinde_id = b.id AND o.escolhido = 1
            LEFT JOIN presenca_fornecedor f ON f.id = o.fornecedor_id AND f.ativo = 1
            WHERE b.ativo = 1 AND e.estoque_atual < e.estoque_minimo
            ORDER BY (e.estoque_minimo - e.estoque_atual) DESC";
    foreach ($pdo->query($sql) as $r) {
        $qtdRef = max(1, (int)$r['qtd_compra_referencia']);
        // Quantidade sugerida: pra deixar 2x o minimo em estoque (colchao)
        $alvo = max((int)$r['estoque_minimo'] * 2, $qtdRef);
        $qtdSug = max($alvo - (int)$r['estoque_atual'], $qtdRef);
        // Respeita minimo do fornecedor
        if (!empty($r['qtd_minima']) && $qtdSug < (int)$r['qtd_minima']) $qtdSug = (int)$r['qtd_minima'];
        $custoTotal = $r['valor_unitario'] !== null ? ($qtdSug * (float)$r['valor_unitario']) + (float)$r['frete'] : null;
        $prazo = $r['valor_unitario'] !== null ? (int)$r['prazo_producao_dias'] + (int)$r['prazo_entrega_dias'] : null;
        $itens[] = array(
            'id' => (int)$r['id'],
            'nome' => $r['nome'],
            'categoria' => $r['categoria'],
            'estoque_atual' => (int)$r['estoque_atual'],
            'estoque_minimo' => (int)$r['estoque_minimo'],
            'qtd_sugerida' => $qtdSug,
            'valor_unitario' => $r['valor_unitario'] !== null ? (float)$r['valor_unitario'] : null,
            'custo_total' => $custoTotal,
            'prazo' => $prazo,
            'forn_nome' => $r['forn_nome'],
            'forn_tel'  => $r['forn_tel'],
            'forn_email'=> $r['forn_email'],
        );
    }
} catch (Exception $e) {}

$totalGeral = 0;
foreach ($itens as $it) if ($it['custo_total'] !== null) $totalGeral += $it['custo_total'];

$csrf = generate_csrf_token();
require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.pr-hero { display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; flex-wrap:wrap; gap:10px; }
.pr-hero h1 { margin:0; font-family:'Cormorant Garamond',Georgia,serif; font-size:1.6rem; font-weight:600; color:#0E2E36; }
.pr-back { display:inline-flex; align-items:center; gap:5px; padding:5px 12px; background:#fff; border:1px solid #e5e7eb; border-radius:8px; text-decoration:none; color:#0E2E36; font-size:.78rem; font-weight:600; }
.pr-explica { background:#fff7ed; border-left:4px solid #d97706; padding:10px 14px; border-radius:6px; margin-bottom:16px; font-size:.82rem; color:#78350f; line-height:1.5; }
.pr-tabela { width:100%; border-collapse:collapse; background:#fff; border-radius:12px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,.05); margin-bottom:14px; }
.pr-tabela th, .pr-tabela td { padding:11px 12px; text-align:left; border-bottom:1px solid #f3f4f6; font-size:.85rem; vertical-align:middle; }
.pr-tabela th { background:#0E2E36; color:#fff; font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; }
.pr-tabela tbody tr:hover { background:#fafafa; }
.pr-tabela .pr-nome { font-weight:800; color:#0E2E36; }
.pr-tabela .pr-cat { display:inline-block; margin-left:6px; padding:1px 8px; background:#f5ede3; color:#78350f; border-radius:999px; font-size:.65rem; font-weight:700; }
.pr-tabela .pr-critico { color:#dc2626; font-weight:800; }
.pr-tabela .pr-qtd input { width:70px; padding:4px 6px; border:1px solid #e5e7eb; border-radius:6px; font-size:.85rem; text-align:right; }
.pr-tabela .pr-sem { color:#dc2626; font-weight:700; font-size:.75rem; }
.pr-tabela .pr-check { text-align:center; }
.pr-tabela .pr-check input { width:18px; height:18px; cursor:pointer; }
.pr-tabela .pr-forn { font-size:.78rem; color:#374151; }
.pr-tabela .pr-forn strong { color:#0E2E36; }
.pr-tabela .pr-forn a { color:#B87333; text-decoration:none; font-weight:700; }
.pr-tabela .pr-total { text-align:right; font-weight:800; color:#0E2E36; font-family:'Outfit',monospace; }
.pr-total-linha { background:linear-gradient(90deg,#f0fdf4,#fff); font-weight:900; }
.pr-total-linha td { font-size:1rem; padding:14px 12px; color:#0E2E36; }
.pr-actions { display:flex; gap:10px; flex-wrap:wrap; margin-top:8px; align-items:center; }
.pr-btn { background:#0E2E36; color:#fff; border:none; border-radius:8px; padding:10px 18px; font-size:.85rem; font-weight:700; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; gap:6px; }
.pr-btn.warn { background:#B87333; }
.pr-btn.link { background:#fff; color:#0E2E36; border:1px solid #d1d5db; }
.pr-vazio { background:#fff; border:1px dashed #d1d5db; border-radius:12px; padding:40px; text-align:center; color:#6b7280; }
.pr-vazio strong { display:block; font-size:1.2rem; color:#16a34a; margin-bottom:6px; font-family:'Cormorant Garamond',serif; }
</style>

<div class="pr-hero">
    <div>
        <h1>🛒 Pedido de Reposição</h1>
        <div style="font-size:.85rem;color:#6b7280;margin-top:4px;">Itens abaixo do mínimo + melhor fornecedor + qtd sugerida pra dobrar o estoque mínimo</div>
    </div>
    <a href="<?= module_url('presenca') ?>" class="pr-back">← Voltar</a>
</div>

<?php if (empty($itens)): ?>

<div class="pr-vazio">
    <strong>Estoque em dia 🎉</strong>
    Nenhum item abaixo do mínimo. Bora fechar o café e ir pra próxima demanda.
</div>

<?php else: ?>

<div class="pr-explica">
    💡 <strong>Como funciona.</strong> Sistema puxou os brindes abaixo do mínimo, cruzou com o fornecedor de melhor score (na tela Fornecedores &amp; Orçamentos) e sugeriu quantidade pra deixar 2× o estoque mínimo. Marque os que vai pedir agora, clique <strong>Copiar mensagem</strong> pra enviar direto pelo WhatsApp/e-mail do fornecedor, e depois <strong>Registrar pedido feito</strong>.
</div>

<form method="POST" data-fsa-skip="1" id="pedidoForm">
    <?= csrf_input() ?>
    <input type="hidden" name="acao" value="marcar_pedido">
    <table class="pr-tabela">
        <thead>
            <tr>
                <th style="width:36px;"><input type="checkbox" id="chkTodos" title="Marcar todos"></th>
                <th>Item</th>
                <th>Estoque</th>
                <th>Qtd. sugerida</th>
                <th>Fornecedor (melhor score)</th>
                <th style="text-align:right;">Custo estimado</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($itens as $it): ?>
            <tr data-brinde="<?= $it['id'] ?>">
                <td class="pr-check">
                    <input type="checkbox" name="brinde_ids[]" value="<?= $it['id'] ?>" class="pr-chk-item" checked>
                </td>
                <td>
                    <span class="pr-nome"><?= e($it['nome']) ?></span>
                    <?php if ($it['categoria']): ?><span class="pr-cat"><?= e($it['categoria']) ?></span><?php endif; ?>
                </td>
                <td>
                    <span class="pr-critico"><?= $it['estoque_atual'] ?></span> / mín <?= $it['estoque_minimo'] ?>
                </td>
                <td class="pr-qtd">
                    <input type="number" min="1" value="<?= $it['qtd_sugerida'] ?>" class="pr-qtd-input" data-unit="<?= $it['valor_unitario'] !== null ? $it['valor_unitario'] : 0 ?>">
                </td>
                <td class="pr-forn">
                    <?php if ($it['forn_nome']): ?>
                        <strong><?= e($it['forn_nome']) ?></strong>
                        <?php if ($it['prazo']): ?> · <?= $it['prazo'] ?>d<?php endif; ?><br>
                        <?php if ($it['forn_tel']): ?>📞 <?= e($it['forn_tel']) ?><?php endif; ?>
                        <?php if ($it['forn_email']): ?> · ✉ <?= e($it['forn_email']) ?><?php endif; ?>
                    <?php else: ?>
                        <span class="pr-sem">⚠ Sem orçamento cadastrado</span> — <a href="<?= module_url('presenca','fornecedores.php') ?>?orc=1&brinde=<?= $it['id'] ?>">registrar</a>
                    <?php endif; ?>
                </td>
                <td class="pr-total" data-total="<?= $it['custo_total'] !== null ? $it['custo_total'] : 0 ?>">
                    <?php if ($it['custo_total'] !== null): ?>
                        R$ <?= number_format($it['custo_total'], 2, ',', '.') ?>
                    <?php else: ?>
                        <span class="pr-sem">—</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr class="pr-total-linha">
                <td colspan="5" style="text-align:right;">Total estimado (itens marcados):</td>
                <td class="pr-total" id="totalGeralCell">R$ <?= number_format($totalGeral, 2, ',', '.') ?></td>
            </tr>
        </tfoot>
    </table>

    <div class="pr-actions">
        <button type="submit" class="pr-btn">✅ Registrar pedido feito (marca no estoque)</button>
        <button type="button" class="pr-btn warn" id="btnCopiarMsg">📋 Copiar mensagem por fornecedor</button>
        <a href="<?= module_url('presenca') ?>" class="pr-btn link">Cancelar</a>
    </div>
</form>

<script>
// Recalcula total geral conforme usuario mexe em qtd ou desmarca item
function prCalcularTotal() {
    var total = 0;
    document.querySelectorAll('.pr-tabela tbody tr').forEach(function(tr) {
        var chk = tr.querySelector('.pr-chk-item');
        if (!chk || !chk.checked) return;
        var qtdInp = tr.querySelector('.pr-qtd-input');
        var unit = parseFloat(qtdInp.dataset.unit) || 0;
        var qtd  = parseInt(qtdInp.value, 10) || 0;
        var linha = qtd * unit;
        // Nao considera frete no recalculo dinamico (aprox — ja estava incluso no valor inicial)
        var tdTotal = tr.querySelector('.pr-total');
        if (unit > 0) {
            tdTotal.textContent = 'R$ ' + linha.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
            total += linha;
        }
    });
    var g = document.getElementById('totalGeralCell');
    if (g) g.textContent = 'R$ ' + total.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
}
document.querySelectorAll('.pr-qtd-input, .pr-chk-item').forEach(function(el) {
    el.addEventListener('change', prCalcularTotal);
    el.addEventListener('keyup', prCalcularTotal);
});
document.getElementById('chkTodos').addEventListener('change', function() {
    var v = this.checked;
    document.querySelectorAll('.pr-chk-item').forEach(function(c) { c.checked = v; });
    prCalcularTotal();
});

// Copia mensagem agrupada por fornecedor
document.getElementById('btnCopiarMsg').addEventListener('click', function() {
    var porForn = {};
    document.querySelectorAll('.pr-tabela tbody tr').forEach(function(tr) {
        var chk = tr.querySelector('.pr-chk-item');
        if (!chk || !chk.checked) return;
        var nome = tr.querySelector('.pr-nome').textContent.trim();
        var qtd  = parseInt(tr.querySelector('.pr-qtd-input').value, 10) || 0;
        var forn = tr.querySelector('.pr-forn strong');
        var fornNome = forn ? forn.textContent.trim() : '(sem fornecedor)';
        if (!porForn[fornNome]) porForn[fornNome] = [];
        porForn[fornNome].push('· ' + nome + ' — ' + qtd + ' unidades');
    });
    var texto = '';
    Object.keys(porForn).forEach(function(f) {
        texto += '━━━ Pedido para: ' + f + ' ━━━\n\n';
        texto += 'Boa tarde! Podemos fazer o seguinte pedido?\n\n';
        texto += porForn[f].join('\n') + '\n\n';
        texto += 'Aguardo confirmação de disponibilidade, prazo e forma de pagamento.\nEquipe Ferreira & Sá Advocacia\n\n\n';
    });
    if (!texto) { alert('Nenhum item marcado.'); return; }
    // Copia pra clipboard
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(texto).then(function() {
            if (window.FsaFeedback) FsaFeedback.ok('Mensagem copiada — cole no WhatsApp/e-mail do fornecedor');
            else alert('Copiado! Cole no WhatsApp/e-mail do fornecedor.');
        }).catch(function() { prCopiarFallback(texto); });
    } else prCopiarFallback(texto);
});
function prCopiarFallback(txt) {
    var ta = document.createElement('textarea');
    ta.value = txt; ta.style.position = 'fixed'; ta.style.opacity = '0';
    document.body.appendChild(ta); ta.select();
    try { document.execCommand('copy'); if (window.FsaFeedback) FsaFeedback.ok('Copiado'); else alert('Copiado'); }
    catch (e) { alert('Nao consegui copiar automaticamente. Selecione manualmente:\n\n' + txt.substring(0, 200) + '...'); }
    document.body.removeChild(ta);
}

// Confirmacao antes de submeter (marca pedido feito)
document.getElementById('pedidoForm').addEventListener('submit', function(ev) {
    var marcados = document.querySelectorAll('.pr-chk-item:checked').length;
    if (marcados === 0) { ev.preventDefault(); alert('Selecione ao menos 1 item.'); return; }
    if (!confirm('Registrar ' + marcados + ' item(ns) como "pedido feito"?\n\nIsso NAO envia mensagem automatica — apenas registra no historico do estoque que voce ja mandou o pedido pro fornecedor.')) ev.preventDefault();
});
</script>

<?php endif; ?>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
