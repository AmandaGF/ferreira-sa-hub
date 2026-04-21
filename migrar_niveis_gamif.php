<?php
/**
 * Ajuste da curva de níveis de gamificação — Abril/2026
 *
 * Antes: 0 / 500 / 1500 / 3000 / 6000 / 10000
 * Depois: 0 / 150 / 500 / 1500 / 3500 / 7500
 *
 * Motivação: limiar anterior muito alto — nível 2 (500 pts) exigia 10 módulos
 * de treinamento pra sair de Estagiário. Curva nova mantém proporção exponencial
 * mas reduz a 1ª barreira e achata o teto.
 *
 * Uso: curl -s "https://ferreiraesa.com.br/conecta/migrar_niveis_gamif.php?key=fsa-hub-deploy-2026"
 */

if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') {
    http_response_code(403);
    exit('forbidden');
}

require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');

$pdo = db();

$niveis = array(
    array(1, 'Estagiário',      0,    '🎓'),
    array(2, 'Associado',       150,  '⭐'),
    array(3, 'Advogado Jr',     500,  '⚖️'),
    array(4, 'Advogado Pleno',  1500, '💼'),
    array(5, 'Advogado Sênior', 3500, '🏆'),
    array(6, 'Sócio',           7500, '👑'),
);

echo "--- Atualizando limiares dos níveis ---\n";
$stmt = $pdo->prepare("UPDATE gamificacao_niveis SET nome = ?, pontos_minimos = ?, badge_emoji = ? WHERE nivel_num = ?");
foreach ($niveis as $n) {
    $stmt->execute(array($n[1], $n[2], $n[3], $n[0]));
    echo "[OK] Nível {$n[0]}: {$n[1]} ({$n[2]} pts) {$n[3]}\n";
}

echo "\n--- Recalculando nível de cada usuário ---\n";
$tot = $pdo->query("SELECT user_id, pontos_total_comercial + pontos_total_operacional AS total, nivel_num FROM gamificacao_totais")->fetchAll();
$upd = $pdo->prepare("UPDATE gamificacao_totais SET nivel = ?, nivel_num = ? WHERE user_id = ?");
$nomeUser = $pdo->prepare("SELECT name FROM users WHERE id = ?");
$buscaNivel = $pdo->prepare("SELECT nivel_num, nome FROM gamificacao_niveis WHERE pontos_minimos <= ? ORDER BY pontos_minimos DESC LIMIT 1");

foreach ($tot as $t) {
    $buscaNivel->execute(array((int)$t['total']));
    $novo = $buscaNivel->fetch();
    if (!$novo) continue;
    $nomeUser->execute(array($t['user_id']));
    $userName = $nomeUser->fetchColumn() ?: '(desconhecido)';
    if ((int)$novo['nivel_num'] !== (int)$t['nivel_num']) {
        $upd->execute(array($novo['nome'], $novo['nivel_num'], $t['user_id']));
        echo "[UP] {$userName}: nível {$t['nivel_num']} → {$novo['nivel_num']} ({$novo['nome']}) — {$t['total']} pts\n";
    } else {
        echo "[=] {$userName}: nível {$novo['nivel_num']} ({$novo['nome']}) — {$t['total']} pts (sem mudança)\n";
    }
}

echo "\n--- Pronto ---\n";
