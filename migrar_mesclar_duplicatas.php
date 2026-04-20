<?php
/**
 * Detecta e mescla em lote conversas duplicadas do WhatsApp.
 *
 * Causa do passivo: Multi-Device da Z-API alterna entre @lid (id interno)
 * e telefone real, criando 2 entradas em zapi_conversas pro mesmo contato.
 *
 * Critérios de match (por canal):
 *   A) Mesmos últimos 10 dígitos do telefone (ignorando @lid/@g.us) — match forte
 *   B) Mesmo nome_contato (não vazio) E últimos 6 dígitos batem — match médio
 *
 * Pra cada grupo de duplicatas:
 *   - A que tem MAIS mensagens vira o destino (titular).
 *   - Empate: a com ultima_msg_em mais recente.
 *   - Outras do grupo são absorvidas (msgs + etiquetas migram).
 *
 * Grupos (eh_grupo=1) ficam fora — não duplicam do mesmo jeito.
 */
require_once __DIR__ . '/core/database.php';

$key = $_GET['key'] ?? '';
if ($key !== 'fsa-hub-deploy-2026') {
    http_response_code(403);
    exit('Chave inválida');
}

$pdo = db();
$dryRun = !isset($_GET['exec']);

header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><meta charset="utf-8"><title>Mesclar conversas duplicadas</title>';
echo '<style>body{font-family:Inter,Arial,sans-serif;max-width:1200px;margin:2rem auto;padding:0 1rem;color:#052228} h1,h2{color:#052228} table{border-collapse:collapse;width:100%;margin:1rem 0;font-size:12px} th,td{border:1px solid #d1d5db;padding:.35rem .55rem;text-align:left;vertical-align:top} th{background:#f3f4f6} .badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600} .ok{background:#d1fae5;color:#065f46} .warn{background:#fef3c7;color:#78350f} .err{background:#fee2e2;color:#991b1b} .act{background:#dc2626;color:#fff;padding:.6rem 1.2rem;border-radius:8px;text-decoration:none;display:inline-block;margin-top:1rem;font-weight:700} .muted{color:#6b7280;font-size:12px} code{background:#f3f4f6;padding:1px 4px;border-radius:3px;font-size:.9em} .dest{background:#ecfdf5;} .orig{background:#fef2f2;}</style>';
echo '<h1>🔗 Mesclar conversas duplicadas em lote</h1>';
echo '<p class="muted">Modo: <strong>' . ($dryRun ? 'DRY RUN (nada altera)' : 'EXECUÇÃO REAL') . '</strong></p>';

