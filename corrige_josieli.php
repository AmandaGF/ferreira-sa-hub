<?php
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_zapi.php';
require_once __DIR__ . '/core/functions_comemoracao.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
header('Content-Type: text/plain; charset=utf-8');
$cfg = comemoracao_get_config();
if (!$cfg['grupo_id'] || !in_array($cfg['canal'], array('21','24'), true)) {
    echo "Grupo/canal invalido\n"; print_r($cfg); exit;
}
$msg = "⚠️ *Corrigindo aqui* — foi a *Naiara* quem deixou a pasta da Josieli Braz apta, não a Nativânia. Bola pra frente, time! 💪\n\n— Tio do Ferreira & Sá";
$r = zapi_send_text($cfg['canal'], $cfg['grupo_id'], $msg);
print_r($r);
