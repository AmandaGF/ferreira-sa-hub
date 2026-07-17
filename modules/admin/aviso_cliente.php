<?php
/**
 * /admin/aviso_cliente.php — Controle do aviso automatico ao cliente
 * Amanda 16/07/2026
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
require_min_role('gestao');
require_once __DIR__ . '/../../core/functions_aviso_cliente.php';

$pdo = db();
aviso_cliente_self_heal($pdo);
$flash = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) { $flash = '<div class="alert alert-error">CSRF inválido.</div>'; }
    else {
        $acao = $_POST['acao'] ?? '';
        if ($acao === 'salvar_cfg') {
            $ativo = !empty($_POST['ativo']) ? '1' : '0';
            $tipos = trim($_POST['tipos_ignorar'] ?? '');
            $janela = max(60, min(600, (int)($_POST['janela_seg'] ?? 180)));
            $max = max(1, min(50, (int)($_POST['max_por_run'] ?? 15)));
            $st = $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES (?, ?)
                                 ON DUPLICATE KEY UPDATE valor = VALUES(valor)");
            $st->execute(array('aviso_cliente_ativo', $ativo));
            $st->execute(array('aviso_cliente_tipos_ignorar', $tipos));
            $st->execute(array('aviso_cliente_janela_seg', (string)$janela));
            $st->execute(array('aviso_cliente_max_por_run', (string)$max));
            audit_log('aviso_cliente_cfg', 'configuracoes', 0, "ativo=$ativo janela=$janela");
            $flash = '<div class="alert alert-success">✓ Configuração salva.</div>';
        } elseif ($acao === 'testar_agora') {
            $r = aviso_cliente_processar_pendentes($pdo, 15);
            $flash = '<div class="alert alert-info">Rodagem executada: ' . (int)$r['enviados'] . ' enviado(s), ' . (int)$r['erros'] . ' erro(s), ' . (int)$r['pendentes_total'] . ' pendente(s). Recarregue pra ver log atualizado.</div>';
        }
    }
}

// Recarrega config apos salvar
$cfg = array();
foreach ($pdo->query("SELECT chave, valor FROM configuracoes WHERE chave LIKE 'aviso_cliente_%'") as $r) {
    $cfg[$r['chave']] = $r['valor'];
}
$ativo = ($cfg['aviso_cliente_ativo'] ?? '0') === '1';
$tipos = $cfg['aviso_cliente_tipos_ignorar'] ?? '';
$janela = (int)($cfg['aviso_cliente_janela_seg'] ?? 180);
$max = (int)($cfg['aviso_cliente_max_por_run'] ?? 15);

// Log — ultimos 50 enviados
$logEnv = array();
try {
    $logEnv = $pdo->query("SELECT ca.id, ca.tipo, ca.notif_cliente_status, ca.notif_cliente_enviada_em,
                                  LEFT(ca.notif_cliente_texto, 220) AS trecho,
                                  cs.title, cl.name AS cliente
                             FROM case_andamentos ca
                             LEFT JOIN cases cs ON cs.id = ca.case_id
                             LEFT JOIN clients cl ON cl.id = cs.client_id
                            WHERE ca.notif_cliente_status IS NOT NULL
                              AND ca.notif_cliente_status NOT IN ('desligado','antigo')
                            ORDER BY ca.id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Contadores
$fila = 0;
try { $fila = (int)$pdo->query("SELECT COUNT(*) FROM case_andamentos WHERE notif_cliente_status IS NULL")->fetchColumn(); } catch (Exception $e) {}

$pageTitle = 'Aviso Automático ao Cliente';
require_once APP_ROOT . '/templates/layout_start.php';
?>
<div style="max-width:960px;">
    <a href="<?= module_url('admin') ?>" class="btn btn-outline btn-sm mb-2">← Admin</a>
    <h1 style="font-size:1.4rem;margin:.5rem 0 1rem;">🔔 Aviso automático ao cliente</h1>
    <?= $flash ?>

    <div class="card mb-2">
        <div class="card-body">
            <p style="margin:0 0 .8rem;color:#4b5563;font-size:.88rem;">
                Sempre que entrar um andamento novo em um caso, o sistema chama a IA (Haiku)
                pra explicar em linguagem de leigo, e manda WhatsApp automático pro cliente
                pelo <strong>canal 24 (CX)</strong>. Custo estimado: <strong>~R$ 0,01 por andamento</strong>.
            </p>
            <form method="POST">
                <?= csrf_input() ?>
                <input type="hidden" name="acao" value="salvar_cfg">

                <div style="display:flex;align-items:center;gap:1rem;padding:1rem;background:<?= $ativo ? '#dcfce7' : '#fef3c7' ?>;border-radius:8px;margin-bottom:1rem;">
                    <label style="display:flex;align-items:center;gap:.6rem;cursor:pointer;font-size:1rem;font-weight:700;">
                        <input type="checkbox" name="ativo" value="1" <?= $ativo ? 'checked' : '' ?> style="width:24px;height:24px;cursor:pointer;">
                        <?= $ativo ? '🟢 Ativo — clientes recebem WhatsApp automático' : '🔴 Desligado — nenhum aviso é enviado' ?>
                    </label>
                </div>

                <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:1rem;">
                    <div>
                        <label style="display:block;font-size:.78rem;font-weight:700;color:#6b7280;margin-bottom:.3rem;">Tipos de andamento a IGNORAR (csv, match parcial)</label>
                        <input type="text" name="tipos_ignorar" value="<?= e($tipos) ?>" class="form-input" style="width:100%;font-family:monospace;font-size:.78rem;" placeholder="ato_ordinatorio,mero_expediente">
                        <small style="color:#6b7280;font-size:.72rem;">Match parcial no campo <code>tipo</code>. Ex: 'juntada_ap' pega 'juntada_ap_re', 'juntada_ap_autor', etc.</small>
                    </div>
                    <div>
                        <label style="display:block;font-size:.78rem;font-weight:700;color:#6b7280;margin-bottom:.3rem;">Janela debounce (seg)</label>
                        <input type="number" name="janela_seg" value="<?= $janela ?>" class="form-input" style="width:100%;" min="60" max="600">
                        <small style="color:#6b7280;font-size:.72rem;">Aguarda X segundos após INSERT antes de processar (agrega múltiplos andamentos numa msg só).</small>
                    </div>
                    <div>
                        <label style="display:block;font-size:.78rem;font-weight:700;color:#6b7280;margin-bottom:.3rem;">Máx casos por rodagem</label>
                        <input type="number" name="max_por_run" value="<?= $max ?>" class="form-input" style="width:100%;" min="1" max="50">
                        <small style="color:#6b7280;font-size:.72rem;">Limite por execução do cron (evita explodir custo IA).</small>
                    </div>
                </div>

                <div style="display:flex;gap:.5rem;justify-content:flex-end;margin-top:1rem;">
                    <button type="submit" class="btn btn-primary">💾 Salvar configuração</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card mb-2">
        <div class="card-body">
            <div style="display:flex;justify-content:space-between;align-items:center;">
                <div>
                    <strong style="color:#052228;">📋 Fila atual:</strong>
                    <span style="font-size:1.2rem;font-weight:900;color:#B87333;"><?= $fila ?></span>
                    <span style="color:#6b7280;font-size:.85rem;">andamento(s) pendente(s)</span>
                </div>
                <form method="POST" style="margin:0;">
                    <?= csrf_input() ?>
                    <input type="hidden" name="acao" value="testar_agora">
                    <button type="submit" class="btn btn-outline btn-sm">▶️ Rodar agora manualmente</button>
                </form>
            </div>
            <small style="color:#6b7280;font-size:.75rem;">Cron rodando a cada 2min via cPanel.</small>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <h3 style="margin:0 0 .8rem;font-size:1rem;">📜 Últimos 50 processados</h3>
            <?php if (empty($logEnv)): ?>
                <p style="color:#6b7280;font-size:.85rem;">Nenhum aviso registrado ainda.</p>
            <?php else: ?>
                <table class="table" style="font-size:.78rem;">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Cliente / Processo</th>
                            <th>Status</th>
                            <th>Enviado em</th>
                            <th>Prévia</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($logEnv as $l):
                        $stColor = array(
                            'enviado' => '#059669',
                            'erro_envio' => '#b91c1c',
                            'erro_ia' => '#b91c1c',
                            'silenciado_caso' => '#6b7280',
                            'tipo_ignorado' => '#6b7280',
                            'sem_fone' => '#9a3412',
                            'sem_cliente' => '#9a3412',
                        )[$l['notif_cliente_status']] ?? '#6b7280';
                    ?>
                        <tr>
                            <td style="color:#9ca3af;">#<?= $l['id'] ?></td>
                            <td>
                                <strong style="color:#052228;"><?= e($l['cliente'] ?: '—') ?></strong>
                                <div style="color:#6b7280;font-size:.72rem;"><?= e(mb_substr($l['title'] ?? '', 0, 45)) ?></div>
                            </td>
                            <td><span style="background:<?= $stColor ?>;color:#fff;padding:2px 8px;border-radius:8px;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.03em;"><?= e($l['notif_cliente_status']) ?></span></td>
                            <td style="color:#6b7280;font-size:.72rem;white-space:nowrap;"><?= $l['notif_cliente_enviada_em'] ? date('d/m H:i', strtotime($l['notif_cliente_enviada_em'])) : '—' ?></td>
                            <td style="color:#4b5563;font-size:.75rem;"><?= e($l['trecho'] ?: '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
