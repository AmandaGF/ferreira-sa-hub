<?php
/**
 * Migration — Treinamento obrigatório de audiência remota.
 *
 * Cria tabela treinamento_audiencia_aceites para rastrear:
 *   - Link único enviado pra cada audiência (token de 48 chars hex)
 *   - Aceite do cliente (nome, CPF, IP, user-agent, hash dos checkboxes)
 *   - Certificado gerado (URL do PDF no Drive quando Onda 2)
 *   - Envios/lembretes
 *
 * Também cria a chave de configuração `treinamento_audiencia_ativo`
 * (killswitch) default '0' — feature entra desligada até Amanda revisar
 * o texto do termo.
 *
 * Uso: GET ?key=fsa-hub-deploy-2026
 */
require_once __DIR__ . '/core/database.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') {
    http_response_code(403);
    exit('forbidden');
}
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Migration treinamento_audiencia_aceites ===\n\n";

try {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS treinamento_audiencia_aceites (
            id INT AUTO_INCREMENT PRIMARY KEY,
            token VARCHAR(64) NOT NULL,

            case_id INT NOT NULL,
            client_id INT NULL,
            agenda_evento_id INT NULL,
            audiencia_data_hora DATETIME NULL,
            audiencia_titulo VARCHAR(255) NULL,

            -- Aceite (preenchidos quando cliente conclui)
            aceite_em DATETIME NULL,
            aceite_nome VARCHAR(255) NULL,
            aceite_cpf VARCHAR(20) NULL,
            aceite_ip VARCHAR(64) NULL,
            aceite_user_agent VARCHAR(500) NULL,
            aceite_checks_json TEXT NULL,
            aceite_checks_hash VARCHAR(64) NULL,
            aceite_termo_versao VARCHAR(20) NULL,

            -- Certificado (Onda 2)
            certificado_url VARCHAR(500) NULL,
            certificado_gerado_em DATETIME NULL,

            -- Metadados
            criado_por INT NOT NULL,
            criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
            enviado_wa_em DATETIME NULL,
            enviado_wa_canal VARCHAR(4) NULL,
            lembretes_enviados INT DEFAULT 0,
            ultimo_lembrete_em DATETIME NULL,

            UNIQUE KEY uk_token (token),
            KEY idx_case (case_id),
            KEY idx_agenda (agenda_evento_id),
            KEY idx_aceite (aceite_em)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    echo "✓ Tabela treinamento_audiencia_aceites criada/existente\n";
} catch (Exception $e) {
    echo "✗ ERRO: " . $e->getMessage() . "\n";
    exit;
}

// Killswitch em configuracoes — default desligado até Amanda aprovar termo
try {
    $stmt = $pdo->prepare(
        "INSERT INTO configuracoes (chave, valor)
         VALUES ('treinamento_audiencia_ativo', '0')
         ON DUPLICATE KEY UPDATE chave = chave"
    );
    $stmt->execute();
    echo "✓ Killswitch treinamento_audiencia_ativo (default '0')\n";
} catch (Exception $e) {
    echo "aviso killswitch: " . $e->getMessage() . "\n";
}

// Versão do termo — pra rastrear qual texto o cliente assinou. Se Amanda
// mudar o termo, incremento essa versão e aceites antigos preservam
// referência ao texto original.
try {
    $stmt = $pdo->prepare(
        "INSERT INTO configuracoes (chave, valor)
         VALUES ('treinamento_audiencia_termo_versao', 'minuta-v1-2026-07-02')
         ON DUPLICATE KEY UPDATE chave = chave"
    );
    $stmt->execute();
    echo "✓ Versão do termo registrada (minuta-v1-2026-07-02)\n";
} catch (Exception $e) {
    echo "aviso versao: " . $e->getMessage() . "\n";
}

echo "\n=== OK ===\n";
echo "Feature ficara DESLIGADA (killswitch=0) ate Amanda revisar\n";
echo "a minuta do termo e ativar via /admin/ ou direto no banco.\n";
