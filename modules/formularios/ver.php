<?php
/**
 * Ferreira & Sá Hub — Ver Formulário (melhorado)
 */

require_once __DIR__ . '/../../core/middleware.php';
require_access('formularios');

$pdo = db();
$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT fs.*, u.name as assigned_name, c.name as linked_client_name
     FROM form_submissions fs
     LEFT JOIN users u ON u.id = fs.assigned_to
     LEFT JOIN clients c ON c.id = fs.linked_client_id
     WHERE fs.id = ?'
);
$stmt->execute(array($id));
$form = $stmt->fetch();

if (!$form) { flash_set('error', 'Formulário não encontrado.'); redirect(module_url('formularios')); }

$pageTitle = 'Formulário ' . $form['protocol'];
$payload = json_decode($form['payload_json'], true);
if (!is_array($payload)) $payload = array();

// Labels dos tipos
$typeLabels = array(
    'convivencia' => 'Regulamentação de Convivência',
    'gastos_pensao' => 'Levantamento de Gastos — Pensão Alimentícia',
    'cadastro_cliente' => 'Cadastro de Cliente',
    'calculadora_lead' => 'Lead da Calculadora',
    'divorcio' => 'Divórcio',
    'alimentos' => 'Alimentos',
    'responsabilidade_civil' => 'Responsabilidade Civil',
);

