<?php
/**
 * Ferreira & Sá Hub — Automações WhatsApp
 * Horário de atendimento + toggles de mensagens automáticas.
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
if (!has_min_role('gestao')) {
    flash_set('error', 'Acesso restrito.');
    redirect(url('modules/whatsapp/'));
}

$pdo = db();
$pageTitle = 'Automações WhatsApp';

// Garantir tabela configuracoes
try { $pdo->query("SELECT 1 FROM configuracoes LIMIT 1"); }
catch (Exception $e) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS configuracoes (
        chave VARCHAR(80) PRIMARY KEY,
        valor TEXT,
        atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// Defaults
$defaults = array(
    'zapi_horario_inicio'        => '10',           // 10h
    'zapi_horario_fim'           => '18',           // 18h
    'zapi_dias_uteis'            => '1,2,3,4,5',    // seg-sex
    'zapi_auto_fora_horario'     => '1',
    'zapi_auto_fora_horario_tpl' => 'Fora do horário',
    'zapi_auto_boasvindas'       => '0',
    'zapi_auto_boasvindas_tpl'   => 'Boas-vindas Comercial',
    'zapi_auto_boasvindas_canal' => '21',
    'zapi_auto_doc_24'           => '0',
    'zapi_auto_doc_24_tpl'       => 'Confirmação de documentos',
    'zapi_signature_on'          => '0',
    'zapi_signature_format'      => '— {{atendente}}',
);

// ── POST ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
    $up = $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)");

    // Horário
    $up->execute(array('zapi_horario_inicio', (int)($_POST['horario_inicio'] ?? 10)));
    $up->execute(array('zapi_horario_fim',    (int)($_POST['horario_fim']    ?? 18)));

    $dias = array();
    foreach (array('1','2','3','4','5','6','7') as $d) if (!empty($_POST['dia_'.$d])) $dias[] = $d;
    if (empty($dias)) $dias = array('1','2','3','4','5');
    $up->execute(array('zapi_dias_uteis', implode(',', $dias)));

    // Toggles + templates
    $up->execute(array('zapi_auto_fora_horario',     !empty($_POST['auto_fora_horario']) ? '1' : '0'));
    $up->execute(array('zapi_auto_fora_horario_tpl', trim($_POST['auto_fora_horario_tpl'] ?? '')));

    $up->execute(array('zapi_auto_boasvindas',       !empty($_POST['auto_boasvindas']) ? '1' : '0'));
    $up->execute(array('zapi_auto_boasvindas_tpl',   trim($_POST['auto_boasvindas_tpl'] ?? '')));
    $up->execute(array('zapi_auto_boasvindas_canal', in_array($_POST['auto_boasvindas_canal'] ?? '21', array('21','24','ambos'), true) ? $_POST['auto_boasvindas_canal'] : '21'));

    $up->execute(array('zapi_auto_doc_24',           !empty($_POST['auto_doc_24']) ? '1' : '0'));
    $up->execute(array('zapi_auto_doc_24_tpl',       trim($_POST['auto_doc_24_tpl'] ?? '')));

    $up->execute(array('zapi_signature_on',     !empty($_POST['signature_on']) ? '1' : '0'));
    $up->execute(array('zapi_signature_format', trim($_POST['signature_format'] ?? '— {{atendente}}')));

    audit_log('zapi_automacoes_salvar', 'configuracoes', 0);
    flash_set('success', 'Automações salvas.');
    redirect(module_url('whatsapp', 'automacoes.php'));
}

// ── Ler config atual ────────────────────────────────────
$cfg = $defaults;
foreach ($pdo->query("SELECT chave, valor FROM configuracoes WHERE chave LIKE 'zapi_%'")->fetchAll() as $r) {
    if (array_key_exists($r['chave'], $cfg)) $cfg[$r['chave']] = $r['valor'];
}
$diasArr = explode(',', $cfg['zapi_dias_uteis']);

// Lista de templates ativos pra dropdown
$templates = $pdo->query("SELECT nome, canal, categoria FROM zapi_templates WHERE ativo = 1 ORDER BY nome")->fetchAll();

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.aut-card { background:#fff;border:1px solid var(--border);border-radius:14px;padding:1.2rem;margin-bottom:1rem; }
.aut-card h3 { margin:0 0 .5rem;color:var(--petrol-900);font-size:1rem; }
.aut-row { display:grid;grid-template-columns:1fr 2fr;gap:.8rem;align-items:center;margin-bottom:.5rem; }
.aut-row label { font-weight:600;font-size:.85rem; }
.aut-dias { display:flex;gap:.4rem;flex-wrap:wrap; }
.aut-dias label { background:#f3f4f6;padding:6px 12px;border-radius:8px;cursor:pointer;font-size:.8rem;font-weight:500; }
.aut-dias input:checked + span { color:var(--rose);font-weight:700; }
.aut-toggle-row { display:flex;align-items:center;gap:.8rem;margin-bottom:.5rem; }
.aut-hint { font-size:.72rem;color:var(--text-muted);margin:0 0 .5rem; }
</style>

<a href="<?= module_url('whatsapp') ?>" class="btn btn-outline btn-sm mb-2">&larr; Voltar ao WhatsApp</a>

<div style="display:flex;align-items:center;gap:.5rem;margin-bottom:1rem;">
    <h1 style="margin:0;">⚙️ Automações WhatsApp</h1>
    <div style="margin-left:auto;">
        <a href="<?= module_url('whatsapp', 'templates.php') ?>" class="btn btn-outline btn-sm">📋 Templates →</a>
    </div>
</div>

<form method="POST">
    <?= csrf_input() ?>

    <div class="aut-card">
        <h3>🕒 Horário de Atendimento</h3>
        <p class="aut-hint">Fora desse horário, a mensagem automática "Fora do horário" é enviada (se ativada abaixo).</p>
        <div class="aut-row">
            <label>Hora de início</label>
            <div><input type="number" name="horario_inicio" min="0" max="23" value="<?= e($cfg['zapi_horario_inicio']) ?>" class="form-control" style="width:100px;"> <span class="text-muted text-sm">h (ex: 10 = 10h00)</span></div>
        </div>
        <div class="aut-row">
            <label>Hora de fim</label>
            <div><input type="number" name="horario_fim" min="1" max="24" value="<?= e($cfg['zapi_horario_fim']) ?>" class="form-control" style="width:100px;"> <span class="text-muted text-sm">h (ex: 18 = 18h00)</span></div>
        </div>
        <div class="aut-row">
            <label>Dias úteis</label>
            <div class="aut-dias">
                <?php
                $diasLabels = array('1'=>'Seg','2'=>'Ter','3'=>'Qua','4'=>'Qui','5'=>'Sex','6'=>'Sáb','7'=>'Dom');
                foreach ($diasLabels as $k => $l):
                ?>
                    <label><input type="checkbox" name="dia_<?= $k ?>" value="1" <?= in_array($k, $diasArr, true) ? 'checked' : '' ?>> <span><?= $l ?></span></label>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="aut-card">
        <h3>🌙 Fora do Horário</h3>
        <p class="aut-hint">Envia automaticamente um template quando um cliente manda mensagem fora do horário comercial.</p>
        <div class="aut-toggle-row">
            <label><input type="checkbox" name="auto_fora_horario" value="1" <?= $cfg['zapi_auto_fora_horario'] === '1' ? 'checked' : '' ?>> Ativar envio automático</label>
        </div>
        <div class="aut-row">
            <label>Template a enviar</label>
            <select name="auto_fora_horario_tpl" class="form-control">
                <?php foreach ($templates as $t): ?>
                    <option value="<?= e($t['nome']) ?>" <?= $cfg['zapi_auto_fora_horario_tpl'] === $t['nome'] ? 'selected' : '' ?>>
                        <?= e($t['nome']) ?> (<?= e($t['canal']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="aut-card">
        <h3>👋 Boas-vindas ao Primeiro Contato</h3>
        <p class="aut-hint">Quando um número NOVO (primeira vez) manda mensagem, envia um template de recepção.</p>
        <div class="aut-toggle-row">
            <label><input type="checkbox" name="auto_boasvindas" value="1" <?= $cfg['zapi_auto_boasvindas'] === '1' ? 'checked' : '' ?>> Ativar envio automático</label>
        </div>
        <div class="aut-row">
            <label>Canal</label>
            <select name="auto_boasvindas_canal" class="form-control" style="max-width:240px;">
                <option value="21"    <?= $cfg['zapi_auto_boasvindas_canal'] === '21'    ? 'selected' : '' ?>>Apenas DDD 21</option>
                <option value="24"    <?= $cfg['zapi_auto_boasvindas_canal'] === '24'    ? 'selected' : '' ?>>Apenas DDD 24</option>
                <option value="ambos" <?= $cfg['zapi_auto_boasvindas_canal'] === 'ambos' ? 'selected' : '' ?>>Ambos</option>
            </select>
        </div>
        <div class="aut-row">
            <label>Template a enviar</label>
            <select name="auto_boasvindas_tpl" class="form-control">
                <?php foreach ($templates as $t): ?>
                    <option value="<?= e($t['nome']) ?>" <?= $cfg['zapi_auto_boasvindas_tpl'] === $t['nome'] ? 'selected' : '' ?>>
                        <?= e($t['nome']) ?> (<?= e($t['canal']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="aut-card">
        <h3>📎 Confirmação de Documentos (DDD 24)</h3>
        <p class="aut-hint">Quando um cliente envia um arquivo no DDD 24, responde automaticamente confirmando o recebimento.</p>
        <div class="aut-toggle-row">
            <label><input type="checkbox" name="auto_doc_24" value="1" <?= $cfg['zapi_auto_doc_24'] === '1' ? 'checked' : '' ?>> Ativar confirmação automática</label>
        </div>
        <div class="aut-row">
            <label>Template a enviar</label>
            <select name="auto_doc_24_tpl" class="form-control">
                <?php foreach ($templates as $t): ?>
                    <option value="<?= e($t['nome']) ?>" <?= $cfg['zapi_auto_doc_24_tpl'] === $t['nome'] ? 'selected' : '' ?>>
                        <?= e($t['nome']) ?> (<?= e($t['canal']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="aut-card">
        <h3>✍️ Assinatura do Atendente</h3>
        <p class="aut-hint">Quando ligada, toda mensagem que você enviar pelo Hub sai com a assinatura no final — assim o cliente sabe quem está falando. Use <code>{{atendente}}</code> pra inserir o nome do usuário logado.</p>
        <div class="aut-toggle-row">
            <label><input type="checkbox" name="signature_on" value="1" <?= $cfg['zapi_signature_on'] === '1' ? 'checked' : '' ?>> Ativar assinatura automática nas mensagens enviadas</label>
        </div>
        <div class="aut-row">
            <label>Formato</label>
            <input type="text" name="signature_format" value="<?= e($cfg['zapi_signature_format']) ?>" class="form-control" placeholder="— {{atendente}}">
        </div>
        <p class="aut-hint">Exemplos: <code>— {{atendente}}</code> · <code>— {{atendente}}, Ferreira & Sá</code> · <code>Atenciosamente,\n{{atendente}}</code></p>
    </div>

    <button type="submit" class="btn btn-primary">💾 Salvar automações</button>
</form>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
