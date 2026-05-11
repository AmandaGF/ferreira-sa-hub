<?php
/**
 * Pagina publica: formulario de Seguro de Vida — colaboradora preenche apenas
 * os dados que NAO temos no cadastro (estado civil, peso, altura, fumante,
 * esporte). Demais campos vem auto-preenchidos read-only de colaboradores_onboarding.
 *
 * Pedido pela Amanda 11/05/2026. Substitui o form do Office Forms.
 *
 * Apos envio: notifica admins, agradecimento, permite editar a resposta enviada.
 */
require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/database.php';
require_once __DIR__ . '/../../core/functions_notify.php';

@session_start();

$pdo = db();
$token = isset($_GET['token']) ? trim($_GET['token']) : '';
if (!$token || !preg_match('/^[a-f0-9]{16,48}$/', $token)) {
    http_response_code(404); exit('Link invalido.');
}

$st = $pdo->prepare("SELECT * FROM colaboradores_onboarding WHERE token = ? AND status != 'arquivado'");
$st->execute(array($token));
$reg = $st->fetch();
if (!$reg) { http_response_code(404); exit('Link invalido.'); }

$sessKey = 'onb_auth_' . $token;
if (empty($_SESSION[$sessKey])) {
    header('Location: ./?token=' . urlencode($token)); exit;
}

// Self-heal: coluna estado_civil em colaboradores_onboarding (nao tinha)
try { $pdo->exec("ALTER TABLE colaboradores_onboarding ADD COLUMN estado_civil VARCHAR(30) NULL"); } catch (Exception $e) {}

