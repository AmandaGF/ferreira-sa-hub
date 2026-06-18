<?php
/**
 * Diagnóstico: por que só aparecem mensagens recentes no chat do Hub?
 * Hipóteses:
 *   A) LIMIT 500 por conversa — conversa movimentada esconde as antigas
 *   B) momment_ms gravado em SEGUNDOS (1.7e9) em vez de MS (1.7e12) —
 *      _ord_ts minúsculo joga a mensagem pra fora do top 500 (ORDER BY DESC)
 *
 * Uso: curl "https://ferreiraesa.com.br/conecta/diag_wa_historico.php?key=fsa-hub-deploy-2026"
 *      opcional: &telefone=552499999999  (foca numa conversa)
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';

if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') {
    http_response_code(403);
    exit('Forbidden.');
}

header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== DIAG WhatsApp — histórico de mensagens ===\n";
echo "Agora: " . date('Y-m-d H:i:s') . "\n\n";

// Limiares: ms desde epoch hoje ~ 1.75e12; segundos ~ 1.75e9
$MS_MIN = 1000000000000; // 1e12

// 1. Distribuição de momment_ms
echo "--- 1. Distribuição de momment_ms (toda a tabela) ---\n";
$st = $pdo->query(
    "SELECT
        COUNT(*) AS total,
        SUM(momment_ms IS NULL) AS nulos,
        SUM(momment_ms = 0) AS zeros,
        SUM(momment_ms > 0 AND momment_ms < $MS_MIN) AS em_segundos,
        SUM(momment_ms >= $MS_MIN) AS em_ms
     FROM zapi_mensagens"
);
$r = $st->fetch();
echo "Total mensagens:        " . $r['total'] . "\n";
echo "momment_ms NULL:        " . $r['nulos'] . "  (usa fallback created_at — OK)\n";
echo "momment_ms = 0:         " . $r['zeros'] . "  (_ord_ts=0 → vai pro fundo!)\n";
echo "momment_ms em SEGUNDOS: " . $r['em_segundos'] . "  (BUG: ordena abaixo das que estão em ms)\n";
echo "momment_ms em MS:       " . $r['em_ms'] . "  (correto)\n\n";

// 2. Faixa de datas global
echo "--- 2. Faixa de datas (created_at) ---\n";
$r2 = $pdo->query("SELECT MIN(created_at) AS mais_antiga, MAX(created_at) AS mais_nova FROM zapi_mensagens")->fetch();
echo "Mais antiga: " . $r2['mais_antiga'] . "\n";
echo "Mais nova:   " . $r2['mais_nova'] . "\n\n";

// 3. Top conversas por nº de mensagens + faixa de datas
echo "--- 3. Top 15 conversas por volume (candidatas a estourar LIMIT 500) ---\n";
try {
    // Agrega só na tabela de mensagens (sem JOIN) — mais rápido e sem ONLY_FULL_GROUP_BY
    $st3 = $pdo->query(
        "SELECT conversa_id, COUNT(*) AS qt, MIN(created_at) AS dt_min, MAX(created_at) AS dt_max
         FROM zapi_mensagens GROUP BY conversa_id ORDER BY qt DESC LIMIT 15"
    );
    $rows = $st3->fetchAll();
    echo sprintf("%-6s %-5s %-15s %-6s %-19s %-19s\n", 'conv', 'canal', 'telefone', 'qtd', 'mais_antiga', 'mais_nova');
    foreach ($rows as $c) {
        $cinfo = $pdo->prepare("SELECT canal, telefone, nome_contato AS nome FROM zapi_conversas WHERE id = ?");
        $cinfo->execute(array($c['conversa_id']));
        $ci = $cinfo->fetch() ?: array('canal' => '?', 'telefone' => '?', 'nome' => '');
        echo sprintf("%-6s %-5s %-15s %-6s %-19s %-19s  %s\n",
            $c['conversa_id'], $ci['canal'], substr($ci['telefone'], 0, 15), $c['qt'],
            $c['dt_min'], $c['dt_max'], ($c['qt'] > 500 ? '⚠ >500' : ''));
    }
} catch (Exception $e) {
    echo "ERRO na seção 3: " . $e->getMessage() . "\n";
}
echo "\n";

// 4. Se telefone informado: simula EXATAMENTE a query do chat e mostra o corte
$tel = preg_replace('/\D/', '', $_GET['telefone'] ?? '');
if ($tel !== '') {
    echo "--- 4. Conversa do telefone $tel ---\n";
    $cv = $pdo->prepare("SELECT id, canal, telefone, nome_contato AS nome FROM zapi_conversas WHERE telefone LIKE ? ORDER BY id DESC");
    $cv->execute(array('%' . $tel . '%'));
    foreach ($cv->fetchAll() as $conv) {
        $cid = $conv['id'];
        $tot = $pdo->prepare("SELECT COUNT(*) FROM zapi_mensagens WHERE conversa_id = ?");
        $tot->execute(array($cid));
        $qt = (int)$tot->fetchColumn();
        echo "Conversa id={$cid} canal={$conv['canal']} nome=" . ($conv['nome'] ?: '—') . " → {$qt} msgs totais\n";

        // a mais antiga QUE APARECE hoje (top 500 por _ord_ts, igual ao chat)
        $sim = $pdo->prepare(
            "SELECT MIN(created_at) AS dt_corte FROM (
                SELECT m.created_at, COALESCE(m.momment_ms, UNIX_TIMESTAMP(m.created_at)*1000) AS _ord_ts
                FROM zapi_mensagens m WHERE m.conversa_id = ?
                ORDER BY _ord_ts DESC, m.id DESC LIMIT 500
             ) sub"
        );
        $sim->execute(array($cid));
        $corte = $sim->fetchColumn();
        $realMin = $pdo->prepare("SELECT MIN(created_at) FROM zapi_mensagens WHERE conversa_id = ?");
        $realMin->execute(array($cid));
        echo "   data real mais antiga:        " . $realMin->fetchColumn() . "\n";
        echo "   mais antiga VISÍVEL no chat:  " . $corte . ($qt > 500 ? "  (cortada por LIMIT 500)" : "") . "\n";
    }
    echo "\n";
}

// 5. Lista de conversas: a tela usa LIMIT 200 ordenado por ultima_msg_em DESC.
//    Se >200 conversas ativas, as menos recentes somem da lista.
echo "--- 5. Lista de conversas (LIMIT 200 da tela) ---\n";
foreach (array('21', '24') as $canal) {
    $tot = $pdo->prepare("SELECT COUNT(*) FROM zapi_conversas WHERE canal = ? AND status != 'arquivado'");
    $tot->execute(array($canal));
    $qtTot = (int)$tot->fetchColumn();

    // ultima_msg_em da 200ª conversa (o ponto de corte da tela)
    $corte = $pdo->prepare(
        "SELECT COALESCE(ultima_msg_em, created_at) AS dt FROM zapi_conversas
         WHERE canal = ? AND status != 'arquivado'
         ORDER BY COALESCE(fixada,0) DESC, COALESCE(ultima_msg_em, created_at) DESC
         LIMIT 1 OFFSET 199"
    );
    $corte->execute(array($canal));
    $dtCorte = $corte->fetchColumn();

    echo "Canal $canal: $qtTot conversas (nao-arquivadas).";
    if ($qtTot > 200) {
        echo "  ⚠ Tela mostra so as 200 mais recentes — corte em ultima_msg_em = " . ($dtCorte ?: '?') . "\n";
        echo "          → as " . ($qtTot - 200) . " conversas que falaram antes disso NAO aparecem (so via busca).\n";
    } else {
        echo "  OK (cabe nas 200, sem corte).\n";
    }
}
echo "\n";

echo "=== FIM ===\n";
