<?php
/**
 * Central VIP F&S — Tradução de andamento processual (IA Haiku)
 *
 * Endpoint AJAX chamado pelo botão "Em linguagem comum" da tela de detalhes
 * do processo. Recebe ?andamento_id=X, valida que pertence ao cliente logado,
 * dispara ia_traduzir_andamento_leigo() e devolve JSON com a tradução.
 *
 * Cacheado: cada andamento é traduzido UMA VEZ e o texto fica salvo em
 * case_andamentos.traducao_leiga. Próxima leitura volta do banco sem custo.
 *
 * Killswitch: ia_feature_traducao_leiga_enabled em `configuracoes`. Se OFF,
 * devolve erro grácil pra UI exibir mensagem.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

// 1. Sessão da Central VIP
salavip_require_login();
$clienteId = salavip_current_cliente_id();
if ($clienteId <= 0) {
    echo json_encode(['ok' => false, 'erro' => 'Sessão inválida.']);
    exit;
}

// 2. Validação do parâmetro
$andamentoId = isset($_GET['andamento_id']) ? (int)$_GET['andamento_id'] : 0;
if ($andamentoId <= 0) {
    echo json_encode(['ok' => false, 'erro' => 'Andamento não informado.']);
    exit;
}

$pdo = sv_db();

// 3. Garante que esse andamento É do cliente logado E está marcado visível
$stmt = $pdo->prepare(
    "SELECT a.id, a.descricao, a.traducao_leiga
     FROM case_andamentos a
     INNER JOIN cases c ON c.id = a.case_id
     WHERE a.id = ?
       AND c.client_id = ?
       AND a.visivel_cliente = 1
       AND c.salavip_ativo = 1
     LIMIT 1"
);
try {
    $stmt->execute([$andamentoId, $clienteId]);
} catch (PDOException $e) {
    // Coluna traducao_leiga ainda não existe — cria
    try { $pdo->exec("ALTER TABLE case_andamentos ADD COLUMN traducao_leiga TEXT NULL"); } catch (Exception $e2) {}
    $stmt->execute([$andamentoId, $clienteId]);
}
$row = $stmt->fetch();
if (!$row) {
    echo json_encode(['ok' => false, 'erro' => 'Andamento não pertence a você ou foi removido.']);
    exit;
}

// 4. Cache hit?
if (!empty($row['traducao_leiga'])) {
    echo json_encode(['ok' => true, 'traducao' => $row['traducao_leiga'], 'cached' => true]);
    exit;
}

// 5. Carrega o hub principal pra chamar IA. Em PRODUCAO: /public_html/salavip
//    e /public_html/conecta sao SIDE-BY-SIDE — usar dirname(__DIR__, 2) + /conecta/.
//    Em DEV (working dir do repo): salavip_src/ e core/ sao irmaos — usar /../../core.
$hubCfg = dirname(__DIR__, 2) . '/conecta/core/config.php'; // producao
if (!is_file($hubCfg)) {
    $hubCfg = __DIR__ . '/../../core/config.php'; // dev
}
$hubDir = dirname($hubCfg);
$hubFn  = $hubDir . '/functions_ia.php';
$hubDb  = $hubDir . '/database.php';
if (!is_file($hubFn) || !is_file($hubDb)) {
    echo json_encode(['ok' => false, 'erro' => 'Módulo de IA indisponível.']);
    exit;
}
// config.php do conecta ja foi carregado pelo salavip/config.php — evita re-definir constantes.
// database.php define a funcao db() usada por functions_ia.php (singleton PDO do hub).
require_once $hubDb;
require_once $hubFn;

// 6. Dispara tradução (Haiku, cacheada)
$resp = ia_traduzir_andamento_leigo($andamentoId, $row['descricao'], 0);

echo json_encode([
    'ok'        => !empty($resp['ok']),
    'traducao'  => $resp['traducao'] ?? '',
    'cached'    => !empty($resp['cached']),
    'erro'      => $resp['erro'] ?? null,
]);
