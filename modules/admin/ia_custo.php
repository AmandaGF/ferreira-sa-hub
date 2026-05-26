<?php
/**
 * Ferreira & Sá Hub — Dashboard de Custo do Módulo de IA
 * Acesso: SOMENTE admin
 *
 * Mostra: gasto do mês corrente + projeção, gráfico por feature/usuário,
 * últimas chamadas (debug), e permite alterar killswitches e whitelist
 * de usuários autorizados.
 */
require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/database.php';
require_once __DIR__ . '/../../core/auth.php';
require_once __DIR__ . '/../../core/middleware.php';
require_once __DIR__ . '/../../core/functions_utils.php';
require_once __DIR__ . '/../../core/functions_ia.php';

require_login();
require_role('admin');

$pdo = db();
$pageTitle = '🤖 IA — Custo e Configuração';

// ── POST: salvar configurações ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) {
        $erroCfg = 'Token CSRF inválido — recarregue a página e tente de novo.';
    } else {
        $orc      = max(0, (int)($_POST['orcamento'] ?? 300));
        $cambio   = max(1.0, (float)($_POST['cambio'] ?? 5.50));
        $usersCsv = trim((string)($_POST['users_autorizados'] ?? ''));
        $usersCsv = implode(',', array_filter(array_map('intval', explode(',', preg_replace('/[^\d,]/', '', $usersCsv)))));

        $feats = array('resumo_caso','classif_andamento','cliente_esfriando','sugerir_acao','briefing','resumo_wa_chamado',
                       // Fase 3
                       'traducao_leiga','revisao_peticao','sentiment_wa',
                       // Fase 4 — análise profunda com Sonnet (26/05/2026)
                       'analise_aprofundada');
        $stCfg = $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)");
        $stCfg->execute(array('ia_orcamento_mensal_reais', (string)$orc));
        $stCfg->execute(array('ia_cambio_brl', (string)$cambio));
        $stCfg->execute(array('ia_users_autorizados', $usersCsv));
        foreach ($feats as $f) {
            $on = !empty($_POST['feat_' . $f]) ? '1' : '0';
            $stCfg->execute(array('ia_feature_' . $f . '_enabled', $on));
        }
        $okCfg = 'Configurações salvas.';
        @audit_log('IA_CONFIG_UPDATE', 'configuracoes', 0, 'orcamento=' . $orc . ' users=[' . $usersCsv . ']');
    }
}

