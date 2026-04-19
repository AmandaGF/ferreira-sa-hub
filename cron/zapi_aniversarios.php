<?php
/**
 * Cron: envio automático de parabéns de aniversário via WhatsApp.
 *
 * Como rodar:
 *   - cPanel → Cron Jobs → configurar para rodar de hora em hora (0 * * * *)
 *     Comando: curl -s "https://ferreiraesa.com.br/conecta/cron/zapi_aniversarios.php?key=fsa-hub-deploy-2026"
 *   - OU executar manualmente via URL no navegador (com ?key=...)
 *   - OU pelo botão "Enviar aniversários agora" em Automações (admin)
 *
 * Config lida de `configuracoes`:
 *   - zapi_auto_aniversario (0/1)
 *   - zapi_auto_aniversario_canal (21/24)
 *   - zapi_auto_aniversario_hora (0-23, ex: 9)
 *   - zapi_auto_aniversario_tpl (nome do template)
 *
 * Segurança:
 *   - Requer ?key=fsa-hub-deploy-2026 OU chamada via CLI
 *   - Parametro ?forcar=1 ignora a checagem de horário (manual)
 */

$isCli = php_sapi_name() === 'cli';
$keyOk = ($_GET['key'] ?? '') === 'fsa-hub-deploy-2026';
if (!$isCli && !$keyOk) { http_response_code(403); die('Acesso negado.'); }

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/functions_zapi.php';

header('Content-Type: text/plain; charset=utf-8');
$pdo = db();
$now = date('Y-m-d H:i:s');
echo "[{$now}] === Cron Aniversarios WhatsApp ===\n";

// 1) Ler config
$cfg = array(
    'ativo'  => '0',
    'canal'  => '24',
    'hora'   => '9',
    'tpl'    => '🎂 Aniversário Cliente',
);
try {
    foreach ($pdo->query("SELECT chave, valor FROM configuracoes WHERE chave LIKE 'zapi_auto_aniversario%'")->fetchAll() as $r) {
        if ($r['chave'] === 'zapi_auto_aniversario')       $cfg['ativo'] = $r['valor'];
        if ($r['chave'] === 'zapi_auto_aniversario_canal') $cfg['canal'] = $r['valor'];
        if ($r['chave'] === 'zapi_auto_aniversario_hora')  $cfg['hora']  = $r['valor'];
        if ($r['chave'] === 'zapi_auto_aniversario_tpl')   $cfg['tpl']   = $r['valor'];
    }
} catch (Exception $e) { echo "ERRO config: " . $e->getMessage() . "\n"; exit; }

if ($cfg['ativo'] !== '1') { echo "Automação desligada em Automações → '🎂 Aniversário'.\n"; exit; }

$forcar = !empty($_GET['forcar']);
$horaAtual = (int)date('H');
$horaCfg   = (int)$cfg['hora'];
if (!$forcar && $horaAtual !== $horaCfg) {
    echo "Hora atual ({$horaAtual}h) != hora configurada ({$horaCfg}h). Nada a fazer.\n";
    exit;
}

// 2) Pegar aniversariantes de hoje ainda não parabenizados neste ano
$year = (int)date('Y');
try {
    $rows = $pdo->prepare(
        "SELECT c.id, c.name, c.phone
         FROM clients c
         LEFT JOIN birthday_greetings bg ON bg.client_id = c.id AND bg.year = ?
         WHERE c.birth_date IS NOT NULL
           AND MONTH(c.birth_date) = MONTH(CURDATE())
           AND DAY(c.birth_date)   = DAY(CURDATE())
           AND c.phone IS NOT NULL AND c.phone != ''
           AND bg.id IS NULL
         ORDER BY c.name"
    );
    $rows->execute(array($year));
    $clientes = $rows->fetchAll();
} catch (Exception $e) { echo "ERRO query: " . $e->getMessage() . "\n"; exit; }

echo "Aniversariantes hoje (ainda não parabenizados em {$year}): " . count($clientes) . "\n\n";

if (empty($clientes)) { echo "Nada a enviar.\n"; exit; }

// 3) Enviar para cada um
$ok = 0; $falhas = 0;
foreach ($clientes as $c) {
    $nome = explode(' ', trim($c['name']))[0];
    $msg  = zapi_get_template($cfg['tpl'], array('nome' => $nome));
    if (!$msg) { echo "  [SKIP] {$c['name']} — template '{$cfg['tpl']}' não encontrado\n"; continue; }

    $r = zapi_send_text($cfg['canal'], $c['phone'], $msg);
    if (!empty($r['ok'])) {
        $pdo->prepare("INSERT IGNORE INTO birthday_greetings (client_id, year, sent_by) VALUES (?, ?, NULL)")
            ->execute(array($c['id'], $year));
        echo "  [OK] {$c['name']} ({$c['phone']})\n";
        $ok++;
    } else {
        echo "  [FALHA] {$c['name']} ({$c['phone']}) — HTTP " . ($r['http_code'] ?? '?') . " " . json_encode($r['data'] ?? '') . "\n";
        $falhas++;
    }
    // Pequena pausa pra não estourar rate limit Z-API
    usleep(500000); // 0.5s
}

echo "\n=== CONCLUIDO === Enviados: {$ok} | Falhas: {$falhas}\n";

// Audit (se existir função)
if (function_exists('audit_log')) {
    try { audit_log('zapi_cron_aniversarios', 'clients', 0, "ok={$ok} falhas={$falhas}"); } catch (Exception $e) {}
}
