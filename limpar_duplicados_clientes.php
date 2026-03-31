<?php
/**
 * Limpeza de clientes duplicados
 * Mantém o registro mais completo (mais campos preenchidos), redireciona referências e remove os extras.
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
set_time_limit(300);

$dryRun = !isset($_GET['executar']); // Por padrão só mostra o que faria

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== LIMPEZA DE CLIENTES DUPLICADOS ===\n";
echo $dryRun ? ">>> MODO SIMULAÇÃO (adicione &executar para rodar de verdade) <<<\n\n" : ">>> EXECUTANDO LIMPEZA <<<\n\n";

// 1. Encontrar grupos de nomes duplicados
$stmt = $pdo->query(
    "SELECT name, COUNT(*) as qtd, GROUP_CONCAT(id ORDER BY id) as ids
     FROM clients
     WHERE name IS NOT NULL AND name != ''
     GROUP BY name HAVING qtd > 1
     ORDER BY qtd DESC"
);
$duplicados = $stmt->fetchAll();

echo "Total de nomes duplicados: " . count($duplicados) . "\n";

// Campos que contam como "preenchidos" para determinar o melhor registro
$camposCheck = array('cpf','rg','birth_date','phone','email','address_street','address_city',
    'address_state','address_zip','profession','marital_status','gender','pix_key','children_names');

$totalRemovidos = 0;
$totalRedirecionados = 0;

// Tabelas que referenciam client_id
$tabelasRef = array(
    array('tabela' => 'pipeline_leads', 'coluna' => 'client_id'),
    array('tabela' => 'cases',          'coluna' => 'client_id'),
    array('tabela' => 'case_documents', 'coluna' => 'client_id'),
    array('tabela' => 'agenda_eventos', 'coluna' => 'client_id'),
    array('tabela' => 'notificacoes_cliente', 'coluna' => 'client_id'),
);

// Verificar quais tabelas existem
$tabelasExistentes = array();
foreach ($tabelasRef as $ref) {
    try {
        $pdo->query("SELECT 1 FROM " . $ref['tabela'] . " LIMIT 0");
        $tabelasExistentes[] = $ref;
    } catch (Exception $e) {
        // tabela não existe, ignorar
    }
}

foreach ($duplicados as $dup) {
    $ids = explode(',', $dup['ids']);
    if (count($ids) < 2) continue;

    // Buscar todos os registros deste grupo
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT * FROM clients WHERE id IN ($placeholders) ORDER BY id");
    $stmt->execute($ids);
    $registros = $stmt->fetchAll();

    // Calcular "score" de completude para cada registro
    $melhor = null;
    $melhorScore = -1;
    foreach ($registros as $reg) {
        $score = 0;
        foreach ($camposCheck as $campo) {
            if (!empty($reg[$campo]) && $reg[$campo] !== '[NÃO INFORMADO]') $score++;
        }
        // Desempate: ID menor (mais antigo)
        if ($score > $melhorScore || ($score === $melhorScore && ($melhor === null || $reg['id'] < $melhor['id']))) {
            $melhor = $reg;
            $melhorScore = $score;
        }
    }

    $melhId = (int)$melhor['id'];
    $idsRemover = array();
    foreach ($registros as $reg) {
        if ((int)$reg['id'] !== $melhId) {
            $idsRemover[] = (int)$reg['id'];
        }
    }

    if (empty($idsRemover)) continue;

    // Mesclar dados: preencher campos vazios do melhor com dados dos outros
    if (!$dryRun) {
        foreach ($camposCheck as $campo) {
            if (empty($melhor[$campo]) || $melhor[$campo] === '[NÃO INFORMADO]') {
                // Procurar valor nos outros registros
                foreach ($registros as $reg) {
                    if ((int)$reg['id'] !== $melhId && !empty($reg[$campo]) && $reg[$campo] !== '[NÃO INFORMADO]') {
                        $pdo->prepare("UPDATE clients SET $campo = ? WHERE id = ?")->execute(array($reg[$campo], $melhId));
                        break;
                    }
                }
            }
        }
    }

    // Redirecionar referências
    foreach ($tabelasExistentes as $ref) {
        $placeholders2 = implode(',', array_fill(0, count($idsRemover), '?'));
        if ($dryRun) {
            $cStmt = $pdo->prepare("SELECT COUNT(*) as c FROM " . $ref['tabela'] . " WHERE " . $ref['coluna'] . " IN ($placeholders2)");
            $cStmt->execute($idsRemover);
            $cnt = (int)$cStmt->fetch()['c'];
            if ($cnt > 0) {
                echo "  [REDIR] " . $ref['tabela'] . ": $cnt registro(s) de IDs " . implode(',', $idsRemover) . " → ID $melhId\n";
                $totalRedirecionados += $cnt;
            }
        } else {
            $params = $idsRemover;
            array_unshift($params, $melhId);
            $pdo->prepare("UPDATE " . $ref['tabela'] . " SET " . $ref['coluna'] . " = ? WHERE " . $ref['coluna'] . " IN ($placeholders2)")
                ->execute($params);
            $totalRedirecionados += $pdo->query("SELECT ROW_COUNT()")->fetchColumn();
        }
    }

    // Remover duplicados
    if ($dryRun) {
        echo "[" . count($idsRemover) . " extra(s)] " . $dup['name'] . " — manter ID $melhId (score $melhorScore), remover IDs: " . implode(', ', $idsRemover) . "\n";
    } else {
        $placeholders3 = implode(',', array_fill(0, count($idsRemover), '?'));
        $pdo->prepare("DELETE FROM clients WHERE id IN ($placeholders3)")->execute($idsRemover);
    }

    $totalRemovidos += count($idsRemover);
}

echo "\n=== RESUMO ===\n";
echo "Grupos de duplicados: " . count($duplicados) . "\n";
echo "Registros a " . ($dryRun ? 'remover' : 'removidos') . ": $totalRemovidos\n";
echo "Referências " . ($dryRun ? 'a redirecionar' : 'redirecionadas') . ": $totalRedirecionados\n";

// Contar total antes/depois
$totalClientes = (int)$pdo->query("SELECT COUNT(*) FROM clients")->fetchColumn();
echo "Total de clientes atual: $totalClientes\n";
echo "Total após limpeza: " . ($totalClientes - $totalRemovidos) . "\n";

if ($dryRun) {
    echo "\n>>> Para executar de verdade, acesse com &executar <<<\n";
}

echo "\n=== FIM ===\n";
