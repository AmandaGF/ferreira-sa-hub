<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') die('nope');
header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors', '1'); error_reporting(E_ALL);
while (ob_get_level() > 0) { ob_end_clean(); }
echo "1. Start OK\n"; @flush();
require_once __DIR__ . '/core/config.php'; echo "2. config OK\n"; @flush();
require_once __DIR__ . '/core/database.php'; echo "3. database OK\n"; @flush();
require_once __DIR__ . '/core/functions.php'; echo "4. functions OK\n"; @flush();
require_once __DIR__ . '/core/functions_zapi.php'; echo "5. functions_zapi OK\n"; @flush();
require_once __DIR__ . '/core/functions_comemoracao.php'; echo "6. functions_comemoracao OK\n"; @flush();
require_once __DIR__ . '/core/functions_jorjao.php'; echo "7. functions_jorjao OK\n"; @flush();
echo "Funcoes existem?\n";
echo "  jorjao_grupo_config: " . (function_exists('jorjao_grupo_config') ? 'sim' : 'nao') . "\n";
echo "  jorjao_tocada_ativa: " . (function_exists('jorjao_tocada_ativa') ? 'sim' : 'nao') . "\n";
echo "  jorjao_peticao_distribuida: " . (function_exists('jorjao_peticao_distribuida') ? 'sim' : 'nao') . "\n";
echo "Tocada peticao ativa? " . (jorjao_tocada_ativa('peticao_distribuida') ? 'SIM' : 'NAO') . "\n";
echo "FIM.\n";
