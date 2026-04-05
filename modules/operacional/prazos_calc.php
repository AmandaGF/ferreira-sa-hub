<?php
/**
 * Ferreira & Sa Conecta — Calculadora de Prazos Processuais
 *
 * Calcula prazos com base nas regras do CPC:
 *   Disponibilizacao (D) -> Publicacao (D+1 util) -> Inicio contagem (D+2 util)
 *   Conta dias úteis, exclui fins de semana e suspensoes (feriados/recesso)
 *   Data fatal em dia nao util -> avanca para proximo util
 *
 * PHP 7.4 — array() syntax, no match(), no str_contains()
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();

$pageTitle = 'Calculadora de Prazos';
$pdo = db();

// ─── Pre-fill from URL params ──────────────────────────
$preCaseId = (int)($_GET['case_id'] ?? 0);
$preTipo   = isset($_GET['tipo']) ? $_GET['tipo'] : '';
$preComarca = isset($_GET['comarca']) ? $_GET['comarca'] : '';

// If case_id, fetch case data for pre-fill
$preCase = null;
if ($preCaseId) {
    $stmt = $pdo->prepare("SELECT id, title, case_number, court, comarca, case_type FROM cases WHERE id = ?");
    $stmt->execute(array($preCaseId));
    $preCase = $stmt->fetch();
    if ($preCase && !$preComarca) {
        $preComarca = $preCase['comarca'] ? $preCase['comarca'] : '';
    }
}

// ─── Handle POST: calculate ────────────────────────────
$resultado = null;
$salvoComSucesso = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && validate_csrf()) {
    $dataDisp  = isset($_POST['data_disponibilizacao']) ? $_POST['data_disponibilizacao'] : '';
    $qtd       = (int)(isset($_POST['quantidade']) ? $_POST['quantidade'] : 15);
    $unidade   = (isset($_POST['unidade']) && $_POST['unidade'] === 'meses') ? 'meses' : 'dias';
    $comarca   = clean_str(isset($_POST['comarca']) ? $_POST['comarca'] : '', 100);
    $tipoPrazo = clean_str(isset($_POST['tipo_prazo']) ? $_POST['tipo_prazo'] : '', 100);
    $caseId    = (int)(isset($_POST['case_id']) ? $_POST['case_id'] : 0);

    if ($dataDisp && $qtd > 0) {
        $resultado = calcular_prazo_completo($dataDisp, $qtd, $unidade, $comarca ? $comarca : null);

        // Save if requested
        if (isset($_POST['salvar']) && $_POST['salvar']) {
            try {
                $pdo->prepare(
                    "INSERT INTO prazos_calculos (case_id, tipo_prazo, data_disponibilizacao, data_publicacao, data_inicio_contagem, quantidade, unidade, comarca, data_fatal, calculado_por)
                     VALUES (?,?,?,?,?,?,?,?,?,?)"
                )->execute(array(
                    $caseId ? $caseId : null,
                    $tipoPrazo ? $tipoPrazo : null,
                    $resultado['disponibilizacao'],
                    $resultado['publicacao'],
                    $resultado['inicio_contagem'],
                    $qtd,
                    $unidade,
                    $comarca ? $comarca : null,
                    $resultado['data_fatal'],
                    current_user_id()
                ));
            } catch (Exception $e) { /* table may not exist yet */ }

            // If case_id, also create task in prazos_processuais and agenda
            if ($caseId) {
                try {
                    $pdo->prepare(
                        "INSERT INTO prazos_processuais (case_id, tipo, descricao, data_fatal, status) VALUES (?,?,?,?,0)"
                    )->execute(array(
                        $caseId,
                        $tipoPrazo ? $tipoPrazo : 'Prazo',
                        'Prazo calculado: ' . $qtd . ' ' . $unidade,
                        $resultado['data_fatal']
                    ));
                } catch (Exception $e) { /* ignore */ }

                try {
                    $pdo->prepare(
                        "INSERT INTO agenda_eventos (titulo, tipo, data_inicio, data_fim, dia_todo, case_id, responsavel_id) VALUES (?,?,?,?,1,?,?)"
                    )->execute(array(
                        'PRAZO: ' . ($tipoPrazo ? $tipoPrazo : 'Processual') . ' - ' . ($preCase ? $preCase['title'] : ''),
                        'prazo',
                        $resultado['data_fatal'],
                        $resultado['data_fatal'],
                        $caseId,
                        current_user_id()
                    ));
                } catch (Exception $e) { /* ignore */ }
            }

            $salvoComSucesso = true;
            flash_set('success', 'Prazo salvo com sucesso! Data fatal: ' . date('d/m/Y', strtotime($resultado['data_fatal'])));
        }
    } else {
        flash_set('error', 'Preencha a data de disponibilização e a quantidade de dias/meses.');
    }
}

