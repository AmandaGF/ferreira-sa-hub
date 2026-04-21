<?php
/**
 * Ferreira & Sá Hub — CRON DataJud
 * Rodar diariamente às 07h00:
 * /usr/local/bin/php /home/ferre315/public_html/conecta/api/datajud_cron.php
 *
 * Também pode ser chamado via web com chave:
 * /conecta/api/datajud_cron.php?key=fsa-hub-deploy-2026
 */

// Permitir execução via CLI ou via web com chave
$isCli = (php_sapi_name() === 'cli');
if (!$isCli) {
    if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') {
        http_response_code(403);
        exit('Chave invalida');
    }
}

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/functions.php';

// Cron pode levar vários minutos (rate limit 1s por processo × N casos)
@set_time_limit(0);
@ini_set('memory_limit', '512M');

header('Content-Type: text/plain; charset=utf-8');

$pdo = db();
$inicio = microtime(true);

echo "=== DataJud Sync — " . date('Y-m-d H:i:s') . " ===\n\n";

// Buscar casos elegíveis: TODOS com número cadastrado,
// exceto cancelados e arquivados, que não sincronizaram nas últimas 24h.
// LIMIT 1000 cobre escritório com até ~1000 processos ativos — com rate limit
// de 1s por case, demora até ~17min, toleravelmente dentro do horário de cron.
$stmt = $pdo->query(
    "SELECT id, title, case_number FROM cases
     WHERE case_number IS NOT NULL
       AND case_number != ''
       AND status NOT IN ('cancelado', 'arquivado')
       AND (datajud_ultima_sync IS NULL OR datajud_ultima_sync < NOW() - INTERVAL 24 HOUR)
     ORDER BY datajud_ultima_sync ASC
     LIMIT 1000"
);
$casos = $stmt->fetchAll();

echo "Casos elegiveis: " . count($casos) . "\n\n";

$totalSucesso = 0;
$totalNovos = 0;
$totalErros = 0;
$totalNaoEncontrado = 0;

foreach ($casos as $i => $caso) {
    echo ($i + 1) . ". #{$caso['id']} {$caso['title']} ({$caso['case_number']})... ";

    $resultado = datajud_sincronizar_caso($caso['id'], 'automatico', null);

    $status = $resultado['status'];
    $novos = isset($resultado['novos']) ? $resultado['novos'] : 0;

    if ($status === 'sucesso') {
        $totalSucesso++;
        $totalNovos += $novos;
        echo "OK" . ($novos > 0 ? " (+{$novos} movimentos)" : " (sem novidades)") . "\n";
    } elseif ($status === 'nao_encontrado') {
        $totalNaoEncontrado++;
        echo "NAO ENCONTRADO\n";
    } else {
        $totalErros++;
        echo "ERRO: " . ($resultado['msg'] ?? 'desconhecido') . "\n";
    }

    // Rate limit: 1 segundo entre chamadas
    if ($i < count($casos) - 1) {
        sleep(1);
    }
}

$duracao = round(microtime(true) - $inicio, 1);

$resumo = "\n=== RESUMO ===\n"
    . "Sincronizados: {$totalSucesso}\n"
    . "Movimentos novos: {$totalNovos}\n"
    . "Nao encontrados: {$totalNaoEncontrado}\n"
    . "Erros: {$totalErros}\n"
    . "Duracao: {$duracao}s\n"
    . "=== FIM ===\n";

echo $resumo;

// Notificar admin com resumo
if ($totalSucesso > 0 || $totalErros > 0) {
    $msgNotif = "DataJud: {$totalSucesso} processos sincronizados, {$totalNovos} novos movimentos";
    if ($totalErros > 0) $msgNotif .= ", {$totalErros} erros";
    if ($totalNaoEncontrado > 0) $msgNotif .= ", {$totalNaoEncontrado} nao encontrados";

    notify_admins($msgNotif, $msgNotif, 'info', url('modules/admin/datajud.php'), '');
}
