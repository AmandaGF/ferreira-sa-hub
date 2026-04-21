<?php
/**
 * Sincroniza foto de perfil de TODOS os contatos do WhatsApp via Z-API.
 * Processa em batches pra não estourar timeout. Força refresh de contatos com foto > 7 dias ou sem foto.
 *
 * URL: /conecta/sync_fotos_wa.php?key=fsa-hub-deploy-2026
 * Opções:
 *   &canal=21 (ou 24) pra filtrar por canal
 *   &force=1 pra atualizar TODOS (ignora "atualizado há menos de 7 dias")
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit; }
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_zapi.php';
set_time_limit(0);
ignore_user_abort(true);
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

$canal = $_GET['canal'] ?? '';
$force = ($_GET['force'] ?? '') === '1';
$batchSize = 20;

echo "=== Sync fotos WhatsApp ===\n";
echo "Canal: " . ($canal ?: 'todos') . "\n";
echo "Force: " . ($force ? 'sim (ignora cache de 7 dias)' : 'não (só stale ou sem foto)') . "\n\n";

$wh = $force ? '1=1' : "(co.foto_perfil_atualizada IS NULL OR co.foto_perfil_atualizada < DATE_SUB(NOW(), INTERVAL 7 DAY))";
$params = array();
if ($canal) { $wh .= " AND co.canal = ?"; $params[] = $canal; }

$total = (int)$pdo->query("SELECT COUNT(*) FROM zapi_conversas co WHERE $wh")->fetchColumn();
// Reexecutar com params se canal filtrado
if ($canal) {
    $sC = $pdo->prepare("SELECT COUNT(*) FROM zapi_conversas co WHERE $wh");
    $sC->execute($params);
    $total = (int)$sC->fetchColumn();
}

echo "Total pendente: $total conversas\n\n";

if ($total === 0) { echo "Nada a fazer.\n"; exit; }

$comFoto = 0; $clientesUpdated = 0; $erros = 0; $processadas = 0;

while ($processadas < $total) {
    $stmt = $pdo->prepare("SELECT co.id, co.nome_contato, co.telefone FROM zapi_conversas co WHERE $wh ORDER BY co.foto_perfil_atualizada ASC, co.id DESC LIMIT $batchSize");
    $stmt->execute($params);
    $ids = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$ids) break;
    foreach ($ids as $row) {
        $r = zapi_sync_foto_contato((int)$row['id']);
        $processadas++;
        if (isset($r['error'])) {
            $erros++;
            echo sprintf("[%03d/%03d] ❌ #%d %s — %s\n", $processadas, $total, $row['id'], $row['nome_contato'] ?: $row['telefone'], substr($r['error'], 0, 60));
        } else {
            if (!empty($r['foto_url'])) $comFoto++;
            if (!empty($r['client_updated'])) $clientesUpdated++;
            echo sprintf("[%03d/%03d] ✓ #%d %s%s\n", $processadas, $total, $row['id'], $row['nome_contato'] ?: $row['telefone'], !empty($r['foto_url']) ? ' (foto!)' : '');
        }
        // Pausa curta pra não saturar a API
        if ($processadas % 5 === 0) usleep(200000); // 200ms
        flush(); ob_flush();
    }
}

echo "\n══ RESUMO ══\n";
echo "Processadas: $processadas\n";
echo "Com foto encontrada: $comFoto\n";
echo "Clientes atualizados (cadastro ganhou foto): $clientesUpdated\n";
echo "Erros: $erros\n";
