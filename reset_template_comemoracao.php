<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

$novoTemplate = "🎉🔔 *CONTRATO FECHADO!* 🔔🎉\n\nParabéns ao time! ✨\n\n👤 Cliente: *[cliente]*\n💼 Caso: [tipo_caso]\n🎯 Vendedor(a): *[comercial]*\n📅 Data: [hoje]\n\n_Mais uma família escolheu a equipe Ferreira & Sá Advocacia!_ 💪\n\n🚀 Cada contrato fechado é uma vida transformada. Vamos com tudo, time — que venham muitos outros! 🏆";

$pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES ('comemoracao_contrato_template', ?)
               ON DUPLICATE KEY UPDATE valor = VALUES(valor)")
    ->execute(array($novoTemplate));

echo "✓ Template atualizado no banco. Sem valor, com data e mensagem motivacional nova.\n\n";
echo "Conteudo agora:\n\n";
echo $novoTemplate . "\n";
