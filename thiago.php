<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== BUSCA POR THIAGO CASSIANO ===\n";
$st = $pdo->query("SELECT id, title, client_id FROM cases WHERE title LIKE '%Cassiano%' OR title LIKE '%Thiago Cass%'");
foreach ($st as $c) {
    echo "\n--- case #{$c['id']} '{$c['title']}' client_id={$c['client_id']} ---\n";
    $ps = $pdo->prepare("SELECT id, papel, tipo_pessoa, nome, razao_social, nome_fantasia, cpf, cnpj, client_id, eh_nosso_cliente
                         FROM case_partes WHERE case_id = ? ORDER BY id");
    $ps->execute(array($c['id']));
    foreach ($ps as $p) {
        printf("  parte#%d papel=%-25s tipo=%s nome='%s' rz='%s' cpf=%s client_id=%s nosso=%d\n",
            $p['id'], $p['papel'], $p['tipo_pessoa']?:'-',
            $p['nome']?:'', $p['razao_social']?:'', $p['cpf']?:'-', $p['client_id']?:'-', $p['eh_nosso_cliente']);
    }
}

echo "\n\n=== TESTANDO buscar_partes_caso(1203) ===\n";
require_once __DIR__ . '/core/functions_cases.php';
$hp = buscar_partes_caso(1203);
echo "Autores: " . count($hp['autores']) . "\n";
foreach ($hp['autores'] as $a) echo "  - " . ($a['nome']?:$a['razao_social']) . "\n";
echo "Reus: " . count($hp['reus']) . "\n";
