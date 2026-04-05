<?php
/**
 * Relatório Visual — Levantamento de Gastos / Pensão Alimentícia
 * Gráfico de pizza, tabela de despesas, resumo, exportável em PDF
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();

$pdo = db();
$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare(
    "SELECT fs.*, c.name as linked_client_name
     FROM form_submissions fs
     LEFT JOIN clients c ON c.id = fs.linked_client_id
     WHERE fs.id = ? AND fs.form_type IN ('gastos_pensao', 'despesas_mensais')"
);
$stmt->execute(array($id));
$form = $stmt->fetch();

if (!$form) { flash_set('error', 'Formulário não encontrado.'); redirect(module_url('formularios')); }

$payload = json_decode($form['payload_json'], true);
if (!is_array($payload)) $payload = array();

// Compatibilidade: novo formulário (despesas_mensais) usa nomes de campos diferentes
$isDespesasMensais = ($form['form_type'] === 'despesas_mensais');

// Extrair dados
$nomeResp = isset($payload['nome_responsavel']) ? $payload['nome_responsavel'] : (isset($payload['nome_completo']) ? $payload['nome_completo'] : ($form['client_name'] ?: '—'));
$cpfResp = isset($payload['cpf_responsavel']) ? $payload['cpf_responsavel'] : (isset($payload['cpf']) ? $payload['cpf'] : '—');
$whatsapp = isset($payload['whatsapp']) ? $payload['whatsapp'] : ($form['client_phone'] ?: '—');
$nomeFilho = isset($payload['nome_filho_referente']) ? $payload['nome_filho_referente'] : '—';
$teaStatus = isset($payload['tea_status']) ? $payload['tea_status'] : '—';
$tratamento = isset($payload['faz_tratamento_especifico']) ? $payload['faz_tratamento_especifico'] : '';
$detalheTrat = isset($payload['detalhe_tratamento']) ? $payload['detalhe_tratamento'] : '';
$qtdFilhos = isset($payload['qtd_filhos']) ? $payload['qtd_filhos'] : '—';
$fonteRenda = isset($payload['fonte_renda']) ? $payload['fonte_renda'] : '—';
$rendaMensal = isset($payload['renda_mensal_cents']) ? (int)$payload['renda_mensal_cents'] : 0;
$quemPaga = isset($payload['quem_paga']) ? $payload['quem_paga'] : '—';
$rendaObrigado = isset($payload['renda_obrigado_cents']) ? (int)$payload['renda_obrigado_cents'] : 0;
$moradores = isset($payload['moradores']) ? $payload['moradores'] : '—';
$protocolo = $form['protocol'];
$dataForm = date('d/m/Y', strtotime($form['created_at']));

// O payload_json pode ter nesting duplo: payload_json contém string JSON com stored/totais
// Tentar desaninhar se payload_json existe como string dentro do payload
if (isset($payload['payload_json']) && is_string($payload['payload_json'])) {
    $inner = json_decode($payload['payload_json'], true);
    if (is_array($inner)) {
        // Mesclar dados internos (prioridade para o inner)
        $payload = array_merge($payload, $inner);
    }
}

// Totais podem estar em $payload['totais'] (app) ou no nível raiz (migrado)
$totais = isset($payload['totais']) ? $payload['totais'] : $payload;

// Compatibilidade com novo formulário (despesas_mensais)
// Mapear total_moradia → moradia_rateada_cents, total_alim → alimentacao_cents, etc.
if ($isDespesasMensais) {
    $mapTotais = array(
        'total_moradia' => 'moradia_rateada_cents',
        'total_alim' => 'alimentacao_cents',
        'total_saude' => 'saude_cents',
        'total_edu' => 'educacao_cents',
        'total_transp' => 'transporte_cents',
        'total_vest' => 'vestuario_cents',
        'total_lazer' => 'lazer_cents',
        'total_tech' => 'tecnologia_cents',
        'total_care' => 'cuidados_cents',
        'total_outros' => 'outros_cents',
    );
    foreach ($mapTotais as $novo => $antigo) {
        if (isset($payload[$novo]) && !isset($totais[$antigo])) {
            $totais[$antigo] = (int)$payload[$novo];
        }
    }
    if (isset($payload['total_geral']) && !isset($totais['total_geral_cents'])) {
        $totais['total_geral_cents'] = (int)$payload['total_geral'];
    }
    // Moradia total (para nota de rateio)
    if (!isset($totais['moradia_total_cents'])) {
        // Somar todos os campos moradia_ do payload
        $moradiaSoma = 0;
        foreach ($payload as $k => $v) {
            if (strpos($k, 'moradia_') === 0 && is_numeric($v)) $moradiaSoma += (int)$v;
        }
        if ($moradiaSoma > 0) $totais['moradia_total_cents'] = $moradiaSoma;
    }
}

// Detalhamento individual dos gastos (subcategorias)
// O novo formulário salva campos com prefixo por categoria no raiz do payload
$stored = isset($payload['stored']) ? $payload['stored'] : array();
if (empty($stored) && $isDespesasMensais) {
    // Usar o próprio payload como stored (campos individuais estão no raiz)
    $stored = $payload;
}

// Se stored é string JSON (aninhado), decodificar
if (is_string($stored)) {
    $storedDecoded = json_decode($stored, true);
    if (is_array($storedDecoded)) $stored = $storedDecoded;
    else $stored = array();
}

// Moradia: usar rateada (dividida por moradores)
$moradiaTotal = isset($totais['moradia_total_cents']) ? (int)$totais['moradia_total_cents'] : 0;
$moradiaRateada = isset($totais['moradia_rateada_cents']) ? (int)$totais['moradia_rateada_cents'] : 0;
$numMoradores = is_numeric($moradores) ? (int)$moradores : 1;

// Categorias de gastos (busca nos totais)
$categorias = array(
    'moradia' => array('label' => 'Moradia (rateada)', 'icon' => '🏠', 'cor' => '#052228', 'campo' => 'moradia_rateada_cents'),
    'alimentacao' => array('label' => 'Alimentação', 'icon' => '🍽️', 'cor' => '#B87333', 'campo' => 'alimentacao_cents'),
    'saude' => array('label' => 'Saúde', 'icon' => '🏥', 'cor' => '#059669', 'campo' => 'saude_cents'),
    'educacao' => array('label' => 'Educação', 'icon' => '📚', 'cor' => '#6366f1', 'campo' => 'educacao_cents'),
    'transporte' => array('label' => 'Transporte', 'icon' => '🚗', 'cor' => '#0ea5e9', 'campo' => 'transporte_cents'),
    'vestuario' => array('label' => 'Vestuário', 'icon' => '👕', 'cor' => '#d97706', 'campo' => 'vestuario_cents'),
    'lazer' => array('label' => 'Lazer e Cultura', 'icon' => '🎮', 'cor' => '#8b5cf6', 'campo' => 'lazer_cents'),
    'tecnologia' => array('label' => 'Tecnologia', 'icon' => '💻', 'cor' => '#06b6d4', 'campo' => 'tecnologia_cents'),
    'cuidados' => array('label' => 'Cuidados Pessoais', 'icon' => '🧴', 'cor' => '#ec4899', 'campo' => 'cuidados_cents'),
    'outros' => array('label' => 'Outros', 'icon' => '📋', 'cor' => '#6b7280', 'campo' => 'outros_cents'),
);

// Mapeamento de nomes de campos do stored → labels legíveis
$storedLabels = array(
    'agua'=>'Água','internet'=>'Internet','telefone'=>'Telefone','tv'=>'TV/Streaming',
    'manutencao'=>'Manutenção','aluguel'=>'Aluguel','condominio'=>'Condomínio',
    'luz'=>'Luz/Energia','gas'=>'Gás','iptu'=>'IPTU',
    'supermercado'=>'Supermercado','feira'=>'Feira/Hortifruti','carnes'=>'Açougue/Carnes',
    'padaria'=>'Padaria','lanche_escolar'=>'Lanche escolar','lanche'=>'Lanche',
    'refeicoes_fora'=>'Refeições fora','leite_formula'=>'Leite/Fórmula','leite'=>'Leite/Fórmula',
    'agua_mineral'=>'Água mineral','suplementos'=>'Suplementos',
    'plano_saude'=>'Plano de saúde','plano'=>'Plano de saúde','consultas'=>'Consultas',
    'odontologia'=>'Odontologia','oculos'=>'Óculos/Lentes','medicamentos'=>'Medicamentos',
    'terapia'=>'Terapia/Psicólogo','fisioterapia'=>'Fisioterapia','fonoaudiologia'=>'Fonoaudiologia',
    'escola'=>'Escola/Mensalidade','material_escolar'=>'Material escolar','uniforme'=>'Uniforme',
    'cursos'=>'Cursos/Atividades','natacao'=>'Natação','ballet'=>'Ballet','futebol'=>'Futebol',
    'musica'=>'Aula de música','reforco'=>'Reforço escolar','ingles'=>'Inglês',
    'transporte_escolar'=>'Transporte escolar','uber'=>'Uber/App','combustivel'=>'Combustível',
    'onibus'=>'Ônibus/Passagens','estacionamento'=>'Estacionamento',
    'roupas'=>'Roupas','calcados'=>'Calçados',
    'passeios'=>'Passeios','aniversarios'=>'Aniversários/Festas','brinquedos'=>'Brinquedos',
    'outros_lazer'=>'Outros lazer','cinema'=>'Cinema','parque'=>'Parques',
    'higiene'=>'Higiene pessoal','fraldas'=>'Fraldas','cabelo'=>'Corte de cabelo',
    'celular'=>'Celular','tablet'=>'Tablet','jogos'=>'Jogos/Apps',
    // Novo formulário (despesas_mensais) — prefixo por categoria
    'moradia_aluguel'=>'Aluguel','moradia_condominio'=>'Condomínio','moradia_iptu'=>'IPTU',
    'moradia_agua'=>'Água','moradia_luz'=>'Luz/Energia','moradia_gas'=>'Gás',
    'moradia_internet'=>'Internet','moradia_telefone'=>'Telefone','moradia_tv'=>'TV/Streaming',
    'moradia_manutencao'=>'Manutenção',
    'alim_supermercado'=>'Supermercado','alim_feira'=>'Feira/Hortifruti','alim_carnes'=>'Açougue/Carnes',
    'alim_padaria'=>'Padaria','alim_lanche'=>'Lanche escolar','alim_refeicoes'=>'Refeições fora',
    'alim_leite'=>'Leite/Fórmula','alim_agua'=>'Água mineral','alim_especial'=>'Alimentação especial',
    'alim_suplementos'=>'Suplementos','alim_outros'=>'Outros alimentação',
    'saude_plano'=>'Plano de saúde','saude_copart'=>'Coparticipação','saude_medicamentos'=>'Medicamentos',
    'saude_consultas'=>'Consultas','saude_exames'=>'Exames','saude_odonto'=>'Odontologia',
    'saude_psico'=>'Psicólogo','saude_fono'=>'Fonoaudiologia','saude_terapias'=>'Terapias',
    'saude_fisio'=>'Fisioterapia','saude_oculos'=>'Óculos/Lentes','saude_vacinas'=>'Vacinas',
    'saude_outros'=>'Outros saúde',
    'edu_mensalidade'=>'Mensalidade escolar','edu_matricula'=>'Matrícula','edu_transp'=>'Transporte escolar',
    'edu_material'=>'Material escolar','edu_uniforme'=>'Uniforme','edu_livros'=>'Livros/Apostilas',
    'edu_reforco'=>'Reforço escolar','edu_idiomas'=>'Idiomas/Cursos','edu_passeios'=>'Passeios escola',
    'edu_outros'=>'Outros educação',
    'transp_publico'=>'Transporte público','transp_uber'=>'Uber/App','transp_combustivel'=>'Combustível',
    'transp_manutencao'=>'Manutenção veículo','transp_seguro'=>'Seguro','transp_ipva'=>'IPVA',
    'transp_estacionamento'=>'Estacionamento','transp_outros'=>'Outros transporte',
    'vest_roupas'=>'Roupas','vest_calcados'=>'Calçados','vest_higiene'=>'Higiene pessoal',
    'vest_fraldas'=>'Fraldas','vest_cabelo'=>'Corte de cabelo','vest_derma'=>'Itens dermatológicos',
    'vest_outros'=>'Outros vestuário',
    'lazer_esportes'=>'Esportes','lazer_atividades'=>'Atividades','lazer_passeios'=>'Passeios',
    'lazer_aniversarios'=>'Aniversários','lazer_brinquedos'=>'Brinquedos','lazer_streaming'=>'Streaming',
    'lazer_outros'=>'Outros lazer',
    'tech_celular'=>'Celular','tech_aparelho'=>'Aparelho','tech_notebook'=>'Notebook/Tablet',
    'tech_apps'=>'Apps educacionais','tech_internet'=>'Internet estudo','tech_outros'=>'Outros tecnologia',
    'care_baba'=>'Babá','care_cuidador'=>'Cuidador','care_acompanhante'=>'Acompanhante',
    'care_diarista'=>'Diarista','care_outros'=>'Outros cuidados',
    'outros_gerais'=>'Outros gerais','contribuicao_atual'=>'Contribuição atual genitor',
);

// Agrupar campos do stored por categoria
$storedPorCategoria = array(
    'moradia' => array('agua','internet','telefone','tv','manutencao','aluguel','condominio','luz','gas','iptu',
        'moradia_aluguel','moradia_condominio','moradia_iptu','moradia_agua','moradia_luz','moradia_gas','moradia_internet','moradia_telefone','moradia_tv','moradia_manutencao'),
    'alimentacao' => array('supermercado','feira','carnes','padaria','lanche_escolar','lanche','refeicoes_fora','leite_formula','leite','agua_mineral','suplementos',
        'alim_supermercado','alim_feira','alim_carnes','alim_padaria','alim_lanche','alim_refeicoes','alim_leite','alim_agua','alim_especial','alim_suplementos','alim_outros'),
    'saude' => array('plano_saude','plano','consultas','odontologia','oculos','medicamentos','terapia','fisioterapia','fonoaudiologia',
        'saude_plano','saude_copart','saude_medicamentos','saude_consultas','saude_exames','saude_odonto','saude_psico','saude_fono','saude_terapias','saude_fisio','saude_oculos','saude_vacinas','saude_outros'),
    'educacao' => array('escola','material_escolar','uniforme','cursos','natacao','ballet','futebol','musica','reforco','ingles',
        'edu_mensalidade','edu_matricula','edu_transp','edu_material','edu_uniforme','edu_livros','edu_reforco','edu_idiomas','edu_passeios','edu_outros'),
    'transporte' => array('transporte_escolar','uber','combustivel','onibus','estacionamento',
        'transp_publico','transp_uber','transp_combustivel','transp_manutencao','transp_seguro','transp_ipva','transp_estacionamento','transp_outros'),
    'vestuario' => array('roupas','calcados',
        'vest_roupas','vest_calcados','vest_higiene','vest_fraldas','vest_cabelo','vest_derma','vest_outros'),
    'lazer' => array('passeios','aniversarios','brinquedos','outros_lazer','cinema','parque',
        'lazer_esportes','lazer_atividades','lazer_passeios','lazer_aniversarios','lazer_brinquedos','lazer_streaming','lazer_outros'),
    'cuidados' => array('higiene','fraldas','cabelo',
        'care_baba','care_cuidador','care_acompanhante','care_diarista','care_outros'),
    'tecnologia' => array('celular','tablet','jogos',
        'tech_celular','tech_aparelho','tech_notebook','tech_apps','tech_internet','tech_outros'),
);

$totalGeral = isset($totais['total_geral_cents']) ? (int)$totais['total_geral_cents'] : 0;
$gastosData = array();
$totalCalculado = 0;
foreach ($categorias as $key => $cat) {
    // Tentar campo no totais, fallback para nível raiz com prefixo total_
    $valor = 0;
    if (isset($totais[$cat['campo']])) {
        $valor = (int)$totais[$cat['campo']];
    } elseif (isset($payload['total_' . $cat['campo']])) {
        $valor = (int)$payload['total_' . $cat['campo']];
    }
    $gastosData[$key] = $valor;
    $totalCalculado += $valor;
}
if ($totalGeral === 0) $totalGeral = $totalCalculado;

function fmt($cents) { return 'R$ ' . number_format($cents / 100, 2, ',', '.'); }
function pct($parte, $total) { return $total > 0 ? round(($parte / $total) * 100, 1) : 0; }

$logoUrl = url('assets/img/logo.png');

// DEBUG: ver estrutura real dos dados (visível no código-fonte)
$debugStoredKeys = !empty($stored) ? implode(', ', array_keys($stored)) : 'VAZIO';
$debugTotaisKeys = is_array($totais) ? implode(', ', array_keys($totais)) : 'VAZIO';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Relatório de Gastos — <?= htmlspecialchars($nomeFilho, ENT_QUOTES, 'UTF-8') ?></title>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:Calibri,'Segoe UI',Arial,sans-serif; color:#1A1A1A; background:#e5e7eb; font-size:12pt; }

.toolbar { background:#052228; color:#fff; padding:.5rem 1.5rem; display:flex; align-items:center; justify-content:space-between; gap:.5rem; position:sticky; top:0; z-index:100; flex-wrap:wrap; }
.toolbar a, .toolbar button { color:#fff; background:rgba(255,255,255,.15); border:none; padding:.4rem .8rem; border-radius:8px; cursor:pointer; font-family:inherit; font-size:.78rem; font-weight:600; text-decoration:none; display:inline-flex; align-items:center; gap:.3rem; }
.toolbar a:hover, .toolbar button:hover { background:rgba(255,255,255,.25); }
.toolbar .btn-pdf { background:#dc2626; }
.toolbar .btn-word { background:#2b579a; }

.page { max-width:210mm; margin:1.5rem auto; background:#fff; padding:40px 50px; box-shadow:0 4px 20px rgba(0,0,0,.12); line-height:1.6; }

.header { text-align:center; margin-bottom:24px; padding-bottom:16px; border-bottom:3px solid #B87333; }
.header img { max-width:300px; height:auto; margin-bottom:8px; }
.header h1 { font-size:16pt; color:#052228; margin:8px 0 4px; letter-spacing:1px; }
.header .sub { font-size:10pt; color:#666; }

.info-grid { display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-bottom:24px; padding:16px; background:#f8f9fa; border-radius:8px; border-left:4px solid #052228; }
.info-item { font-size:10pt; }
.info-item .lbl { font-weight:700; color:#052228; font-size:9pt; text-transform:uppercase; letter-spacing:.5px; }

.section-title { font-size:13pt; font-weight:700; color:#052228; text-transform:uppercase; letter-spacing:1px; margin:28px 0 12px; padding:8px 0 6px; border-bottom:2px solid #B87333; }

/* Tabela de despesas */
.gastos-table { width:100%; border-collapse:collapse; margin:12px 0 24px; font-size:11pt; }
.gastos-table th { background:#052228; color:#fff; padding:10px 14px; text-align:left; font-size:10pt; font-weight:700; letter-spacing:.5px; text-transform:uppercase; }
.gastos-table th:last-child { text-align:right; }
.gastos-table td { padding:10px 14px; border-bottom:1px solid #e5e7eb; }
.gastos-table td:last-child { text-align:right; font-weight:600; }
.gastos-table tr:nth-child(even) td { background:#f8f9fa; }
.gastos-table tr:hover td { background:rgba(215,171,144,.1); }
.gastos-table .total-row td { background:#052228 !important; color:#fff; font-weight:800; font-size:12pt; border:none; }
.gastos-table .bar { height:8px; background:#e5e7eb; border-radius:4px; overflow:hidden; min-width:60px; }
.gastos-table .bar-fill { height:100%; border-radius:4px; }
.gastos-table .pct { font-size:9pt; color:#666; font-weight:400; }
.gastos-table .icon { font-size:14pt; }

/* Gráfico */
.chart-section { display:grid; grid-template-columns:1fr 1fr; gap:24px; margin:16px 0 24px; align-items:center; }
.chart-wrap { max-width:300px; margin:0 auto; }
.chart-legend { font-size:10pt; }
.chart-legend-item { display:flex; align-items:center; gap:8px; margin:4px 0; padding:4px 8px; border-radius:6px; }
.chart-legend-item:hover { background:#f8f9fa; }
.chart-legend-dot { width:12px; height:12px; border-radius:3px; flex-shrink:0; }
.chart-legend-value { margin-left:auto; font-weight:700; color:#052228; }

/* Resumo */
.resumo-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:12px; margin:16px 0; }
.resumo-card { background:#f8f9fa; border-radius:10px; padding:16px; text-align:center; border:1px solid #e5e7eb; }
.resumo-card .valor { font-size:18pt; font-weight:800; color:#052228; }
.resumo-card .label { font-size:9pt; color:#666; text-transform:uppercase; letter-spacing:.5px; margin-top:4px; }

.footer { text-align:center; margin-top:32px; padding-top:12px; border-top:1px solid #B87333; font-size:9pt; color:#888; }
.footer .cidades { margin-bottom:2px; font-weight:600; }

.nota-legal { background:#fef3c7; border:1px solid #fcd34d; border-radius:8px; padding:12px 16px; font-size:9pt; color:#92400e; margin-top:20px; }

@media print {
    body { background:#fff; }
    .toolbar { display:none !important; }
    .page { box-shadow:none; margin:0; padding:30px 40px; }
    .gastos-table tr:hover td { background:inherit; }
    @page { size:A4; margin:1cm; }
}
</style>
</head>
<body>

<div class="toolbar">
    <div style="display:flex;gap:.5rem;align-items:center;">
        <a href="<?= module_url('formularios', 'ver.php?id=' . $id) ?>">← Voltar</a>
        <span style="font-size:.78rem;opacity:.7;">Relatório de Gastos — <?= htmlspecialchars($nomeFilho, ENT_QUOTES, 'UTF-8') ?></span>
    </div>
    <div style="display:flex;gap:.5rem;">
        <button onclick="copiarRelatorio()" style="background:#059669;">📋 Copiar</button>
        <button onclick="window.print()" class="btn-pdf">📕 PDF / Imprimir</button>
    </div>
</div>

<div class="page" id="relatorio">
    <!-- Header com timbrado -->
    <div class="header">
        <img src="<?= $logoUrl ?>" alt="Ferreira &amp; Sá" onerror="this.outerHTML='<h2 style=color:#052228>FERREIRA &amp; SÁ ADVOCACIA</h2>'">
        <h1>LEVANTAMENTO DE DESPESAS MENSAIS ESSENCIAIS</h1>
        <div class="sub">
            Ref.: <?= htmlspecialchars($nomeFilho, ENT_QUOTES, 'UTF-8') ?>
            &nbsp;|&nbsp; Protocolo: <?= htmlspecialchars($protocolo, ENT_QUOTES, 'UTF-8') ?>
            &nbsp;|&nbsp; Data: <?= $dataForm ?>
        </div>
    </div>

    <!-- Dados do responsável -->
    <div class="info-grid">
        <div class="info-item"><div class="lbl">Responsável</div><?= htmlspecialchars($nomeResp, ENT_QUOTES, 'UTF-8') ?></div>
        <div class="info-item"><div class="lbl">CPF</div><?= htmlspecialchars($cpfResp, ENT_QUOTES, 'UTF-8') ?></div>
        <div class="info-item"><div class="lbl">Filho(a) referente</div><?= htmlspecialchars($nomeFilho, ENT_QUOTES, 'UTF-8') ?></div>
        <div class="info-item"><div class="lbl">WhatsApp</div><?= htmlspecialchars($whatsapp, ENT_QUOTES, 'UTF-8') ?></div>
        <div class="info-item"><div class="lbl">Nº de filhos</div><?= htmlspecialchars($qtdFilhos, ENT_QUOTES, 'UTF-8') ?></div>
        <div class="info-item"><div class="lbl">Moradores na residência</div><?= htmlspecialchars($moradores, ENT_QUOTES, 'UTF-8') ?></div>
        <?php if ($teaStatus && $teaStatus !== '—' && strtolower($teaStatus) !== 'nao'): ?>
        <div class="info-item"><div class="lbl">TEA (Autismo)</div><?= htmlspecialchars($teaStatus, ENT_QUOTES, 'UTF-8') ?><?= $detalheTrat ? ' — ' . htmlspecialchars($detalheTrat, ENT_QUOTES, 'UTF-8') : '' ?></div>
        <?php endif; ?>
        <div class="info-item"><div class="lbl">Fonte de renda</div><?= htmlspecialchars($fonteRenda, ENT_QUOTES, 'UTF-8') ?></div>
    </div>

    <!-- Resumo rápido -->
    <div class="resumo-grid">
        <div class="resumo-card">
            <div class="valor"><?= fmt($totalGeral) ?></div>
            <div class="label">Total mensal</div>
        </div>
        <div class="resumo-card">
            <div class="valor"><?= $rendaMensal > 0 ? fmt($rendaMensal) : '—' ?></div>
            <div class="label">Renda do responsável</div>
        </div>
        <div class="resumo-card">
            <div class="valor"><?= $rendaObrigado > 0 ? fmt($rendaObrigado) : '—' ?></div>
            <div class="label">Renda do obrigado</div>
        </div>
    </div>

    <!-- Tabela de despesas -->
    <div class="section-title">Despesas Mensais por Categoria</div>
    <table class="gastos-table">
        <thead>
            <tr>
                <th></th>
                <th>Categoria</th>
                <th>Proporção</th>
                <th>Valor Mensal</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($categorias as $key => $cat):
                $valor = $gastosData[$key];
                if ($valor === 0) continue;
                $percentual = pct($valor, $totalGeral);
            ?>
            <tr>
                <td class="icon"><?= $cat['icon'] ?></td>
                <td><strong><?= $cat['label'] ?></strong></td>
                <td>
                    <div class="bar"><div class="bar-fill" style="width:<?= $percentual ?>%;background:<?= $cat['cor'] ?>;"></div></div>
                    <span class="pct"><?= $percentual ?>%</span>
                </td>
                <td><?= fmt($valor) ?></td>
            </tr>
            <?php
            // Detalhamento das subcategorias do stored
            if (isset($storedPorCategoria[$key]) && !empty($stored)):
                $camposExibidos = array();
                foreach ($storedPorCategoria[$key] as $subCampo):
                    $subValor = 0;
                    // Tentar nome direto, com _cents, e variações
                    foreach (array($subCampo, $subCampo . '_cents') as $tentativa) {
                        if (isset($stored[$tentativa]) && (int)$stored[$tentativa] > 0) {
                            $subValor = (int)$stored[$tentativa];
                            $camposExibidos[] = $tentativa;
                            break;
                        }
                    }
                    if ($subValor === 0) continue;
                    $subLabel = isset($storedLabels[$subCampo]) ? $storedLabels[$subCampo] : ucfirst(str_replace('_', ' ', $subCampo));
                ?>
                <tr style="background:rgba(0,0,0,.02);">
                    <td></td>
                    <td style="padding-left:32px;font-size:10pt;color:#666;">↳ <?= $subLabel ?></td>
                    <td></td>
                    <td style="font-size:10pt;color:#666;"><?= fmt($subValor) ?></td>
                </tr>
                <?php endforeach;

                // Observações da categoria (campos obs_xxx no stored)
                $obsKeys = array('obs_moradia','obs_alimentacao','obs_saude','obs_educacao','obs_transporte','obs_vestuario','obs_lazer','obs_tecnologia','obs_cuidados','obs_outros','obs_' . $key);
                foreach ($obsKeys as $obsKey) {
                    $obsVal = isset($stored[$obsKey]) ? trim($stored[$obsKey]) : '';
                    if ($obsVal && strpos($obsKey, $key) !== false) {
                        echo '<tr style="background:rgba(0,0,0,.02);"><td></td><td colspan="2" style="padding-left:32px;font-size:9pt;color:#92400e;font-style:italic;">📝 ' . htmlspecialchars($obsVal, ENT_QUOTES, 'UTF-8') . '</td><td></td></tr>';
                    }
                }
            endif;
            ?>
            <?php endforeach; ?>
            <?php if ($moradiaTotal > 0 && $moradiaRateada > 0 && $moradiaTotal !== $moradiaRateada): ?>
            <tr style="background:#fef3c7;">
                <td></td>
                <td colspan="2" style="font-size:9pt;color:#92400e;"><strong>Nota:</strong> Moradia total <?= fmt($moradiaTotal) ?> ÷ <?= $numMoradores ?> moradores = <?= fmt($moradiaRateada) ?> (rateio)</td>
                <td></td>
            </tr>
            <?php endif; ?>
            <tr class="total-row">
                <td></td>
                <td>TOTAL MENSAL APROXIMADO</td>
                <td></td>
                <td><?= fmt($totalGeral) ?></td>
            </tr>
        </tbody>
    </table>

    <!-- Gráfico + Legenda -->
    <div class="section-title">Distribuição dos Gastos</div>
    <div class="chart-section">
        <div class="chart-wrap">
            <canvas id="graficoPizza" width="280" height="280"></canvas>
        </div>
        <div class="chart-legend">
            <?php foreach ($categorias as $key => $cat):
                $valor = $gastosData[$key];
                if ($valor === 0) continue;
            ?>
            <div class="chart-legend-item">
                <div class="chart-legend-dot" style="background:<?= $cat['cor'] ?>;"></div>
                <span><?= $cat['icon'] ?> <?= $cat['label'] ?></span>
                <span class="chart-legend-value"><?= pct($valor, $totalGeral) ?>%</span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- DEBUG (remover depois): -->
    <!-- STORED KEYS: <?= $debugStoredKeys ?> -->
    <!-- TOTAIS KEYS: <?= $debugTotaisKeys ?> -->

    <!-- Nota legal -->
    <div class="nota-legal">
        <strong>Nota:</strong> Estimativa elaborada com base nas necessidades ordinárias informadas pelo(a) responsável, nos termos do art. 1.694, §1º, do Código Civil c/c art. 22 do ECA (se aplicável). Os valores de moradia foram rateados proporcionalmente pelo número de moradores da residência. Valores sujeitos a atualização no curso da instrução processual.
        <br><strong>Fonte:</strong> Ferreira &amp; Sá Advocacia Especializada — OAB-RJ 163.260
    </div>

    <!-- Rodapé -->
    <div class="footer">
        <div class="cidades">Rio de Janeiro / RJ &nbsp;&nbsp; Barra Mansa / RJ &nbsp;&nbsp; Volta Redonda / RJ &nbsp;&nbsp; Resende / RJ &nbsp;&nbsp; São Paulo / SP</div>
        <div>(24) 9.9205.0096 / (11) 2110-5438</div>
        <div>www.ferreiraesa.com.br &nbsp;&nbsp; contato@ferreiraesa.com.br</div>
    </div>
</div>

<script>
// Gráfico de pizza
var ctx = document.getElementById('graficoPizza').getContext('2d');
<?php
$labels = array();
$values = array();
$colors = array();
foreach ($categorias as $key => $cat) {
    if ($gastosData[$key] === 0) continue;
    $labels[] = $cat['label'];
    $values[] = $gastosData[$key] / 100;
    $colors[] = $cat['cor'];
}
?>
new Chart(ctx, {
    type: 'doughnut',
    data: {
        labels: <?= json_encode($labels) ?>,
        datasets: [{
            data: <?= json_encode($values) ?>,
            backgroundColor: <?= json_encode($colors) ?>,
            borderWidth: 2,
            borderColor: '#fff',
            hoverOffset: 8
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { display: false },
            tooltip: {
                callbacks: {
                    label: function(ctx) {
                        var total = ctx.dataset.data.reduce(function(a,b){return a+b;},0);
                        var pct = ((ctx.raw/total)*100).toFixed(1);
                        return ctx.label + ': R$ ' + ctx.raw.toFixed(2).replace('.',',') + ' (' + pct + '%)';
                    }
                }
            }
        },
        cutout: '55%',
        animation: { animateRotate: true, duration: 800 }
    }
});

function copiarRelatorio() {
    var el = document.getElementById('relatorio');
    var range = document.createRange();
    range.selectNodeContents(el);
    var sel = window.getSelection();
    sel.removeAllRanges();
    sel.addRange(range);
    document.execCommand('copy');
    sel.removeAllRanges();
    var btn = document.querySelector('[onclick*="copiarRelatorio"]');
    var orig = btn.innerHTML;
    btn.innerHTML = '✓ Copiado!';
    btn.style.background = '#065f46';
    setTimeout(function(){ btn.innerHTML = orig; btn.style.background = '#059669'; }, 2000);
}
</script>

</body>
</html>
