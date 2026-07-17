<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_zapi.php';
require_once __DIR__ . '/core/functions_jorjao.php';
header('Content-Type: text/plain; charset=utf-8');

$g = jorjao_grupo_config();
if (!$g['grupo_id']) { echo "grupo nao configurado\n"; exit; }

$msg =  "*[TESTE DE FORMATAÇÃO — não é do Alfredo real]*\n\n"
      . "*Alfredo Neves\u{1D35}\u{1D2C}*: Bom dia, Cinthia! 🎉\n\n"
      . "A empresa depositou o valor em juízo e nós confirmamos o recebimento nos autos. Agora o processo foi pro juiz pra analisar a liberação do dinheiro e encerrar essa fase.\n\n"
      . "---\n"
      . "*Dica:* 📱 Você sabia que também pode acompanhar tudo o que aconteceu no seu processo pelo sistema exclusivo do Ferreira & Sá?! Não deixe de entrar sempre que tiver dúvidas! Isso vai agilizar seus atendimentos: https://ferreiraesa.com.br/salavip";

$r = zapi_send_text($g['canal'], $g['grupo_id'], $msg);
echo "canal=" . $g['canal'] . " grupo=" . $g['grupo_id'] . "\n";
echo "ok=" . (!empty($r['ok']) ? '1' : '0') . "\n";
echo "erro=" . ($r['erro'] ?? '-') . "\n";
echo "http=" . ($r['http_code'] ?? '-') . "\n";