// ═══════════════════════════════════════════════════════
// Mapeamento de nomes dos campos para português
// ═══════════════════════════════════════════════════════
$fieldLabels = array(
    // Convivência — Identificação
    'client_name' => 'Nome do cliente',
    'client_phone' => 'Telefone / WhatsApp',
    'client_email' => 'E-mail',
    'relationship_role' => 'Relação com a criança (Mãe/Pai/Responsável)',
    'children' => 'Filhos',

    // Convivência — Visitas
    'pickup_frequency' => 'Frequência de convivência',
    'pickup_frequency_other' => 'Outra forma de convivência (detalhe)',
    'weekend_model' => 'Modelo de fim de semana',
    'weekend_sunday_time' => 'Horário do domingo',
    'wk_sat_pick_time' => 'Horário de busca no sábado',
    'wk_sun_drop_time' => 'Horário de entrega no domingo',
    'wk_sat_pick_time_2' => 'Horário de busca no sábado (modelo 2)',
    'overnight' => 'Pernoite (dormir na casa)',
    'overnight_quick_reason' => 'Motivo rápido de não pernoitar',
    'overnight_reason' => 'Motivo detalhado de não pernoitar',

    // Convivência — Início/Retorno
    'convivio_inicio' => 'Início da convivência',
    'convivio_retorno' => 'Retorno da convivência',
    'exchange_place' => 'Local de troca (busca/entrega)',

    // Convivência — Datas especiais
    'bday_child' => 'Aniversário da criança',
    'bday_child_other' => 'Aniversário da criança (outro — detalhe)',
    'bday_mom' => 'Dia das Mães',
    'bday_mom_other' => 'Dia das Mães (outro — detalhe)',
    'bday_dad' => 'Dia dos Pais',
    'bday_dad_other' => 'Dia dos Pais (outro — detalhe)',
    'holidays' => 'Feriados',
    'holidays_other' => 'Feriados (outro — detalhe)',

    // Convivência — Férias
    'vac_mid' => 'Férias de meio de ano',
    'vac_mid_other' => 'Férias meio de ano (outro — detalhe)',
    'vac_end' => 'Férias de fim de ano',
    'vac_end_other' => 'Férias fim de ano (outro — detalhe)',

    // Convivência — Natal/Ano Novo
    'xmas' => 'Natal',
    'xmas_other' => 'Natal (outro — detalhe)',
    'newyear' => 'Ano Novo',
    'newyear_other' => 'Ano Novo (outro — detalhe)',

    // Convivência — Observações
    'open_notes' => 'Observações adicionais do cliente',

    // Gastos Pensão
    'nome_responsavel' => 'Nome do responsável',
    'cpf_responsavel' => 'CPF do responsável',
    'whatsapp' => 'WhatsApp',
    'nome_filho_referente' => 'Nome do filho (referência)',
    'tea_status' => 'Criança com TEA?',
    'faz_tratamento_especifico' => 'Faz tratamento específico?',
    'detalhe_tratamento' => 'Detalhe do tratamento',
    'qtd_filhos' => 'Quantidade de filhos',
    'gastos_iguais_todos' => 'Gastos iguais para todos os filhos?',
    'fonte_renda' => 'Fonte de renda',
    'obs_renda' => 'Observações sobre renda',
    'renda_mensal_cents' => 'Renda mensal (centavos)',
    'quem_paga' => 'Quem paga',
    'renda_obrigado_cents' => 'Renda do obrigado (centavos)',
    'moradores' => 'Moradores da residência',
    'total_moradia_rateada_cents' => 'Total Moradia (rateado)',
    'total_alimentacao_cents' => 'Total Alimentação',
    'total_saude_cents' => 'Total Saúde',
    'total_educacao_cents' => 'Total Educação',
    'total_transporte_cents' => 'Total Transporte',
    'total_vestuario_cents' => 'Total Vestuário',
    'total_lazer_cents' => 'Total Lazer',
    'total_tecnologia_cents' => 'Total Tecnologia',
    'total_cuidados_cents' => 'Total Cuidados',
    'total_outros_cents' => 'Total Outros',
    'total_geral_cents' => 'TOTAL GERAL',
    'protocolo' => 'Protocolo',
    'status_peticao' => 'Status da petição',

    // Cadastro Cliente
    'nome' => 'Nome completo',
    'cpf' => 'CPF',
    'nascimento' => 'Data de nascimento',
    'profissao' => 'Profissão',
    'estado_civil' => 'Estado civil',
    'rg' => 'RG',
    'celular' => 'Celular (WhatsApp)',
    'email' => 'E-mail',
    'cep' => 'CEP',
    'endereco' => 'Endereço completo',
    'pix' => 'Chave PIX',
    'conta_bancaria' => 'Conta para depósito',
    'imposto_renda' => 'Declara Imposto de Renda?',
    'clt' => 'Carteira assinada?',
    'filhos' => 'Possui filhos?',
    'nome_filhos' => 'Nome(s) dos filhos',
    'tipo_atendimento' => 'Preferência de atendimento',
    'autoriza_contato' => 'Autoriza contato?',
    'fam_saude' => 'Tratamento de saúde?',
    'fam_escola' => 'Escola pública ou particular?',
    'fam_pensao_atual' => 'Paga pensão? Qual valor?',
    'fam_trabalho_genitor' => 'Outro genitor trabalha?',
    'fam_contato_genitor' => 'WhatsApp do outro genitor',
    'fam_endereco_genitor' => 'Endereço do outro genitor',

    // Leads Calculadora
    'porcentagem' => 'Porcentagem calculada',
    'situacao' => 'Situação',
    'ano_referencia' => 'Ano de referência',
    'idade_filhos' => 'Idade dos filhos',
    'atendido' => 'Já foi atendido?',
    'data_envio' => 'Data de envio',

    // Gastos Pensão — campos extras da tabela MySQL
    'id' => 'ID do registro',
    'protocolo' => 'Protocolo',
    'created_at' => 'Data de criação',
    'ip' => 'IP',
    'user_agent' => 'Navegador',
    'nome_responsavel' => 'Nome do responsável',
    'cpf_responsavel' => 'CPF do responsável',
    'whatsapp' => 'WhatsApp',
    'nome_filho_referente' => 'Nome do filho (referência)',
    'tea_status' => 'Criança com TEA (autismo)?',
    'faz_tratamento_especifico' => 'Faz tratamento específico?',
    'detalhe_tratamento' => 'Detalhe do tratamento',
    'qtd_filhos' => 'Quantidade de filhos',
    'gastos_iguais_todos' => 'Gastos iguais para todos os filhos?',
    'fonte_renda' => 'Fonte de renda',
    'obs_renda' => 'Observações sobre renda',
    'renda_mensal_cents' => 'Renda mensal',
    'quem_paga' => 'Quem paga as despesas',
    'renda_obrigado_cents' => 'Renda do obrigado a pagar',
    'moradores' => 'Nº de moradores da residência',
    'total_moradia_rateada_cents' => 'Total Moradia (rateado)',
    'total_alimentacao_cents' => 'Total Alimentação',
    'total_saude_cents' => 'Total Saúde',
    'total_educacao_cents' => 'Total Educação',
    'total_transporte_cents' => 'Total Transporte',
    'total_vestuario_cents' => 'Total Vestuário',
    'total_lazer_cents' => 'Total Lazer',
    'total_tecnologia_cents' => 'Total Tecnologia',
    'total_cuidados_cents' => 'Total Cuidados',
    'total_outros_cents' => 'Total Outros gastos',
    'total_geral_cents' => 'TOTAL GERAL DE GASTOS',
    'status_peticao' => 'Status da petição',
    'payload_json' => 'Dados completos (JSON)',

    // Cadastro Cliente — campos extras
    'conta_bancaria' => 'Conta para depósito',
    'pix' => 'Chave PIX',
    'tipo_atendimento' => 'Preferência de atendimento',
    'autoriza_contato' => 'Autoriza contato?',
    'fam_saude' => 'Filho faz tratamento de saúde?',
    'fam_escola' => 'Escola pública ou particular?',
    'fam_pensao_atual' => 'Outro genitor paga pensão? Valor?',
    'fam_trabalho_genitor' => 'Outro genitor trabalha? Empresa/Cargo?',
    'fam_contato_genitor' => 'WhatsApp do outro genitor',
    'fam_endereco_genitor' => 'Endereço do outro genitor',
);

