<?php
/**
 * Migração — CRM Comercial + cobrança de leads sem resposta.
 *   curl -s "https://ferreiraesa.com.br/conecta/migrar_crm_comercial.php?key=fsa-hub-deploy-2026"
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Migração CRM Comercial ===\n\n";

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS comercial_lead_obs (
        conversa_id INT NOT NULL PRIMARY KEY,
        lead_id INT NULL,
        observacao TEXT NULL,
        proximo_followup DATE NULL,
        atualizado_por INT NULL,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "✓ comercial_lead_obs\n";
} catch (Exception $e) { echo "⚠️ comercial_lead_obs: " . $e->getMessage() . "\n"; }

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS comercial_cobranca (
        conversa_id INT NOT NULL PRIMARY KEY,
        ultima_msg_id INT NOT NULL,
        responsavel_id INT NULL,
        alertado_em DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "✓ comercial_cobranca\n";
} catch (Exception $e) { echo "⚠️ comercial_cobranca: " . $e->getMessage() . "\n"; }

// Configs default (não sobrescreve se já existirem)
$defaults = array(
    'comercial_cobranca_ativo' => '0',   // começa DESLIGADO
    'comercial_grupo_id'       => '',
    'comercial_cobranca_canal' => '21',
    'comercial_cobranca_min'   => '5',
);
$ins = $pdo->prepare("INSERT IGNORE INTO configuracoes (chave, valor) VALUES (?, ?)");
foreach ($defaults as $k => $v) {
    $ins->execute(array($k, $v));
    echo "✓ config $k = '$v' (se ainda não existia)\n";
}

echo "\n=== FIM ===\n";
echo "Lembre de agendar o cron (a cada 5 min):\n";
echo "  */5 * * * * php /home/ferre315/public_html/conecta/cron/comercial_cobranca.php\n";
