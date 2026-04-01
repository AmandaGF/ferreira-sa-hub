<?php
/**
 * Ferreira & Sá Hub — Importar Andamentos do LegalOne (CSV/Texto)
 *
 * Aceita:
 * 1. Upload de CSV exportado do LegalOne
 * 2. Cole de texto (copiar/colar do LegalOne)
 *
 * Formato esperado do CSV LegalOne:
 * Data | Tipo | Descrição (ou variações)
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
$erros = 0;
$preview = array();

// ─── POST: processar importação ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) {
        flash_set('error', 'Token CSRF inválido.');
        redirect(module_url('operacional', 'importar_andamentos.php?case_id=' . $caseId));
    }

    $modo = $_POST['modo'] ?? 'preview'; // preview ou importar
    $texto = trim($_POST['texto'] ?? '');
    $linhas = array();

    // Fonte: upload CSV ou texto colado
    if (!empty($_FILES['arquivo']['tmp_name']) && $_FILES['arquivo']['error'] === UPLOAD_ERR_OK) {
        $conteudo = file_get_contents($_FILES['arquivo']['tmp_name']);
        // Detectar encoding
        if (mb_detect_encoding($conteudo, 'UTF-8', true) === false) {
            $conteudo = mb_convert_encoding($conteudo, 'UTF-8', 'ISO-8859-1');
        }
        $linhas = explode("\n", $conteudo);
    } elseif ($texto !== '') {
        $linhas = explode("\n", $texto);
    }

    if (empty($linhas)) {
        flash_set('error', 'Nenhum dado fornecido.');
        redirect(module_url('operacional', 'importar_andamentos.php?case_id=' . $caseId));
    }

    $userId = current_user_id();

    // Mapear tipos do LegalOne para tipos do sistema
    $tipoMap = array(
        'movimentação' => 'movimentacao', 'movimentacao' => 'movimentacao', 'andamento' => 'movimentacao',
        'despacho' => 'despacho', 'decisão' => 'decisao', 'decisao' => 'decisao',
        'sentença' => 'sentenca', 'sentenca' => 'sentenca',
        'audiência' => 'audiencia', 'audiencia' => 'audiencia',
        'petição' => 'peticao_juntada', 'peticao' => 'peticao_juntada', 'juntada' => 'peticao_juntada',
        'intimação' => 'intimacao', 'intimacao' => 'intimacao',
        'citação' => 'citacao', 'citacao' => 'citacao',
        'acordo' => 'acordo', 'recurso' => 'recurso',
        'cumprimento' => 'cumprimento', 'diligência' => 'diligencia', 'diligencia' => 'diligencia',
        'distribuição' => 'movimentacao', 'distribuicao' => 'movimentacao',
        'publicação' => 'intimacao', 'publicacao' => 'intimacao',
    );

    foreach ($linhas as $linha) {
        $linha = trim($linha);
        if ($linha === '' || $linha === "\r") continue;

        // Tentar parsear: pode ser CSV (separador ; ou , ou tab) ou texto livre
        $campos = null;

        // Tentar tab
        if (strpos($linha, "\t") !== false) {
            $campos = explode("\t", $linha);
        }
        // Tentar ponto e vírgula
        elseif (substr_count($linha, ';') >= 1) {
            $campos = explode(';', $linha);
        }
        // Tentar vírgula (mas cuidado com datas)
        elseif (preg_match('/^\d{2}\/\d{2}\/\d{4}\s*,/', $linha)) {
            $campos = explode(',', $linha, 3);
        }
        // Tentar pipe
        elseif (strpos($linha, '|') !== false) {
            $campos = explode('|', $linha);
        }

        $data = null;
        $tipo = 'movimentacao';
        $descricao = '';

        if ($campos && count($campos) >= 2) {
            // Limpar campos
            $campos = array_map('trim', $campos);

            // Primeiro campo que parece data
            foreach ($campos as $i => $c) {
                if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $c)) {
                    $parts = explode('/', $c);
                    $data = $parts[2] . '-' . $parts[1] . '-' . $parts[0];
                    unset($campos[$i]);
                    break;
                }
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $c)) {
                    $data = $c;
                    unset($campos[$i]);
                    break;
                }
            }

            $campos = array_values($campos);

            // Tentar identificar tipo
            if (count($campos) >= 2) {
                $possibleTipo = mb_strtolower(trim($campos[0]));
                if (isset($tipoMap[$possibleTipo])) {
                    $tipo = $tipoMap[$possibleTipo];
                    $descricao = implode(' — ', array_slice($campos, 1));
                } else {
                    $descricao = implode(' — ', $campos);
                }
            } else {
                $descricao = implode(' ', $campos);
            }
        } else {
            // Texto livre — tentar extrair data do início
            if (preg_match('/^(\d{2}\/\d{2}\/\d{4})\s*[-–—:]\s*(.+)/s', $linha, $m)) {
                $parts = explode('/', $m[1]);
                $data = $parts[2] . '-' . $parts[1] . '-' . $parts[0];
                $descricao = trim($m[2]);
            } else {
                $descricao = $linha;
            }
        }

        // Pular cabeçalhos
        $descLower = mb_strtolower($descricao);
        if (strpos($descLower, 'data') !== false && strpos($descLower, 'descri') !== false) continue;
        if ($descLower === '' || $descLower === 'tipo' || $descLower === 'descrição') continue;

        if (!$data) $data = date('Y-m-d');
        $descricao = trim($descricao);
        if ($descricao === '') continue;

        // Detectar tipo pela descrição se ainda é genérico
        if ($tipo === 'movimentacao') {
            $descLower = mb_strtolower($descricao);
            if (strpos($descLower, 'despacho') !== false) $tipo = 'despacho';
            elseif (strpos($descLower, 'decisão') !== false || strpos($descLower, 'decisao') !== false) $tipo = 'decisao';
            elseif (strpos($descLower, 'sentença') !== false || strpos($descLower, 'sentenca') !== false) $tipo = 'sentenca';
            elseif (strpos($descLower, 'audiência') !== false || strpos($descLower, 'audiencia') !== false) $tipo = 'audiencia';
            elseif (strpos($descLower, 'intimação') !== false || strpos($descLower, 'intimacao') !== false || strpos($descLower, 'publicação') !== false) $tipo = 'intimacao';
            elseif (strpos($descLower, 'citação') !== false || strpos($descLower, 'citacao') !== false) $tipo = 'citacao';
            elseif (strpos($descLower, 'juntada') !== false || strpos($descLower, 'petição') !== false) $tipo = 'peticao_juntada';
            elseif (strpos($descLower, 'acordo') !== false) $tipo = 'acordo';
            elseif (strpos($descLower, 'recurso') !== false) $tipo = 'recurso';
        }

        $item = array('data' => $data, 'tipo' => $tipo, 'descricao' => $descricao);

        if ($modo === 'importar') {
            // Verificar duplicado (mesma data + descrição)
            $chk = $pdo->prepare("SELECT id FROM case_andamentos WHERE case_id = ? AND data_andamento = ? AND descricao = ? LIMIT 1");
            $chk->execute(array($caseId, $data, $descricao));
            if ($chk->fetch()) {
                $item['status'] = 'duplicado';
                $erros++;
            } else {
                try {
                    $pdo->prepare(
                        "INSERT INTO case_andamentos (case_id, data_andamento, tipo, descricao, created_by, created_at)
                         VALUES (?, ?, ?, ?, ?, NOW())"
                    )->execute(array($caseId, $data, $tipo, $descricao, $userId));
                    $item['status'] = 'ok';
                    $importados++;
                } catch (Exception $e) {
                    $item['status'] = 'erro';
                    $item['erro'] = $e->getMessage();
                    $erros++;
                }
            }
        } else {
            $item['status'] = 'preview';
        }

        $preview[] = $item;
    }

    $resultado = array('modo' => $modo, 'total' => count($preview), 'importados' => $importados, 'erros' => $erros);
}

$tipoLabels = array(
    'movimentacao'=>'Movimentação','despacho'=>'Despacho','decisao'=>'Decisão','sentenca'=>'Sentença',
    'audiencia'=>'Audiência','peticao_juntada'=>'Petição/Juntada','intimacao'=>'Intimação','citacao'=>'Citação',
    'acordo'=>'Acordo','recurso'=>'Recurso','cumprimento'=>'Cumprimento','diligencia'=>'Diligência','observacao'=>'Observação'
);

$pageTitle = 'Importar Andamentos — ' . $case['title'];
require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.import-container { max-width:850px; margin:0 auto; }
.import-preview-table { width:100%; border-collapse:collapse; font-size:.82rem; }
.import-preview-table th { background:var(--petrol-900); color:#fff; padding:.5rem .75rem; text-align:left; font-size:.72rem; text-transform:uppercase; }
.import-preview-table td { padding:.5rem .75rem; border-bottom:1px solid var(--border); }
.import-preview-table tr.status-ok { background:rgba(5,150,105,.06); }
.import-preview-table tr.status-duplicado { background:rgba(217,119,6,.06); opacity:.7; }
.import-preview-table tr.status-erro { background:rgba(220,38,38,.06); }
</style>

<div class="import-container">
    <a href="<?= module_url('operacional', 'caso_ver.php?id=' . $caseId) ?>" class="btn btn-outline btn-sm" style="margin-bottom:1rem;">← Voltar ao processo</a>

    <div class="card" style="margin-bottom:1.25rem;">
        <div class="card-header" style="background:linear-gradient(135deg, var(--petrol-900), var(--petrol-500)); color:#fff;">
            <h3 style="color:#fff;">Importar Andamentos — <?= e($case['title']) ?></h3>
        </div>
        <div class="card-body">
            <p style="font-size:.85rem;color:var(--text-muted);margin-bottom:1rem;">
                Importe andamentos do <strong>LegalOne</strong> ou de qualquer sistema. Aceita CSV, texto colado (copiar/colar) ou arquivo.
            </p>

            <form method="POST" enctype="multipart/form-data" id="formImportar">
                <?= csrf_input() ?>
                <input type="hidden" name="modo" id="modoImportar" value="preview">

                <!-- Opção 1: Upload CSV -->
                <div style="margin-bottom:1rem;">
                    <label style="font-size:.78rem;font-weight:700;color:var(--petrol-900);display:block;margin-bottom:.3rem;">Opção 1: Upload de arquivo (CSV, TXT)</label>
                    <input type="file" name="arquivo" accept=".csv,.txt,.tsv" class="form-input" style="padding:.4rem;">
                </div>

                <!-- Opção 2: Colar texto -->
                <div style="margin-bottom:1rem;">
                    <label style="font-size:.78rem;font-weight:700;color:var(--petrol-900);display:block;margin-bottom:.3rem;">Opção 2: Cole o texto dos andamentos</label>
                    <textarea name="texto" class="form-input" rows="8" placeholder="Cole aqui os andamentos do LegalOne...&#10;&#10;Formato aceito:&#10;25/03/2026 ; Intimação ; Intimação para manifestação em 15 dias&#10;20/03/2026 ; Despacho ; Juiz determinou juntada de documentos&#10;&#10;Ou texto livre:&#10;25/03/2026 - Intimação para manifestação em 15 dias" style="font-size:.82rem;font-family:monospace;"><?= isset($_POST['texto']) ? e($_POST['texto']) : '' ?></textarea>
                </div>

                <div style="display:flex;gap:.75rem;">
                    <button type="submit" class="btn btn-outline" onclick="document.getElementById('modoImportar').value='preview';">Pré-visualizar</button>
                    <?php if ($resultado && $resultado['modo'] === 'preview' && count($preview) > 0): ?>
                        <button type="submit" class="btn btn-primary" style="background:#B87333;" onclick="document.getElementById('modoImportar').value='importar';">Importar <?= count($preview) ?> andamentos</button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <?php if ($resultado): ?>
    <div class="card">
        <div class="card-header">
            <h3><?= $resultado['modo'] === 'importar' ? 'Resultado da Importação' : 'Pré-visualização' ?></h3>
            <?php if ($resultado['modo'] === 'importar'): ?>
                <span style="font-size:.82rem;font-weight:700;color:var(--success);">✓ <?= $importados ?> importados<?= $erros > 0 ? " · $erros ignorados" : '' ?></span>
            <?php endif; ?>
        </div>
        <div style="overflow-x:auto;">
            <table class="import-preview-table">
                <thead>
                    <tr>
                        <th style="width:100px;">Data</th>
                        <th style="width:130px;">Tipo</th>
                        <th>Descrição</th>
                        <th style="width:90px;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($preview as $item): ?>
                    <tr class="status-<?= $item['status'] ?>">
                        <td style="font-family:monospace;font-size:.78rem;"><?= date('d/m/Y', strtotime($item['data'])) ?></td>
                        <td>
                            <span style="display:inline-block;padding:2px 6px;border-radius:4px;font-size:.7rem;font-weight:600;background:var(--petrol-900);color:#fff;">
                                <?= isset($tipoLabels[$item['tipo']]) ? $tipoLabels[$item['tipo']] : $item['tipo'] ?>
                            </span>
                        </td>
                        <td style="font-size:.82rem;"><?= e(mb_substr($item['descricao'], 0, 200)) ?><?= mb_strlen($item['descricao']) > 200 ? '...' : '' ?></td>
                        <td>
                            <?php if ($item['status'] === 'ok'): ?>
                                <span style="color:#059669;font-weight:700;">✓ OK</span>
                            <?php elseif ($item['status'] === 'duplicado'): ?>
                                <span style="color:#d97706;font-size:.75rem;">Duplicado</span>
                            <?php elseif ($item['status'] === 'erro'): ?>
                                <span style="color:#dc2626;font-size:.75rem;">Erro</span>
                            <?php else: ?>
                                <span style="color:var(--text-muted);font-size:.75rem;">Preview</span>
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
