<?php
/**
 * configurar_webphone_cred.php — salva credenciais encriptadas do WebPhone.
 * Apagar após uso.
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('x'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_utils.php';
require_once __DIR__ . '/core/functions_nvoip.php';

echo "=== Salvando credenciais WebPhone Nvoip ===\n\n";

nvoip_cfg_set('nvoip_webphone_email', 'ligacoes@ferreiraesa.com.br');
nvoip_cfg_set('nvoip_webphone_senha', encrypt_value('Ligacoes2026@'));
nvoip_cfg_set('nvoip_webphone_url',   'https://painel.nvoip.com.br/telephony/dids');

echo "✓ e-mail salvo\n";
echo "✓ senha salva (encriptada AES-256)\n";
echo "✓ URL salva\n\n";

// Validação: tenta decriptar de volta
$enc = nvoip_cfg_get('nvoip_webphone_senha');
try {
    $dec = decrypt_value($enc);
    echo "✓ decriptação OK (preview: " . substr($dec, 0, 3) . "...)\n";
} catch (Exception $e) {
    echo "✗ falha decriptação: " . $e->getMessage() . "\n";
}

echo "\nAPAGAR este arquivo agora.\n";
