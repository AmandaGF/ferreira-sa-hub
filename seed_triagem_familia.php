<?php
/**
 * seed_triagem_familia.php
 *
 * Cria (ou re-aplica) o fluxo "Triagem Inicial - Família" + os 3 campos
 * que ele usa. Idempotente: re-rodar mantém os IDs, só re-aplica config.
 *
 * Fluxo nasce INATIVO pra Amanda revisar antes de ativar.
 *
 * Disparar: curl -s "https://ferreiraesa.com.br/conecta/seed_triagem_familia.php?key=CHAVE"
 */

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_fluxos.php';

if (!_fluxo_admin_check_key($_GET['key'] ?? '')) { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');

$pdo = db();
$NOME_FLUXO = 'Triagem Inicial - Família';

echo "=== Seed: Triagem Inicial - Família ===\n\n";

// ── 1. Campos ────────────────────────────────────────────
$camposEsperados = array(
    'triagem_familia_nome'    => array('Nome completo (triagem família)', 'texto',
                                        'Nome completo capturado na triagem inicial de direito de família.'),
    'triagem_familia_demanda' => array('Tipo de demanda (triagem família)', 'texto',
                                        'Tipo da demanda jurídica em texto livre — ex: divórcio, alimentos, guarda, inventário.'),
    'triagem_familia_filhos'  => array('Tem filhos menores (triagem família)', 'opcao',
                                        'Resposta sim/não pra se há filhos menores de 18 envolvidos.'),
);
echo "Campos:\n";
foreach ($camposEsperados as $chave => $meta) {
    $campoId = fluxo_campo_criar($chave, $meta[0], $meta[1], $meta[2]);
    echo "  ✓ $chave (id=$campoId, tipo={$meta[1]})\n";
}

// ── 2. Fluxo ─────────────────────────────────────────────
$st = $pdo->prepare("SELECT id, ativo FROM zapi_fluxo WHERE nome = ? LIMIT 1");
$st->execute(array($NOME_FLUXO));
$fluxo = $st->fetch();

$gatilhoConfig = json_encode(array(
    'palavras' => array(
        'divorcio', 'divórcio', 'separacao', 'separação',
        'alimentos', 'pensao', 'pensão', 'pensão alimenticia', 'pensao alimenticia',
        'guarda', 'visita', 'visitas',
        'reconhecimento de paternidade', 'paternidade',
        'inventario', 'inventário', 'sucessao', 'sucessão',
    ),
), JSON_UNESCAPED_UNICODE);

if ($fluxo) {
    $fluxoId = (int)$fluxo['id'];
    $pdo->prepare("UPDATE zapi_fluxo SET descricao = ?, canal = NULL, gatilho_tipo = 'palavra_chave', gatilho_config = ? WHERE id = ?")
        ->execute(array(
            'Triagem inicial pra qualquer cliente que chega no WhatsApp falando sobre direito de família. Coleta nome, tipo da demanda e se há filhos menores. Encerra transferindo pra atendente humano.',
            $gatilhoConfig, $fluxoId
        ));
    echo "\nFluxo JÁ EXISTE (id=$fluxoId, ativo=" . $fluxo['ativo'] . ") — config atualizada\n";
} else {
    $pdo->prepare(
        "INSERT INTO zapi_fluxo (nome, descricao, canal, gatilho_tipo, gatilho_config, ativo, criado_em)
         VALUES (?, ?, NULL, 'palavra_chave', ?, 0, NOW())"
    )->execute(array(
        $NOME_FLUXO,
        'Triagem inicial pra qualquer cliente que chega no WhatsApp falando sobre direito de família. Coleta nome, tipo da demanda e se há filhos menores. Encerra transferindo pra atendente humano.',
        $gatilhoConfig,
    ));
    $fluxoId = (int)$pdo->lastInsertId();
    echo "\nFluxo CRIADO (id=$fluxoId, ativo=0)\n";
}

// ── 3. Blocos ────────────────────────────────────────────
$blocosEsperados = array(
    // pos, tipo, config
    array(1,  'mensagem', array('texto' => "Oi {{nome}}! 👋\n\nSou a triagem automática do escritório Ferreira & Sá Advocacia. Vou te fazer 3 perguntas rápidas pra entender sua situação e encaminhar pra advogada certa, tá?\n\nVamos lá!")),
    array(2,  'mensagem', array('texto' => "Primeiro, qual é o seu *nome completo*?")),
    array(3,  'esperar',  array('timeout_min' => 1440)), // 24h
    array(4,  'capturar', array('campo' => 'triagem_familia_nome', 'trim' => true)),
    array(5,  'mensagem', array('texto' => "Obrigada, *{{campo:triagem_familia_nome}}*! 🙌\n\nAgora, em poucas palavras, *sobre o que você precisa de ajuda*?\n\nPor exemplo: divórcio, alimentos, guarda, inventário, reconhecimento de paternidade, união estável...")),
    array(6,  'esperar',  array('timeout_min' => 1440)),
    array(7,  'capturar', array('campo' => 'triagem_familia_demanda', 'trim' => true)),
    array(8,  'mensagem', array('texto' => "Entendi. Última pergunta:\n\n*Tem filhos menores de 18 anos envolvidos* nessa situação?\n\nResponde *SIM* ou *NÃO*.")),
    array(9,  'esperar',  array('timeout_min' => 1440)),
    array(10, 'capturar', array('campo' => 'triagem_familia_filhos', 'trim' => true)),
    array(11, 'anotar',   array('destino' => 'conversa', 'texto' => "📋 TRIAGEM FAMÍLIA\n• Nome: {{campo:triagem_familia_nome}}\n• Demanda: {{campo:triagem_familia_demanda}}\n• Filhos menores: {{campo:triagem_familia_filhos}}")),
    array(12, 'mensagem', array('texto' => "Perfeito, {{campo:triagem_familia_nome}}! Anotei tudo aqui. ✅\n\nVou te passar agora pra advogada — alguém da equipe vai te chamar em breve.\n\nEnquanto isso, se preferir já adiantar algum documento, pode mandar por aqui mesmo. 📎")),
    array(13, 'transferir_humano', array('status' => 'aguardando')),
);

$blocosIds = array(); // pos => id
foreach ($blocosEsperados as $b) {
    list($pos, $tipo, $cfg) = $b;
    $st = $pdo->prepare("SELECT id FROM zapi_fluxo_bloco WHERE fluxo_id = ? AND tipo = ? AND pos_x = ? LIMIT 1");
    $st->execute(array($fluxoId, $tipo, $pos));
    $existeId = $st->fetchColumn();
    if ($existeId) {
        $pdo->prepare("UPDATE zapi_fluxo_bloco SET config_json = ? WHERE id = ?")
            ->execute(array(json_encode($cfg, JSON_UNESCAPED_UNICODE), (int)$existeId));
        $blocosIds[$pos] = (int)$existeId;
    } else {
        $pdo->prepare(
            "INSERT INTO zapi_fluxo_bloco (fluxo_id, tipo, config_json, pos_x, pos_y, criado_em)
             VALUES (?, ?, ?, ?, 1, NOW())"
        )->execute(array($fluxoId, $tipo, json_encode($cfg, JSON_UNESCAPED_UNICODE), $pos));
        $blocosIds[$pos] = (int)$pdo->lastInsertId();
    }
    echo "  pos=$pos $tipo (id={$blocosIds[$pos]})\n";
}

// ── 4. Bloco inicial ────────────────────────────────────
$pdo->prepare("UPDATE zapi_fluxo SET bloco_inicial_id = ? WHERE id = ?")
    ->execute(array($blocosIds[1], $fluxoId));
echo "\nBloco inicial = pos 1 (id={$blocosIds[1]})\n";

// ── 5. Arestas ──────────────────────────────────────────
// Sequência linear 1→2→3→...→13 com saída 'default'
echo "\nArestas:\n";
for ($i = 1; $i <= 12; $i++) {
    $origemId  = $blocosIds[$i];
    $destinoId = $blocosIds[$i + 1];
    $st = $pdo->prepare(
        "SELECT id FROM zapi_fluxo_aresta
         WHERE fluxo_id = ? AND origem_bloco_id = ? AND saida = 'default'
         LIMIT 1"
    );
    $st->execute(array($fluxoId, $origemId));
    $existeId = $st->fetchColumn();
    if ($existeId) {
        $pdo->prepare("UPDATE zapi_fluxo_aresta SET destino_bloco_id = ? WHERE id = ?")
            ->execute(array($destinoId, (int)$existeId));
        echo "  $origemId -[default]-> $destinoId (já existia)\n";
    } else {
        $pdo->prepare(
            "INSERT INTO zapi_fluxo_aresta (fluxo_id, origem_bloco_id, destino_bloco_id, saida)
             VALUES (?, ?, ?, 'default')"
        )->execute(array($fluxoId, $origemId, $destinoId));
        echo "  $origemId -[default]-> $destinoId (criada)\n";
    }
}

// ── 6. Validação final ──────────────────────────────────
echo "\n--- Validação do grafo ---\n";
$problemas = fluxo_validar_grafo($fluxoId);
if (empty($problemas)) {
    echo "✓ Grafo está OK (zero problemas)\n";
} else {
    foreach ($problemas as $p) {
        $emoji = $p['nivel']==='critico'?'🚫':($p['nivel']==='aviso'?'⚠️':'ℹ️');
        echo "  $emoji {$p['nivel']}: {$p['msg']}\n";
    }
}

echo "\n=== Pronto ===\n";
echo "Fluxo id=$fluxoId, INATIVO (nasce desligado).\n\n";
echo "Próximos passos pra Amanda:\n";
echo "  1. Abrir https://ferreiraesa.com.br/conecta/modules/whatsapp/fluxos.php\n";
echo "  2. Clicar no 'Triagem Inicial - Família' pra revisar blocos + palavras-chave\n";
echo "  3. Ajustar mensagens, palavras-gatilho, timeouts conforme quiser\n";
echo "  4. Quando estiver satisfeita, clicar ▶ Ativar na lista\n";
echo "  5. Ligar o killswitch global (botão verde no topo)\n";
echo "  6. Pronto — quando cliente novo falar 'divórcio'/'alimentos'/etc no WhatsApp, o fluxo dispara\n";
