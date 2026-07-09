<?php
/**
 * DIAG 09/07/2026 — investigar duplicidade "Audiência AIJ Dayana" vs
 * "Audiência (Conciliação) — corresp.: Carolina" no dia 09/07/2026 14h.
 *
 * Descartar via: chave ?key=fsa-hub-deploy-2026
 */
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';

if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }

header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== DIAG duplicidade 09/07/2026 14h ===\n\n";

$sql = "SELECT ae.id, ae.case_id, ae.client_id, ae.tipo, ae.modalidade, ae.titulo,
               ae.data_inicio, ae.data_fim, ae.status, ae.local, ae.meet_link,
               ae.responsavel_id, ae.criado_por, ae.criado_em, ae.updated_at,
               ae.origem_import, ae.pub_vinc_id, ae.sol_aud_id, ae.dia_todo,
               c.title AS case_title, c.case_number,
               cl.name AS client_name,
               ur.name AS resp_name, uc.name AS criado_por_name
        FROM agenda_eventos ae
        LEFT JOIN cases c ON c.id = ae.case_id
        LEFT JOIN clients cl ON cl.id = ae.client_id
        LEFT JOIN users ur ON ur.id = ae.responsavel_id
        LEFT JOIN users uc ON uc.id = ae.criado_por
        WHERE DATE(ae.data_inicio) = '2026-07-09'
          AND (
              ae.titulo LIKE '%DAYANA%' OR ae.titulo LIKE '%Dayana%'
              OR ae.titulo LIKE '%Carolina%dos%Santos%'
              OR ae.titulo LIKE '%corresp%Carolina%'
          )
        ORDER BY ae.data_inicio, ae.id";
$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

if (!$rows) {
    echo "Nenhum evento encontrado com esses padroes em 2026-07-09.\n";
    echo "Talvez tenham sido criados com outros nomes. Buscando TUDO no dia 09/07 as 14h...\n\n";
    $rows = $pdo->query(
        "SELECT ae.id, ae.case_id, ae.tipo, ae.modalidade, ae.titulo, ae.data_inicio, ae.status,
                ae.origem_import, ae.pub_vinc_id, ae.sol_aud_id, ae.criado_em,
                c.title AS case_title
         FROM agenda_eventos ae
         LEFT JOIN cases c ON c.id = ae.case_id
         WHERE DATE(ae.data_inicio) = '2026-07-09'
           AND HOUR(ae.data_inicio) BETWEEN 13 AND 15
         ORDER BY ae.data_inicio, ae.id"
    )->fetchAll(PDO::FETCH_ASSOC);
}

foreach ($rows as $r) {
    echo str_repeat('─', 78) . "\n";
    echo "id=" . $r['id'] . "  case_id=" . ($r['case_id'] ?? 'NULL') . "  tipo=" . $r['tipo']
       . "  modalidade=" . ($r['modalidade'] ?? '-') . "  status=" . $r['status'] . "\n";
    echo "TITULO: " . $r['titulo'] . "\n";
    if (!empty($r['case_title'])) echo "CASE:   " . $r['case_title'] . " (num=" . ($r['case_number'] ?? '-') . ")\n";
    if (!empty($r['client_name'])) echo "CLI:    " . $r['client_name'] . "\n";
    echo "QUANDO: " . $r['data_inicio'] . " → " . ($r['data_fim'] ?? '-')
       . "  dia_todo=" . ($r['dia_todo'] ?? '-') . "\n";
    if (!empty($r['local'])) echo "LOCAL:  " . $r['local'] . "\n";
    if (!empty($r['meet_link'])) echo "MEET:   " . $r['meet_link'] . "\n";
    echo "RESP:   " . ($r['resp_name'] ?? '-') . "\n";
    echo "CRIADO: " . ($r['criado_em'] ?? '-') . " por " . ($r['criado_por_name'] ?? '-')
       . " (id=" . ($r['criado_por'] ?? '-') . ")\n";
    echo "UPDATE: " . ($r['updated_at'] ?? '-') . "\n";
    echo "ORIGEM: import=" . ($r['origem_import'] ?? '-')
       . "  pub_vinc_id=" . ($r['pub_vinc_id'] ?? '-')
       . "  sol_aud_id=" . ($r['sol_aud_id'] ?? '-') . "\n";
}
echo str_repeat('─', 78) . "\n\n";

if (count($rows) < 2) exit;

// Se os 2 eventos existem no mesmo case_id, e mesmo horario → PROVAVEL duplicata.
$byCase = array();
foreach ($rows as $r) { $byCase[$r['case_id'] ?? 0][] = $r; }
echo "AGRUPADO POR CASE_ID:\n";
foreach ($byCase as $cid => $lst) {
    echo "  case_id=$cid  qtd=" . count($lst) . "\n";
}

echo "\n=== solicitacoes_audiencista relacionadas (se sol_aud_id preenchido) ===\n";
$solIds = array();
foreach ($rows as $r) { if (!empty($r['sol_aud_id'])) $solIds[] = (int)$r['sol_aud_id']; }
if ($solIds) {
    $ph = implode(',', array_fill(0, count($solIds), '?'));
    try {
        $st = $pdo->prepare("SELECT sa.*, u.name AS audiencista_nome
                             FROM solicitacoes_audiencia sa
                             LEFT JOIN users u ON u.id = sa.audiencista_id
                             WHERE sa.id IN ($ph)");
        $st->execute($solIds);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $sa) {
            print_r($sa);
        }
    } catch (Exception $e) { echo "  [erro tabela] " . $e->getMessage() . "\n"; }
} else {
    echo "  (nenhum sol_aud_id preenchido)\n";
}

echo "\n=== pubs vinculadas (se pub_vinc_id preenchido) ===\n";
$pubIds = array();
foreach ($rows as $r) { if (!empty($r['pub_vinc_id'])) $pubIds[] = (int)$r['pub_vinc_id']; }
if ($pubIds) {
    $ph = implode(',', array_fill(0, count($pubIds), '?'));
    try {
        $st = $pdo->prepare("SELECT id, case_id, tipo_prazo, prazo_ate, resumo, status, created_at
                             FROM publicacoes WHERE id IN ($ph)");
        $st->execute($pubIds);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) print_r($p);
    } catch (Exception $e) { echo "  [erro tabela] " . $e->getMessage() . "\n"; }
} else {
    echo "  (nenhum pub_vinc_id preenchido)\n";
}

echo "\n=== audit_log — evento agenda_eventos ===\n";
$evIds = array();
foreach ($rows as $r) $evIds[] = (int)$r['id'];
$ph = implode(',', array_fill(0, count($evIds), '?'));
try {
    $st = $pdo->prepare("SELECT id, user_id, acao, entidade, entidade_id, detalhes, created_at
                         FROM audit_log
                         WHERE entidade = 'agenda_evento' AND entidade_id IN ($ph)
                         ORDER BY id");
    $st->execute($evIds);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $al) {
        echo "  [$al[created_at]] user=$al[user_id] $al[acao] #$al[entidade_id]  $al[detalhes]\n";
    }
} catch (Exception $e) { echo "  [erro] " . $e->getMessage() . "\n"; }

echo "\n=== FIM ===\n";