// ─── Data for form ─────────────────────────────────────
$comarcas   = comarcas_rj();
$tiposPrazo = tipos_prazo();

// Fetch recent cases for the select (limit 200)
$casesForSelect = array();
try {
    $casesForSelect = $pdo->query(
        "SELECT id, title, case_number FROM cases ORDER BY title ASC LIMIT 200"
    )->fetchAll();
} catch (Exception $e) {
    $casesForSelect = array();
}

// ─── Extra CSS for this page ───────────────────────────
$extraCss = '
<style>
/* Layout 2 colunas */
.prazos-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1.5rem;
    align-items: start;
}
@media (max-width: 900px) {
    .prazos-grid { grid-template-columns: 1fr; }
}

/* Form */
.prazo-form .form-group { margin-bottom: 1rem; }
.prazo-form label {
    display: block;
    font-weight: 600;
    margin-bottom: .3rem;
    font-size: .88rem;
    color: #374151;
}
.prazo-form .form-input,
.prazo-form .form-select {
    width: 100%;
    padding: .55rem .75rem;
    border: 1px solid #d1d5db;
    border-radius: .5rem;
    font-size: .92rem;
    background: #fff;
    transition: border-color .2s;
}
.prazo-form .form-input:focus,
.prazo-form .form-select:focus {
    outline: none;
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37,99,235,.12);
}

