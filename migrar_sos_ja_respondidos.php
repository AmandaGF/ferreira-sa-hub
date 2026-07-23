<?php
/**
 * One-shot 23/07/2026 — resolve SOS do Alfredo que ja foram atendidos.
 *
 * Contexto: ate hoje o SOS (alfredo_sugestoes.eh_sos=1) so saia do banner
 * quando alguem aprovava/descartava a SUGESTAO do Alfredo. Se a equipe
 * respondia o cliente normalmente (ex: Amanda ja tinha respondido o "Luiz
 * Eduardo"), o SOS continuava pendente e gritando no banner pra sempre.
 *
 * O fix novo (wa_resolver_sos_pendente) resolve em envios daqui pra frente.
 * Esta migracao limpa o passivo: resolve todo SOS pendente cuja conversa ja
 * teve uma resposta HUMANA (enviado_por_id NOT NULL) DEPOIS que o SOS subiu.
 *
 * Uso: curl "https://ferreiraesa.com.br/conecta/migrar_sos_ja_respondidos.php?key=fsa-hub-deploy-2026"
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Forbidden.'); }
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/core/database.php';
$pdo = db();

// Condicao: SOS pendente + existe msg enviada por humano apos o SOS na mesma conversa.
$cond = "s.eh_sos = 1 AND s.sos_resolvido_em IS NULL
         AND EXISTS (
             SELECT 1 FROM zapi_mensagens m
             WHERE m.conversa_id = s.conversa_id
               AND m.direcao = 'enviada'
               AND m.enviado_por_id IS NOT NULL
               AND m.created_at > s.created_at
         )";

// Preview do que sera resolvido
echo "== SOS que serao resolvidos (ja respondidos por humano) ==\n";
try {
    $q = $pdo->query("SELECT s.id, s.conversa_id, COALESCE(cl.name, co.nome_contato, CONCAT('conv#', s.conversa_id)) AS quem, s.created_at
                      FROM alfredo_sugestoes s
                      JOIN zapi_conversas co ON co.id = s.conversa_id
                      LEFT JOIN clients cl ON cl.id = co.client_id
                      WHERE {$cond}
                      ORDER BY s.id DESC");
    $n = 0;
    foreach ($q as $r) { $n++; echo "  sug#{$r['id']} conv#{$r['conversa_id']} — {$r['quem']} (SOS em {$r['created_at']})\n"; }
    echo "Total: {$n}\n\n";
} catch (Exception $e) { echo "ERRO no preview: " . $e->getMessage() . "\n"; }

// Resolve
try {
    $stmt = $pdo->prepare("UPDATE alfredo_sugestoes s
                           SET s.sos_resolvido_em = NOW(), s.sos_resolvido_por = 0
                           WHERE {$cond}");
    $stmt->execute();
    echo "✓ Resolvidos: " . $stmt->rowCount() . " SOS.\n";
} catch (Exception $e) {
    echo "ERRO ao resolver: " . $e->getMessage() . "\n";
}

$rest = (int)$pdo->query("SELECT COUNT(*) FROM alfredo_sugestoes WHERE eh_sos = 1 AND sos_resolvido_em IS NULL")->fetchColumn();
echo "SOS ainda pendentes (nao respondidos): {$rest}\n";