// Self-heal: tabela dedicada pras respostas do seguro de vida
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS colaboradores_seguro_vida (
        id INT AUTO_INCREMENT PRIMARY KEY,
        colaborador_id INT NOT NULL,
        peso DECIMAL(5,2) NULL,
        altura DECIMAL(4,2) NULL,
        fumante TINYINT(1) NULL,
        pratica_esporte TEXT NULL,
        observacoes TEXT NULL,
        enviado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
        atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_colab (colaborador_id),
        INDEX idx_data (enviado_em)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

// Carrega resposta anterior (se ja preencheu)
$resp = null;
try {
    $stR = $pdo->prepare("SELECT * FROM colaboradores_seguro_vida WHERE colaborador_id = ?");
    $stR->execute(array($reg['id']));
    $resp = $stR->fetch() ?: null;
} catch (Exception $e) {}

// Recarrega estado_civil apos potencial self-heal
$reg = $pdo->prepare("SELECT * FROM colaboradores_onboarding WHERE id = ?");
$reg->execute(array($st->fetch(PDO::FETCH_ASSOC) ? null : null)); // dummy — vou refazer
$stReg = $pdo->prepare("SELECT * FROM colaboradores_onboarding WHERE token = ?");
$stReg->execute(array($token));
$reg = $stReg->fetch();

$msg = null;
$erro = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enviar_seguro'])) {
    $estadoCivil = trim($_POST['estado_civil'] ?? '');
    $pesoRaw = trim($_POST['peso'] ?? '');
    $alturaRaw = trim($_POST['altura'] ?? '');
    $fumante = isset($_POST['fumante']) ? ($_POST['fumante'] === '1' ? 1 : 0) : null;
    $esporte = trim($_POST['pratica_esporte'] ?? '');
    $obs = trim($_POST['observacoes'] ?? '');

    // Normaliza peso/altura — aceita vírgula brasileira
    $peso = $pesoRaw !== '' ? (float)str_replace(array(',', ' kg', ' Kg'), array('.', '', ''), $pesoRaw) : null;
    $altura = $alturaRaw !== '' ? (float)str_replace(array(',', ' m', ' M', 'cm'), array('.', '', '', ''), $alturaRaw) : null;
    // Se altura veio em cm (ex 175), converte pra metros
    if ($altura !== null && $altura > 3) $altura = $altura / 100;

    if (!$estadoCivil) $erro = 'Selecione seu estado civil.';
    elseif ($peso === null || $peso < 30 || $peso > 300) $erro = 'Informe um peso válido (em kg).';
    elseif ($altura === null || $altura < 1.0 || $altura > 2.5) $erro = 'Informe uma altura válida (em metros — ex: 1,75).';
    elseif ($fumante === null) $erro = 'Marque se é fumante ou não.';

    if (!$erro) {
        try {
            $pdo->beginTransaction();
            // Atualiza estado_civil no cadastro do colaborador (ficou aqui pra futuras coisas)
            $pdo->prepare("UPDATE colaboradores_onboarding SET estado_civil = ? WHERE id = ?")
                ->execute(array($estadoCivil, $reg['id']));

            // Upsert na tabela do seguro
            $pdo->prepare("INSERT INTO colaboradores_seguro_vida
                           (colaborador_id, peso, altura, fumante, pratica_esporte, observacoes)
                           VALUES (?, ?, ?, ?, ?, ?)
                           ON DUPLICATE KEY UPDATE
                              peso = VALUES(peso),
                              altura = VALUES(altura),
                              fumante = VALUES(fumante),
                              pratica_esporte = VALUES(pratica_esporte),
                              observacoes = VALUES(observacoes)")
                ->execute(array($reg['id'], $peso, $altura, $fumante, $esporte ?: null, $obs ?: null));
            $pdo->commit();

            // Notifica admins
            try {
                if (function_exists('notify_admins')) {
                    notify_admins(
                        'Seguro de Vida — dados pra contratação',
                        $reg['nome_completo'] . ' enviou os dados para a contratação do Seguro de Vida (benefício do contrato).',
                        'success',
                        url('modules/admin/onboarding.php?busca=' . urlencode($reg['nome_completo'])),
                        '🛡️'
                    );
                }
            } catch (Exception $e) { /* não bloqueia */ }

            $msg = '✓ Suas informações foram enviadas! Já encaminhamos pra contratação do seu Seguro de Vida.';

            // Recarrega resposta atualizada
            $stR = $pdo->prepare("SELECT * FROM colaboradores_seguro_vida WHERE colaborador_id = ?");
            $stR->execute(array($reg['id']));
            $resp = $stR->fetch() ?: null;
            // Atualiza local
            $stReg->execute(array($token));
            $reg = $stReg->fetch();
        } catch (Exception $e) {
            try { $pdo->rollBack(); } catch (Exception $e2) {}
            $erro = 'Erro ao salvar: ' . $e->getMessage();
        }
    }
}

// Helpers de formatacao pros campos read-only
function _segValor($v) { return $v !== null && $v !== '' ? htmlspecialchars($v) : '<span style="color:#9ca3af;">(não cadastrado)</span>'; }
function _segCpf($c) { $d = preg_replace('/\D/', '', (string)$c); if (strlen($d) !== 11) return _segValor($c); return substr($d,0,3).'.'.substr($d,3,3).'.'.substr($d,6,3).'-'.substr($d,9,2); }
function _segData($d) { if (!$d) return _segValor(null); $ts = strtotime($d); return $ts ? date('d/m/Y', $ts) : _segValor($d); }
function _segValor_($v) { return $v !== null && $v !== '' ? htmlspecialchars($v) : ''; }

$primeiroNome = explode(' ', $reg['nome_completo'])[0];
$estadoCivilAtual = $reg['estado_civil'] ?? '';
$ehFumante = $resp ? (int)$resp['fumante'] : null;
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Seguro de Vida — Ferreira & Sá Advocacia</title>
<style>
:root { --petrol:#0f2140; --cobre:#B87333; --nude:#F5EDE3; --cobre-light:#E8D4B8; --border:#e5e7eb; --text:#374151; --text-muted:#6b7280; }
* { box-sizing: border-box; }
body { margin:0; font-family:'Inter','Segoe UI',sans-serif; background:linear-gradient(180deg, var(--nude) 0%, #fff 300px); color:var(--text); line-height:1.55; }
.sv-wrap { max-width:720px; margin:0 auto; padding:1.5rem 1rem 3rem; }
.sv-hdr { text-align:center; margin-bottom:1.5rem; }
.sv-hdr h1 { font-family:'Playfair Display',Georgia,serif; font-size:1.8rem; color:var(--petrol); margin:0 0 .3rem; }
.sv-hdr .sub { color:var(--text-muted); font-size:.95rem; }
.sv-back { color:var(--petrol); text-decoration:none; font-size:.85rem; display:inline-flex; align-items:center; gap:4px; margin-bottom:1rem; }
.sv-back:hover { color:var(--cobre); }
.sv-card { background:#fff; border:1px solid var(--border); border-radius:14px; padding:1.5rem; margin-bottom:1.25rem; box-shadow:0 1px 4px rgba(0,0,0,.04); }
.sv-card h2 { font-family:'Playfair Display',serif; font-size:1.15rem; color:var(--petrol); margin:0 0 .85rem; padding-bottom:.4rem; border-bottom:2px solid var(--cobre-light); }
.sv-card .sub-h { font-size:.78rem; color:var(--text-muted); margin-bottom:.85rem; }
.sv-row { display:grid; grid-template-columns:1fr 1fr; gap:.85rem 1.25rem; margin-bottom:.85rem; }
.sv-fg label { font-size:.7rem; font-weight:700; color:var(--text-muted); display:block; margin-bottom:.2rem; text-transform:uppercase; letter-spacing:.4px; }
.sv-fg input, .sv-fg select, .sv-fg textarea { width:100%; padding:.6rem .75rem; border:1px solid var(--border); border-radius:8px; font-family:inherit; font-size:.92rem; color:var(--text); }
.sv-fg input:focus, .sv-fg select:focus, .sv-fg textarea:focus { outline:none; border-color:var(--cobre); box-shadow:0 0 0 3px rgba(184,115,51,.15); }
.sv-fg input[readonly] { background:#f9fafb; color:var(--text-muted); cursor:default; }
.sv-fg textarea { resize:vertical; min-height:60px; }
.sv-radio-group { display:flex; gap:.75rem; margin-top:.3rem; }
.sv-radio { flex:1; padding:.6rem .85rem; border:1.5px solid var(--border); border-radius:8px; cursor:pointer; display:flex; align-items:center; gap:.5rem; font-size:.92rem; user-select:none; transition:all .15s; }
.sv-radio:hover { border-color:var(--cobre); background:var(--nude); }
.sv-radio input { margin:0; cursor:pointer; }
.sv-radio.sel { background:var(--petrol); color:#fff; border-color:var(--petrol); }
.sv-disclaimer { background:#fef3c7; border-left:4px solid #f59e0b; border-radius:6px; padding:.85rem 1rem; font-size:.82rem; color:#92400e; margin-bottom:1.25rem; line-height:1.55; }
.sv-disclaimer strong { color:#78350f; }
.sv-btn { background:var(--petrol); color:#fff; border:none; border-radius:10px; padding:.85rem 2rem; font-size:1rem; font-weight:700; font-family:inherit; cursor:pointer; width:100%; transition:opacity .15s; }
.sv-btn:hover { opacity:.92; }
.sv-msg { padding:.85rem 1.1rem; border-radius:8px; margin-bottom:1.25rem; font-size:.92rem; }
.sv-msg.ok { background:#dcfce7; color:#166534; border:1px solid #86efac; }
.sv-msg.err { background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; }
.sv-info-grid { display:grid; grid-template-columns:1fr 1fr; gap:.55rem 1rem; font-size:.86rem; }
.sv-info-grid > div { padding:.45rem .65rem; background:#f9fafb; border-radius:6px; }
.sv-info-grid > div span:first-child { font-size:.68rem; color:var(--text-muted); display:block; text-transform:uppercase; letter-spacing:.4px; font-weight:600; }
.sv-info-grid > div span:last-child { color:var(--petrol); font-weight:600; }
@media (max-width: 560px) {
    .sv-row, .sv-info-grid { grid-template-columns: 1fr; }
}
</style>
</head>
<body>

<div class="sv-wrap">
    <a href="./?token=<?= htmlspecialchars($token) ?>" class="sv-back">← Voltar ao portal</a>

    <div class="sv-hdr">
        <h1>🛡️ Seguro de Vida</h1>
        <div class="sub">Olá <?= htmlspecialchars($primeiroNome) ?>! O Seguro de Vida é um benefício incluso no seu contrato com o escritório. Pra fazer a contratação em seu nome, precisamos de algumas informações suas.</div>
    </div>

    <?php if ($msg): ?>
        <div class="sv-msg ok"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>
    <?php if ($erro): ?>
        <div class="sv-msg err">⚠ <?= htmlspecialchars($erro) ?></div>
    <?php endif; ?>

    <div class="sv-disclaimer">
        <strong>📋 Informações importantes:</strong> esses dados serão enviados à seguradora
        exclusivamente para a <strong>contratação do seu Seguro de Vida</strong> (benefício
        incluso no seu contrato com o escritório), garantindo total sigilo e segurança.
        A veracidade das informações é fundamental — ela implica aceite ou recusa do risco
        pela seguradora e pagamento (ou não) da indenização em caso de sinistro
        (Lei 15.040). Em caso de dúvidas, fale com a equipe.
    </div>

    <!-- Bloco 1: dados já cadastrados (read-only) -->
    <div class="sv-card">
        <h2>📇 Seus dados cadastrados</h2>
        <div class="sub-h">Esses dados vêm do seu cadastro no Hub. Se algum estiver desatualizado, avise a equipe.</div>
        <div class="sv-info-grid">
            <div><span>Nome completo</span><span><?= _segValor($reg['nome_completo']) ?></span></div>
            <div><span>CPF</span><span><?= _segCpf($reg['cpf']) ?></span></div>
            <div><span>Data de nascimento</span><span><?= _segData($reg['data_nascimento']) ?></span></div>
            <div><span>E-mail</span><span><?= _segValor($reg['email_institucional']) ?></span></div>
            <div><span>Telefone</span><span><?= _segValor($reg['telefone_whatsapp']) ?></span></div>
            <div><span>Cargo / Ocupação</span><span><?= _segValor($reg['cargo']) ?></span></div>
            <div><span>Tipo de contrato</span><span><?= _segValor($reg['tipo_remuneracao']) ?></span></div>
            <div><span>Renda mensal</span><span><?= $reg['valor_remuneracao'] ? 'R$ ' . number_format((float)$reg['valor_remuneracao'], 2, ',', '.') : _segValor(null) ?></span></div>
        </div>
    </div>

    <!-- Bloco 2: formulário com os dados que faltam -->
    <form method="POST">
        <input type="hidden" name="enviar_seguro" value="1">

        <div class="sv-card">
            <h2>📝 Informações para a contratação</h2>
            <div class="sub-h">Campos com <strong style="color:#dc2626;">*</strong> são obrigatórios.</div>

            <div class="sv-row">
                <div class="sv-fg">
                    <label>Estado civil <span style="color:#dc2626;">*</span></label>
                    <select name="estado_civil" required>
                        <option value="">Selecione...</option>
                        <?php foreach (array('Solteiro(a)','Casado(a) ou União Estável','Divorciado(a)','Separado(a)','Viúvo(a)') as $opt): ?>
                        <option value="<?= htmlspecialchars($opt) ?>" <?= $estadoCivilAtual === $opt ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="sv-fg">
                    <label>É fumante? <span style="color:#dc2626;">*</span></label>
                    <div class="sv-radio-group">
                        <label class="sv-radio <?= $ehFumante === 0 ? 'sel' : '' ?>">
                            <input type="radio" name="fumante" value="0" <?= $ehFumante === 0 ? 'checked' : '' ?> required> Não
                        </label>
                        <label class="sv-radio <?= $ehFumante === 1 ? 'sel' : '' ?>">
                            <input type="radio" name="fumante" value="1" <?= $ehFumante === 1 ? 'checked' : '' ?>> Sim
                        </label>
                    </div>
                </div>
            </div>

            <div class="sv-row">
                <div class="sv-fg">
                    <label>Peso (kg) <span style="color:#dc2626;">*</span></label>
                    <input type="text" name="peso" placeholder="Ex: 68,5" value="<?= $resp && $resp['peso'] ? str_replace('.', ',', rtrim(rtrim($resp['peso'], '0'), '.')) : '' ?>" required inputmode="decimal">
                </div>
                <div class="sv-fg">
                    <label>Altura (m) <span style="color:#dc2626;">*</span></label>
                    <input type="text" name="altura" placeholder="Ex: 1,72" value="<?= $resp && $resp['altura'] ? str_replace('.', ',', rtrim(rtrim($resp['altura'], '0'), '.')) : '' ?>" required inputmode="decimal">
                </div>
            </div>

            <div class="sv-fg" style="margin-bottom:.85rem;">
                <label>Pratica esporte? Se sim, qual?</label>
                <input type="text" name="pratica_esporte" placeholder="Ex: Corrida (3x/semana), Crossfit, Futebol amador..." value="<?= htmlspecialchars($resp['pratica_esporte'] ?? '') ?>">
            </div>

            <div class="sv-fg">
                <label>Observações (opcional)</label>
                <textarea name="observacoes" placeholder="Qualquer informação adicional que considere relevante..."><?= htmlspecialchars($resp['observacoes'] ?? '') ?></textarea>
            </div>
        </div>

        <button type="submit" class="sv-btn">
            <?= $resp ? '✓ Atualizar minhas informações' : '🛡️ Enviar dados do meu seguro' ?>
        </button>
    </form>

    <?php if ($resp): ?>
        <div style="text-align:center;margin-top:1rem;font-size:.78rem;color:var(--text-muted);">
            Última atualização: <?= date('d/m/Y \à\s H:i', strtotime($resp['atualizado_em'] ?? $resp['enviado_em'])) ?>
        </div>
    <?php endif; ?>
</div>

<script>
// Realça o radio selecionado visualmente
document.querySelectorAll('.sv-radio input[type=radio]').forEach(function(r){
    r.addEventListener('change', function(){
        document.querySelectorAll('.sv-radio').forEach(function(lbl){
            var inp = lbl.querySelector('input');
            lbl.classList.toggle('sel', inp && inp.checked);
        });
    });
});
</script>

</body>
</html>
