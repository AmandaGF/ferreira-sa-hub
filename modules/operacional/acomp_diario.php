<?php
/**
 * Gerenciamento das mensagens diárias de acompanhamento
 * (feature Amanda 01/07/2026).
 *
 * Lista todas as configs de msg diária de acompanhamento cadastradas.
 * Permite ligar/desligar, editar horário/dias, excluir.
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
require_access('operacional');

$pdo = db();
$pageTitle = 'Msg diária de acompanhamento';

// Self-heal defensivo
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS acompanhamento_msg_diario (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        client_id INT UNSIGNED NOT NULL, case_id INT UNSIGNED NOT NULL,
        canal ENUM('21','24') NOT NULL DEFAULT '24',
        horario_envio TIME NOT NULL DEFAULT '10:00:00',
        dias_uteis_only TINYINT(1) NOT NULL DEFAULT 1,
        ativo TINYINT(1) NOT NULL DEFAULT 1,
        ultimo_envio_em DATETIME NULL, ultimo_template_idx INT NULL,
        ultima_data_andamento_visto DATE NULL,
        total_envios INT NOT NULL DEFAULT 0, obs TEXT NULL,
        pausado_em DATETIME NULL, pausado_motivo TEXT NULL,
        created_by INT UNSIGNED NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_client_case (client_id, case_id),
        INDEX idx_ativo (ativo), INDEX idx_case (case_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Exception $e) {}

// Ações POST (toggle/excluir)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && validate_csrf()) {
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    if ($action === 'toggle' && $id > 0) {
        $st = $pdo->prepare("SELECT ativo FROM acompanhamento_msg_diario WHERE id = ?");
        $st->execute(array($id));
        $atual = (int)$st->fetchColumn();
        $novo = $atual ? 0 : 1;
        $pdo->prepare("UPDATE acompanhamento_msg_diario SET ativo = ? WHERE id = ?")->execute(array($novo, $id));
        audit_log('acomp_diario_toggle', 'acomp', $id, $novo ? 'ligado' : 'desligado');
        flash_set('success', $novo ? 'Envio diário LIGADO.' : 'Envio diário DESLIGADO.');
    }

    if ($action === 'excluir' && $id > 0) {
        $pdo->prepare("DELETE FROM acompanhamento_msg_diario WHERE id = ?")->execute(array($id));
        audit_log('acomp_diario_excluir', 'acomp', $id);
        flash_set('success', 'Configuração removida.');
    }

    redirect(module_url('operacional', 'acomp_diario.php'));
}

// Filtros
$filtro = $_GET['f'] ?? 'todos'; // todos | ativos | desligados

$where = '';
if ($filtro === 'ativos') $where = 'WHERE a.ativo = 1';
elseif ($filtro === 'desligados') $where = 'WHERE a.ativo = 0';

$sql = "SELECT a.*, c.name AS client_name, c.phone AS client_phone,
               cs.title AS case_title, cs.case_number, cs.status AS case_status,
               u.name AS created_by_name
        FROM acompanhamento_msg_diario a
        JOIN clients c ON c.id = a.client_id
        JOIN cases cs ON cs.id = a.case_id
        LEFT JOIN users u ON u.id = a.created_by
        {$where}
        ORDER BY a.ativo DESC, a.updated_at DESC";
$lista = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

// Contadores pros badges
$totAtivos = (int)$pdo->query("SELECT COUNT(*) FROM acompanhamento_msg_diario WHERE ativo = 1")->fetchColumn();
$totDesligados = (int)$pdo->query("SELECT COUNT(*) FROM acompanhamento_msg_diario WHERE ativo = 0")->fetchColumn();
$totEnviosSemana = (int)$pdo->query("SELECT COUNT(*) FROM acompanhamento_msg_diario WHERE ultimo_envio_em >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
$killswitch = $pdo->query("SELECT valor FROM configuracoes WHERE chave='acompanhamento_msg_diario_ativo'")->fetchColumn();
$killswitchOn = ($killswitch === '1' || $killswitch === 1);

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.ad-toolbar { display:flex; gap:.4rem; flex-wrap:wrap; align-items:center; margin-bottom:1rem; }
.ad-chip { display:inline-flex; align-items:center; gap:.35rem; padding:.35rem .75rem; border-radius:8px; font-size:.78rem; font-weight:600; text-decoration:none; color:#64748b; background:#fff; border:1.5px solid var(--border); }
.ad-chip.ativa { background:#0891b2; color:#fff; border-color:#0891b2; }
.ad-chip-count { background:rgba(0,0,0,.1); padding:1px 6px; border-radius:6px; font-size:.68rem; }
.ad-chip.ativa .ad-chip-count { background:rgba(255,255,255,.25); }
.ad-tabela { width:100%; border-collapse:collapse; background:#fff; border-radius:10px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,.06); }
.ad-tabela thead { background:linear-gradient(135deg,#0891b2,#0e7490); color:#fff; }
.ad-tabela th { text-align:left; padding:9px 11px; font-size:.68rem; font-weight:700; text-transform:uppercase; letter-spacing:.3px; }
.ad-tabela td { padding:9px 11px; border-bottom:1px solid #f1f5f9; font-size:.82rem; vertical-align:middle; }
.ad-tabela tr:last-child td { border-bottom:none; }
.ad-tabela tr:hover { background:#f8fafc; }
.ad-tabela tr.desligado td { opacity:.55; }
.ad-tabela tr.desligado .ad-titulo { text-decoration:line-through; color:#94a3b8; }
.ad-status { display:inline-block; padding:2px 8px; border-radius:6px; font-size:.66rem; font-weight:700; text-transform:uppercase; letter-spacing:.3px; }
.ad-status.on { background:#dcfce7; color:#166534; }
.ad-status.off { background:#f1f5f9; color:#64748b; }
.ad-acoes button, .ad-acoes a { padding:3px 8px; border-radius:5px; font-size:.7rem; font-weight:700; cursor:pointer; text-decoration:none; border:none; margin-right:2px; }
.ad-btn-toggle { background:#f0fdfa; color:#0891b2; border:1px solid #a5f3fc; }
.ad-btn-toggle.on { background:#fef2f2; color:#991b1b; border-color:#fecaca; }
.ad-btn-edit { background:#eff6ff; color:#1e40af; border:1px solid #bfdbfe; }
.ad-btn-del { background:#fef2f2; color:#dc2626; border:1px solid #fecaca; }
</style>

<h2 style="margin:0 0 .5rem 0;font-size:1.15rem;color:var(--petrol-900);">🔁 Mensagens diárias de acompanhamento</h2>
<p style="color:var(--text-muted);font-size:.82rem;margin-bottom:1rem;">
    Clientes que recebem WhatsApp diário automático quando o processo não tem andamento novo.
</p>

<!-- KPIs -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:.55rem;margin-bottom:1rem;">
    <div style="background:#fff;border:1px solid var(--border);border-radius:10px;padding:.65rem .85rem;">
        <div style="font-size:1.4rem;font-weight:800;color:#059669;line-height:1;"><?= $totAtivos ?></div>
        <div style="font-size:.68rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.3px;margin-top:.15rem;">Ativos</div>
    </div>
    <div style="background:#fff;border:1px solid var(--border);border-radius:10px;padding:.65rem .85rem;">
        <div style="font-size:1.4rem;font-weight:800;color:#64748b;line-height:1;"><?= $totDesligados ?></div>
        <div style="font-size:.68rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.3px;margin-top:.15rem;">Desligados</div>
    </div>
    <div style="background:#fff;border:1px solid var(--border);border-radius:10px;padding:.65rem .85rem;">
        <div style="font-size:1.4rem;font-weight:800;color:#0891b2;line-height:1;"><?= $totEnviosSemana ?></div>
        <div style="font-size:.68rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.3px;margin-top:.15rem;">Enviaram últimos 7d</div>
    </div>
    <div style="background:<?= $killswitchOn ? '#dcfce7' : '#fee2e2' ?>;border:1px solid <?= $killswitchOn ? '#86efac' : '#fca5a5' ?>;border-radius:10px;padding:.65rem .85rem;">
        <div style="font-size:.9rem;font-weight:800;color:<?= $killswitchOn ? '#166534' : '#991b1b' ?>;line-height:1.3;">
            <?= $killswitchOn ? '🟢 Sistema ligado' : '🔴 Sistema desligado' ?>
        </div>
        <div style="font-size:.65rem;color:<?= $killswitchOn ? '#166534' : '#991b1b' ?>;margin-top:.15rem;">
            Killswitch geral
        </div>
    </div>
</div>

<!-- Filtros -->
<div class="ad-toolbar">
    <a href="?f=todos" class="ad-chip <?= $filtro === 'todos' ? 'ativa' : '' ?>">Todos <span class="ad-chip-count"><?= $totAtivos + $totDesligados ?></span></a>
    <a href="?f=ativos" class="ad-chip <?= $filtro === 'ativos' ? 'ativa' : '' ?>">🟢 Ativos <span class="ad-chip-count"><?= $totAtivos ?></span></a>
    <a href="?f=desligados" class="ad-chip <?= $filtro === 'desligados' ? 'ativa' : '' ?>">⚫ Desligados <span class="ad-chip-count"><?= $totDesligados ?></span></a>
    <div style="margin-left:auto;font-size:.72rem;color:var(--text-muted);">
        💡 Pra adicionar: abre a pasta do processo → botão "🔁 Msg diária"
    </div>
</div>

<?php if (empty($lista)): ?>
    <div style="background:#fff;border:1px solid var(--border);border-radius:10px;padding:2.5rem;text-align:center;color:var(--text-muted);">
        <div style="font-size:2rem;margin-bottom:.5rem;">📭</div>
        <div style="font-weight:700;color:var(--petrol-900);font-size:.95rem;">Nenhuma mensagem diária configurada<?= $filtro !== 'todos' ? ' nesse filtro' : '' ?></div>
        <div style="font-size:.8rem;margin-top:.35rem;">Abra a pasta de um processo e clique em "🔁 Msg diária" pra configurar.</div>
    </div>
<?php else: ?>
<div style="overflow-x:auto;">
<table class="ad-tabela">
    <thead>
        <tr>
            <th>Status</th>
            <th>Cliente / Caso</th>
            <th>Canal</th>
            <th>Horário</th>
            <th>Dias</th>
            <th>Último envio</th>
            <th>Envios</th>
            <th>Criado por</th>
            <th style="text-align:right;">Ações</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($lista as $c):
            $caseCanceladoBanner = in_array($c['case_status'], array('cancelado','arquivado','concluido','finalizado'), true);
        ?>
        <tr class="<?= $c['ativo'] ? 'ligado' : 'desligado' ?>">
            <td>
                <span class="ad-status <?= $c['ativo'] ? 'on' : 'off' ?>">
                    <?= $c['ativo'] ? '🟢 Ativo' : '⚫ Off' ?>
                </span>
                <?php if ($caseCanceladoBanner): ?>
                    <br><span style="font-size:.6rem;color:#991b1b;font-weight:700;">⚠️ caso <?= e($c['case_status']) ?></span>
                <?php endif; ?>
            </td>
            <td>
                <div class="ad-titulo" style="font-weight:700;color:var(--petrol-900);"><?= e($c['client_name']) ?></div>
                <div style="font-size:.72rem;color:var(--text-muted);">
                    <a href="<?= module_url('operacional', 'caso_ver.php?id=' . (int)$c['case_id']) ?>" style="color:#0891b2;text-decoration:none;font-weight:600;"><?= e($c['case_title']) ?></a>
                    <?php if ($c['case_number']): ?> · <?= e($c['case_number']) ?><?php endif; ?>
                </div>
                <?php if ($c['client_phone']): ?>
                    <div style="font-size:.68rem;color:var(--text-muted);margin-top:2px;">📞 <?= e($c['client_phone']) ?></div>
                <?php endif; ?>
                <?php if (!empty($c['obs'])): ?>
                    <div style="font-size:.68rem;color:#78350f;background:#fef3c7;border-radius:4px;padding:2px 5px;margin-top:2px;display:inline-block;">💬 <?= e(mb_substr($c['obs'], 0, 60)) ?></div>
                <?php endif; ?>
            </td>
            <td style="font-family:monospace;font-size:.75rem;">📱 <?= e($c['canal']) ?></td>
            <td><?= e(substr((string)$c['horario_envio'], 0, 5)) ?><?php if (!empty($c['usar_ia'])): ?><br><span style="font-size:.6rem;background:#e0f2fe;color:#0369a1;padding:1px 5px;border-radius:3px;font-weight:700;">🤖 IA</span><?php endif; ?></td>
            <td style="font-size:.72rem;color:var(--text-muted);">
                <?php
                $_diasCsv = trim((string)($c['dias_semana'] ?? ''));
                if ($_diasCsv === '') $_diasCsv = !empty($c['dias_uteis_only']) ? '1,2,3,4,5' : '1,2,3,4,5,6,7';
                $_ds = array_map('intval', explode(',', $_diasCsv));
                sort($_ds);
                $_lbl = array(1=>'S',2=>'T',3=>'Q',4=>'Q',5=>'S',6=>'S',7=>'D');
                $_full = array(1=>'Seg',2=>'Ter',3=>'Qua',4=>'Qui',5=>'Sex',6=>'Sáb',7=>'Dom');
                // Presets
                if ($_ds === array(1,2,3,4,5)) echo 'Só úteis';
                elseif ($_ds === array(1,2,3,4,5,6,7)) echo 'Todos';
                elseif ($_ds === array(1,5)) echo 'Seg + Sex';
                elseif ($_ds === array(1,3,5)) echo 'Seg/Qua/Sex';
                else {
                    $_out = array();
                    foreach ($_ds as $_d) if (isset($_full[$_d])) $_out[] = $_full[$_d];
                    echo implode(', ', $_out);
                }
                ?>
            </td>
            <td style="font-size:.72rem;">
                <?php if ($c['ultimo_envio_em']): ?>
                    <?= date('d/m H:i', strtotime($c['ultimo_envio_em'])) ?>
                    <?php if (!empty($c['ultimo_template_idx']) || $c['ultimo_template_idx'] === '0'): ?>
                        <br><span style="color:var(--text-muted);">tpl #<?= (int)$c['ultimo_template_idx'] ?></span>
                    <?php endif; ?>
                <?php else: ?>
                    <span style="color:var(--text-muted);">nunca</span>
                <?php endif; ?>
            </td>
            <td style="text-align:center;font-weight:700;color:#0891b2;"><?= (int)$c['total_envios'] ?></td>
            <td style="font-size:.72rem;color:var(--text-muted);"><?= e($c['created_by_name'] ? explode(' ', $c['created_by_name'])[0] : '—') ?></td>
            <td class="ad-acoes" style="text-align:right;white-space:nowrap;">
                <form method="POST" style="display:inline;">
                    <?= csrf_input() ?>
                    <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                    <button type="submit" name="action" value="toggle" class="ad-btn-toggle <?= $c['ativo'] ? 'on' : '' ?>" title="<?= $c['ativo'] ? 'Desligar' : 'Ligar' ?>">
                        <?= $c['ativo'] ? '⏸ Desligar' : '▶ Ligar' ?>
                    </button>
                </form>
                <a href="<?= module_url('operacional', 'caso_ver.php?id=' . (int)$c['case_id'] . '&edit_acomp=1') ?>" class="ad-btn-edit" title="Editar configuração da mensagem diária">✏️ Editar</a>
                <form method="POST" style="display:inline;" onsubmit="return confirm('Remover a configuração de msg diária deste cliente? (não desliga o cron, só remove essa config)');">
                    <?= csrf_input() ?>
                    <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                    <button type="submit" name="action" value="excluir" class="ad-btn-del" title="Excluir">🗑️</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>

<div style="margin-top:1.5rem;padding:.85rem 1rem;background:#f0f9ff;border:1px solid #bae6fd;border-left:4px solid #0891b2;border-radius:8px;font-size:.78rem;color:#0c4a6e;line-height:1.5;">
    <strong>ℹ️ Como funciona:</strong> o cron roda de hora em hora e, pra cada config ativa, verifica se chegou o horário e se hoje ainda não enviou. Antes de mandar, ele checa se teve andamento novo no processo — <strong>se teve, não envia</strong> (evita ruído). Se não teve, escolhe uma das 14 mensagens rotativas (nunca repete 2 dias seguidos) e envia pelo canal configurado.
</div>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
