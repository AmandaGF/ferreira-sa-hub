<?php
/**
 * Migração: Módulo de Publicações Processuais
 * Conecta — Ferreira & Sá
 * Executar uma vez via navegador (admin only)
 */
require_once __DIR__ . '/core/middleware.php';
require_login();
if (!has_min_role('admin')) { die('Acesso negado.'); }

$pdo = db();
$erros = array();
$ok = array();

// Tabela principal de publicações
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS case_publicacoes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            case_id INT UNSIGNED NOT NULL,
            data_disponibilizacao DATE NOT NULL COMMENT 'Marco legal — início do prazo (art. 224 CPC)',
            data_publicacao DATE NULL COMMENT 'Data da edição do diário',
            conteudo TEXT NOT NULL,
            caderno VARCHAR(100) NULL,
            tribunal VARCHAR(50) NULL,
            tipo_publicacao ENUM('intimacao','citacao','despacho','decisao','sentenca','acordao','edital','outro') NOT NULL DEFAULT 'intimacao',
            fonte ENUM('manual','pol','escavador','outro') NOT NULL DEFAULT 'manual',
            prazo_dias INT NULL COMMENT 'Prazo em dias úteis sugerido',
            data_prazo_fim DATE NULL COMMENT 'Data calculada do vencimento',
            status_prazo ENUM('pendente','confirmado','descartado') NOT NULL DEFAULT 'pendente',
            task_id INT UNSIGNED NULL COMMENT 'ID da tarefa criada automaticamente',
            agenda_id INT UNSIGNED NULL COMMENT 'ID do evento criado na agenda',
            visivel_cliente TINYINT(1) NOT NULL DEFAULT 0,
            criado_por INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_case_id (case_id),
            INDEX idx_data_disponibilizacao (data_disponibilizacao),
            INDEX idx_status_prazo (status_prazo),
            INDEX idx_fonte (fonte),
            CONSTRAINT fk_pub_case FOREIGN KEY (case_id) REFERENCES cases(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $ok[] = 'Tabela case_publicacoes criada.';
} catch (Exception $e) {
    $erros[] = 'case_publicacoes: ' . $e->getMessage();
}

// Índice de busca por fonte externa (para quando POL/Escavador integrarem)
try {
    $pdo->exec("
        ALTER TABLE case_publicacoes
        ADD COLUMN fonte_id VARCHAR(100) NULL COMMENT 'ID único da publicação no sistema de origem'
    ");
    $ok[] = 'Coluna fonte_id adicionada.';
} catch (Exception $e) {
    $msg = $e->getMessage();
    if (strpos($msg, 'Duplicate column') !== false) {
        $ok[] = 'Coluna fonte_id ja existe — OK.';
    } else {
        $erros[] = 'fonte_id: ' . $msg;
    }
}

try {
    $pdo->exec("CREATE UNIQUE INDEX idx_fonte_id ON case_publicacoes (fonte, fonte_id)");
    $ok[] = 'Indice idx_fonte_id criado.';
} catch (Exception $e) {
    $msg = $e->getMessage();
    if (strpos($msg, 'Duplicate') !== false || strpos($msg, 'exists') !== false) {
        $ok[] = 'Indice idx_fonte_id ja existe — OK.';
    } else {
        $erros[] = 'idx_fonte_id: ' . $msg;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<title>Migration — Publicacoes</title>
<style>
body { font-family: sans-serif; max-width: 700px; margin: 40px auto; padding: 20px; }
.ok { color: #059669; background: #ecfdf5; border: 1px solid #059669; border-radius: 6px; padding: 8px 12px; margin: 6px 0; }
.erro { color: #dc2626; background: #fef2f2; border: 1px solid #dc2626; border-radius: 6px; padding: 8px 12px; margin: 6px 0; }
h2 { color: #052228; }
</style>
</head>
<body>
<h2>Migration — Modulo de Publicacoes</h2>
<?php foreach ($ok as $msg): ?>
    <div class="ok"><?= e($msg) ?></div>
<?php endforeach; ?>
<?php foreach ($erros as $msg): ?>
    <div class="erro"><?= e($msg) ?></div>
<?php endforeach; ?>
<?php if (empty($erros)): ?>
    <div class="ok" style="margin-top:16px;font-weight:700;">Migration concluida com sucesso. Pode apagar este arquivo.</div>
<?php else: ?>
    <div class="erro" style="margin-top:16px;font-weight:700;">Verifique os erros acima antes de prosseguir.</div>
<?php endif; ?>
<p><a href="<?= url('modules/dashboard/') ?>">Voltar ao Dashboard</a></p>
</body>
</html>
