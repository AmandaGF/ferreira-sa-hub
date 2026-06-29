<?php
/**
 * Corrige lead do Kanban Comercial que ficou com nome antigo (Yasmim)
 * após renomeação do cliente vinculado (Cosme — client_id 2491).
 *
 * Também lista qualquer outro lead onde pipeline_leads.name != clients.name
 * pra dar visibilidade de outros casos do mesmo bug.
 *
 * Uso: GET /corrigir_lead_cosme.php?key=fsa-hub-deploy-2026
 *      GET /corrigir_lead_cosme.php?key=fsa-hub-deploy-2026&fix=1   (aplica correção)
 */
require_once __DIR__ . '/core/database.php';

$key = $_GET['key'] ?? '';
if ($key !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }

header('Content-Type: text/plain; charset=utf-8');
$pdo = db();
$aplicar = !empty($_GET['fix']);

echo "== Diag de leads com nome divergente do cliente vinculado ==\n\n";

$stmt = $pdo->query("
    SELECT pl.id AS lead_id, pl.name AS lead_name, pl.phone AS lead_phone, pl.stage,
           c.id AS client_id, c.name AS client_name, c.cpf AS client_cpf
    FROM pipeline_leads pl
    JOIN clients c ON c.id = pl.client_id
    WHERE pl.name <> c.name
      AND pl.stage NOT IN ('finalizado','perdido','arquivado')
    ORDER BY pl.id DESC
");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$rows) {
    echo "Nenhum lead com nome divergente encontrado.\n";
} else {
    echo "Encontrados " . count($rows) . " lead(s) divergente(s):\n";
    foreach ($rows as $r) {
        echo "  Lead #{$r['lead_id']} '{$r['lead_name']}' (stage={$r['stage']}) → Client #{$r['client_id']} '{$r['client_name']}' CPF={$r['client_cpf']}\n";
    }
    echo "\n";
}

// Fix pontual por ID: &fix_lead=ID (mais seguro que &fix=1 em massa)
$leadFixId = isset($_GET['fix_lead']) ? (int)$_GET['fix_lead'] : 0;
if ($leadFixId) {
    echo "\n== Aplicando correção apenas no Lead #{$leadFixId} ==\n";
    $alvo = null;
    foreach ($rows as $r) {
        if ((int)$r['lead_id'] === $leadFixId) { $alvo = $r; break; }
    }
    if (!$alvo) {
        echo "  ✗ Lead #{$leadFixId} não está na lista de divergentes (talvez já corrigido).\n";
    } else {
        $up = $pdo->prepare("UPDATE pipeline_leads SET name = ? WHERE id = ?");
        $up->execute(array($alvo['client_name'], $alvo['lead_id']));
        echo "  ✓ Lead #{$alvo['lead_id']}: '{$alvo['lead_name']}' → '{$alvo['client_name']}'\n";
    }
} elseif ($aplicar && $rows) {
    echo "\n== Aplicando correção em MASSA (UPDATE pipeline_leads.name = clients.name) ==\n";
    echo "ATENÇÃO: revisar a lista acima — alguns podem ser vínculos errados, não nome velho.\n\n";
    $up = $pdo->prepare("UPDATE pipeline_leads SET name = ? WHERE id = ?");
    foreach ($rows as $r) {
        $up->execute(array($r['client_name'], $r['lead_id']));
        echo "  ✓ Lead #{$r['lead_id']}: '{$r['lead_name']}' → '{$r['client_name']}'\n";
    }
    echo "\nFeito.\n";
} elseif ($rows) {
    echo "\nPra corrigir só um lead: &fix_lead=ID\n";
    echo "Pra aplicar em TODOS (CUIDADO com vínculos errados): &fix=1\n";
}
