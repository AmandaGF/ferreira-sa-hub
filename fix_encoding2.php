<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

$apply = isset($_GET['apply']);

// Ver amostra raw dos dados corrompidos
echo "=== Amostra hex dos dados ===\n";
$row = $pdo->query("SELECT id, HEX(LEFT(descricao,100)) as hex_desc, LEFT(descricao,100) as desc_text FROM case_andamentos WHERE case_id = 734 ORDER BY id DESC LIMIT 1")->fetch();
echo "ID: " . $row['id'] . "\n";
echo "Texto: " . $row['desc_text'] . "\n";
echo "Hex: " . $row['hex_desc'] . "\n\n";

// Contar todos os andamentos com problemas (case 734 e outros)
echo "=== Andamentos com ½ ou ¿ ou � (double-encoded) ===\n";
$broken = $pdo->query("SELECT id, case_id, descricao FROM case_andamentos WHERE descricao LIKE '%¿½%' OR descricao LIKE '%i¿½%' OR descricao LIKE '%�%' ORDER BY id DESC LIMIT 50")->fetchAll();
echo "Total: " . count($broken) . "\n\n";

foreach ($broken as $b) {
    // Esses dados foram double-encoded: UTF-8 bytes interpretados como latin1, depois convertidos para UTF-8 de novo
    // Tentativas de fix:
    $text = $b['descricao'];

    // Tentativa 1: decode from UTF-8 to latin1 then back to UTF-8
    $fix1 = @mb_convert_encoding($text, 'ISO-8859-1', 'UTF-8');

    // Tentativa 2: usar utf8_decode (PHP built-in)
    $fix2 = utf8_decode($text);

    // Escolher o melhor: verificar se tem menos caracteres estranhos
    $score1 = substr_count($fix1, '?') + substr_count($fix1, '�');
    $score2 = substr_count($fix2, '?') + substr_count($fix2, '�');
    $best = ($score1 <= $score2) ? $fix1 : $fix2;

    echo "#" . $b['id'] . " case=" . $b['case_id'] . "\n";
    echo "  ANTES: " . mb_substr($text, 0, 120) . "\n";
    echo "  FIX1 : " . mb_substr($fix1, 0, 120) . "\n";
    echo "  FIX2 : " . mb_substr($fix2, 0, 120) . "\n";

    if ($apply && $best !== $text) {
        $pdo->prepare("UPDATE case_andamentos SET descricao = ? WHERE id = ?")->execute(array($best, $b['id']));
        echo "  → APLICADO\n";
    }
    echo "\n";
}

if (!$apply) echo ">>> Modo simulação. Para aplicar: &apply=1\n";
