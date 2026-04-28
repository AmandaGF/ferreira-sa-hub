<?php
/**
 * Cron mensal de refresh do whatsapp_lid (item 1+2 do plano alinhado com doc Z-API).
 *
 * Z-API NÃO documenta oficialmente que o @lid é fixo eterno. Paola (suporte) disse
 * empiricamente que é, mas a doc evita garantia temporal explícita. Esse cron faz
 * 2 coisas:
 *
 * 1) BACKFILL: pega clients sem whatsapp_lid → consulta /phone-exists-batch (50k/req)
 *    → preenche o lid + lid_updated_at.
 *
 * 2) REFRESH: pega clients com whatsapp_lid_updated_at > 30 dias → reconsulta no
 *    batch → atualiza se mudou (loga divergência) e bumpa o updated_at.
 *
 * Cron entry mensal sugerido (cPanel):
 *   0 5 1 * * curl -s "https://ferreiraesa.com.br/conecta/cron/wa_lid_refresh.php?key=fsa-hub-deploy-2026"
 *   (todo dia 1 às 5h da manhã)
 *
 * Acesso admin: ?key=fsa-hub-deploy-2026
 */
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/functions_zapi.php';

if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
header('Content-Type: text/plain; charset=utf-8');

$pdo = db();
echo "Cron wa_lid_refresh rodando em " . date('Y-m-d H:i:s') . "\n\n";

// Self-heal: coluna whatsapp_lid_updated_at em clients
try { $pdo->exec("ALTER TABLE clients ADD COLUMN whatsapp_lid_updated_at DATETIME NULL"); } catch (Exception $e) {}
try { $pdo->exec("CREATE INDEX idx_clients_lid_updated ON clients(whatsapp_lid_updated_at)"); } catch (Exception $e) {}

