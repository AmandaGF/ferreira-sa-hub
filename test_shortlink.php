<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') die('nope');
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_shortlinks.php';

echo "=== TESTE SHORTLINKS ===\n\n";

// 1) Cria um shortlink
$url = sl_criar_short_link('https://www.google.com/search?q=teste', array(
    'client_id' => 1, // fake pra teste
    'canal' => '24',
));
echo "1. Shortlink criado: {$url}\n\n";

// 2) Testa encurtamento em texto
$texto = "Olá! Segue o link do treinamento: https://ferreiraesa.com.br/conecta/modules/treinamento/modulo.php?slug=agendar-mensagem-wa e também https://www.google.com/search?q=teste\n\nURL interna do Hub (NÃO deve encurtar): https://ferreiraesa.com.br/conecta/modules/whatsapp/";
$textoFinal = sl_encurtar_urls_no_texto($texto, array('client_id' => 1, 'canal' => '24'));
echo "2. Texto original:\n{$texto}\n\n";
echo "   Texto processado:\n{$textoFinal}\n\n";

// 3) Simula um clique
$codigo = substr($url, strrpos($url, '/') + 1);
$antes = db()->query("SELECT cliques_total FROM short_links WHERE codigo = '{$codigo}'")->fetchColumn();
echo "3. Cliques ANTES do teste no código '{$codigo}': {$antes}\n";
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'TesteScript/1.0';
$urlOrig = sl_registrar_clique($codigo);
$depois = db()->query("SELECT cliques_total FROM short_links WHERE codigo = '{$codigo}'")->fetchColumn();
echo "   URL retornada pelo redirect: {$urlOrig}\n";
echo "   Cliques DEPOIS: {$depois}\n";
echo "   Delta: " . ($depois - $antes) . " (esperado: 1)\n\n";

// 4) Estatística geral
$tot = db()->query("SELECT COUNT(*) FROM short_links")->fetchColumn();
$totCli = db()->query("SELECT COUNT(*) FROM link_clicks")->fetchColumn();
echo "4. Total shortlinks no banco: {$tot}\n";
echo "   Total registros de clique: {$totCli}\n";

echo "\n=== FIM ===\n";
