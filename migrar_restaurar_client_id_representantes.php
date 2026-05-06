<?php
/**
 * RECUPERAÇÃO: re-vincula client_id de partes do tipo representante_legal
 * que foram erroneamente zeradas pela limpeza anterior.
 *
 * Como funciona: faz match por nome (case-insensitive, trim) entre
 * case_partes.nome e clients.name. Se houver match único, restaura
 * client_id. Se houver duplicidade ou nenhum match, deixa NULL e
 * loga pra revisão manual.
 *
 * Uso: curl https://ferreiraesa.com.br/conecta/migrar_restaurar_client_id_representantes.php?key=fsa-hub-deploy-2026
 */
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';

if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') {
    http_response_code(403);
    exit('Forbidden.');
}

header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Restaurando client_id em representantes legais ===\n\n";

// Pega todas as partes representante_legal com client_id NULL
$st = $pdo->query("SELECT id, case_id, nome FROM case_partes
                   WHERE papel = 'representante_legal' AND client_id IS NULL
                     AND nome IS NOT NULL AND TRIM(nome) != ''");
$partes = $st->fetchAll();

echo count($partes) . " representante(s) legal(is) sem client_id.\n\n";

$restaurados = 0;
$ambiguos = 0;
$sem_match = 0;

$findStmt = $pdo->prepare(
    "SELECT id FROM clients WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 2"
);
$updStmt = $pdo->prepare("UPDATE case_partes SET client_id = ? WHERE id = ?");

foreach ($partes as $p) {
    $findStmt->execute(array($p['nome']));
    $matches = $findStmt->fetchAll(PDO::FETCH_COLUMN);

    if (count($matches) === 1) {
        $clientId = (int)$matches[0];
        $updStmt->execute(array($clientId, $p['id']));
        echo "[OK] parte_id={$p['id']} (case_id={$p['case_id']}) — '{$p['nome']}' → client_id={$clientId}\n";
        $restaurados++;
    } elseif (count($matches) > 1) {
        echo "[AMBIGUO] parte_id={$p['id']} — '{$p['nome']}' (>1 cliente com mesmo nome — não restaurado)\n";
        $ambiguos++;
    } else {
        echo "[SEM-MATCH] parte_id={$p['id']} — '{$p['nome']}' (nenhum cliente com esse nome)\n";
        $sem_match++;
    }
}

echo "\n=== Resumo ===\n";
echo "Restaurados:  $restaurados\n";
echo "Ambíguos:     $ambiguos (não restaurados — revisar manual)\n";
echo "Sem match:    $sem_match (provavelmente representantes que NÃO eram clientes)\n";
echo "\nFim.\n";
