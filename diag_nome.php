<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('x'); }
@session_start(); $_SESSION['user']=array('id'=>1,'name'=>'Amanda Guedes Ferreira','email'=>'x','role'=>'admin'); // sessão SEM wa_display_name (como no login real)
require_once __DIR__.'/core/config.php'; require_once __DIR__.'/core/database.php'; require_once __DIR__.'/core/middleware.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo=db();
echo "wa_display_name no banco (uid=1): ".var_export($pdo->query("SELECT wa_display_name FROM users WHERE id=1")->fetchColumn(),true)."\n";
echo "user_display_name() [sessão sem wa]: ".user_display_name()."\n";
echo "user_display_name(1) [por id]: ".user_display_name(1)."\n";
