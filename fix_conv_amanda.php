<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "=== Corrigir estado bagunçado ===\n\n";

// 1. Marcar mensagens com conteudo='[mensagem apagada]' como status='deletada' (foram sobrescritas)
$r1 = $pdo->exec("UPDATE zapi_mensagens
                  SET status = 'deletada'
                  WHERE conteudo = '[mensagem apagada]' AND status != 'deletada'");
echo "Mensagens corrigidas para status=deletada: {$r1}\n";

// 2. Setar '[imagem]', '[vídeo]', '[documento]' onde estiver vazio + tipo corresponde
$r2 = $pdo->exec("UPDATE zapi_mensagens SET conteudo = '[imagem]'
                  WHERE (conteudo IS NULL OR conteudo = '') AND tipo = 'imagem'");
echo "Imagens com conteudo preenchido: {$r2}\n";

$r3 = $pdo->exec("UPDATE zapi_mensagens SET conteudo = '[vídeo]'
                  WHERE (conteudo IS NULL OR conteudo = '') AND tipo = 'video'");
echo "Vídeos com conteudo preenchido: {$r3}\n";

$r4 = $pdo->exec("UPDATE zapi_mensagens SET conteudo = COALESCE(arquivo_nome, '[documento]')
                  WHERE (conteudo IS NULL OR conteudo = '') AND tipo = 'documento'");
echo "Documentos com conteudo preenchido: {$r4}\n";

$r5 = $pdo->exec("UPDATE zapi_mensagens SET conteudo = '[áudio]'
                  WHERE (conteudo IS NULL OR conteudo = '') AND tipo = 'audio'");
echo "Áudios com conteudo preenchido: {$r5}\n";

echo "\n=== CONCLUIDO ===\n";
