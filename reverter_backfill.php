<?php
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
header('Content-Type: text/plain; charset=utf-8');
error_reporting(E_ALL); ini_set('display_errors','1');
$pdo = db();

echo "=== REVERTER backfill (troca invertida) ===\n\n";

// Eventos audiencia/preparacao_audiencia updated nos ultimos 10min
$st = $pdo->query(
    "SELECT ae.id, ae.titulo, ae.case_id, ae.client_id, ae.updated_at,
            c1.title AS case_atual,
            cl1.name AS client_atual,
            c2.title AS seria_case_se_trocar,
            cl2.name AS seria_client_se_trocar
     FROM agenda_eventos ae
     LEFT JOIN cases c1 ON c1.id = ae.case_id
     LEFT JOIN clients cl1 ON cl1.id = ae.client_id
     LEFT JOIN cases c2 ON c2.id = ae.client_id
     LEFT JOIN clients cl2 ON cl2.id = ae.case_id
     WHERE ae.tipo IN ('preparacao_audiencia','audiencia')
       AND ae.updated_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)
     ORDER BY ae.id DESC"
);
$evs = $st->fetchAll(PDO::FETCH_ASSOC);
echo "Eventos alterados nos ultimos 10min: " . count($evs) . "\n\n";

$rev = 0;
foreach ($evs as $e) {
    // Verifica: após trocar de novo, o title do evento vai bater com o case novo?
    // O objetivo: se ao TROCAR o case_id e client_id (voltar ao estado antes do backfill),
    // o titulo bate com o titulo_case_apos_reverter, então reverter.

    // Título das partes:
    $nomeNoEv = mb_strtoupper((string)$e['titulo']);
    $caseAtualUpper = mb_strtoupper((string)$e['case_atual']);
    $seriaCaseTrocado = mb_strtoupper((string)$e['seria_case_se_trocar']);

    // Score: bate com case atual? bate com case após reverter?
    $bateAtual = 0; $bateReverter = 0;
    if ($caseAtualUpper) {
        $primToken = explode(' ', trim($caseAtualUpper))[0];
        if ($primToken && mb_strlen($primToken) > 3 && strpos($nomeNoEv, $primToken) !== false) $bateAtual = 1;
    }
    if ($seriaCaseTrocado) {
        $primToken = explode(' ', trim($seriaCaseTrocado))[0];
        if ($primToken && mb_strlen($primToken) > 3 && strpos($nomeNoEv, $primToken) !== false) $bateReverter = 1;
    }

    // Se batem AMBOS, ambiguo -> nao reverte (deixa como esta)
    // Se batia melhor ao reverter (seria_case bate mas atual nao), reverte
    if ($bateReverter && !$bateAtual) {
        echo "REVERTER #ev{$e['id']} '{$e['titulo']}':\n";
        echo "   ATUAL: case_id={$e['case_id']} ({$e['case_atual']}) client_id={$e['client_id']} ({$e['client_atual']})\n";
        echo "   REVERTER PRA: case_id={$e['client_id']} ({$e['seria_case_se_trocar']}) client_id={$e['case_id']} ({$e['seria_client_se_trocar']})\n";
        $stU = $pdo->prepare("UPDATE agenda_eventos SET case_id = ?, client_id = ?, updated_at = NOW() WHERE id = ?");
        $stU->execute(array((int)$e['client_id'], (int)$e['case_id'], (int)$e['id']));
        $rev++;
        echo "   ✓ Revertido\n\n";
    } elseif ($bateAtual && $bateReverter) {
        echo "AMBIGUO #ev{$e['id']} '{$e['titulo']}' — batem os 2 nomes. Deixando como está.\n";
        echo "   ATUAL: {$e['case_atual']} / {$e['client_atual']}\n";
        echo "   SERIA: {$e['seria_case_se_trocar']} / {$e['seria_client_se_trocar']}\n\n";
    } elseif ($bateAtual) {
        echo "OK #ev{$e['id']} '{$e['titulo']}' — atual bate com titulo. Deixa.\n\n";
    } else {
        echo "SEM MATCH #ev{$e['id']} '{$e['titulo']}' — nenhum bate. Deixa como esta.\n\n";
    }
}

echo "=== RESUMO ===\n";
echo "Total revertidos: $rev\n";
