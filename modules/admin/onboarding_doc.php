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
                            co.local_presencial,
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
 * Pré-preenchimento dos campos admin a partir do cadastro do colaborador.
 * Só usado quando o admin AINDA NÃO preencheu o campo no documento (valor vazio).
 * Evita re-digitação de valores que já estão no cadastro.
 */
$autofill = array();
if (!empty($doc['valor_remuneracao'])) {
    $autofill['valor_bolsa'] = number_format((float)$doc['valor_remuneracao'], 2, '.', '');
}
// Parse benefícios buscando vale-transporte → extrai valor
if (!empty($doc['beneficios'])) {
    foreach (preg_split('/\R/', $doc['beneficios']) as $linha) {
        if (preg_match('/transporte/i', $linha)
            && preg_match('/R?\$?\s*([\d.]+,\d{2}|\d+)/', $linha, $m)) {
            $valor = (float)str_replace(',', '.', str_replace('.', '', $m[1]));
            if ($valor > 0) {
                $autofill['valor_aux_transporte'] = number_format($valor, 2, '.', '');
            }
            break;
        }
    }
}
// Dados especificos do estagio cadastrados na ficha do colaborador
if (!empty($doc['modalidade_estagio']))   $autofill['modalidade'] = $doc['modalidade_estagio'];
if (!empty($doc['data_inicio_estagio']))  $autofill['data_inicio'] = $doc['data_inicio_estagio'];
if (!empty($doc['data_termino_estagio'])) $autofill['data_termino'] = $doc['data_termino_estagio'];
if (!empty($doc['seguro_num_apolice']))   $autofill['num_apolice'] = $doc['seguro_num_apolice'];
if (!empty($doc['seguro_seguradora']))    $autofill['seguradora'] = $doc['seguro_seguradora'];

// Salvar
if ($_SERVER['REQUEST_METHOD'] === 'POST' && validate_csrf()) {
    $novosDados = array();
    foreach ($schema['campos_admin'] as $key => $def) {
        if ($def['tipo'] === 'checklist') {
            // Checklist tem estrutura própria (será tratado em outra subpágina)
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
            <div style="background:#ecfdf5;border:1px solid #34d399;color:#065f46;padding:.65rem .9rem;border-radius:8px;font-size:.8rem;margin-bottom:1rem;">
                ✨ <strong>Pré-preenchimento automático:</strong> os dados abaixo já vêm do cadastro do colaborador. Os <em>demais campos do termo</em> (carga horária, dias de trabalho, horários, local presencial, modalidade Presencial/Remoto/Híbrido) também são puxados direto do cadastro — não precisa preencher aqui.
            </div>
        <?php endif; ?>
        <div class="adm-doc-grid">
        <?php foreach ($schema['campos_admin'] as $key => $def):
            if ($def['tipo'] === 'checklist') continue;
            $val = $dadosAdmin[$key] ?? '';
            // Se admin ainda não preencheu, sugere valor a partir do cadastro do colaborador
            $sugestao = ($val === '' && isset($autofill[$key])) ? $autofill[$key] : '';
            if ($sugestao !== '' && $val === '') $val = $sugestao;
        ?>
            <div<?= ($def['tipo'] === 'text' && (strpos($key, 'endereco') !== false)) ? ' style="grid-column:1/-1;"' : '' ?>>
                <label><?= htmlspecialchars($def['label']) ?><?= !empty($def['obrigatorio']) ? ' *' : '' ?></label>
                <?php if ($def['tipo'] === 'select'): ?>
                    <select name="<?= e($key) ?>">
                        <option value="">— Selecione —</option>
                        <?php foreach ($def['opcoes'] as $optK => $optLabel): ?>
                            <option value="<?= e($optK) ?>" <?= $val === $optK ? 'selected' : '' ?>><?= e($optLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php elseif ($def['tipo'] === 'date'): ?>
                    <input type="date" name="<?= e($key) ?>" value="<?= e($val) ?>">
                <?php elseif ($def['tipo'] === 'money'): ?>
                    <input type="number" step="0.01" min="0" name="<?= e($key) ?>" value="<?= e($val) ?>" placeholder="0,00">
                <?php elseif ($def['tipo'] === 'number'): ?>
                    <input type="number" name="<?= e($key) ?>" value="<?= e($val) ?>"
                        <?= isset($def['min']) ? 'min="' . (int)$def['min'] . '"' : '' ?>
                        <?= isset($def['max']) ? 'max="' . (int)$def['max'] . '"' : '' ?>>
                <?php else: ?>
                    <input type="text" name="<?= e($key) ?>" value="<?= e($val) ?>"
                        <?= !empty($def['placeholder']) ? 'placeholder="' . e($def['placeholder']) . '"' : '' ?>>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        </div>

        <div style="margin-top:1.5rem;">
            <button type="submit" class="btn btn-primary">💾 Salvar campos administrativos</button>
        </div>
    </form>
    <?php endif; ?>
</div>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
