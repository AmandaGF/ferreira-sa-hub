<?php
/**
 * Ferreira & Sá Hub — Proposta de Acordo Financeiro
 * Relatório editável com desconto, gráfico, juros/multa, vantagens do acordo.
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
if (!can_access_financeiro()) { redirect(url('modules/dashboard/')); }

$pdo = db();
$clientId = (int)($_GET['id'] ?? 0);
if (!$clientId) { redirect(module_url('financeiro')); }

$stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ?");
$stmt->execute(array($clientId));
$client = $stmt->fetch();
if (!$client) { redirect(module_url('financeiro')); }

// Cobranças em aberto (PENDING + OVERDUE) e pagas (pra histórico)
$vencidas = $pdo->prepare("SELECT * FROM asaas_cobrancas WHERE client_id = ? AND status = 'OVERDUE' ORDER BY vencimento ASC");
$vencidas->execute(array($clientId)); $vencidas = $vencidas->fetchAll();

$pendentes = $pdo->prepare("SELECT * FROM asaas_cobrancas WHERE client_id = ? AND status = 'PENDING' ORDER BY vencimento ASC");
$pendentes->execute(array($clientId)); $pendentes = $pendentes->fetchAll();

$pagas = $pdo->prepare("SELECT * FROM asaas_cobrancas WHERE client_id = ? AND status IN ('RECEIVED','CONFIRMED','RECEIVED_IN_CASH') ORDER BY data_pagamento DESC");
$pagas->execute(array($clientId)); $pagas = $pagas->fetchAll();

$totalPago    = 0; foreach ($pagas as $p) $totalPago += (float)($p['valor_pago'] ?: $p['valor']);
$totalVencido = 0; foreach ($vencidas as $v) $totalVencido += (float)$v['valor'];
$totalPendente = 0; foreach ($pendentes as $p) $totalPendente += (float)$p['valor'];

// Calcula maior dias de atraso (pra sugerir faixa de desconto)
$diasAtrasoMax = 0;
foreach ($vencidas as $v) {
    $d = (int)((time() - strtotime($v['vencimento'])) / 86400);
    if ($d > $diasAtrasoMax) $diasAtrasoMax = $d;
}
// Faixas de desconto sugeridas
$descSugerido = 0; $tirarJurosMulta = false; $faixaTexto = '';
if ($diasAtrasoMax >= 365)       { $descSugerido = 50; $tirarJurosMulta = true; $faixaTexto = '+ de 1 ano de atraso'; }
elseif ($diasAtrasoMax >= 180)   { $descSugerido = 15; $tirarJurosMulta = true; $faixaTexto = '+ de 6 meses de atraso'; }
elseif ($diasAtrasoMax >= 60)    { $descSugerido = 10; $tirarJurosMulta = true; $faixaTexto = '+ de 2 meses de atraso'; }

// Parâmetros editáveis (via GET pra poder testar)
$descontoPct = isset($_GET['desconto']) ? max(0, min(100, (int)$_GET['desconto'])) : $descSugerido;
$semJurosMulta = isset($_GET['sem_jm']) ? ((int)$_GET['sem_jm'] === 1) : $tirarJurosMulta;
$prazoProposta = $_GET['prazo'] ?? date('Y-m-d', strtotime('+7 days'));
$observacoes   = trim($_GET['obs'] ?? '');

// Conforme CLÁUSULA 5.1 do contrato padrão do escritório:
// "multa pecuniária de 20%, juros de mora de 1% ao mês e correção monetária"
function calcular_juros_multa($valor, $diasAtraso) {
    if ($diasAtraso <= 0) return array('juros' => 0, 'multa' => 0, 'total' => $valor);
    $multa = $valor * 0.20;                        // 20% contratual
    $juros = $valor * 0.01 * ($diasAtraso / 30);   // 1% ao mês, pro-rata dia
    return array('juros' => $juros, 'multa' => $multa, 'total' => $valor + $multa + $juros);
}

// Calcula totais com e sem acréscimos
$totalOriginal = $totalVencido + $totalPendente; // valor nominal
$totalComAcrescimos = 0;
$totalJuros = 0; $totalMulta = 0;
foreach ($vencidas as $v) {
    $d = (int)((time() - strtotime($v['vencimento'])) / 86400);
    $r = calcular_juros_multa((float)$v['valor'], $d);
    $totalComAcrescimos += $r['total'];
    $totalJuros += $r['juros']; $totalMulta += $r['multa'];
}
$totalComAcrescimos += $totalPendente; // pendente não tem acréscimo ainda

// Valor final da proposta
$baseProposta = $semJurosMulta ? $totalOriginal : $totalComAcrescimos;
$desconto = $baseProposta * ($descontoPct / 100);
$valorFinal = $baseProposta - $desconto;
$economia = ($semJurosMulta ? $totalComAcrescimos : $baseProposta) - $valorFinal;

$pageTitle = 'Proposta — ' . $client['name'];
require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.pa-toolbar { background:#fff;border:1px solid var(--border);border-radius:10px;padding:1rem 1.25rem;margin-bottom:1rem; }
.pa-toolbar h3 { font-size:1rem;font-weight:800;margin-bottom:.75rem;color:#052228; }
.pa-grid { display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:.75rem;margin-bottom:.75rem; }
.pa-grid label { font-size:.72rem;font-weight:700;color:var(--text-muted);display:block;margin-bottom:.2rem;text-transform:uppercase;letter-spacing:.4px; }
.pa-grid input, .pa-grid select, .pa-grid textarea { width:100%;padding:6px 10px;border:1px solid var(--border);border-radius:6px;font-size:.85rem; }
.pa-actions { display:flex;gap:.5rem;flex-wrap:wrap; }
.pa-quick { display:flex;gap:.4rem;flex-wrap:wrap;margin-bottom:.75rem; }
.pa-quick button { padding:4px 10px;background:#fff;border:1.5px solid var(--border);border-radius:20px;font-size:.74rem;cursor:pointer;font-weight:600; }
.pa-quick button.active { background:#b45309;color:#fff;border-color:#b45309; }

/* Relatório imprimível */
.prop { background:#fff;padding:2.5rem;border:1px solid #e5e7eb;border-radius:8px;max-width:900px;margin:0 auto;font-family:'Inter',sans-serif;color:#0f172a; }
.prop-head { text-align:center;border-bottom:3px solid #052228;padding-bottom:1rem;margin-bottom:1.5rem; }
.prop-head h1 { font-size:1.5rem;color:#052228;margin-bottom:.3rem; }
.prop-head .subh { font-size:.85rem;color:#64748b; }
.prop h2 { font-size:1.05rem;color:#052228;border-bottom:2px solid #f1f5f9;padding-bottom:.4rem;margin:1.5rem 0 .75rem; }
.prop h3 { font-size:.95rem;color:#052228;margin:1rem 0 .5rem; }
.prop p { font-size:.88rem;line-height:1.5;margin-bottom:.5rem; }
.prop table { width:100%;border-collapse:collapse;font-size:.82rem;margin:.5rem 0 1rem; }
.prop table th { text-align:left;background:#f8fafc;padding:.4rem .6rem;font-size:.72rem;text-transform:uppercase;color:#475569;font-weight:700; }
.prop table td { padding:.45rem .6rem;border-bottom:1px solid #f1f5f9; }
.prop-box-destaque { background:linear-gradient(135deg,#fef3c7,#fde68a);border:2px solid #b45309;border-radius:10px;padding:1.25rem;margin:1.5rem 0;text-align:center; }
.prop-box-destaque .valor-old { text-decoration:line-through;color:#94a3b8;font-size:1.1rem; }
.prop-box-destaque .valor-new { font-size:2rem;font-weight:900;color:#b45309;margin:.3rem 0; }
.prop-box-destaque .economia { background:#052228;color:#fff;display:inline-block;padding:.4rem 1rem;border-radius:20px;font-weight:800;margin-top:.5rem; }
.prop-vantagens { display:grid;grid-template-columns:repeat(2,1fr);gap:.75rem;margin:1rem 0; }
.prop-vantagens li { background:#f0fdf4;border-left:4px solid #059669;padding:.6rem .8rem;border-radius:6px;font-size:.85rem;list-style:none;font-weight:600;color:#065f46; }
.prop-sign { margin-top:2rem;padding-top:1rem;border-top:1px dashed #cbd5e1;font-size:.82rem;color:#475569; }
.prop-sign strong { color:#052228; }
.prop-chart { margin:1rem auto;max-width:500px; }

@media print {
    body * { visibility:hidden; }
    .prop, .prop * { visibility:visible; }
    .prop { position:absolute;left:0;top:0;max-width:100%;border:none;padding:1rem 2rem;box-shadow:none; }
    .pa-toolbar, .sidebar, .fav-bar, .topbar, button.no-print, .btn { display:none !important; }
    @page { size:A4;margin:1.5cm; }
}
</style>

<!-- Toolbar (não imprime) -->
<div class="pa-toolbar no-print">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:.5rem;margin-bottom:.5rem;">
        <div>
            <h3>📄 Proposta de Acordo — <?= e($client['name']) ?></h3>
            <div style="font-size:.8rem;color:var(--text-muted);"><?= $faixaTexto ? '⏰ Situação: <strong style="color:#b45309;">' . e($faixaTexto) . '</strong> — desconto sugerido: ' . $descSugerido . '%' : 'Cliente em dia ou atraso leve' ?></div>
        </div>
        <a href="<?= module_url('financeiro', 'cliente.php?id=' . $clientId) ?>" class="btn btn-outline btn-sm">← Voltar</a>
    </div>

    <form method="GET" id="formProposta">
        <input type="hidden" name="id" value="<?= $clientId ?>">

        <!-- Atalhos de desconto -->
        <div class="pa-quick">
            <span style="font-size:.72rem;font-weight:700;color:var(--text-muted);padding:6px 2px;">Atalhos:</span>
            <button type="button" onclick="setProp(0, 0)">0% (sem desconto)</button>
            <button type="button" onclick="setProp(10, 1)" class="<?= $descontoPct === 10 ? 'active' : '' ?>">10% + s/ juros (2m)</button>
            <button type="button" onclick="setProp(15, 1)" class="<?= $descontoPct === 15 ? 'active' : '' ?>">15% + s/ juros (6m)</button>
            <button type="button" onclick="setProp(50, 1)" class="<?= $descontoPct === 50 ? 'active' : '' ?>">50% + s/ juros (1a)</button>
        </div>

        <div class="pa-grid">
            <div>
                <label>Desconto (%)</label>
                <input type="number" name="desconto" id="inputDesc" min="0" max="100" value="<?= $descontoPct ?>">
            </div>
            <div>
                <label>Remover juros e multa?</label>
                <select name="sem_jm" id="inputSemJM">
                    <option value="1" <?= $semJurosMulta ? 'selected' : '' ?>>Sim, zerar juros e multa</option>
                    <option value="0" <?= !$semJurosMulta ? 'selected' : '' ?>>Não, cobrar acréscimos</option>
                </select>
            </div>
            <div>
                <label>Validade da proposta</label>
                <input type="date" name="prazo" value="<?= e($prazoProposta) ?>">
            </div>
            <div>
                <label>Observações (opcional)</label>
                <input type="text" name="obs" value="<?= e($observacoes) ?>" placeholder="Ex: pagamento à vista via PIX">
            </div>
        </div>

        <div class="pa-actions">
            <button type="submit" class="btn btn-primary btn-sm" style="background:#052228;">🔄 Recalcular</button>
            <button type="button" onclick="window.print()" class="btn btn-sm" style="background:#b45309;color:#fff;">🖨️ Imprimir / Salvar PDF</button>
            <?php if ($client['phone']):
                $msg = "Olá " . explode(' ', $client['name'])[0] . "! 📄\n\nPreparamos uma *proposta de acordo especial* para você quitar as pendências com condições muito vantajosas:\n\n"
                     . "💰 Valor à vista: *R$ " . number_format($valorFinal, 2, ',', '.') . "*\n"
                     . "💵 Economia: *R$ " . number_format($economia, 2, ',', '.') . "*"
                     . ($descontoPct > 0 ? " (" . $descontoPct . "% de desconto)" : "")
                     . "\n⏰ Válida até: *" . date('d/m/Y', strtotime($prazoProposta)) . "*\n\n"
                     . "Vantagens do acordo:\n✅ Evita processo judicial\n✅ Evita negativação no SPC/Serasa\n✅ Resolve de forma rápida e amigável\n\n"
                     . "Podemos conversar?\n\n_Ferreira & Sá Advocacia_";
            ?>
            <button type="button" onclick="waSenderOpen({telefone:'<?= preg_replace('/\D/', '', $client['phone']) ?>',nome:<?= json_encode($client['name']) ?>,clientId:<?= (int)$clientId ?>,canal:'24',mensagem:<?= json_encode($msg) ?>})" class="btn btn-success btn-sm" style="border:none;">💬 Enviar resumo por WhatsApp</button>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Relatório (imprimível) -->
<div class="prop">
    <div class="prop-head">
        <h1>FERREIRA & SÁ ADVOCACIA</h1>
        <div class="subh">Rua Dr. Aldrovando de Oliveira, 140 — Ano Bom — Barra Mansa/RJ<br>
        <strong>PROPOSTA DE ACORDO FINANCEIRO</strong> · Emitida em <?= date('d/m/Y') ?></div>
    </div>

    <p><strong>Cliente:</strong> <?= e($client['name']) ?> <?php if ($client['cpf']): ?> &nbsp;·&nbsp; <strong>CPF:</strong> <?= e($client['cpf']) ?><?php endif; ?></p>
    <p><strong>Proposta válida até:</strong> <?= date('d/m/Y', strtotime($prazoProposta)) ?></p>

    <h2>1. Histórico de Pagamentos Efetuados</h2>
    <p>Pagamentos já realizados por este cliente ao longo do relacionamento com o escritório:</p>
    <?php if (empty($pagas)): ?>
        <p style="color:#64748b;font-style:italic;">Nenhum pagamento registrado até o momento.</p>
    <?php else: ?>
        <table>
            <thead><tr><th>Descrição</th><th>Vencimento</th><th>Pago em</th><th style="text-align:right;">Valor</th></tr></thead>
            <tbody>
            <?php foreach (array_slice($pagas, 0, 30) as $p): ?>
                <tr>
                    <td><?= e(mb_substr($p['descricao'] ?: 'Honorários Advocatícios', 0, 70)) ?></td>
                    <td><?= date('d/m/Y', strtotime($p['vencimento'])) ?></td>
                    <td><?= $p['data_pagamento'] ? date('d/m/Y', strtotime($p['data_pagamento'])) : '—' ?></td>
                    <td style="text-align:right;font-weight:700;color:#059669;">R$ <?= number_format((float)($p['valor_pago'] ?: $p['valor']), 2, ',', '.') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr style="background:#f0fdf4;"><td colspan="3" style="font-weight:700;text-align:right;">Total pago historicamente:</td><td style="text-align:right;font-weight:800;color:#059669;">R$ <?= number_format($totalPago, 2, ',', '.') ?></td></tr></tfoot>
        </table>
        <?php if (count($pagas) > 30): ?><p style="font-size:.75rem;color:#64748b;font-style:italic;">Exibindo 30 de <?= count($pagas) ?> pagamentos — totais já consolidados acima.</p><?php endif; ?>
    <?php endif; ?>

    <h2>2. Valores em Aberto</h2>
    <?php if (empty($vencidas) && empty($pendentes)): ?>
        <p style="color:#059669;">✅ Nenhum débito em aberto.</p>
    <?php else: ?>
        <?php if (!empty($vencidas)): ?>
            <h3 style="color:#dc2626;">🚨 Parcelas VENCIDAS</h3>
            <table>
                <thead><tr><th>Descrição</th><th>Vencimento</th><th style="text-align:right;">Dias atraso</th><th style="text-align:right;">Valor original</th><?php if (!$semJurosMulta): ?><th style="text-align:right;">+ Juros/Multa</th><th style="text-align:right;">Total</th><?php endif; ?></tr></thead>
                <tbody>
                <?php foreach ($vencidas as $v):
                    $d = (int)((time() - strtotime($v['vencimento'])) / 86400);
                    $r = calcular_juros_multa((float)$v['valor'], $d);
                ?>
                <tr>
                    <td><?= e(mb_substr($v['descricao'] ?: 'Honorários Advocatícios', 0, 60)) ?></td>
                    <td><?= date('d/m/Y', strtotime($v['vencimento'])) ?></td>
                    <td style="text-align:right;color:#dc2626;font-weight:700;"><?= $d ?></td>
                    <td style="text-align:right;">R$ <?= number_format((float)$v['valor'], 2, ',', '.') ?></td>
                    <?php if (!$semJurosMulta): ?>
                    <td style="text-align:right;color:#b45309;">R$ <?= number_format($r['juros'] + $r['multa'], 2, ',', '.') ?></td>
                    <td style="text-align:right;font-weight:700;">R$ <?= number_format($r['total'], 2, ',', '.') ?></td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php if (!empty($pendentes)): ?>
            <h3 style="color:#b45309;">📋 Parcelas a Vencer</h3>
            <table>
                <thead><tr><th>Descrição</th><th>Vencimento</th><th style="text-align:right;">Valor</th></tr></thead>
                <tbody>
                <?php foreach ($pendentes as $p): ?>
                <tr>
                    <td><?= e(mb_substr($p['descricao'] ?: 'Honorários Advocatícios', 0, 60)) ?></td>
                    <td><?= date('d/m/Y', strtotime($p['vencimento'])) ?></td>
                    <td style="text-align:right;font-weight:700;">R$ <?= number_format((float)$p['valor'], 2, ',', '.') ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <table style="margin-top:1rem;">
            <tr><td style="font-weight:700;">Total vencido (valor nominal):</td><td style="text-align:right;">R$ <?= number_format($totalVencido, 2, ',', '.') ?></td></tr>
            <?php if (!$semJurosMulta && ($totalJuros + $totalMulta) > 0): ?>
            <tr><td style="font-weight:700;">+ Multa contratual (20%) e juros de mora (1%/mês):</td><td style="text-align:right;color:#b45309;">R$ <?= number_format($totalJuros + $totalMulta, 2, ',', '.') ?></td></tr>
            <?php endif; ?>
            <tr><td style="font-weight:700;">Total a vencer:</td><td style="text-align:right;">R$ <?= number_format($totalPendente, 2, ',', '.') ?></td></tr>
            <tr style="background:#fef2f2;"><td style="font-weight:800;">TOTAL DEVIDO <?= $semJurosMulta ? '(sem acréscimos)' : 'com acréscimos' ?>:</td><td style="text-align:right;font-weight:800;color:#dc2626;font-size:1.05rem;">R$ <?= number_format($baseProposta, 2, ',', '.') ?></td></tr>
        </table>
        <?php if (!$semJurosMulta): ?>
        <p style="font-size:.7rem;color:#64748b;font-style:italic;margin-top:-.5rem;">Acréscimos calculados conforme <strong>cláusula 5.1 do contrato de honorários</strong>: multa pecuniária de 20%, juros de mora de 1% ao mês e correção monetária.</p>
        <?php endif; ?>
    <?php endif; ?>

    <?php if (($totalVencido + $totalPendente) > 0): ?>
    <h2>3. Proposta Especial de Quitação</h2>
    <p>Em atenção ao histórico de relacionamento e considerando que <strong>o trabalho jurídico já foi prestado por nosso escritório</strong>, apresentamos abaixo uma <strong>proposta de acordo</strong> com condições especiais, <strong>exclusiva para pagamento à vista</strong> até a data de validade.</p>

    <?php if ($semJurosMulta): ?>
        <p>🎁 <strong>Benefício 1:</strong> ZERAMOS os juros de mora e a multa contratual.</p>
    <?php endif; ?>
    <?php if ($descontoPct > 0): ?>
        <p>🎁 <strong>Benefício <?= $semJurosMulta ? '2' : '1' ?>:</strong> Concedemos um <strong>desconto de <?= $descontoPct ?>%</strong> sobre o valor devido.</p>
    <?php endif; ?>
    <?php if ($observacoes): ?>
        <p><strong>Obs:</strong> <?= e($observacoes) ?></p>
    <?php endif; ?>

    <div class="prop-box-destaque">
        <?php if ($descontoPct > 0 || $semJurosMulta): ?>
        <div class="valor-old">De R$ <?= number_format($totalComAcrescimos, 2, ',', '.') ?></div>
        <?php endif; ?>
        <div class="valor-new">R$ <?= number_format($valorFinal, 2, ',', '.') ?></div>
        <div style="font-size:.8rem;color:#78350f;">à vista (PIX ou boleto), válido até <?= date('d/m/Y', strtotime($prazoProposta)) ?></div>
        <?php if ($economia > 0): ?>
        <div class="economia">💰 Você economiza R$ <?= number_format($economia, 2, ',', '.') ?></div>
        <?php endif; ?>
    </div>

    <!-- Gráfico comparativo -->
    <div class="prop-chart">
        <canvas id="chartEconomia" height="160"></canvas>
    </div>

    <h2>4. Vantagens de Fechar o Acordo</h2>
    <ul class="prop-vantagens">
        <li>✅ Evita <strong>negativação</strong> no SPC / Serasa / SCR</li>
        <li>✅ Evita <strong>processo judicial de cobrança</strong> (custas e honorários extras)</li>
        <li>✅ Evita <strong>aumento contínuo</strong> da dívida com juros diários</li>
        <li>✅ Mantém o <strong>bom relacionamento</strong> com o escritório</li>
        <li>✅ Possibilita <strong>emissão imediata</strong> do termo de quitação</li>
        <li>✅ Preserva seu <strong>nome limpo</strong> e histórico de crédito</li>
    </ul>

    <h2>5. Justificativa</h2>
    <p style="background:#f8fafc;border-left:4px solid #052228;padding:.75rem 1rem;border-radius:6px;font-size:.85rem;">
        Os honorários advocatícios correspondem ao <strong>trabalho jurídico efetivamente prestado</strong> por este escritório em favor do(a) cliente. Nossa equipe dedicou tempo, técnica e recursos para acompanhar o caso, elaborar peças processuais e buscar o melhor resultado. A presente proposta tem como objetivo <strong>facilitar a regularização da pendência de forma amigável</strong>, preservando a relação entre as partes e evitando medidas judiciais, que sempre acarretam mais ônus ao devedor.
    </p>
    <?php endif; ?>

    <div class="prop-sign">
        <p><strong>Como aceitar esta proposta:</strong></p>
        <p>📱 Responda este documento pelo WhatsApp <strong>(<?= $client['phone'] ? substr(preg_replace('/\D/','',$client['phone']), 0, 2) : '24' ?>) <?= $client['phone'] ? substr(preg_replace('/\D/','',$client['phone']), 2) : '—' ?></strong><br>
        📧 Ou envie e-mail para <strong>contato@ferreiraesa.com.br</strong><br>
        Em seguida, enviaremos os dados para pagamento à vista (PIX ou boleto).</p>
        <p style="margin-top:1rem;color:#64748b;font-size:.75rem;">
            <em>Esta proposta tem validade somente até a data mencionada e está condicionada ao pagamento integral à vista. Após o vencimento, os valores retornam ao total com todos os acréscimos legais e contratuais.</em>
        </p>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
function setProp(desc, sjm) {
    document.getElementById('inputDesc').value = desc;
    document.getElementById('inputSemJM').value = sjm;
    document.getElementById('formProposta').submit();
}

(function(){
    var c = document.getElementById('chartEconomia');
    if (!c) return;
    new Chart(c, {
        type: 'bar',
        data: {
            labels: ['Dívida completa', 'Proposta de acordo'],
            datasets: [{
                label: 'Valor (R$)',
                data: [<?= round($totalComAcrescimos, 2) ?>, <?= round($valorFinal, 2) ?>],
                backgroundColor: ['#dc2626', '#059669'],
                borderRadius: 8
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: false },
                title: { display: true, text: 'Sua economia com o acordo', font: { size: 14, weight: 'bold' }, color: '#052228' }
            },
            scales: {
                y: { beginAtZero: true, ticks: { callback: function(v){ return 'R$'+v.toLocaleString('pt-BR'); } } }
            }
        }
    });
})();
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