// Carrega todas as conversas candidatas (não-grupo)
$conversas = $pdo->query("SELECT id, canal, telefone, nome_contato, ultima_msg_em,
                                  COALESCE(eh_grupo, 0) AS eh_grupo,
                                  (SELECT COUNT(*) FROM zapi_mensagens WHERE conversa_id = co.id) AS qt_msgs
                           FROM zapi_conversas co
                           WHERE COALESCE(eh_grupo, 0) = 0")->fetchAll();

// Indexa por "chave" (últimos 10 dígitos) + por nome
$porDigitos = array(); // "canal::ult10" => [convs]
$porNome    = array(); // "canal::nome"  => [convs]
foreach ($conversas as $c) {
    $tel = (string)$c['telefone'];
    $digits = preg_replace('/\D/', '', str_replace(array('@lid','@g.us'), '', $tel));
    if (strlen($digits) >= 10) {
        $key10 = $c['canal'] . '::' . substr($digits, -10);
        $porDigitos[$key10][] = $c;
    }
    $nome = trim((string)$c['nome_contato']);
    if ($nome !== '') {
        $keyN = $c['canal'] . '::' . mb_strtolower($nome);
        $porNome[$keyN][] = $c;
    }
}

// Agrupa duplicatas: critério A (últimos 10 dígitos)
$gruposPorChave = array();
foreach ($porDigitos as $key => $convs) {
    if (count($convs) >= 2) {
        $gruposPorChave[$key] = array('match' => 'telefone (10 últimos dígitos)', 'convs' => $convs);
    }
}
// Critério B: nome idêntico + 6 dígitos batem (pra pegar casos onde só @lid + telefone real com prefixos diferentes)
foreach ($porNome as $key => $convs) {
    if (count($convs) < 2) continue;
    // Ainda precisa validar dígitos batem entre os pares
    $grupoValido = array();
    $ult6_primeiro = substr(preg_replace('/\D/', '', str_replace(array('@lid','@g.us'), '', $convs[0]['telefone'])), -6);
    if (!$ult6_primeiro) continue;
    foreach ($convs as $c) {
        $d = substr(preg_replace('/\D/', '', str_replace(array('@lid','@g.us'), '', $c['telefone'])), -6);
        if ($d === $ult6_primeiro) $grupoValido[] = $c;
    }
    if (count($grupoValido) >= 2) {
        // Usa o ID do primeiro como chave pra evitar duplicar com grupoPorChave
        $jaExiste = false;
        foreach ($gruposPorChave as $g) {
            $ids = array_column($g['convs'], 'id');
            if (in_array((int)$grupoValido[0]['id'], $ids, true)) { $jaExiste = true; break; }
        }
        if (!$jaExiste) {
            $gruposPorChave['nome_' . $key] = array('match' => 'nome idêntico + 6 dígitos', 'convs' => $grupoValido);
        }
    }
}

if (count($gruposPorChave) === 0) {
    echo '<p class="badge ok">✓ Nenhuma duplicata detectada. Base limpa.</p>';
    exit;
}

echo '<h2>🟠 Grupos identificados: ' . count($gruposPorChave) . '</h2>';

$totalMesclagens = 0;
foreach ($gruposPorChave as $grpKey => $grp) {
    $convs = $grp['convs'];
    // Escolhe titular (destino): maior qt_msgs, empate → ultima_msg_em mais recente
    usort($convs, function($a, $b) {
        if ((int)$b['qt_msgs'] !== (int)$a['qt_msgs']) return (int)$b['qt_msgs'] - (int)$a['qt_msgs'];
        return strcmp((string)$b['ultima_msg_em'], (string)$a['ultima_msg_em']);
    });
    $destino = $convs[0];
    $origens = array_slice($convs, 1);
    $totalMesclagens += count($origens);

    echo '<p><strong>Match por:</strong> ' . htmlspecialchars($grp['match']) . '</p>';
    echo '<table><tr><th>Papel</th><th>ID</th><th>Canal</th><th>Telefone/ID</th><th>Nome</th><th>Msgs</th><th>Última</th></tr>';
    foreach (array_merge(array($destino), $origens) as $i => $c) {
        $isDest = ($i === 0);
        echo '<tr class="' . ($isDest ? 'dest' : 'orig') . '">';
        echo '<td>' . ($isDest ? '<span class="badge ok">🎯 DESTINO</span>' : '<span class="badge err">➡ absorvida</span>') . '</td>';
        echo '<td>#' . (int)$c['id'] . '</td>';
        echo '<td>' . htmlspecialchars($c['canal']) . '</td>';
        echo '<td><code>' . htmlspecialchars($c['telefone']) . '</code></td>';
        echo '<td>' . htmlspecialchars($c['nome_contato'] ?: '—') . '</td>';
        echo '<td>' . (int)$c['qt_msgs'] . '</td>';
        echo '<td>' . htmlspecialchars($c['ultima_msg_em'] ?? '') . '</td>';
        echo '</tr>';
    }
    echo '</table>';

    if (!$dryRun) {
        try {
            $pdo->beginTransaction();
            foreach ($origens as $o) {
                $oid = (int)$o['id'];
                $did = (int)$destino['id'];
                $pdo->prepare("UPDATE zapi_mensagens SET conversa_id = ? WHERE conversa_id = ?")->execute(array($did, $oid));
                $pdo->prepare("UPDATE IGNORE zapi_conversa_etiquetas SET conversa_id = ? WHERE conversa_id = ?")->execute(array($did, $oid));
                $pdo->prepare("DELETE FROM zapi_conversa_etiquetas WHERE conversa_id = ?")->execute(array($oid));
                $pdo->prepare("DELETE FROM zapi_conversas WHERE id = ?")->execute(array($oid));
            }
            // Recalcula resumo do destino
            $pdo->prepare("UPDATE zapi_conversas co
                           SET ultima_mensagem = (SELECT conteudo FROM zapi_mensagens WHERE conversa_id = co.id ORDER BY id DESC LIMIT 1),
                               ultima_msg_em   = (SELECT created_at FROM zapi_mensagens WHERE conversa_id = co.id ORDER BY id DESC LIMIT 1)
                           WHERE id = ?")->execute(array((int)$destino['id']));
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            echo '<p class="badge err">❌ Falha ao mesclar grupo: ' . htmlspecialchars($e->getMessage()) . '</p>';
        }
    }
}

echo '<hr>';
if ($dryRun) {
    echo '<p><strong>Dry run — nada foi alterado.</strong></p>';
    echo '<p>Total de conversas que seriam absorvidas: <strong>' . $totalMesclagens . '</strong></p>';
    echo '<a class="act" href="?key=fsa-hub-deploy-2026&exec=1">▶ Executar mesclagem em lote</a>';
} else {
    echo '<p class="badge ok">✓ ' . $totalMesclagens . ' conversa(s) absorvida(s) em ' . count($gruposPorChave) . ' grupo(s).</p>';
    echo '<p class="muted">Concluído em ' . date('d/m/Y H:i:s') . '.</p>';
}