/* Preview inline */
.preview-row {
    display: flex;
    gap: 1rem;
    margin-top: .5rem;
    flex-wrap: wrap;
}
.preview-item {
    background: #f0f9ff;
    border: 1px solid #bfdbfe;
    border-radius: .4rem;
    padding: .4rem .75rem;
    font-size: .82rem;
    color: #1e40af;
    flex: 1;
    min-width: 140px;
}
.preview-item strong { color: #1e3a5f; }

/* Quantidade inline */
.qtd-row {
    display: flex;
    gap: .75rem;
    align-items: flex-end;
}
.qtd-row .form-group { flex: 1; margin-bottom: 0; }

/* Botao grande */
.btn-calcular {
    width: 100%;
    padding: .85rem 1.5rem;
    font-size: 1.1rem;
    font-weight: 700;
    background: #2563eb;
    color: #fff;
    border: none;
    border-radius: .6rem;
    cursor: pointer;
    transition: background .2s;
    margin-top: 1rem;
    letter-spacing: .5px;
}
.btn-calcular:hover { background: #1d4ed8; }

/* Resultado */
.resultado-card {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: .75rem;
    padding: 1.5rem;
    box-shadow: 0 1px 4px rgba(0,0,0,.06);
}
.resultado-card h3 {
    margin: 0 0 1rem;
    font-size: 1.05rem;
    color: #1e3a5f;
    border-bottom: 2px solid #e5e7eb;
    padding-bottom: .5rem;
}

.resultado-linha {
    display: flex;
    justify-content: space-between;
    padding: .4rem 0;
    font-size: .9rem;
    border-bottom: 1px solid #f3f4f6;
}
.resultado-linha:last-child { border-bottom: none; }
.resultado-label { color: #6b7280; font-weight: 500; }
.resultado-valor { color: #1f2937; font-weight: 600; }

/* Data fatal destaque */
.data-fatal-box {
    background: linear-gradient(135deg, #dc2626, #b91c1c);
    color: #fff;
    text-align: center;
    padding: 1.25rem 1rem;
    border-radius: .6rem;
    margin: 1.25rem 0;
}
.data-fatal-box .label-fatal {
    font-size: .82rem;
    text-transform: uppercase;
    letter-spacing: 1px;
    opacity: .9;
    margin-bottom: .25rem;
}
.data-fatal-box .data-fatal-valor {
    font-size: 1.8rem;
    font-weight: 800;
    letter-spacing: 1px;
}
.data-fatal-box .dia-semana {
    font-size: .9rem;
    margin-top: .2rem;
    opacity: .85;
}

/* Dias ate prazo */
.dias-ate-box {
    text-align: center;
    padding: .75rem;
    border-radius: .5rem;
    font-weight: 700;
    font-size: 1rem;
    margin-bottom: 1rem;
}
.dias-ate-box.urgente { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }
.dias-ate-box.atencao { background: #fffbeb; color: #d97706; border: 1px solid #fde68a; }
.dias-ate-box.ok      { background: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0; }
.dias-ate-box.vencido { background: #fef2f2; color: #991b1b; border: 1px solid #fca5a5; }

/* Suspensoes */
.suspensoes-lista {
    margin: .75rem 0;
    padding: 0;
    list-style: none;
}
.suspensoes-lista li {
    padding: .35rem .5rem;
    font-size: .82rem;
    border-left: 3px solid #f59e0b;
    margin-bottom: .35rem;
    background: #fffbeb;
    border-radius: 0 .3rem .3rem 0;
    color: #92400e;
}

/* Botoes resultado */
.resultado-acoes {
    display: flex;
    gap: .75rem;
    margin-top: 1rem;
}
.resultado-acoes .btn { flex: 1; text-align: center; }

/* Mini calendario */
.mini-cal-container { margin-top: 1.25rem; }
.mini-cal-container h4 {
    font-size: .92rem;
    color: #374151;
    margin: 0 0 .5rem;
}
.mini-cal-mes {
    margin-bottom: 1rem;
}
.mini-cal-mes-title {
    font-weight: 600;
    font-size: .85rem;
    color: #1e3a5f;
    margin-bottom: .35rem;
    text-transform: capitalize;
}
.mini-cal-grid {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 2px;
    font-size: .72rem;
    text-align: center;
}
.mini-cal-header {
    font-weight: 700;
    color: #6b7280;
    padding: .2rem 0;
    font-size: .68rem;
}
.mini-cal-day {
    position: relative;
    padding: .25rem .1rem;
    border-radius: .25rem;
    min-height: 1.6rem;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 500;
}
.mini-cal-day.empty { background: transparent; }
.mini-cal-day.fim-de-semana { background: #f3f4f6; color: #9ca3af; }
.mini-cal-day.suspenso { background: #fef3c7; color: #92400e; }
.mini-cal-day.contado { background: #dcfce7; color: #166534; }
.mini-cal-day.fatal {
    background: #dc2626;
    color: #fff;
    font-weight: 800;
    border-radius: 50%;
}
.mini-cal-day.inicio { background: #dbeafe; color: #1e40af; font-weight: 700; }
.mini-cal-day.publicacao { background: #e0e7ff; color: #3730a3; }
.mini-cal-day.fora-periodo { color: #d1d5db; }

/* Legenda */
.cal-legenda {
    display: flex;
    gap: .75rem;
    flex-wrap: wrap;
    margin-top: .5rem;
    font-size: .75rem;
}
.cal-legenda-item {
    display: flex;
    align-items: center;
    gap: .3rem;
}
.cal-legenda-dot {
    width: 12px;
    height: 12px;
    border-radius: 50%;
    display: inline-block;
    flex-shrink: 0;
}
.dot-fatal     { background: #dc2626; }
.dot-suspenso  { background: #fef3c7; border: 1px solid #f59e0b; }
.dot-fds       { background: #f3f4f6; border: 1px solid #d1d5db; }
.dot-contado   { background: #dcfce7; border: 1px solid #86efac; }
.dot-inicio    { background: #dbeafe; border: 1px solid #93c5fd; }

/* Sem resultado placeholder */
.sem-resultado {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    min-height: 300px;
    color: #9ca3af;
    text-align: center;
    padding: 2rem;
}
.sem-resultado-icon {
    font-size: 3rem;
    margin-bottom: .75rem;
    opacity: .5;
}
.sem-resultado p {
    font-size: .92rem;
    max-width: 280px;
    line-height: 1.5;
}
</style>';

require_once APP_ROOT . '/templates/layout_start.php';
?>

<!-- Voltar -->
<a href="<?= module_url('operacional') ?>" class="btn btn-outline btn-sm mb-2">&larr; Voltar</a>

<?php if ($preCase): ?>
<div style="margin-bottom:.75rem;">
    <a href="<?= module_url('operacional', 'caso_ver.php?id=' . (int)$preCase['id']) ?>" class="btn btn-outline btn-sm" style="font-size:.82rem;">
        &larr; Voltar ao processo: <strong><?= e($preCase['title'] ?: $preCase['case_number']) ?></strong>
    </a>
</div>
<?php endif; ?>

<div class="prazos-grid">
    <!-- ════════════════════════════════════════════════════ -->
    <!-- COLUNA ESQUERDA: Formulario                        -->
    <!-- ════════════════════════════════════════════════════ -->
    <div>
        <div class="card">
            <form method="POST" class="prazo-form" id="prazoForm">
                <?= csrf_input() ?>

                <!-- Processo (opcional) -->
                <div class="form-group">
                    <label for="caseSelect">Processo (opcional)</label>
                    <select name="case_id" id="caseSelect" class="form-select">
                        <option value="">-- Sem vinculo a processo --</option>
                        <?php foreach ($casesForSelect as $c): ?>
                            <option value="<?= (int)$c['id'] ?>"
                                <?php if ($preCaseId && $preCaseId == $c['id']): ?> selected<?php endif; ?>
                            >
                                <?= e($c['title'] ?: $c['case_number'] ?: 'Processo #' . $c['id']) ?>
                                <?php if ($c['case_number']): ?> (<?= e($c['case_number']) ?>)<?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Tipo de prazo -->
                <div class="form-group">
                    <label for="tipoPrazo">Tipo de Prazo</label>
                    <select name="tipo_prazo" id="tipoPrazo" class="form-select">
                        <option value="">-- Selecione --</option>
                        <?php foreach ($tiposPrazo as $tp): ?>
                            <option value="<?= e($tp) ?>"
                                <?php
                                    $postTipo = isset($_POST['tipo_prazo']) ? $_POST['tipo_prazo'] : $preTipo;
                                    if ($postTipo === $tp) echo ' selected';
                                ?>
                            ><?= e($tp) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Comarca -->
                <div class="form-group">
                    <label for="comarca">Comarca</label>
                    <select name="comarca" id="comarca" class="form-select">
                        <option value="">-- Selecione a comarca --</option>
                        <?php foreach ($comarcas as $cm): ?>
                            <option value="<?= e($cm) ?>"
                                <?php
                                    $postComarca = isset($_POST['comarca']) ? $_POST['comarca'] : $preComarca;
                                    if ($postComarca === $cm) echo ' selected';
                                ?>
                            ><?= e($cm) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Data de disponibilizacao -->
                <div class="form-group">
                    <label for="dataDisp">Data de Disponibilizacao (DJe)</label>
                    <input type="date" name="data_disponibilizacao" id="dataDisp"
                           class="form-input"
                           value="<?= e(isset($_POST['data_disponibilizacao']) ? $_POST['data_disponibilizacao'] : '') ?>"
                           required>
                </div>

                <!-- Preview auto-calculado -->
                <div class="preview-row" id="previewRow" style="display:none;">
                    <div class="preview-item">
                        <strong>Publicação (D+1):</strong><br>
                        <span id="previewPub">--</span>
                    </div>
                    <div class="preview-item">
                        <strong>Inicio contagem:</strong><br>
                        <span id="previewInicio">--</span>
                    </div>
                </div>

                <!-- Quantidade + unidade -->
                <div class="form-group" style="margin-top:1rem;">
                    <label>Prazo</label>
                    <div class="qtd-row">
                        <div class="form-group">
                            <input type="number" name="quantidade" id="quantidade"
                                   class="form-input" min="1" max="999"
                                   value="<?= e(isset($_POST['quantidade']) ? $_POST['quantidade'] : '15') ?>"
                                   placeholder="15">
                        </div>
                        <div class="form-group">
                            <select name="unidade" id="unidade" class="form-select">
                                <option value="dias"<?php if (!isset($_POST['unidade']) || $_POST['unidade'] === 'dias') echo ' selected'; ?>>Dias uteis</option>
                                <option value="meses"<?php if (isset($_POST['unidade']) && $_POST['unidade'] === 'meses') echo ' selected'; ?>>Meses</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Botao calcular -->
                <button type="submit" class="btn-calcular">CALCULAR PRAZO</button>
            </form>
        </div>

        <!-- Info box -->
        <div class="card" style="margin-top:1rem; padding:1rem; font-size:.82rem; color:#6b7280; line-height:1.6;">
            <strong style="color:#374151;">Como funciona:</strong><br>
            1. <strong>Disponibilizacao</strong> = data da publicacao no DJe<br>
            2. <strong>Publicacao</strong> = D+1 dia útil<br>
            3. <strong>Inicio da contagem</strong> = primeiro dia útil apos a publicacao<br>
            4. O prazo conta apenas <strong>dias úteis</strong> (exceto se em meses)<br>
            5. Se a data fatal cair em dia nao util, avanca para o proximo dia útil<br>
            6. Sao excluidos: sabados, domingos, feriados e suspensoes do TJRJ
        </div>
    </div>

    <!-- ════════════════════════════════════════════════════ -->
    <!-- COLUNA DIREITA: Resultado                          -->
    <!-- ════════════════════════════════════════════════════ -->
    <div>
        <?php if ($resultado): ?>
            <div class="resultado-card">
                <h3>Resultado do Calculo</h3>

                <!-- Linhas de dados -->
                <div class="resultado-linha">
                    <span class="resultado-label">Disponibilizacao</span>
                    <span class="resultado-valor"><?= data_br($resultado['disponibilizacao']) ?></span>
                </div>
                <div class="resultado-linha">
                    <span class="resultado-label">Publicação (D+1)</span>
                    <span class="resultado-valor"><?= data_br($resultado['publicacao']) ?></span>
                </div>
                <div class="resultado-linha">
                    <span class="resultado-label">Inicio da contagem</span>
                    <span class="resultado-valor"><?= data_br($resultado['inicio_contagem']) ?></span>
                </div>
                <div class="resultado-linha">
                    <span class="resultado-label">Prazo</span>
                    <span class="resultado-valor"><?= (int)$resultado['quantidade'] ?> <?= $resultado['unidade'] === 'meses' ? 'meses' : 'dias úteis' ?></span>
                </div>

                <!-- Suspensoes encontradas -->
                <?php if (!empty($resultado['suspensoes'])): ?>
                    <div style="margin-top:.75rem;">
                        <span class="resultado-label" style="font-size:.85rem;">Suspensões encontradas:</span>
                        <ul class="suspensoes-lista">
                            <?php foreach ($resultado['suspensoes'] as $susp): ?>
                                <li>
                                    <?= data_br($susp['data_inicio']) ?>
                                    <?php if ($susp['data_fim'] !== $susp['data_inicio']): ?>
                                        a <?= data_br($susp['data_fim']) ?>
                                    <?php endif; ?>
                                    &mdash; <?= e($susp['motivo']) ?>
                                    <span style="font-size:.72rem;color:#b45309;">(<?= e($susp['abrangencia']) ?>)</span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php else: ?>
                    <div style="margin-top:.5rem;font-size:.82rem;color:#6b7280;">
                        Nenhuma suspensao encontrada no periodo.
                    </div>
                <?php endif; ?>

                <!-- DATA SEGURANÇA destaque -->
                <div style="background:#059669;border-radius:12px;padding:1rem;text-align:center;margin-bottom:.75rem;">
                    <div style="font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:rgba(255,255,255,.8);">Prazo Interno (segurança)</div>
                    <div style="font-size:1.8rem;font-weight:800;color:#fff;"><?= date('d/m/Y', strtotime($resultado['data_seguranca'])) ?></div>
                    <div style="font-size:.78rem;color:rgba(255,255,255,.7);"><?= e($resultado['dia_semana_seg']) ?></div>
                    <div style="font-size:.68rem;color:rgba(255,255,255,.6);margin-top:.3rem;">1 dia útil ANTES do término — para evitar perda de prazo</div>
                </div>

                <!-- DATA FATAL destaque -->
                <div class="data-fatal-box">
                    <div class="label-fatal">Data Fatal (término legal)</div>
                    <div class="data-fatal-valor" id="dataFatalValor"><?= date('d/m/Y', strtotime($resultado['data_fatal'])) ?></div>
                    <div class="dia-semana"><?= e($resultado['dia_semana_fatal']) ?></div>
                </div>

                <!-- Dias ate o prazo -->
                <?php
                $diasAte = (int)$resultado['dias_ate_prazo'];
                if ($diasAte < 0) {
                    $diasClass = 'vencido';
                    $diasTexto = 'PRAZO VENCIDO ha ' . abs($diasAte) . ' dia' . (abs($diasAte) !== 1 ? 's' : '');
                } elseif ($diasAte === 0) {
                    $diasClass = 'urgente';
                    $diasTexto = 'PRAZO VENCE HOJE!';
                } elseif ($diasAte <= 3) {
                    $diasClass = 'urgente';
                    $diasTexto = $diasAte . ' dia' . ($diasAte !== 1 ? 's' : '') . ' ate o prazo';
                } elseif ($diasAte <= 7) {
                    $diasClass = 'atencao';
                    $diasTexto = $diasAte . ' dias ate o prazo';
                } else {
                    $diasClass = 'ok';
                    $diasTexto = $diasAte . ' dias ate o prazo';
                }
                ?>
                <div class="dias-ate-box <?= $diasClass ?>"><?= e($diasTexto) ?></div>
                <?php $diasSeg = (int)$resultado['dias_ate_seguranca']; ?>
                <?php if ($diasSeg >= 0 && $diasSeg !== $diasAte): ?>
                <div style="text-align:center;font-size:.72rem;color:#059669;font-weight:700;margin-top:.3rem;">Prazo interno (segurança): <?= $diasSeg ?> dia<?= $diasSeg !== 1 ? 's' : '' ?></div>
                <?php endif; ?>

                <!-- Botoes -->
                <div class="resultado-acoes">
                    <?php if (!$salvoComSucesso): ?>
                        <button type="button" class="btn btn-primary" id="btnSalvar" onclick="salvarPrazo()">
                            Salvar no processo
                        </button>
                    <?php else: ?>
                        <span class="btn btn-outline" style="opacity:.6;cursor:default;">Salvo com sucesso</span>
                    <?php endif; ?>
                    <button type="button" class="btn btn-outline" onclick="copiarResultado()">
                        Copiar
                    </button>
                </div>
            </div>

            <!-- ──────────────────────────────────────────── -->
            <!-- Mini Calendario                             -->
            <!-- ──────────────────────────────────────────── -->
            <div class="mini-cal-container">
                <h4>Calendario do Prazo</h4>
                <?php
                // Determine range of months to show
                $calInicio = new DateTime($resultado['inicio_contagem']);
                $calFim    = new DateTime($resultado['data_fatal']);

                // Get expanded suspension days for calendar marking
                $diasSuspensos = get_dias_suspensos_expandidos(
                    $calInicio->format('Y-m-d'),
                    $calFim->format('Y-m-d'),
                    $resultado['comarca'] ? $resultado['comarca'] : null
                );

                // Build set of counted business days between inicio and fatal
                $diasContados = array();
                $tempDt = new DateTime($resultado['inicio_contagem']);
                $tempDt->modify('+1 day'); // contagem comeca no dia seguinte ao inicio
                $fatalDate = $resultado['data_fatal'];
                $comarcaCal = $resultado['comarca'] ? $resultado['comarca'] : null;
                $maxIter = 500;
                $iterCount = 0;
                while ($tempDt->format('Y-m-d') <= $fatalDate && $iterCount < $maxIter) {
                    $dd = $tempDt->format('Y-m-d');
                    if (is_dia_util($dd, $comarcaCal)) {
                        $diasContados[$dd] = true;
                    }
                    $tempDt->modify('+1 day');
                    $iterCount++;
                }

                // Key dates
                $dateDisp    = $resultado['disponibilizacao'];
                $datePub     = $resultado['publicacao'];
                $dateInicio  = $resultado['inicio_contagem'];
                $dateFatal   = $resultado['data_fatal'];

                // Iterate months
                $mesAtual = new DateTime($calInicio->format('Y-m-01'));
                $mesFim   = new DateTime($calFim->format('Y-m-01'));

                $mesesPt = array(
                    1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Marco', 4 => 'Abril',
                    5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
                    9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
                );
                $diasSemanaHeader = array('D', 'S', 'T', 'Q', 'Q', 'S', 'S');

                while ($mesAtual <= $mesFim) {
                    $ano = (int)$mesAtual->format('Y');
                    $mes = (int)$mesAtual->format('n');
                    $diasNoMes = (int)$mesAtual->format('t');
                    $primeiroDow = (int)(new DateTime($mesAtual->format('Y-m-01')))->format('w');

                    echo '<div class="mini-cal-mes">';
                    echo '<div class="mini-cal-mes-title">' . $mesesPt[$mes] . ' ' . $ano . '</div>';
                    echo '<div class="mini-cal-grid">';

                    // Header
                    foreach ($diasSemanaHeader as $dh) {
                        echo '<div class="mini-cal-header">' . $dh . '</div>';
                    }

                    // Empty cells before first day
                    for ($e = 0; $e < $primeiroDow; $e++) {
                        echo '<div class="mini-cal-day empty"></div>';
                    }

                    // Days
                    for ($d = 1; $d <= $diasNoMes; $d++) {
                        $dStr = sprintf('%04d-%02d-%02d', $ano, $mes, $d);
                        $dow  = (int)(new DateTime($dStr))->format('w');

                        // Determine class
                        $cls = '';
                        $title = '';

                        if ($dStr === $dateFatal) {
                            $cls = 'fatal';
                            $title = 'Data Fatal';
                        } elseif ($dStr === $dateInicio) {
                            $cls = 'inicio';
                            $title = 'Inicio da contagem';
                        } elseif ($dStr === $datePub) {
                            $cls = 'publicacao';
                            $title = 'Publicacao';
                        } elseif (isset($diasSuspensos[$dStr])) {
                            $cls = 'suspenso';
                            $title = e($diasSuspensos[$dStr]);
                        } elseif ($dow === 0 || $dow === 6) {
                            $cls = 'fim-de-semana';
                        } elseif (isset($diasContados[$dStr])) {
                            $cls = 'contado';
                        } elseif ($dStr < $dateInicio || $dStr > $dateFatal) {
                            $cls = 'fora-periodo';
                        }

                        echo '<div class="mini-cal-day ' . $cls . '"' . ($title ? ' title="' . $title . '"' : '') . '>' . $d . '</div>';
                    }

                    echo '</div>'; // grid
                    echo '</div>'; // mes
                    $mesAtual->modify('+1 month');
                }
                ?>

                <!-- Legenda -->
                <div class="cal-legenda">
                    <div class="cal-legenda-item"><span class="cal-legenda-dot dot-fatal"></span> Data Fatal</div>
                    <div class="cal-legenda-item"><span class="cal-legenda-dot dot-suspenso"></span> Suspensao</div>
                    <div class="cal-legenda-item"><span class="cal-legenda-dot dot-fds"></span> Fim de semana</div>
                    <div class="cal-legenda-item"><span class="cal-legenda-dot dot-contado"></span> Dia contado</div>
                    <div class="cal-legenda-item"><span class="cal-legenda-dot dot-inicio"></span> Início / Publicação</div>
                </div>
            </div>

        <?php else: ?>
            <!-- Placeholder quando nao ha resultado -->
            <div class="card">
                <div class="sem-resultado">
                    <div class="sem-resultado-icon">&#128197;</div>
                    <p>Preencha os dados ao lado e clique em <strong>CALCULAR PRAZO</strong> para ver o resultado aqui.</p>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════ -->
<!-- JavaScript                                             -->
<!-- ════════════════════════════════════════════════════════ -->
<script>
(function() {
    // ─── Preview auto-calculation ──────────────────────
    var dataInput = document.getElementById('dataDisp');
    var qtdInput  = document.getElementById('quantidade');
    var unidInput = document.getElementById('unidade');

    if (dataInput) {
        dataInput.addEventListener('change', previewCalculo);
        if (qtdInput) qtdInput.addEventListener('change', previewCalculo);
        if (unidInput) unidInput.addEventListener('change', previewCalculo);
        // Run on load if value already set
        if (dataInput.value) previewCalculo();
    }

    function previewCalculo() {
        var data = document.getElementById('dataDisp').value;
        var qtd  = document.getElementById('quantidade').value;
        if (!data || !qtd) {
            document.getElementById('previewRow').style.display = 'none';
            return;
        }
        // Simple D+1 preview (server calculates properly on submit)
        var dt = new Date(data + 'T12:00:00');
        // D+1 = publicacao (approximate, ignores holidays)
        dt.setDate(dt.getDate() + 1);
        // Skip weekends for publicacao
        while (dt.getDay() === 0 || dt.getDay() === 6) {
            dt.setDate(dt.getDate() + 1);
        }
        document.getElementById('previewPub').textContent = formatDateBR(dt) + ' (aprox.)';

        // D+2 = inicio contagem (approximate)
        dt.setDate(dt.getDate() + 1);
        while (dt.getDay() === 0 || dt.getDay() === 6) {
            dt.setDate(dt.getDate() + 1);
        }
        document.getElementById('previewInicio').textContent = formatDateBR(dt) + ' (aprox.)';

        document.getElementById('previewRow').style.display = 'flex';
    }

    function formatDateBR(dt) {
        var d = ('0' + dt.getDate()).slice(-2);
        var m = ('0' + (dt.getMonth() + 1)).slice(-2);
        var y = dt.getFullYear();
        return d + '/' + m + '/' + y;
    }

    // ─── Salvar prazo (resubmit form with salvar=1) ───
    window.salvarPrazo = function() {
        var form = document.getElementById('prazoForm');
        if (!form) return;
        // Add hidden field salvar=1
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'salvar';
        input.value = '1';
        form.appendChild(input);
        form.submit();
    };

    // ─── Copiar resultado ─────────────────────────────
    window.copiarResultado = function() {
        var el = document.getElementById('dataFatalValor');
        if (!el) return;

        var lines = [];
        lines.push('=== CALCULO DE PRAZO ===');
        lines.push('Disponibilização: <?= $resultado ? data_br($resultado['disponibilizacao']) : '' ?>');
        lines.push('Publicação: <?= $resultado ? data_br($resultado['publicacao']) : '' ?>');
        lines.push('Inicio contagem: <?= $resultado ? data_br($resultado['inicio_contagem']) : '' ?>');
        lines.push('Prazo: <?= $resultado ? (int)$resultado['quantidade'] . ' ' . ($resultado['unidade'] === 'meses' ? 'meses' : 'dias úteis') : '' ?>');
        lines.push('');
        lines.push('PRAZO INTERNO (segurança): <?= $resultado ? date('d/m/Y', strtotime($resultado['data_seguranca'])) . ' (' . $resultado['dia_semana_seg'] . ')' : '' ?>');
        lines.push('DATA FATAL (término legal): ' + el.textContent + ' (<?= $resultado ? $resultado['dia_semana_fatal'] : '' ?>)');
        lines.push('OBS: Considere protocolar até a data de segurança para evitar perda de prazo.');
        <?php if ($resultado && !empty($resultado['suspensoes'])): ?>
        lines.push('');
        lines.push('Suspensoes no periodo:');
        <?php foreach ($resultado['suspensoes'] as $susp): ?>
        lines.push('  - <?= data_br($susp['data_inicio']) ?><?= ($susp['data_fim'] !== $susp['data_inicio']) ? ' a ' . data_br($susp['data_fim']) : '' ?> - <?= e($susp['motivo']) ?>');
        <?php endforeach; ?>
        <?php endif; ?>

        var text = lines.join('\n');

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function() {
                alert('Resultado copiado para a area de transferencia!');
            });
        } else {
            // Fallback
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.left = '-9999px';
            document.body.appendChild(ta);
            ta.select();
            try { document.execCommand('copy'); alert('Resultado copiado!'); } catch(e) {}
            document.body.removeChild(ta);
        }
    };
})();
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