// Valores legíveis para campos com códigos
$valueLabels = array(
    'quinzenal_fds' => 'Fins de semana quinzenais',
    'todo_fds' => 'Todos os fins de semana',
    'somente_semana' => 'Somente durante a semana',
    'videochamadas' => 'Apenas videochamadas',
    'sex_aula_seg_escola' => 'Sexta após aula → Segunda na escola',
    'sex_aula_dom' => 'Sexta após aula → Domingo (horário definido)',
    'sab_dom' => 'Sábado de manhã → Domingo à noite',
    'sab_seg_escola' => 'Sábado de manhã → Segunda antes da escola',
    'alternado' => 'Alternado entre os genitores',
    'sempre_comigo' => 'Sempre comigo',
    'sempre_outro' => 'Sempre com o outro genitor',
    'parte_do_dia' => 'Parte do dia com cada um',
    'com_a_mae' => 'Com a mãe',
    'com_o_pai' => 'Com o pai',
    'indiferente' => 'Indiferente',
    'livre' => 'Livre combinação',
    'dividido' => 'Dividido entre os dois',
    'pares_mae_impares_pai' => 'Anos pares com a mãe, ímpares com o pai',
    'pares_pai_impares_mae' => 'Anos pares com o pai, ímpares com a mãe',
    'com_pai' => 'Com o pai',
    'com_mae' => 'Com a mãe',
    'AGUARDANDO_INICIO_OPERACIONAL' => 'Aguardando início operacional',
    'AGUARDANDO_DOCUMENTOS' => 'Aguardando documentos',
    'EM_ELABORACAO' => 'Em elaboração',
    'DISTRIBUIDA' => 'Distribuída',
);

$statusFormLabels = array('novo' => 'Novo', 'em_analise' => 'Em análise', 'processado' => 'Processado', 'arquivado' => 'Arquivado');
$users = $pdo->query("SELECT id, name FROM users WHERE is_active = 1 ORDER BY name")->fetchAll();
$clients = $pdo->query("SELECT id, name FROM clients ORDER BY name LIMIT 200")->fetchAll();

function getLabel($key, $labels) {
    if (isset($labels[$key])) return $labels[$key];
    // Transformar snake_case em texto legível
    $text = str_replace(array('_', '-'), ' ', $key);
    return ucfirst($text);
}

function getValue($val, $valueLabels, $key = '') {
    if (is_bool($val)) return $val ? 'Sim' : 'Não';
    if ($val === true || $val === 'true') return 'Sim';
    if ($val === false || $val === 'false') return 'Não';
    if (is_array($val)) {
        // Caso especial: filhos (array de objetos com name, dob, age)
        if ($key === 'children') {
            $parts = array();
            foreach ($val as $i => $child) {
                if (is_array($child)) {
                    $nome = isset($child['name']) ? $child['name'] : '?';
                    $nasc = isset($child['dob']) ? $child['dob'] : '';
                    $idade = isset($child['age']) ? $child['age'] : '';
                    $line = ($i + 1) . '. ' . $nome;
                    if ($nasc) $line .= ' — Nasc: ' . $nasc;
                    if ($idade) $line .= ' (' . $idade . ')';
                    $parts[] = $line;
                } else {
                    $parts[] = (string)$child;
                }
            }
            return implode("\n", $parts);
        }
        $parts = array();
        foreach ($val as $k => $v) {
            if (is_array($v)) {
                $parts[] = json_encode($v, JSON_UNESCAPED_UNICODE);
            } else {
                $parts[] = is_numeric($k) ? getValue($v, $valueLabels) : getLabel($k, array()) . ': ' . getValue($v, $valueLabels);
            }
        }
        return implode("\n", $parts);
    }
    if ($val === 'sim' || $val === 'yes' || $val === true || $val === 1) return 'Sim';
    if ($val === 'nao' || $val === 'no' || $val === false || $val === 0) return 'Não';
    if (is_string($val) && isset($valueLabels[$val])) return $valueLabels[$val];
    if (is_numeric($val) && $val === 0) return '0';
    return (string)$val;
}

