<?php
/**
 * ver_log_caso_novo.php — diagnóstico TEMPORÁRIO.
 *
 * Lê as últimas linhas do error_log do PHP procurando por menções a
 * caso_novo.php / email_monitor / fatal — útil quando a página está
 * em branco e o display_errors está off.
 *
 * Acesso: ?key=fsa-hub-deploy-2026
 *
 * APAGAR DEPOIS DE DIAGNÓSTICO.
 */

if (!isset($_GET['key']) || $_GET['key'] !== 'fsa-hub-deploy-2026') {
    http_response_code(403);
    exit('Acesso negado.');
}
header('Content-Type: text/plain; charset=utf-8');

echo "=== Diagnóstico Email Monitor / caso_novo ===\n\n";

// 1. Verifica se o include existe
$includePath = __DIR__ . '/includes/email_monitor_functions.php';
echo "1. Include path: $includePath\n";
echo "   Existe: " . (file_exists($includePath) ? 'SIM' : 'NÃO') . "\n";
echo "   Tamanho: " . (file_exists($includePath) ? filesize($includePath) . ' bytes' : 'n/a') . "\n\n";

// 2. Verifica syntax do caso_novo.php via php -l (se disponível)
$casoNovoPath = __DIR__ . '/modules/operacional/caso_novo.php';
echo "2. caso_novo.php: $casoNovoPath\n";
echo "   Existe: " . (file_exists($casoNovoPath) ? 'SIM' : 'NÃO') . "\n";
echo "   Tamanho: " . (file_exists($casoNovoPath) ? filesize($casoNovoPath) . ' bytes' : 'n/a') . "\n\n";

// 3. Tenta dar require no include pra ver se carrega sem erro
echo "3. Teste de require_once includes/email_monitor_functions.php:\n";
try {
    require_once $includePath;
    $funcoes = array(
        'email_monitor_conectar_imap',
        'email_monitor_parsear_email',
        'email_monitor_inserir_andamento',
        'email_monitor_extract_body',
        'email_monitor_parse',
        'email_monitor_eh_so_iniciais',
        'email_monitor_eh_pessoa_juridica',
    );
    foreach ($funcoes as $fn) {
        echo "   [" . (function_exists($fn) ? 'OK' : 'FALHA') . "] $fn\n";
    }
} catch (Throwable $e) {
    echo "   ERRO: " . $e->getMessage() . "\n";
    echo "   Em: " . $e->getFile() . ':' . $e->getLine() . "\n";
}
echo "\n";

// 4. Tenta achar o error_log do PHP
echo "4. error_log do PHP:\n";
$logCandidates = array(
    __DIR__ . '/error_log',
    dirname(__DIR__) . '/error_log',
    dirname(dirname(__DIR__)) . '/error_log',
    ini_get('error_log'),
);
foreach ($logCandidates as $log) {
    if (!$log) continue;
    if (file_exists($log) && is_readable($log)) {
        echo "   Encontrado: $log (" . filesize($log) . " bytes)\n";
        $linhas = file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($linhas) {
            $relevantes = array();
            $palavras = array('caso_novo', 'email_monitor', 'preUfOrgao', 'preComarcaOrgao', 'preVaraOrgao', 'fatal', 'parse error', 'syntax error');
            foreach (array_reverse($linhas) as $linha) {
                foreach ($palavras as $p) {
                    if (stripos($linha, $p) !== false) {
                        $relevantes[] = $linha;
                        break;
                    }
                }
                if (count($relevantes) >= 30) break;
            }
            if ($relevantes) {
                echo "   Últimas " . count($relevantes) . " linhas relevantes:\n";
                foreach (array_reverse($relevantes) as $l) echo "      $l\n";
            } else {
                echo "   Sem entradas relevantes a caso_novo / email_monitor.\n";
                echo "   Últimas 5 linhas QUAISQUER do log:\n";
                foreach (array_slice($linhas, -5) as $l) echo "      $l\n";
            }
        }
    }
}

echo "\n=== Fim ===\n";
