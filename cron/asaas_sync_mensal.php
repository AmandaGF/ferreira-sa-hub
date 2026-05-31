<?php
/**
 * Cron MENSAL: sync global Asaas — pra rodar 1x por mes (madrugada do dia 1).
 *
 * Diferenca pra aplicar_sync_asaas_global.php:
 *  - Sem paginacao por HTTP (set_time_limit(0), processa tudo de uma vez)
 *  - Loga em /files/asaas_sync_mensal.log (texto, append)
 *  - Notifica admins ao final via notify_admins() com resumo
 *  - Saida minima (so resumo final) pra nao explodir log do cron
 *
 * URL: /cron/asaas_sync_mensal.php?key=fsa-hub-deploy-2026
 *
 * Configurar no cPanel:
 *   0 3 1 * *   curl -s "https://ferreiraesa.com.br/conecta/cron/asaas_sync_mensal.php?key=fsa-hub-deploy-2026"
 *   (03:00 do dia 1 de cada mes)
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }

set_time_limit(0);
ignore_user_abort(true);

require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/asaas_helper.php';
require_once __DIR__ . '/../core/functions_utils.php';
require_once __DIR__ . '/../core/functions_notify.php';

header('Content-Type: text/plain; charset=utf-8');

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$logFile = dirname(__DIR__) . '/files/asaas_sync_mensal.log';
function _logSync($msg) {
    global $logFile;
    @file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
}

// Lock leve: nao deixa 2 instancias rodarem simultaneamente
$lockFile = dirname(__DIR__) . '/files/asaas_sync_mensal.lock';
if (file_exists($lockFile)) {
    $age = time() - @filemtime($lockFile);
    if ($age < 3600) { // lock vivo se < 1h
        echo "OUTRO RUN EM EXECUCAO (lock ha " . round($age/60) . "min)\n";
        exit;
    }
    @unlink($lockFile); // lock velho, ignora
}
@file_put_contents($lockFile, date('Y-m-d H:i:s'));

// Self-heal
try { $pdo->exec("ALTER TABLE clients ADD COLUMN asaas_ultima_sync_em DATETIME NULL"); } catch (Exception $e) {}

$inicio = microtime(true);
_logSync('=== INICIO sync mensal ===');

$st = $pdo->query("SELECT id, name, cpf, asaas_customer_id FROM clients WHERE asaas_customer_id IS NOT NULL AND asaas_customer_id != '' ORDER BY id");
$todos = $st->fetchAll();
$totalElegiveis = count($todos);
echo "$totalElegiveis clientes elegíveis\n";
_logSync("Elegiveis: $totalElegiveis");

$processados = 0; $erros = 0; $totalApi = 0; $novas = 0; $atualizadas = 0;
$errosList = array();

$upsert = $pdo->prepare(
    "INSERT INTO asaas_cobrancas
        (client_id, asaas_payment_id, asaas_customer_id, descricao, valor,
         vencimento, status, forma_pagamento, data_pagamento, valor_pago,
         link_boleto, invoice_url, ultima_sync, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
     ON DUPLICATE KEY UPDATE
        client_id = VALUES(client_id), asaas_customer_id = VALUES(asaas_customer_id),
        descricao = VALUES(descricao), valor = VALUES(valor), vencimento = VALUES(vencimento),
        status = VALUES(status), forma_pagamento = VALUES(forma_pagamento),
        data_pagamento = VALUES(data_pagamento), valor_pago = VALUES(valor_pago),
        link_boleto = VALUES(link_boleto), invoice_url = VALUES(invoice_url),
        ultima_sync = NOW()"
);
$markSynced = $pdo->prepare("UPDATE clients SET asaas_ultima_sync_em = NOW() WHERE id = ?");

foreach ($todos as $cli) {
    $custIds = array($cli['asaas_customer_id'] => true);
    if (!empty($cli['cpf'])) {
        $cpfLimpo = preg_replace('/\D/', '', $cli['cpf']);
        if (strlen($cpfLimpo) >= 11) {
            try {
                $rc = asaas_request('GET', '/customers?cpfCnpj=' . urlencode($cpfLimpo) . '&limit=20');
                if ($rc && !empty($rc['data'])) {
                    foreach ($rc['data'] as $c) if (!empty($c['id'])) $custIds[$c['id']] = true;
                }
            } catch (Exception $e) {}
        }
    }
    $custIds = array_keys($custIds);

    $cliInserted = 0; $cliUpdated = 0; $cliApi = 0; $erroMsg = '';
    try {
        foreach ($custIds as $cId) {
            $offset = 0; $limit = 100; $paginas = 0;
            while ($paginas < 20) {
                $resp = asaas_request('GET', '/payments?customer=' . urlencode($cId) . '&limit=' . $limit . '&offset=' . $offset);
                if (!$resp || isset($resp['error'])) {
                    $erroMsg = 'API: ' . ($resp['error'] ?? 'sem resposta');
                    break 2;
                }
                $lista = $resp['data'] ?? array();
                if (empty($lista)) break;
                $cliApi += count($lista);
                foreach ($lista as $p) {
                    $payId = $p['id'] ?? ''; if (!$payId) continue;
                    $upsert->execute(array(
                        $cli['id'], $payId, $cId,
                        mb_substr((string)($p['description'] ?? ''), 0, 250),
                        $p['value'] ?? 0,
                        $p['dueDate'] ?? date('Y-m-d'),
                        $p['status'] ?? 'PENDING',
                        $p['billingType'] ?? null,
                        $p['paymentDate'] ?? null,
                        $p['netValue'] ?? null,
                        $p['bankSlipUrl'] ?? null,
                        $p['invoiceUrl'] ?? null,
                    ));
                    if ($upsert->rowCount() === 1) $cliInserted++;
                    else $cliUpdated++;
                }
                $offset += $limit;
                $paginas++;
                if (!($resp['hasMore'] ?? false)) break;
            }
        }
        $markSynced->execute(array($cli['id']));
    } catch (Exception $e) {
        $erroMsg = $e->getMessage();
    }

    if ($erroMsg) {
        $errosList[] = '#' . $cli['id'] . ' ' . mb_substr($cli['name'], 0, 30) . ': ' . $erroMsg;
        $erros++;
    } else {
        $novas += $cliInserted;
        $atualizadas += $cliUpdated;
        $totalApi += $cliApi;
        $processados++;
    }

    usleep(150000); // 150ms throttle
}

$duracao = round(microtime(true) - $inicio, 1);

$resumo = "Processados: $processados/$totalElegiveis | API: $totalApi | Novas: $novas | Atualizadas: $atualizadas | Erros: $erros | Tempo: {$duracao}s";
echo $resumo . "\n";
_logSync($resumo);
if ($errosList) _logSync("ERROS: " . implode(' | ', array_slice($errosList, 0, 10)));

// Notifica admins (so se houve novas cobrancas OU erros)
try {
    if ($novas > 0 || $erros > 0) {
        $msgN = "📊 Sync mensal Asaas: $processados clientes · ";
        if ($novas > 0) $msgN .= "+$novas novas cobranças · ";
        if ($erros > 0) $msgN .= "⚠ $erros erros · ";
        $msgN .= "{$duracao}s";
        notify_admins('Sync Asaas mensal concluído', $msgN, 'info', '/conecta/modules/financeiro/', '💰');
    }
    audit_log('asaas_sync_mensal', 'clients', null, $resumo);
} catch (Exception $e) {}

@unlink($lockFile);
_logSync('=== FIM sync mensal ===');
echo "OK\n";
