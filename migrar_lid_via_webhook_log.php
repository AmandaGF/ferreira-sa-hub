<?php
/**
 * Recupera telefone real e nome_contato de conversas com lid bruto, vasculhando
 * o histórico de zapi_webhook_log em busca de payloads que JÁ trouxeram número
 * real (ex: cliente respondeu uma vez e o webhook recebeu phone+chatName completos
 * mas não fez upgrade da conv naquela época).
 *
 * Pra cada conv com tel @lid bruto, procura na zapi_webhook_log o payload mais
 * recente que tenha mesmo chatLid + phone NÃO-lid + chatName real. Se achar,
 * upgrade.
 *
 * Acesso admin: ?key=fsa-hub-deploy-2026 [&confirmar=1]
 */
ini_set('display_errors','1'); error_reporting(E_ALL);
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit; }
$pdo = db();

echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Upgrade via webhook log</title>';
echo '<style>body{font-family:system-ui;padding:20px;max-width:1300px;margin:0 auto}table{width:100%;border-collapse:collapse;margin:.5rem 0}td,th{padding:6px;border-bottom:1px solid #ddd;font-size:12px;text-align:left;vertical-align:top}th{background:#052228;color:#fff}.box{padding:.6rem 1rem;border-radius:8px;margin:.5rem 0}.ok{background:#d1fae5;color:#065f46}.warn{background:#fef3c7;color:#92400e}h1,h2{color:#052228}</style>';
echo '</head><body><h1>🔄 Upgrade de conv lid bruto via histórico de webhook</h1>';

$convs = $pdo->query("SELECT id, telefone, chat_lid, nome_contato, client_id FROM zapi_conversas
                      WHERE COALESCE(eh_grupo,0)=0
                        AND (LENGTH(REGEXP_REPLACE(telefone,'[^0-9]','')) > 14 OR telefone LIKE '%@lid%')")->fetchAll();
echo '<div class="box warn">' . count($convs) . ' conversas com lid bruto</div>';

$confirmar = isset($_GET['confirmar']);
$rows = array();
$upgraded = 0;

foreach ($convs as $c) {
    $lid = $c['chat_lid'] ?: $c['telefone'];
    if (!$lid) continue;
    // Vasculha logs procurando payload com mesmo chatLid mas phone NÃO-lid
    $st = $pdo->prepare("SELECT payload_json FROM zapi_webhook_log
                         WHERE payload_json LIKE ? AND payload_json NOT LIKE '%MessageStatusCallback%'
                         ORDER BY id DESC LIMIT 50");
    $st->execute(array('%' . $lid . '%'));
    $logs = $st->fetchAll();

    $achouPhone = null; $achouNome = null;
    foreach ($logs as $log) {
        $p = json_decode($log['payload_json'], true);
        if (!$p) continue;
        // Tenta achar número real em qualquer campo
        $phoneCandidatos = array($p['senderPhoneNumber'] ?? '', $p['phone'] ?? '', $p['participantPhone'] ?? '');
        foreach ($phoneCandidatos as $pc) {
            $d = preg_replace('/\D/', '', (string)$pc);
            if (strlen($d) >= 10 && strlen($d) <= 14 && strpos($pc, '@lid') === false) {
                $achouPhone = $pc;
                break;
            }
        }
        // Tenta achar nome real (chatName não-lid)
        $cn = $p['chatName'] ?? '';
        if (!$achouNome && $cn && !preg_match('/@lid/', $cn) && !preg_match('/^\d{14,}$/', $cn)) {
            $achouNome = $cn;
        }
        if ($achouPhone && $achouNome) break;
    }

    $r = array(
        'conv_id' => $c['id'], 'tel_orig' => $c['telefone'], 'lid' => $lid,
        'nome_orig' => $c['nome_contato'], 'phone_recuperado' => $achouPhone, 'nome_recuperado' => $achouNome,
    );

    if ($achouPhone || ($achouNome && (!$c['nome_contato'] || strpos($c['nome_contato'], '@lid') !== false))) {
        $upd = array(); $params = array();
        if ($achouPhone) { $upd[] = "telefone = ?"; $params[] = $achouPhone; }
        if ($achouNome && (!$c['nome_contato'] || strpos($c['nome_contato'], '@lid') !== false || preg_match('/^\d{14,}$/', $c['nome_contato']))) {
            $upd[] = "nome_contato = ?"; $params[] = $achouNome;
        }
        if ($achouPhone) {
            $upd[] = "precisa_revisao = 0";
            $upd[] = "motivo_revisao = NULL";
        }
        $params[] = $c['id'];
        if ($confirmar) {
            try {
                $pdo->prepare("UPDATE zapi_conversas SET " . implode(', ', $upd) . " WHERE id = ?")->execute($params);
                $r['acao'] = 'UPGRADE';
                $upgraded++;
            } catch (Exception $e) {
                $r['acao'] = 'ERRO: ' . $e->getMessage();
            }
        } else {
            $r['acao'] = 'UPGRADE';
            $upgraded++;
        }
    } else {
        $r['acao'] = 'sem dados nos logs';
    }
    $rows[] = $r;
}

echo '<div class="box ' . ($confirmar ? 'ok' : 'warn') . '">';
echo $confirmar ? '<strong>✓ Upgrades aplicados.</strong>' : '<strong>⚠️ MODO PRÉ-CHECK</strong> — adicione <code>&confirmar=1</code> pra aplicar';
echo " {$upgraded} conversa(s) recuperáveis via webhook log.</div>";

echo '<table><thead><tr><th>conv</th><th>Tel original</th><th>LID</th><th>Nome orig</th><th>Phone recuperado</th><th>Nome recuperado</th><th>Ação</th></tr></thead><tbody>';
foreach ($rows as $r) {
    echo '<tr><td>' . $r['conv_id'] . '</td><td><code>' . htmlspecialchars($r['tel_orig']) . '</code></td><td><code>' . htmlspecialchars(substr($r['lid'], 0, 25)) . '</code></td><td>' . htmlspecialchars(substr($r['nome_orig'] ?: '-', 0, 30)) . '</td><td><strong>' . htmlspecialchars($r['phone_recuperado'] ?: '-') . '</strong></td><td><strong>' . htmlspecialchars($r['nome_recuperado'] ?: '-') . '</strong></td><td>' . htmlspecialchars($r['acao']) . '</td></tr>';
}
echo '</tbody></table>';

if ($confirmar) audit_log('lid_upgrade_via_log', 'zapi_conversas', 0, "{$upgraded} convs upgradadas via histórico webhook");
echo '</body></html>';
