<?php
/**
 * Reparo retroativo: para cada caso em doc_faltante que NAO tem lead vinculado
 * no Pipeline Comercial (vitimas do bug de "lead roubado" entre duplicatas),
 * cria um lead novo apontando para esse caso.
 *
 * Acesse: ferreiraesa.com.br/conecta/reparar_leads_orfaos_doc_faltante.php?key=fsa-hub-deploy-2026
 *
 * Idempotente: se rodar 2x, nao cria duplicata (verifica linked_case_id antes).
 * Dry-run por padrao. Para aplicar de verdade: ?key=...&aplicar=1
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/functions_utils.php';

$pdo = db();
$aplicar = ($_GET['aplicar'] ?? '0') === '1';

echo "=== Reparo: criar leads para casos em doc_faltante orfaos ===\n";
echo $aplicar ? "MODO: APLICAR (vai gravar no banco)\n\n" : "MODO: DRY-RUN (so simula — adicione &aplicar=1 pra valer)\n\n";

$cases = $pdo->query("
    SELECT cs.id, cs.title, cs.client_id, c.name AS client_name,
           cs.case_type, cs.case_number, cs.stage_antes_doc_faltante
    FROM cases cs
    LEFT JOIN clients c ON c.id = cs.client_id
    WHERE cs.status = 'doc_faltante'
      AND COALESCE(cs.kanban_oculto, 0) = 0
      AND cs.client_id IS NOT NULL
    ORDER BY cs.client_id, cs.id
")->fetchAll();

$criados = 0;
$jaTinha = 0;
$semCliente = 0;

foreach ($cases as $cs) {
    // Checa se ja tem lead vinculado a ESTE caso especifico
    $st = $pdo->prepare("SELECT id FROM pipeline_leads WHERE linked_case_id = ? LIMIT 1");
    $st->execute(array($cs['id']));
    if ($st->fetch()) {
        $jaTinha++;
        continue;
    }

    if (!$cs['client_id']) {
        $semCliente++;
        continue;
    }

    // Pega lista de docs pendentes daquele case para o doc_faltante_motivo
    $stD = $pdo->prepare("SELECT descricao FROM documentos_pendentes WHERE case_id = ? AND status = 'pendente' ORDER BY solicitado_em");
    $stD->execute(array($cs['id']));
    $docs = $stD->fetchAll(PDO::FETCH_COLUMN);
    $motivo = $docs ? implode('; ', $docs) : 'Documento(s) faltante(s)';
    $stageAntes = $cs['stage_antes_doc_faltante'] ?: 'contrato_assinado';
    $nome = $cs['client_name'] ?: ($cs['title'] ?: 'Caso #' . $cs['id']);

    echo "  Caso #" . str_pad((string)$cs['id'], 4, ' ') . " — " . substr($nome, 0, 35)
        . " [tipo=" . ($cs['case_type'] ?: '-') . "]\n";
    echo "    motivo: " . substr($motivo, 0, 80) . "\n";

    if ($aplicar) {
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO pipeline_leads
                    (client_id, linked_case_id, name, stage, case_type,
                     doc_faltante_motivo, stage_antes_doc_faltante, created_at, updated_at)
                 VALUES (?, ?, ?, 'doc_faltante', ?, ?, ?, NOW(), NOW())"
            );
            $stmt->execute(array(
                (int)$cs['client_id'],
                (int)$cs['id'],
                $nome,
                $cs['case_type'] ?: 'outro',
                $motivo,
                $stageAntes,
            ));
            $newLeadId = (int)$pdo->lastInsertId();
            // Atualizar docs pendentes desse caso com o lead_id
            $pdo->prepare("UPDATE documentos_pendentes SET lead_id = ? WHERE case_id = ? AND lead_id IS NULL")
                ->execute(array($newLeadId, (int)$cs['id']));
            // Historico
            try {
                $pdo->prepare("INSERT INTO pipeline_history (lead_id, from_stage, to_stage, changed_by, notes) VALUES (?,?,?,?,?)")
                    ->execute(array($newLeadId, 'auto', 'doc_faltante', 0, 'Reparo retroativo: lead criado para caso orfao em doc_faltante'));
            } catch (Exception $e) {}
            audit_log('reparo_lead_doc_faltante', 'case', (int)$cs['id'], 'Lead #' . $newLeadId . ' criado retroativamente');
            echo "    [OK] Lead #$newLeadId criado.\n";
            $criados++;
        } catch (Exception $e) {
            echo "    [ERRO] " . $e->getMessage() . "\n";
        }
    } else {
        echo "    [SIMULADO] criaria Lead com stage=doc_faltante.\n";
        $criados++;
    }
}

echo "\n--- Resumo ---\n";
echo "  Casos analisados: " . count($cases) . "\n";
echo "  Ja tinham lead:   $jaTinha\n";
echo "  Sem client_id:    $semCliente\n";
echo "  " . ($aplicar ? 'Criados' : 'Seriam criados') . ": $criados\n";
echo "\n[FIM]\n";
