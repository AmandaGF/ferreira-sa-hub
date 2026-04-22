<?php
/**
 * teste_cron.php — script mínimo pra validar se o CRON da hospedagem
 * está disparando.
 *
 * Escreve UMA linha no arquivo cron/logs/teste_cron.log com timestamp
 * e metadados. Se esse arquivo for atualizado depois do agendamento,
 * o cron funciona. Se não → problema de agendamento, não de código.
 *
 * Uso:
 *   /usr/bin/php /home7/ferre3151357/public_html/conecta/cron/teste_cron.php
 */

$logPath = __DIR__ . '/logs/teste_cron.log';
$logDir  = dirname($logPath);
if (!is_dir($logDir)) @mkdir($logDir, 0755, true);

$linha = '[' . date('Y-m-d H:i:s') . '] ['
       . (php_sapi_name() ?: '?') . '] '
       . 'PID ' . getmypid() . ' | '
       . 'TZ=' . date_default_timezone_get() . ' | '
       . 'PHP=' . PHP_VERSION . ' | '
       . 'argv=' . (isset($argv) ? implode(' ', $argv) : '(none)')
       . "\n";

@file_put_contents($logPath, $linha, FILE_APPEND);
echo $linha;
