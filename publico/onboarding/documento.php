<?php
/**
 * Ferreira e Sá — Página pública de preenchimento e assinatura de documento.
 *
 * Acesso: ?token=XXX&doc=ID
 *
 * Fluxos por tipo de documento (definido no schema):
 *   estagiario_preenche_e_assina
 *     → Tela 1: form com campos_colaborador (RG, endereço, RA, etc)
 *     → Tela 2: revisão do documento renderizado
 *     → Tela 3: confirmação + assinatura
 *     → Tela 4: documento assinado + botão imprimir/PDF
 *
 *   so_assina
 *     → Tela 2: revisão direto
 *     → Tela 3: assinatura
 *     → Tela 4: documento assinado
 *
 *   admin_marca_e_ambos_assinam (Checklist)
 *     → Mostra mensagem aguardando admin
 *     → Quando admin marca tudo, libera assinatura da colaboradora
 */
require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/database.php';
require_once __DIR__ . '/../../core/onboarding_docs_schema.php';
require_once __DIR__ . '/../../core/onboarding_docs_templates.php';

@session_start();

$pdo = db();
$token = isset($_GET['token']) ? trim($_GET['token']) : '';
$docId = (int)($_GET['doc'] ?? 0);

if (!$token || !preg_match('/^[a-f0-9]{16,48}$/', $token) || !$docId) {
    http_response_code(404);
    echo '<h1 style="font-family:sans-serif;text-align:center;padding:3rem;">Link inválido.</h1>';
    exit;
}

// Carrega colaborador
try {
    $st = $pdo->prepare("SELECT * FROM colaboradores_onboarding WHERE token = ? AND status != 'arquivado'");
    $st->execute(array($token));
    $reg = $st->fetch();
} catch (Exception $e) { $reg = null; }

if (!$reg) {
    http_response_code(404);
    echo '<h1 style="font-family:sans-serif;text-align:center;padding:3rem;">Link inválido ou expirado.</h1>';
    exit;
}

// Verifica autenticação (mesma session da página principal)
$sessKey = 'onb_auth_' . $token;
if (empty($_SESSION[$sessKey])) {
    // Manda pra tela de login da pagina principal, que depois volta
    header('Location: ./?token=' . urlencode($token));
    exit;
}

// Carrega o documento
try {
    $st = $pdo->prepare("SELECT * FROM colaboradores_documentos WHERE id = ? AND colaborador_id = ?");
    $st->execute(array($docId, $reg['id']));
    $doc = $st->fetch();
} catch (Exception $e) { $doc = null; }

if (!$doc) {
    http_response_code(404);
    echo '<h1 style="font-family:sans-serif;text-align:center;padding:3rem;">Documento não encontrado.</h1>';
    exit;
}

$schema = onboarding_doc_schema($doc['tipo']);
if (!$schema) {
    http_response_code(500);
    echo '<h1 style="font-family:sans-serif;text-align:center;padding:3rem;">Tipo de documento desconhecido.</h1>';
    exit;
}

$dadosAdmin = $doc['dados_admin_json'] ? json_decode($doc['dados_admin_json'], true) : array();
$dadosColab = $doc['dados_estagiario_json'] ? json_decode($doc['dados_estagiario_json'], true) : array();
if (!is_array($dadosAdmin)) $dadosAdmin = array();
if (!is_array($dadosColab)) $dadosColab = array();

$jaAssinado = !empty($doc['assinatura_estagiario_em']);
$temCamposColab = !empty($schema['campos_colaborador']);
$camposPreenchidos = false;
if ($temCamposColab) {
    $algumPreenchido = false;
    foreach ($schema['campos_colaborador'] as $k => $def) {
        if (!empty($dadosColab[$k])) { $algumPreenchido = true; break; }
    }
    $camposPreenchidos = $algumPreenchido;
}

