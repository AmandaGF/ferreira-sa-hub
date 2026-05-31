<?php
/**
 * disparar_fluxo_demo.php
 *
 * Dispara o "DEMO Motor de Fluxos" (criado por seed_fluxo_demo.php)
 * numa conversa especifica.
 *
 * Parametros:
 *   ?key=fsa-hub-deploy-2026   obrigatório
 *   ?conv_id=N                 conversa alvo (obrigatório)
 *   ?dry=1                     modo dry-run: NÃO envia msg real, só simula
 *   ?action=info               não dispara, só mostra estado das execuções da conv
 *   ?action=cancelar           cancela qualquer execução viva do demo nessa conv
 *
 * Disparo real:
 *   curl -s "https://ferreiraesa.com.br/conecta/disparar_fluxo_demo.php?key=fsa-hub-deploy-2026&conv_id=123"
 *
 * Disparo dry-run (recomendado pra testar lógica sem mandar mensagem real):
 *   curl -s "https://ferreiraesa.com.br/conecta/disparar_fluxo_demo.php?key=fsa-hub-deploy-2026&conv_id=123&dry=1"
 */

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_fluxos.php';

if (!_fluxo_admin_check_key($_GET['key'] ?? '')) { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');

$pdo = db();
$NOME_DEMO = 'DEMO Motor de Fluxos';

$convId = (int)($_GET['conv_id'] ?? 0);
$dry    = !empty($_GET['dry']);
$action = (string)($_GET['action'] ?? 'disparar');

if ($convId <= 0 && $action !== 'info-fluxo') {
    echo "ERRO: passa ?conv_id=N pra escolher a conversa alvo.\n";
    exit;
}

// ── Localiza o fluxo demo ──
$st = $pdo->prepare("SELECT id, ativo FROM zapi_fluxo WHERE nome = ? LIMIT 1");
$st->execute(array($NOME_DEMO));
$fluxo = $st->fetch();
if (!$fluxo) {
    echo "ERRO: fluxo demo nao existe. Rode primeiro:\n";
    echo "  curl -s 'https://ferreiraesa.com.br/conecta/seed_fluxo_demo.php?key=fsa-hub-deploy-2026'\n";
    exit;
}
$fluxoId = (int)$fluxo['id'];

echo "=== Disparar fluxo demo ===\n";
echo "Fluxo: $NOME_DEMO (id=$fluxoId, ativo=" . $fluxo['ativo'] . ")\n";
echo "Conversa alvo: $convId\n";
echo "Modo: " . ($dry ? 'DRY-RUN (não envia msg real)' : 'REAL') . "\n";
echo "Action: $action\n\n";

// ── Action: info ──
if ($action === 'info') {
    // Mostra dados da conversa + execucoes existentes do demo
    $st = $pdo->prepare("SELECT id, canal, telefone, nome_contato, status FROM zapi_conversas WHERE id = ?");
    $st->execute(array($convId));
    $conv = $st->fetch();
    if (!$conv) {
        echo "Conversa $convId NAO existe.\n";
        exit;
    }
    echo "--- Conversa ---\n";
    echo "  id=" . $conv['id'] . " canal=" . $conv['canal'] . " tel=" . $conv['telefone'] . "\n";
    echo "  nome='" . $conv['nome_contato'] . "' status=" . $conv['status'] . "\n";

    $st = $pdo->prepare(
        "SELECT id, bloco_atual_id, estado, aguardando_ate, tentativas, iniciado_em, atualizado_em
           FROM zapi_fluxo_execucao
          WHERE fluxo_id = ? AND conversa_id = ?
          ORDER BY id DESC LIMIT 10"
    );
    $st->execute(array($fluxoId, $convId));
    $execs = $st->fetchAll();
    echo "\n--- Execucoes do demo nessa conv: " . count($execs) . " ---\n";
    foreach ($execs as $e) {
        echo "  exec#" . $e['id'] . " bloco=" . ($e['bloco_atual_id'] ?? 'NULL')
           . " estado=" . $e['estado']
           . " aguardando_ate=" . ($e['aguardando_ate'] ?? '—')
           . " tentativas=" . $e['tentativas']
           . " iniciado=" . $e['iniciado_em']
           . "\n";
    }

    $vals = fluxo_valores_da_conversa($convId);
    echo "\n--- Valores coletados nessa conv ---\n";
    if (empty($vals)) echo "  (nenhum)\n";
    else foreach ($vals as $k => $v) echo "  $k = '" . mb_substr($v, 0, 200) . "'\n";
    exit;
}

// ── Action: cancelar ──
if ($action === 'cancelar') {
    $st = $pdo->prepare(
        "SELECT id FROM zapi_fluxo_execucao
          WHERE fluxo_id = ? AND conversa_id = ?
            AND estado IN ('em_andamento','aguardando')"
    );
    $st->execute(array($fluxoId, $convId));
    $alvos = $st->fetchAll(PDO::FETCH_COLUMN);
    if (empty($alvos)) {
        echo "Nenhuma execucao viva pra cancelar.\n";
        exit;
    }
    foreach ($alvos as $execId) {
        fluxo_parar((int)$execId, 'cancelado');
        echo "exec#$execId cancelada.\n";
    }
    exit;
}

// ── Action: disparar ──
$st = $pdo->prepare("SELECT id, canal, telefone, nome_contato FROM zapi_conversas WHERE id = ?");
$st->execute(array($convId));
$conv = $st->fetch();
if (!$conv) {
    echo "ERRO: conversa $convId nao existe.\n";
    exit;
}
echo "Conversa: canal=" . $conv['canal'] . " tel=" . $conv['telefone'] . " nome='" . $conv['nome_contato'] . "'\n\n";

if ($dry) {
    // Modo dry-run: intercepta zapi_send_text via runkit? Nao. Em vez disso,
    // simula a execução percorrendo o grafo sem chamar fluxo_avancar real.
    echo "--- DRY-RUN: simulacao passo a passo ---\n";
    $grafo = fluxo_carregar($fluxoId);
    $blocoId = (int)$grafo['fluxo']['bloco_inicial_id'];
    $passos = 0;
    while ($blocoId && $passos < 10) {
        $passos++;
        $b = $grafo['blocos'][$blocoId] ?? null;
        if (!$b) { echo "  bloco $blocoId nao existe\n"; break; }
        $cfg = json_decode($b['config_json'], true) ?: array();
        $line = "  passo $passos: bloco#$blocoId tipo=" . $b['tipo'];
        if ($b['tipo'] === 'mensagem') {
            $tx = $cfg['texto'] ?? '';
            // Resolve {{nome}} pra demonstrar
            $tx = str_replace('{{nome}}', $conv['nome_contato'] ?: 'cliente', $tx);
            $tx = preg_replace('/\{\{campo:[^}]+\}\}/', '<valor_capturado>', $tx);
            $line .= " → ENVIARIA: \"" . $tx . "\"";
            echo "$line\n";
            $blocoId = fluxo_proximo_bloco_id($grafo['arestas'], $blocoId, 'default');
        } elseif ($b['tipo'] === 'esperar') {
            $line .= " → PAUSARIA " . ($cfg['timeout_min'] ?? 60) . " min aguardando resposta\n";
            echo $line;
            echo "  (em dry-run paramos aqui — execução real esperaria o cliente)\n";
            break;
        } elseif ($b['tipo'] === 'capturar') {
            $line .= " → GRAVARIA proxima msg do cliente em campo '" . ($cfg['campo'] ?? '') . "'";
            echo "$line\n";
            $blocoId = fluxo_proximo_bloco_id($grafo['arestas'], $blocoId, 'default');
        } elseif ($b['tipo'] === 'condicional') {
            $line .= " → AVALIARIA campo '" . ($cfg['campo'] ?? '') . "' " . ($cfg['operador'] ?? '?') . " '" . ($cfg['valor'] ?? '') . "'";
            echo "$line\n";
            $blocoId = fluxo_proximo_bloco_id($grafo['arestas'], $blocoId, 'sim'); // assume sim em dry-run
        } elseif ($b['tipo'] === 'fim') {
            $line .= " → FIM da execucao";
            echo "$line\n";
            break;
        } else {
            $line .= " (tipo desconhecido)\n";
            echo $line;
            break;
        }
    }
    echo "\nDry-run concluido. Nada foi gravado, nenhuma msg foi enviada.\n";
    exit;
}

// Disparo REAL
echo "--- Disparo real ---\n";
$execId = fluxo_iniciar($fluxoId, $convId);
if (!$execId) {
    echo "fluxo_iniciar retornou null. Possiveis causas:\n";
    echo "  - fluxo inativo (ativo=0)\n";
    echo "  - fluxo sem bloco inicial\n";
    echo "  - conv_id invalido\n";
    exit;
}
echo "execucao iniciada: exec#$execId\n";

$res = fluxo_avancar((int)$execId, null);
echo "fluxo_avancar() retornou:\n";
foreach ($res as $k => $v) {
    echo "  $k = " . (is_scalar($v) ? $v : json_encode($v)) . "\n";
}

echo "\nPra inspecionar depois:\n";
echo "  curl -s 'https://ferreiraesa.com.br/conecta/disparar_fluxo_demo.php?key=fsa-hub-deploy-2026&conv_id=$convId&action=info'\n";
echo "Pra cancelar:\n";
echo "  curl -s 'https://ferreiraesa.com.br/conecta/disparar_fluxo_demo.php?key=fsa-hub-deploy-2026&conv_id=$convId&action=cancelar'\n";
