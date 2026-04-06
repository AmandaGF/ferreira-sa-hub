<?php
/**
 * Ferreira & Sá Hub — Importar Publicações DJen
 * Cole o texto copiado do portal DJen — o sistema identifica e vincula automaticamente
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
if (!has_min_role('operacional') && !has_min_role('gestao')) { flash_set('error', 'Sem permissao.'); redirect(url('modules/dashboard/')); }

$pdo = db();
$userId = current_user_id();

// Buscar usuarios ativos
$usuarios = array();
try {
    $usuarios = $pdo->query("SELECT id, name FROM users WHERE is_active = 1 ORDER BY name")->fetchAll();
} catch (Exception $e) {}

// ── Parser do texto bruto do DJen ──
function parsear_djen($texto) {
    $publicacoes = array();
    $blocos = preg_split('/(?=Processo\s+\d{7}-\d{2}\.\d{4}\.\d{1,2}\.\d{2}\.\d{4})/u', $texto, -1, PREG_SPLIT_NO_EMPTY);
    foreach ($blocos as $bloco) {
        $bloco = trim($bloco);
        if (!$bloco) continue;
        $pub = array(
            'numero_processo'  => '',
            'orgao'            => '',
            'data_disp'        => date('Y-m-d'),
            'tipo_comunicacao' => 'intimacao',
            'meio'             => 'DJEN',
            'partes'           => array(),
            'advogados'        => array(),
            'conteudo'         => '',
            'segredo'          => false,
            'comarca'          => '',
        );
        // Numero do processo
        if (preg_match('/Processo\s+(\d{7}-\d{2}\.\d{4}\.\d{1,2}\.\d{2}\.\d{4})/u', $bloco, $m)) {
            $pub['numero_processo'] = $m[1];
        }
        if (!$pub['numero_processo']) continue;

        // Orgao
        if (preg_match('/(?:Org[aã]o|Orgao)\s*[:\-]?\s*(.+?)(?:\n|Data)/ui', $bloco, $m)) {
            $pub['orgao'] = trim($m[1]);
        }
        // Data de disponibilizacao
        if (preg_match('/Data de disponibiliza[cç][aã]o\s*[:\-]?\s*(\d{2}\/\d{2}\/\d{4})/ui', $bloco, $m)) {
            $partes_data = explode('/', $m[1]);
            if (count($partes_data) === 3) {
                $pub['data_disp'] = $partes_data[2] . '-' . $partes_data[1] . '-' . $partes_data[0];
            }
        }
        // Tipo de comunicacao
        if (preg_match('/Tipo de comunica[cç][aã]o\s*[:\-]?\s*(.+?)(?:\n)/ui', $bloco, $m)) {
            $tipo = strtolower(trim($m[1]));
            if (strpos($tipo, 'intima') !== false) $pub['tipo_comunicacao'] = 'intimacao';
            elseif (strpos($tipo, 'cita') !== false) $pub['tipo_comunicacao'] = 'citacao';
            elseif (strpos($tipo, 'edital') !== false) $pub['tipo_comunicacao'] = 'edital';
            else $pub['tipo_comunicacao'] = 'outro';
        }
        // Segredo de justica
        if (stripos($bloco, 'SEGREDO DE JUSTI') !== false) {
            $pub['segredo'] = true;
        }
        // Partes
        if (preg_match('/Parte\(s\)(.*?)Advogado\(s\)/us', $bloco, $m)) {
            $linhas = array_filter(array_map('trim', explode("\n", trim($m[1]))));
            foreach ($linhas as $l) {
                $l = preg_replace('/^[\*\-\x{2022}]\s*/u', '', $l);
                if ($l && stripos($l, 'SEGREDO') === false) {
                    $pub['partes'][] = $l;
                }
            }
        }
        // Advogados
        if (preg_match('/Advogado\(s\)(.*?)(?:Poder Judici|$)/us', $bloco, $m)) {
            $linhas = array_filter(array_map('trim', explode("\n", trim($m[1]))));
            foreach ($linhas as $l) {
                $l = preg_replace('/^[\*\-\x{2022}]\s*/u', '', $l);
                if ($l && preg_match('/OAB/i', $l)) {
                    $pub['advogados'][] = $l;
                }
            }
        }
        // Conteudo completo
        $pub['conteudo'] = $bloco;

        // Comarca extraida do orgao
        if (preg_match('/Comarca\s+de\s+([^,\n]+)/ui', $pub['orgao'], $m)) {
            $pub['comarca'] = trim($m[1]);
        }

        $publicacoes[] = $pub;
    }
    return $publicacoes;
}

