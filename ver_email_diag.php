<?php
/**
 * ver_email_diag.php — Diagnóstico 2: cruza pendentes do Email Monitor com a
 * tabela cases pra descobrir se o processo NÃO existe ou só está com o
 * case_number desalinhado (máscara/zeros) ou vinculável por nome.
 * Uso: https://ferreiraesa.com.br/conecta/ver_email_diag.php?key=fsa-hub-deploy-2026
 * Script de uso único — remover do repo depois.
 */
require_once __DIR__ . '/core/database.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Negado.'); }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

function so_digitos($s) { return preg_replace('/\D/', '', (string)$s); }
function primeiro_nome_maiusc($s) {
    $s = trim((string)$s);
    // pega o primeiro nome "de verdade" (ignora iniciais tipo "M. D. S.")
    $tokens = preg_split('/\s+/', $s);
    foreach ($tokens as $t) {
        $t = trim($t, " .,");
        if (mb_strlen($t) >= 3) return mb_strtoupper($t, 'UTF-8');
    }
    return mb_strtoupper($s, 'UTF-8');
}

echo "=== DIAG 2 — PENDENTES x CASES ===\n";
echo "Agora: " . date('d/m/Y H:i:s') . "\n\n";

// Carrega todos os cases com seu número normalizado
$cases = $pdo->query("SELECT id, case_number, title, client_id, status FROM cases")->fetchAll(PDO::FETCH_ASSOC);
$byDigits = array();
foreach ($cases as $c) {
    $d = so_digitos($c['case_number']);
    if ($d !== '') $byDigits[$d][] = $c;
}
echo "Total de cases no banco: " . count($cases) . "\n";

$pend = $pdo->query("SELECT case_number, polo_ativo, polo_passivo, orgao, total_emails_recebidos
                     FROM email_monitor_pendentes WHERE status='pendente' ORDER BY total_emails_recebidos DESC")->fetchAll(PDO::FETCH_ASSOC);
echo "Total pendentes: " . count($pend) . "\n\n";

$catExatoFalhaDigitoOk = 0; // existe case com mesmos dígitos mas case_number formatado diferente
$catNomeProvavel = 0;       // não acha por número, mas nome do polo bate com cliente de algum case
$catNaoExiste = 0;          // realmente não há case correspondente

$stmtCli = $pdo->prepare("SELECT c.id, c.name, cs.id AS case_id, cs.case_number, cs.title
                          FROM clients c JOIN cases cs ON cs.client_id = c.id
                          WHERE UPPER(c.name) LIKE ? LIMIT 6");

foreach ($pend as $p) {
    $cnj = $p['case_number'];
    $dig = so_digitos($cnj);
    echo "CNJ {$cnj}  ({$p['total_emails_recebidos']} emails)\n";
    echo "   " . mb_substr((string)$p['polo_ativo'],0,45) . " x " . mb_substr((string)$p['polo_passivo'],0,45) . "\n";

    if (isset($byDigits[$dig])) {
        // Existe um case com os mesmos dígitos -> case_number formatado diferente / OK mas match falhou
        foreach ($byDigits[$dig] as $c) {
            echo "   >>> EXISTE case #{$c['id']} case_number='{$c['case_number']}' status={$c['status']} title=" . mb_substr((string)$c['title'],0,40) . "\n";
            echo "       (dígitos batem — match exato falhou por formatação/espacos)\n";
        }
        $catExatoFalhaDigitoOk++;
    } else {
        // Tenta achar por nome do polo ativo
        $nome = primeiro_nome_maiusc($p['polo_ativo']);
        $achou = array();
        if (mb_strlen($nome) >= 3) {
            $stmtCli->execute(array('%' . $nome . '%'));
            $achou = $stmtCli->fetchAll(PDO::FETCH_ASSOC);
        }
        if ($achou) {
            echo "   ?? Possível vínculo por NOME ('{$nome}'):\n";
            foreach ($achou as $a) {
                echo "      cliente #{$a['id']} {$a['name']} -> case #{$a['case_id']} num='{$a['case_number']}'\n";
            }
            $catNomeProvavel++;
        } else {
            echo "   --- Nenhum case com esses dígitos nem cliente com esse nome. Provavelmente NÃO cadastrado.\n";
            $catNaoExiste++;
        }
    }
    echo "\n";
}

echo "=== RESUMO ===\n";
echo "Pendentes cujo processo JÁ EXISTE (case_number desalinhado): $catExatoFalhaDigitoOk\n";
echo "Pendentes com possível vínculo por nome do cliente:           $catNomeProvavel\n";
echo "Pendentes provavelmente NÃO cadastrados:                      $catNaoExiste\n";
echo "\n=== FIM ===\n";
