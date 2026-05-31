<?php
/**
 * Diag: confirmar o estado real dos achados da rodada 3 da Nilce
 *  - Item 3b: busca "Gisele" gera contador != linhas?
 *  - Item 2: textarea de andamento tem os handlers corretos?
 *  - Item 5: JS de tooltip esta no operacional/index.php?
 *  - Sticky bar: position:sticky com z-index correto?
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function h($s) { echo "\n========== $s ==========\n"; }

h('1) BUSCA GISELE — qual a query que conta vs lista?');
$st = $pdo->query("
    SELECT id, title, status, kanban_oculto, client_id, is_parceria, kanban_prev
    FROM cases
    WHERE title LIKE '%gisele%'
    ORDER BY id
");
foreach ($st->fetchAll() as $c) {
    printf("  #%-5d status=%-15s kanban_oculto=%-1d is_parceria=%-1d kanban_prev=%-1d title=%s\n",
        $c['id'], $c['status'], $c['kanban_oculto'], (int)$c['is_parceria'], (int)$c['kanban_prev'],
        mb_substr($c['title'], 0, 60));
}

echo "\n";
$st = $pdo->query("
    SELECT cs.id, cs.title, cs.status, cs.kanban_oculto, cl.name AS cliente, cs.is_parceria, cs.kanban_prev
    FROM cases cs
    LEFT JOIN clients cl ON cl.id = cs.client_id
    WHERE cl.name LIKE '%gisele%' OR cs.title LIKE '%gisele%'
    ORDER BY cs.id
");
echo "Match por title OU client.name:\n";
foreach ($st->fetchAll() as $c) {
    printf("  #%-5d status=%-15s kanban_oculto=%-1d is_parceria=%-1d kanban_prev=%-1d cliente=%s | %s\n",
        $c['id'], $c['status'], $c['kanban_oculto'], (int)$c['is_parceria'], (int)$c['kanban_prev'],
        mb_substr($c['cliente'] ?? '?', 0, 30), mb_substr($c['title'], 0, 40));
}

h('2) JS DE TOOLTIP TRUNCADO esta no arquivo deployado?');
$arq = __DIR__ . '/modules/operacional/index.php';
echo "Arquivo: $arq\n";
echo "mtime: " . date('Y-m-d H:i:s', filemtime($arq)) . "\n";
$cont = file_get_contents($arq);
$temTooltipJs = strpos($cont, 'checarTruncamento') !== false;
$temDataTruncated = strpos($cont, 'data-truncated') !== false;
echo "  function checarTruncamento: " . ($temTooltipJs ? 'SIM' : 'NAO') . "\n";
echo "  CSS data-truncated: " . ($temDataTruncated ? 'SIM' : 'NAO') . "\n";

h('3) JS DOS MODELOS RAPIDOS — tem click + mousedown?');
$arq2 = __DIR__ . '/modules/operacional/caso_ver.php';
$cont2 = file_get_contents($arq2);
$temMouseDown = strpos($cont2, "addEventListener('mousedown', function(ev){ ev.preventDefault(); aplicar(i);") !== false;
$temClick = strpos($cont2, "addEventListener('click', function(ev){ ev.preventDefault(); aplicar(i);") !== false;
$temMouseOverSemRender = strpos($cont2, 'background = (j === i) ?') !== false;
echo "  mousedown handler: " . ($temMouseDown ? 'SIM' : 'NAO') . "\n";
echo "  click handler (fallback): " . ($temClick ? 'SIM' : 'NAO') . "\n";
echo "  mouseover sem re-render: " . ($temMouseOverSemRender ? 'SIM (NOVO)' : 'NAO (BUG ANTIGO)') . "\n";

h('4) STICKY BAR — z-index e CSS estao corretos?');
$temStickyZ30 = strpos($cont2, 'z-index:30') !== false;
$temIsStuck = strpos($cont2, 'is-stuck') !== false;
echo "  z-index:30 no cv-toolbar-sticky: " . ($temStickyZ30 ? 'SIM' : 'NAO') . "\n";
echo "  classe is-stuck no CSS: " . ($temIsStuck ? 'SIM' : 'NAO') . "\n";

h('5) CACHE DE PWA — qual o cache atual no sw.js?');
$sw = @file_get_contents(__DIR__ . '/sw.js');
if ($sw && preg_match('/CACHE_NAME[^=]*=\s*[\'"]([^\'"]+)/', $sw, $m)) {
    echo "  CACHE_NAME: " . $m[1] . "\n";
}

echo "\nFIM.\n";
