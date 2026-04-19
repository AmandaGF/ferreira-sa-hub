<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
ini_set('display_errors', '1');
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "=== MIGRACAO: Variacoes de Aniversario ===\n\n";

$variacoes = array(
    array('🎂 Aniversário — Clássico',
        "Feliz aniversário, {{nome}}! 🎂🎉\n\nTodos do escritório Ferreira & Sá Advocacia desejam um dia cheio de alegria e um ano repleto de conquistas.\n\nCom carinho,\nEquipe Ferreira & Sá"),

    array('🎂 Aniversário — Caloroso',
        "{{nome}}, feliz aniversário! 🥳\n\nQue este novo ano traga saúde, paz e muitas realizações pessoais e profissionais. É sempre um prazer tê-lo(a) como cliente.\n\nUm abraço da equipe Ferreira & Sá Advocacia!"),

    array('🎂 Aniversário — Reflexivo',
        "Oi, {{nome}}! 💐\n\nPassando rapidamente para desejar um feliz aniversário! Que neste novo ciclo você seja cercado(a) de amor, boas notícias e serenidade.\n\nParabéns de todos nós do escritório Ferreira & Sá. 🎂"),

    array('🎂 Aniversário — Elegante',
        "Prezado(a) {{nome}},\n\nEm nome do escritório Ferreira & Sá Advocacia, desejamos sinceros parabéns pelo seu aniversário. Que este novo ano seja marcado por saúde, tranquilidade e prosperidade. 🎉\n\nAtenciosamente,\nDra. Amanda Guedes Ferreira e equipe"),

    array('🎂 Aniversário — Próximo',
        "Feliz aniversário, {{nome}}! 🎊\n\nEsperamos que o seu dia seja maravilhoso ao lado de quem você ama. Que venham muitas alegrias, saúde e conquistas neste novo ciclo!\n\nUm forte abraço,\nFerreira & Sá Advocacia 💛"),
);

// Inserir só os que ainda não existem (pelo nome)
$addCount = 0;
foreach ($variacoes as $v) {
    try {
        $chk = $pdo->prepare("SELECT id FROM zapi_templates WHERE nome = ?");
        $chk->execute(array($v[0]));
        if ($chk->fetchColumn()) { echo "[SKIP] {$v[0]} (ja existe)\n"; continue; }
        $pdo->prepare("INSERT INTO zapi_templates (nome, conteudo, canal, categoria, ativo) VALUES (?,?,?,?,1)")
            ->execute(array($v[0], $v[1], '24', 'aniversario'));
        $addCount++;
        echo "[OK] {$v[0]}\n";
    } catch (Exception $e) { echo "[ERRO] {$v[0]}: " . $e->getMessage() . "\n"; }
}
echo "\nAdicionados: {$addCount}\n";

// Remover o template antigo genérico que virou redundante
try {
    $pdo->exec("UPDATE zapi_templates SET nome = '🎂 Aniversário — Clássico' WHERE nome = '🎂 Aniversário Cliente'");
    echo "[OK] Template antigo renomeado para Classico (se existir)\n";
} catch (Exception $e) {}

echo "\n=== CONCLUIDO ===\n";
