<?php
/**
 * Diag rapido: busca conversas zapi por nome_contato OU telefone parcial.
 * Read-only. Pra investigar o caso da Renata pos-merge.
 *
 * Uso: ?key=XXX&busca=Renata
 *      ?key=XXX&busca=34661457631
 *      ?key=XXX&busca=5534661457631
 */
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';

if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }

header('Content-Type: text/plain; charset=utf-8');

$busca = trim($_GET['busca'] ?? '');
if (!$busca) { echo "Use ?busca=TERMO (nome ou telefone parcial)\n"; exit; }

$pdo = db();

$like = '%' . $busca . '%';
$stmt = $pdo->prepare(
    "SELECT co.id, co.telefone, co.nome_contato, co.client_id, co.lead_id,
            co.instancia_id, co.status, co.ultima_msg_em,
            cl.name AS client_name, cl.phone AS client_phone, cl.is_internacional AS client_intl
     FROM zapi_conversas co
     LEFT JOIN clients cl ON cl.id = co.client_id
     WHERE co.telefone LIKE ? OR co.nome_contato LIKE ? OR cl.name LIKE ?
     ORDER BY co.ultima_msg_em DESC
     LIMIT 30"
);
$stmt->execute(array($like, $like, $like));
$rows = $stmt->fetchAll();

echo "=== BUSCA: '$busca' ===\n";
echo "Encontradas: " . count($rows) . "\n\n";
foreach ($rows as $r) {
    echo "conv#" . $r['id']
       . " | tel=" . $r['telefone']
       . " | nome_contato=" . ($r['nome_contato'] ?: '—')
       . " | client_id=" . ($r['client_id'] ?: 'NULL')
       . " (" . ($r['client_name'] ?: 'sem nome') . ", phone_cad=" . ($r['client_phone'] ?: '—')
       . ", intl=" . ($r['client_intl'] ?? 0) . ")"
       . " | inst=" . $r['instancia_id']
       . " | status=" . $r['status']
       . " | ult=" . $r['ultima_msg_em']
       . "\n";

    // Conta mensagens
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM zapi_mensagens WHERE conversa_id = ?");
    $cnt->execute(array($r['id']));
    $n = (int)$cnt->fetchColumn();
    echo "   msgs nessa conv: $n\n";
}