// ── HANDLERS POST ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['acao_salvar_campos']) && !$jaAssinado) {
        $novos = array();
        $erros = array();
        foreach ($schema['campos_colaborador'] as $key => $def) {
            $val = trim($_POST[$key] ?? '');
            if (!empty($def['obrigatorio']) && $val === '') {
                $erros[] = $def['label'];
            }
            $novos[$key] = $val;
        }
        if (!empty($erros)) {
            $erroForm = 'Preencha os campos obrigatórios: ' . implode(', ', $erros);
        } else {
            try {
                $pdo->prepare("UPDATE colaboradores_documentos
                               SET dados_estagiario_json = ?,
                                   status = IF(status='pendente','em_preenchimento',status)
                               WHERE id = ?")
                    ->execute(array(json_encode($novos, JSON_UNESCAPED_UNICODE), $docId));
                header('Location: ?token=' . urlencode($token) . '&doc=' . $docId . '&etapa=revisar');
                exit;
            } catch (Exception $e) {
                $erroForm = 'Erro ao salvar: ' . $e->getMessage();
            }
        }
    }

    if (isset($_POST['acao_assinar']) && !$jaAssinado) {
        $confirma = isset($_POST['confirma_leitura']) && $_POST['confirma_leitura'] === '1';
        if (!$confirma) {
            $erroForm = 'Você precisa confirmar que leu e concorda com o documento.';
        } else {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            $now = date('Y-m-d H:i:s');
            $assinaturas = array(
                'estagiario_em' => $now,
                'estagiario_ip' => $ip,
            );
            $renderFn = $schema['render_function'];
            $htmlSnapshot = '';
            if (function_exists($renderFn)) {
                try {
                    $htmlSnapshot = $renderFn($reg, $dadosAdmin, $dadosColab, $assinaturas);
                } catch (Exception $e) { $htmlSnapshot = ''; }
            }
            try {
                $pdo->prepare("UPDATE colaboradores_documentos
                               SET assinatura_estagiario_em = ?,
                                   assinatura_estagiario_ip = ?,
                                   assinatura_estagiario_nome = ?,
                                   status = 'assinado',
                                   pdf_html_snapshot = ?
                               WHERE id = ?")
                    ->execute(array($now, $ip, $reg['nome_completo'], $htmlSnapshot, $docId));

                // Notifica admins
                try {
                    require_once __DIR__ . '/../../core/functions_notify.php';
                    if (function_exists('notify_admins')) {
                        notify_admins(
                            '✓ Documento assinado',
                            $reg['nome_completo'] . ' assinou: ' . $schema['label'],
                            null
                        );
                    }
                } catch (Exception $e) {}

                header('Location: ?token=' . urlencode($token) . '&doc=' . $docId . '&etapa=assinado');
                exit;
            } catch (Exception $e) {
                $erroForm = 'Erro ao registrar assinatura: ' . $e->getMessage();
            }
        }
    }
}

// Determina etapa atual (depois dos handlers POST)
$etapa = isset($_GET['etapa']) ? $_GET['etapa'] : null;
if ($jaAssinado) {
    $etapa = 'assinado';
} elseif (!$etapa) {
    if ($temCamposColab && !$camposPreenchidos) $etapa = 'preencher';
    else $etapa = 'revisar';
}

