<?php
/**
 * Teste avulso: envia template de aniversário pra um telefone específico.
 * Uso: /conecta/test_zapi_aniv.php?key=fsa-hub-deploy-2026&phone=24992234554&nome=Amanda&canal=24
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions.php';
require_once __DIR__ . '/core/functions_zapi.php';

ini_set('display_errors', '1');
error_reporting(E_ALL);

$phone = $_GET['phone'] ?? '24992234554';
$nome  = $_GET['nome']  ?? 'Amanda';
$canal = $_GET['canal'] ?? '24';
$tplN  = $_GET['tpl']   ?? '🎂 Aniversário Cliente';

echo "=== TESTE ENVIO ANIVERSARIO ===\n\n";
echo "Canal: $canal\n";
echo "Telefone: $phone\n";
echo "Nome: $nome\n";
echo "Template: $tplN\n\n";

// 1. Checar instancia
$inst = zapi_get_instancia($canal);
echo "Instancia {$canal}: ";
if (!$inst) { echo "NAO EXISTE NO DB\n"; exit; }
echo $inst['nome'] . " | id=" . ($inst['instancia_id'] ? 'OK' : 'VAZIO') . " | token=" . ($inst['token'] ? 'OK' : 'VAZIO') . " | conectado=" . ($inst['conectado'] ? 'SIM' : 'NAO') . "\n";
if (!$inst['instancia_id'] || !$inst['token']) { echo "\n[ABORT] Credenciais da instancia {$canal} nao configuradas.\n"; exit; }

// 2. Ler template
$msg = zapi_get_template($tplN, array('nome' => $nome));
if (!$msg) { echo "\n[ABORT] Template '{$tplN}' nao encontrado.\n"; exit; }
echo "\nMensagem final:\n---\n{$msg}\n---\n\n";

// 3. Enviar
echo "Enviando...\n";
$r = zapi_send_text($canal, $phone, $msg);
echo "HTTP: " . ($r['http_code'] ?? '?') . "\n";
echo "OK: "   . (!empty($r['ok']) ? 'SIM' : 'NAO') . "\n";
echo "Resposta Z-API: " . json_encode($r['data'] ?? '', JSON_PRETTY_PRINT) . "\n";
if (!empty($r['erro'])) echo "ERRO cURL: " . $r['erro'] . "\n";

echo "\n=== FIM ===\n";
