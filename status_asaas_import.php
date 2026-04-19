<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Status da Importação Asaas ===\n\n";

$totalCob = $pdo->query("SELECT COUNT(*) FROM asaas_cobrancas")->fetchColumn();
$vinc     = $pdo->query("SELECT COUNT(*) FROM asaas_cobrancas WHERE client_id IS NOT NULL")->fetchColumn();
$semLink  = $pdo->query("SELECT COUNT(*) FROM asaas_cobrancas WHERE client_id IS NULL")->fetchColumn();

echo "Total de cobranças importadas: {$totalCob}\n";
echo "  Vinculadas a um cliente: {$vinc}\n";
echo "  Sem cliente vinculado:   {$semLink}\n\n";

echo "--- Por status ---\n";
$s = $pdo->query("SELECT status, COUNT(*) AS n, SUM(valor) AS total FROM asaas_cobrancas GROUP BY status ORDER BY n DESC");
foreach ($s->fetchAll() as $r) {
    echo sprintf("  %-25s  %5d  R$ %10s\n",
        $r['status'],
        $r['n'],
        number_format((float)$r['total'], 2, ',', '.')
    );
}

echo "\n--- Por forma de pagamento ---\n";
$f = $pdo->query("SELECT COALESCE(forma_pagamento,'(nenhuma)') as fp, COUNT(*) AS n FROM asaas_cobrancas GROUP BY forma_pagamento ORDER BY n DESC");
foreach ($f->fetchAll() as $r) {
    echo sprintf("  %-15s  %d\n", $r['fp'], $r['n']);
}

echo "\n--- Clientes ---\n";
$tc = $pdo->query("SELECT COUNT(*) FROM clients")->fetchColumn();
$tcs = $pdo->query("SELECT COUNT(*) FROM clients WHERE asaas_customer_id IS NOT NULL")->fetchColumn();
echo "Total clientes no Hub: {$tc}\n";
echo "Clientes vinculados Asaas: {$tcs}\n";

echo "\n--- Últimas 5 cobranças importadas ---\n";
$u = $pdo->query("SELECT c.asaas_payment_id, c.status, c.valor, c.vencimento, COALESCE(cl.name,'(sem cliente)') as cliente
                  FROM asaas_cobrancas c LEFT JOIN clients cl ON cl.id = c.client_id
                  ORDER BY c.id DESC LIMIT 5");
foreach ($u->fetchAll() as $r) {
    echo sprintf("  %s  %-10s  R$ %8s  venc=%s  %s\n",
        $r['asaas_payment_id'],
        $r['status'],
        number_format((float)$r['valor'], 2, ',', '.'),
        $r['vencimento'],
        $r['cliente']
    );
}