// Sugestao de prazo por tipo
function prazo_sugerido_djen($tipo) {
    $prazos = array(
        'intimacao' => 15, 'citacao' => 15, 'decisao' => 15,
        'sentenca' => 15, 'despacho' => 5, 'acordao' => 15,
        'edital' => 20, 'outro' => 0,
    );
    return isset($prazos[$tipo]) ? $prazos[$tipo] : 0;
}

// Calcular data fim em dias uteis
function calcular_data_fim_djen($dataInicio, $dias, $pdo) {
    if (!$dias) return null;
    // Usar funcao do sistema se disponivel
    if (function_exists('calcular_prazo_completo')) {
        $res = calcular_prazo_completo($dataInicio, $dias, 'dias', null);
        return isset($res['data_fatal']) ? $res['data_fatal'] : null;
    }
    // Fallback simples
    try {
        $atual = new DateTime($dataInicio);
        $atual->modify('+1 day');
        $cont = 0;
        while ($cont < $dias) {
            $dow = (int)$atual->format('N');
            if ($dow < 6) { $cont++; }
            if ($cont < $dias) { $atual->modify('+1 day'); }
        }
        return $atual->format('Y-m-d');
    } catch (Exception $e) { return null; }
}

// ── POST: processar ──
$resultado = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // Etapa 1: parsear texto
    if ($_POST['action'] === 'parsear') {
        $texto = $_POST['texto_djen'] ?? '';
        $publicacoes = parsear_djen($texto);
        $parsed = array();
        foreach ($publicacoes as $pub) {
            $numLimpo = preg_replace('/\D/', '', $pub['numero_processo']);
            $stmtCase = $pdo->prepare(
                "SELECT cs.id, cs.title, cs.comarca, cs.case_type, cs.responsible_user_id,
                        c.name as client_name, c.id as client_id
                 FROM cases cs
                 LEFT JOIN clients c ON c.id = cs.client_id
                 WHERE REPLACE(REPLACE(REPLACE(cs.case_number,'-',''),'.',''),'/','') = ?
                 AND cs.status NOT IN ('arquivado','cancelado')
                 LIMIT 1"
            );
            $stmtCase->execute(array($numLimpo));
            $caso = $stmtCase->fetch();
            $pub['case_id']      = $caso ? (int)$caso['id'] : null;
            $pub['case_title']   = $caso ? $caso['title'] : null;
            $pub['client_name']  = $caso ? $caso['client_name'] : null;
            $pub['client_id']    = $caso ? (int)$caso['client_id'] : null;
            $pub['responsavel']  = $caso ? (int)$caso['responsible_user_id'] : $userId;
            $pub['prazo_dias']   = prazo_sugerido_djen($pub['tipo_comunicacao']);
            $pub['data_fim']     = calcular_data_fim_djen($pub['data_disp'], $pub['prazo_dias'], $pdo);
            $parsed[] = $pub;
        }
        $resultado = $parsed;
    }

    // Etapa 2: importar selecionados
    if ($_POST['action'] === 'importar') {
        if (!validate_csrf()) { flash_set('error', 'Token invalido.'); redirect(module_url('admin', 'djen_importar.php')); exit; }
        $itens = $_POST['itens'] ?? array();
        $importados = 0;
        $erros = array();

        foreach ($itens as $idx => $item) {
            if (empty($item['_sel'])) continue; // nao selecionado

            $caseId     = (int)($item['case_id'] ?? 0);
            $dataDisp   = $item['data_disp'] ?? date('Y-m-d');
            $tipoPub    = $item['tipo_comunicacao'] ?? 'intimacao';
            $conteudo   = trim($item['conteudo'] ?? '');
            $orgao      = trim($item['orgao'] ?? '');
            $comarca    = trim($item['comarca'] ?? '');
            $prazoDias  = (int)($item['prazo_dias'] ?? 0);
            $dataFim    = !empty($item['data_fim']) ? $item['data_fim'] : null;
            $responsavel = (int)($item['responsavel'] ?? $userId);
            $numero     = trim($item['numero_processo'] ?? '');

            if (!$conteudo || !$numero) continue;

            // Criar pasta se solicitado
            if (!$caseId && !empty($item['criar_pasta'])) {
                $clientId = (int)($item['client_id_novo'] ?? 0);
                $tituloNovo = trim($item['title_novo'] ?? ('Processo ' . $numero));
                if ($clientId && $tituloNovo) {
                    try {
                        $pdo->prepare(
                            "INSERT INTO cases (client_id, title, case_number, court, comarca, status,
                             responsible_user_id, sistema_tribunal, created_at, updated_at)
                             VALUES (?,?,?,?,?,'em_andamento',?,'TJRJ',NOW(),NOW())"
                        )->execute(array($clientId, $tituloNovo, $numero, $orgao, $comarca, $responsavel));
                        $caseId = (int)$pdo->lastInsertId();
                        audit_log('CASE_CRIADO_DJEN', 'case', $caseId, 'Criado via importacao DJen: ' . $numero);
                    } catch (Exception $e) {
                        $erros[] = 'Erro ao criar pasta para ' . $numero . ': ' . $e->getMessage();
                        continue;
                    }
                }
            }

            if (!$caseId) {
                $erros[] = 'Processo ' . $numero . ' sem pasta vinculada.';
                continue;
            }

            // Verificar duplicata
            try {
                $stmtDup = $pdo->prepare(
                    "SELECT id FROM case_publicacoes WHERE case_id = ? AND data_disponibilizacao = ? AND tipo_publicacao = ? AND LEFT(conteudo, 100) = LEFT(?, 100)"
                );
                $stmtDup->execute(array($caseId, $dataDisp, $tipoPub, $conteudo));
                if ($stmtDup->fetch()) {
                    $erros[] = 'Processo ' . $numero . ' — publicacao ja importada.';
                    continue;
                }
            } catch (Exception $e) {}

            // Recalcular data_fim com feriados no backend
            if ($prazoDias > 0) {
                $dataFim = calcular_data_fim_djen($dataDisp, $prazoDias, $pdo);
            }

            // Salvar publicacao
            try {
                $pdo->prepare(
                    "INSERT INTO case_publicacoes
                     (case_id, data_disponibilizacao, conteudo, caderno, tribunal,
                      tipo_publicacao, fonte, prazo_dias, data_prazo_fim,
                      status_prazo, visivel_cliente, criado_por, created_at)
                     VALUES (?,?,?,'DJEN',?,?,'manual',?,?,'pendente',0,?,NOW())"
                )->execute(array(
                    $caseId, $dataDisp, $conteudo, $orgao, $tipoPub,
                    $prazoDias ?: null, $dataFim, $userId
                ));
                $pubId = (int)$pdo->lastInsertId();

                // Criar tarefa se tem prazo
                if ($dataFim) {
                    $stmtCase2 = $pdo->prepare("SELECT title, responsible_user_id FROM cases WHERE id = ?");
                    $stmtCase2->execute(array($caseId));
                    $casoRow = $stmtCase2->fetch();
                    $tituloCase = $casoRow ? $casoRow['title'] : 'Caso #' . $caseId;
                    $respCase = $casoRow ? (int)$casoRow['responsible_user_id'] : $responsavel;

                    $tipoLbl = array(
                        'intimacao'=>'INTIMACAO','citacao'=>'CITACAO','despacho'=>'DESPACHO',
                        'decisao'=>'DECISAO','sentenca'=>'SENTENCA','acordao'=>'ACORDAO',
                        'edital'=>'EDITAL','outro'=>'PUBLICACAO'
                    );
                    $lbl = isset($tipoLbl[$tipoPub]) ? $tipoLbl[$tipoPub] : 'PUBLICACAO';

                    $prazoAlerta = date('Y-m-d', strtotime($dataFim . ' -3 days'));

                    $pdo->prepare(
                        "INSERT INTO case_tasks
                         (case_id, title, descricao, tipo, subtipo, due_date,
                          prazo_alerta, status, prioridade, assigned_to, created_at)
                         VALUES (?,?,?,'prazo','prazo_publicacao',?,?,'a_fazer','alta',?,NOW())"
                    )->execute(array(
                        $caseId,
                        'PRAZO - ' . $lbl . ' | ' . $tituloCase,
                        'Prazo de ' . $prazoDias . 'du a partir de ' . date('d/m/Y', strtotime($dataDisp)) . '. Vence: ' . date('d/m/Y', strtotime($dataFim)),
                        $dataFim, $prazoAlerta, $responsavel
                    ));
                    $taskId = (int)$pdo->lastInsertId();

                    $pdo->prepare("UPDATE case_publicacoes SET task_id = ? WHERE id = ?")->execute(array($taskId, $pubId));

                    // Evento na agenda
                    $pdo->prepare(
                        "INSERT INTO agenda_eventos
                         (case_id, titulo, descricao, data_inicio, data_fim, dia_todo,
                          tipo, responsavel_id, created_by, created_at)
                         VALUES (?,?,?,?,?,1,'prazo',?,?,NOW())"
                    )->execute(array(
                        $caseId, 'Publicacao: ' . $lbl . ' | ' . $tituloCase,
                        mb_substr($conteudo, 0, 300, 'UTF-8'),
                        $dataDisp . ' 08:00:00', $dataDisp . ' 08:30:00',
                        $responsavel, $userId
                    ));

                    // Notificar responsavel
                    if ($respCase && $respCase !== $userId) {
                        notify($respCase, 'Novo prazo: ' . $lbl,
                            'Vence em ' . date('d/m/Y', strtotime($dataFim)) . ' - ' . $tituloCase,
                            'warning', module_url('operacional', 'caso_ver.php?id=' . $caseId), '');
                    }
                }

                audit_log('PUBLICACAO_IMPORTADA_DJEN', 'case', $caseId, 'pub_id=' . $pubId . ' processo=' . $numero);
                $importados++;

            } catch (Exception $e) {
                $erros[] = 'Erro em ' . $numero . ': ' . $e->getMessage();
            }
        }

        flash_set('success', $importados . ' publicacao(oes) importada(s).' . (!empty($erros) ? ' ' . count($erros) . ' ignorada(s).' : ''));
        if (!empty($erros)) { $_SESSION['djen_erros'] = $erros; }
        redirect(module_url('admin', 'djen_importar.php'));
        exit;
    }
}

