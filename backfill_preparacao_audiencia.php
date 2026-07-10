<?php
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
header('Content-Type: text/plain; charset=utf-8');
error_reporting(E_ALL); ini_set('display_errors','1');
$pdo = db();

echo "=== BACKFILL agenda_eventos com case_id/client_id trocados ===\n";
echo "Escopo: tipo IN (preparacao_audiencia, audiencia) que tem referencia_evento_id ou\n";
echo "cujo case_id no banco NAO bate com o case_id 'esperado' de audiencia.\n\n";

// Estratégia: pegar todos eventos onde o client_id gravado eh na verdade um case_id valido
// e o case_id gravado eh na verdade um client_id valido. Detecta pelas colunas da tabela cases.

echo "-- Eventos com POSSIVEL troca (case_id e client_id ambos existem como IDs opostos) --\n";
$st = $pdo->query(
    "SELECT ae.id, ae.titulo, ae.tipo, ae.case_id, ae.client_id,
            (SELECT id FROM cases WHERE id = ae.client_id) AS client_id_bate_case,
            (SELECT id FROM clients WHERE id = ae.case_id) AS case_id_bate_client,
            (SELECT title FROM cases WHERE id = ae.case_id) AS titulo_case_gravado,
            (SELECT name FROM clients WHERE id = ae.client_id) AS titulo_client_gravado
     FROM agenda_eventos ae
     WHERE ae.tipo IN ('preparacao_audiencia','audiencia')
       AND ae.case_id IS NOT NULL AND ae.client_id IS NOT NULL
       AND ae.status NOT IN ('cancelado')
     ORDER BY ae.id DESC"
);
$evs = $st->fetchAll(PDO::FETCH_ASSOC);
echo "Eventos ativos tipo audiencia/preparacao_audiencia: " . count($evs) . "\n\n";

// Filtra os que estao TROCADOS (client_id=case existe + case_id=client existe)
$trocados = array_filter($evs, function($e){
    return !empty($e['client_id_bate_case']) && !empty($e['case_id_bate_client']);
});
echo "Suspeitos de troca: " . count($trocados) . "\n\n";

$ok = 0;
foreach ($trocados as $e) {
    // Confirma que sao coerentes: o case existe (via client_id gravado) e o client existe (via case_id gravado)
    // Antes de trocar, confere se o case_id GRAVADO existe em cases tambem (pode ser client_id coincidindo)
    $stConf = $pdo->prepare("SELECT id FROM cases WHERE id = ?");
    $stConf->execute(array((int)$e['case_id']));
    $caseGravadoExisteComoCase = (bool)$stConf->fetchColumn();

    $stConf2 = $pdo->prepare("SELECT id FROM clients WHERE id = ?");
    $stConf2->execute(array((int)$e['client_id']));
    $clientGravadoExisteComoClient = (bool)$stConf2->fetchColumn();

    // Sinal FORTE de troca: campos opostos sao mais coerentes que os atuais
    // Ou seja: se ao trocar, ambos ficam validos E os atuais tambem ficam validos entao ambiguo.
    // Mas se ao trocar melhora (o case_id novo tem um titulo com nome semelhante ao evento), corrigimos.

    // Regra pratica: como o bug era sistemico (todos eventos criados via chamada errada),
    // vamos trocar TUDO que se encaixe no padrao — evento cujo titulo menciona nome
    // que casa com o titulo_client_gravado (o client_id gravado eh na verdade um case)
    $nomeNoTitulo = mb_strtoupper($e['titulo']);
    $nomeCliNoBanco = mb_strtoupper((string)$e['titulo_client_gravado']);
    $nomeCaseNoBanco = mb_strtoupper((string)$e['titulo_case_gravado']);

    // Se o titulo do evento tem palavras do titulo_client_gravado (que na teoria eh um case_id ao contrario)
    // significa que se trocarmos, o case ficara correto.
    $bateCase = false;
    if ($nomeCliNoBanco) {
        // Pega primeira palavra do titulo do case
        $primeiroToken = explode(' ', trim($nomeCliNoBanco))[0];
        if ($primeiroToken && mb_strlen($primeiroToken) > 3) {
            $bateCase = (strpos($nomeNoTitulo, $primeiroToken) !== false);
        }
    }

    if ($bateCase) {
        echo "TROCA #ev{$e['id']} '{$e['titulo']}':\n";
        echo "   ANTES: case_id={$e['case_id']} client_id={$e['client_id']}\n";
        echo "   DEPOIS: case_id={$e['client_id']} client_id={$e['case_id']}\n";
        echo "   Novo case: {$e['titulo_client_gravado']}\n";
        $stUpd = $pdo->prepare("UPDATE agenda_eventos SET case_id = ?, client_id = ?, updated_at = NOW() WHERE id = ?");
        $stUpd->execute(array((int)$e['client_id'], (int)$e['case_id'], (int)$e['id']));
        $ok++;
        echo "   ✓ Corrigido\n\n";
    } else {
        echo "PULAR #ev{$e['id']} '{$e['titulo']}': titulo nao bate com o titulo do case sugerido\n";
        echo "   case_id atual: {$e['case_id']} ({$e['titulo_case_gravado']})\n";
        echo "   client_id atual: {$e['client_id']} ({$e['titulo_client_gravado']})\n\n";
    }
}

echo "=== RESUMO ===\n";
echo "Total corrigidos: $ok / " . count($trocados) . "\n";
