<?php
/**
 * Jorjão — configuração das 4 tocadas expandidas + templates variados.
 *
 * A tocada 'contrato_assinado' continua em comemorar_contrato.php (canal +
 * grupo + template inline). Este arquivo cuida do resto:
 *   - peticao_distribuida
 *   - prazo_cumprido
 *   - novidade_hub    (com botão "tocar sino agora")
 *   - resumo_diario   (cron 19h com IA)
 *
 * Amanda 06/07/2026.
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
if (!has_min_role('admin')) {
    flash_set('error', 'Acesso restrito.');
    redirect(url('modules/dashboard/index.php'));
}

require_once APP_ROOT . '/core/functions_comemoracao.php';
require_once APP_ROOT . '/core/functions_jorjao.php';

$pdo = db();
$pageTitle = '🔔 Jorjão — Sinos automáticos';

// Helpers
function _cfg_set($pdo, $chave, $valor) {
    $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES (?, ?)
                   ON DUPLICATE KEY UPDATE valor = VALUES(valor)")
        ->execute(array($chave, $valor));
}
function _cfg_get($pdo, $chave, $default = '') {
    $v = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = ?");
    $v->execute(array($chave));
    $r = $v->fetchColumn();
    return $r === false || $r === null ? $default : (string)$r;
}
// Força envio bypassando killswitch (usado só no botão "testar tocada")
function jorjao_enviar_forcado($tocada, $vars) {
    $g = jorjao_grupo_config();
    if (!$g['grupo_id']) return array('ok' => false, 'erro' => 'Grupo não configurado');
    $tpl = jorjao_pick_template($tocada);
    if (!$tpl) return array('ok' => false, 'erro' => 'Nenhum template ativo');
    $mensagem = jorjao_render($tpl['template'], $vars);
    $r = zapi_send_text($g['canal'], $g['grupo_id'], $mensagem);
    if (!empty($r['ok'])) jorjao_marcar_usado((int)$tpl['id']);
    return array('ok' => !empty($r['ok']), 'erro' => $r['ok'] ? null : ($r['erro'] ?? '?'), 'mensagem' => $mensagem);
}

// ── POST ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) { flash_set('error', 'CSRF inválido'); redirect($_SERVER['REQUEST_URI']); }
    $act = $_POST['action'] ?? '';

    if ($act === 'salvar_flags') {
        _cfg_set($pdo, 'jorjao_peticao_distribuida_ativo', !empty($_POST['peticao']) ? '1' : '0');
        _cfg_set($pdo, 'jorjao_prazo_cumprido_ativo',     !empty($_POST['prazo']) ? '1' : '0');
        _cfg_set($pdo, 'jorjao_novidade_hub_ativo',       !empty($_POST['novidade']) ? '1' : '0');
        _cfg_set($pdo, 'jorjao_resumo_diario_ativo',      !empty($_POST['resumo']) ? '1' : '0');
        $hora = (int)($_POST['resumo_hora'] ?? 19);
        if ($hora >= 0 && $hora <= 23) _cfg_set($pdo, 'jorjao_resumo_diario_hora', (string)$hora);
        $minMsgs = max(1, (int)($_POST['resumo_min_msgs'] ?? 5));
        _cfg_set($pdo, 'jorjao_resumo_diario_min_msgs', (string)$minMsgs);
        flash_set('success', '✓ Configurações salvas.');
        redirect($_SERVER['REQUEST_URI']);
    }

    if ($act === 'template_salvar') {
        $id = (int)($_POST['id'] ?? 0);
        $tocada = $_POST['tocada'] ?? '';
        $tpl = trim($_POST['template'] ?? '');
        $ativo = !empty($_POST['ativo']) ? 1 : 0;
        if (!in_array($tocada, array('contrato_assinado','peticao_distribuida','prazo_cumprido','novidade_hub'), true)) {
            flash_set('error', 'Tocada inválida'); redirect($_SERVER['REQUEST_URI']);
        }
        if (!$tpl) { flash_set('error', 'Template vazio'); redirect($_SERVER['REQUEST_URI']); }
        if ($id > 0) {
            $pdo->prepare("UPDATE jorjao_templates SET template = ?, ativo = ? WHERE id = ?")
                ->execute(array($tpl, $ativo, $id));
            flash_set('success', 'Template atualizado.');
        } else {
            $pdo->prepare("INSERT INTO jorjao_templates (tocada, template, ativo, ordem) VALUES (?, ?, ?, 99)")
                ->execute(array($tocada, $tpl, $ativo));
            flash_set('success', 'Template criado.');
        }
        redirect($_SERVER['REQUEST_URI'] . '#' . $tocada);
    }

    if ($act === 'template_excluir') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $tocada = $pdo->prepare("SELECT tocada FROM jorjao_templates WHERE id = ?");
            $tocada->execute(array($id));
            $t = $tocada->fetchColumn();
            $pdo->prepare("DELETE FROM jorjao_templates WHERE id = ?")->execute(array($id));
            flash_set('success', 'Template excluído.');
            redirect($_SERVER['REQUEST_URI'] . ($t ? '#' . $t : ''));
        }
        redirect($_SERVER['REQUEST_URI']);
    }

    if ($act === 'novidade_disparar') {
        $titulo = trim($_POST['nov_titulo'] ?? '');
        $desc   = trim($_POST['nov_desc'] ?? '');
        $link   = trim($_POST['nov_link'] ?? '');
        if ($titulo === '' || $desc === '') {
            flash_set('error', 'Título e descrição são obrigatórios.');
            redirect($_SERVER['REQUEST_URI'] . '#novidade_hub');
        }
        $r = jorjao_novidade_hub($titulo, $desc, $link);
        if (!empty($r['ok'])) {
            flash_set('success', '🔔 Jorjão tocou! Confira o grupo.');
        } else {
            flash_set('error', '⚠️ Falhou: ' . ($r['erro'] ?? 'erro desconhecido'));
        }
        redirect($_SERVER['REQUEST_URI'] . '#novidade_hub');
    }

    if ($act === 'testar_tocada') {
        $tocada = $_POST['tocada'] ?? '';
        if (!in_array($tocada, array('peticao_distribuida','prazo_cumprido'), true)) {
            flash_set('error', 'Tocada inválida pra teste');
            redirect($_SERVER['REQUEST_URI']);
        }
        $varsDemo = array(
            'peticao_distribuida' => array(
                'cliente' => 'Cliente Teste da Silva', 'operacional' => 'Duda',
                'tipo_caso' => 'Divórcio Consensual', 'numero_processo' => '0817952-56.2025.8.19.0202',
                'hoje' => date('d/m/Y'),
            ),
            'prazo_cumprido' => array(
                'cliente' => 'Cliente Teste da Silva', 'operacional' => 'Amanda',
                'tipo_prazo' => 'Contestação — 15 dias', 'processo' => '0817952-56.2025.8.19.0202',
                'hoje' => date('d/m/Y'),
            ),
        );
        // Força bypass do killswitch pra teste
        $cache = null; // ignora cache estatica
        $r = jorjao_enviar_forcado($tocada, $varsDemo[$tocada]);
        if (!empty($r['ok'])) {
            flash_set('success', '🔔 Jorjão testou a tocada "' . $tocada . '"! Confira o grupo.');
        } else {
            flash_set('error', '⚠️ Teste falhou: ' . ($r['erro'] ?? '?'));
        }
        redirect($_SERVER['REQUEST_URI'] . '#' . $tocada);
    }

    if ($act === 'resumo_disparar') {
        // Chama o cron via HTTP (mais simples que reimplementar toda a lógica)
        $url = 'https://ferreiraesa.com.br/conecta/cron/jorjao_resumo_diario.php?key=fsa-hub-deploy-2026&forcar=1';
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 90,
        ));
        $out = curl_exec($ch);
        curl_close($ch);
        // Guarda o output pra Amanda ver o que aconteceu
        _cfg_set($pdo, 'jorjao_resumo_ultimo_debug', substr((string)$out, 0, 5000));
        flash_set('success', '🔔 Resumo disparado! (verifique o grupo e o debug abaixo)');
        redirect($_SERVER['REQUEST_URI'] . '#resumo_diario');
    }
}

// Estado
$cfgComemo = comemoracao_get_config(); // canal + grupo compartilhados
$flagPeticao  = _cfg_get($pdo, 'jorjao_peticao_distribuida_ativo', '0') === '1';
$flagPrazo    = _cfg_get($pdo, 'jorjao_prazo_cumprido_ativo', '0') === '1';
$flagNovidade = _cfg_get($pdo, 'jorjao_novidade_hub_ativo', '1') === '1';
$flagResumo   = _cfg_get($pdo, 'jorjao_resumo_diario_ativo', '0') === '1';
$resumoHora   = (int)_cfg_get($pdo, 'jorjao_resumo_diario_hora', '19');
$resumoMinMsgs = (int)_cfg_get($pdo, 'jorjao_resumo_diario_min_msgs', '5');
$resumoUltimo = _cfg_get($pdo, 'jorjao_resumo_ultimo_em', '');
$resumoDebug  = _cfg_get($pdo, 'jorjao_resumo_ultimo_debug', '');

// Templates agrupados por tocada
$tpls = array('contrato_assinado' => array(), 'peticao_distribuida' => array(), 'prazo_cumprido' => array(), 'novidade_hub' => array());
$stTpl = $pdo->query("SELECT * FROM jorjao_templates ORDER BY tocada, ordem, id");
foreach ($stTpl->fetchAll(PDO::FETCH_ASSOC) as $t) {
    if (isset($tpls[$t['tocada']])) $tpls[$t['tocada']][] = $t;
}

$abaAtiva = $_GET['aba'] ?? 'peticao_distribuida';
if (!in_array($abaAtiva, array('peticao_distribuida','prazo_cumprido','novidade_hub','resumo_diario','contrato_assinado','templates'), true)) $abaAtiva = 'peticao_distribuida';

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.jz-wrap { max-width: 1050px; margin: 0 auto; }
.jz-hero { background: linear-gradient(135deg,#052228,#B87333); color:#fff; padding: 1.2rem 1.5rem; border-radius: 14px; margin-bottom: 1.25rem; }
.jz-hero h1 { font-family: 'Cormorant Garamond', serif; font-size: 1.8rem; margin: 0 0 .3rem; font-weight: 600; color:#fff; }
.jz-hero p { margin: 0; font-size: .88rem; opacity: .9; }

.jz-alert { background:#fef3c7; border-left:4px solid #d97706; padding:.8rem 1rem; border-radius:0 8px 8px 0; font-size:.85rem; margin-bottom:1rem; }
.jz-alert a { color:#78350f; font-weight:600; }

.jz-tabs { display:flex; gap:4px; border-bottom: 2px solid #e5e7eb; margin-bottom: 1.25rem; flex-wrap:wrap; }
.jz-tab { padding: 10px 16px; background: transparent; border: none; border-bottom: 3px solid transparent; font-weight: 600; color: #64748b; cursor: pointer; font-size: .87rem; text-decoration: none; display:flex; align-items:center; gap:6px; }
.jz-tab:hover { color: #B87333; }
.jz-tab.on { color: #052228; border-bottom-color: #B87333; }
.jz-tab .pill { padding:1px 8px; border-radius:10px; font-size:.65rem; font-weight:700; }
.jz-tab .pill.on { background:#d1fae5; color:#065f46; }
.jz-tab .pill.off { background:#fee2e2; color:#991b1b; }

.jz-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:1.2rem 1.4rem; margin-bottom:1rem; }
.jz-card h2 { font-family: 'Cormorant Garamond', serif; font-size: 1.35rem; margin: 0 0 .5rem; color:#052228; font-weight:600; }
.jz-card .lede { color:#64748b; font-size:.85rem; margin: 0 0 1rem; }

.jz-vars { font-size:.75rem; color:#78350f; background:#f5ede3; padding:.5rem .7rem; border-radius:6px; margin-bottom:.75rem; }
.jz-vars code { background: rgba(184,115,51,.15); padding: 1px 6px; border-radius: 4px; font-family: 'JetBrains Mono', ui-monospace, monospace; font-size: .88em; }

.jz-tpl-list { display:flex; flex-direction:column; gap:.55rem; }
.jz-tpl { background:#faf7f2; border:1px solid #e5e7eb; border-radius:8px; padding:.7rem .85rem; display:flex; gap:.7rem; align-items:flex-start; }
.jz-tpl.off { opacity:.55; background:#f3f4f6; }
.jz-tpl-body { flex:1; }
.jz-tpl-body pre { white-space: pre-wrap; margin:0; font-family: inherit; font-size:.82rem; color:#334155; line-height:1.45; word-break:break-word; }
.jz-tpl-actions { display:flex; gap:6px; flex-shrink:0; }
.jz-tpl-actions .btn-mini { padding:3px 10px; border-radius:6px; font-size:.7rem; font-weight:600; text-decoration:none; border:1px solid transparent; cursor:pointer; }
.jz-btn-edit { background:#e0f2fe; color:#075985; border-color:#bae6fd; }
.jz-btn-del { background:#fee2e2; color:#991b1b; border-color:#fecaca; }
.jz-btn-off { background:#f3f4f6; color:#4b5563; border-color:#e5e7eb; }
.jz-btn-on { background:#d1fae5; color:#065f46; border-color:#a7f3d0; }

.jz-add-form { margin-top: 1rem; padding: 1rem; background:#f5faff; border:1px dashed #93c5fd; border-radius:8px; }
.jz-add-form textarea { width:100%; min-height:110px; padding:.7rem; border:1.5px solid #e5e7eb; border-radius:7px; font-family:inherit; font-size:.85rem; box-sizing:border-box; }
.jz-add-form .actions { display:flex; gap:.5rem; margin-top:.55rem; align-items:center; }

.jz-toggle-line { display:flex; align-items:center; gap:.6rem; padding:.8rem 1rem; background:#fafbfc; border-radius:8px; margin-bottom:.6rem; }
.jz-toggle-line input[type=checkbox] { transform:scale(1.3); }
.jz-toggle-line .info { flex:1; }
.jz-toggle-line .info b { display:block; }
.jz-toggle-line .info small { color:#64748b; font-size:.75rem; }

.jz-btn-primary { background: #052228; color:#fff; border:none; padding:8px 16px; border-radius:7px; font-weight:600; cursor:pointer; font-size:.85rem; }
.jz-btn-primary:hover { background:#0d3640; }
.jz-btn-good { background:#059669; color:#fff; border:none; padding:8px 16px; border-radius:7px; font-weight:600; cursor:pointer; font-size:.85rem; }
.jz-btn-good:hover { background:#047857; }
.jz-btn-outline { background:transparent; color:#052228; border:1.5px solid #e5e7eb; padding:8px 14px; border-radius:7px; font-weight:600; cursor:pointer; font-size:.82rem; }

.jz-tab-content { display: none; }
.jz-tab-content.on { display: block; }

.jz-debug { background:#0f172a; color:#e2e8f0; padding:.9rem 1rem; border-radius:8px; font-family:'JetBrains Mono', monospace; font-size:.72rem; white-space:pre-wrap; max-height:280px; overflow:auto; margin-top:.7rem; }
</style>

<div class="jz-wrap">

<div class="jz-hero">
  <h1>🐻 Jorjão — Sinos automáticos no grupo</h1>
  <p>O mascote do escritório que toca sino no grupo quando algo bom acontece. Configure aqui quais tocadas ativar e como ele fala.</p>
</div>

<?php if (!$cfgComemo['grupo_id']): ?>
<div class="jz-alert">
  ⚠️ <strong>O grupo do WhatsApp ainda não está configurado.</strong>
  Antes de ligar qualquer tocada, vá em <a href="<?= url('modules/admin/comemorar_contrato.php') ?>">🔔 Comemorar Contrato</a> e escolha o grupo + canal.
</div>
<?php endif; ?>

<!-- Painel de flags principal -->
<div class="jz-card">
  <h2>⚙️ Ligar / Desligar tocadas</h2>
  <p class="lede">Todas começam DESLIGADAS por segurança. Marque as que quer ativar e salve.</p>
  <form method="POST">
    <?= csrf_input() ?>
    <input type="hidden" name="action" value="salvar_flags">

    <div class="jz-toggle-line">
      <input type="checkbox" name="peticao" value="1" <?= $flagPeticao ? 'checked' : '' ?> id="fp">
      <label for="fp" class="info" style="cursor:pointer;">
        <b>🎯 Petição inicial distribuída</b>
        <small>Toca quando um caso recebe número CNJ ou entra em "em_andamento" pela primeira vez. Roda via cron a cada 10 min.</small>
      </label>
    </div>

    <div class="jz-toggle-line">
      <input type="checkbox" name="prazo" value="1" <?= $flagPrazo ? 'checked' : '' ?> id="fpz">
      <label for="fpz" class="info" style="cursor:pointer;">
        <b>⏰ Prazo processual cumprido</b>
        <small>Toca quando alguém marca um prazo como concluído (na tela Prazos, Painel do Dia, tarefa vinculada, ou pasta do processo).</small>
      </label>
    </div>

    <div class="jz-toggle-line">
      <input type="checkbox" name="novidade" value="1" <?= $flagNovidade ? 'checked' : '' ?> id="fn">
      <label for="fn" class="info" style="cursor:pointer;">
        <b>🎁 Novidade no Hub (manual)</b>
        <small>Só toca quando você aciona pela aba "Novidade no Hub" abaixo. Bom pra anunciar nova função + treinamento.</small>
      </label>
    </div>

    <div class="jz-toggle-line">
      <input type="checkbox" name="resumo" value="1" <?= $flagResumo ? 'checked' : '' ?> id="fr">
      <label for="fr" class="info" style="cursor:pointer;">
        <b>📆 Resumo diário do grupo (IA)</b>
        <small>Cron todo dia às <input type="number" name="resumo_hora" value="<?= $resumoHora ?>" min="0" max="23" style="width:52px;padding:2px 4px;">h — só resume se tiver ao menos <input type="number" name="resumo_min_msgs" value="<?= $resumoMinMsgs ?>" min="1" style="width:52px;padding:2px 4px;"> mensagens de texto. Custo Anthropic Haiku ~R$ 0,05/dia.</small>
      </label>
    </div>

    <button type="submit" class="jz-btn-primary">Salvar configurações</button>
  </form>
</div>

<!-- Abas por tocada -->
<div class="jz-tabs">
  <a href="?aba=peticao_distribuida#peticao_distribuida" class="jz-tab <?= $abaAtiva === 'peticao_distribuida' ? 'on' : '' ?>" data-aba="peticao_distribuida">🎯 Petição <span class="pill <?= $flagPeticao ? 'on' : 'off' ?>"><?= $flagPeticao ? 'ON' : 'OFF' ?></span></a>
  <a href="?aba=prazo_cumprido#prazo_cumprido" class="jz-tab <?= $abaAtiva === 'prazo_cumprido' ? 'on' : '' ?>" data-aba="prazo_cumprido">⏰ Prazo <span class="pill <?= $flagPrazo ? 'on' : 'off' ?>"><?= $flagPrazo ? 'ON' : 'OFF' ?></span></a>
  <a href="?aba=novidade_hub#novidade_hub" class="jz-tab <?= $abaAtiva === 'novidade_hub' ? 'on' : '' ?>" data-aba="novidade_hub">🎁 Novidade <span class="pill <?= $flagNovidade ? 'on' : 'off' ?>"><?= $flagNovidade ? 'ON' : 'OFF' ?></span></a>
  <a href="?aba=resumo_diario#resumo_diario" class="jz-tab <?= $abaAtiva === 'resumo_diario' ? 'on' : '' ?>" data-aba="resumo_diario">📆 Resumo <span class="pill <?= $flagResumo ? 'on' : 'off' ?>"><?= $flagResumo ? 'ON' : 'OFF' ?></span></a>
</div>

<?php
// Info de cada tocada
$tocadaInfo = array(
    'peticao_distribuida' => array(
        'titulo' => '🎯 Petição inicial distribuída',
        'lede'   => 'Toca quando um caso ganha número CNJ ou muda pra "em_andamento" — sinal de que a petição inicial foi ao fórum.',
        'vars'   => array('cliente','operacional','tipo_caso','numero_processo','hoje'),
    ),
    'prazo_cumprido' => array(
        'titulo' => '⏰ Prazo processual cumprido',
        'lede'   => 'Toca quando um prazo é marcado como concluído em qualquer lugar do Hub.',
        'vars'   => array('cliente','operacional','tipo_prazo','processo','hoje'),
    ),
    'novidade_hub' => array(
        'titulo' => '🎁 Anúncio de novidade no Hub',
        'lede'   => 'Manual. Você preenche título + descrição + link e clica "Tocar sino agora".',
        'vars'   => array('titulo','descricao','link','hoje'),
    ),
);

foreach ($tocadaInfo as $tocKey => $info): ?>
<div class="jz-tab-content <?= $abaAtiva === $tocKey ? 'on' : '' ?>" id="<?= $tocKey ?>" data-aba="<?= $tocKey ?>">
  <div class="jz-card">
    <h2><?= e($info['titulo']) ?></h2>
    <p class="lede"><?= e($info['lede']) ?></p>

    <div class="jz-vars">
      <strong>Variáveis disponíveis:</strong>
      <?php foreach ($info['vars'] as $v): ?><code>[<?= $v ?>]</code> <?php endforeach; ?>
    </div>

    <?php if ($tocKey === 'novidade_hub'): ?>
      <!-- Form especial: Tocar sino agora -->
      <form method="POST" style="background:#fef3c7; padding:1rem; border-radius:10px; margin:1rem 0;">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="novidade_disparar">
        <h3 style="margin:0 0 .75rem;font-size:1rem;color:#78350f;">🔔 Anunciar novidade agora</h3>
        <div style="display:grid;gap:.6rem;">
          <div>
            <label style="font-size:.72rem;font-weight:700;text-transform:uppercase;color:#78350f;">Título</label>
            <input type="text" name="nov_titulo" required placeholder="Ex: Novo módulo Agendar Mensagem WhatsApp" style="width:100%;padding:8px;border:1.5px solid #d97706;border-radius:6px;">
          </div>
          <div>
            <label style="font-size:.72rem;font-weight:700;text-transform:uppercase;color:#78350f;">Descrição curta</label>
            <textarea name="nov_desc" required rows="3" placeholder="Ex: Programe mensagens WhatsApp pra sair sozinhas em data e hora específica." style="width:100%;padding:8px;border:1.5px solid #d97706;border-radius:6px;font-family:inherit;"></textarea>
          </div>
          <div>
            <label style="font-size:.72rem;font-weight:700;text-transform:uppercase;color:#78350f;">Link do treinamento (opcional)</label>
            <input type="url" name="nov_link" placeholder="https://ferreiraesa.com.br/conecta/modules/treinamento/modulo.php?slug=..." style="width:100%;padding:8px;border:1.5px solid #d97706;border-radius:6px;">
          </div>
          <div>
            <button type="submit" class="jz-btn-good" style="background:#d97706;">🔔 Tocar sino agora</button>
            <?php if (!$flagNovidade): ?><small style="color:#991b1b;font-size:.75rem;">⚠ Killswitch OFF — ligue no painel acima primeiro.</small><?php endif; ?>
          </div>
        </div>
      </form>
    <?php elseif (in_array($tocKey, array('peticao_distribuida','prazo_cumprido'))): ?>
      <form method="POST" style="margin-bottom:.75rem;">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="testar_tocada">
        <input type="hidden" name="tocada" value="<?= $tocKey ?>">
        <button type="submit" class="jz-btn-outline">🔊 Testar essa tocada agora (sorteia um template e manda no grupo)</button>
      </form>
    <?php endif; ?>

    <h3 style="font-size:.95rem;margin: 1.2rem 0 .5rem;color:#052228;">Variações do template (sorteadas aleatoriamente)</h3>

    <div class="jz-tpl-list">
      <?php foreach ($tpls[$tocKey] as $t): ?>
      <div class="jz-tpl <?= $t['ativo'] ? '' : 'off' ?>">
        <div class="jz-tpl-body">
          <pre><?= e($t['template']) ?></pre>
          <?php if (!$t['ativo']): ?><small style="color:#991b1b;font-size:.7rem;">DESATIVADO</small><?php endif; ?>
        </div>
        <div class="jz-tpl-actions">
          <button type="button" class="btn-mini jz-btn-edit" onclick="jzEdit(<?= (int)$t['id'] ?>, this)">✎ Editar</button>
          <form method="POST" style="display:inline;" onsubmit="return confirm('Excluir esse template?')">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="template_excluir">
            <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
            <button type="submit" class="btn-mini jz-btn-del">🗑</button>
          </form>
        </div>
      </div>
      <?php endforeach; ?>
      <?php if (empty($tpls[$tocKey])): ?>
        <div style="padding:1rem;text-align:center;color:#94a3b8;font-style:italic;background:#f9fafb;border-radius:8px;">Nenhum template ainda. Adicione abaixo.</div>
      <?php endif; ?>
    </div>

    <!-- Form editar/adicionar -->
    <div class="jz-add-form">
      <form method="POST" id="form-<?= $tocKey ?>">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="template_salvar">
        <input type="hidden" name="tocada" value="<?= $tocKey ?>">
        <input type="hidden" name="id" value="0">
        <label style="font-size:.75rem;font-weight:700;text-transform:uppercase;color:#052228;">Novo template</label>
        <textarea name="template" placeholder="Escreva o template com variáveis tipo [cliente], [operacional]..." required></textarea>
        <div class="actions">
          <label style="display:inline-flex;align-items:center;gap:5px;font-size:.85rem;"><input type="checkbox" name="ativo" value="1" checked> Ativo</label>
          <button type="submit" class="jz-btn-primary">Salvar template</button>
        </div>
      </form>
    </div>

  </div>
</div>
<?php endforeach; ?>

<!-- Aba especial: Resumo diário -->
<div class="jz-tab-content <?= $abaAtiva === 'resumo_diario' ? 'on' : '' ?>" id="resumo_diario">
  <div class="jz-card">
    <h2>📆 Resumo diário do grupo</h2>
    <p class="lede">Cron todo dia às <b><?= $resumoHora ?>h</b> lê as mensagens do grupo do dia (mínimo <?= $resumoMinMsgs ?>) e pede pro Claude Haiku fazer um resumo no estilo Jorjão.</p>

    <?php if ($resumoUltimo): ?>
      <div style="background:#d1fae5; padding:.7rem 1rem; border-radius:8px; font-size:.85rem; margin-bottom:1rem;">
        ✓ Último resumo enviado: <b><?= date('d/m/Y', strtotime($resumoUltimo)) ?></b>
      </div>
    <?php endif; ?>

    <form method="POST" style="margin-bottom:1rem;">
      <?= csrf_input() ?>
      <input type="hidden" name="action" value="resumo_disparar">
      <button type="submit" class="jz-btn-outline">🔊 Testar agora (roda o cron com forcar=1)</button>
    </form>

    <?php if ($resumoDebug): ?>
      <h3 style="font-size:.9rem;color:#052228;">Debug do último teste</h3>
      <div class="jz-debug"><?= e($resumoDebug) ?></div>
    <?php endif; ?>
  </div>
</div>

</div>

<script>
function jzEdit(id, btn) {
  var tpl = btn.closest('.jz-tpl');
  var texto = tpl.querySelector('pre').textContent;
  var tocada = tpl.closest('.jz-tab-content').dataset.aba;
  var form = document.getElementById('form-' + tocada);
  if (!form) return;
  form.querySelector('input[name="id"]').value = id;
  form.querySelector('textarea[name="template"]').value = texto;
  form.querySelector('button[type=submit]').textContent = 'Atualizar template #' + id;
  form.querySelector('label').textContent = 'Editando template #' + id;
  form.scrollIntoView({behavior:'smooth', block:'center'});
}
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
