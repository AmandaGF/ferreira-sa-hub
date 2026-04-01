<?php
/**
 * Sincronizar cobranças do Asaas para o cache local
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
if (!has_role('admin','gestao','comercial')) { redirect(url('modules/dashboard/')); }

require_once __DIR__ . '/../../core/asaas_helper.php';
$pdo = db();

// Buscar todos os clientes vinculados ao Asaas
$clientes = $pdo->query("SELECT id, name, asaas_customer_id FROM clients WHERE asaas_customer_id IS NOT NULL AND asaas_customer_id != ''")->fetchAll();

$totalSynced = 0;
$erros = 0;
foreach ($clientes as $c) {
    $result = sync_cobrancas_cliente($c['id'], $c['asaas_customer_id']);
    if (isset($result['error'])) { $erros++; }
    else { $totalSynced += $result['synced']; }
}

flash_set('success', "Sincronização concluída! $totalSynced cobranças atualizadas de " . count($clientes) . " clientes." . ($erros > 0 ? " ($erros erros)" : ''));
redirect(module_url('financeiro'));
