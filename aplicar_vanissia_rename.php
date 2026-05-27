<?php
// 27/05/2026 — Amanda confirmou: o cliente #2391 (cadastrado como 'Zilma
// Ferreira') na verdade e a VANISSIA FERREIRA SANTOS, que preencheu o
// form colocando o nome da mae mas com o proprio CPF e data de nascimento.
// Renomear o registro e adicionar observacao interna.

if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
require_once __DIR__ . '/core/database.php';
$pdo = db();
header('Content-Type: text/plain; charset=utf-8');

$clientId = 2391;

$st = $pdo->prepare("SELECT id, name, cpf, email, notes FROM clients WHERE id = ?");
$st->execute(array($clientId));
$cli = $st->fetch();
if (!$cli) { echo "Cliente #$clientId nao encontrado.\n"; exit; }

echo "Antes:\n";
foreach ($cli as $k => $v) echo "  $k = $v\n";

$nomeAntigo = $cli['name'];
$nomeNovo = 'Vanissia Ferreira Santos';

if ($nomeAntigo === $nomeNovo) {
    echo "\nJa renomeado — nada a fazer.\n";
    exit;
}

$obsAtual = trim((string)($cli['notes'] ?? ''));
$obsNova = '⚠ Cadastro corrigido em 27/05/2026 — registro estava como "' . $nomeAntigo
         . '" (nome da mae). Vanissia preencheu o form colocando o nome da mae,'
         . ' mas CPF e data de nascimento sao DELA (confirmado pela Amanda via WA).'
         . ' Verificar se o e-mail (' . ($cli['email'] ?: 'vazio') . ') tambem precisa ser trocado.';

$obsFinal = $obsAtual ? ($obsNova . "\n\n" . $obsAtual) : $obsNova;

// Tenta colunas 'notes' e 'observacoes' — schema variou entre versoes
$updated = false;
try {
    $pdo->prepare("UPDATE clients SET name = ?, notes = ? WHERE id = ?")
        ->execute(array($nomeNovo, $obsFinal, $clientId));
    $updated = true;
} catch (Throwable $e) {
    try {
        $pdo->prepare("UPDATE clients SET name = ?, observacoes = ? WHERE id = ?")
            ->execute(array($nomeNovo, $obsFinal, $clientId));
        $updated = true;
    } catch (Throwable $e2) {
        $pdo->prepare("UPDATE clients SET name = ? WHERE id = ?")
            ->execute(array($nomeNovo, $clientId));
        $updated = true;
        echo "\n[aviso] Coluna de observacoes nao encontrada — so o nome foi atualizado.\n";
    }
}

audit_log('client_rename', 'clients', $clientId, "'$nomeAntigo' -> '$nomeNovo' (Vanissia preencheu form com nome da mae)");

$st->execute(array($clientId));
$cliPos = $st->fetch();
echo "\nDepois:\n";
foreach ($cliPos as $k => $v) echo "  $k = $v\n";

echo "\nPronto. Recarregue o Kanban (Ctrl+Shift+R) e abra o card da Vanissia pra conferir.\n";
