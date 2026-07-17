<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();
$n = $pdo->query("SELECT COUNT(*) FROM zapi_mensagens WHERE arquivo_salvo_drive=1 AND backup_status='auto'")->fetchColumn();
echo "total salvos no Drive (auto): $n\n";
$q = $pdo->query("SELECT tipo, COUNT(*) n FROM zapi_mensagens
                  WHERE arquivo_salvo_drive=1 AND drive_file_id IS NOT NULL AND drive_file_id<>''
                  GROUP BY tipo ORDER BY n DESC");
echo "\npor tipo (com drive_file_id confirmado):\n";
foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) { printf("  %-12s %s\n", $r['tipo'], $r['n']); }
echo "\nfila restante por tipo (salvavel, <=30d, com cliente e pasta):\n";
$q = $pdo->query("SELECT m.tipo, COUNT(*) n FROM zapi_mensagens m
  JOIN zapi_conversas co ON co.id=m.conversa_id
  WHERE m.tipo IN ('imagem','video','audio','documento')
    AND m.arquivo_url IS NOT NULL AND m.arquivo_url<>''
    AND (m.arquivo_salvo_drive=0 OR m.arquivo_salvo_drive IS NULL)
    AND (m.backup_status IS NULL OR m.backup_status='retry_ok')
    AND m.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    AND co.client_id IS NOT NULL
    AND EXISTS(SELECT 1 FROM cases c WHERE c.client_id=co.client_id
               AND c.drive_folder_url IS NOT NULL AND c.drive_folder_url<>'')
  GROUP BY m.tipo ORDER BY n DESC");
foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) { printf("  %-12s %s\n", $r['tipo'], $r['n']); }
