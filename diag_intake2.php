<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('x'); }
header('Content-Type: text/plain; charset=utf-8');

// Carrega o config.php do convivencia_form (ele define DB_* e cria uma função pdo())
// Vamos capturar só a função e usar diretamente.
$confPath = dirname(__DIR__) . '/convivencia_form/config.php';
require_once $confPath;

// Tenta usar a função pdo() que o config.php expõe
try {
    $p = pdo();
    $q = $p->query("SELECT id, protocol, client_name, client_phone, client_email, relationship_role, created_at FROM intake_visitas WHERE created_at > '2026-04-01' ORDER BY id DESC");
    $res = $q->fetchAll();
    echo "=== Entradas em intake_visitas APOS 01/04/2026 (perdidas no Hub) ===\n";
    echo "Total: " . count($res) . "\n\n";
    foreach ($res as $r) {
        echo "#{$r['id']} [{$r['protocol']}] {$r['created_at']}\n";
        echo "  {$r['client_name']} | tel={$r['client_phone']} | email={$r['client_email']} | papel={$r['relationship_role']}\n\n";
    }
} catch (Exception $e) {
    echo "ERRO: " . $e->getMessage() . "\n";
}
