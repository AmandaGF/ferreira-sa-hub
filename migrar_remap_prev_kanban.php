<?php
/**
 * Remapeia os status dos 49 cases previdenciarios importados para
 * as colunas reais do Kanban PREV.
 *
 * URL:
 *   ?key=fsa-hub-deploy-2026&modo=simular  (default)
 *   ?key=fsa-hub-deploy-2026&modo=executar
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_utils.php';
header('Content-Type: text/html; charset=utf-8');
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$modo = ($_GET['modo'] ?? 'simular') === 'executar' ? 'executar' : 'simular';

echo '<!doctype html><meta charset="utf-8">';
echo '<style>
body{font-family:system-ui;background:#f8f4ef;color:#052228;padding:1.5rem;max-width:1100px;margin:0 auto;}
h1{color:#052228;border-bottom:3px solid #B87333;padding-bottom:.5rem;}
.ok{background:#d1fae5;color:#065f46;padding:.6rem .9rem;border-radius:8px;margin:.5rem 0;font-weight:600;}
.warn{background:#fef3c7;color:#92400e;padding:.6rem .9rem;border-radius:8px;margin:.5rem 0;}
.modo{padding:.7rem 1rem;border-radius:8px;font-weight:700;text-align:center;margin-bottom:1rem;}
.modo-s{background:#fef3c7;color:#92400e;border:2px solid #f59e0b;}
.modo-e{background:#fee2e2;color:#7f1d1d;border:2px solid #dc2626;}
table{width:100%;border-collapse:collapse;font-size:.82rem;background:#fff;margin:.5rem 0;}
th{background:#052228;color:#fff;padding:.4rem .6rem;text-align:left;font-size:.7rem;}
td{padding:.35rem .6rem;border-bottom:1px solid #e5e7eb;}
code{background:#e5e7eb;padding:1px 4px;border-radius:3px;font-size:.78rem;}
</style>';
echo '<h1>Remapeamento PREV — Kanban</h1>';
echo '<div class="modo modo-' . ($modo === 'executar' ? 'e' : 's') . '">';
echo $modo === 'executar' ? '⚠️ EXECUTANDO' : '📋 SIMULANDO — use ?modo=executar pra aplicar';
echo '</div>';

// Carrega cases importados + dados PREV
$st = $pdo->query("
    SELECT c.id, c.title, c.status AS status_atual, c.notes, c.is_parceria,
           cp.especie, cp.codigo_b, cp.fase, cp.resultado_adm, cp.monitorar_radar,
           cl.name AS cliente
    FROM cases c
    LEFT JOIN cases_previdenciario cp ON cp.case_id = c.id
    LEFT JOIN clients cl ON cl.id = c.client_id
    WHERE c.notes LIKE 'Importado da planilha%'
    ORDER BY c.id
");
$cases = $st->fetchAll();

// Regras de remapeamento
function _decidir_nova_coluna($c) {
    $obs = mb_strtolower($c['notes'] ?? '');

    // Parceria primeiro (Rejane)
    if ((int)$c['is_parceria'] === 1) return 'parceria';

    // Status atual = cancelado -> manter
    if ($c['status_atual'] === 'cancelado') return 'cancelado';

    // Status concluido + deferido + radar = aguardando_implantacao
    if ($c['status_atual'] === 'concluido' && $c['resultado_adm'] === 'deferido') {
        return 'aguardando_implantacao';
    }

    // Status concluido + indeferido = cancelado (sem coluna "indeferido" no Kanban)
    if ($c['status_atual'] === 'concluido' && $c['resultado_adm'] === 'indeferido') {
        return 'cancelado';
    }

    // Em andamento — analisa obs e fase
    if (strpos($obs, 'ag. perícia') !== false || strpos($obs, 'ag. pericia') !== false || strpos($obs, 'ag perícia') !== false) {
        return 'aguardando_pericia';
    }
    if (strpos($obs, 'exigência aberta') !== false || strpos($obs, 'exigencia aberta') !== false) {
        return 'aguardando_docs';
    }
    if (strpos($obs, 'recurso administrativo') !== false) {
        return 'recurso_administrativo';
    }
    if (strpos($obs, 'ag. contestação') !== false || strpos($obs, 'ag. contestacao') !== false) {
        return 'acao_judicial'; // contestacao = ja distribuiu
    }
    if (strpos($obs, 'perícia realizada') !== false || strpos($obs, 'pericia realizada') !== false) {
        return 'aguardando_sentenca';
    }
    if ($c['fase'] === 'jud') return 'acao_judicial';
    if ($c['fase'] === 'recurso_adm' || $c['fase'] === 'crps') return 'recurso_administrativo';

    // Default em andamento: aguardando_analise_inss
    return 'aguardando_analise_inss';
}

// Aplica
$novosStatus = array();
$contadores = array();
foreach ($cases as $c) {
    $novo = _decidir_nova_coluna($c);
    $novosStatus[$c['id']] = $novo;
    if (!isset($contadores[$novo])) $contadores[$novo] = 0;
    $contadores[$novo]++;
}

echo '<h2>Resumo da redistribuição</h2>';
echo '<table><tr><th>Coluna do Kanban PREV</th><th>Quantidade</th></tr>';
arsort($contadores);
foreach ($contadores as $col => $qtd) {
    echo '<tr><td><code>' . htmlspecialchars($col) . '</code></td><td>' . $qtd . '</td></tr>';
}
echo '</table>';

if ($modo === 'executar') {
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("UPDATE cases SET status = ? WHERE id = ?");
        foreach ($novosStatus as $cid => $novo) {
            $stmt->execute(array($novo, $cid));
        }
        $pdo->commit();
        try { audit_log('PREV_REMAP_KANBAN', 'cases', null, count($novosStatus) . ' cases remapeados pras colunas do Kanban PREV'); } catch (Exception $e) {}
        echo '<div class="ok">✓ ' . count($novosStatus) . ' cases remapeados com sucesso.</div>';
    } catch (Exception $e) {
        $pdo->rollBack();
        echo '<div style="background:#fee2e2;color:#7f1d1d;padding:1rem;">ERRO: ' . htmlspecialchars($e->getMessage()) . ' — rollback completo.</div>';
        exit;
    }
}

echo '<h2>Detalhe (todos os 49)</h2>';
echo '<table><tr><th>ID</th><th>Cliente</th><th>Título</th><th>Status atual → Novo</th><th>Resultado / Radar</th></tr>';
foreach ($cases as $c) {
    $novo = $novosStatus[$c['id']];
    $mudou = ($c['status_atual'] !== $novo) ? '→ <strong style="color:#065f46;">' . $novo . '</strong>' : ' (sem mudança)';
    $radar = $c['monitorar_radar'] ? ' 🔭RADAR' : '';
    echo '<tr>';
    echo '<td>#' . $c['id'] . '</td>';
    echo '<td>' . htmlspecialchars(mb_substr($c['cliente'] ?? '?', 0, 35)) . '</td>';
    echo '<td>' . htmlspecialchars(mb_substr($c['title'], 0, 30)) . '</td>';
    echo '<td><code>' . $c['status_atual'] . '</code> ' . $mudou . '</td>';
    echo '<td>' . htmlspecialchars($c['resultado_adm'] ?? '-') . $radar . '</td>';
    echo '</tr>';
}
echo '</table>';

if ($modo === 'simular') {
    echo '<div class="warn">📋 Simulação. Use <code>?modo=executar</code> pra aplicar.</div>';
}
