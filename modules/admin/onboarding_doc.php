<?php
/**
 * Subpágina admin: preencher os campos administrativos de um documento
 * vinculado a um colaborador (modalidade, datas, valores, apólice, etc).
 *
 * Acesso via /modules/admin/onboarding_doc.php?id=DOCUMENTO_ID
 * (não confundir com onboarding.php que lista os colaboradores)
 *
 * Acesso: SOMENTE admin
 */
require_once __DIR__ . '/../../core/middleware.php';
require_once __DIR__ . '/../../core/onboarding_docs_schema.php';
require_login();
require_role('admin');

$pdo = db();
$docId = (int)($_GET['id'] ?? 0);
if (!$docId) { redirect(module_url('admin', 'onboarding.php')); }

// Carrega o documento e o colaborador (puxando dados extras do cadastro pra pré-preencher)
$st = $pdo->prepare("SELECT cd.*, co.nome_completo, co.token AS colab_token,
                            co.valor_remuneracao, co.beneficios, co.cargo, co.setor,
                            co.modalidade AS colab_modalidade, co.horario_inicio, co.horario_fim,
                            co.local_presencial, co.data_pagamento, co.perfil_cargo,
                            co.modalidade_estagio, co.data_inicio_estagio, co.data_termino_estagio,
                            co.seguro_num_apolice, co.seguro_seguradora
                     FROM colaboradores_documentos cd
                     LEFT JOIN colaboradores_onboarding co ON co.id = cd.colaborador_id
                     WHERE cd.id = ?");
$st->execute(array($docId));
$doc = $st->fetch();
if (!$doc) {
    flash_set('error', 'Documento não encontrado.');
    redirect(module_url('admin', 'onboarding.php'));
}

$schema = onboarding_doc_schema($doc['tipo']);
if (!$schema) {
    flash_set('error', 'Tipo de documento desconhecido: ' . htmlspecialchars($doc['tipo']));
    redirect(module_url('admin', 'onboarding.php?id=' . $doc['colaborador_id']));
}

$dadosAdmin = $doc['dados_admin_json'] ? json_decode($doc['dados_admin_json'], true) : array();
if (!is_array($dadosAdmin)) $dadosAdmin = array();

/**
 * Pré-preenchimento dos campos admin.
 *
 * Separado em 2 grupos:
 *   $autofillCadastro — vêm do cadastro do colaborador (mutável). Usado tanto na
 *                       1ª visualização QUANTO pelo botão "🔄 Re-puxar do cadastro".
 *   $autofillSchema   — defaults estáticos do schema (forma_pagamento, multa, etc.).
 *                       Só usados na 1ª visualização — botão re-puxar não mexe.
 *
 * Quando Amanda atualiza o cadastro do colaborador e quer propagar pro contrato,
 * usa o botão re-puxar (sobrescreve só o que vem do cadastro).
 */
$autofillCadastro = array();
if (!empty($doc['valor_remuneracao'])) {
    $autofillCadastro['valor_bolsa'] = number_format((float)$doc['valor_remuneracao'], 2, '.', '');
}
// Parse benefícios buscando vale-transporte → extrai valor
if (!empty($doc['beneficios'])) {
    foreach (preg_split('/\R/', $doc['beneficios']) as $linha) {
        if (preg_match('/transporte/i', $linha)
            && preg_match('/R?\$?\s*([\d.]+,\d{2}|\d+)/', $linha, $m)) {
            $valor = (float)str_replace(',', '.', str_replace('.', '', $m[1]));
            if ($valor > 0) {
                $autofillCadastro['valor_aux_transporte'] = number_format($valor, 2, '.', '');
            }
            break;
        }
    }
}
// Dados especificos do estagio cadastrados na ficha do colaborador
if (!empty($doc['modalidade_estagio']))   $autofillCadastro['modalidade'] = $doc['modalidade_estagio'];
if (!empty($doc['data_inicio_estagio']))  $autofillCadastro['data_inicio'] = $doc['data_inicio_estagio'];
if (!empty($doc['data_termino_estagio'])) $autofillCadastro['data_termino'] = $doc['data_termino_estagio'];
if (!empty($doc['seguro_num_apolice']))   $autofillCadastro['num_apolice'] = $doc['seguro_num_apolice'];
if (!empty($doc['seguro_seguradora']))    $autofillCadastro['seguradora'] = $doc['seguro_seguradora'];

// Dia de pagamento — extrai número do campo "data_pagamento" do cadastro (ex.: "5º dia útil" → 5)
if (!empty($doc['data_pagamento']) && preg_match('/(\d{1,2})/', $doc['data_pagamento'], $m)) {
    $dia = (int)$m[1];
    if ($dia >= 1 && $dia <= 28) $autofillCadastro['dia_pagamento'] = (string)$dia;
}

// Botão "🔄 Re-puxar dados do cadastro" — sobrescreve só campos do cadastro,
// preservando edições manuais em campos de schema (forma_pagamento, multa, etc.).
// Pedido Amanda 01/06/2026: atualizou cadastro mas contrato ficou desatualizado.
if (isset($_GET['repuxar']) && !empty($autofillCadastro)) {
    $dadosAtualizado = $dadosAdmin;
    $sobrescritos = array();
    foreach ($autofillCadastro as $k => $v) {
        if (!isset($dadosAtualizado[$k]) || (string)$dadosAtualizado[$k] !== (string)$v) {
            $sobrescritos[] = $k;
        }
        $dadosAtualizado[$k] = $v;
    }
    try {
        $pdo->prepare("UPDATE colaboradores_documentos SET dados_admin_json = ? WHERE id = ?")
            ->execute(array(json_encode($dadosAtualizado, JSON_UNESCAPED_UNICODE), $docId));
        if (!empty($sobrescritos)) {
            flash_set('success', '🔄 Re-puxado do cadastro: ' . count($sobrescritos) . ' campo(s) atualizado(s) (' . implode(', ', $sobrescritos) . ').');
        } else {
            flash_set('success', '✓ Tudo já está atualizado com o cadastro.');
        }
    } catch (Exception $e) {
        flash_set('error', 'Erro ao re-puxar: ' . $e->getMessage());
    }
    redirect(module_url('admin', 'onboarding_doc.php?id=' . $docId));
}

// Defaults do schema (forma_pagamento, multa, tempo_resposta_lead etc) entram como sugestão
// SE o admin ainda não tocou no campo. Botão "Re-puxar" NÃO mexe nesses.
$autofill = $autofillCadastro;
foreach ($schema['campos_admin'] as $_k => $_def) {
    if (!isset($_def['default'])) continue;
    if (isset($autofill[$_k])) continue; // já sugerido por outra regra
    $autofill[$_k] = (string)$_def['default'];
}

// Salvar
if ($_SERVER['REQUEST_METHOD'] === 'POST' && validate_csrf()) {
    $novosDados = array();
    foreach ($schema['campos_admin'] as $key => $def) {
        if ($def['tipo'] === 'checklist') {
            // Checklist tem estrutura própria (será tratado em outra subpágina)
            continue;
        }
        // Clausula opcional com toggle "incluir/nao incluir":
        // se admin selecionou "Nao incluir", grava '' (vazio = clausula omitida no render).
        if (!empty($def['incluir_opcional'])) {
            $incluir = ($_POST['incluir_' . $key] ?? '1') === '1';
            $novosDados[$key] = $incluir ? trim($_POST[$key] ?? '') : '';
            continue;
        }
        $val = trim($_POST[$key] ?? '');
        $novosDados[$key] = $val;
    }
    try {
        $pdo->prepare("UPDATE colaboradores_documentos
                       SET dados_admin_json = ?,
                           status = IF(status='pendente', 'em_preenchimento', status)
                       WHERE id = ?")
            ->execute(array(json_encode($novosDados, JSON_UNESCAPED_UNICODE), $docId));
        flash_set('success', 'Campos administrativos do documento salvos.');
        redirect(module_url('admin', 'onboarding_doc.php?id=' . $docId));
    } catch (Exception $e) {
        flash_set('error', 'Erro ao salvar: ' . $e->getMessage());
    }
}

$pageTitle = $schema['icon'] . ' ' . $schema['label'];
require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.adm-doc-card { background:#fff; border-radius:14px; padding:1.5rem 1.8rem; box-shadow:0 2px 8px rgba(0,0,0,.04); border:1px solid #e5e7eb; }
.adm-doc-card h3 { font-size:1.05rem; color:#052228; padding-bottom:.5rem; border-bottom:2px solid #d7ab90; margin-bottom:1rem; }
.adm-doc-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:.85rem; }
.adm-doc-grid label { display:block; font-size:.78rem; font-weight:700; color:#052228; margin-bottom:.25rem; }
.adm-doc-grid input, .adm-doc-grid select, .adm-doc-grid textarea {
    width:100%; padding:.55rem .75rem; border:1.5px solid #e5e7eb; border-radius:8px;
    font-size:.85rem; font-family:inherit;
}
.adm-doc-grid input:focus, .adm-doc-grid select:focus, .adm-doc-grid textarea:focus {
    outline:none; border-color:#B87333; box-shadow:0 0 0 3px rgba(184,115,51,.15);
}
.adm-doc-info { background:#fff7ed; border:1px solid #d7ab90; border-radius:10px; padding:.85rem 1rem; margin-bottom:1.2rem; font-size:.85rem; }
.adm-doc-info strong { color:#6a3c2c; }
.adm-doc-status-bar { display:inline-block; padding:.2rem .7rem; border-radius:12px; font-size:.7rem; font-weight:700; }
.adm-doc-status-bar.pendente { background:#fef3c7; color:#92400e; }
.adm-doc-status-bar.em_preenchimento { background:#fed7aa; color:#9a3412; }
.adm-doc-status-bar.assinado { background:#d1fae5; color:#065f46; }
</style>

<div class="card">
    <div class="card-header">
        <h3><?= htmlspecialchars($schema['icon']) ?> <?= htmlspecialchars($schema['label']) ?></h3>
        <p style="font-size:.82rem;color:#6b7280;margin-top:.3rem;">
            Colaborador(a): <strong><?= e($doc['nome_completo']) ?></strong> &middot;
            <span class="adm-doc-status-bar <?= e($doc['status']) ?>"><?= e($doc['status']) ?></span>
            <?php if (!empty($doc['assinatura_estagiario_em'])): ?>
                &middot; ✓ Assinado em <?= e(date('d/m/Y H:i', strtotime($doc['assinatura_estagiario_em']))) ?>
            <?php endif; ?>
        </p>
        <p style="margin-top:.5rem;">
            <a href="<?= module_url('admin', 'onboarding.php?id=' . (int)$doc['colaborador_id']) ?>" class="btn btn-outline btn-sm">← Voltar ao cadastro</a>
        </p>
    </div>
</div>

<div style="margin-top:1.2rem;" class="adm-doc-card">
    <div class="adm-doc-info">
        <strong>📌 <?= htmlspecialchars($schema['label']) ?>:</strong> <?= htmlspecialchars($schema['descricao']) ?>
    </div>

    <?php if (empty($schema['campos_admin'])): ?>
        <p style="color:#6b7280;font-size:.85rem;">Este documento não tem campos administrativos para preencher. A colaboradora só precisa lê-lo e assinar pela página de boas-vindas.</p>
    <?php else: ?>
    <form method="POST">
        <?= csrf_input() ?>
        <h3>Campos administrativos</h3>
        <p style="font-size:.78rem;color:#6b7280;margin-bottom:1rem;">
            Esses dados serão usados para preencher o documento automaticamente. A colaboradora só visualiza (não pode editar).
        </p>
        <?php if (!empty($autofill) || $doc['tipo'] === 'compromisso_estagio'): ?>
            <div style="background:#ecfdf5;border:1px solid #34d399;color:#065f46;padding:.65rem .9rem;border-radius:8px;font-size:.8rem;margin-bottom:1rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
                <div style="flex:1;min-width:280px;">
                    ✨ <strong>Pré-preenchimento automático:</strong> os dados abaixo já vêm do cadastro do colaborador. Os <em>demais campos do termo</em> (carga horária, dias de trabalho, horários, local presencial, modalidade) também são puxados direto do cadastro — não precisa preencher aqui.
                </div>
                <?php if (!empty($autofillCadastro)): ?>
                <a href="<?= module_url('admin', 'onboarding_doc.php?id=' . $docId . '&repuxar=1') ?>"
                   onclick="return confirm('Re-puxar os dados que vêm do cadastro do colaborador?\n\nVai sobrescrever os campos: valor da bolsa, vale-transporte, modalidade, datas do estágio, apólice/seguradora, dia de pagamento.\n\nNÃO mexe em campos que você editou manualmente (forma de pagamento, multa, etc).');"
                   style="background:#065f46;color:#fff;padding:.45rem .75rem;border-radius:6px;font-size:.75rem;font-weight:700;text-decoration:none;white-space:nowrap;">
                    🔄 Re-puxar do cadastro
                </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <?php
        // Se ja houve um save anterior, NAO sobrescrevemos saves em branco com o default
        // (a colaboradora pode ter intencionalmente deixado uma clausula opcional em branco).
        $temSavePrevio = !empty($dadosAdmin);
        ?>
        <div class="adm-doc-grid">
        <?php foreach ($schema['campos_admin'] as $key => $def):
            if ($def['tipo'] === 'checklist') continue;
            $val = $dadosAdmin[$key] ?? '';
            // Autofill (do cadastro principal + defaults do schema) so quando NUNCA foi salvo.
            if (!$temSavePrevio && $val === '' && isset($autofill[$key])) {
                $val = $autofill[$key];
            }
            // Toggle "incluir/nao incluir" para clausulas opcionais
            $optBool = !empty($def['incluir_opcional']);
            if ($optBool) {
                if ($temSavePrevio) {
                    // Estado salvo: vazio -> nao incluir; com valor -> incluir
                    $incluir = ($val !== '' && $val !== '0');
                } else {
                    $incluir = !empty($def['incluir_default']);
                }
            }
        ?>
            <div<?= ($def['tipo'] === 'text' && (strpos($key, 'endereco') !== false)) ? ' style="grid-column:1/-1;"' : '' ?>>
                <label><?= htmlspecialchars($def['label']) ?><?= !empty($def['obrigatorio']) ? ' *' : '' ?></label>
                <?php if ($optBool): ?>
                    <div class="opt-toggle" style="display:flex;gap:.4rem;margin-bottom:.4rem;font-size:.75rem;">
                        <label style="display:flex;align-items:center;gap:.25rem;font-weight:500;cursor:pointer;padding:.25rem .55rem;border-radius:6px;background:<?= $incluir ? '#d1fae5' : '#f3f4f6' ?>;border:1px solid <?= $incluir ? '#34d399' : '#d1d5db' ?>;">
                            <input type="radio" name="incluir_<?= e($key) ?>" value="1" <?= $incluir ? 'checked' : '' ?> onchange="atualizarOptCampo('<?= e($key) ?>')"> ✅ Incluir
                        </label>
                        <label style="display:flex;align-items:center;gap:.25rem;font-weight:500;cursor:pointer;padding:.25rem .55rem;border-radius:6px;background:<?= !$incluir ? '#fee2e2' : '#f3f4f6' ?>;border:1px solid <?= !$incluir ? '#fca5a5' : '#d1d5db' ?>;">
                            <input type="radio" name="incluir_<?= e($key) ?>" value="0" <?= !$incluir ? 'checked' : '' ?> onchange="atualizarOptCampo('<?= e($key) ?>')"> ❌ Não incluir
                        </label>
                    </div>
                <?php endif; ?>
                <?php if ($def['tipo'] === 'select'): ?>
                    <select name="<?= e($key) ?>" id="opt_<?= e($key) ?>" <?= $optBool && !$incluir ? 'disabled' : '' ?>>
                        <option value="">— Selecione —</option>
                        <?php foreach ($def['opcoes'] as $optK => $optLabel): ?>
                            <option value="<?= e($optK) ?>" <?= $val === $optK ? 'selected' : '' ?>><?= e($optLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php elseif ($def['tipo'] === 'date'): ?>
                    <input type="date" name="<?= e($key) ?>" id="opt_<?= e($key) ?>" value="<?= e($val) ?>" <?= $optBool && !$incluir ? 'disabled' : '' ?>>
                <?php elseif ($def['tipo'] === 'money'): ?>
                    <input type="number" step="0.01" min="0" name="<?= e($key) ?>" id="opt_<?= e($key) ?>" value="<?= e($val) ?>" placeholder="0,00" <?= $optBool && !$incluir ? 'disabled' : '' ?>>
                <?php elseif ($def['tipo'] === 'number'): ?>
                    <input type="number" name="<?= e($key) ?>" id="opt_<?= e($key) ?>" value="<?= e($val) ?>"
                        <?= isset($def['min']) ? 'min="' . (int)$def['min'] . '"' : '' ?>
                        <?= isset($def['max']) ? 'max="' . (int)$def['max'] . '"' : '' ?>
                        <?= !empty($def['placeholder']) ? 'placeholder="' . e($def['placeholder']) . '"' : '' ?>
                        <?= $optBool && !$incluir ? 'disabled' : '' ?>>
                <?php else: ?>
                    <input type="text" name="<?= e($key) ?>" id="opt_<?= e($key) ?>" value="<?= e($val) ?>"
                        <?= !empty($def['placeholder']) ? 'placeholder="' . e($def['placeholder']) . '"' : '' ?>
                        <?= $optBool && !$incluir ? 'disabled' : '' ?>>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        </div>

        <script>
        // Toggle "incluir/nao incluir" das clausulas opcionais
        function atualizarOptCampo(key) {
            var radios = document.getElementsByName('incluir_' + key);
            var incluir = '1';
            for (var i = 0; i < radios.length; i++) if (radios[i].checked) incluir = radios[i].value;
            var input = document.getElementById('opt_' + key);
            if (!input) return;
            input.disabled = (incluir === '0');
            // Repinta labels
            var wrap = input.closest('.adm-doc-grid > div');
            if (wrap) {
                var labels = wrap.querySelectorAll('.opt-toggle label');
                if (labels.length === 2) {
                    if (incluir === '1') {
                        labels[0].style.background = '#d1fae5'; labels[0].style.borderColor = '#34d399';
                        labels[1].style.background = '#f3f4f6'; labels[1].style.borderColor = '#d1d5db';
                    } else {
                        labels[0].style.background = '#f3f4f6'; labels[0].style.borderColor = '#d1d5db';
                        labels[1].style.background = '#fee2e2'; labels[1].style.borderColor = '#fca5a5';
                    }
                }
            }
        }
        </script>

        <div style="margin-top:1.5rem;">
            <button type="submit" class="btn btn-primary">💾 Salvar campos administrativos</button>
        </div>
    </form>
    <?php endif; ?>
</div>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
