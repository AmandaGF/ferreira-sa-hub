<?php
/**
 * Exportador de Casos com seleção de campos + filtros pelo usuário.
 *
 * Acesso: /modules/relatorios/exportar_casos_custom.php
 *   - GET sem params: mostra UI com checkboxes (campos) + filtros
 *   - GET com 'gerar=1': gera o CSV com os campos selecionados
 *
 * Pedido pela Amanda 27/05/2026: 'add, mas deixe que o usuário escolha o
 * que ele quer ver no relatório'.
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
require_access('relatorios');

$pdo = db();

// ─── Catálogo de campos disponíveis ───────────────────────────
$camposCatalogo = array(
    // grupo => array('label' => 'X', 'campos' => [...])
    'Identificação' => array(
        'title'             => 'Título da pasta',
        'case_type'         => 'Tipo de ação',
        'case_number'       => 'CNJ',
        'case_number_fmt'   => 'CNJ formatado',
        'court'             => 'Vara / Tribunal',
        'comarca'           => 'Comarca',
        'comarca_uf'        => 'UF',
        'sistema_tribunal'  => 'Sistema (PJE/eproc/etc)',
        'segredo_justica'   => 'Segredo de justiça (sim/não)',
    ),
    'Status & datas' => array(
        'status'            => 'Status (label)',
        'priority'          => 'Prioridade',
        'opened_at'         => 'Data de abertura',
        'distribution_date' => 'Data de distribuição',
        'closed_at'         => 'Data de fechamento',
        'deadline'          => 'Próximo prazo',
        'ultimo_andamento'  => 'Data do último andamento',
        'proxima_audiencia' => 'Próxima audiência (data + tipo)',
        'created_at'        => 'Cadastrado em',
        'updated_at'        => 'Atualizado em',
    ),
    'Cliente principal' => array(
        'cliente_nome'      => 'Cliente — nome',
        'cliente_cpf'       => 'Cliente — CPF/CNPJ',
        'cliente_telefone'  => 'Cliente — telefone',
        'cliente_email'     => 'Cliente — e-mail',
    ),
    'Equipe' => array(
        'responsavel'       => 'Responsável',
        'setor'             => 'Setor / dept.',
        'partes_adversas'   => 'Partes adversas (réus)',
    ),
    'Métricas' => array(
        'tarefas_pendentes' => 'Qtd. tarefas pendentes',
        'tarefas_feitas'    => 'Qtd. tarefas feitas',
        'docs_pendentes'    => 'Qtd. documentos pendentes',
        'qtd_andamentos'    => 'Qtd. andamentos',
    ),
    'Outros' => array(
        'drive_folder_url'  => 'Pasta Drive (URL)',
        'has_drive'         => 'Tem pasta Drive (sim/não)',
        'salavip_ativo'     => 'Visível na Central VIP',
        'acompanhamento_externo' => 'Acompanhamento externo',
    ),
);

// Defaults (caso usuário não escolha nada): mantém compatibilidade com o
// export antigo (tipo=casos) — Título / Tipo / CNJ / Vara / Status / etc.
$camposDefault = array('title','case_type','case_number','court','status','priority','opened_at','closed_at','cliente_nome','cliente_telefone','responsavel','tarefas_pendentes','tarefas_feitas','drive_folder_url');

// Status para filtro multiselect
$statusOpts = array(
    'aguardando_docs'   => 'Aguardando Docs',
    'em_elaboracao'     => 'Pasta Apta',
    'em_andamento'      => 'Em Execução',
    'doc_faltante'      => 'Doc Faltante',
    'aguardando_prazo'  => 'Aguardando Distribuição',
    'distribuido'       => 'Processo Distribuído',
    'concluido'         => 'Concluído',
    'arquivado'         => 'Arquivado',
);

$priorityOpts = array('urgente'=>'Urgente','alta'=>'Alta','normal'=>'Normal','baixa'=>'Baixa');

$users = array();
try { $users = $pdo->query("SELECT id, name FROM users WHERE is_active = 1 ORDER BY name")->fetchAll(); } catch (Throwable $e) {}

$tiposAcao = array();
try { $tiposAcao = $pdo->query("SELECT DISTINCT case_type FROM cases WHERE case_type IS NOT NULL AND case_type != '' ORDER BY case_type")->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) {}

// ─── Helper formatar CNJ ──────────────────────────────────────
function _expCnjFmt($num) {
    $d = preg_replace('/\D/', '', (string)$num);
    if (strlen($d) !== 20) return $num ?: '';
    return substr($d,0,7).'-'.substr($d,7,2).'.'.substr($d,9,4).'.'.substr($d,13,1).'.'.substr($d,14,2).'.'.substr($d,16,4);
}
function _expDt($d) { if (!$d || $d === '0000-00-00' || strpos((string)$d,'0000-')===0) return ''; $ts=strtotime($d); return $ts ? date('d/m/Y', $ts) : (string)$d; }
function _expDtH($d) { if (!$d || strpos((string)$d,'0000-')===0) return ''; $ts=strtotime($d); return $ts ? date('d/m/Y H:i', $ts) : (string)$d; }

// ─── GERAR CSV (se gerar=1) ───────────────────────────────────
if (!empty($_GET['gerar'])) {
    $camposSel = isset($_GET['campos']) ? array_filter(explode(',', $_GET['campos'])) : $camposDefault;
    $statusSel = isset($_GET['status']) && is_array($_GET['status']) ? $_GET['status'] : array();
    $userSel   = (int)($_GET['responsavel'] ?? 0);
    $tipoSel   = trim($_GET['tipo_acao'] ?? '');
    $deSel     = trim($_GET['de'] ?? '');
    $ateSel    = trim($_GET['ate'] ?? '');
    $semArqSel = !empty($_GET['sem_arquivados']);

    // valida campos
    $todosValidos = array();
    foreach ($camposCatalogo as $_g) foreach ($_g as $k => $_lbl) $todosValidos[$k] = $_lbl;
    $camposSel = array_values(array_filter($camposSel, function($c) use ($todosValidos) { return isset($todosValidos[$c]); }));
    if (!$camposSel) $camposSel = $camposDefault;

    $where = array('1=1');
    $params = array();
    if ($semArqSel) $where[] = "cs.status != 'arquivado'";
    if ($statusSel) {
        $ph = implode(',', array_fill(0, count($statusSel), '?'));
        $where[] = "cs.status IN ($ph)";
        foreach ($statusSel as $s) $params[] = $s;
    }
    if ($userSel) { $where[] = "cs.responsible_user_id = ?"; $params[] = $userSel; }
    if ($tipoSel) { $where[] = "cs.case_type = ?"; $params[] = $tipoSel; }
    if ($deSel  && preg_match('/^\d{4}-\d{2}-\d{2}$/', $deSel))  { $where[] = "DATE(cs.created_at) >= ?"; $params[] = $deSel;  }
    if ($ateSel && preg_match('/^\d{4}-\d{2}-\d{2}$/', $ateSel)) { $where[] = "DATE(cs.created_at) <= ?"; $params[] = $ateSel; }
    $whereStr = implode(' AND ', $where);

    $sql = "SELECT cs.*,
                   c.name AS cliente_nome_q, c.cpf AS cliente_cpf_q, c.phone AS cliente_phone_q, c.email AS cliente_email_q,
                   u.name AS responsavel_q, u.setor AS responsavel_setor_q,
                   (SELECT COUNT(*) FROM case_tasks ct WHERE ct.case_id = cs.id AND ct.status NOT IN ('concluido','feito')) AS tarefas_pendentes_q,
                   (SELECT COUNT(*) FROM case_tasks ct WHERE ct.case_id = cs.id AND ct.status IN ('concluido','feito'))    AS tarefas_feitas_q,
                   (SELECT COUNT(*) FROM documentos_pendentes dp WHERE dp.case_id = cs.id AND dp.status = 'pendente')      AS docs_pendentes_q,
                   (SELECT COUNT(*) FROM case_andamentos ca WHERE ca.case_id = cs.id)                                      AS qtd_andamentos_q,
                   (SELECT MAX(data_andamento) FROM case_andamentos ca WHERE ca.case_id = cs.id)                           AS ultimo_andamento_q,
                   (SELECT CONCAT(IFNULL(DATE_FORMAT(ae.data_inicio,'%d/%m/%Y %H:%i'),'—'), ' · ', IFNULL(ae.titulo,''))
                      FROM agenda_eventos ae WHERE ae.case_id = cs.id AND ae.tipo='audiencia' AND ae.status='agendado'
                      AND ae.data_inicio >= NOW() ORDER BY ae.data_inicio ASC LIMIT 1)                                      AS proxima_audiencia_q,
                   (SELECT GROUP_CONCAT(DISTINCT cp.nome SEPARATOR '; ')
                      FROM case_partes cp WHERE cp.case_id = cs.id AND cp.papel IN ('reu','litisconsorte_passivo'))         AS partes_adversas_q
             FROM cases cs
             LEFT JOIN clients c ON c.id = cs.client_id
             LEFT JOIN users u ON u.id = cs.responsible_user_id
             WHERE $whereStr
             ORDER BY cs.created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Headers HTTP
    while (ob_get_level() > 0) @ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="casos_' . date('Y-m-d_H-i') . '.csv"');
    $fp = fopen('php://output', 'w');
    fwrite($fp, "\xEF\xBB\xBF"); // BOM UTF-8 (Excel)

    // Cabeçalho
    $hdr = array();
    foreach ($camposSel as $k) if (isset($todosValidos[$k])) $hdr[] = $todosValidos[$k];
    fputcsv($fp, $hdr, ';');

    foreach ($rows as $r) {
        $linha = array();
        foreach ($camposSel as $k) {
            $linha[] = _exp_valor_campo($k, $r, $statusOpts, $priorityOpts);
        }
        fputcsv($fp, $linha, ';');
    }
    fclose($fp);

    try { audit_log('relatorio_casos_custom', 'cases', 0, count($rows) . ' linhas, campos=[' . implode(',', $camposSel) . ']'); } catch (Throwable $e) {}
    exit;
}

// Helper: extrai o valor de cada campo conforme a chave
function _exp_valor_campo($k, $r, $statusOpts, $priorityOpts) {
    switch ($k) {
        case 'title':              return $r['title'] ?? '';
        case 'case_type':          return $r['case_type'] ?? '';
        case 'case_number':        return $r['case_number'] ?? '';
        case 'case_number_fmt':    return _expCnjFmt($r['case_number'] ?? '');
        case 'court':              return $r['court'] ?? '';
        case 'comarca':            return $r['comarca'] ?? '';
        case 'comarca_uf':         return $r['comarca_uf'] ?? '';
        case 'sistema_tribunal':   return $r['sistema_tribunal'] ?? '';
        case 'segredo_justica':    return !empty($r['segredo_justica']) ? 'Sim' : 'Não';
        case 'status':             return $statusOpts[$r['status']] ?? ($r['status'] ?? '');
        case 'priority':           return $priorityOpts[$r['priority']] ?? ($r['priority'] ?? '');
        case 'opened_at':          return _expDt($r['opened_at'] ?? '');
        case 'distribution_date':  return _expDt($r['distribution_date'] ?? '');
        case 'closed_at':          return _expDt($r['closed_at'] ?? '');
        case 'deadline':           return _expDt($r['deadline'] ?? '');
        case 'created_at':         return _expDt($r['created_at'] ?? '');
        case 'updated_at':         return _expDt($r['updated_at'] ?? '');
        case 'ultimo_andamento':   return _expDt($r['ultimo_andamento_q'] ?? '');
        case 'proxima_audiencia':  return $r['proxima_audiencia_q'] ?? '';
        case 'cliente_nome':       return $r['cliente_nome_q'] ?? '';
        case 'cliente_cpf':        return $r['cliente_cpf_q'] ?? '';
        case 'cliente_telefone':   return $r['cliente_phone_q'] ?? '';
        case 'cliente_email':      return $r['cliente_email_q'] ?? '';
        case 'responsavel':        return $r['responsavel_q'] ?? '';
        case 'setor':              return $r['responsavel_setor_q'] ?? '';
        case 'partes_adversas':    return $r['partes_adversas_q'] ?? '';
        case 'tarefas_pendentes':  return (int)($r['tarefas_pendentes_q'] ?? 0);
        case 'tarefas_feitas':     return (int)($r['tarefas_feitas_q'] ?? 0);
        case 'docs_pendentes':     return (int)($r['docs_pendentes_q'] ?? 0);
        case 'qtd_andamentos':     return (int)($r['qtd_andamentos_q'] ?? 0);
        case 'drive_folder_url':   return $r['drive_folder_url'] ?? '';
        case 'has_drive':          return !empty($r['drive_folder_url']) ? 'Sim' : 'Não';
        case 'salavip_ativo':      return !empty($r['salavip_ativo']) ? 'Sim' : 'Não';
        case 'acompanhamento_externo': return !empty($r['acompanhamento_externo']) ? 'Sim' : 'Não';
    }
    return '';
}

$pageTitle = 'Exportar Casos — Relatório Customizado';
require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.ec-wrap { max-width:1100px; margin:0 auto; padding:.5rem 1rem; }
.ec-grupo { background:#fff; border:1px solid #e5e7eb; border-radius:10px; margin-bottom:1rem; overflow:hidden; }
.ec-grupo h3 { background:#f9fafb; margin:0; padding:.6rem 1rem; font-size:.85rem; color:#052228; border-bottom:1px solid #e5e7eb; display:flex; justify-content:space-between; align-items:center; }
.ec-grupo .ec-acoes { display:flex; gap:.3rem; }
.ec-grupo .ec-acoes button { background:#fff; border:1px solid #e5e7eb; padding:.15rem .55rem; font-size:.68rem; border-radius:5px; cursor:pointer; color:#6b7280; }
.ec-grupo .ec-acoes button:hover { background:#f3f4f6; }
.ec-grupo .ec-body { padding:.7rem 1rem; display:grid; grid-template-columns:repeat(auto-fill, minmax(220px, 1fr)); gap:.3rem .8rem; }
.ec-chk { display:flex; align-items:center; gap:.4rem; font-size:.82rem; color:#1f2937; cursor:pointer; padding:.15rem 0; }
.ec-chk input { width:16px; height:16px; }
.ec-filtros { display:grid; grid-template-columns:repeat(auto-fit, minmax(180px,1fr)); gap:.7rem; padding:.85rem 1rem; }
.ec-filtros label { font-size:.7rem; color:#6b7280; display:block; margin-bottom:.2rem; font-weight:600; text-transform:uppercase; letter-spacing:.4px; }
.ec-filtros select, .ec-filtros input[type="date"] { width:100%; font-size:.85rem; padding:.4rem .55rem; border:1px solid #d1d5db; border-radius:6px; background:#fff; }
.ec-status-multi { display:flex; flex-wrap:wrap; gap:.3rem; }
.ec-status-multi label { display:inline-flex; align-items:center; gap:.3rem; background:#f9fafb; border:1px solid #e5e7eb; padding:.25rem .6rem; border-radius:99px; font-size:.75rem; cursor:pointer; }
.ec-status-multi label.on { background:#dcfce7; border-color:#86efac; color:#166534; }
.ec-actions { display:flex; gap:.5rem; align-items:center; padding:1rem 0; flex-wrap:wrap; }
.ec-btn-export { background:#0e7490; color:#fff; padding:.6rem 1.2rem; border:none; border-radius:8px; font-weight:700; cursor:pointer; font-size:.9rem; }
.ec-btn-export:hover { background:#155e75; }
.ec-resumo { font-size:.8rem; color:#6b7280; }
</style>

<div class="ec-wrap">
    <div style="margin-bottom:1rem;">
        <a href="<?= module_url('relatorios') ?>" style="font-size:.8rem;color:#6b7280;text-decoration:none;">← Voltar aos Relatórios</a>
        <h2 style="margin:.4rem 0;font-size:1.3rem;color:#052228;">📊 Exportar Casos — Customizado</h2>
        <p style="color:#6b7280;font-size:.85rem;margin:0;">Escolha quais campos quer no CSV e filtre por status, responsável, tipo ou período. Inclui arquivados por padrão.</p>
    </div>

    <form method="GET" id="formExp" action="<?= module_url('relatorios', 'exportar_casos_custom.php') ?>">
        <input type="hidden" name="gerar" value="1">
        <input type="hidden" name="campos" id="hCampos" value="">

        <div class="ec-grupo">
            <h3>🔍 Filtros</h3>
            <div class="ec-filtros">
                <div>
                    <label>Status (selecione 1 ou mais)</label>
                    <div class="ec-status-multi" id="ecStatus">
                        <?php foreach ($statusOpts as $k => $lb): ?>
                            <label data-k="<?= e($k) ?>"><input type="checkbox" name="status[]" value="<?= e($k) ?>" onchange="this.closest('label').classList.toggle('on', this.checked)"> <?= e($lb) ?></label>
                        <?php endforeach; ?>
                    </div>
                    <div style="margin-top:.4rem;font-size:.7rem;color:#9ca3af;">Vazio = todos os status</div>
                </div>
                <div>
                    <label>Responsável</label>
                    <select name="responsavel">
                        <option value="0">— Todos —</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= (int)$u['id'] ?>"><?= e($u['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Tipo de ação</label>
                    <select name="tipo_acao">
                        <option value="">— Todos —</option>
                        <?php foreach ($tiposAcao as $t): ?>
                            <option value="<?= e($t) ?>"><?= e($t) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Cadastrado entre</label>
                    <div style="display:flex;gap:.3rem;">
                        <input type="date" name="de">
                        <input type="date" name="ate">
                    </div>
                </div>
            </div>
        </div>

        <?php foreach ($camposCatalogo as $grupoLabel => $campos):
            $grupoId = preg_replace('/[^a-z0-9]/i', '', $grupoLabel);
        ?>
        <div class="ec-grupo">
            <h3>
                <span><?= e($grupoLabel) ?></span>
                <div class="ec-acoes">
                    <button type="button" onclick="ecMarcarGrupo('<?= $grupoId ?>', true)">Todos</button>
                    <button type="button" onclick="ecMarcarGrupo('<?= $grupoId ?>', false)">Nenhum</button>
                </div>
            </h3>
            <div class="ec-body" data-grupo="<?= $grupoId ?>">
                <?php foreach ($campos as $k => $lb):
                    $checked = in_array($k, $camposDefault, true);
                ?>
                <label class="ec-chk"><input type="checkbox" data-campo="<?= e($k) ?>" <?= $checked ? 'checked' : '' ?>> <?= e($lb) ?></label>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <div class="ec-actions">
            <button type="submit" class="ec-btn-export" onclick="ecCompilarCampos()">📥 Baixar CSV</button>
            <span class="ec-resumo" id="ecResumo"></span>
            <span style="margin-left:auto;">
                <button type="button" onclick="ecPreset('basico')" style="background:#fff;border:1px solid #d1d5db;padding:.35rem .7rem;border-radius:6px;cursor:pointer;font-size:.75rem;">Preset básico</button>
                <button type="button" onclick="ecPreset('completo')" style="background:#fff;border:1px solid #d1d5db;padding:.35rem .7rem;border-radius:6px;cursor:pointer;font-size:.75rem;">Preset completo</button>
                <button type="button" onclick="ecPreset('zerar')" style="background:#fef2f2;border:1px solid #fca5a5;color:#b91c1c;padding:.35rem .7rem;border-radius:6px;cursor:pointer;font-size:.75rem;">Zerar tudo</button>
            </span>
        </div>
    </form>
</div>

<script>
function ecMarcarGrupo(gid, val) {
    document.querySelectorAll('[data-grupo="' + gid + '"] input[type="checkbox"]').forEach(function(c){ c.checked = val; });
    ecAtualizarResumo();
}
function ecCampos() {
    var arr = [];
    document.querySelectorAll('input[data-campo]').forEach(function(c){ if (c.checked) arr.push(c.getAttribute('data-campo')); });
    return arr;
}
function ecAtualizarResumo() {
    var n = ecCampos().length;
    var el = document.getElementById('ecResumo');
    if (el) el.textContent = n + ' campo(s) selecionado(s)';
}
function ecCompilarCampos() {
    document.getElementById('hCampos').value = ecCampos().join(',');
}
function ecPreset(p) {
    document.querySelectorAll('input[data-campo]').forEach(function(c){ c.checked = false; });
    var presets = {
        basico:    ['title','case_type','case_number_fmt','status','cliente_nome','responsavel'],
        completo:  ['title','case_type','case_number_fmt','court','comarca','status','priority','opened_at','closed_at','ultimo_andamento','proxima_audiencia','cliente_nome','cliente_cpf','cliente_telefone','responsavel','partes_adversas','tarefas_pendentes','docs_pendentes','qtd_andamentos','drive_folder_url'],
        zerar:     []
    };
    (presets[p] || []).forEach(function(k){
        var c = document.querySelector('input[data-campo="' + k + '"]');
        if (c) c.checked = true;
    });
    ecAtualizarResumo();
}
document.querySelectorAll('input[data-campo]').forEach(function(c){
    c.addEventListener('change', ecAtualizarResumo);
});
ecAtualizarResumo();
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
