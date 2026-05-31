<?php
/**
 * seed_fluxo_demo.php
 *
 * Cria (ou re-inspeciona) um fluxo demo pra validar o executor.
 * Idempotente: re-rodar nao duplica nem altera nada. Procura pelo nome
 * canonico "DEMO Motor de Fluxos" e retorna o id se ja existe.
 *
 * Estrutura do fluxo (5 blocos, 4 arestas, linear):
 *
 *   [1 mensagem] → [2 esperar 5min] → [3 capturar:resposta_demo] → [4 eco] → [5 fim]
 *
 *   1) Envia: "Oi {{nome}}! Esse é um teste do motor de fluxos. Me responde qualquer coisa."
 *   2) Pausa ate cliente responder (timeout 5 min)
 *   3) Captura a resposta na chave 'resposta_demo'
 *   4) Envia: "Recebi: \"{{campo:resposta_demo}}\". Obrigada — teste OK 🎯"
 *   5) Marca fim
 *
 * Disparar via: curl -s "https://ferreiraesa.com.br/conecta/seed_fluxo_demo.php?key=fsa-hub-deploy-2026"
 */

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_fluxos.php';

if (!_fluxo_admin_check_key($_GET['key'] ?? '')) { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');

$pdo = db();
$NOME_DEMO = 'DEMO Motor de Fluxos';

echo "=== Seed do fluxo demo ===\n";
echo "Nome canonico: $NOME_DEMO\n\n";

// ── 1. Garante o campo 'resposta_demo' ──
$campoId = fluxo_campo_criar('resposta_demo', 'Resposta do teste', 'texto', 'Captura a primeira resposta do cliente no fluxo demo.');
echo "Campo 'resposta_demo' OK (id=$campoId)\n";

// ── 2. Procura fluxo existente ──
$st = $pdo->prepare("SELECT id, ativo, bloco_inicial_id FROM zapi_fluxo WHERE nome = ? LIMIT 1");
$st->execute(array($NOME_DEMO));
$fluxo = $st->fetch();

if ($fluxo) {
    echo "\nFluxo JA EXISTE (id=" . $fluxo['id'] . ", ativo=" . $fluxo['ativo'] . ", bloco_inicial=" . ($fluxo['bloco_inicial_id'] ?? 'NULL') . ")\n";
    $fluxoId = (int)$fluxo['id'];
} else {
    $pdo->prepare(
        "INSERT INTO zapi_fluxo (nome, descricao, canal, gatilho_tipo, ativo, criado_por, criado_em)
         VALUES (?, ?, NULL, 'manual', 1, NULL, NOW())"
    )->execute(array(
        $NOME_DEMO,
        'Fluxo de demonstracao criado por seed_fluxo_demo.php pra validar o executor. Disparo manual via disparar_fluxo_demo.php.',
    ));
    $fluxoId = (int)$pdo->lastInsertId();
    echo "\nFluxo CRIADO (id=$fluxoId)\n";
}

// ── 3. Garante os 5 blocos (re-rodar nao duplica) ──
// Estrategia: busca por tipo + posicao (pos_x = ordem); se ja existe mantem,
// senao cria. Isso evita conflitar com outros fluxos demo possiveis.
$blocosEsperados = array(
    array('tipo' => 'mensagem',    'pos_x' => 1, 'config' => array('texto' => 'Oi {{nome}}! Esse é um teste do motor de fluxos. Me responde qualquer coisa.')),
    array('tipo' => 'esperar',     'pos_x' => 2, 'config' => array('timeout_min' => 5)),
    array('tipo' => 'capturar',    'pos_x' => 3, 'config' => array('campo' => 'resposta_demo', 'trim' => true)),
    array('tipo' => 'mensagem',    'pos_x' => 4, 'config' => array('texto' => 'Recebi: "{{campo:resposta_demo}}". Obrigada — teste OK 🎯')),
    array('tipo' => 'fim',         'pos_x' => 5, 'config' => array('motivo' => 'concluido')),
);

$blocosIds = array(); // pos_x => id
foreach ($blocosEsperados as $b) {
    $st = $pdo->prepare("SELECT id FROM zapi_fluxo_bloco WHERE fluxo_id = ? AND tipo = ? AND pos_x = ? LIMIT 1");
    $st->execute(array($fluxoId, $b['tipo'], $b['pos_x']));
    $existeId = $st->fetchColumn();
    if ($existeId) {
        $blocosIds[$b['pos_x']] = (int)$existeId;
        // Atualiza config_json caso tenhamos mudado o texto
        $pdo->prepare("UPDATE zapi_fluxo_bloco SET config_json = ? WHERE id = ?")
            ->execute(array(json_encode($b['config'], JSON_UNESCAPED_UNICODE), (int)$existeId));
        echo "  Bloco pos=" . $b['pos_x'] . " tipo=" . $b['tipo'] . " OK (id=" . $existeId . ") - config atualizada\n";
    } else {
        $pdo->prepare(
            "INSERT INTO zapi_fluxo_bloco (fluxo_id, tipo, config_json, pos_x, pos_y, criado_em)
             VALUES (?, ?, ?, ?, 1, NOW())"
        )->execute(array($fluxoId, $b['tipo'], json_encode($b['config'], JSON_UNESCAPED_UNICODE), $b['pos_x']));
        $novoId = (int)$pdo->lastInsertId();
        $blocosIds[$b['pos_x']] = $novoId;
        echo "  Bloco pos=" . $b['pos_x'] . " tipo=" . $b['tipo'] . " CRIADO (id=$novoId)\n";
    }
}

// ── 4. Marca o bloco_inicial_id do fluxo ──
$inicial = $blocosIds[1];
$pdo->prepare("UPDATE zapi_fluxo SET bloco_inicial_id = ? WHERE id = ?")->execute(array($inicial, $fluxoId));
echo "\nBloco inicial: pos=1, id=$inicial\n";

// ── 5. Garante as 4 arestas (linear: 1→2→3→4→5, saida 'default') ──
for ($i = 1; $i <= 4; $i++) {
    $origemId  = $blocosIds[$i];
    $destinoId = $blocosIds[$i + 1];
    $st = $pdo->prepare(
        "SELECT id FROM zapi_fluxo_aresta
         WHERE fluxo_id = ? AND origem_bloco_id = ? AND destino_bloco_id = ? AND saida = 'default'
         LIMIT 1"
    );
    $st->execute(array($fluxoId, $origemId, $destinoId));
    if ($st->fetchColumn()) {
        echo "  Aresta $origemId -[default]-> $destinoId OK\n";
    } else {
        $pdo->prepare(
            "INSERT INTO zapi_fluxo_aresta (fluxo_id, origem_bloco_id, destino_bloco_id, saida)
             VALUES (?, ?, ?, 'default')"
        )->execute(array($fluxoId, $origemId, $destinoId));
        echo "  Aresta $origemId -[default]-> $destinoId CRIADA\n";
    }
}

// ── 6. Inspeciona o grafo carregado pelo executor (sanity) ──
echo "\n--- Grafo carregado pelo executor ---\n";
$grafo = fluxo_carregar($fluxoId);
echo "Fluxo: " . $grafo['fluxo']['nome'] . " (id=" . $grafo['fluxo']['id'] . ", ativo=" . $grafo['fluxo']['ativo'] . ")\n";
echo "Blocos: " . count($grafo['blocos']) . "\n";
foreach ($grafo['blocos'] as $bid => $bl) {
    $cfg = json_decode($bl['config_json'], true) ?: array();
    $resumo = $bl['tipo'];
    if (isset($cfg['texto']))       $resumo .= ' "' . mb_substr($cfg['texto'], 0, 50, 'UTF-8') . (mb_strlen($cfg['texto']) > 50 ? '…' : '') . '"';
    if (isset($cfg['timeout_min'])) $resumo .= ' (timeout=' . $cfg['timeout_min'] . 'min)';
    if (isset($cfg['campo']))       $resumo .= ' (campo=' . $cfg['campo'] . ')';
    echo "  bloco#$bid pos=" . $bl['pos_x'] . " → $resumo\n";
}
echo "Arestas: " . array_sum(array_map('count', $grafo['arestas'])) . "\n";
foreach ($grafo['arestas'] as $oid => $saidas) {
    foreach ($saidas as $saida => $did) {
        echo "  $oid -[$saida]-> $did\n";
    }
}

echo "\n=== Pronto ===\n";
echo "Fluxo demo id=$fluxoId\n";
echo "\nPra disparar numa conversa:\n";
echo "  curl -s 'https://ferreiraesa.com.br/conecta/disparar_fluxo_demo.php?key=fsa-hub-deploy-2026&conv_id=NNN'\n";
echo "Pra simular (sem enviar msg real):\n";
echo "  curl -s 'https://ferreiraesa.com.br/conecta/disparar_fluxo_demo.php?key=fsa-hub-deploy-2026&conv_id=NNN&dry=1'\n";