// ── Lê estado atual ──────────────────────────────────────
function cfg($pdo, $k, $def = '') { $st = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = ?"); $st->execute(array($k)); $v = $st->fetchColumn(); return $v === false ? $def : (string)$v; }

$orc       = (int)cfg($pdo, 'ia_orcamento_mensal_reais', '300');
$cambio    = (float)cfg($pdo, 'ia_cambio_brl', '5.50');
$usersCsv  = cfg($pdo, 'ia_users_autorizados', '');
$featResumo = cfg($pdo, 'ia_feature_resumo_caso_enabled', '1') === '1';
$featClass  = cfg($pdo, 'ia_feature_classif_andamento_enabled', '1') === '1';
$featEsfri  = cfg($pdo, 'ia_feature_cliente_esfriando_enabled', '1') === '1';
$featSug    = cfg($pdo, 'ia_feature_sugerir_acao_enabled', '1') === '1';
$featBrief  = cfg($pdo, 'ia_feature_briefing_enabled', '1') === '1';
$featRwa    = cfg($pdo, 'ia_feature_resumo_wa_chamado_enabled', '1') === '1';
// Fase 3 — DEFAULT OFF (Amanda ativa quando quiser, todas tem custo)
$featTrad   = cfg($pdo, 'ia_feature_traducao_leiga_enabled', '0') === '1';
$featRev    = cfg($pdo, 'ia_feature_revisao_peticao_enabled', '0') === '1';
$featSent   = cfg($pdo, 'ia_feature_sentiment_wa_enabled', '0') === '1';
// Fase 4 — DEFAULT OFF (Sonnet, custo médio R$ 0,15-0,30 por análise)
$featAnaP   = cfg($pdo, 'ia_feature_analise_aprofundada_enabled', '0') === '1';

// Lista todos os usuários ativos pra render do checkbox
$users = $pdo->query("SELECT id, name, role FROM users WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$autorizadosIds = array_map('intval', array_filter(explode(',', $usersCsv)));

// Métricas
$gastoMes  = ia_gasto_mes_atual();
$diasMes   = (int)date('t');
$diaHoje   = (int)date('j');
$projecao  = $diaHoje > 0 ? round($gastoMes / $diaHoje * $diasMes, 2) : 0;
$pctOrc    = $orc > 0 ? round($gastoMes / $orc * 100, 1) : 0;

$gastoOntem = (float)$pdo->query("SELECT COALESCE(SUM(custo_brl),0) FROM ia_usage_log WHERE DATE(created_at)=DATE_SUB(CURDATE(), INTERVAL 1 DAY)")->fetchColumn();
$gastoHoje  = (float)$pdo->query("SELECT COALESCE(SUM(custo_brl),0) FROM ia_usage_log WHERE DATE(created_at)=CURDATE()")->fetchColumn();

// Por feature
$porFeat = $pdo->query("SELECT feature, COUNT(*) n, COALESCE(SUM(custo_brl),0) brl, COALESCE(SUM(input_tokens),0) tok_in, COALESCE(SUM(output_tokens),0) tok_out
                        FROM ia_usage_log WHERE YEAR(created_at)=YEAR(NOW()) AND MONTH(created_at)=MONTH(NOW())
                        GROUP BY feature ORDER BY brl DESC")->fetchAll(PDO::FETCH_ASSOC);
// Por usuário
$porUser = $pdo->query("SELECT u.name, l.user_id, COUNT(*) n, COALESCE(SUM(l.custo_brl),0) brl
                        FROM ia_usage_log l LEFT JOIN users u ON u.id = l.user_id
                        WHERE YEAR(l.created_at)=YEAR(NOW()) AND MONTH(l.created_at)=MONTH(NOW())
                        GROUP BY l.user_id ORDER BY brl DESC")->fetchAll(PDO::FETCH_ASSOC);
// Por dia (últimos 14)
$porDia = $pdo->query("SELECT DATE(created_at) d, COALESCE(SUM(custo_brl),0) brl, COUNT(*) n
                       FROM ia_usage_log WHERE created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
                       GROUP BY DATE(created_at) ORDER BY d ASC")->fetchAll(PDO::FETCH_ASSOC);
// Últimas 20
$ultimas = $pdo->query("SELECT l.*, u.name AS user_name FROM ia_usage_log l LEFT JOIN users u ON u.id = l.user_id ORDER BY l.id DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../../templates/layout_start.php';
?>

<div style="max-width:1100px;">
<h1 style="margin-bottom:.3rem;">🤖 IA — Custo e Configuração</h1>
<p style="color:#6b7280;margin-bottom:1.5rem;">Painel central do módulo de IA: gasto do mês, killswitches por feature, whitelist de usuários autorizados.</p>

<?php if (!empty($okCfg)): ?><div style="background:#dcfce7;border:1px solid #86efac;color:#15803d;padding:.5rem .8rem;border-radius:8px;margin-bottom:1rem;">✓ <?= e($okCfg) ?></div><?php endif; ?>
<?php if (!empty($erroCfg)): ?><div style="background:#fef2f2;border:1px solid #fca5a5;color:#b91c1c;padding:.5rem .8rem;border-radius:8px;margin-bottom:1rem;">⚠ <?= e($erroCfg) ?></div><?php endif; ?>

<!-- KPIs principais -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:.75rem;margin-bottom:1.5rem;">
    <div style="background:#fff;border:1px solid #e5e7eb;border-left:4px solid #6366f1;border-radius:8px;padding:.85rem 1rem;">
        <div style="font-size:.7rem;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;">Gasto deste mês</div>
        <div style="font-size:1.6rem;font-weight:700;color:#1e1b4b;margin-top:.1rem;">R$ <?= number_format($gastoMes, 2, ',', '.') ?></div>
        <div style="font-size:.7rem;color:#6b7280;">de R$ <?= number_format($orc, 2, ',', '.') ?> · <?= $pctOrc ?>%</div>
    </div>
    <div style="background:#fff;border:1px solid #e5e7eb;border-left:4px solid #059669;border-radius:8px;padding:.85rem 1rem;">
        <div style="font-size:.7rem;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;">Projeção fim do mês</div>
        <div style="font-size:1.6rem;font-weight:700;color:#065f46;margin-top:.1rem;">R$ <?= number_format($projecao, 2, ',', '.') ?></div>
        <div style="font-size:.7rem;color:#6b7280;">dia <?= $diaHoje ?>/<?= $diasMes ?></div>
    </div>
    <div style="background:#fff;border:1px solid #e5e7eb;border-left:4px solid #f59e0b;border-radius:8px;padding:.85rem 1rem;">
        <div style="font-size:.7rem;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;">Hoje</div>
        <div style="font-size:1.6rem;font-weight:700;color:#92400e;margin-top:.1rem;">R$ <?= number_format($gastoHoje, 2, ',', '.') ?></div>
        <div style="font-size:.7rem;color:#6b7280;">ontem: R$ <?= number_format($gastoOntem, 2, ',', '.') ?></div>
    </div>
    <div style="background:#fff;border:1px solid #e5e7eb;border-left:4px solid #6b7280;border-radius:8px;padding:.85rem 1rem;">
        <div style="font-size:.7rem;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;">Câmbio US→BRL</div>
        <div style="font-size:1.6rem;font-weight:700;color:#374151;margin-top:.1rem;">R$ <?= number_format($cambio, 2, ',', '.') ?></div>
    </div>
</div>

<?php if ($pctOrc >= 80): ?>
<div style="background:#fef2f2;border:1px solid #fca5a5;color:#b91c1c;padding:.7rem .9rem;border-radius:8px;margin-bottom:1rem;">
    ⚠ <strong>Atenção</strong>: gasto está em <?= $pctOrc ?>% do orçamento mensal. Considere desligar features menos críticas abaixo.
</div>
<?php endif; ?>

<!-- Configuração -->
<form method="POST" style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:1rem 1.2rem;margin-bottom:1.5rem;">
    <?= csrf_input() ?>
    <h3 style="margin-top:0;">Configuração</h3>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem;">
        <div>
            <label style="font-size:.8rem;font-weight:600;color:#374151;">Orçamento mensal (R$)</label>
            <input type="number" name="orcamento" value="<?= (int)$orc ?>" min="0" step="50" style="width:100%;padding:.4rem .6rem;border:1px solid #d1d5db;border-radius:6px;margin-top:.2rem;">
        </div>
        <div>
            <label style="font-size:.8rem;font-weight:600;color:#374151;">Câmbio USD→BRL (atualize se variar muito)</label>
            <input type="number" name="cambio" value="<?= number_format($cambio, 2, '.', '') ?>" min="1" step="0.01" style="width:100%;padding:.4rem .6rem;border:1px solid #d1d5db;border-radius:6px;margin-top:.2rem;">
        </div>
    </div>

    <div style="margin-bottom:1rem;">
        <label style="font-size:.8rem;font-weight:600;color:#374151;">Usuários autorizados a usar IA</label>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:.4rem;margin-top:.3rem;">
            <?php foreach ($users as $u): $on = in_array((int)$u['id'], $autorizadosIds, true); ?>
                <label style="display:flex;align-items:center;gap:.4rem;background:<?= $on ? '#ecfdf5' : '#f9fafb' ?>;border:1px solid <?= $on ? '#86efac' : '#e5e7eb' ?>;padding:.35rem .55rem;border-radius:6px;font-size:.8rem;cursor:pointer;">
                    <input type="checkbox" id="u_<?= (int)$u['id'] ?>" data-uid="<?= (int)$u['id'] ?>" class="usrChk" <?= $on ? 'checked' : '' ?>>
                    <span><?= e($u['name']) ?> <span style="color:#9ca3af;font-size:.7rem;">#<?= (int)$u['id'] ?> · <?= e($u['role']) ?></span></span>
                </label>
            <?php endforeach; ?>
        </div>
        <input type="hidden" name="users_autorizados" id="users_autorizados_hidden" value="<?= e($usersCsv) ?>">
    </div>

    <div style="margin-bottom:1rem;">
        <label style="font-size:.8rem;font-weight:600;color:#374151;">Features habilitadas (killswitch individual)</label>
        <div style="display:flex;gap:.6rem;margin-top:.3rem;flex-wrap:wrap;">
            <label style="display:flex;align-items:center;gap:.4rem;background:#f9fafb;border:1px solid #e5e7eb;padding:.4rem .7rem;border-radius:6px;font-size:.85rem;cursor:pointer;">
                <input type="checkbox" name="feat_resumo_caso" <?= $featResumo ? 'checked' : '' ?>>
                📋 Resumo automático do caso
            </label>
            <label style="display:flex;align-items:center;gap:.4rem;background:#f9fafb;border:1px solid #e5e7eb;padding:.4rem .7rem;border-radius:6px;font-size:.85rem;cursor:pointer;">
                <input type="checkbox" name="feat_classif_andamento" <?= $featClass ? 'checked' : '' ?>>
                🚦 Classificação de urgência (e-mail PJe)
            </label>
            <label style="display:flex;align-items:center;gap:.4rem;background:#f9fafb;border:1px solid #e5e7eb;padding:.4rem .7rem;border-radius:6px;font-size:.85rem;cursor:pointer;">
                <input type="checkbox" name="feat_cliente_esfriando" <?= $featEsfri ? 'checked' : '' ?>>
                ❄️ Detector de cliente esfriando (sem IA)
            </label>
            <label style="display:flex;align-items:center;gap:.4rem;background:#f9fafb;border:1px solid #e5e7eb;padding:.4rem .7rem;border-radius:6px;font-size:.85rem;cursor:pointer;">
                <input type="checkbox" name="feat_sugerir_acao" <?= $featSug ? 'checked' : '' ?>>
                ✨ Sugestão de próxima ação no caso
            </label>
            <label style="display:flex;align-items:center;gap:.4rem;background:#f9fafb;border:1px solid #e5e7eb;padding:.4rem .7rem;border-radius:6px;font-size:.85rem;cursor:pointer;">
                <input type="checkbox" name="feat_briefing" <?= $featBrief ? 'checked' : '' ?>>
                🌅 Briefing diário no Painel
            </label>
            <label style="display:flex;align-items:center;gap:.4rem;background:#f9fafb;border:1px solid #e5e7eb;padding:.4rem .7rem;border-radius:6px;font-size:.85rem;cursor:pointer;">
                <input type="checkbox" name="feat_resumo_wa_chamado" <?= $featRwa ? 'checked' : '' ?>>
                🤖 Resumo conversa WA pra chamado
            </label>
        </div>

        <!-- Fase 3: features que custam dinheiro e ficam OFF por default -->
        <div style="margin-top:.8rem;padding:.7rem;background:#fef9c3;border:1px solid #fde68a;border-radius:6px;">
            <div style="font-size:.78rem;font-weight:700;color:#92400e;margin-bottom:.4rem;">⚙️ Fase 3 — desligadas por padrão (têm custo). Ative só o que quiser.</div>
            <div style="display:flex;gap:.6rem;flex-wrap:wrap;">
                <label style="display:flex;align-items:center;gap:.4rem;background:#fff;border:1px solid #fde68a;padding:.4rem .7rem;border-radius:6px;font-size:.85rem;cursor:pointer;">
                    <input type="checkbox" name="feat_traducao_leiga" <?= $featTrad ? 'checked' : '' ?>>
                    📖 Tradução jurídico → leigo (Central VIP) <span style="color:#9ca3af;font-size:.72rem;">~R$1–2/mês</span>
                </label>
                <label style="display:flex;align-items:center;gap:.4rem;background:#fff;border:1px solid #fde68a;padding:.4rem .7rem;border-radius:6px;font-size:.85rem;cursor:pointer;">
                    <input type="checkbox" name="feat_revisao_peticao" <?= $featRev ? 'checked' : '' ?>>
                    🔍 Revisar petição com IA (Sonnet) <span style="color:#9ca3af;font-size:.72rem;">~R$9/mês</span>
                </label>
                <label style="display:flex;align-items:center;gap:.4rem;background:#fff;border:1px solid #fde68a;padding:.4rem .7rem;border-radius:6px;font-size:.85rem;cursor:pointer;">
                    <input type="checkbox" name="feat_sentiment_wa" <?= $featSent ? 'checked' : '' ?>>
                    🌡️ Detectar tom irritado no WhatsApp <span style="color:#9ca3af;font-size:.72rem;">~R$5–9/mês</span>
                </label>
                <label style="display:flex;align-items:center;gap:.4rem;background:#fff;border:1px solid #fde68a;padding:.4rem .7rem;border-radius:6px;font-size:.85rem;cursor:pointer;" title="Análise estratégica profunda da pasta (Sonnet) — pontos fortes/fracos, riscos, estratégia adversária, próximos movimentos. Cache 30 dias + invalida em andamento novo.">
                    <input type="checkbox" name="feat_analise_aprofundada" <?= $featAnaP ? 'checked' : '' ?>>
                    🧠 Análise estratégica do caso (Sonnet) <span style="color:#9ca3af;font-size:.72rem;">~R$0,20/análise · cache 30d</span>
                </label>
            </div>
        </div>
    </div>

    <button type="submit" style="background:#6366f1;color:#fff;border:none;padding:.5rem 1.1rem;border-radius:6px;font-weight:600;cursor:pointer;">Salvar</button>
</form>

<script>
(function(){
    var hidden = document.getElementById('users_autorizados_hidden');
    function syncCsv() {
        var ids = [];
        document.querySelectorAll('.usrChk').forEach(function(cb){ if (cb.checked) ids.push(cb.dataset.uid); });
        hidden.value = ids.join(',');
    }
    document.querySelectorAll('.usrChk').forEach(function(cb){
        cb.addEventListener('change', function(){
            var lbl = cb.closest('label');
            if (cb.checked) { lbl.style.background='#ecfdf5'; lbl.style.borderColor='#86efac'; }
            else            { lbl.style.background='#f9fafb'; lbl.style.borderColor='#e5e7eb'; }
            syncCsv();
        });
    });
    syncCsv();
})();
</script>

<!-- Por feature + Por usuário -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1.5rem;">
    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:1rem;">
        <h3 style="margin-top:0;">Por feature (mês corrente)</h3>
        <?php if (!$porFeat): ?><p style="color:#9ca3af;">Nenhuma chamada ainda este mês.</p><?php else: ?>
            <table style="width:100%;font-size:.85rem;">
                <thead><tr style="border-bottom:2px solid #e5e7eb;"><th style="text-align:left;padding:.3rem;">Feature</th><th style="text-align:right;">Chamadas</th><th style="text-align:right;">Tokens</th><th style="text-align:right;">R$</th></tr></thead>
                <tbody>
                <?php foreach ($porFeat as $f): ?>
                <tr style="border-bottom:1px solid #f3f4f6;">
                    <td style="padding:.3rem;"><?= e($f['feature']) ?></td>
                    <td style="text-align:right;"><?= (int)$f['n'] ?></td>
                    <td style="text-align:right;"><?= number_format((int)$f['tok_in']+(int)$f['tok_out'], 0, ',', '.') ?></td>
                    <td style="text-align:right;font-weight:600;">R$ <?= number_format((float)$f['brl'], 2, ',', '.') ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:1rem;">
        <h3 style="margin-top:0;">Por usuário (mês corrente)</h3>
        <?php if (!$porUser): ?><p style="color:#9ca3af;">Nenhuma chamada ainda este mês.</p><?php else: ?>
            <table style="width:100%;font-size:.85rem;">
                <thead><tr style="border-bottom:2px solid #e5e7eb;"><th style="text-align:left;padding:.3rem;">Usuário</th><th style="text-align:right;">Chamadas</th><th style="text-align:right;">R$</th></tr></thead>
                <tbody>
                <?php foreach ($porUser as $u): ?>
                <tr style="border-bottom:1px solid #f3f4f6;">
                    <td style="padding:.3rem;"><?= e($u['name'] ?: '— (sistema)') ?></td>
                    <td style="text-align:right;"><?= (int)$u['n'] ?></td>
                    <td style="text-align:right;font-weight:600;">R$ <?= number_format((float)$u['brl'], 2, ',', '.') ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<!-- Por dia -->
<div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:1rem;margin-bottom:1.5rem;">
    <h3 style="margin-top:0;">Por dia (últimos 14 dias)</h3>
    <?php if (!$porDia): ?><p style="color:#9ca3af;">Sem dados.</p><?php else: ?>
        <?php $maxBrl = max(array_map(function($r){ return (float)$r['brl']; }, $porDia)); $maxBrl = $maxBrl ?: 1; ?>
        <table style="width:100%;font-size:.8rem;">
            <?php foreach ($porDia as $d): $pct = round((float)$d['brl']/$maxBrl*100); ?>
                <tr><td style="width:80px;font-family:monospace;"><?= $d['d'] ?></td>
                    <td><div style="background:#6366f1;height:14px;border-radius:3px;width:<?= max(1,$pct) ?>%;display:inline-block;"></div></td>
                    <td style="width:90px;text-align:right;font-weight:600;">R$ <?= number_format((float)$d['brl'], 2, ',', '.') ?></td>
                    <td style="width:70px;text-align:right;color:#9ca3af;"><?= (int)$d['n'] ?> ch.</td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>
</div>

<!-- Últimas chamadas -->
<div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:1rem;">
    <h3 style="margin-top:0;">Últimas 20 chamadas</h3>
    <?php if (!$ultimas): ?><p style="color:#9ca3af;">Sem chamadas registradas ainda.</p><?php else: ?>
        <table style="width:100%;font-size:.75rem;">
            <thead><tr style="border-bottom:2px solid #e5e7eb;">
                <th style="text-align:left;padding:.3rem;">Quando</th>
                <th style="text-align:left;">Feature</th>
                <th style="text-align:left;">Modelo</th>
                <th style="text-align:left;">Usuário</th>
                <th style="text-align:right;">Tokens (in/out/cache)</th>
                <th style="text-align:right;">R$</th>
                <th style="text-align:right;">ms</th>
                <th>Status</th>
            </tr></thead>
            <tbody>
            <?php foreach ($ultimas as $l): ?>
            <tr style="border-bottom:1px solid #f3f4f6;">
                <td style="padding:.25rem;"><?= e(date('d/m H:i:s', strtotime($l['created_at']))) ?></td>
                <td><?= e($l['feature']) ?></td>
                <td><?= e($l['modelo']) ?></td>
                <td><?= e($l['user_name'] ?: '—') ?></td>
                <td style="text-align:right;font-family:monospace;"><?= (int)$l['input_tokens'] ?>/<?= (int)$l['output_tokens'] ?>/<?= (int)$l['cached_input_tokens'] ?></td>
                <td style="text-align:right;font-weight:600;">R$ <?= number_format((float)$l['custo_brl'], 4, ',', '.') ?></td>
                <td style="text-align:right;color:#9ca3af;"><?= (int)$l['duracao_ms'] ?></td>
                <td><?php
                    if ($l['status'] === 'ok') echo '<span style="color:#15803d;">✓ ok</span>';
                    else echo '<span style="color:#b91c1c;" title="'.e($l['erro']).'">✕ '.e($l['status']).'</span>';
                ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

</div>

<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
