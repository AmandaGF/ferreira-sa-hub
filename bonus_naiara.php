<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

// Self-heal (mesmo do painel, pra caso ainda nao rodou)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS dopamina_bonus (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        pontos DECIMAL(6,1) NOT NULL DEFAULT 0,
        motivo VARCHAR(300) NULL,
        data_ref DATE NOT NULL,
        parabens_titulo VARCHAR(200) NULL,
        parabens_texto TEXT NULL,
        parabens_visto_em DATETIME NULL,
        created_by INT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX (user_id, data_ref),
        INDEX (user_id, parabens_visto_em)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "✓ Tabela dopamina_bonus pronta\n";
} catch (Exception $e) { echo "erro tabela: " . $e->getMessage() . "\n"; }

// Data de amanha — quando Naiara vai abrir a pagina de novo
$amanha = date('Y-m-d', strtotime('tomorrow'));

// Ja existe algum bonus com esse motivo pra ela? Nao insere duplicado
$stCheck = $pdo->prepare("SELECT id FROM dopamina_bonus WHERE user_id=8 AND motivo LIKE '%Myllena%' LIMIT 1");
$stCheck->execute();
if ($stCheck->fetch()) {
    echo "\n⚠ Bonus da Myllena ja existe pra Naiara — nao inserindo duplicado.\n";
    exit;
}

$titulo = '🏆 Parabéns, Naiara! Você é a estrela do escritório!';
$texto  = "A gente sabe o QUANTO de força de vontade e paciência foi preciso pra conseguir a Certidão de Nascimento da Myllena. Isso desbloqueia um monte de coisa pra família dela — e é o tipo de entrega que muita gente teria desistido no meio.\n\nObrigada de coração pelo cuidado, pela persistência e pelo carinho com cada detalhe. Você é FODA. 💛\n\nEquipe Ferreira & Sá te aplaudindo aqui.";
$motivo = 'Certidão de Nascimento da Myllena — conquistada após dias de insistência';

$pdo->prepare("INSERT INTO dopamina_bonus (user_id, pontos, motivo, data_ref, parabens_titulo, parabens_texto, created_by)
               VALUES (?,?,?,?,?,?,?)")
    ->execute(array(8, 1500, $motivo, $amanha, $titulo, $texto, 1));

$bonusId = (int)$pdo->lastInsertId();
audit_log('dopamina_bonus_dado', 'users', 8, "1500 pontos — {$motivo}");

echo "\n✓ Bonus registrado (id=$bonusId):\n";
echo "  Para:     Naiara Gama Dourado (user #8)\n";
echo "  Pontos:   +1500\n";
echo "  Motivo:   $motivo\n";
echo "  Data ref: $amanha (amanha, pra soma-los no dia dela)\n";
echo "  Modal:    aparece na proxima abertura do painel dela\n";
