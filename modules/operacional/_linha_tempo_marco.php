<?php
/**
 * Partial de um marco no editor da Linha do Tempo.
 * Espera $_m (linha de case_timeline_eventos) e $_labels (lt_tipos_labels()).
 *
 * Usado duas vezes em linha_tempo.php: no loop dos marcos existentes e dentro
 * do <template> que o JS clona pra criar marco novo.
 */
$_mId  = (int)($_m['id'] ?? 0);
$_mVis = (int)($_m['visivel'] ?? 1);
$_mDes = (int)($_m['destaque'] ?? 0);
$_mIa  = (int)($_m['gerado_ia'] ?? 0);
$_mMan = (int)($_m['editado_manual'] ?? 0);
if (!isset($_labels)) $_labels = lt_tipos_labels();
?>
<div class="lt-marco<?= $_mVis ? '' : ' oculto' ?><?= $_mDes ? ' destaque' : '' ?>"
     data-id="<?= $_mId ?>" data-dirty="0" draggable="true">

    <div class="lt-marco-head">
        <span class="arrasta" title="Arraste para reordenar">⠿</span>

        <input type="date" data-f="data_evento" onchange="ltMarcoMudou(this)"
               value="<?= e($_m['data_evento'] ?? '') ?>"
               style="border:1px solid #e5e7eb;border-radius:6px;padding:.25rem .4rem;font-size:.78rem;">

        <select data-f="tipo" onchange="ltMarcoMudou(this)"
                style="border:1px solid #e5e7eb;border-radius:6px;padding:.25rem .4rem;font-size:.78rem;">
            <?php foreach ($_labels as $_k => $_lbl): ?>
                <option value="<?= e($_k) ?>" <?= ($_m['tipo'] ?? 'outro') === $_k ? 'selected' : '' ?>><?= e($_lbl) ?></option>
            <?php endforeach; ?>
        </select>

        <?php if ($_mIa && !$_mMan): ?>
            <span class="ia-tag" title="Gerado pela IA — será substituído se você gerar de novo">IA</span>
        <?php elseif ($_mMan): ?>
            <span class="ia-tag man-tag" title="Editado à mão — a IA nunca sobrescreve">editado à mão</span>
        <?php endif; ?>

        <div class="lt-marco-acoes">
            <label class="lt-chk" title="Marcos ocultos não aparecem para o cliente">
                <input type="checkbox" data-f="visivel" onchange="ltMarcoMudou(this)" <?= $_mVis ? 'checked' : '' ?>> visível
            </label>
            <label class="lt-chk" title="Vira o marco grande de virada do caso">
                <input type="checkbox" data-f="destaque" onchange="ltMarcoMudou(this)" <?= $_mDes ? 'checked' : '' ?>> destaque
            </label>
            <button type="button" class="btn btn-primary btn-sm" onclick="ltSalvarMarco(this)">Salvar</button>
            <button type="button" class="btn btn-outline btn-sm" onclick="ltExcluirMarco(this)" title="Excluir marco">✕</button>
        </div>
    </div>

    <div class="lt-campo" style="margin-bottom:.45rem;">
        <input type="text" data-f="titulo" maxlength="200" oninput="ltMarcoMudou(this)"
               value="<?= e($_m['titulo'] ?? '') ?>"
               placeholder="Título do marco — já conte a notícia. Ex: A juíza garantiu a pensão"
               style="border:1px solid #e5e7eb;border-radius:6px;padding:.45rem .55rem;font-size:.9rem;font-weight:600;width:100%;">
    </div>

    <div class="lt-campo" style="margin-bottom:.45rem;">
        <textarea data-f="texto" oninput="ltMarcoMudou(this)" rows="2"
                  placeholder="O que isso significou na prática pra vida do cliente. Uma a três frases, sem juridiquês."
                  style="border:1px solid #e5e7eb;border-radius:6px;padding:.45rem .55rem;font-size:.85rem;font-family:inherit;line-height:1.5;width:100%;resize:vertical;"><?= e($_m['texto'] ?? '') ?></textarea>
    </div>

    <div style="display:grid;grid-template-columns:2fr 1fr;gap:.45rem;">
        <input type="text" data-f="nota" oninput="ltMarcoMudou(this)"
               value="<?= e($_m['nota'] ?? '') ?>" placeholder="Observação secundária (opcional)"
               style="border:1px solid #e5e7eb;border-radius:6px;padding:.35rem .5rem;font-size:.78rem;color:#6b7280;">
        <input type="text" data-f="data_label" maxlength="60" oninput="ltMarcoMudou(this)"
               value="<?= e($_m['data_label'] ?? '') ?>" placeholder="Data por extenso (opcional)"
               title="Use quando a data exata não importa. Ex: 'meados de 2025'. Substitui a data na página do cliente."
               style="border:1px solid #e5e7eb;border-radius:6px;padding:.35rem .5rem;font-size:.78rem;color:#6b7280;">
    </div>
</div>
