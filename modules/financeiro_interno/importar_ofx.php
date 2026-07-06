<?php
/**
 * Ferreira & Sá Hub — Importar extrato bancário (OFX) no Setor Financeiro.
 * Lê o arquivo OFX, sugere conciliação com contas em aberto e importa como
 * lançamentos. Acesso: Amanda (1) e Luiz Eduardo (6).
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
if (!can_access_financeiro_interno()) { redirect(url('modules/dashboard/')); }
require_once __DIR__ . '/../../core/functions_financeiro_interno.php';

$pageTitle = 'Importar Extrato';
$pdo = db();
fin_int_ensure_schema($pdo);
$uid = current_user_id();
$base = url('modules/financeiro_interno/');
$erro = ''; $ok = '';
$step = $_POST['step'] ?? '';
$preview = array();

// ─── CONFIRMAÇÃO: aplica as decisões ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 'confirm') {
    if (!validate_csrf()) {
        $erro = 'Sessão expirada — recarregue a página e tente de novo.';
    } else {
        $acoes  = $_POST['acao']        ?? array();
        $fitids = $_POST['fitid']       ?? array();
        $datas  = $_POST['data']        ?? array();
        $tipos  = $_POST['tipo']        ?? array();
        $vals   = $_POST['valor_cents'] ?? array();
        $descs  = $_POST['descricao']   ?? array();
        $cats   = $_POST['categoria']   ?? array();
        $matchs = $_POST['match_id']    ?? array();

        $criados = 0; $conciliados = 0; $pulados = 0;
        $insSt = $pdo->prepare("INSERT INTO fin_lancamentos
            (tipo, categoria, descricao, valor_cents, vencimento, pago, pago_em, origem, fitid, criado_por)
            VALUES (?,?,?,?,?,1,?, 'ofx', ?, ?)");
        $updSt = $pdo->prepare("UPDATE fin_lancamentos SET pago=1, pago_em=?, fitid=? WHERE id=?");
        $chkSt = $pdo->prepare("SELECT COUNT(*) FROM fin_lancamentos WHERE fitid = ?");

        foreach ($acoes as $i => $acao) {
            $fitid = trim((string)($fitids[$i] ?? ''));
            $data  = (string)($datas[$i] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) continue;
            $tipo  = ($tipos[$i] ?? 'saida') === 'entrada' ? 'entrada' : 'saida';
            $cents = (int)($vals[$i] ?? 0);
            $desc  = trim((string)($descs[$i] ?? ''));
            if ($cents <= 0 || $desc === '') continue;

            if ($acao === 'ignorar') { $pulados++; continue; }

            // Dedup por fitid — nunca importa a mesma transação 2x
            if ($fitid !== '') {
                $chkSt->execute(array($fitid));
                if ((int)$chkSt->fetchColumn() > 0) { $pulados++; continue; }
            }

            if ($acao === 'conciliar' && !empty($matchs[$i])) {
                $updSt->execute(array($data, $fitid, (int)$matchs[$i]));
                $conciliados++;
                audit_log('fin_int_ofx_conciliado', 'fin_lancamentos', (int)$matchs[$i], $desc . ' ' . fin_int_fmt($cents));
            } else {
                $cat = trim((string)($cats[$i] ?? '')); if ($cat === '') $cat = 'A classificar';
                $insSt->execute(array($tipo, $cat, $desc, $cents, $data, $data, $fitid, $uid));
                $criados++;
            }
        }
        $ok = "Importação concluída: {$criados} novo(s), {$conciliados} conciliado(s), {$pulados} ignorado(s)/já existente(s).";
    }
}

// ─── PREVIEW: leu o arquivo, monta a tela de conferência ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 'preview') {
    if (!validate_csrf()) {
        $erro = 'Sessão expirada — recarregue a página e tente de novo.';
    } elseif (empty($_FILES['arquivo']) || ($_FILES['arquivo']['error'] ?? 1) !== UPLOAD_ERR_OK) {
        $erro = 'Não recebi o arquivo. Selecione um .ofx exportado do banco.';
    } else {
        $raw = file_get_contents($_FILES['arquivo']['tmp_name']);
        $txs = fin_int_parse_ofx($raw);
        if (!$txs) {
            $erro = 'Não achei transações nesse arquivo. Confirme que é um extrato no formato OFX.';
        } else {
            // Ordena por data
            usort($txs, function ($a, $b) { return strcmp($a['data'], $b['data']); });
            $chk = $pdo->prepare("SELECT COUNT(*) FROM fin_lancamentos WHERE fitid = ?");
            foreach ($txs as &$t) {
                $chk->execute(array($t['fitid']));
                $t['ja_importado'] = ((int)$chk->fetchColumn() > 0);
                $t['match'] = $t['ja_importado'] ? null : fin_int_sugerir_conciliacao($pdo, $t['tipo'], $t['valor_cents'], $t['data']);
            }
            unset($t);
            $preview = $txs;
        }
    }
}

require_once APP_ROOT . '/templates/layout_start.php';
?>
<style>
.oi-wrap { max-width:960px; margin:0 auto; padding:0 4px 60px; }
.oi-head { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; margin:6px 0 18px; }
.oi-title { font-size:1.4rem; font-weight:800; display:flex; align-items:center; gap:10px; }
.oi-back { text-decoration:none; color:var(--text-muted,#6b7280); font-weight:700; font-size:.85rem; }
.oi-card { background:var(--bg-card,#fff); border:1px solid var(--border,#e5e7eb); border-radius:16px; padding:20px; box-shadow:var(--shadow-sm); margin-bottom:16px; }
.oi-alert { padding:12px 16px; border-radius:12px; font-weight:600; margin-bottom:16px; }
.oi-alert.err { background:#fee2e2; color:#991b1b; } .oi-alert.ok { background:#d1fae5; color:#065f46; }
.oi-drop { border:2px dashed var(--border,#cbd5e1); border-radius:14px; padding:28px; text-align:center; }
.oi-btn { border:none; border-radius:10px; padding:10px 18px; font-weight:700; cursor:pointer; font-size:.9rem; background:#164e63; color:#fff; }
.oi-btn:hover { filter:brightness(1.1); }
.oi-btn.ghost { background:transparent; border:1px solid var(--border,#e5e7eb); color:var(--text,#111); }
.oi-tbl { width:100%; border-collapse:collapse; font-size:.85rem; }
.oi-tbl th { text-align:left; font-size:.7rem; text-transform:uppercase; letter-spacing:.5px; color:var(--text-muted,#6b7280); padding:6px; border-bottom:2px solid var(--border,#e5e7eb); }
.oi-tbl td { padding:8px 6px; border-bottom:1px solid var(--border,#eee); vertical-align:middle; }
.oi-pill { display:inline-block; padding:2px 8px; border-radius:999px; font-size:.72rem; font-weight:700; }
.oi-pill.e { background:#d1fae5; color:#065f46; } .oi-pill.s { background:#fee2e2; color:#991b1b; }
.oi-sel { padding:5px 7px; border:1px solid var(--border,#d1d5db); border-radius:8px; font-size:.8rem; background:var(--bg,#fff); color:var(--text,#111); }
.oi-catin { padding:5px 7px; border:1px solid var(--border,#d1d5db); border-radius:8px; font-size:.8rem; width:120px; background:var(--bg,#fff); color:var(--text,#111); }
.oi-dup { opacity:.5; }
.oi-match { font-size:.72rem; color:#065f46; font-weight:700; }
.oi-help { font-size:.8rem; color:var(--text-muted,#6b7280); line-height:1.5; }
</style>

<div class="oi-wrap">
    <div class="oi-head">
        <div class="oi-title">⬆️ Importar extrato bancário</div>
        <a class="oi-back" href="<?= $base ?>">← voltar ao Setor Financeiro</a>
    </div>

    <?php if ($erro): ?><div class="oi-alert err">⚠️ <?= htmlspecialchars($erro) ?></div><?php endif; ?>
    <?php if ($ok): ?>
        <div class="oi-alert ok">✅ <?= htmlspecialchars($ok) ?></div>
        <div class="oi-card" style="text-align:center;">
            <a class="oi-btn" href="<?= $base ?>">Ver lançamentos →</a>
            <a class="oi-btn ghost" href="<?= url('modules/financeiro_interno/importar_ofx.php') ?>">Importar outro arquivo</a>
        </div>
    <?php endif; ?>

    <?php if (!$ok && !$preview): ?>
    <!-- Passo 1: upload -->
    <div class="oi-card">
        <form method="post" enctype="multipart/form-data">
            <?= csrf_input() ?>
            <input type="hidden" name="step" value="preview">
            <div class="oi-drop">
                <div style="font-size:2.4rem;">🏦</div>
                <p style="font-weight:700;margin:8px 0;">Selecione o arquivo <strong>.OFX</strong> do seu banco</p>
                <input type="file" name="arquivo" accept=".ofx,.qfx,application/x-ofx" required style="margin:10px 0;">
                <br>
                <button type="submit" class="oi-btn">Ler extrato</button>
            </div>
        </form>
    </div>
    <div class="oi-card">
        <div class="oi-help">
            <strong>Como pegar o OFX:</strong> no app/site do seu banco, vá em <em>Extrato</em> → <em>Exportar / Baixar</em> e escolha o formato <strong>OFX</strong> (às vezes aparece como "OFX Money", "Financial" ou "OFX 1.0/2.0"). Baixe o período que quer e suba aqui.<br><br>
            Cada transação tem um código único do banco (FITID), então <strong>importar o mesmo arquivo 2× não duplica nada</strong> — o sistema pula o que já entrou.
        </div>
    </div>
    <?php endif; ?>

    <?php if (!$ok && $preview):
        $totCred = 0; $totDeb = 0; $novos = 0;
        foreach ($preview as $t) { if (!$t['ja_importado']) { $novos++; if ($t['tipo']==='entrada') $totCred += $t['valor_cents']; else $totDeb += $t['valor_cents']; } }
    ?>
    <!-- Passo 2: conferência / conciliação -->
    <div class="oi-card">
        <p style="margin:0 0 4px;font-weight:700;"><?= count($preview) ?> transação(ões) no arquivo · <?= $novos ?> nova(s) pra importar</p>
        <p class="oi-help" style="margin:0;">Entradas: <strong class="oi-pill e"><?= fin_int_fmt($totCred) ?></strong> &nbsp; Saídas: <strong class="oi-pill s"><?= fin_int_fmt($totDeb) ?></strong>. Revise cada linha e clique em <strong>Importar</strong> no fim.</p>
    </div>
    <form method="post">
        <?= csrf_input() ?>
        <input type="hidden" name="step" value="confirm">
        <div class="oi-card" style="overflow-x:auto;">
            <table class="oi-tbl">
                <thead><tr>
                    <th>Data</th><th>Descrição</th><th style="text-align:right;">Valor</th><th>O que fazer</th><th>Categoria</th>
                </tr></thead>
                <tbody>
                <?php foreach ($preview as $i => $t):
                    $ehEnt = ($t['tipo'] === 'entrada');
                ?>
                    <tr class="<?= $t['ja_importado'] ? 'oi-dup' : '' ?>">
                        <td style="white-space:nowrap;"><?= date('d/m/Y', strtotime($t['data'])) ?></td>
                        <td><?= htmlspecialchars($t['descricao']) ?></td>
                        <td style="text-align:right;white-space:nowrap;"><span class="oi-pill <?= $ehEnt ? 'e' : 's' ?>"><?= $ehEnt ? '+' : '−' ?> <?= fin_int_fmt($t['valor_cents']) ?></span></td>
                        <td>
                            <input type="hidden" name="fitid[<?= $i ?>]" value="<?= htmlspecialchars($t['fitid']) ?>">
                            <input type="hidden" name="data[<?= $i ?>]" value="<?= htmlspecialchars($t['data']) ?>">
                            <input type="hidden" name="tipo[<?= $i ?>]" value="<?= $t['tipo'] ?>">
                            <input type="hidden" name="valor_cents[<?= $i ?>]" value="<?= (int)$t['valor_cents'] ?>">
                            <input type="hidden" name="descricao[<?= $i ?>]" value="<?= htmlspecialchars($t['descricao']) ?>">
                            <?php if ($t['ja_importado']): ?>
                                <input type="hidden" name="acao[<?= $i ?>]" value="ignorar">
                                <em style="font-size:.78rem;color:var(--text-muted,#6b7280);">já importado ✓</em>
                            <?php else: ?>
                                <select class="oi-sel" name="acao[<?= $i ?>]" onchange="oiToggleCat(this, <?= $i ?>)">
                                    <?php if ($t['match']): ?>
                                        <option value="conciliar" selected>Conciliar: <?= htmlspecialchars(mb_substr($t['match']['descricao'],0,28)) ?></option>
                                    <?php endif; ?>
                                    <option value="criar" <?= $t['match'] ? '' : 'selected' ?>>Criar novo lançamento</option>
                                    <option value="ignorar">Ignorar</option>
                                </select>
                                <?php if ($t['match']): ?>
                                    <input type="hidden" name="match_id[<?= $i ?>]" value="<?= (int)$t['match']['id'] ?>">
                                    <div class="oi-match">↔ casa com conta em aberto (venc <?= date('d/m', strtotime($t['match']['vencimento'])) ?>)</div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!$t['ja_importado']): ?>
                                <input class="oi-catin" id="oi-cat-<?= $i ?>" name="categoria[<?= $i ?>]" list="fiCatsOfx" placeholder="A classificar"
                                    value="<?= $t['match'] ? htmlspecialchars($t['match']['categoria']) : '' ?>"
                                    <?= $t['match'] ? 'style="display:none;"' : '' ?>>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="oi-card" style="display:flex;gap:10px;justify-content:flex-end;">
            <a class="oi-btn ghost" href="<?= url('modules/financeiro_interno/importar_ofx.php') ?>">Cancelar</a>
            <button type="submit" class="oi-btn">Importar <?= $novos ?> lançamento(s)</button>
        </div>
    </form>
    <datalist id="fiCatsOfx">
        <option value="Aluguel"><option value="Salários"><option value="Pró-labore"><option value="Softwares/Assinaturas">
        <option value="Contador"><option value="Impostos/Taxas"><option value="Marketing"><option value="Material/Escritório">
        <option value="Energia/Internet"><option value="Custas processuais"><option value="Honorários"><option value="Tarifas bancárias">
        <option value="A classificar">
    </datalist>
    <script>
    function oiToggleCat(sel, i){
        var cat = document.getElementById('oi-cat-' + i);
        if (!cat) return;
        cat.style.display = (sel.value === 'criar') ? '' : 'none';
    }
    </script>
    <?php endif; ?>
</div>
<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
