<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') die('Acesso negado.');
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Diag nota_fixa + mensagens fixadas ===\n\n";

// 1. Confere se as colunas existem
echo "[1] Schema zapi_conversas:\n";
try {
    $cols = $pdo->query("SHOW COLUMNS FROM zapi_conversas LIKE 'nota_fixa%'")->fetchAll();
    if (empty($cols)) {
        echo "  ❌ Nenhuma coluna nota_fixa* existe!\n";
    } else {
        foreach ($cols as $c) echo "  ✓ " . $c['Field'] . " (" . $c['Type'] . ")\n";
    }
} catch (Exception $e) { echo "  ERRO: " . $e->getMessage() . "\n"; }

// 2. Conta quantas conversas tem nota_fixa preenchida
echo "\n[2] Conversas com nota_fixa preenchida:\n";
try {
    $tot = (int)$pdo->query("SELECT COUNT(*) FROM zapi_conversas WHERE nota_fixa IS NOT NULL AND nota_fixa != ''")->fetchColumn();
    echo "  Total: $tot\n";
    if ($tot > 0) {
        $rows = $pdo->query("SELECT id, telefone, nome_contato, LEFT(nota_fixa, 80) AS preview, nota_fixa_em, nota_fixa_por FROM zapi_conversas WHERE nota_fixa IS NOT NULL AND nota_fixa != '' ORDER BY nota_fixa_em DESC LIMIT 10")->fetchAll();
        foreach ($rows as $r) {
            echo "  conv#" . $r['id'] . " tel=" . $r['telefone'] . " contato=" . ($r['nome_contato'] ?: '-') . " por=" . ($r['nota_fixa_por'] ?: 'NULL') . " em=" . ($r['nota_fixa_em'] ?: 'NULL') . "\n";
            echo "    nota: " . $r['preview'] . "\n";
        }
    }
} catch (Exception $e) { echo "  ERRO: " . $e->getMessage() . "\n"; }

// 3. Schema zapi_mensagens.pinned
echo "\n[3] Schema zapi_mensagens.pinned:\n";
try {
    $cols = $pdo->query("SHOW COLUMNS FROM zapi_mensagens LIKE 'pinn%'")->fetchAll();
    if (empty($cols)) {
        echo "  ❌ Nenhuma coluna pinned* existe!\n";
    } else {
        foreach ($cols as $c) echo "  ✓ " . $c['Field'] . " (" . $c['Type'] . ", default " . ($c['Default'] ?? 'NULL') . ")\n";
    }
} catch (Exception $e) { echo "  ERRO: " . $e->getMessage() . "\n"; }

// 4. Conta mensagens fixadas
echo "\n[4] Mensagens fixadas (pinned=1):\n";
try {
    $tot = (int)$pdo->query("SELECT COUNT(*) FROM zapi_mensagens WHERE pinned = 1")->fetchColumn();
    echo "  Total: $tot\n";
    if ($tot > 0) {
        $rows = $pdo->query("SELECT id, conversa_id, direcao, LEFT(conteudo, 60) AS preview, pinned_at FROM zapi_mensagens WHERE pinned = 1 ORDER BY pinned_at DESC LIMIT 10")->fetchAll();
        foreach ($rows as $r) {
            echo "  msg#" . $r['id'] . " conv=" . $r['conversa_id'] . " dir=" . $r['direcao'] . " em=" . ($r['pinned_at'] ?: 'NULL') . " preview: " . $r['preview'] . "\n";
        }
    }
} catch (Exception $e) { echo "  ERRO: " . $e->getMessage() . "\n"; }

// 5. Últimos audit_log relacionados
echo "\n[5] Últimas 10 entradas audit_log de nota fixa / pin:\n";
try {
    $rows = $pdo->query(
        "SELECT id, action, user_id, entity_type, entity_id, details, created_at
         FROM audit_log
         WHERE action IN ('wa_nota_fixa_set','wa_nota_fixa_remover','wa_pin_msg','wa_unpin_msg')
         ORDER BY id DESC LIMIT 10"
    )->fetchAll();
    if (empty($rows)) {
        echo "  Nenhum registro — sinal de que o endpoint NUNCA foi executado com sucesso.\n";
    } else {
        foreach ($rows as $r) {
            echo "  #" . $r['id'] . " [" . $r['created_at'] . "] user=" . $r['user_id'] . " action=" . $r['action'] . " entity=" . $r['entity_type'] . "#" . $r['entity_id'] . " details=" . substr((string)$r['details'], 0, 80) . "\n";
        }
    }
} catch (Exception $e) { echo "  ERRO: " . $e->getMessage() . "\n"; }

echo "\n[FIM]\n";
