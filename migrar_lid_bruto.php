<?php
/**
 * Conserta TODAS as conversas com telefone em formato @lid bruto.
 *
 * Pra cada conv:
 * 1. Se já tem client_id → pega clients.phone, atualiza telefone da conv
 * 2. Se não tem client_id mas nome_contato bate UM (e só um) clients.name →
 *    vincula client_id + atualiza telefone
 * 3. Senão → marca precisa_revisao=1 pra Amanda revisar manualmente
 *
 * Em todos os casos, preserva o lid em chat_lid pra dedup futura funcionar.
 *
 * Acesso admin: ?key=fsa-hub-deploy-2026 [&confirmar=1]
 */
ini_set('display_errors','1'); error_reporting(E_ALL);
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit; }
$pdo = db();

// Self-heal: coluna pra marcar conversas que precisam de revisão manual
try { $pdo->exec("ALTER TABLE zapi_conversas ADD COLUMN precisa_revisao TINYINT(1) NOT NULL DEFAULT 0"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE zapi_conversas ADD COLUMN motivo_revisao VARCHAR(120) NULL"); } catch (Exception $e) {}

echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Migrar conv LID bruto</title>';
echo '<style>body{font-family:system-ui;padding:20px;max-width:1300px;margin:0 auto}h1,h2{color:#052228;border-bottom:2px solid #B87333;padding-bottom:6px}h2{font-size:1rem;margin-top:1.5rem}table{width:100%;border-collapse:collapse;margin:.5rem 0}td,th{padding:5px 8px;border-bottom:1px solid #ddd;font-size:11.5px;text-align:left;vertical-align:top}th{background:#052228;color:#fff}.box{padding:.6rem 1rem;border-radius:8px;margin:.5rem 0}.warn{background:#fef3c7;color:#92400e}.no{background:#fee2e2;color:#991b1b}.ok{background:#d1fae5;color:#065f46}code{font-size:11px;color:#6b7280}.tag{display:inline-block;padding:1px 6px;border-radius:4px;font-size:10px;font-weight:700}.t-cli{background:#10b981;color:#fff}.t-nome{background:#3b82f6;color:#fff}.t-rev{background:#f59e0b;color:#fff}</style>';
echo '</head><body><h1>🔧 Migrar conversas com telefone em formato @lid bruto</h1>';

// Identifica conversas com lid bruto: telefone com >14 dígitos numéricos OU contém @lid
$st = $pdo->query("SELECT * FROM zapi_conversas
                   WHERE COALESCE(eh_grupo,0)=0
                     AND (LENGTH(REGEXP_REPLACE(telefone, '[^0-9]', '')) > 14
                          OR telefone LIKE '%@lid%')
                   ORDER BY id ASC");
$conversas = $st->fetchAll();
echo '<div class="box ' . (count($conversas) > 0 ? 'warn' : 'ok') . '">' . count($conversas) . ' conversas com @lid bruto identificadas</div>';

if (empty($conversas)) {
    echo '<p>✓ Nada a fazer.</p></body></html>';
    exit;
}

$confirmar = isset($_GET['confirmar']);
$contadores = array('via_client_id' => 0, 'via_nome' => 0, 'precisa_revisao' => 0, 'sem_mudanca' => 0);
$rows = array();

foreach ($conversas as $conv) {
    $convId = $conv['id'];
    $telOrig = $conv['telefone'];
    $lidOrig = $conv['chat_lid'] ?: $telOrig; // se chat_lid vazio, o "telefone" provavelmente é o lid
    $clientIdAtual = $conv['client_id'] ? (int)$conv['client_id'] : 0;
    $nomeContato = $conv['nome_contato'] ?? '';

    $resultado = array(
        'conv_id' => $convId, 'tel_orig' => $telOrig, 'lid' => $lidOrig,
        'nome_contato' => $nomeContato, 'client_id_antes' => $clientIdAtual,
        'acao' => null, 'tel_novo' => null, 'client_id_depois' => null, 'msg' => '',
    );

    // CASO 1: conv tem client_id e o cliente tem phone válido
    if ($clientIdAtual > 0) {
        $stCli = $pdo->prepare("SELECT phone FROM clients WHERE id = ?");
        $stCli->execute(array($clientIdAtual));
        $phoneCli = trim((string)$stCli->fetchColumn());
        if ($phoneCli && _eh_phone_valido($phoneCli)) {
            $telLimpo = preg_replace('/\D/', '', $phoneCli);
            if (strlen($telLimpo) === 10 || strlen($telLimpo) === 11) $telLimpo = '55' . $telLimpo;
            $resultado['acao'] = 'via_client_id';
            $resultado['tel_novo'] = $telLimpo;
            $resultado['client_id_depois'] = $clientIdAtual;
            $resultado['msg'] = "Cliente #{$clientIdAtual} já vinculado com phone={$phoneCli}";
            if ($confirmar) {
                $pdo->prepare("UPDATE zapi_conversas SET telefone = ?, chat_lid = ?, precisa_revisao = 0, motivo_revisao = NULL WHERE id = ?")
                    ->execute(array($telLimpo, $lidOrig, $convId));
            }
            $contadores['via_client_id']++;
            $rows[] = $resultado;
            continue;
        }
    }

    // CASO 2: tenta achar cliente único pelo nome_contato
    if ($nomeContato && mb_strlen(trim($nomeContato), 'UTF-8') >= 4) {
        $nm = trim($nomeContato);
        $stMatch = $pdo->prepare("SELECT id, name, phone FROM clients
                                  WHERE name LIKE ? AND phone IS NOT NULL AND phone != ''
                                  LIMIT 5");
        $stMatch->execute(array('%' . $nm . '%'));
        $candidatos = $stMatch->fetchAll();
        if (count($candidatos) === 1) {
            $cli = $candidatos[0];
            $phoneCli = trim($cli['phone']);
            if (_eh_phone_valido($phoneCli)) {
                $telLimpo = preg_replace('/\D/', '', $phoneCli);
                if (strlen($telLimpo) === 10 || strlen($telLimpo) === 11) $telLimpo = '55' . $telLimpo;
                $resultado['acao'] = 'via_nome';
                $resultado['tel_novo'] = $telLimpo;
                $resultado['client_id_depois'] = (int)$cli['id'];
                $resultado['msg'] = "Match único: {$cli['name']} (#{$cli['id']}) phone={$phoneCli}";
                if ($confirmar) {
                    $pdo->prepare("UPDATE zapi_conversas SET telefone = ?, chat_lid = ?, client_id = ?, precisa_revisao = 0, motivo_revisao = NULL WHERE id = ?")
                        ->execute(array($telLimpo, $lidOrig, $cli['id'], $convId));
                }
                $contadores['via_nome']++;
                $rows[] = $resultado;
                continue;
            }
        } elseif (count($candidatos) > 1) {
            $resultado['msg'] = count($candidatos) . " candidatos com nome parecido — ambíguo";
        }
    }

    // CASO 3: marca pra revisão manual
    $resultado['acao'] = 'precisa_revisao';
    if (!$resultado['msg']) {
        $resultado['msg'] = $clientIdAtual > 0 ? 'Cliente vinculado mas sem phone válido' : 'Sem client_id e sem nome que case com cliente';
    }
    if ($confirmar) {
        $pdo->prepare("UPDATE zapi_conversas SET chat_lid = COALESCE(NULLIF(chat_lid,''), ?), precisa_revisao = 1, motivo_revisao = ? WHERE id = ?")
            ->execute(array($lidOrig, $resultado['msg'], $convId));
    }
    $contadores['precisa_revisao']++;
    $rows[] = $resultado;
}

// Helper: phone tem que parecer um telefone real (8-13 dígitos, não @lid)
function _eh_phone_valido($p) {
    if (!$p) return false;
    if (strpos($p, '@lid') !== false) return false;
    $d = preg_replace('/\D/', '', $p);
    if (strlen($d) > 14) return false; // @lid bruto tem mais de 14
    if (strlen($d) < 8)  return false;
    return true;
}

// Render resultado
echo '<div class="box ' . ($confirmar ? 'ok' : 'warn') . '">';
echo $confirmar ? '<strong>✓ Mudanças aplicadas.</strong>' : '<strong>⚠️ MODO PRÉ-CHECK</strong> (nada foi alterado ainda — adicione <code>&confirmar=1</code> pra aplicar)';
echo '</div>';

echo '<h2>📊 Resumo</h2>';
echo '<table style="max-width:500px"><thead><tr><th>Categoria</th><th>Qtd</th></tr></thead><tbody>';
echo '<tr><td><span class="tag t-cli">via client_id</span> Recuperáveis (cliente vinculado com phone)</td><td><strong>' . $contadores['via_client_id'] . '</strong></td></tr>';
echo '<tr><td><span class="tag t-nome">via nome</span> Recuperáveis (match único pelo nome)</td><td><strong>' . $contadores['via_nome'] . '</strong></td></tr>';
echo '<tr><td><span class="tag t-rev">precisa revisão</span> Sem dados pra recuperar</td><td><strong>' . $contadores['precisa_revisao'] . '</strong></td></tr>';
echo '</tbody></table>';

echo '<h2>Detalhamento de cada conversa</h2>';
echo '<table><thead><tr><th>conv</th><th>Tel original</th><th>LID</th><th>Nome contato</th><th>Ação</th><th>Tel novo</th><th>Client</th><th>Msg</th></tr></thead><tbody>';
foreach ($rows as $r) {
    $tag = $r['acao'] === 'via_client_id' ? '<span class="tag t-cli">via_client</span>'
         : ($r['acao'] === 'via_nome' ? '<span class="tag t-nome">via_nome</span>'
         : '<span class="tag t-rev">REVISAR</span>');
    echo '<tr><td>' . $r['conv_id'] . '</td><td><code>' . htmlspecialchars($r['tel_orig']) . '</code></td><td><code>' . htmlspecialchars($r['lid']) . '</code></td><td>' . htmlspecialchars($r['nome_contato']) . '</td><td>' . $tag . '</td><td><code>' . htmlspecialchars($r['tel_novo'] ?: '-') . '</code></td><td>' . ($r['client_id_depois'] ?: '-') . '</td><td>' . htmlspecialchars($r['msg']) . '</td></tr>';
}
echo '</tbody></table>';

if ($confirmar) {
    audit_log('lid_bruto_migrado', 'zapi_conversas', 0,
        "via_client_id={$contadores['via_client_id']} via_nome={$contadores['via_nome']} revisao={$contadores['precisa_revisao']}");
}
echo '</body></html>';