// ─── Fase 1: BACKFILL — clientes sem lid e com phone válido ────────────
echo "=== Fase 1: BACKFILL (clientes sem whatsapp_lid) ===\n";
$st = $pdo->query("SELECT id, name, phone FROM clients
                   WHERE phone IS NOT NULL AND phone != ''
                     AND (whatsapp_lid IS NULL OR whatsapp_lid = '')
                   LIMIT 1000");
$candidatos = $st->fetchAll();
echo "Candidatos: " . count($candidatos) . "\n";

if (!empty($candidatos)) {
    $phonesByDigits = array();
    foreach ($candidatos as $c) {
        $num = preg_replace('/\D/', '', $c['phone']);
        if (strlen($num) === 10 || strlen($num) === 11) $num = '55' . $num;
        if (strlen($num) >= 12) $phonesByDigits[$num] = $c['id'];
    }
    if (empty($phonesByDigits)) {
        echo "Nenhum telefone válido pra batch\n";
    } else {
        $r = zapi_phone_exists_batch('21', array_keys($phonesByDigits));
        echo "Batch HTTP " . ($r['http_code'] ?? '?') . " - results: " . (isset($r['results']) ? count($r['results']) : 0) . "\n";
        if (!empty($r['results'])) {
            // Debug: primeiros 2 results pra inspecionar formato real
            echo "Sample[0]: " . json_encode(array_slice($r['results'], 0, 2), JSON_UNESCAPED_UNICODE) . "\n";
            $atualizados = 0;
            foreach ($r['results'] as $row) {
                $phoneRet = preg_replace('/\D/', '', $row['phone'] ?? '');
                $lidRet = $row['lid'] ?? null;
                if (!$phoneRet) continue;
                // Tenta match exato primeiro, depois sufixo (caso Z-API responda sem 55)
                $clientId = null;
                if (isset($phonesByDigits[$phoneRet])) {
                    $clientId = $phonesByDigits[$phoneRet];
                } else {
                    foreach ($phonesByDigits as $key => $cid) {
                        if (substr($key, -10) === substr($phoneRet, -10) && strlen($phoneRet) >= 10) {
                            $clientId = $cid; break;
                        }
                    }
                }
                if (!$clientId) continue;
                if (!$lidRet) {
                    $pdo->prepare("UPDATE clients SET whatsapp_lid_updated_at = NOW() WHERE id = ?")
                        ->execute(array($clientId));
                    continue;
                }
                $pdo->prepare("UPDATE clients SET whatsapp_lid = ?, whatsapp_lid_updated_at = NOW() WHERE id = ? AND (whatsapp_lid IS NULL OR whatsapp_lid = '')")
                    ->execute(array($lidRet, $clientId));
                $atualizados++;
            }
            echo "✓ Backfill: {$atualizados} cliente(s) com lid preenchido\n";
        }
    }
}

// ─── Fase 2: REFRESH — clientes com lid_updated_at > 30 dias ────────────
echo "\n=== Fase 2: REFRESH (lid_updated_at > 30 dias) ===\n";
$st = $pdo->query("SELECT id, name, phone, whatsapp_lid FROM clients
                   WHERE phone IS NOT NULL AND phone != ''
                     AND whatsapp_lid IS NOT NULL AND whatsapp_lid != ''
                     AND (whatsapp_lid_updated_at IS NULL
                          OR whatsapp_lid_updated_at < DATE_SUB(NOW(), INTERVAL 30 DAY))
                   LIMIT 1000");
$revalidar = $st->fetchAll();
echo "Pra revalidar: " . count($revalidar) . "\n";

if (!empty($revalidar)) {
    $phonesByDigits = array();
    foreach ($revalidar as $c) {
        $num = preg_replace('/\D/', '', $c['phone']);
        if (strlen($num) === 10 || strlen($num) === 11) $num = '55' . $num;
        if (strlen($num) >= 12) $phonesByDigits[$num] = $c;
    }
    if (!empty($phonesByDigits)) {
        $r = zapi_phone_exists_batch('21', array_keys($phonesByDigits));
        echo "Batch HTTP " . ($r['http_code'] ?? '?') . " - results: " . (isset($r['results']) ? count($r['results']) : 0) . "\n";
        if (!empty($r['results'])) {
            $iguais = 0; $mudados = 0; $sumiu = 0;
            foreach ($r['results'] as $row) {
                $phoneRet = preg_replace('/\D/', '', $row['phone'] ?? '');
                $lidRet = $row['lid'] ?? null;
                if (!$phoneRet || !isset($phonesByDigits[$phoneRet])) continue;
                $cli = $phonesByDigits[$phoneRet];

                if (!$lidRet) {
                    $sumiu++;
                    $pdo->prepare("UPDATE clients SET whatsapp_lid_updated_at = NOW() WHERE id = ?")
                        ->execute(array($cli['id']));
                    continue;
                }
                if ($lidRet !== $cli['whatsapp_lid']) {
                    $mudados++;
                    $pdo->prepare("UPDATE clients SET whatsapp_lid = ?, whatsapp_lid_updated_at = NOW() WHERE id = ?")
                        ->execute(array($lidRet, $cli['id']));
                    audit_log('whatsapp_lid_alterado', 'clients', $cli['id'],
                        "lid mudou: '{$cli['whatsapp_lid']}' → '{$lidRet}' (cliente trocou de número?)");
                } else {
                    $iguais++;
                    $pdo->prepare("UPDATE clients SET whatsapp_lid_updated_at = NOW() WHERE id = ?")
                        ->execute(array($cli['id']));
                }
            }
            echo "✓ Refresh: {$iguais} sem mudança, {$mudados} alterados, {$sumiu} sem lid retornado\n";
            if ($mudados > 0) {
                try { notify_gestao('🔄 LIDs do WhatsApp alterados', "{$mudados} cliente(s) tiveram whatsapp_lid alterado no refresh mensal — provavelmente trocaram de número. Conferir no audit_log.", 'info', url('modules/admin/diag_wa.php'), '🔄'); } catch (Exception $e) {}
            }
        }
    }
}

audit_log('wa_lid_refresh', 'cron', 0, 'concluído');
echo "\n✓ Cron concluído\n";
