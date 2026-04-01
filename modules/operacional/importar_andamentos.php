<?php
/**
 * Ferreira & Sá Hub — Importar Andamentos do LegalOne (CSV)
 *
 * Formato LegalOne CSV (separador ;):
 * Confidencial;Tipo de origem;Data;Hora;Tipo;Descrição;UF;Diário;Caderno;Página;Status;Status do tratamento;Tem arquivo;...
 *
 * Filtra ruído automático (Expedição de Certidão, Conclusos ao Juiz, etc.)
 * Importa apenas andamentos relevantes: Publicações, Despachos, Sentenças, Decisões, Audiências, Manuais
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();

$pdo = db();
$caseId = (int)($_GET['case_id'] ?? 0);

if (!$caseId) {
    flash_set('error', 'Processo não informado.');
    redirect(module_url('operacional'));
}

$stmt = $pdo->prepare('SELECT cs.*, c.name as client_name FROM cases cs LEFT JOIN clients c ON c.id = cs.client_id WHERE cs.id = ?');
$stmt->execute(array($caseId));
$case = $stmt->fetch();

if (!$case) {
    flash_set('error', 'Processo não encontrado.');
    redirect(module_url('operacional'));
}

$resultado = null;
$importados = 0;
$errosCount = 0;
$ignorados = 0;
$preview = array();

// ─── Movimentações que são RUÍDO (ignorar) ───
$ruido = array(
    'expedição de certidão',
    'expedição de outros documentos',
    'expedição de mandado',
    'expedição de aviso de recebimento',
    'conclusos ao juiz',
    'conclusos para decisão',
    'recebidos os autos',
    'proferido despacho de mero expediente',
    'distribuído por sorteio',
    'decorrido prazo de',
    'disponibilizado no dj eletrônico',
    'publicado intimação em',
    'publicado decisão em',
    'publicado despacho em',
    'juntada de aviso de recebimento',
);

function ehRuido($descricao, $ruido) {
    $desc = mb_strtolower(trim($descricao));
    foreach ($ruido as $r) {
        if (strpos($desc, $r) === 0 || $desc === $r) return true;
    }
    // Muito curto e genérico
    if (mb_strlen($desc) < 10) return true;
    return false;
}

function detectarTipo($tipoOrigem, $tipoLegalOne, $descricao) {
    $desc = mb_strtolower($descricao);
    $origem = mb_strtolower($tipoOrigem);

    // Publicação = geralmente contém despacho/sentença/decisão completa
    if ($tipoLegalOne === 'Publicação' || strpos($origem, 'recorte') !== false) {
        if (strpos($desc, 'sentença') !== false || strpos($desc, 'sentenã§a') !== false) return 'sentenca';
        if (strpos($desc, 'decisão') !== false || strpos($desc, 'decisã£o') !== false) return 'decisao';
        if (strpos($desc, 'despacho') !== false) return 'despacho';
        return 'intimacao'; // publicação genérica = intimação
    }

    // Manual = notas da equipe
    if (strpos($origem, 'manual') !== false) return 'observacao';

    // Datacloud = movimentações automáticas
    if (strpos($desc, 'audiência') !== false || strpos($desc, 'audiã') !== false) return 'audiencia';
    if (strpos($desc, 'sentença') !== false || strpos($desc, 'sentenã') !== false) return 'sentenca';
    if (strpos($desc, 'decisão') !== false || strpos($desc, 'decisã') !== false) return 'decisao';
    if (strpos($desc, 'intimação') !== false || strpos($desc, 'intimaã') !== false) return 'intimacao';
    if (strpos($desc, 'citação') !== false || strpos($desc, 'citaã') !== false) return 'citacao';
    if (strpos($desc, 'juntada de petição') !== false || strpos($desc, 'juntada de petiã') !== false) return 'peticao_juntada';
    if (strpos($desc, 'juntada de ata') !== false) return 'audiencia';
    if (strpos($desc, 'acordo') !== false || strpos($desc, 'transação') !== false || strpos($desc, 'transaã') !== false) return 'acordo';
    if (strpos($desc, 'homolog') !== false) return 'sentenca';
    if (strpos($desc, 'recurso') !== false) return 'recurso';
    if (strpos($desc, 'execução') !== false || strpos($desc, 'execuã') !== false || strpos($desc, 'cumprimento') !== false) return 'cumprimento';
    if (strpos($desc, 'trânsito em julgado') !== false || strpos($desc, 'trã¢nsito') !== false) return 'sentenca';

    return 'movimentacao';
}

function resumirPublicacao($descricao) {
    // Publicações do DJ são enormes. Extrair o essencial.
    $desc = trim($descricao);

    // Remover header do tribunal (endereço, comarca)
    if (preg_match('/(DESPACHO|SENTENÇA|DECISÃO|CERTIDÃO)\s+Processo:/iu', $desc, $m, PREG_OFFSET_CAPTURE)) {
        $desc = substr($desc, $m[0][1]);
    }
    // Alternativa com encoding quebrado
    if (preg_match('/(DESPACHO|SENTEN|DECIS|CERTID)/i', $desc, $m, PREG_OFFSET_CAPTURE)) {
        if ($m[0][1] > 50) {
            $desc = substr($desc, $m[0][1]);
        }
    }

    // Remover seção de Partes/Advogados do final
    $markers = array('Número único:', 'Números de processos:', 'Partes:', 'Polo A:');
    foreach ($markers as $mk) {
        $pos = strpos($desc, $mk);
        if ($pos !== false && $pos > 50) {
            $desc = trim(substr($desc, 0, $pos));
        }
    }

    return $desc;
}

// ─── POST: processar importação ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) {
        flash_set('error', 'Token CSRF inválido.');
        redirect(module_url('operacional', 'importar_andamentos.php?case_id=' . $caseId));
    }

    $modo = $_POST['modo'] ?? 'preview';
    $userId = current_user_id();
    $registros = array();

    // Ler conteúdo
    $conteudo = '';
    if (!empty($_FILES['arquivo']['tmp_name']) && $_FILES['arquivo']['error'] === UPLOAD_ERR_OK) {
        $conteudo = file_get_contents($_FILES['arquivo']['tmp_name']);
    } elseif (trim($_POST['texto'] ?? '') !== '') {
        $conteudo = $_POST['texto'];
    }

    if ($conteudo === '') {
        flash_set('error', 'Nenhum dado fornecido.');
        redirect(module_url('operacional', 'importar_andamentos.php?case_id=' . $caseId));
    }

    // Fix encoding
    if (mb_detect_encoding($conteudo, 'UTF-8', true) === false) {
        $conteudo = mb_convert_encoding($conteudo, 'UTF-8', 'ISO-8859-1');
    }
    // BOM UTF-8
    if (substr($conteudo, 0, 3) === "\xEF\xBB\xBF") {
        $conteudo = substr($conteudo, 3);
    }

    // Detectar se é formato LegalOne (header com "Confidencial;Tipo de origem;")
    $isLegalOne = (strpos($conteudo, 'Confidencial') !== false && strpos($conteudo, 'Tipo de origem') !== false);

    if ($isLegalOne) {
        // ── Parser CSV LegalOne com campos multiline entre aspas ──
        $tmpFile = tempnam(sys_get_temp_dir(), 'csv_');
        file_put_contents($tmpFile, $conteudo);
        $handle = fopen($tmpFile, 'r');
        $header = fgetcsv($handle, 0, ';', '"');
        // Mapear índices do header
        $colIdx = array();
        if ($header) {
            foreach ($header as $i => $h) {
                $h = trim(mb_strtolower($h));
                if (strpos($h, 'tipo de origem') !== false) $colIdx['origem'] = $i;
                if ($h === 'data') $colIdx['data'] = $i;
                if ($h === 'hora') $colIdx['hora'] = $i;
                if ($h === 'tipo') $colIdx['tipo'] = $i;
                if (strpos($h, 'descri') !== false) $colIdx['descricao'] = $i;
            }
        }

        while (($row = fgetcsv($handle, 0, ';', '"')) !== false) {
            if (count($row) < 5) continue;

            $origem = isset($colIdx['origem']) ? trim($row[$colIdx['origem']]) : '';
            $dataStr = isset($colIdx['data']) ? trim($row[$colIdx['data']]) : '';
            $tipoLO = isset($colIdx['tipo']) ? trim($row[$colIdx['tipo']]) : '';
            $descricao = isset($colIdx['descricao']) ? trim($row[$colIdx['descricao']]) : '';

            // Parsear data
            $data = null;
            if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $dataStr, $m)) {
                $data = $m[3] . '-' . $m[2] . '-' . $m[1];
            }
            if (!$data) continue;
            if ($descricao === '') continue;

            // Verificar se é ruído
            if (ehRuido($descricao, $ruido)) {
                $ignorados++;
                continue;
            }

            // Detectar tipo
            $tipo = detectarTipo($origem, $tipoLO, $descricao);

            // Para publicações, resumir o texto
            if ($tipoLO === 'Publicação' || strpos(mb_strtolower($origem), 'recorte') !== false) {
                $descricao = resumirPublicacao($descricao);
            }

            // Limpar descrição
            $descricao = trim(preg_replace('/\s+/', ' ', str_replace(array("\r\n", "\r", "\n"), ' ', $descricao)));
            if (mb_strlen($descricao) > 2000) {
                $descricao = mb_substr($descricao, 0, 2000) . '...';
            }

            $registros[] = array('data' => $data, 'tipo' => $tipo, 'descricao' => $descricao, 'origem' => $origem);
        }
        fclose($handle);
        @unlink($tmpFile);

    } else {
        // ── Parser genérico (texto livre, CSV simples) ──
        $linhas = explode("\n", $conteudo);
        foreach ($linhas as $linha) {
            $linha = trim($linha);
            if ($linha === '') continue;

            $campos = null;
            if (strpos($linha, "\t") !== false) $campos = explode("\t", $linha);
            elseif (substr_count($linha, ';') >= 1) $campos = explode(';', $linha);
            elseif (strpos($linha, '|') !== false) $campos = explode('|', $linha);

            $data = null;
            $tipo = 'movimentacao';
            $descricao = '';

            if ($campos && count($campos) >= 2) {
                $campos = array_map('trim', $campos);
                foreach ($campos as $i => $c) {
                    if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $c)) {
                        $parts = explode('/', $c);
                        $data = $parts[2] . '-' . $parts[1] . '-' . $parts[0];
                        unset($campos[$i]);
                        break;
                    }
                }
                $campos = array_values($campos);
                $descricao = implode(' — ', $campos);
            } else {
                if (preg_match('/^(\d{2}\/\d{2}\/\d{4})\s*[-–—:]\s*(.+)/s', $linha, $m)) {
                    $parts = explode('/', $m[1]);
                    $data = $parts[2] . '-' . $parts[1] . '-' . $parts[0];
                    $descricao = trim($m[2]);
                } else {
                    $descricao = $linha;
                }
            }

            $descLower = mb_strtolower($descricao);
            if (strpos($descLower, 'data') !== false && strpos($descLower, 'descri') !== false) continue;
            if (!$data) $data = date('Y-m-d');
            if ($descricao === '') continue;

            $tipo = detectarTipo('', '', $descricao);
            $registros[] = array('data' => $data, 'tipo' => $tipo, 'descricao' => $descricao, 'origem' => 'texto');
        }
    }

    // Processar registros
    foreach ($registros as $reg) {
        $item = $reg;

        if ($modo === 'importar') {
            $chk = $pdo->prepare("SELECT id FROM case_andamentos WHERE case_id = ? AND data_andamento = ? AND descricao = ? LIMIT 1");
            $chk->execute(array($caseId, $reg['data'], $reg['descricao']));
            if ($chk->fetch()) {
                $item['status'] = 'duplicado';
                $errosCount++;
            } else {
                try {
                    $pdo->prepare(
                        "INSERT INTO case_andamentos (case_id, data_andamento, tipo, descricao, created_by, created_at) VALUES (?, ?, ?, ?, ?, NOW())"
                    )->execute(array($caseId, $reg['data'], $reg['tipo'], $reg['descricao'], $userId));
                    $item['status'] = 'ok';
                    $importados++;
                } catch (Exception $e) {
                    $item['status'] = 'erro';
                    $errosCount++;
                }
            }
        } else {
            $item['status'] = 'preview';
        }
        $preview[] = $item;
    }

    $resultado = array('modo' => $modo, 'total' => count($preview), 'importados' => $importados, 'erros' => $errosCount, 'ignorados' => $ignorados);
}

$tipoLabels = array(
    'movimentacao'=>'Movimentação','despacho'=>'Despacho','decisao'=>'Decisão','sentenca'=>'Sentença',
    'audiencia'=>'Audiência','peticao_juntada'=>'Petição/Juntada','intimacao'=>'Intimação','citacao'=>'Citação',
    'acordo'=>'Acordo','recurso'=>'Recurso','cumprimento'=>'Cumprimento','diligencia'=>'Diligência','observacao'=>'Nota interna'
);
$tipoCores = array(
    'movimentacao'=>'#888','despacho'=>'#B87333','decisao'=>'#052228','sentenca'=>'#052228',
    'audiencia'=>'#6B4C9A','peticao_juntada'=>'#059669','intimacao'=>'#dc2626','citacao'=>'#dc2626',
    'acordo'=>'#2D7A4F','recurso'=>'#1a3a7a','cumprimento'=>'#059669','diligencia'=>'#B87333','observacao'=>'#888'
);

$pageTitle = 'Importar Andamentos — ' . $case['title'];
require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.import-container { max-width:900px; margin:0 auto; }
.import-preview-table { width:100%; border-collapse:collapse; font-size:.82rem; }
.import-preview-table th { background:var(--petrol-900); color:#fff; padding:.5rem .75rem; text-align:left; font-size:.72rem; text-transform:uppercase; }
.import-preview-table td { padding:.5rem .75rem; border-bottom:1px solid var(--border); }
.import-preview-table tr.status-ok { background:rgba(5,150,105,.06); }
.import-preview-table tr.status-duplicado { background:rgba(217,119,6,.06); opacity:.7; }
.import-preview-table tr.status-erro { background:rgba(220,38,38,.06); }
.import-preview-table tr.status-preview:hover { background:rgba(5,34,40,.04); }
</style>

<div class="import-container">
    <a href="<?= module_url('operacional', 'caso_ver.php?id=' . $caseId) ?>" class="btn btn-outline btn-sm" style="margin-bottom:1rem;">← Voltar ao processo</a>

    <div class="card" style="margin-bottom:1.25rem;">
        <div class="card-header" style="background:linear-gradient(135deg, var(--petrol-900), var(--petrol-500)); color:#fff;">
            <h3 style="color:#fff;">Importar Andamentos — <?= e($case['title']) ?></h3>
        </div>
        <div class="card-body">
            <p style="font-size:.85rem;color:var(--text-muted);margin-bottom:.5rem;">
                Exporte os andamentos do <strong>LegalOne</strong> em CSV e faça o upload aqui.
            </p>
            <p style="font-size:.78rem;color:var(--text-muted);margin-bottom:1rem;">
                O sistema filtra automaticamente o ruído (Expedição de Certidão, Conclusos ao Juiz, etc.) e importa apenas andamentos relevantes: publicações, despachos, sentenças, decisões, audiências e notas manuais.
            </p>

            <form method="POST" enctype="multipart/form-data" id="formImportar">
                <?= csrf_input() ?>
                <input type="hidden" name="modo" id="modoImportar" value="preview">

                <div style="margin-bottom:1rem;">
                    <label style="font-size:.78rem;font-weight:700;color:var(--petrol-900);display:block;margin-bottom:.3rem;">Upload CSV do LegalOne</label>
                    <input type="file" name="arquivo" accept=".csv,.txt,.tsv" class="form-input" style="padding:.4rem;">
                </div>

                <details style="margin-bottom:1rem;">
                    <summary style="font-size:.78rem;font-weight:700;color:var(--petrol-900);cursor:pointer;">Ou cole o texto manualmente</summary>
                    <textarea name="texto" class="form-input" rows="6" placeholder="Cole aqui os andamentos..." style="font-size:.82rem;font-family:monospace;margin-top:.5rem;"><?= isset($_POST['texto']) ? e($_POST['texto']) : '' ?></textarea>
                </details>

                <div style="display:flex;gap:.75rem;align-items:center;">
                    <button type="submit" class="btn btn-outline" onclick="document.getElementById('modoImportar').value='preview';">Pré-visualizar</button>
                    <?php if ($resultado && $resultado['modo'] === 'preview' && count($preview) > 0): ?>
                        <button type="submit" class="btn btn-primary" style="background:#B87333;" onclick="document.getElementById('modoImportar').value='importar';">Importar <?= count($preview) ?> andamentos</button>
                    <?php endif; ?>
                    <?php if ($resultado): ?>
                        <span style="font-size:.78rem;color:var(--text-muted);">
                            <?= count($preview) ?> relevantes
                            <?php if ($ignorados > 0): ?> · <?= $ignorados ?> ruído filtrado<?php endif; ?>
                        </span>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <?php if ($resultado): ?>
    <div class="card">
        <div class="card-header">
            <h3><?= $resultado['modo'] === 'importar' ? 'Resultado da Importação' : 'Pré-visualização (' . count($preview) . ' andamentos)' ?></h3>
            <?php if ($resultado['modo'] === 'importar'): ?>
                <span style="font-size:.82rem;font-weight:700;color:var(--success);">✓ <?= $importados ?> importados<?= $errosCount > 0 ? " · $errosCount ignorados" : '' ?></span>
            <?php endif; ?>
        </div>
        <div style="overflow-x:auto;">
            <table class="import-preview-table">
                <thead>
                    <tr>
                        <th style="width:90px;">Data</th>
                        <th style="width:110px;">Tipo</th>
                        <th style="width:90px;">Origem</th>
                        <th>Descrição</th>
                        <th style="width:80px;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($preview as $item):
                        $cor = isset($tipoCores[$item['tipo']]) ? $tipoCores[$item['tipo']] : '#888';
                    ?>
                    <tr class="status-<?= $item['status'] ?>">
                        <td style="font-family:monospace;font-size:.78rem;"><?= date('d/m/Y', strtotime($item['data'])) ?></td>
                        <td>
                            <span style="display:inline-block;padding:2px 6px;border-radius:4px;font-size:.68rem;font-weight:600;background:<?= $cor ?>;color:#fff;">
                                <?= isset($tipoLabels[$item['tipo']]) ? $tipoLabels[$item['tipo']] : $item['tipo'] ?>
                            </span>
                        </td>
                        <td style="font-size:.72rem;color:var(--text-muted);">
                            <?php
                            $origemLabel = $item['origem'] ?? '';
                            if (strpos(mb_strtolower($origemLabel), 'recorte') !== false) echo 'Publicação';
                            elseif (strpos(mb_strtolower($origemLabel), 'manual') !== false) echo 'Manual';
                            elseif (strpos(mb_strtolower($origemLabel), 'datacloud') !== false) echo 'Tribunal';
                            else echo e($origemLabel);
                            ?>
                        </td>
                        <td style="font-size:.8rem;max-width:400px;overflow:hidden;text-overflow:ellipsis;">
                            <?= e(mb_strlen($item['descricao']) > 250 ? mb_substr($item['descricao'], 0, 250) . '...' : $item['descricao']) ?>
                        </td>
                        <td>
                            <?php if ($item['status'] === 'ok'): ?>
                                <span style="color:#059669;font-weight:700;">✓ OK</span>
                            <?php elseif ($item['status'] === 'duplicado'): ?>
                                <span style="color:#d97706;font-size:.72rem;">Duplicado</span>
                            <?php elseif ($item['status'] === 'erro'): ?>
                                <span style="color:#dc2626;font-size:.72rem;">Erro</span>
                            <?php else: ?>
                                <span style="color:var(--text-muted);font-size:.72rem;">Preview</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
