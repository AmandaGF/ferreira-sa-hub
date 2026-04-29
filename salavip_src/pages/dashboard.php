<?php
/**
 * Central VIP F&S — Dashboard / Painel
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
$pdo = sv_db();
$user = salavip_current_user();
$clienteId = salavip_current_cliente_id();

// --- Palavras de bloqueio ---
$palavrasBloqueio = $pdo->query("SELECT termo FROM salavip_palavras_bloqueio WHERE ativo=1")->fetchAll(PDO::FETCH_COLUMN);
function sv_andamento_visivel($descricao, $palavras) {
    foreach ($palavras as $p) {
        if (stripos($descricao, $p) !== false) return false;
    }
    return true;
}

// --- KPI 1: Processos ativos ---
$stmtProcessos = $pdo->prepare(
    "SELECT COUNT(*) FROM cases WHERE client_id = ? AND salavip_ativo = 1 AND status NOT IN ('cancelado','arquivado')"
);
$stmtProcessos->execute([$clienteId]);
$kpiProcessos = (int) $stmtProcessos->fetchColumn();

// --- KPI 2: Mensagens não lidas ---
$stmtMsgs = $pdo->prepare(
    "SELECT COUNT(*) FROM salavip_mensagens WHERE cliente_id = ? AND origem = 'conecta' AND lida_cliente = 0"
);
$stmtMsgs->execute([$clienteId]);
$kpiMensagens = (int) $stmtMsgs->fetchColumn();

// --- KPI 3: Documentos pendentes ---
$stmtDocs = $pdo->prepare(
    "SELECT COUNT(*) FROM documentos_pendentes WHERE client_id = ? AND status = 'pendente' AND visivel_cliente = 1"
);
$stmtDocs->execute([$clienteId]);
$kpiDocumentos = (int) $stmtDocs->fetchColumn();

// --- KPI 4: Próximos compromissos ---
$stmtEventos = $pdo->prepare(
    "SELECT COUNT(*) FROM agenda_eventos WHERE client_id = ? AND visivel_cliente = 1 AND data_inicio >= NOW() AND status NOT IN ('cancelado','remarcado','realizado')"
);
$stmtEventos->execute([$clienteId]);
$kpiCompromissos = (int) $stmtEventos->fetchColumn();

// --- Próximos 3 compromissos ---
$stmtProxEventos = $pdo->prepare(
    "SELECT * FROM agenda_eventos WHERE client_id = ? AND visivel_cliente = 1 AND data_inicio >= NOW() AND status NOT IN ('cancelado','remarcado','realizado') ORDER BY data_inicio ASC LIMIT 3"
);
$stmtProxEventos->execute([$clienteId]);
$proxEventos = $stmtProxEventos->fetchAll();

// --- Últimos andamentos (5) ---
$stmtAndamentos = $pdo->prepare(
    "SELECT ca.*, c.title as caso_titulo FROM case_andamentos ca
     INNER JOIN cases c ON c.id = ca.case_id
     WHERE c.client_id = ? AND c.salavip_ativo = 1 AND ca.visivel_cliente = 1
     ORDER BY ca.data_andamento DESC, ca.created_at DESC
     LIMIT 5"
);
$stmtAndamentos->execute([$clienteId]);
$andamentos = $stmtAndamentos->fetchAll();

// --- Mensagens recentes (3) ---
$stmtMsgsRecentes = $pdo->prepare(
    "SELECT * FROM salavip_mensagens WHERE cliente_id = ? ORDER BY criado_em DESC LIMIT 3"
);
$stmtMsgsRecentes->execute([$clienteId]);
$msgsRecentes = $stmtMsgsRecentes->fetchAll();

// Primeiro nome
$primeiroNome = explode(' ', trim($user['nome_exibicao']))[0];

$pageTitle = 'Painel';
require_once __DIR__ . '/../includes/header.php';
?>

<p style="font-family:'Playfair Display',serif;color:var(--sv-accent);font-size:1.5rem;margin-bottom:1.5rem;">
    Bem-vindo(a), <?= sv_e($primeiroNome) ?>
</p>

<!-- Dica de navegação -->
<?php if ($kpiProcessos > 0): ?>
<a href="<?= sv_url('pages/meus_processos.php') ?>" style="display:flex;align-items:center;gap:.6rem;padding:.75rem 1rem;background:var(--sv-accent-bg);border:1px solid var(--sv-border);border-radius:10px;margin-bottom:1.25rem;text-decoration:none;transition:all .2s;" onmouseover="this.style.transform='translateX(4px)'" onmouseout="this.style.transform=''">
    <span style="font-size:1.3rem;">📂</span>
    <div>
        <div style="color:var(--sv-accent);font-weight:700;font-size:.88rem;">Acompanhe seus processos</div>
        <div style="color:var(--sv-text-muted);font-size:.78rem;">Clique aqui para ver detalhes, andamentos completos e documentos de cada processo</div>
    </div>
    <span style="color:var(--sv-accent);margin-left:auto;font-size:1.1rem;">→</span>
</a>
<?php endif; ?>

<!-- KPI Grid -->
<div class="sv-kpi-grid">
    <a href="<?= sv_url('pages/meus_processos.php') ?>" class="sv-kpi-card" style="text-decoration:none;cursor:pointer;">
        <div class="sv-kpi-number"><?= $kpiProcessos ?></div>
        <div class="sv-kpi-label">Processos ativos</div>
    </a>
    <div class="sv-kpi-card">
        <div class="sv-kpi-number"><?= $kpiMensagens ?></div>
        <div class="sv-kpi-label">Mensagens n&atilde;o lidas</div>
    </div>
    <div class="sv-kpi-card">
        <div class="sv-kpi-number"><?= $kpiDocumentos ?></div>
        <div class="sv-kpi-label">Documentos pendentes</div>
    </div>
    <div class="sv-kpi-card">
        <div class="sv-kpi-number"><?= $kpiCompromissos ?></div>
        <div class="sv-kpi-label">Pr&oacute;ximos compromissos</div>
    </div>
</div>

<!-- Próximos Compromissos -->
<div class="sv-card" style="margin-top:1.5rem;">
    <h3>Pr&oacute;ximos Compromissos</h3>
    <?php if (empty($proxEventos)): ?>
        <p class="sv-empty">Nenhum compromisso agendado.</p>
    <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:.75rem;">
            <?php foreach ($proxEventos as $ev): ?>
                <div style="border-bottom:1px solid rgba(201,169,78,.1);padding-bottom:.75rem;">
                    <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;">
                        <strong style="color:var(--sv-accent);"><?= sv_formatar_data_hora($ev['data_inicio']) ?></strong>
                        <span><?= sv_e($ev['titulo']) ?></span>
                        <span style="background:var(--sv-accent-bg);color:var(--sv-accent);padding:2px 8px;border-radius:9999px;font-size:.7rem;font-weight:600;">
                            <?= sv_e(sv_nome_tipo_evento($ev['tipo'])) ?>
                        </span>
                    </div>
                    <?php if (!empty($ev['local'])): ?>
                        <div style="color:var(--sv-text-muted);font-size:.85rem;margin-top:.25rem;">
                            Local: <?= sv_e($ev['local']) ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Últimos Andamentos -->
<div class="sv-card" style="margin-top:1.5rem;">
    <h3>&Uacute;ltimos Andamentos</h3>
    <?php
    $tipoLabels = ['movimentacao'=>'Movimentação','despacho'=>'Despacho','decisao'=>'Decisão','sentenca'=>'Sentença','intimacao'=>'Intimação','citacao'=>'Citação','audiencia'=>'Audiência','peticao'=>'Petição','certidao'=>'Certidão','observacao'=>'Andamento','chamado'=>'Atendimento','publicacao'=>'Publicação'];
    $tipoCores = ['movimentacao'=>'#6366f1','despacho'=>'#0ea5e9','decisao'=>'#dc2626','sentenca'=>'#7c3aed','intimacao'=>'#d97706','citacao'=>'#059669','audiencia'=>'#e67e22','peticao'=>'#B87333','certidao'=>'#0891b2','observacao'=>'#64748b','chamado'=>'#f59e0b','publicacao'=>'#dc2626'];
    ?>
    <?php if (empty($andamentos)): ?>
        <p class="sv-empty">Nenhum andamento recente.</p>
    <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:.75rem;">
            <?php foreach ($andamentos as $and):
                $tipo = $and['tipo'] ?? 'observacao';
                $tipoLabel = $tipoLabels[$tipo] ?? ucfirst($tipo);
                $tipoCor = $tipoCores[$tipo] ?? '#64748b';
                $procCor = sv_cor_processo((int)$and['case_id']);
            ?>
                <a href="<?= sv_url('pages/processo_detalhe.php?id=' . (int)$and['case_id']) ?>" style="display:block;border-left:3px solid <?= $procCor['border'] ?>;border-bottom:1px solid var(--sv-border);text-decoration:none;transition:all .15s;border-radius:6px;padding:.6rem .8rem;margin:0 -.4rem;" onmouseover="this.style.background='var(--sv-accent-bg)'" onmouseout="this.style.background=''">
                    <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;">
                        <strong style="color:var(--sv-accent);"><?= sv_formatar_data($and['data_andamento']) ?></strong>
                        <span style="background:<?= $tipoCor ?>20;color:<?= $tipoCor ?>;padding:2px 8px;border-radius:6px;font-size:.7rem;font-weight:700;"><?= sv_e($tipoLabel) ?></span>
                        <?php if (!empty($and['caso_titulo'])): ?>
                        <span style="background:<?= $procCor['bg'] ?>;color:<?= $procCor['text'] ?>;padding:2px 10px;border-radius:9999px;font-size:.72rem;font-weight:700;letter-spacing:.02em;"><?= sv_e($and['caso_titulo']) ?></span>
                        <?php endif; ?>
                        <span style="color:var(--sv-accent);font-size:.72rem;margin-left:auto;">Ver detalhes →</span>
                    </div>
                    <div style="color:var(--sv-text);margin-top:.3rem;font-size:.88rem;line-height:1.5;">
                        <?= nl2br(sv_e(mb_strimwidth(sv_traduzir_andamento($and['descricao']), 0, 200, '...'))) ?>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Mensagens Recentes -->
<div class="sv-card" style="margin-top:1.5rem;">
    <h3>Mensagens Recentes</h3>
    <?php if (empty($msgsRecentes)): ?>
        <p class="sv-empty">Nenhuma mensagem.</p>
    <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:.75rem;">
            <?php foreach ($msgsRecentes as $msg): ?>
                <div style="border-bottom:1px solid rgba(201,169,78,.1);padding-bottom:.75rem;<?= ($msg['origem'] === 'conecta' && !$msg['lida_cliente']) ? 'border-left:3px solid #c9a94e;padding-left:.75rem;' : '' ?>">
                    <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;">
                        <strong style="color:<?= $msg['origem'] === 'conecta' ? 'var(--sv-accent)' : 'var(--sv-text-muted)' ?>;">
                            <?= $msg['origem'] === 'conecta' ? 'Escritório' : 'Você' ?>
                        </strong>
                        <span style="color:var(--sv-text-muted);font-size:.75rem;"><?= sv_formatar_data_hora($msg['criado_em']) ?></span>
                        <?php if ($msg['origem'] === 'conecta' && !$msg['lida_cliente']): ?>
                            <span style="background:#dc2626;color:#fff;padding:1px 6px;border-radius:9999px;font-size:.65rem;font-weight:600;">Nova</span>
                        <?php endif; ?>
                    </div>
                    <div style="color:var(--sv-text);margin-top:.25rem;">
                        <?= sv_e(mb_strimwidth($msg['mensagem'], 0, 80, '...')) ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <div style="text-align:right;margin-top:.75rem;">
            <a href="<?= sv_url('pages/mensagens.php') ?>" style="color:var(--sv-accent);font-size:.85rem;">Ver todas &rarr;</a>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