// Buscar clientes para select
$clientes = array();
try { $clientes = $pdo->query("SELECT id, name, cpf FROM clients ORDER BY name")->fetchAll(); } catch (Exception $e) {}

$pageTitle = 'Importar Publicacoes DJen';
require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.djen-wrap { max-width:1100px; margin:0 auto; }
.djen-card { background:var(--bg-card); border:1px solid var(--border); border-radius:12px; padding:1.4rem; margin-bottom:1.2rem; }
.pub-row { border:1px solid var(--border); border-radius:10px; padding:1rem; margin-bottom:.8rem; transition:.15s; }
.pub-row.encontrado { border-left:4px solid #059669; }
.pub-row.nao-encontrado { border-left:4px solid #d97706; }
.pub-row.segredo { border-left:4px solid #6b7280; }
.pub-row .numero { font-size:.85rem; font-weight:800; color:var(--petrol-900); font-family:monospace; }
.pub-row .orgao-txt { font-size:.75rem; color:var(--text-muted); margin-top:2px; }
.pub-row .case-badge { font-size:.7rem; font-weight:700; padding:2px 8px; border-radius:4px; }
.pub-row .case-badge.ok { background:#ecfdf5; color:#059669; }
.pub-row .case-badge.warn { background:#fef3c7; color:#d97706; }
.pub-row .case-badge.seg { background:#f1f5f9; color:#6b7280; }
.pub-row .conteudo-txt { font-size:.78rem; color:#374151; white-space:pre-wrap; max-height:80px; overflow:hidden; margin-top:.5rem; line-height:1.5; }
.pub-row .conteudo-txt.expandido { max-height:none; }
.criar-pasta-form { background:#fffbeb; border:1px solid #fcd34d; border-radius:8px; padding:.8rem; margin-top:.6rem; }
.prazo-input { width:60px; font-size:.8rem; padding:3px 6px; border:1px solid var(--border); border-radius:6px; text-align:center; }
.btn-expandir { background:none; border:none; color:#3b82f6; font-size:.7rem; cursor:pointer; padding:0; font-family:inherit; }
.stat-pill { font-size:.72rem; font-weight:700; padding:3px 10px; border-radius:20px; }
.stat-pill.verde { background:#ecfdf5; color:#059669; }
.stat-pill.amarelo { background:#fef3c7; color:#d97706; }
.stat-pill.cinza { background:#f1f5f9; color:#6b7280; }
</style>

<div class="djen-wrap">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.2rem;flex-wrap:wrap;gap:.8rem;">
        <div>
            <h2 style="margin:0;font-size:1.3rem;color:var(--petrol-900);">Importar Publicacoes DJen</h2>
            <p style="margin:.2rem 0 0;font-size:.8rem;color:var(--text-muted);">Cole o texto copiado do portal DJen — o sistema identifica e vincula automaticamente</p>
        </div>
        <a href="<?= module_url('admin', 'datajud_monitor.php') ?>" class="btn btn-outline btn-sm">Monitor DataJud</a>
    </div>

    <?php if (!empty($_SESSION['djen_erros'])): ?>
    <div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:.8rem 1.2rem;margin-bottom:1rem;">
        <div style="font-size:.8rem;font-weight:700;color:#dc2626;margin-bottom:.4rem;">Avisos da importacao:</div>
        <?php foreach ($_SESSION['djen_erros'] as $err): ?>
            <div style="font-size:.75rem;color:#dc2626;"><?= e($err) ?></div>
        <?php endforeach; unset($_SESSION['djen_erros']); ?>
    </div>
    <?php endif; ?>

    <!-- Etapa 1: Colar texto -->
    <div class="djen-card">
        <h3 style="margin:0 0 .8rem;font-size:.95rem;">1. Cole o texto copiado do DJen</h3>
        <form method="POST">
            <input type="hidden" name="action" value="parsear">
            <textarea name="texto_djen" id="textoDjen" class="form-input"
                rows="10" style="width:100%;font-size:.78rem;font-family:monospace;resize:vertical;"
                placeholder="Cole aqui o texto completo copiado do portal comunica.pje.jus.br..."></textarea>
            <div style="display:flex;gap:.6rem;align-items:center;margin-top:.6rem;flex-wrap:wrap;">
                <button type="submit" class="btn btn-primary btn-sm">Identificar Publicacoes</button>
                <button type="button" onclick="document.getElementById('textoDjen').value=''" class="btn btn-outline btn-sm">Limpar</button>
                <span style="font-size:.72rem;color:var(--text-muted);">O sistema vai identificar cada processo e vincular as pastas automaticamente</span>
            </div>
        </form>
    </div>

    <?php if ($resultado !== null): ?>
    <?php
    $qtdEncontrados = count(array_filter($resultado, function($p){ return $p['case_id']; }));
    $qtdNaoEncontrados = count(array_filter($resultado, function($p){ return !$p['case_id']; }));
    $qtdSegredo = count(array_filter($resultado, function($p){ return $p['segredo']; }));
    ?>
    <div class="djen-card">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.8rem;flex-wrap:wrap;gap:.6rem;">
            <h3 style="margin:0;font-size:.95rem;">2. Revisar e Importar (<?= count($resultado) ?> publicacoes)</h3>
            <div style="display:flex;gap:.4rem;flex-wrap:wrap;">
                <span class="stat-pill verde"><?= $qtdEncontrados ?> com pasta</span>
                <span class="stat-pill amarelo"><?= $qtdNaoEncontrados ?> sem pasta</span>
                <?php if ($qtdSegredo): ?><span class="stat-pill cinza"><?= $qtdSegredo ?> segredo</span><?php endif; ?>
            </div>
        </div>

        <form method="POST">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="importar">

            <div style="display:flex;gap:.5rem;align-items:center;margin-bottom:.8rem;flex-wrap:wrap;">
                <button type="button" onclick="selDjen(true)" class="btn btn-outline btn-sm">Selecionar todos</button>
                <button type="button" onclick="selDjen(false)" class="btn btn-outline btn-sm">Desmarcar todos</button>
                <button type="button" onclick="selDjenEnc()" class="btn btn-outline btn-sm" style="border-color:#059669;color:#059669;">So com pasta</button>
                <span style="font-size:.72rem;color:var(--text-muted);margin-left:.5rem;" id="contadorSel">0 selecionados</span>
            </div>

            <?php foreach ($resultado as $idx => $pub):
                $encontrado = !empty($pub['case_id']);
                $rowClass = $pub['segredo'] ? 'segredo' : ($encontrado ? 'encontrado' : 'nao-encontrado');
            ?>
            <div class="pub-row <?= $rowClass ?>" id="pubRow<?= $idx ?>">
                <div style="display:flex;align-items:flex-start;gap:.7rem;">
                    <input type="checkbox" name="itens[<?= $idx ?>][_sel]" value="1"
                           class="cb-pub" onchange="contSel()"
                           <?= $encontrado ? 'checked' : '' ?>
                           style="margin-top:3px;width:16px;height:16px;flex-shrink:0;">
                    <div style="flex:1;min-width:0;">
                        <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;margin-bottom:.3rem;">
                            <span class="numero"><?= e($pub['numero_processo']) ?></span>
                            <?php if ($encontrado): ?>
                                <span class="case-badge ok"><?= e($pub['case_title']) ?> — <?= e($pub['client_name']) ?></span>
                            <?php elseif ($pub['segredo']): ?>
                                <span class="case-badge seg">Segredo de Justica</span>
                            <?php else: ?>
                                <span class="case-badge warn">Pasta nao encontrada</span>
                            <?php endif; ?>
                            <span style="font-size:.68rem;color:var(--text-muted);">
                                <?= e(ucfirst($pub['tipo_comunicacao'])) ?> &middot; <?= date('d/m/Y', strtotime($pub['data_disp'])) ?>
                            </span>
                        </div>
                        <div class="orgao-txt"><?= e($pub['orgao']) ?></div>
                        <div class="conteudo-txt" id="cont<?= $idx ?>"><?= e(mb_substr($pub['conteudo'], 0, 300, 'UTF-8')) ?></div>
                        <button type="button" class="btn-expandir" onclick="expDjen(<?= $idx ?>)">Ver completo</button>

                        <input type="hidden" name="itens[<?= $idx ?>][numero_processo]" value="<?= e($pub['numero_processo']) ?>">
                        <input type="hidden" name="itens[<?= $idx ?>][case_id]" value="<?= (int)($pub['case_id'] ?? 0) ?>">
                        <input type="hidden" name="itens[<?= $idx ?>][client_id]" value="<?= (int)($pub['client_id'] ?? 0) ?>">
                        <input type="hidden" name="itens[<?= $idx ?>][data_disp]" value="<?= e($pub['data_disp']) ?>">
                        <input type="hidden" name="itens[<?= $idx ?>][tipo_comunicacao]" value="<?= e($pub['tipo_comunicacao']) ?>">
                        <input type="hidden" name="itens[<?= $idx ?>][orgao]" value="<?= e($pub['orgao']) ?>">
                        <input type="hidden" name="itens[<?= $idx ?>][comarca]" value="<?= e($pub['comarca'] ?? '') ?>">
                        <input type="hidden" name="itens[<?= $idx ?>][conteudo]" value="<?= e($pub['conteudo']) ?>">
                        <input type="hidden" name="itens[<?= $idx ?>][data_fim]" id="dataFim<?= $idx ?>" value="<?= e($pub['data_fim'] ?? '') ?>">

                        <div style="display:flex;align-items:center;gap:.5rem;margin-top:.5rem;flex-wrap:wrap;">
                            <label style="font-size:.72rem;color:var(--text-muted);font-weight:600;">Prazo (du):</label>
                            <input type="number" class="prazo-input" name="itens[<?= $idx ?>][prazo_dias]"
                                   value="<?= (int)$pub['prazo_dias'] ?>" min="0" max="365"
                                   onchange="recalcFim(<?= $idx ?>, this.value, '<?= e($pub['data_disp']) ?>')">
                            <span style="font-size:.72rem;font-weight:700;color:<?= $pub['data_fim'] ? '#dc2626' : 'var(--text-muted)' ?>;" id="labelFim<?= $idx ?>">
                                <?= $pub['data_fim'] ? 'Vence: ' . date('d/m/Y', strtotime($pub['data_fim'])) : 'Sem prazo' ?>
                            </span>
                            <label style="font-size:.72rem;color:var(--text-muted);font-weight:600;margin-left:.5rem;">Responsavel:</label>
                            <select name="itens[<?= $idx ?>][responsavel]" style="font-size:.72rem;padding:2px 6px;border:1px solid var(--border);border-radius:6px;">
                                <?php foreach ($usuarios as $u): ?>
                                <option value="<?= $u['id'] ?>" <?= ($u['id'] == ($pub['responsavel'] ?? $userId)) ? 'selected' : '' ?>><?= e(explode(' ', $u['name'])[0]) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <?php if (!$encontrado && !$pub['segredo']): ?>
                        <div style="margin-top:.6rem;">
                            <button type="button" class="btn btn-sm btn-outline" style="font-size:.7rem;border-color:#d97706;color:#d97706;" onclick="togCriar(<?= $idx ?>)">+ Criar pasta</button>
                            <div id="criarPasta<?= $idx ?>" class="criar-pasta-form" style="display:none;">
                                <div style="font-size:.75rem;font-weight:700;color:#d97706;margin-bottom:.5rem;">Nova pasta</div>
                                <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:.5rem;">
                                    <div style="display:flex;flex-direction:column;gap:2px;">
                                        <label style="font-size:.65rem;color:var(--text-muted);">Titulo *</label>
                                        <input type="text" name="itens[<?= $idx ?>][title_novo]" class="form-input" style="width:250px;font-size:.78rem;" value="Processo <?= e($pub['numero_processo']) ?>">
                                    </div>
                                    <div style="display:flex;flex-direction:column;gap:2px;">
                                        <label style="font-size:.65rem;color:var(--text-muted);">Cliente *</label>
                                        <select name="itens[<?= $idx ?>][client_id_novo]" class="form-select" style="width:220px;font-size:.78rem;">
                                            <option value="">— Selecione —</option>
                                            <?php foreach ($clientes as $cl):
                                                $presel = false;
                                                foreach ($pub['partes'] as $pn) {
                                                    if (mb_stripos($pn, $cl['name']) !== false || mb_stripos($cl['name'], $pn) !== false) { $presel = true; break; }
                                                }
                                            ?>
                                            <option value="<?= $cl['id'] ?>" <?= $presel ? 'selected' : '' ?>><?= e($cl['name']) ?><?= $cl['cpf'] ? ' (' . e($cl['cpf']) . ')' : '' ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <?php if (!empty($pub['partes'])): ?>
                                <div style="font-size:.68rem;color:var(--text-muted);">Partes: <?= e(implode(', ', array_slice($pub['partes'], 0, 3))) ?></div>
                                <?php endif; ?>
                                <input type="hidden" name="itens[<?= $idx ?>][criar_pasta]" value="1">
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>

            <div style="display:flex;gap:.8rem;align-items:center;margin-top:1rem;padding-top:1rem;border-top:1px solid var(--border);flex-wrap:wrap;">
                <button type="submit" class="btn btn-primary" onclick="return confImport()">Importar Selecionados</button>
                <span style="font-size:.78rem;color:var(--text-muted);">Prazos e tarefas serao criados automaticamente</span>
            </div>
        </form>
    </div>
    <?php endif; ?>
</div>

<script>
function contSel() {
    var t = document.querySelectorAll('.cb-pub:checked').length;
    var el = document.getElementById('contadorSel');
    if (el) el.textContent = t + ' selecionado(s)';
}
contSel();

function selDjen(sel) {
    document.querySelectorAll('.cb-pub').forEach(function(cb) { cb.checked = sel; });
    contSel();
}
function selDjenEnc() {
    document.querySelectorAll('.cb-pub').forEach(function(cb) {
        var row = cb.closest('.pub-row');
        cb.checked = row && row.classList.contains('encontrado');
    });
    contSel();
}
function expDjen(idx) {
    var el = document.getElementById('cont' + idx);
    if (el) { el.classList.toggle('expandido'); el.style.maxHeight = el.classList.contains('expandido') ? 'none' : '80px'; }
}
function togCriar(idx) {
    var el = document.getElementById('criarPasta' + idx);
    if (el) el.style.display = el.style.display === 'none' ? 'block' : 'none';
    var cb = document.querySelector('#pubRow' + idx + ' .cb-pub');
    if (cb) { cb.checked = true; contSel(); }
}
function recalcFim(idx, dias, dataInicio) {
    dias = parseInt(dias) || 0;
    var label = document.getElementById('labelFim' + idx);
    var inputFim = document.getElementById('dataFim' + idx);
    if (!dias || !dataInicio) { if (label) label.textContent = 'Sem prazo'; if (inputFim) inputFim.value = ''; return; }
    var d = new Date(dataInicio); d.setDate(d.getDate() + 1);
    var cont = 0, max = 500;
    while (cont < dias && max > 0) { if (d.getDay() !== 0 && d.getDay() !== 6) cont++; if (cont < dias) d.setDate(d.getDate() + 1); max--; }
    var y = d.getFullYear(), m = String(d.getMonth()+1).padStart(2,'0'), dd = String(d.getDate()).padStart(2,'0');
    if (label) label.textContent = 'Vence: ' + dd + '/' + m + '/' + y + ' (aprox.)';
    if (inputFim) inputFim.value = y + '-' + m + '-' + dd;
}
function confImport() {
    var sel = document.querySelectorAll('.cb-pub:checked').length;
    if (!sel) { alert('Selecione ao menos uma publicacao.'); return false; }
    return confirm('Importar ' + sel + ' publicacao(oes)?\nPrazos e tarefas serao criados automaticamente.');
}
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