function isCentsField($key) {
    return strpos($key, '_cents') !== false;
}

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.form-detail-grid { display:grid; grid-template-columns:1fr; gap:0; }
.field-row { display:grid; grid-template-columns:250px 1fr; border-bottom:1px solid var(--border); }
.field-row:last-child { border-bottom:none; }
.field-label { padding:.6rem 1rem; background:var(--bg); font-size:.78rem; font-weight:700; color:var(--petrol-900); text-transform:uppercase; letter-spacing:.3px; display:flex; align-items:center; }
.field-value { padding:.6rem 1rem; font-size:.88rem; color:var(--text); white-space:pre-wrap; word-break:break-word; display:flex; align-items:center; }
.field-value.empty { color:var(--text-muted); font-style:italic; }
.field-value.money { font-weight:700; color:var(--success); }
.field-value.total { font-weight:800; font-size:1rem; color:var(--petrol-900); background:var(--rose-light); }

@media print {
    .sidebar, .topbar, .btn-sidebar-toggle, .sidebar-overlay, .no-print { display:none !important; }
    .main-content { margin-left:0 !important; }
    .card { box-shadow:none !important; border:1px solid #ddd; }
    .page-content { padding:0 !important; }
    .field-row { break-inside:avoid; }
    .print-header { display:block !important; text-align:center; margin-bottom:1.5rem; }
    .print-header h1 { font-size:1.2rem; color:#052228; }
    .print-header p { font-size:.82rem; color:#666; }
}

@media (max-width:768px) {
    .field-row { grid-template-columns:1fr; }
    .field-label { padding:.4rem .75rem .1rem; font-size:.7rem; }
    .field-value { padding:.1rem .75rem .5rem; }
}
</style>

<!-- Header de impressão (visível só no print) -->
<div class="print-header" style="display:none;">
    <h1>Ferreira &amp; Sá Advocacia</h1>
    <p><?= isset($typeLabels[$form['form_type']]) ? $typeLabels[$form['form_type']] : e($form['form_type']) ?> — Protocolo: <?= e($form['protocol']) ?></p>
    <p>Data: <?= data_hora_br($form['created_at']) ?></p>
</div>

<div class="no-print">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.5rem;margin-bottom:1rem;">
        <a href="<?= module_url('formularios', '?type=' . urlencode($form['form_type'])) ?>" class="btn btn-outline btn-sm">← Voltar</a>
        <div class="flex gap-1">
            <button onclick="window.print()" class="btn btn-outline btn-sm">🖨️ Imprimir</button>
            <?php if ($form['form_type'] === 'gastos_pensao' || $form['form_type'] === 'despesas_mensais'): ?>
                <a href="<?= module_url('formularios', 'relatorio_gastos.php?id=' . $form['id']) ?>" class="btn btn-primary btn-sm" style="background:#B87333;">📊 Relatório Visual</a>
            <?php endif; ?>
            <?php if ($form['client_phone']): ?>
                <a href="https://wa.me/55<?= preg_replace('/\D/', '', $form['client_phone']) ?>" target="_blank" class="btn btn-success btn-sm">💬 WhatsApp</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Info do formulário -->
<div class="card mb-2 no-print">
    <div class="card-header">
        <div>
            <h3><?= e($form['protocol']) ?></h3>
            <span class="text-sm text-muted">
                <?= isset($typeLabels[$form['form_type']]) ? $typeLabels[$form['form_type']] : e($form['form_type']) ?>
                · <?= data_hora_br($form['created_at']) ?>
            </span>
        </div>
        <span class="badge badge-<?= array('novo'=>'warning','em_analise'=>'info','processado'=>'success','arquivado'=>'gestao')[$form['status']] ?? 'gestao' ?>">
            <?= $statusFormLabels[$form['status']] ?? $form['status'] ?>
        </span>
    </div>
    <div class="card-body">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
            <!-- Status + Responsável -->
            <form method="POST" action="<?= module_url('formularios', 'api.php') ?>" style="display:flex;gap:.5rem;flex-wrap:wrap;">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="form_id" value="<?= $form['id'] ?>">
                <select name="status" class="form-select" style="flex:1;font-size:.82rem;">
                    <?php foreach ($statusFormLabels as $k => $v): ?>
                        <option value="<?= $k ?>" <?= $form['status'] === $k ? 'selected' : '' ?>><?= $v ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="assigned_to" class="form-select" style="flex:1;font-size:.82rem;">
                    <option value="">Sem responsável</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?= $u['id'] ?>" <?= (int)$form['assigned_to'] === (int)$u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-primary btn-sm">Salvar</button>
            </form>

            <!-- Vincular cliente -->
            <?php if (!$form['linked_client_id']): ?>
                <form method="POST" action="<?= module_url('formularios', 'api.php') ?>" style="display:flex;gap:.5rem;">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="create_client_from_form">
                    <input type="hidden" name="form_id" value="<?= $form['id'] ?>">
                    <button type="submit" class="btn btn-success btn-sm">+ Criar cliente com estes dados</button>
                </form>
            <?php else: ?>
                <span class="text-sm">Vinculado a: <a href="<?= module_url('crm', 'cliente_ver.php?id=' . $form['linked_client_id']) ?>" class="font-bold"><?= e($form['linked_client_name']) ?></a></span>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Respostas do formulário — TODOS os campos com nomes claros -->
<div class="card">
    <div class="card-header">
        <h3>Respostas</h3>
    </div>
    <div class="form-detail-grid">
        <?php
        // Campos fixos primeiro
        $fixedFields = array(
            'client_name' => $form['client_name'],
            'client_phone' => $form['client_phone'],
            'client_email' => $form['client_email'],
        );

        foreach ($fixedFields as $key => $val): ?>
            <div class="field-row">
                <div class="field-label"><?= getLabel($key, $fieldLabels) ?></div>
                <div class="field-value <?= empty($val) ? 'empty' : '' ?>"><?= empty($val) ? '(não preenchido)' : e($val) ?></div>
            </div>
        <?php endforeach; ?>

        <?php
        // Campos do JSON ignorando os que já mostramos
        $skipKeys = array('id', 'created_at', 'updated_at', 'ip', 'ip_address', 'user_agent', 'data_envio', 'payload_json', 'seconds', 'nanoseconds', 'client_name', 'client_phone', 'client_email', 'form_type', 'protocol_original', 'protocol', 'protocolo');
        // Flatten: se tem 'totais' aninhado, extrair os sub-campos
        $flatPayload = array();
        foreach ($payload as $key => $val) {
            if ($key === 'totais' && is_array($val)) {
                foreach ($val as $sk => $sv) { $flatPayload['total_' . str_replace('_cents', '', $sk) . '_cents'] = $sv; }
            } else {
                $flatPayload[$key] = $val;
            }
        }

        foreach ($flatPayload as $key => $val):
            if (in_array($key, $skipKeys)) continue;
            $label = getLabel($key, $fieldLabels);
            $displayVal = getValue($val, $valueLabels, $key);
            $isEmpty = ($displayVal === '' || $displayVal === null);
            $isMoney = isCentsField($key) || strpos($key, 'renda') !== false;
            $isTotal = ($key === 'total_geral_cents');
        ?>
            <div class="field-row">
                <div class="field-label"><?= e($label) ?></div>
                <div class="field-value <?= $isEmpty ? 'empty' : '' ?> <?= $isMoney ? 'money' : '' ?> <?= $isTotal ? 'total' : '' ?>">
                    <?php if ($isEmpty): ?>
                        (não preenchido)
                    <?php elseif ($isMoney && is_numeric($displayVal)): ?>
                        R$ <?= number_format((int)$displayVal / 100, 2, ',', '.') ?>
                    <?php else: ?>
                        <?= nl2br(e($displayVal)) ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Notas -->
<div class="card mt-2 no-print">
    <div class="card-header"><h3>Notas internas</h3></div>
    <div class="card-body">
        <form method="POST" action="<?= module_url('formularios', 'api.php') ?>">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="update_notes">
            <input type="hidden" name="form_id" value="<?= $form['id'] ?>">
            <textarea name="notes" class="form-textarea" rows="3" placeholder="Anotações sobre este formulário..."><?= e($form['notes'] ?? '') ?></textarea>
            <button type="submit" class="btn btn-outline btn-sm mt-1">Salvar notas</button>
        </form>
    </div>
</div>

<!-- Apagar -->
<div class="card mt-2 no-print" style="border-color:var(--danger);">
    <div class="card-body" style="display:flex;justify-content:space-between;align-items:center;">
        <span class="text-sm text-muted">Apagar este formulário permanentemente</span>
        <form method="POST" action="<?= module_url('formularios', 'api.php') ?>">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="form_id" value="<?= $form['id'] ?>">
            <input type="hidden" name="redirect_type" value="<?= e($form['form_type']) ?>">
            <button type="submit" class="btn btn-danger btn-sm" data-confirm="Tem certeza que deseja APAGAR este formulário? Esta ação não pode ser desfeita.">🗑️ Apagar</button>
        </form>
    </div>
</div>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
