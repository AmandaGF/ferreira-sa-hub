<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

$u = $pdo->query("SELECT * FROM salavip_usuarios WHERE cliente_id = 1107")->fetch();
echo "ID: " . $u['id'] . "\n";
echo "Nome: " . $u['nome_exibicao'] . "\n";
echo "CPF: " . $u['cpf'] . "\n";
echo "Email: " . $u['email'] . "\n";
echo "Ativo: " . $u['ativo'] . "\n";
echo "Token: " . ($u['token_ativacao'] ?: 'NULL') . "\n";
echo "Expira: " . ($u['token_expira'] ?: 'NULL') . "\n";
echo "Agora: " . date('Y-m-d H:i:s') . "\n";
echo "Expirado: " . ($u['token_expira'] && $u['token_expira'] < date('Y-m-d H:i:s') ? 'SIM' : 'NÃO') . "\n";

echo "\nLink completo:\n";
echo "https://www.ferreiraesa.com.br/salavip/ativar_conta.php?token=" . $u['token_ativacao'] . "\n";

// Testar se o link funciona
echo "\nTestando link...\n";
$url = "https://www.ferreiraesa.com.br/salavip/ativar_conta.php?token=" . $u['token_ativacao'];
$ch = curl_init($url);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_NOBODY => false]);
$body = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
curl_close($ch);
echo "HTTP: $code\n";
echo "Final URL: $finalUrl\n";
echo "Contém 'inválido': " . (stripos($body, 'inválido') !== false || stripos($body, 'inv&aacute;lido') !== false || stripos($body, 'expirado') !== false ? 'SIM' : 'NÃO') . "\n";
echo "Contém 'senha': " . (stripos($body, 'senha') !== false ? 'SIM (formulário aparece)' : 'NÃO') . "\n";
