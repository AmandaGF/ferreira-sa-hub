<?php
/**
 * Corrige telefone de conversas zapi de um cliente que foi cadastrado
 * SEM '+' como internacional e ganhou '55' prefixado erroneamente.
 *
 * Caso classico: Renata da Silva de Amorim (Espanha, DDI 34).
 * Telefone real: +34 661 457 631 -> 34661457631
 * Cadastrado antes como: 34661457631 (sem '+')
 * Sistema viu 11 dig e prefixou 55: 5534661457631
 * Conversa criada no Hub com esse numero errado, mensagens vao pra Brasil DDD 34 (Uberlandia).
 *
 * Uso:
 *   ?key=XXX&client_id=N                                   -> PREVIEW (read-only)
 *   ?key=XXX&client_id=N&novo_telefone=34661457631&confirm=1 -> APLICA
 *
 * Criado 08/06/2026 a pedido da Amanda.
 */

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';

if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') {
    http_response_code(403); exit('forbidden');
}

header('Content-Type: text/plain; charset=utf-8');

$pdo = db();
$clientId = (int)($_GET['client_id'] ?? 0);
$novoTel  = preg_replace('/\D/', '', $_GET['novo_telefone'] ?? '');
$confirm  = !empty($_GET['confirm']);

if (!$clientId) {
    echo "USO:\n";
    echo "  ?key=fsa-hub-deploy-2026&client_id=N                                   -> PREVIEW\n";
    echo "  ?key=fsa-hub-deploy-2026&client_id=N&novo_telefone=34661457631&confirm=1 -> APLICA\n\n";
    echo "Pra achar client_id, busque a cliente no CRM e pegue do URL: clientes/ver.php?id=N\n";
    echo "Pra Renata da Silva de Amorim (Espanha), o novo_telefone correto e 34661457631 (DDI 34 + numero).\n";
    exit;
}

// Cliente
$cli = $pdo->prepare("SELECT id, name, phone FROM clients WHERE id = ?");
$cli->execute(array($clientId));
$cliRow = $cli->fetch();
if (!$cliRow) { echo "Cliente $clientId nao encontrado.\n"; exit; }

echo "=== CLIENTE ===\n";
echo "ID: " . $cliRow['id'] . "\n";
echo "Nome: " . $cliRow['name'] . "\n";
echo "Phone (cadastro): " . ($cliRow['phone'] ?? '(vazio)') . "\n\n";

// Conversas zapi vinculadas
$stConv = $pdo->prepare(
    "SELECT id, telefone, nome_contato, instancia_id, status, ultima_msg_em
     FROM zapi_conversas
     WHERE client_id = ?
     ORDER BY ultima_msg_em DESC"
);
$stConv->execute(array($clientId));
$convs = $stConv->fetchAll();

if (!$convs) { echo "Nenhuma conversa zapi vinculada a este cliente.\n"; exit; }

echo "=== CONVERSAS ZAPI VINCULADAS (" . count($convs) . ") ===\n\n";
foreach ($convs as $c) {
    echo "  conv#" . $c['id'] . " | tel=" . $c['telefone'] . " | nome=" . ($c['nome_contato'] ?: '—')
       . " | inst=" . $c['instancia_id'] . " | status=" . $c['status'] . " | ult_msg=" . $c['ultima_msg_em'] . "\n";
}
echo "\n";

if (!$novoTel) {
    echo "[PREVIEW] Pra corrigir, passe &novo_telefone=NUMERO_CORRETO_SEM_+ (so digitos com DDI no inicio).\n";
    echo "Exemplo Renata Espanha: &novo_telefone=34661457631\n";
    exit;
}

echo "=== ALTERACAO PROPOSTA ===\n";
echo "Novo telefone (sem '+'): " . $novoTel . "\n\n";

// Detecta conversas que tem 55 prefixado erroneamente (telefone = '55' . $novoTel)
$telErrado = '55' . $novoTel;
$candidatos = array();
foreach ($convs as $c) {
    if ($c['telefone'] === $telErrado || $c['telefone'] === $novoTel) {
        $candidatos[] = $c;
    }
}

if (!$candidatos) {
    echo "Nenhuma conversa com telefone='" . $telErrado . "' ou '" . $novoTel . "' achada.\n";
    echo "Confira os telefones listados acima -- pode estar em outro formato.\n";
    exit;
}

foreach ($candidatos as $c) {
    if ($c['telefone'] === $telErrado) {
        echo "  conv#" . $c['id'] . " | ANTES: " . $c['telefone'] . "  ->  DEPOIS: " . $novoTel . "\n";
    } else {
        echo "  conv#" . $c['id'] . " | ja esta certo (telefone=" . $c['telefone'] . ")\n";
    }
}

if (!$confirm) {
    echo "\n[PREVIEW] Pra APLICAR, adicione &confirm=1 na URL.\n";
    exit;
}

// Verifica colisao: ja existe conversa com novo_telefone nesta mesma instancia?
foreach ($candidatos as $c) {
    if ($c['telefone'] !== $telErrado) continue;
    $stColl = $pdo->prepare(
        "SELECT id FROM zapi_conversas WHERE telefone = ? AND instancia_id = ? AND id != ?"
    );
    $stColl->execute(array($novoTel, $c['instancia_id'], $c['id']));
    $collId = $stColl->fetchColumn();
    if ($collId) {
        echo "  conv#" . $c['id'] . " -> COLISAO: ja existe conv#" . $collId . " com telefone=" . $novoTel . " na mesma instancia. Pular esta.\n";
        continue;
    }

    try {
        $pdo->prepare("UPDATE zapi_conversas SET telefone = ?, updated_at = NOW() WHERE id = ?")
            ->execute(array($novoTel, $c['id']));
        echo "  conv#" . $c['id'] . " | OK (atualizado para " . $novoTel . ")\n";
        try {
            $pdo->prepare("INSERT INTO audit_log (user_id, action, entity_type, entity_id, details, created_at) VALUES (0, ?, ?, ?, ?, NOW())")
                ->execute(array('corrigir_telefone_internacional', 'zapi_conversa', $c['id'], 'antes=' . $telErrado . ' depois=' . $novoTel . ' client=' . $clientId));
        } catch (Exception $eA) {}
    } catch (Exception $eU) {
        echo "  conv#" . $c['id'] . " | ERRO: " . $eU->getMessage() . "\n";
    }
}

echo "\n=== CONCLUIDO ===\n";
