<?php
/**
 * Admin — Respostas do formulario de Seguro de Vida dos colaboradores.
 * Lista todos os colaboradores que preencheram os dados pra contratacao do
 * seguro (beneficio incluso no contrato), com botao 'Copiar tudo' que
 * formata o pacote completo pra colar no email/WhatsApp da corretora.
 * Pedido pela Amanda 11/05/2026.
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
require_min_role('admin');

$pdo = db();
$pageTitle = 'Seguro de Vida — Dados dos Colaboradores';

// Self-heal: garante tabela (idempotente)
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
        UNIQUE KEY uniq_colab (colaborador_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE colaboradores_onboarding ADD COLUMN estado_civil VARCHAR(30) NULL"); } catch (Exception $e) {}

// Busca todos colaboradores que preencheram + dados do onboarding
$rows = $pdo->query(
    "SELECT sv.*, co.nome_completo, co.cpf, co.data_nascimento, co.email_institucional,
            co.telefone_whatsapp, co.cargo, co.tipo_remuneracao, co.valor_remuneracao,
            co.estado_civil, co.foto_path, co.token
     FROM colaboradores_seguro_vida sv
     LEFT JOIN colaboradores_onboarding co ON co.id = sv.colaborador_id
     ORDER BY sv.atualizado_em DESC"
)->fetchAll();

// Pendentes — colaboradores ativos que ainda nao preencheram
$pendentes = $pdo->query(
    "SELECT co.id, co.nome_completo, co.token, co.created_at
     FROM colaboradores_onboarding co
     LEFT JOIN colaboradores_seguro_vida sv ON sv.colaborador_id = co.id
     WHERE co.status NOT IN ('arquivado') AND sv.id IS NULL
     ORDER BY co.nome_completo ASC"
)->fetchAll();

function _segFmt($v) { return $v !== null && $v !== '' ? htmlspecialchars($v) : '<span style="color:#9ca3af;">—</span>'; }
function _segCpf($c) { $d = preg_replace('/\D/', '', (string)$c); if (strlen($d) !== 11) return _segFmt($c); return substr($d,0,3).'.'.substr($d,3,3).'.'.substr($d,6,3).'-'.substr($d,9,2); }
function _segDt($d) { if (!$d) return _segFmt(null); $ts = strtotime($d); return $ts ? date('d/m/Y', $ts) : _segFmt($d); }
function _segDtH($d) { if (!$d) return _segFmt(null); $ts = strtotime($d); return $ts ? date('d/m/Y \à\s H:i', $ts) : _segFmt($d); }
function _segPeso($p) { if ($p === null || $p === '') return '—'; return rtrim(rtrim(number_format((float)$p, 2, ',', '.'), '0'), ',') . ' kg'; }
function _segAlt($a) { if ($a === null || $a === '') return '—'; return number_format((float)$a, 2, ',', '.') . ' m'; }
function _segRenda($r) { if ($r === null || $r === '') return '—'; return 'R$ ' . number_format((float)$r, 2, ',', '.'); }

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.sv-adm-wrap { padding: 1rem 1.25rem; max-width: 1100px; margin: 0 auto; }
.sv-adm-hdr { display:flex; justify-content:space-between; align-items:flex-end; flex-wrap:wrap; gap:1rem; margin-bottom:1.5rem; padding-bottom:.75rem; border-bottom:2px solid var(--border); }
.sv-adm-hdr h1 { margin:0; font-size:1.5rem; font-family:'Playfair Display',serif; color:var(--petrol-900); }
.sv-adm-hdr .sub { color:var(--text-muted); font-size:.85rem; margin-top:.2rem; }
.sv-adm-totais { display:flex; gap:.75rem; flex-wrap:wrap; }
.sv-tot { background:var(--bg-card); border:1px solid var(--border); border-radius:10px; padding:.55rem 1rem; min-width:120px; }
.sv-tot .v { font-size:1.4rem; font-weight:700; color:var(--petrol-900); }
.sv-tot .l { font-size:.7rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:.4px; }

.sv-card { background:var(--bg-card); border:1px solid var(--border); border-radius:12px; margin-bottom:1rem; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,.04); }
.sv-card-hdr { background:linear-gradient(135deg,#eff6ff,#dbeafe); padding:.75rem 1rem; display:flex; align-items:center; gap:.75rem; border-bottom:1px solid #bfdbfe; }
.sv-card-hdr h3 { margin:0; font-size:1rem; color:#0f2140; flex:1; }
.sv-card-hdr .data-resp { font-size:.7rem; color:#1e3a8a; }
.sv-card-body { padding:1rem; display:grid; grid-template-columns:1fr 1fr; gap:1rem; }
.sv-bloco h4 { font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:var(--text-muted); margin:0 0 .55rem; padding-bottom:.3rem; border-bottom:1px solid var(--border); }
.sv-info { display:grid; grid-template-columns:1fr 1fr; gap:.25rem .85rem; font-size:.82rem; }
.sv-info > div { padding:.2rem 0; }
.sv-info > div > span:first-child { display:block; font-size:.66rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:.3px; }
.sv-info > div > span:last-child { display:block; color:var(--petrol-900); font-weight:600; }
.sv-card-ft { padding:.65rem 1rem; background:#fafafa; border-top:1px solid var(--border); display:flex; gap:.5rem; flex-wrap:wrap; }
.sv-btn { background:var(--petrol-900); color:#fff; border:none; padding:.4rem .85rem; border-radius:6px; font-size:.78rem; font-weight:600; cursor:pointer; font-family:inherit; }
.sv-btn.alt { background:#fff; color:var(--petrol-900); border:1px solid var(--border); }
.sv-pendentes-card { background:#fef3c7; border:1px solid #fbbf24; border-left:4px solid #d97706; border-radius:10px; padding:.85rem 1rem; margin-bottom:1.5rem; }
.sv-pendentes-card h3 { margin:0 0 .4rem; font-size:.95rem; color:#92400e; }
.sv-pend-list { display:flex; gap:.4rem; flex-wrap:wrap; }
.sv-pend-tag { background:#fff; border:1px solid #fbbf24; padding:.25rem .7rem; border-radius:99px; font-size:.78rem; color:#92400e; font-weight:600; }
.sv-empty { text-align:center; padding:3rem; color:var(--text-muted); background:var(--bg-card); border-radius:12px; border:1px dashed var(--border); }
@media (max-width:740px) { .sv-card-body, .sv-info { grid-template-columns:1fr; } }
</style>

<div class="sv-adm-wrap">
    <div class="sv-adm-hdr">
        <div>
            <h1>🛡️ Seguro de Vida — Dados dos Colaboradores</h1>
            <div class="sub">Informações preenchidas pelos colaboradores para a <strong>contratação</strong> do Seguro de Vida (benefício do contrato).</div>
        </div>
        <div class="sv-adm-totais">
            <div class="sv-tot"><div class="v"><?= count($rows) ?></div><div class="l">Preenchidos</div></div>
            <div class="sv-tot"><div class="v"><?= count($pendentes) ?></div><div class="l">Pendentes</div></div>
        </div>
    </div>

    <?php if ($pendentes): ?>
    <div class="sv-pendentes-card">
        <h3>⏳ <?= count($pendentes) ?> colaborador(es) ainda não preencheu(ram)</h3>
        <div class="sv-pend-list">
            <?php foreach ($pendentes as $p): ?>
            <a href="<?= e(url('publico/onboarding/seguro_vida.php?token=' . $p['token'])) ?>" target="_blank" class="sv-pend-tag" title="Abrir o form pra ver/preencher como o colaborador" style="text-decoration:none;">
                <?= e($p['nome_completo']) ?> →
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!$rows): ?>
        <div class="sv-empty">📭 Ninguém preencheu ainda. Aguardando os colaboradores enviarem seus dados pelo portal de boas-vindas.</div>
    <?php else: ?>
        <?php foreach ($rows as $r):
            $idCard = 'svc-' . (int)$r['colaborador_id'];
            // Monta o texto pronto pra copiar e colar no e-mail/WhatsApp da corretora
            $textoCopiar = "═══ DADOS PARA CONTRATAÇÃO DO SEGURO DE VIDA ═══\n\n";
            $textoCopiar .= "Nome completo: " . ($r['nome_completo'] ?: '—') . "\n";
            $textoCopiar .= "CPF: " . (function_exists('_segCpfPlain') ? '' : '') . preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', preg_replace('/\D/', '', $r['cpf'] ?: '')) . "\n";
            $textoCopiar .= "Data de nascimento: " . ($r['data_nascimento'] ? date('d/m/Y', strtotime($r['data_nascimento'])) : '—') . "\n";
            $textoCopiar .= "Estado civil: " . ($r['estado_civil'] ?: '—') . "\n";
            $textoCopiar .= "E-mail: " . ($r['email_institucional'] ?: '—') . "\n";
            $textoCopiar .= "Telefone: " . ($r['telefone_whatsapp'] ?: '—') . "\n";
            $textoCopiar .= "Cargo / Ocupação: " . ($r['cargo'] ?: '—') . "\n";
            $textoCopiar .= "Tipo de contrato: " . ($r['tipo_remuneracao'] ?: '—') . "\n";
            $textoCopiar .= "Renda mensal: " . ($r['valor_remuneracao'] ? 'R$ ' . number_format((float)$r['valor_remuneracao'], 2, ',', '.') : '—') . "\n";
            $textoCopiar .= "\n--- Informações para o risco ---\n";
            $textoCopiar .= "Peso: " . _segPeso($r['peso']) . "\n";
            $textoCopiar .= "Altura: " . _segAlt($r['altura']) . "\n";
            $textoCopiar .= "Fumante: " . ($r['fumante'] === '1' || $r['fumante'] === 1 ? 'Sim' : ($r['fumante'] === '0' || $r['fumante'] === 0 ? 'Não' : '—')) . "\n";
            $textoCopiar .= "Pratica esporte: " . ($r['pratica_esporte'] ?: '—') . "\n";
            if (!empty($r['observacoes'])) $textoCopiar .= "Observações: " . $r['observacoes'] . "\n";
            $textoCopiar .= "\nEnviado em: " . date('d/m/Y \à\s H:i', strtotime($r['atualizado_em'] ?: $r['enviado_em']));
        ?>
        <div class="sv-card" id="<?= $idCard ?>">
            <div class="sv-card-hdr">
                <span style="font-size:1.4rem;">🛡️</span>
                <h3><?= e($r['nome_completo']) ?></h3>
                <span class="data-resp">📅 <?= _segDtH($r['atualizado_em'] ?: $r['enviado_em']) ?></span>
            </div>
            <div class="sv-card-body">
                <div class="sv-bloco">
                    <h4>📇 Dados pessoais (do cadastro)</h4>
                    <div class="sv-info">
                        <div><span>CPF</span><span><?= _segCpf($r['cpf']) ?></span></div>
                        <div><span>Nascimento</span><span><?= _segDt($r['data_nascimento']) ?></span></div>
                        <div><span>Estado civil</span><span><?= _segFmt($r['estado_civil']) ?></span></div>
                        <div><span>E-mail</span><span><?= _segFmt($r['email_institucional']) ?></span></div>
                        <div><span>Telefone</span><span><?= _segFmt($r['telefone_whatsapp']) ?></span></div>
                        <div><span>Cargo</span><span><?= _segFmt($r['cargo']) ?></span></div>
                        <div><span>Tipo contrato</span><span><?= _segFmt($r['tipo_remuneracao']) ?></span></div>
                        <div><span>Renda</span><span><?= _segRenda($r['valor_remuneracao']) ?></span></div>
                    </div>
                </div>
                <div class="sv-bloco">
                    <h4>🩺 Informações pra contratação</h4>
                    <div class="sv-info">
                        <div><span>Peso</span><span><?= _segPeso($r['peso']) ?></span></div>
                        <div><span>Altura</span><span><?= _segAlt($r['altura']) ?></span></div>
                        <div><span>Fumante</span><span style="color:<?= (int)$r['fumante'] === 1 ? '#dc2626' : '#059669' ?>;font-weight:700;"><?= $r['fumante'] === null ? '—' : ((int)$r['fumante'] === 1 ? 'SIM' : 'Não') ?></span></div>
                        <div><span>Pratica esporte</span><span><?= _segFmt($r['pratica_esporte']) ?></span></div>
                    </div>
                    <?php if (!empty($r['observacoes'])): ?>
                    <div style="margin-top:.65rem;padding:.5rem .65rem;background:#fef3c7;border-left:3px solid #d97706;border-radius:5px;font-size:.78rem;color:#92400e;">
                        <strong>Obs.:</strong> <?= nl2br(e($r['observacoes'])) ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="sv-card-ft">
                <button type="button" class="sv-btn" onclick="svCopiar('<?= $idCard ?>')">📋 Copiar tudo (pra colar pra corretora)</button>
                <a href="<?= e(url('publico/onboarding/seguro_vida.php?token=' . $r['token'])) ?>" target="_blank" class="sv-btn alt">👁 Abrir como o colaborador vê</a>
                <textarea id="<?= $idCard ?>-txt" style="position:absolute;left:-9999px;top:-9999px;"><?= e($textoCopiar) ?></textarea>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<script>
function svCopiar(cardId) {
    var ta = document.getElementById(cardId + '-txt');
    if (!ta) return;
    ta.style.position = 'fixed'; ta.style.left = '0'; ta.style.top = '0';
    ta.select();
    var ok = false;
    try { ok = document.execCommand('copy'); } catch (e) {}
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(ta.value).then(function(){
            mostrarToast('✓ Dados copiados! Cole no email/WhatsApp da corretora.');
        }).catch(function(){
            if (ok) mostrarToast('✓ Dados copiados!');
        });
    } else if (ok) {
        mostrarToast('✓ Dados copiados!');
    }
    ta.style.position = 'absolute'; ta.style.left = '-9999px';
}
function mostrarToast(msg) {
    var t = document.createElement('div');
    t.textContent = msg;
    t.style.cssText = 'position:fixed;bottom:20px;right:20px;background:#059669;color:#fff;padding:12px 20px;border-radius:8px;font-weight:600;z-index:100001;box-shadow:0 8px 24px rgba(0,0,0,.25);font-family:inherit;';
    document.body.appendChild(t);
    setTimeout(function(){ t.style.transition='opacity .4s'; t.style.opacity='0'; }, 2500);
    setTimeout(function(){ if (t.parentNode) t.parentNode.removeChild(t); }, 3000);
}
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
