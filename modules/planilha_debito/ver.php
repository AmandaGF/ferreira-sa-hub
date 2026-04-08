<?php
/**
 * Planilha de Débito — Visualização para impressão/PDF
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();

$pdo = db();
$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT pd.*, u.name as user_name, cs.title as case_title, cs.case_number, cl.name as client_name
    FROM planilha_debito pd
    LEFT JOIN users u ON u.id = pd.created_by
    LEFT JOIN cases cs ON cs.id = pd.case_id
    LEFT JOIN clients cl ON cl.id = pd.client_id
    WHERE pd.id = ?");
$stmt->execute(array($id));
$pl = $stmt->fetch();

if (!$pl) { die('Planilha não encontrada.'); }

$dados = json_decode($pl['dados_json'], true);
if (!$dados) { die('Dados inválidos.'); }

$meta = isset($dados['meta']) ? $dados['meta'] : array();
$parcelas = isset($dados['parcelas']) ? $dados['parcelas'] : array();
$sub = isset($dados['subtotais']) ? $dados['subtotais'] : array();
$userName = current_user()['name'] ?? 'Usuário';
$anoAtual = date('Y');
$hoje = date('d/m/Y H:i');

function fmtMoney($v) { return 'R$ ' . number_format((float)$v, 2, ',', '.'); }
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Planilha de Débito — <?= e($pl['titulo']) ?></title>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family: 'Segoe UI', Arial, sans-serif; font-size:10px; color:#222; padding:15px 25px; }

        .header { display:flex; justify-content:space-between; align-items:center; border-bottom:3px solid #052228; padding-bottom:10px; margin-bottom:14px; }
        .header-left { display:flex; align-items:center; gap:10px; }
        .header-left h1 { font-size:15px; color:#052228; }
        .header-left p { font-size:8px; color:#666; }
        .header-right { text-align:right; font-size:8px; color:#666; }
        .header-right .logo-text { font-size:13px; font-weight:800; color:#052228; }

        .meta-grid { display:grid; grid-template-columns:1fr 1fr; gap:4px 16px; margin-bottom:14px; font-size:10px; padding:8px 10px; background:#f8f9fa; border-radius:6px; border-left:4px solid #B87333; }
        .meta-label { font-size:8px; text-transform:uppercase; color:#888; font-weight:700; }
        .meta-value { font-weight:600; color:#052228; }

        table { width:100%; border-collapse:collapse; font-size:9px; margin-bottom:10px; }
        table th { background:#052228; color:#fff; padding:5px 6px; text-align:right; font-size:8px; text-transform:uppercase; letter-spacing:.3px; }
        table th:nth-child(1), table th:nth-child(2), table th:nth-child(3) { text-align:left; }
        table td { padding:4px 6px; border-bottom:1px solid #e0e0e0; text-align:right; }
        table td:nth-child(1), table td:nth-child(2), table td:nth-child(3) { text-align:left; }
        table tr:nth-child(even) { background:#fafbfc; }
        table tr.pago { color:#059669; font-style:italic; }
        table tr.negativo { color:#dc2626; font-style:italic; }
        table tr.subtotal { background:#E8E0D5; font-weight:700; font-size:10px; }
        table tr.total { background:#052228; color:#fff; font-weight:700; font-size:11px; }
        table tr.total td { border:none; padding:6px; }
        table tr.honorarios { background:#B87333; color:#fff; font-weight:600; }

        .footer { margin-top:16px; padding-top:8px; border-top:2px solid #052228; display:flex; justify-content:space-between; font-size:7px; color:#888; }

        .no-print { margin-bottom:14px; text-align:center; }
        .no-print button { padding:8px 24px; background:#052228; color:#fff; border:none; border-radius:8px; font-size:13px; font-weight:700; cursor:pointer; margin:0 4px; }
        .no-print button.outline { background:#fff; color:#052228; border:2px solid #052228; }
        .no-print button.green { background:#059669; }

        .obs-box { margin-top:8px; padding:6px 10px; background:#fffbeb; border-left:3px solid #B87333; border-radius:0 4px 4px 0; font-size:9px; color:#6b4c00; }

        @media print {
            .no-print { display:none !important; }
            body { padding:8px 15px; }
            @page { margin:10mm 8mm; size:A4 landscape; }
        }
    </style>
</head>
<body>

<div class="no-print">
    <button onclick="window.print()">🖨️ Imprimir / Salvar PDF</button>
    <?php if ($pl['xlsx_path']): ?><button class="green" onclick="window.open('<?= url($pl['xlsx_path']) ?>','_blank')">📥 Baixar XLSX</button><?php endif; ?>
    <button class="outline" onclick="window.close()">Fechar</button>
</div>

<div class="header">
    <div class="header-left">
        <img src="<?= url('assets/img/logo-sidebar.png') ?>" alt="Logo" style="width:42px;height:42px;border-radius:8px;object-fit:cover;" onerror="this.style.display='none'">
        <div>
            <h1>PLANILHA DE DÉBITO</h1>
            <p>Ferreira & Sá Advocacia — Rua Dr. Aldrovando de Oliveira, 140 — Ano Bom — Barra Mansa/RJ</p>
        </div>
    </div>
    <div class="header-right">
        <div class="logo-text">Portal Ferreira & Sá HUB — <?= $anoAtual ?></div>
        <div>Gerado em <?= $hoje ?></div>
        <div>Por: <?= e($userName) ?></div>
    </div>
</div>

<!-- Metadados -->
<div class="meta-grid">
    <?php if (isset($meta['titulo'])): ?><div><span class="meta-label">Título</span><div class="meta-value"><?= e($meta['titulo']) ?></div></div><?php endif; ?>
    <?php if (isset($meta['processo'])): ?><div><span class="meta-label">Processo</span><div class="meta-value"><?= e($meta['processo']) ?></div></div><?php endif; ?>
    <?php if (isset($meta['autor'])): ?><div><span class="meta-label">Autor/Exequente</span><div class="meta-value"><?= e($meta['autor']) ?></div></div><?php endif; ?>
    <?php if (isset($meta['reu'])): ?><div><span class="meta-label">Réu/Executado</span><div class="meta-value"><?= e($meta['reu']) ?></div></div><?php endif; ?>
    <?php if (isset($meta['indice_correcao'])): ?><div><span class="meta-label">Índice de Correção</span><div class="meta-value"><?= e($meta['indice_correcao']) ?></div></div><?php endif; ?>
    <?php if (isset($meta['juros'])): ?><div><span class="meta-label">Juros</span><div class="meta-value"><?= e($meta['juros']) ?></div></div><?php endif; ?>
    <?php if (isset($meta['data_calculo'])): ?><div><span class="meta-label">Data do Cálculo</span><div class="meta-value"><?= e($meta['data_calculo']) ?></div></div><?php endif; ?>
    <?php if ($pl['case_title']): ?><div><span class="meta-label">Pasta vinculada</span><div class="meta-value"><?= e($pl['case_title']) ?><?= $pl['case_number'] ? ' — ' . e($pl['case_number']) : '' ?></div></div><?php endif; ?>
</div>

<!-- Tabela de parcelas -->
<table>
    <thead>
        <tr>
            <th style="width:35px;">Nº</th>
            <th>Descrição</th>
            <th style="width:75px;">Vencimento</th>
            <th style="width:90px;">Valor Nominal</th>
            <th style="width:90px;">Correção</th>
            <th style="width:90px;">Juros</th>
            <th style="width:100px;">Valor Atualizado</th>
            <th style="width:80px;">Pago</th>
            <th>Obs.</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($parcelas as $p):
            $isPago = (float)($p['pago'] ?? 0) > 0;
            $isNeg = (float)($p['valor_nominal'] ?? 0) < 0 || (isset($p['descricao']) && stripos($p['descricao'], 'pag') !== false);
            $trClass = $isNeg ? 'negativo' : ($isPago ? 'pago' : '');
        ?>
        <tr class="<?= $trClass ?>">
            <td><?= e($p['numero'] ?? '') ?></td>
            <td><?= e($p['descricao'] ?? '') ?></td>
            <td><?= e($p['vencimento'] ?? '') ?></td>
            <td><?= fmtMoney($p['valor_nominal'] ?? 0) ?></td>
            <td><?= fmtMoney($p['correcao_monetaria'] ?? 0) ?></td>
            <td><?= fmtMoney($p['juros'] ?? 0) ?></td>
            <td style="font-weight:600;"><?= fmtMoney($p['valor_atualizado'] ?? 0) ?></td>
            <td><?= (float)($p['pago'] ?? 0) > 0 ? fmtMoney($p['pago']) : '—' ?></td>
            <td style="font-size:8px;"><?= e($p['observacao'] ?? '') ?></td>
        </tr>
        <?php endforeach; ?>

        <!-- Subtotais -->
        <tr class="subtotal">
            <td colspan="3" style="text-align:right;">SUBTOTAIS</td>
            <td><?= fmtMoney($sub['total_nominal'] ?? 0) ?></td>
            <td><?= fmtMoney($sub['total_correcao'] ?? 0) ?></td>
            <td><?= fmtMoney($sub['total_juros'] ?? 0) ?></td>
            <td><?= fmtMoney($sub['total_atualizado'] ?? 0) ?></td>
            <td><?= (float)($sub['total_pago'] ?? 0) > 0 ? fmtMoney($sub['total_pago']) : '—' ?></td>
            <td></td>
        </tr>

        <!-- Débito total -->
        <tr class="total">
            <td colspan="6" style="text-align:right;">DÉBITO TOTAL</td>
            <td style="font-size:13px;"><?= fmtMoney($dados['debito_total'] ?? 0) ?></td>
            <td colspan="2"></td>
        </tr>

        <?php if (isset($dados['honorarios_valor']) && $dados['honorarios_valor'] > 0): ?>
        <tr class="honorarios">
            <td colspan="6" style="text-align:right;">Honorários (<?= $dados['honorarios_pct'] ?? '10' ?>%)</td>
            <td><?= fmtMoney($dados['honorarios_valor']) ?></td>
            <td colspan="2"></td>
        </tr>
        <?php endif; ?>

        <?php if (isset($dados['total_execucao']) && $dados['total_execucao'] > 0): ?>
        <tr class="total">
            <td colspan="6" style="text-align:right;">TOTAL PARA EXECUÇÃO</td>
            <td style="font-size:13px;"><?= fmtMoney($dados['total_execucao']) ?></td>
            <td colspan="2"></td>
        </tr>
        <?php endif; ?>
    </tbody>
</table>

<?php if (isset($meta['observacoes']) && $meta['observacoes']): ?>
<div class="obs-box">
    <strong>Observações:</strong> <?= e($meta['observacoes']) ?>
</div>
<?php endif; ?>

<div class="footer">
    <div>Ferreira & Sá Advocacia — OAB/RJ 65.532 · OAB/RJ 249.105</div>
    <div>Portal Ferreira & Sá HUB — <?= $anoAtual ?> · Impresso por <?= e($userName) ?> em <?= $hoje ?></div>
</div>

</body>
</html>
