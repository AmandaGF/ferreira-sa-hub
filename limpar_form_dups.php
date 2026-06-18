<?php
/**
 * Limpa duplicatas de form_submissions criadas por reenvio (mesmo form_type +
 * payload_json identico). Mantem o registro de MENOR id de cada grupo (o
 * primeiro, que criou/linkou cliente e lead) e remove os demais.
 *
 * SIMULACAO (default): so conta o que seria apagado, NAO apaga nada.
 *   curl "https://ferreiraesa.com.br/conecta/limpar_form_dups.php?key=fsa-hub-deploy-2026"
 * EXECUTAR de verdade:
 *   curl "https://ferreiraesa.com.br/conecta/limpar_form_dups.php?key=fsa-hub-deploy-2026&exec=1"
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Forbidden.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

$exec = (($_GET['exec'] ?? '') === '1');
echo "=== Limpeza de duplicatas form_submissions ===\n";
echo $exec ? "MODO: EXECUCAO (vai apagar)\n\n" : "MODO: SIMULACAO (nada sera apagado)\n\n";

// Grupos com mais de 1 linha identica (mesmo form_type + payload_json)
$grupos = $pdo->query(
    "SELECT form_type, payload_json, COUNT(*) AS qt, MIN(id) AS keep_id
     FROM form_submissions
     GROUP BY form_type, payload_json
     HAVING qt > 1"
)->fetchAll();

$totalGrupos = 0; $totalApagar = 0;
$delStmt = $pdo->prepare("DELETE FROM form_submissions WHERE form_type = ? AND payload_json = ? AND id <> ?");

foreach ($grupos as $g) {
    $totalGrupos++;
    $totalApagar += ((int)$g['qt'] - 1);
    if ($exec) {
        $delStmt->execute(array($g['form_type'], $g['payload_json'], $g['keep_id']));
    }
}

echo "Grupos de duplicatas encontrados: $totalGrupos\n";
echo ($exec ? "Linhas APAGADAS: " : "Linhas que SERIAM apagadas: ") . $totalApagar . "\n";
echo "(mantido 1 registro por grupo — o de menor id)\n\n";
echo "=== FIM ===\n";
