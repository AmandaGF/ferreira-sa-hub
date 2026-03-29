<?php
/**
 * Migração: tabela birthday_greetings + birthday_messages
 * Acesse: ferreiraesa.com.br/conecta/migrar_aniversarios.php?key=fsa-hub-deploy-2026
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

// 1. Tabela de registro de parabéns enviados
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `birthday_greetings` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `client_id` INT UNSIGNED NOT NULL,
        `year` SMALLINT NOT NULL,
        `sent_by` INT UNSIGNED DEFAULT NULL,
        `sent_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `uk_client_year` (`client_id`, `year`),
        FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE CASCADE,
        FOREIGN KEY (`sent_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "Tabela 'birthday_greetings' OK\n";
} catch (Exception $e) { echo "birthday_greetings: " . $e->getMessage() . "\n"; }

// 2. Tabela de mensagens de parabéns por mês
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `birthday_messages` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `month` TINYINT NOT NULL COMMENT '1-12',
        `title` VARCHAR(100) NOT NULL,
        `body` TEXT NOT NULL,
        `is_active` TINYINT(1) NOT NULL DEFAULT 1,
        `updated_by` INT UNSIGNED DEFAULT NULL,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY `uk_month` (`month`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "Tabela 'birthday_messages' OK\n";
} catch (Exception $e) { echo "birthday_messages: " . $e->getMessage() . "\n"; }

// 3. Seed mensagens padrão por mês
$count = (int)$pdo->query("SELECT COUNT(*) FROM birthday_messages")->fetchColumn();
if ($count === 0) {
    $msgs = array(
        1 => "Olá, {nome}! 🎂\n\nQue este novo ano de vida seja repleto de conquistas e muita saúde! Janeiro é mês de renovação e novos começos.\n\nFeliz aniversário! 🎉\n\nEquipe Ferreira & Sá Advocacia",
        2 => "Olá, {nome}! 🎂\n\nFevereiro chegou com uma data especial: o seu aniversário! Que este dia seja cheio de amor e alegria.\n\nFeliz aniversário! 🎉\n\nEquipe Ferreira & Sá Advocacia",
        3 => "Olá, {nome}! 🎂\n\nMarço traz o seu dia especial! Desejamos muita saúde, felicidade e realizações neste novo ciclo.\n\nFeliz aniversário! 🎉\n\nEquipe Ferreira & Sá Advocacia",
        4 => "Olá, {nome}! 🎂\n\nAbril tem um motivo especial para celebrar: você! Que este novo ano seja maravilhoso.\n\nFeliz aniversário! 🎉\n\nEquipe Ferreira & Sá Advocacia",
        5 => "Olá, {nome}! 🎂\n\nMaio é mês das mães e também do seu aniversário! Que seja um mês de muito amor e gratidão.\n\nFeliz aniversário! 🎉\n\nEquipe Ferreira & Sá Advocacia",
        6 => "Olá, {nome}! 🎂\n\nJunho chegou festivo e com o seu aniversário! Que sua vida continue sendo motivo de celebração.\n\nFeliz aniversário! 🎉\n\nEquipe Ferreira & Sá Advocacia",
        7 => "Olá, {nome}! 🎂\n\nJulho traz o seu dia especial! Desejamos um ano cheio de vitórias e momentos inesquecíveis.\n\nFeliz aniversário! 🎉\n\nEquipe Ferreira & Sá Advocacia",
        8 => "Olá, {nome}! 🎂\n\nAgosto é o mês do seu aniversário! Que este novo ciclo traga prosperidade e muita paz.\n\nFeliz aniversário! 🎉\n\nEquipe Ferreira & Sá Advocacia",
        9 => "Olá, {nome}! 🎂\n\nSetembro, mês da primavera e do seu aniversário! Que flores de alegria estejam sempre no seu caminho.\n\nFeliz aniversário! 🎉\n\nEquipe Ferreira & Sá Advocacia",
        10 => "Olá, {nome}! 🎂\n\nOutubro chegou com o seu dia especial! Desejamos um ano repleto de conquistas e saúde.\n\nFeliz aniversário! 🎉\n\nEquipe Ferreira & Sá Advocacia",
        11 => "Olá, {nome}! 🎂\n\nNovembro é mês de gratidão e do seu aniversário! Que você tenha muito a celebrar.\n\nFeliz aniversário! 🎉\n\nEquipe Ferreira & Sá Advocacia",
        12 => "Olá, {nome}! 🎂\n\nDezembro encerra o ano com chave de ouro: seu aniversário! Que o novo ciclo traga muitas bênçãos.\n\nFeliz aniversário! 🎉\n\nEquipe Ferreira & Sá Advocacia",
    );
    $stmt = $pdo->prepare("INSERT INTO birthday_messages (month, title, body) VALUES (?, ?, ?)");
    foreach ($msgs as $m => $body) {
        $meses = array('','Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro');
        $stmt->execute(array($m, 'Parabéns - ' . $meses[$m], $body));
    }
    echo "12 mensagens de parabéns inseridas!\n";
} else {
    echo "Mensagens já existem ($count).\n";
}

echo "\nPronto!\n";
