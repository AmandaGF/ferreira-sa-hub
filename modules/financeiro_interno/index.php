<?php
/**
 * Ferreira & Sá Hub — Setor Financeiro Interno (guarda-chuva)
 * Finanças do escritório: contas a pagar/receber, fluxo de caixa, despesas fixas.
 * Atalho pro Financeiro de clientes (cobranças Asaas).
 * Acesso: Amanda (1) e Luiz Eduardo (6).
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
if (!can_access_financeiro_interno()) { redirect(url('modules/dashboard/')); }
require_once __DIR__ . '/../../core/functions_financeiro_interno.php';

$pageTitle = 'Setor Financeiro';
$pdo = db();
fin_int_ensure_schema($pdo);
$uid = current_user_id();

// Gera as despesas/receitas fixas do mês atual (idempotente)
fin_int_gerar_recorrentes($pdo, $uid);

// ── Seletor de mês ──
$mesSel = $_GET['mes'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $mesSel)) $mesSel = date('Y-m');
$ML = array('', 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro');
$mesNum  = (int)substr($mesSel, 5, 2);
$anoSel  = substr($mesSel, 0, 4);
$mesNome = $ML[$mesNum] . ' / ' . $anoSel;
$mesPrev = date('Y-m', strtotime($mesSel . '-01 -1 month'));
$mesNext = date('Y-m', strtotime($mesSel . '-01 +1 month'));
$base = url('modules/financeiro_interno/');

// ── KPIs do mês (por mês de vencimento) ──
function _fi_sum($pdo, $where, $params) {
    $st = $pdo->prepare("SELECT IFNULL(SUM(valor_cents),0) FROM fin_lancamentos WHERE $where");
    $st->execute($params);
    return (int)$st->fetchColumn();
}
$entradasPrev  = _fi_sum($pdo, "tipo='entrada' AND DATE_FORMAT(vencimento,'%Y-%m')=?", array($mesSel));
$entradasReceb = _fi_sum($pdo, "tipo='entrada' AND pago=1 AND DATE_FORMAT(vencimento,'%Y-%m')=?", array($mesSel));
$saidasPrev    = _fi_sum($pdo, "tipo='saida' AND DATE_FORMAT(vencimento,'%Y-%m')=?", array($mesSel));
$saidasPagas   = _fi_sum($pdo, "tipo='saida' AND pago=1 AND DATE_FORMAT(vencimento,'%Y-%m')=?", array($mesSel));
$aReceber  = $entradasPrev - $entradasReceb;
$aPagar    = $saidasPrev - $saidasPagas;
$saldoPrev = $entradasPrev - $saidasPrev;
$saldoReal = $entradasReceb - $saidasPagas;

// ── Fluxo de caixa: últimos 6 meses até o selecionado ──
$fluxoMeses = array();
for ($i = 5; $i >= 0; $i--) { $fluxoMeses[$m = date('Y-m', strtotime($mesSel . '-01 -' . $i . ' month'))] = array('ent' => 0, 'sai' => 0); }
$mIni = array_key_first($fluxoMeses) . '-01';
$stFx = $pdo->prepare("SELECT DATE_FORMAT(vencimento,'%Y-%m') m,
        SUM(CASE WHEN tipo='entrada' THEN valor_cents ELSE 0 END) ent,
        SUM(CASE WHEN tipo='saida'   THEN valor_cents ELSE 0 END) sai
    FROM fin_lancamentos
    WHERE vencimento >= ? AND vencimento <= LAST_DAY(?)
    GROUP BY m");
$stFx->execute(array($mIni, $mesSel . '-01'));
foreach ($stFx->fetchAll(PDO::FETCH_ASSOC) as $r) {
    if (isset($fluxoMeses[$r['m']])) { $fluxoMeses[$r['m']] = array('ent' => (int)$r['ent'], 'sai' => (int)$r['sai']); }
}
$fxMax = 1;
foreach ($fluxoMeses as $f) { $fxMax = max($fxMax, $f['ent'], $f['sai']); }

// ── Lançamentos do mês ──
$stL = $pdo->prepare("SELECT * FROM fin_lancamentos WHERE DATE_FORMAT(vencimento,'%Y-%m')=? ORDER BY pago ASC, vencimento ASC, id ASC");
$stL->execute(array($mesSel));
$lancamentos = $stL->fetchAll(PDO::FETCH_ASSOC);

// ── Despesas/receitas fixas ──
$recorrentes = $pdo->query("SELECT * FROM fin_recorrentes ORDER BY ativo DESC, tipo ASC, descricao ASC")->fetchAll(PDO::FETCH_ASSOC);

$hoje = date('Y-m-d');
require_once APP_ROOT . '/templates/layout_start.php';
?>
<style>
.fi-wrap { max-width:1100px; margin:0 auto; padding:0 4px 60px; }
.fi-head { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin:6px 0 18px; }
.fi-title { font-size:1.5rem; font-weight:800; display:flex; align-items:center; gap:10px; }
.fi-mesnav { display:flex; align-items:center; gap:6px; background:var(--bg-card,#fff); border:1px solid var(--border,#e5e7eb); border-radius:12px; padding:4px 6px; box-shadow:var(--shadow-sm); }
.fi-mesnav a { text-decoration:none; color:var(--text,#111); font-weight:700; padding:6px 10px; border-radius:8px; }
.fi-mesnav a:hover { background:rgba(0,0,0,.06); }
.fi-mesnav .cur { min-width:150px; text-align:center; font-weight:800; }
.fi-cards { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:14px; margin-bottom:18px; }
.fi-card { background:var(--bg-card,#fff); border:1px solid var(--border,#e5e7eb); border-radius:16px; padding:16px 18px; box-shadow:var(--shadow-sm); }
.fi-card .lbl { font-size:.78rem; text-transform:uppercase; letter-spacing:.5px; color:var(--text-muted,#6b7280); font-weight:700; }
.fi-card .big { font-size:1.7rem; font-weight:800; margin-top:4px; }
.fi-card .sub { font-size:.8rem; color:var(--text-muted,#6b7280); margin-top:4px; }
.fi-green { color:#059669; } .fi-red { color:#dc2626; } .fi-blue { color:#2563eb; }
.fi-card.ent { border-top:4px solid #10b981; } .fi-card.sai { border-top:4px solid #ef4444; } .fi-card.sal { border-top:4px solid #3b82f6; }
.fi-section { background:var(--bg-card,#fff); border:1px solid var(--border,#e5e7eb); border-radius:16px; padding:16px 18px; box-shadow:var(--shadow-sm); margin-bottom:18px; }
.fi-section h2 { font-size:1.05rem; font-weight:800; margin:0 0 12px; display:flex; align-items:center; justify-content:space-between; gap:8px; }
.fi-btn { border:none; border-radius:10px; padding:8px 14px; font-weight:700; cursor:pointer; font-size:.85rem; }
.fi-btn-pri { background:#164e63; color:#fff; } .fi-btn-pri:hover { filter:brightness(1.1); }
.fi-btn-sm { padding:4px 8px; font-size:.78rem; border-radius:7px; background:transparent; border:1px solid var(--border,#e5e7eb); cursor:pointer; color:var(--text,#111); }
.fi-btn-sm:hover { background:rgba(0,0,0,.05); }
.fi-tbl { width:100%; border-collapse:collapse; font-size:.87rem; }
.fi-tbl th { text-align:left; font-size:.72rem; text-transform:uppercase; letter-spacing:.5px; color:var(--text-muted,#6b7280); padding:6px 8px; border-bottom:2px solid var(--border,#e5e7eb); }
.fi-tbl td { padding:8px; border-bottom:1px solid var(--border,#eee); vertical-align:middle; }
.fi-tbl tr:hover td { background:rgba(0,0,0,.02); }
.fi-pill { display:inline-block; padding:2px 8px; border-radius:999px; font-size:.72rem; font-weight:700; }
.fi-pill.e { background:#d1fae5; color:#065f46; } .fi-pill.s { background:#fee2e2; color:#991b1b; }
.fi-tag { display:inline-block; padding:1px 7px; border-radius:6px; background:rgba(0,0,0,.06); font-size:.72rem; color:var(--text-muted,#555); }
.fi-chk { width:20px; height:20px; cursor:pointer; }
.fi-atalho { display:flex; align-items:center; gap:14px; background:linear-gradient(135deg,#164e63,#0e7490); color:#fff; border-radius:16px; padding:16px 20px; text-decoration:none; box-shadow:var(--shadow-sm); margin-bottom:18px; transition:transform .15s; }
.fi-atalho:hover { transform:translateY(-2px); }
.fi-atalho .ic { font-size:2rem; } .fi-atalho .tt { font-weight:800; font-size:1.05rem; } .fi-atalho .ds { font-size:.83rem; opacity:.9; }
.fi-atalho .go { margin-left:auto; font-size:1.4rem; }
.fi-fluxo { display:flex; align-items:flex-end; gap:10px; height:130px; padding-top:6px; }
.fi-fluxo .col { flex:1; display:flex; flex-direction:column; align-items:center; gap:3px; height:100%; justify-content:flex-end; }
.fi-fluxo .bars { display:flex; align-items:flex-end; gap:3px; height:90px; }
.fi-fluxo .bar { width:12px; border-radius:4px 4px 0 0; }
.fi-fluxo .bar.e { background:#10b981; } .fi-fluxo .bar.s { background:#ef4444; }
.fi-fluxo .mlbl { font-size:.7rem; color:var(--text-muted,#6b7280); font-weight:700; }
.fi-fluxo .sld { font-size:.72rem; font-weight:800; }
.fi-modal-bg { position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:9998; display:none; align-items:center; justify-content:center; padding:16px; }
.fi-modal { background:var(--bg-card,#fff); color:var(--text,#111); border-radius:16px; padding:20px; width:100%; max-width:440px; box-shadow:0 20px 60px rgba(0,0,0,.3); }
.fi-modal h3 { margin:0 0 14px; font-size:1.1rem; font-weight:800; }
.fi-field { margin-bottom:12px; } .fi-field label { display:block; font-size:.78rem; font-weight:700; margin-bottom:4px; color:var(--text-muted,#555); }
.fi-field input, .fi-field select, .fi-field textarea { width:100%; padding:9px 11px; border:1px solid var(--border,#d1d5db); border-radius:10px; font-size:.9rem; background:var(--bg,#fff); color:var(--text,#111); box-sizing:border-box; }
.fi-row2 { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
.fi-seg { display:flex; gap:6px; } .fi-seg label { flex:1; }
.fi-seg input { display:none; }
.fi-seg span { display:block; text-align:center; padding:9px; border:1px solid var(--border,#d1d5db); border-radius:10px; cursor:pointer; font-weight:700; font-size:.85rem; }
.fi-seg input:checked + span.se { background:#d1fae5; border-color:#10b981; color:#065f46; }
.fi-seg input:checked + span.ss { background:#fee2e2; border-color:#ef4444; color:#991b1b; }
.fi-modal-acts { display:flex; gap:8px; justify-content:flex-end; margin-top:8px; }
.fi-empty { text-align:center; color:var(--text-muted,#9ca3af); padding:22px; font-size:.9rem; }
.fi-venc-atras { color:#dc2626; font-weight:700; }
@media (max-width:640px){ .fi-row2{grid-template-columns:1fr;} .fi-card .big{font-size:1.4rem;} }
</style>

<div class="fi-wrap">
    <div class="fi-head">
        <div class="fi-title">🏦 Setor Financeiro <span style="font-size:.8rem;font-weight:600;color:var(--text-muted,#6b7280);">— interno do escritório</span></div>
        <div class="fi-mesnav">
            <a href="<?= $base ?>?mes=<?= $mesPrev ?>" title="Mês anterior">‹</a>
            <span class="cur"><?= htmlspecialchars($mesNome) ?></span>
            <a href="<?= $base ?>?mes=<?= $mesNext ?>" title="Próximo mês">›</a>
        </div>
    </div>

    <!-- KPIs -->
    <div class="fi-cards">
        <div class="fi-card ent">
            <div class="lbl">Entradas do mês</div>
            <div class="big fi-green"><?= fin_int_fmt($entradasPrev) ?></div>
            <div class="sub">Recebido: <strong><?= fin_int_fmt($entradasReceb) ?></strong> · A receber: <?= fin_int_fmt($aReceber) ?></div>
        </div>
        <div class="fi-card sai">
            <div class="lbl">Saídas do mês</div>
            <div class="big fi-red"><?= fin_int_fmt($saidasPrev) ?></div>
            <div class="sub">Pago: <strong><?= fin_int_fmt($saidasPagas) ?></strong> · A pagar: <?= fin_int_fmt($aPagar) ?></div>
        </div>
        <div class="fi-card sal">
            <div class="lbl">Saldo do mês</div>
            <div class="big <?= $saldoPrev >= 0 ? 'fi-blue' : 'fi-red' ?>"><?= fin_int_fmt($saldoPrev) ?></div>
            <div class="sub">Realizado (pago × recebido): <strong class="<?= $saldoReal >= 0 ? 'fi-green' : 'fi-red' ?>"><?= fin_int_fmt($saldoReal) ?></strong></div>
        </div>
    </div>

    <!-- Atalho pro financeiro de clientes -->
    <a class="fi-atalho" href="<?= url('modules/financeiro/') ?>">
        <span class="ic">💳</span>
        <span>
            <span class="tt">Financeiro de clientes →</span><br>
            <span class="ds">Cobranças Asaas, inadimplentes e fluxo de honorários</span>
        </span>
        <span class="go">→</span>
    </a>

    <!-- Fluxo de caixa -->
    <div class="fi-section">
        <h2>📊 Fluxo de caixa <span style="font-size:.72rem;font-weight:600;color:var(--text-muted,#6b7280);">últimos 6 meses</span></h2>
        <div class="fi-fluxo">
            <?php foreach ($fluxoMeses as $mk => $f):
                $sld = $f['ent'] - $f['sai'];
                $hE = (int)round(($f['ent'] / $fxMax) * 90);
                $hS = (int)round(($f['sai'] / $fxMax) * 90);
                $mn = (int)substr($mk, 5, 2);
            ?>
            <div class="col" title="Entradas <?= fin_int_fmt($f['ent']) ?> · Saídas <?= fin_int_fmt($f['sai']) ?>">
                <div class="bars">
                    <div class="bar e" style="height:<?= max(2,$hE) ?>px;"></div>
                    <div class="bar s" style="height:<?= max(2,$hS) ?>px;"></div>
                </div>
                <div class="sld <?= $sld >= 0 ? 'fi-green' : 'fi-red' ?>"><?= ($sld>=0?'+':'') . number_format($sld/100, 0, ',', '.') ?></div>
                <div class="mlbl"><?= $ML[$mn] ? substr($ML[$mn],0,3) : $mk ?><?= $mk === $mesSel ? ' ●' : '' ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <div style="margin-top:10px;font-size:.75rem;color:var(--text-muted,#6b7280);display:flex;gap:16px;">
            <span><span style="display:inline-block;width:10px;height:10px;background:#10b981;border-radius:2px;"></span> Entradas</span>
            <span><span style="display:inline-block;width:10px;height:10px;background:#ef4444;border-radius:2px;"></span> Saídas</span>
            <span>Número embaixo = saldo do mês (R$)</span>
        </div>
    </div>

    <!-- Lançamentos do mês -->
    <div class="fi-section">
        <h2>
            <span>🧾 Contas a pagar / receber — <?= htmlspecialchars($ML[$mesNum]) ?></span>
            <button class="fi-btn fi-btn-pri" onclick="fiAbrirLanc()">+ Novo lançamento</button>
        </h2>
        <?php if (!$lancamentos): ?>
            <div class="fi-empty">Nenhum lançamento neste mês. Clique em <strong>+ Novo lançamento</strong> pra começar.</div>
        <?php else: ?>
        <div style="overflow-x:auto;">
        <table class="fi-tbl">
            <thead><tr>
                <th style="width:40px;">Pago</th><th>Descrição</th><th>Categoria</th>
                <th>Vencimento</th><th style="text-align:right;">Valor</th><th style="width:90px;"></th>
            </tr></thead>
            <tbody>
            <?php foreach ($lancamentos as $l):
                $atrasado = (!$l['pago'] && $l['vencimento'] && $l['vencimento'] < $hoje);
                $ehEnt = ($l['tipo'] === 'entrada');
            ?>
                <tr data-id="<?= (int)$l['id'] ?>">
                    <td><input type="checkbox" class="fi-chk" <?= $l['pago'] ? 'checked' : '' ?> onchange="fiTogglePago(<?= (int)$l['id'] ?>)"></td>
                    <td>
                        <strong><?= htmlspecialchars($l['descricao']) ?></strong>
                        <?php if (!empty($l['recorrente_id'])): ?><span class="fi-tag" title="Gerado por despesa fixa">🔁 fixa</span><?php endif; ?>
                        <?php if (!empty($l['observacao'])): ?><br><span style="font-size:.75rem;color:var(--text-muted,#6b7280);"><?= htmlspecialchars($l['observacao']) ?></span><?php endif; ?>
                    </td>
                    <td><span class="fi-tag"><?= htmlspecialchars($l['categoria']) ?></span></td>
                    <td class="<?= $atrasado ? 'fi-venc-atras' : '' ?>">
                        <?= $l['vencimento'] ? date('d/m', strtotime($l['vencimento'])) : '—' ?>
                        <?= $atrasado ? ' ⚠️' : '' ?>
                    </td>
                    <td style="text-align:right;">
                        <span class="fi-pill <?= $ehEnt ? 'e' : 's' ?>"><?= $ehEnt ? '+' : '−' ?> <?= fin_int_fmt($l['valor_cents']) ?></span>
                    </td>
                    <td style="text-align:right;white-space:nowrap;">
                        <button class="fi-btn-sm" onclick='fiEditarLanc(<?= json_encode($l, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>✏️</button>
                        <button class="fi-btn-sm" onclick="fiExcluirLanc(<?= (int)$l['id'] ?>)">🗑️</button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- Despesas fixas -->
    <div class="fi-section">
        <h2>
            <span>🔁 Despesas &amp; receitas fixas <span style="font-size:.72rem;font-weight:600;color:var(--text-muted,#6b7280);">geradas todo mês automaticamente</span></span>
            <button class="fi-btn fi-btn-pri" onclick="fiAbrirRec()">+ Nova fixa</button>
        </h2>
        <?php if (!$recorrentes): ?>
            <div class="fi-empty">Cadastre aluguel, salários, softwares… e o sistema lança sozinho todo mês.</div>
        <?php else: ?>
        <div style="overflow-x:auto;">
        <table class="fi-tbl">
            <thead><tr>
                <th>Ativa</th><th>Descrição</th><th>Categoria</th><th>Dia</th>
                <th style="text-align:right;">Valor</th><th style="width:90px;"></th>
            </tr></thead>
            <tbody>
            <?php foreach ($recorrentes as $r): $ehEnt = ($r['tipo'] === 'entrada'); ?>
                <tr style="<?= $r['ativo'] ? '' : 'opacity:.5;' ?>">
                    <td><input type="checkbox" class="fi-chk" <?= $r['ativo'] ? 'checked' : '' ?> onchange="fiToggleRec(<?= (int)$r['id'] ?>)"></td>
                    <td><strong><?= htmlspecialchars($r['descricao']) ?></strong></td>
                    <td><span class="fi-tag"><?= htmlspecialchars($r['categoria']) ?></span></td>
                    <td>dia <?= (int)$r['dia_vencimento'] ?></td>
                    <td style="text-align:right;"><span class="fi-pill <?= $ehEnt ? 'e' : 's' ?>"><?= $ehEnt ? '+' : '−' ?> <?= fin_int_fmt($r['valor_cents']) ?></span></td>
                    <td style="text-align:right;white-space:nowrap;">
                        <button class="fi-btn-sm" onclick='fiEditarRec(<?= json_encode($r, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>✏️</button>
                        <button class="fi-btn-sm" onclick="fiExcluirRec(<?= (int)$r['id'] ?>)">🗑️</button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal lançamento -->
<div class="fi-modal-bg" id="fiModalLanc">
    <div class="fi-modal">
        <h3 id="fiLancTitulo">Novo lançamento</h3>
        <input type="hidden" id="fiLancId" value="0">
        <div class="fi-field">
            <div class="fi-seg">
                <label><input type="radio" name="fiLancTipo" value="entrada" id="fiLancTipoE"><span class="se">↑ Entrada</span></label>
                <label><input type="radio" name="fiLancTipo" value="saida" id="fiLancTipoS" checked><span class="ss">↓ Saída</span></label>
            </div>
        </div>
        <div class="fi-field"><label>Descrição</label><input type="text" id="fiLancDesc" placeholder="Ex.: Aluguel do escritório" maxlength="255"></div>
        <div class="fi-row2">
            <div class="fi-field"><label>Valor (R$)</label><input type="text" id="fiLancValor" inputmode="decimal" placeholder="0,00"></div>
            <div class="fi-field"><label>Vencimento</label><input type="date" id="fiLancVenc" value="<?= $hoje ?>"></div>
        </div>
        <div class="fi-field"><label>Categoria</label>
            <input type="text" id="fiLancCat" list="fiCats" placeholder="Ex.: Aluguel">
        </div>
        <div class="fi-field"><label>Observação (opcional)</label><input type="text" id="fiLancObs" maxlength="255"></div>
        <div class="fi-field"><label style="display:flex;align-items:center;gap:8px;font-weight:700;cursor:pointer;"><input type="checkbox" id="fiLancPago" style="width:auto;"> Já está pago / recebido</label></div>
        <div class="fi-modal-acts">
            <button class="fi-btn-sm" onclick="fiFecharModais()">Cancelar</button>
            <button class="fi-btn fi-btn-pri" onclick="fiSalvarLanc()">Salvar</button>
        </div>
    </div>
</div>

<!-- Modal recorrente -->
<div class="fi-modal-bg" id="fiModalRec">
    <div class="fi-modal">
        <h3 id="fiRecTitulo">Nova despesa fixa</h3>
        <input type="hidden" id="fiRecId" value="0">
        <div class="fi-field">
            <div class="fi-seg">
                <label><input type="radio" name="fiRecTipo" value="entrada" id="fiRecTipoE"><span class="se">↑ Entrada</span></label>
                <label><input type="radio" name="fiRecTipo" value="saida" id="fiRecTipoS" checked><span class="ss">↓ Saída</span></label>
            </div>
        </div>
        <div class="fi-field"><label>Descrição</label><input type="text" id="fiRecDesc" placeholder="Ex.: Aluguel" maxlength="255"></div>
        <div class="fi-row2">
            <div class="fi-field"><label>Valor (R$)</label><input type="text" id="fiRecValor" inputmode="decimal" placeholder="0,00"></div>
            <div class="fi-field"><label>Dia do vencimento</label><input type="number" id="fiRecDia" min="1" max="31" value="5"></div>
        </div>
        <div class="fi-field"><label>Categoria</label><input type="text" id="fiRecCat" list="fiCats" placeholder="Ex.: Aluguel"></div>
        <div class="fi-modal-acts">
            <button class="fi-btn-sm" onclick="fiFecharModais()">Cancelar</button>
            <button class="fi-btn fi-btn-pri" onclick="fiSalvarRec()">Salvar</button>
        </div>
    </div>
</div>

<datalist id="fiCats">
    <option value="Aluguel"><option value="Salários"><option value="Pró-labore"><option value="Softwares/Assinaturas">
    <option value="Contador"><option value="Impostos/Taxas"><option value="Marketing"><option value="Material/Escritório">
    <option value="Energia/Internet"><option value="Custas processuais"><option value="Honorários"><option value="Consulta">
    <option value="Êxito"><option value="Reembolso"><option value="Outros">
</datalist>

<script>
(function(){
    var API = '<?= url('modules/financeiro_interno/api.php') ?>';
    var CSRF = '<?= generate_csrf_token() ?>';

    function post(action, data){
        var fd = new FormData();
        fd.append('action', action);
        fd.append('csrf_token', CSRF);
        for (var k in data){ if (data.hasOwnProperty(k)) fd.append(k, data[k]); }
        return fetch(API, { method:'POST', body:fd, headers:{'X-Requested-With':'XMLHttpRequest'}, credentials:'same-origin' })
            .then(function(r){
                if (r.status === 401){ if (window.fsaMostrarSessaoExpirada) window.fsaMostrarSessaoExpirada(); throw new Error('sessao'); }
                return r.json().catch(function(){ throw new Error('Resposta inválida do servidor.'); });
            })
            .then(function(j){
                if (j && j.csrf_expired){ if (window.fsaMostrarSessaoExpirada) window.fsaMostrarSessaoExpirada(); throw new Error('csrf'); }
                if (j && j.error){ throw new Error(j.error); }
                return j;
            });
    }

    window.fiFecharModais = function(){
        document.getElementById('fiModalLanc').style.display = 'none';
        document.getElementById('fiModalRec').style.display = 'none';
    };

    // ── Lançamento ──
    window.fiAbrirLanc = function(){
        document.getElementById('fiLancTitulo').textContent = 'Novo lançamento';
        document.getElementById('fiLancId').value = '0';
        document.getElementById('fiLancTipoS').checked = true;
        document.getElementById('fiLancDesc').value = '';
        document.getElementById('fiLancValor').value = '';
        document.getElementById('fiLancVenc').value = '<?= $hoje ?>';
        document.getElementById('fiLancCat').value = '';
        document.getElementById('fiLancObs').value = '';
        document.getElementById('fiLancPago').checked = false;
        document.getElementById('fiModalLanc').style.display = 'flex';
        setTimeout(function(){ document.getElementById('fiLancDesc').focus(); }, 50);
    };
    window.fiEditarLanc = function(l){
        document.getElementById('fiLancTitulo').textContent = 'Editar lançamento';
        document.getElementById('fiLancId').value = l.id;
        document.getElementById(l.tipo === 'entrada' ? 'fiLancTipoE' : 'fiLancTipoS').checked = true;
        document.getElementById('fiLancDesc').value = l.descricao || '';
        document.getElementById('fiLancValor').value = (l.valor_cents/100).toFixed(2).replace('.', ',');
        document.getElementById('fiLancVenc').value = l.vencimento || '<?= $hoje ?>';
        document.getElementById('fiLancCat').value = l.categoria || '';
        document.getElementById('fiLancObs').value = l.observacao || '';
        document.getElementById('fiLancPago').checked = (String(l.pago) === '1');
        document.getElementById('fiModalLanc').style.display = 'flex';
    };
    window.fiSalvarLanc = function(){
        var d = {
            id: document.getElementById('fiLancId').value,
            tipo: document.querySelector('input[name=fiLancTipo]:checked').value,
            descricao: document.getElementById('fiLancDesc').value.trim(),
            valor: document.getElementById('fiLancValor').value,
            vencimento: document.getElementById('fiLancVenc').value,
            categoria: document.getElementById('fiLancCat').value.trim(),
            observacao: document.getElementById('fiLancObs').value.trim(),
            pago: document.getElementById('fiLancPago').checked ? 1 : 0
        };
        if (!d.descricao){ alert('Informe a descrição.'); return; }
        post('lanc_salvar', d).then(function(){ location.reload(); }).catch(function(e){ if(e.message!=='sessao'&&e.message!=='csrf') alert(e.message); });
    };
    window.fiTogglePago = function(id){
        post('lanc_toggle_pago', { id:id }).then(function(){ location.reload(); }).catch(function(e){ if(e.message!=='sessao'&&e.message!=='csrf'){ alert(e.message); location.reload(); } });
    };
    window.fiExcluirLanc = function(id){
        if (!confirm('Excluir este lançamento?')) return;
        post('lanc_excluir', { id:id }).then(function(){ location.reload(); }).catch(function(e){ if(e.message!=='sessao'&&e.message!=='csrf') alert(e.message); });
    };

    // ── Recorrente ──
    window.fiAbrirRec = function(){
        document.getElementById('fiRecTitulo').textContent = 'Nova despesa fixa';
        document.getElementById('fiRecId').value = '0';
        document.getElementById('fiRecTipoS').checked = true;
        document.getElementById('fiRecDesc').value = '';
        document.getElementById('fiRecValor').value = '';
        document.getElementById('fiRecDia').value = '5';
        document.getElementById('fiRecCat').value = '';
        document.getElementById('fiModalRec').style.display = 'flex';
        setTimeout(function(){ document.getElementById('fiRecDesc').focus(); }, 50);
    };
    window.fiEditarRec = function(r){
        document.getElementById('fiRecTitulo').textContent = 'Editar despesa fixa';
        document.getElementById('fiRecId').value = r.id;
        document.getElementById(r.tipo === 'entrada' ? 'fiRecTipoE' : 'fiRecTipoS').checked = true;
        document.getElementById('fiRecDesc').value = r.descricao || '';
        document.getElementById('fiRecValor').value = (r.valor_cents/100).toFixed(2).replace('.', ',');
        document.getElementById('fiRecDia').value = r.dia_vencimento || '5';
        document.getElementById('fiRecCat').value = r.categoria || '';
        document.getElementById('fiModalRec').style.display = 'flex';
    };
    window.fiSalvarRec = function(){
        var d = {
            id: document.getElementById('fiRecId').value,
            tipo: document.querySelector('input[name=fiRecTipo]:checked').value,
            descricao: document.getElementById('fiRecDesc').value.trim(),
            valor: document.getElementById('fiRecValor').value,
            dia_vencimento: document.getElementById('fiRecDia').value,
            categoria: document.getElementById('fiRecCat').value.trim(),
            ativo: 1
        };
        if (!d.descricao){ alert('Informe a descrição.'); return; }
        post('rec_salvar', d).then(function(){ location.reload(); }).catch(function(e){ if(e.message!=='sessao'&&e.message!=='csrf') alert(e.message); });
    };
    window.fiToggleRec = function(id){
        post('rec_toggle_ativo', { id:id }).then(function(){ location.reload(); }).catch(function(e){ if(e.message!=='sessao'&&e.message!=='csrf'){ alert(e.message); location.reload(); } });
    };
    window.fiExcluirRec = function(id){
        if (!confirm('Excluir esta despesa fixa? Os lançamentos já gerados continuam.')) return;
        post('rec_excluir', { id:id }).then(function(){ location.reload(); }).catch(function(e){ if(e.message!=='sessao'&&e.message!=='csrf') alert(e.message); });
    };

    // Fecha modal ao clicar fora
    document.querySelectorAll('.fi-modal-bg').forEach(function(bg){
        bg.addEventListener('click', function(e){ if (e.target === bg) fiFecharModais(); });
    });
})();
</script>
<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
