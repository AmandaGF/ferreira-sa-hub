<?php
/**
 * Email Monitor — interface admin
 *
 * Acessível em: https://ferreiraesa.com.br/conecta/modules/email_monitor.php
 *
 * Mostra histórico das últimas 30 execuções do email_monitor_cron.php
 * e tem botão "Rodar agora" que dispara o cron via XHR (com chave fixa).
 *
 * Acesso: apenas admin (require_role('admin')).
 *
 * Padrões obrigatórios:
 *   - middleware.php pra auth
 *   - require_login() + has_min_role()
 *   - validate_csrf() pras ações de mutação (rodar manualmente)
 *   - layout_start.php / layout_end.php
 *   - e() pra escape
 *   - array() em vez de []
 *   - XHR em vez de fetch
 */

require_once __DIR__ . '/../core/middleware.php';
require_login();
if (!has_min_role('admin')) {
    flash_set('error', 'Acesso restrito a administradores.');
    redirect(url('modules/painel/'));
}

$pdo  = db();
$csrf = generate_csrf_token();

// Garante a tabela existe (idempotente — não modifica nada que já exista)
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS email_monitor_log (
        id int unsigned NOT NULL AUTO_INCREMENT,
        executado_em datetime NOT NULL,
        emails_lidos int DEFAULT 0,
        andamentos_inseridos int DEFAULT 0,
        emails_ignorados int DEFAULT 0,
        duplicatas_ignoradas int DEFAULT 0,
        erros int DEFAULT 0,
        detalhes text,
        modo varchar(20) DEFAULT 'cron',
        PRIMARY KEY (id),
        KEY idx_executado_em (executado_em)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$logs = $pdo->query(
    "SELECT id, executado_em, emails_lidos, andamentos_inseridos,
            emails_ignorados, duplicatas_ignoradas, erros, detalhes, modo
     FROM email_monitor_log
     ORDER BY id DESC
     LIMIT 30"
)->fetchAll(PDO::FETCH_ASSOC);

$totaisHoje = $pdo->query(
    "SELECT
        COALESCE(SUM(emails_lidos),0)          AS lidos,
        COALESCE(SUM(andamentos_inseridos),0)  AS inseridos,
        COALESCE(SUM(duplicatas_ignoradas),0)  AS dup,
        COALESCE(SUM(erros),0)                 AS erros
     FROM email_monitor_log
     WHERE DATE(executado_em) = CURDATE()"
)->fetch(PDO::FETCH_ASSOC);

$pageTitle = 'Email Monitor — Andamentos PJe';
require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.em-wrap   { max-width: 1100px; margin: 0 auto; }
.em-hdr    { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: .8rem; margin-bottom: 1rem; }
.em-hdr h1 { margin: 0; font-size: 1.4rem; color: var(--petrol-900); }
.em-stats  { display: grid; grid-template-columns: repeat(4, 1fr); gap: .8rem; margin-bottom: 1.2rem; }
.em-stat   { background: #fff; border: 1px solid var(--border); border-radius: 10px; padding: .75rem 1rem; }
.em-stat-l { font-size: .68rem; text-transform: uppercase; letter-spacing: .4px; color: var(--text-muted); font-weight: 700; }
.em-stat-v { font-size: 1.4rem; font-weight: 800; color: var(--petrol-900); margin-top: .15rem; }
.em-stat.green   .em-stat-v { color: #15803d; }
.em-stat.amber   .em-stat-v { color: #b45309; }
.em-stat.red     .em-stat-v { color: #b91c1c; }
.em-card   { background: #fff; border: 1px solid var(--border); border-radius: 12px; overflow: hidden; }
.em-card-h { padding: .8rem 1rem; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: .5rem; background: #fafafa; }
.em-card-h h3 { margin: 0; font-size: .92rem; color: var(--petrol-900); }
.em-tbl    { width: 100%; border-collapse: collapse; font-size: .82rem; }
.em-tbl th { padding: 8px 12px; text-align: left; font-weight: 700; font-size: .7rem; text-transform: uppercase; letter-spacing: .3px; color: var(--text-muted); border-bottom: 1px solid var(--border); background: #f9fafb; }
.em-tbl td { padding: 8px 12px; border-bottom: 1px solid #f3f4f6; vertical-align: top; }
.em-tbl tr:hover td { background: #fafbfc; }
.em-pill   { display: inline-block; padding: 1px 8px; border-radius: 99px; font-size: .65rem; font-weight: 700; text-transform: uppercase; letter-spacing: .3px; }
.em-pill.cron   { background: #e0f2fe; color: #0369a1; }
.em-pill.manual { background: #fef3c7; color: #b45309; }
.em-num.zero { color: #94a3b8; }
.em-num.ok   { color: #15803d; font-weight: 700; }
.em-num.dup  { color: #b45309; font-weight: 700; }
.em-num.err  { color: #b91c1c; font-weight: 700; }
.em-det      { font-family: ui-monospace, monospace; font-size: .72rem; color: var(--text-muted); white-space: pre-wrap; max-height: 120px; overflow: auto; padding: 6px 8px; background: #f8fafc; border-radius: 6px; border: 1px solid #e2e8f0; }
.em-det-toggle { background: none; border: 1px dashed var(--border); border-radius: 5px; padding: 2px 8px; font-size: .68rem; cursor: pointer; color: var(--petrol-900); }
.em-resultado { margin: .8rem 0; padding: .7rem 1rem; border-radius: 8px; display: none; font-family: ui-monospace, monospace; font-size: .8rem; white-space: pre-wrap; }
.em-resultado.show { display: block; }
.em-resultado.ok   { background: #f0fdf4; border: 1px solid #bbf7d0; color: #15803d; }
.em-resultado.err  { background: #fef2f2; border: 1px solid #fca5a5; color: #b91c1c; }
.em-btn-run  { background: #b87333; color: #fff; border: none; border-radius: 8px; padding: 8px 16px; font-weight: 700; cursor: pointer; font-size: .85rem; }
.em-btn-run:hover { background: #a05c20; }
.em-btn-run:disabled { opacity: .6; cursor: not-allowed; }
@media (max-width: 768px) {
    .em-stats { grid-template-columns: repeat(2, 1fr); }
}
</style>

<div class="em-wrap">

    <div class="em-hdr">
        <h1>📧 Email Monitor — Andamentos PJe</h1>
        <button type="button" class="em-btn-run" id="emBtnRun" onclick="emRodarAgora()">▶ Rodar agora</button>
    </div>

    <div style="font-size: .82rem; color: var(--text-muted); margin-bottom: 1rem;">
        Lê emails não lidos de <code><?= e('tjrj.pjeadm-LD@tjrj.jus.br') ?></code> na conta
        <code><?= e('andamentosfes@gmail.com') ?></code>, parseia movimentos e insere em
        <code>case_andamentos</code> com <code>tipo_origem='email_pje'</code> e deduplicação por hash MD5.
        Cron agendado pra <strong>3× ao dia: 08h, 13h e 19h</strong>.
    </div>

    <div class="em-stats">
        <div class="em-stat">
            <div class="em-stat-l">Hoje · Lidos</div>
            <div class="em-stat-v"><?= (int)$totaisHoje['lidos'] ?></div>
        </div>
        <div class="em-stat green">
            <div class="em-stat-l">Hoje · Inseridos</div>
            <div class="em-stat-v"><?= (int)$totaisHoje['inseridos'] ?></div>
        </div>
        <div class="em-stat amber">
            <div class="em-stat-l">Hoje · Duplicatas</div>
            <div class="em-stat-v"><?= (int)$totaisHoje['dup'] ?></div>
        </div>
        <div class="em-stat red">
            <div class="em-stat-l">Hoje · Erros</div>
            <div class="em-stat-v"><?= (int)$totaisHoje['erros'] ?></div>
        </div>
    </div>

    <div class="em-resultado" id="emResultado"></div>

    <div class="em-card">
        <div class="em-card-h">
            <h3>Histórico (últimas 30 execuções)</h3>
            <span style="font-size: .72rem; color: var(--text-muted);"><?= count($logs) ?> registro(s)</span>
        </div>
        <div style="overflow-x: auto;">
            <table class="em-tbl">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Quando</th>
                        <th>Modo</th>
                        <th>Lidos</th>
                        <th>Inseridos</th>
                        <th>Ignorados</th>
                        <th>Dup.</th>
                        <th>Erros</th>
                        <th>Detalhes</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($logs)): ?>
                    <tr><td colspan="9" style="text-align:center; color: var(--text-muted); padding: 2rem;">
                        Nenhuma execução registrada ainda. Clique em <strong>Rodar agora</strong> pra testar.
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($logs as $row): ?>
                        <?php
                            $modoClasse = ($row['modo'] === 'manual') ? 'manual' : 'cron';
                            $temDet     = !empty(trim((string)$row['detalhes']));
                        ?>
                        <tr>
                            <td><?= (int)$row['id'] ?></td>
                            <td style="white-space: nowrap;"><?= e(date('d/m/Y H:i:s', strtotime($row['executado_em']))) ?></td>
                            <td><span class="em-pill <?= e($modoClasse) ?>"><?= e($row['modo']) ?></span></td>
                            <td><span class="em-num <?= ((int)$row['emails_lidos'] === 0) ? 'zero' : '' ?>"><?= (int)$row['emails_lidos'] ?></span></td>
                            <td><span class="em-num <?= ((int)$row['andamentos_inseridos'] > 0) ? 'ok' : 'zero' ?>"><?= (int)$row['andamentos_inseridos'] ?></span></td>
                            <td><span class="em-num <?= ((int)$row['emails_ignorados'] === 0) ? 'zero' : '' ?>"><?= (int)$row['emails_ignorados'] ?></span></td>
                            <td><span class="em-num <?= ((int)$row['duplicatas_ignoradas'] > 0) ? 'dup' : 'zero' ?>"><?= (int)$row['duplicatas_ignoradas'] ?></span></td>
                            <td><span class="em-num <?= ((int)$row['erros'] > 0) ? 'err' : 'zero' ?>"><?= (int)$row['erros'] ?></span></td>
                            <td>
                                <?php if ($temDet): ?>
                                    <button type="button" class="em-det-toggle" onclick="emToggleDet(<?= (int)$row['id'] ?>)">ver/ocultar</button>
                                    <div id="emDet<?= (int)$row['id'] ?>" class="em-det" style="display:none; margin-top:6px;"><?= e($row['detalhes']) ?></div>
                                <?php else: ?>
                                    <span style="color:#cbd5e1;">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div style="margin-top: 1rem; padding: .8rem 1rem; background: #f8fafc; border-radius: 8px; border: 1px solid #e2e8f0; font-size: .78rem; color: var(--text-muted);">
        <strong>Cron sugerido (cPanel TurboCloud):</strong>
        <pre style="margin: .4rem 0 0; font-size: .76rem; color: #334155; background: #fff; padding: .5rem; border-radius: 6px; overflow-x: auto; border: 1px solid #e5e7eb;">0 8,13,19 * * * curl -s "https://ferreiraesa.com.br/conecta/email_monitor_cron.php?key=fsa-hub-deploy-2026" > /dev/null</pre>
    </div>

</div>

<script>
(function(){
    var EM_KEY  = 'fsa-hub-deploy-2026';
    var EM_URL  = '<?= url('email_monitor_cron.php') ?>?key=' + encodeURIComponent(EM_KEY);
    var EM_CSRF = '<?= e($csrf) ?>';

    window.emRodarAgora = function() {
        var btn = document.getElementById('emBtnRun');
        var box = document.getElementById('emResultado');
        if (!btn) return;

        if (!confirm('Disparar leitura manual da caixa de entrada agora?')) return;

        btn.disabled = true;
        var labelOriginal = btn.textContent;
        btn.textContent = 'Executando...';

        box.className = 'em-resultado show';
        box.textContent = 'Conectando ao servidor de email...';

        var xhr = new XMLHttpRequest();
        xhr.open('GET', EM_URL, true);
        xhr.setRequestHeader('X-Csrf-Token', EM_CSRF);
        xhr.setRequestHeader('X-Api-Key', EM_KEY);
        xhr.timeout = 120000; // 2 min
        xhr.onload = function() {
            btn.disabled = false;
            btn.textContent = labelOriginal;
            if (xhr.status >= 200 && xhr.status < 300) {
                box.className = 'em-resultado show ok';
                box.textContent = xhr.responseText || 'Execução concluída.';
                // Recarrega a tabela após 2s pra mostrar o novo log
                setTimeout(function(){ window.location.reload(); }, 2000);
            } else {
                box.className = 'em-resultado show err';
                box.textContent = 'HTTP ' + xhr.status + '\n' + xhr.responseText;
            }
        };
        xhr.onerror = function() {
            btn.disabled = false;
            btn.textContent = labelOriginal;
            box.className = 'em-resultado show err';
            box.textContent = 'Erro de rede. Verifique a conexão e tente de novo.';
        };
        xhr.ontimeout = function() {
            btn.disabled = false;
            btn.textContent = labelOriginal;
            box.className = 'em-resultado show err';
            box.textContent = 'Timeout (>2 min). O processo pode estar rodando em background — recarregue a página em alguns minutos.';
        };
        xhr.send();
    };

    window.emToggleDet = function(id) {
        var el = document.getElementById('emDet' + id);
        if (!el) return;
        el.style.display = (el.style.display === 'none' || el.style.display === '') ? 'block' : 'none';
    };
})();
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
