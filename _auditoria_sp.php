<?php
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Auditoria cases SP — quais tem CNJ suspeito? ===\n\n";
echo "Usa datajud_erro do banco. Cases com erro 'nao encontrado' apos varias\n";
echo "tentativas do cron sao suspeitos de CNJ digitado errado.\n\n";

$stmt = $pdo->query("
    SELECT cs.id, cs.title, cs.case_number, cs.status, cs.datajud_sincronizado, cs.datajud_erro,
           cs.datajud_ultima_sync, c.name AS cliente
    FROM cases cs LEFT JOIN clients c ON c.id = cs.client_id
    WHERE cs.comarca_uf = 'SP'
      AND cs.status IN ('em_andamento','ativo','arquivado')
    ORDER BY cs.datajud_sincronizado DESC, cs.status, cs.id
");

$sync = array('ok' => array(), 'nao_encontrado' => array(), 'outros_erros' => array(), 'nunca_tentou' => array());
foreach ($stmt as $r) {
    if ($r['datajud_sincronizado'] == 1) $bucket = 'ok';
    elseif (!empty($r['datajud_erro']) && stripos($r['datajud_erro'], 'nao encontrado') !== false) $bucket = 'nao_encontrado';
    elseif (!empty($r['datajud_erro'])) $bucket = 'outros_erros';
    else $bucket = 'nunca_tentou';
    $sync[$bucket][] = $r;
}

foreach ($sync as $tipo => $lista) {
    $titulos = array(
        'ok' => "✓ SINCRONIZADOS (existe no DataJud — CNJ certo)",
        'nao_encontrado' => "⚠ NAO ENCONTRADO no DataJud (SUSPEITO de digitacao errada)",
        'outros_erros' => "✗ ERRO tecnico (API caiu, timeout etc)",
        'nunca_tentou' => "○ NUNCA sincronizado (cron ainda nao tentou)",
    );
    echo "\n═══ " . $titulos[$tipo] . " (" . count($lista) . ") ═══\n";
    foreach ($lista as $r) {
        printf("#%-5d | %s | %s | cliente=%s\n",
            $r['id'], $r['case_number'], $r['status'],
            mb_substr($r['cliente'] ?? '?', 0, 30));
        printf("       %s\n", mb_substr($r['title'], 0, 70));
    }
}
