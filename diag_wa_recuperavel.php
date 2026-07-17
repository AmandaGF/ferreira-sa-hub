<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== O QUE AINDA DA PRA SALVAR? (" . date('d/m/Y H:i') . ") ===\n\n";

echo "--- Arquivos de WhatsApp por faixa de idade ---\n";
$q = $pdo->query("SELECT
  SUM(created_at >= DATE_SUB(NOW(), INTERVAL 28 DAY)) dentro_28d,
  SUM(created_at <  DATE_SUB(NOW(), INTERVAL 28 DAY) AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) entre_28_30d,
  SUM(created_at <  DATE_SUB(NOW(), INTERVAL 30 DAY)) mais_de_30d,
  COUNT(*) total
  FROM zapi_mensagens
  WHERE tipo IN ('imagem','video','audio','documento','sticker')
    AND arquivo_url IS NOT NULL AND arquivo_url <> ''
    AND (arquivo_salvo_drive IS NULL OR arquivo_salvo_drive = 0)");
$r = $q->fetch(PDO::FETCH_ASSOC);
echo "  NAO salvos no Drive:\n";
echo "    dentro de 28d (cron pega)   : {$r['dentro_28d']}\n";
echo "    entre 28 e 30d (janela cega): {$r['entre_28_30d']}\n";
echo "    mais de 30d (link expirado) : {$r['mais_de_30d']}\n";
echo "    TOTAL nao salvo             : {$r['total']}\n";

echo "\n--- Dos recuperaveis (<=28d): quantos tem cliente E case com pasta? ---\n";
$q = $pdo->query("SELECT
  COUNT(*) total,
  SUM(cv.client_id IS NOT NULL) tem_cliente,
  SUM(EXISTS(SELECT 1 FROM cases c WHERE c.client_id = cv.client_id
             AND c.drive_folder_url IS NOT NULL AND c.drive_folder_url <> '')) tem_pasta
  FROM zapi_mensagens m JOIN zapi_conversas cv ON cv.id = m.conversa_id
  WHERE m.tipo IN ('imagem','video','audio','documento','sticker')
    AND m.arquivo_url IS NOT NULL AND m.arquivo_url <> ''
    AND (m.arquivo_salvo_drive IS NULL OR m.arquivo_salvo_drive = 0)
    AND m.created_at >= DATE_SUB(NOW(), INTERVAL 28 DAY)");
$r = $q->fetch(PDO::FETCH_ASSOC);
echo "    total recuperavel      : {$r['total']}\n";
echo "    com conversa->cliente  : {$r['tem_cliente']}\n";
echo "    cliente com case+pasta : {$r['tem_pasta']}  <- estes o cron salva sozinho\n";

echo "\n--- Por tipo (recuperaveis) ---\n";
$q = $pdo->query("SELECT tipo, COUNT(*) n FROM zapi_mensagens
  WHERE tipo IN ('imagem','video','audio','documento','sticker')
    AND arquivo_url IS NOT NULL AND arquivo_url <> ''
    AND (arquivo_salvo_drive IS NULL OR arquivo_salvo_drive = 0)
    AND created_at >= DATE_SUB(NOW(), INTERVAL 28 DAY)
  GROUP BY tipo ORDER BY n DESC");
foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $x) { printf("    %-12s %s\n", $x['tipo'], $x['n']); }
