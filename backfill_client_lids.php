<?php
/**
 * Backfill idempotente — popula clients.whatsapp_lid pra clientes sem @lid.
 *
 * Roda em lotes pra não travar o servidor nem estourar rate limit da Z-API.
 * Usa o endpoint /phone-exists (confirmado pela Paola/Z-API em 24/Abr/2026):
 * @lid é único e fixo por número.
 *
 * Parâmetros:
 *   ?key=fsa-hub-deploy-2026   (obrigatório)
 *   ?batch=50                  (default: 30 clientes por rodada)
 *   ?force=1                   (reprocessa até quem já tem @lid; default off)
 *
 * Execute manualmente várias vezes até esvaziar a fila. Cada execução processa
 * no máximo 'batch' clientes com interval de 0.3s entre chamadas pra API.
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('x'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_zapi.php';

$pdo = db();
$batch = max(1, min(100, (int)($_GET['batch'] ?? 30)));
$force = !empty($_GET['force']);

// Self-heal coluna (idempotente)
try { $pdo->exec("ALTER TABLE clients ADD COLUMN whatsapp_lid VARCHAR(50) DEFAULT NULL"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE clients ADD COLUMN whatsapp_lid_checado_em DATETIME DEFAULT NULL"); } catch (Exception $e) {}

echo "=== Backfill whatsapp_lid — batch={$batch} force=" . ($force ? '1' : '0') . " ===\n\n";

// Seleciona clientes elegíveis: têm telefone, ainda não foram checados (ou force)
$sql = $force
    ? "SELECT id, name, phone FROM clients WHERE phone IS NOT NULL AND phone != '' ORDER BY id DESC LIMIT ?"
    : "SELECT id, name, phone FROM clients WHERE phone IS NOT NULL AND phone != '' AND whatsapp_lid_checado_em IS NULL ORDER BY id DESC LIMIT ?";

$st = $pdo->prepare($sql);
$st->bindValue(1, $batch, PDO::PARAM_INT);
$st->execute();
$clientes = $st->fetchAll();

if (!$clientes) {
    // Conta quantos ainda faltam (só quando não é force)
    $restantes = (int)$pdo->query("SELECT COUNT(*) FROM clients WHERE phone IS NOT NULL AND phone != '' AND whatsapp_lid_checado_em IS NULL")->fetchColumn();
    echo "Nada pra fazer. Restantes ainda não checados: {$restantes}\n";
    if ($restantes > 0) echo "(Dica: tente rodar de novo com ?force=1 ou investigue os restantes manualmente.)\n";
    exit;
}

$ok = 0; $semWpp = 0; $erro = 0;
foreach ($clientes as $c) {
    $r = zapi_atualizar_lid_cliente((int)$c['id'], $force);
    if (!empty($r['ok'])) {
        echo "[OK]   #{$c['id']} {$c['name']} tel={$c['phone']} → {$r['lid']}\n";
        $ok++;
    } else {
        $motivo = $r['motivo'] ?? 'desconhecido';
        $icon = (stripos($motivo, 'não existe') !== false) ? '[N/A] ' : '[ERR] ';
        echo "{$icon} #{$c['id']} {$c['name']} tel={$c['phone']} → {$motivo}\n";
        if (stripos($motivo, 'não existe') !== false) $semWpp++;
        else $erro++;
    }
    usleep(300000); // 0.3s entre chamadas pra respeitar rate limit Z-API
}

$restantes = (int)$pdo->query("SELECT COUNT(*) FROM clients WHERE phone IS NOT NULL AND phone != '' AND whatsapp_lid_checado_em IS NULL")->fetchColumn();

echo "\n=== RESUMO ===\n";
echo "Processados: " . count($clientes) . "\n";
echo "  OK (lid salvo): {$ok}\n";
echo "  Sem WhatsApp:   {$semWpp}\n";
echo "  Erros:          {$erro}\n";
echo "Ainda na fila (sem check): {$restantes}\n";
if ($restantes > 0) echo "→ Rode de novo pra processar próximo lote.\n";
