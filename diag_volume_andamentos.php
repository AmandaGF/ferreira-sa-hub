<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Volume de andamentos ===\n";
$total = (int)$pdo->query("SELECT COUNT(*) FROM case_andamentos WHERE descricao IS NOT NULL AND descricao != ''")->fetchColumn();
echo "Total: $total andamentos\n";

// Detecta os que provavelmente tem erros (palavras-chave em portugues sem acento)
// Heuristica: tem ao menos uma das palavras abaixo SEM acento
$sql = "SELECT COUNT(*) FROM case_andamentos
        WHERE descricao IS NOT NULL AND descricao != ''
          AND (descricao REGEXP '[[:<:]](nao|decisao|peticao|audiencia|citacao|intimacao|contestacao|replica|despacho|certidao|conclusos|merito|orgao|publico|ministerio|distribuicao|sentenca|acordao|uniao|gratuidade|justica)[[:>:]]'
               COLLATE utf8mb4_bin)";
$comProb = (int)$pdo->query($sql)->fetchColumn();
echo "Com palavras-gatilho SEM acento (suspeitos): $comProb\n";
$pct = $total ? round($comProb*100/$total,1) : 0;
echo "% do total: {$pct}%\n";

echo "\n=== Casos com mais andamentos ===\n";
foreach ($pdo->query("SELECT cs.id, cs.title, COUNT(*) AS n
                      FROM case_andamentos a JOIN cases cs ON cs.id = a.case_id
                      WHERE a.descricao IS NOT NULL AND a.descricao != ''
                      GROUP BY cs.id ORDER BY n DESC LIMIT 10") as $r) {
    echo "  Caso #{$r['id']}: {$r['title']} | {$r['n']} andamentos\n";
}

echo "\n=== Tamanho medio (chars) ===\n";
$avg = (int)$pdo->query("SELECT AVG(CHAR_LENGTH(descricao)) FROM case_andamentos WHERE descricao IS NOT NULL")->fetchColumn();
echo "  $avg chars\n";

echo "\n=== Estimativa de custo (Haiku 4.5) ===\n";
// Haiku: $1/M input + $5/M output. Tipico: ~400 tok in / ~400 tok out por andamento.
// USD->BRL ~5,5
$tokensIn = $comProb * 400;
$tokensOut = $comProb * 400;
$custoUsd = ($tokensIn/1e6)*1 + ($tokensOut/1e6)*5;
$custoBrl = $custoUsd * 5.5;
echo "  Aprox. $tokensIn input + $tokensOut output tokens\n";
echo "  Custo estimado: R\$ " . number_format($custoBrl, 2, ',', '.') . "\n";
echo "  (corrigindo SO os $comProb suspeitos — economia vs $total totais)\n";
