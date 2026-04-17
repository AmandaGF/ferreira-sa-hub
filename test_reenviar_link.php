<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_salavip_email.php';

$pdo = db();

echo "=== TESTE: Reenviar Link de Ativação ===\n\n";

// 1. Verificar config Brevo
echo "--- Configuração Brevo ---\n";
$cfg = array();
$rows = $pdo->query("SELECT chave, valor FROM configuracoes WHERE chave LIKE 'brevo_%'")->fetchAll();
foreach ($rows as $r) $cfg[$r['chave']] = $r['valor'];

echo "brevo_api_key: " . (empty($cfg['brevo_api_key']) ? '❌ NÃO CONFIGURADA' : '✓ configurada (' . mb_substr($cfg['brevo_api_key'], 0, 8) . '...)') . "\n";
echo "brevo_sender_email: " . ($cfg['brevo_sender_email'] ?? '(padrão: contato@ferreiraesa.com.br)') . "\n";
echo "brevo_sender_name: " . ($cfg['brevo_sender_name'] ?? '(padrão: Ferreira & Sá Advocacia)') . "\n";

if (empty($cfg['brevo_api_key'])) {
    echo "\n❌ BREVO NÃO CONFIGURADO — o e-mail NÃO será enviado!\n";
    echo "Configure em: modules/admin/brevo ou na tabela configuracoes\n";
    exit;
}

// 2. Listar usuários da Central VIP com e-mail
echo "\n--- Usuários Central VIP ---\n";
$usuarios = $pdo->query(
    "SELECT su.id, su.token_ativacao, su.ativo, c.name as client_name, c.email as client_email
     FROM salavip_usuarios su
     LEFT JOIN clients c ON c.id = su.cliente_id
     WHERE c.email IS NOT NULL AND c.email != ''
     ORDER BY su.id DESC LIMIT 5"
)->fetchAll();

echo "Top 5 usuários com e-mail: " . count($usuarios) . "\n";
foreach ($usuarios as $u) {
    echo "  #{$u['id']} — {$u['client_name']} — {$u['client_email']} (ativo: {$u['ativo']})\n";
}

// 3. Teste de envio real (para o e-mail da Amanda, se existir)
echo "\n--- Teste de envio para amandaguedesferreira@gmail.com ---\n";
$testEmail = 'amandaguedesferreira@gmail.com';
$testNome = 'Amanda (TESTE)';
$testLink = 'https://www.ferreiraesa.com.br/salavip/ativar_conta.php?token=TESTE_' . bin2hex(random_bytes(4));

$resultado = _salavip_enviar_email_ativacao($testEmail, $testNome, $testLink);
echo "Resultado do envio: " . ($resultado ? '✓ SUCESSO (status 2xx)' : '❌ FALHOU') . "\n";

echo "\n=== Teste concluído — verifique a caixa de entrada ===\n";
