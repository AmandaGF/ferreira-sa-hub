<?php
/**
 * Cria a tabela zapi_conversa_alias usada pelo fix critico de mesclar
 * conversas duplicadas (11/05/2026). Garante que a tabela exista antes
 * de qualquer webhook tentar usa-la.
 *
 * Uso: curl https://ferreiraesa.com.br/conecta/migrar_whatsapp_alias.php?key=fsa-hub-deploy-2026
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Forbidden.'); }
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/core/database.php';
$pdo = db();

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS zapi_conversa_alias (
        alias_telefone VARCHAR(60) NOT NULL PRIMARY KEY,
        conversa_id INT NOT NULL,
        criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY idx_conv (conversa_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "OK: tabela zapi_conversa_alias garantida.\n";

    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM zapi_conversa_alias")->fetchColumn();
    echo "Aliases ja registrados: {$cnt}\n";
} catch (Exception $e) {
    echo "ERRO: " . $e->getMessage() . "\n";
}
