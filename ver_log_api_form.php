<?php
/** Le o log temporario de origem do api_form.php (Origin/Referer/IP).
 *  curl "https://ferreiraesa.com.br/conecta/ver_log_api_form.php?key=fsa-hub-deploy-2026"
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
header('Content-Type: text/plain; charset=utf-8');
$f = __DIR__ . '/files/api_form_origem.log';
if (!is_file($f)) { exit("(log ainda vazio — nenhum POST registrado)\n"); }
echo file_get_contents($f);
