<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') die('nope');
header('Content-Type: text/plain');
ini_set('display_errors', '1'); error_reporting(E_ALL);
echo "boot ok\n"; @flush();
require_once __DIR__ . '/../core/config.php'; echo "config ok\n"; @flush();
require_once __DIR__ . '/../core/database.php'; echo "database ok\n"; @flush();
require_once __DIR__ . '/../core/functions.php'; echo "functions ok\n"; @flush();
require_once __DIR__ . '/../core/functions_jorjao.php'; echo "jorjao ok\n"; @flush();
echo "END\n";