$tituloPagina = $schema['icon'] . ' ' . $schema['label'];
$urlVoltar = './?token=' . urlencode($token);
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($tituloPagina) ?> — Ferreira e Sá</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700;800&family=Open+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root {
    --petrol-900: #052228;
    --petrol-700: #173d46;
    --cobre: #6a3c2c;
    --cobre-light: #B87333;
    --nude: #d7ab90;
    --nude-light: #fff7ed;
    --bg: #f8f4ef;
    --rose: #ec4899;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Open Sans', sans-serif; background: var(--bg); color: #1a1a1a; min-height: 100vh; line-height: 1.55; }
h1, h2, h3, h4 { font-family: 'Playfair Display', serif; color: var(--petrol-900); }

.toolbar {
    background: linear-gradient(135deg, var(--petrol-900), var(--petrol-700));
    color: #fff; padding: 1rem 1.5rem; display: flex; align-items: center;
    gap: 1rem; flex-wrap: wrap; justify-content: space-between;
    position: sticky; top: 0; z-index: 100; box-shadow: 0 4px 14px rgba(0,0,0,.15);
}
.toolbar h1 { color: #fff; font-size: 1.1rem; font-weight: 700; }
.toolbar .btn-back { background: rgba(255,255,255,.15); color: #fff; padding: .5rem 1rem; border-radius: 8px; text-decoration: none; font-size: .85rem; font-weight: 600; }
.toolbar .btn-back:hover { background: rgba(255,255,255,.25); }
.toolbar .stepbar { font-size: .8rem; opacity: .9; }
.toolbar .stepbar strong { color: var(--nude); }

.container { max-width: 880px; margin: 1.5rem auto 3rem; padding: 0 1.2rem; }
.card-block { background: #fff; border-radius: 14px; box-shadow: 0 4px 18px rgba(0,0,0,.06); padding: 1.8rem 1.6rem; margin-bottom: 1.2rem; }
.card-block h2 { font-size: 1.4rem; margin-bottom: .8rem; }
.card-block p { margin-bottom: .8rem; color: #1a1a1a; }
.card-block p.lead { font-size: 1rem; color: var(--cobre); }

.form-grid { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); }
.form-grid label { font-size: .8rem; font-weight: 700; color: var(--petrol-900); display: block; margin-bottom: .35rem; }
.form-grid input, .form-grid select, .form-grid textarea {
    width: 100%; padding: .65rem .85rem; border: 1.5px solid #e5e7eb; border-radius: 8px;
    font-size: .9rem; font-family: inherit;
}
.form-grid input:focus, .form-grid select:focus, .form-grid textarea:focus {
    outline: none; border-color: var(--cobre-light); box-shadow: 0 0 0 3px rgba(184,115,51,.15);
}
.form-grid .full { grid-column: 1 / -1; }

.btn-primary {
    background: linear-gradient(135deg, var(--petrol-900), var(--petrol-700));
    color: #fff; border: 0; padding: .85rem 1.8rem; border-radius: 10px;
    font-size: .95rem; font-weight: 700; cursor: pointer; font-family: inherit;
    box-shadow: 0 4px 14px rgba(5,34,40,.25); text-decoration: none;
    display: inline-block;
}
.btn-primary:hover { transform: translateY(-1px); box-shadow: 0 8px 22px rgba(5,34,40,.3); }
.btn-outline {
    background: #fff; border: 1.5px solid var(--cobre-light); color: var(--cobre);
    padding: .75rem 1.4rem; border-radius: 10px; font-weight: 700;
    cursor: pointer; font-family: inherit; text-decoration: none; font-size: .9rem;
    display: inline-block;
}
.btn-success {
    background: linear-gradient(135deg, #059669, #047857);
    color: #fff; border: 0; padding: 1rem 2rem; border-radius: 12px;
    font-size: 1rem; font-weight: 700; cursor: pointer; font-family: inherit;
    box-shadow: 0 4px 16px rgba(5,150,105,.3);
}

.alerta-erro { background: #fef2f2; border: 1px solid #fca5a5; color: #991b1b; padding: .8rem 1rem; border-radius: 10px; margin-bottom: 1rem; }
.alerta-info { background: #eff6ff; border-left: 4px solid #3b82f6; color: #1e40af; padding: .8rem 1rem; border-radius: 0 8px 8px 0; margin-bottom: 1rem; font-size: .88rem; }
.alerta-warn { background: #fef9c3; border-left: 4px solid #f59e0b; color: #78350f; padding: .8rem 1rem; border-radius: 0 8px 8px 0; margin-bottom: 1rem; font-size: .88rem; }
.alerta-success { background: linear-gradient(135deg,#ecfdf5,#d1fae5); border: 1.5px solid #34d399; color: #065f46; padding: 1.2rem 1.4rem; border-radius: 12px; margin-bottom: 1rem; }

.preview-wrap { background: #f3f4f6; padding: 1.5rem; border-radius: 12px; margin: 1rem 0; }
.preview-wrap iframe { background: #fff; width: 100%; height: 70vh; border: 1px solid #d7ab90; border-radius: 8px; }

.confirma-box { background: var(--nude-light); border: 1.5px solid var(--nude); border-radius: 10px; padding: 1rem 1.2rem; margin: 1rem 0; }
.confirma-box label { display: flex; align-items: flex-start; gap: .6rem; cursor: pointer; font-size: .9rem; }
.confirma-box input[type="checkbox"] { margin-top: 4px; transform: scale(1.2); accent-color: #059669; }

.acoes-row { display: flex; gap: .6rem; flex-wrap: wrap; margin-top: 1rem; }

@media print {
    body { background: #fff; margin: 0; padding: 0; }
    .toolbar, .no-print { display: none !important; }
    .container { max-width: none; margin: 0; padding: 0; }
    .card-block { box-shadow: none; padding: 0; margin: 0; }
    iframe { display: none; }
    .preview-wrap { background: #fff; padding: 0; }
    .doc-snapshot { display: block !important; }
}

.doc-snapshot { display: none; }

<?= onboarding_docs_css() ?>
</style>
</head>
<body>

<div class="toolbar no-print">
    <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap;">
        <a href="<?= htmlspecialchars($urlVoltar) ?>" class="btn-back">← Voltar</a>
        <h1><?= htmlspecialchars($tituloPagina) ?></h1>
    </div>
    <div class="stepbar">
        <?php
        $stepLabels = array();
        if ($temCamposColab) $stepLabels[] = '1. Preencher';
        $stepLabels[] = ($temCamposColab ? '2' : '1') . '. Revisar';
        $stepLabels[] = ($temCamposColab ? '3' : '2') . '. Assinar';
        $stepLabels[] = ($temCamposColab ? '4' : '3') . '. Pronto';
        foreach ($stepLabels as $i => $lbl) {
            $atual = false;
            if ($etapa === 'preencher' && $i === 0 && $temCamposColab) $atual = true;
            if ($etapa === 'revisar' && (($temCamposColab && $i === 1) || (!$temCamposColab && $i === 0))) $atual = true;
            if ($etapa === 'assinar' && (($temCamposColab && $i === 2) || (!$temCamposColab && $i === 1))) $atual = true;
            if ($etapa === 'assinado' && $i === count($stepLabels) - 1) $atual = true;
            echo $atual ? '<strong>' . $lbl . '</strong>' : '<span style="opacity:.55;">' . $lbl . '</span>';
            if ($i < count($stepLabels) - 1) echo ' &middot; ';
        }
        ?>
    </div>
</div>

<div class="container">

<?php if (!empty($erroForm)): ?>
    <div class="alerta-erro no-print">⚠ <?= htmlspecialchars($erroForm) ?></div>
<?php endif; ?>

<?php if ($schema['fluxo'] === 'admin_marca_e_ambos_assinam' && !$jaAssinado): ?>
    <!-- CHECKLIST: aguarda admin marcar -->
    <div class="card-block no-print">
        <div style="text-align:center;padding:2rem 1rem;">
            <div style="font-size:4rem;margin-bottom:.5rem;">⏳</div>
            <h2><?= htmlspecialchars($schema['label']) ?></h2>
            <p class="lead" style="margin:1rem 0;"><?= htmlspecialchars($schema['descricao']) ?></p>
            <div class="alerta-info" style="text-align:left;">
                Este documento será preenchido pela <strong>Dra. Amanda</strong> ou <strong>Dr. Luiz Eduardo</strong> ao longo dos seus 5 primeiros dias úteis no escritório, à medida que cada item for sendo concluído.
                Quando todos os itens estiverem marcados, você receberá uma notificação e poderá assinar a ciência aqui mesmo. 💜
            </div>
            <a href="<?= htmlspecialchars($urlVoltar) ?>" class="btn-outline" style="margin-top:1rem;">← Voltar para a página de boas-vindas</a>
        </div>
    </div>

<?php elseif ($etapa === 'preencher'): ?>
    <!-- ETAPA 1: PREENCHER CAMPOS DA COLABORADORA -->
    <div class="card-block no-print">
        <h2>📝 Complete seus dados</h2>
        <p class="lead">Para a gente preencher seu <strong><?= htmlspecialchars($schema['label']) ?></strong>, precisamos das seguintes informações:</p>

        <div class="alerta-info">
            ℹ️ Os dados que aparecem aqui já foram preenchidos por nós. Você só preenche <strong>os seus dados pessoais</strong> que faltam.
        </div>

        <form method="POST">
            <input type="hidden" name="acao_salvar_campos" value="1">
            <div class="form-grid">
                <?php foreach ($schema['campos_colaborador'] as $key => $def):
                    $val = isset($dadosColab[$key]) ? $dadosColab[$key] : (isset($def['default']) ? $def['default'] : '');
                    $isFull = (strpos($key, 'endereco') !== false);
                ?>
                <div<?= $isFull ? ' class="full"' : '' ?>>
                    <label><?= htmlspecialchars($def['label']) ?><?= !empty($def['obrigatorio']) ? ' *' : '' ?></label>
                    <?php if ($def['tipo'] === 'select'): ?>
                        <select name="<?= htmlspecialchars($key) ?>" <?= !empty($def['obrigatorio']) ? 'required' : '' ?>>
                            <option value="">— Selecione —</option>
                            <?php foreach ($def['opcoes'] as $optK => $optLbl): ?>
                                <option value="<?= htmlspecialchars($optK) ?>" <?= $val === $optK ? 'selected' : '' ?>><?= htmlspecialchars($optLbl) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php elseif ($def['tipo'] === 'number'): ?>
                        <input type="number" name="<?= htmlspecialchars($key) ?>" value="<?= htmlspecialchars($val) ?>"
                            <?= isset($def['min']) ? 'min="' . (int)$def['min'] . '"' : '' ?>
                            <?= isset($def['max']) ? 'max="' . (int)$def['max'] . '"' : '' ?>
                            <?= !empty($def['obrigatorio']) ? 'required' : '' ?>
                            <?= !empty($def['placeholder']) ? 'placeholder="' . htmlspecialchars($def['placeholder']) . '"' : '' ?>>
                    <?php elseif ($def['tipo'] === 'tel'): ?>
                        <input type="tel" name="<?= htmlspecialchars($key) ?>" value="<?= htmlspecialchars($val) ?>"
                            placeholder="<?= htmlspecialchars($def['placeholder'] ?? '(00) 00000-0000') ?>"
                            <?= !empty($def['obrigatorio']) ? 'required' : '' ?>>
                    <?php else: ?>
                        <input type="text" name="<?= htmlspecialchars($key) ?>" value="<?= htmlspecialchars($val) ?>"
                            <?= !empty($def['placeholder']) ? 'placeholder="' . htmlspecialchars($def['placeholder']) . '"' : '' ?>
                            <?= !empty($def['obrigatorio']) ? 'required' : '' ?>>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="acoes-row">
                <a href="<?= htmlspecialchars($urlVoltar) ?>" class="btn-outline">← Cancelar</a>
                <button type="submit" class="btn-primary">Continuar para revisar →</button>
            </div>
        </form>
    </div>

<?php elseif ($etapa === 'revisar'): ?>
    <!-- ETAPA 2: REVISAR DOCUMENTO -->
    <div class="card-block no-print">
        <h2>📄 Revise o documento</h2>
        <p class="lead">Leia com calma. Se algo estiver errado, volte e ajuste antes de assinar.</p>

        <div class="alerta-warn">
            ⚠ Após assinar, este documento será considerado <strong>aceito eletronicamente</strong>, com registro de data, hora e IP.
        </div>

        <div class="preview-wrap">
        <?php
        $renderFn = $schema['render_function'];
        if (function_exists($renderFn)) {
            try {
                echo $renderFn($reg, $dadosAdmin, $dadosColab, array());
            } catch (Exception $e) {
                echo '<p style="color:#991b1b;padding:2rem;">Erro ao renderizar documento: ' . htmlspecialchars($e->getMessage()) . '</p>';
            }
        } else {
            echo '<p style="color:#9a3412;padding:2rem;text-align:center;">⏳ Conteúdo deste documento ainda não foi disponibilizado.</p>';
        }
        ?>
        </div>

        <div class="acoes-row">
            <?php if ($temCamposColab): ?>
                <a href="?token=<?= urlencode($token) ?>&doc=<?= $docId ?>&etapa=preencher" class="btn-outline">← Editar meus dados</a>
            <?php else: ?>
                <a href="<?= htmlspecialchars($urlVoltar) ?>" class="btn-outline">← Voltar</a>
            <?php endif; ?>
            <a href="?token=<?= urlencode($token) ?>&doc=<?= $docId ?>&etapa=assinar" class="btn-primary">Avançar para assinatura →</a>
        </div>
    </div>

<?php elseif ($etapa === 'assinar'): ?>
    <!-- ETAPA 3: CONFIRMAR E ASSINAR -->
    <div class="card-block no-print">
        <h2>✍️ Confirmar e assinar</h2>
        <p class="lead">Última etapa! Confirme abaixo que leu e está de acordo, e clique em assinar.</p>

        <div class="alerta-info">
            ✓ Sua assinatura é registrada eletronicamente com <strong>nome completo, data, hora e IP</strong>, conforme padrão da MP 2.200-2/2001.
        </div>

        <form method="POST">
            <input type="hidden" name="acao_assinar" value="1">
            <div class="confirma-box">
                <label>
                    <input type="checkbox" name="confirma_leitura" value="1" required>
                    <span>
                        Eu, <strong><?= htmlspecialchars($reg['nome_completo']) ?></strong>, declaro que <strong>li, compreendi e concordo</strong> integralmente com o conteúdo do <strong><?= htmlspecialchars($schema['label']) ?></strong> e assumo, eletronicamente, todos os compromissos nele estabelecidos.
                    </span>
                </label>
            </div>

            <div class="acoes-row">
                <a href="?token=<?= urlencode($token) ?>&doc=<?= $docId ?>&etapa=revisar" class="btn-outline">← Voltar</a>
                <button type="submit" class="btn-success" onclick="return confirm('Confirmar assinatura eletrônica?\\n\\nApós este momento o documento estará assinado e você não poderá mais editar.');">
                    ✓ Assinar eletronicamente
                </button>
            </div>
        </form>
    </div>

<?php else: /* etapa === 'assinado' */ ?>
    <!-- ETAPA 4: DOCUMENTO ASSINADO -->
    <div class="card-block no-print">
        <div class="alerta-success" style="text-align:center;">
            <div style="font-size:3rem;margin-bottom:.4rem;">✅</div>
            <h2 style="color:#065f46;">Documento assinado!</h2>
            <p style="margin-top:.5rem;">
                <strong><?= htmlspecialchars($schema['label']) ?></strong> foi assinado eletronicamente em
                <strong><?= htmlspecialchars(date('d/m/Y \à\s H:i', strtotime($doc['assinatura_estagiario_em']))) ?></strong>.
            </p>
            <p style="font-size:.85rem;color:#047857;">Você pode imprimir ou salvar uma cópia em PDF abaixo.</p>
        </div>

        <div class="acoes-row">
            <a href="<?= htmlspecialchars($urlVoltar) ?>" class="btn-outline">← Voltar para a página principal</a>
            <button type="button" class="btn-primary" onclick="window.print()">🖨️ Imprimir / Salvar PDF</button>
        </div>
    </div>

    <div class="preview-wrap doc-snapshot-wrap">
        <?php
        if (!empty($doc['pdf_html_snapshot'])) {
            // Exibe snapshot salvo (estado no momento da assinatura)
            echo $doc['pdf_html_snapshot'];
        } else {
            // Fallback: re-renderiza com dados atuais
            $renderFn = $schema['render_function'];
            if (function_exists($renderFn)) {
                $assinaturas = array(
                    'estagiario_em' => $doc['assinatura_estagiario_em'],
                    'estagiario_ip' => $doc['assinatura_estagiario_ip'],
                );
                echo $renderFn($reg, $dadosAdmin, $dadosColab, $assinaturas);
            }
        }
        ?>
    </div>

<?php endif; ?>

</div>

</body>
</html>
