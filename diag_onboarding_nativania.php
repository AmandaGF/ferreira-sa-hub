<?php
// diag_onboarding_nativania.php
// Diagnostico read-only: procura cadastros de onboarding com nome contendo "nativ"
// pra entender pq a Amanda nao esta vendo.
// Disparar: curl -s "https://ferreiraesa.com.br/conecta/diag_onboarding_nativania.php?key=fsa-hub-deploy-2026"
// REMOVER APOS USO.

if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Diag onboarding Nativania ===\n\n";

// ── Acao: reativar cadastro arquivado (idempotente)
if (isset($_GET['reativar'])) {
    $alvoId = (int)$_GET['reativar'];
    $st = $pdo->prepare("SELECT id, nome_completo, status FROM colaboradores_onboarding WHERE id = ?");
    $st->execute(array($alvoId));
    $row = $st->fetch();
    if (!$row) {
        echo "[REATIVAR] id=$alvoId NAO existe na tabela.\n\n";
    } elseif ($row['status'] !== 'arquivado') {
        echo "[REATIVAR] id=$alvoId '" . $row['nome_completo'] . "' ja esta status='" . $row['status'] . "' (nao precisa reativar)\n\n";
    } else {
        $pdo->prepare("UPDATE colaboradores_onboarding SET status = 'ativo' WHERE id = ?")->execute(array($alvoId));
        echo "[REATIVAR] id=$alvoId '" . $row['nome_completo'] . "' status arquivado -> ativo. OK.\n\n";
    }
}


// Conta por status (sem filtro)
echo "[1] Distribuicao de status na tabela colaboradores_onboarding:\n";
$st = $pdo->query("SELECT status, COUNT(*) as qtd FROM colaboradores_onboarding GROUP BY status ORDER BY qtd DESC");
foreach ($st->fetchAll() as $r) echo "  status='" . ($r['status'] ?: '(NULL)') . "' -> " . $r['qtd'] . "\n";

// Procura por nome com "nativ"
echo "\n[2] Cadastros com nome contendo 'nativ' (case-insensitive, qualquer status):\n";
$st = $pdo->prepare("SELECT id, nome_completo, email_institucional, status, created_at, aceite_em
                     FROM colaboradores_onboarding
                     WHERE LOWER(nome_completo) LIKE LOWER(?)
                     ORDER BY created_at DESC");
$st->execute(array('%nativ%'));
$rows = $st->fetchAll();
if (empty($rows)) {
    echo "  NENHUM cadastro com 'nativ' no nome encontrado.\n";
} else {
    foreach ($rows as $r) {
        echo "  id=" . $r['id']
           . " nome='" . $r['nome_completo']
           . "' status='" . $r['status']
           . "' email='" . ($r['email_institucional'] ?: '—')
           . "' criado=" . $r['created_at']
           . " aceito=" . ($r['aceite_em'] ?: '—')
           . "\n";
    }
}

// Confirma que a query da lista (com filtro status != arquivado) bate com o que aparece na UI
echo "\n[3] Lista de TODOS na UI (status != arquivado), ordenada por created_at DESC:\n";
$st = $pdo->query("SELECT id, nome_completo, status, created_at
                   FROM colaboradores_onboarding
                   WHERE status != 'arquivado' OR status IS NULL
                   ORDER BY created_at DESC LIMIT 30");
$rows = $st->fetchAll();
foreach ($rows as $r) {
    $marca = (stripos($r['nome_completo'], 'nativ') !== false) ? ' *** NATIVANIA ***' : '';
    echo "  id=" . $r['id'] . " '" . $r['nome_completo'] . "' status=" . $r['status'] . " criado=" . $r['created_at'] . $marca . "\n";
}

// Verifica audit_log de exclusao/arquivamento recente
echo "\n[4] Acoes recentes em colaboradores_onboarding (audit_log, ultimos 7 dias):\n";
try {
    $st = $pdo->query("SELECT action, entity_id, details, created_at, user_id
                       FROM audit_log
                       WHERE entity = 'colaboradores_onboarding'
                         AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                       ORDER BY created_at DESC LIMIT 50");
    $rows = $st->fetchAll();
    if (empty($rows)) {
        echo "  (sem entries — audit pode usar outro entity name)\n";
    } else {
        foreach ($rows as $r) {
            echo "  " . $r['created_at'] . " action=" . $r['action'] . " entity_id=" . $r['entity_id']
               . " user=" . $r['user_id']
               . " details='" . mb_substr($r['details'] ?: '', 0, 80) . "'\n";
        }
    }
} catch (Exception $e) {
    echo "  (audit_log nao acessivel: " . $e->getMessage() . ")\n";
}

// Verifica coluna email_pessoal (sanity do ultimo fix)
echo "\n[5] Coluna email_pessoal criada?\n";
$st = $pdo->query("SHOW COLUMNS FROM colaboradores_onboarding LIKE 'email_pessoal'");
$row = $st->fetch();
echo "  " . ($row ? "SIM — tipo=" . $row['Type'] . " null=" . $row['Null'] : "NAO existe") . "\n";

echo "\n=== Fim ===\n";
