<?php
/**
 * Fecha prazos em prazos_processuais cuja intimacao correspondente em
 * case_publicacoes ja foi descartada/confirmada mas o prazo continuou aberto.
 *
 * URL: /aplicar_fechar_prazos_descartados.php?key=fsa-hub-deploy-2026&modo=simular
 *      adicione &modo=executar pra aplicar.
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_utils.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$modo = ($_GET['modo'] ?? 'simular') === 'executar' ? 'executar' : 'simular';
echo "MODO: $modo\n\n";

// Busca prazos abertos que tem intimacao correspondente ja
// descartada/confirmada (mesma data + mesmo case/CNJ).
$sql = "
    SELECT
        p.id AS prazo_id, p.descricao_acao, p.prazo_fatal, p.case_id AS p_case,
        p.numero_processo AS p_cnj,
        pub.id AS pub_id, pub.status_prazo, pub.case_id AS pub_case,
        cs.case_number, cs.title
    FROM prazos_processuais p
    JOIN case_publicacoes pub ON (
        pub.status_prazo IN ('descartado','confirmado')
        AND (
            pub.case_id = p.case_id
            OR REPLACE(REPLACE(REPLACE((SELECT case_number FROM cases WHERE id = pub.case_id),'-',''),'.',''),'/','') =
               REPLACE(REPLACE(REPLACE(p.numero_processo,'-',''),'.',''),'/','')
        )
        AND pub.data_prazo_fim = p.prazo_fatal
    )
    LEFT JOIN cases cs ON cs.id = COALESCE(p.case_id, pub.case_id)
    WHERE p.concluido = 0
    GROUP BY p.id
    ORDER BY p.prazo_fatal
";
$rows = $pdo->query($sql)->fetchAll();
echo count($rows) . " prazo(s) abertos com intimacao ja descartada/confirmada:\n\n";

foreach ($rows as $r) {
    printf("prazo #%-4d (%s) %s | intimacao #%d [%s] | caso: %s\n",
        $r['prazo_id'], $r['prazo_fatal'], mb_substr($r['descricao_acao'], 0, 35),
        $r['pub_id'], $r['status_prazo'], mb_substr($r['title'] ?? '?', 0, 30));
}

if ($modo === 'executar' && !empty($rows)) {
    echo "\nAPLICANDO...\n";
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("UPDATE prazos_processuais SET concluido=1, concluido_em=NOW() WHERE id=?");
        foreach ($rows as $r) {
            $stmt->execute(array($r['prazo_id']));
        }
        $pdo->commit();
        try { audit_log('PRAZO_FECHADO_RECONCILIA', 'prazo', null, count($rows) . ' prazos fechados (intimacao ja resolvida)'); } catch (Exception $e) {}
        echo "OK: " . count($rows) . " prazos fechados.\n";
    } catch (Exception $e) {
        $pdo->rollBack();
        echo "ERRO: " . $e->getMessage() . "\n";
    }
}
