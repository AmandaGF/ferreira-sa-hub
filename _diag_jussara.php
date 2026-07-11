<?php
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_datajud.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== TODAS as Jussaras no sistema ===\n\n";
$stmt = $pdo->query("
    SELECT cs.id, cs.title, cs.case_number, cs.comarca_uf, cs.comarca, cs.court, cs.status,
           c.name AS cliente_nome
    FROM cases cs
    LEFT JOIN clients c ON c.id = cs.client_id
    WHERE (cs.title LIKE '%JUSSARA%' OR c.name LIKE '%JUSSARA%' OR cs.title LIKE '%Jussara%' OR c.name LIKE '%Jussara%')
    ORDER BY cs.id DESC
");
foreach ($stmt as $r) {
    printf("#%-5d | CNJ=%s | UF=%-3s | %s\n",
        $r['id'], $r['case_number'] ?: '(sem)', $r['comarca_uf'] ?: '-', mb_substr($r['title'], 0, 60));
    printf("        cliente=%s | comarca=%s | court=%s | status=%s\n\n",
        $r['cliente_nome'] ?: '-', $r['comarca'] ?: '-', $r['court'] ?: '-', $r['status']);
}

echo "\n=== Consulta REAL no DataJud pros CNJs .8.26. da Jussara ===\n\n";
$cnjs = array('10103474820248260127', '10135830820248260127');
foreach ($cnjs as $cnj) {
    echo "Consultando $cnj...\n";
    // Testa em TJSP (indice api_publica_tjsp) e TJMG (api_publica_tjmg)
    foreach (array('api_publica_tjsp' => 'TJSP', 'api_publica_tjmg' => 'TJMG') as $indice => $lbl) {
        $url = 'https://api-publica.datajud.cnj.jus.br/' . $indice . '/_search';
        $payload = json_encode(array('query' => array('match' => array('numeroProcesso' => $cnj)), 'size' => 1));
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => array('Content-Type: application/json', 'Authorization: APIKey ' . DATAJUD_API_KEY),
        ));
        $r = curl_exec($ch);
        $c = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $data = json_decode($r, true);
        $hits = $data['hits']['hits'] ?? array();
        if ($hits) {
            $src = $hits[0]['_source'];
            printf("  [%s] HTTP=%d ACHOU! tribunal=%s | orgaoJulgador=%s | classe=%s\n",
                $lbl, $c, $src['tribunal'] ?? '?',
                $src['orgaoJulgador']['nome'] ?? '?',
                $src['classe']['nome'] ?? '?');
        } else {
            printf("  [%s] HTTP=%d NAO encontrado\n", $lbl, $c);
        }
        sleep(2);
    }
    echo "\n";
}
