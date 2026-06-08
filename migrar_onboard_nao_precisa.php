<?php
/**
 * Migracao: adiciona pipeline_leads.onboard_nao_precisa
 *
 * Amanda 08/06/2026: distinguir "onboarding ainda nao realizado" (estado normal)
 * vs "cliente nao precisa de onboarding" (decisao consciente da equipe).
 *
 * Idempotente: skip se coluna ja existe.
 *
 * Uso: curl -s "https://ferreiraesa.com.br/conecta/migrar_onboard_nao_precisa.php?key=fsa-hub-deploy-2026"
 */

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';

if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') {
    http_response_code(403);
    exit('forbidden');
}

header('Content-Type: text/plain; charset=utf-8');

$pdo = db();

echo "=== Migracao: pipeline_leads.onboard_nao_precisa ===\n\n";

try {
    $r = $pdo->query("SHOW COLUMNS FROM pipeline_leads LIKE 'onboard_nao_precisa'")->fetch();
    if ($r) {
        echo "[SKIP] coluna ja existe (tipo: $r[Type])\n";
    } else {
        $pdo->exec("ALTER TABLE pipeline_leads ADD COLUMN onboard_nao_precisa TINYINT(1) NOT NULL DEFAULT 0 AFTER onboard_realizado");
        echo "[OK] coluna criada (TINYINT(1) NOT NULL DEFAULT 0)\n";
    }
    echo "\n=== MIGRACAO CONCLUIDA ===\n";
} catch (Exception $e) {
    echo "[ERRO] " . $e->getMessage() . "\n";
}
