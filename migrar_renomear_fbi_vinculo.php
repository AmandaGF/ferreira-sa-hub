<?php
/**
 * Migração: renomeia GERID -> FBI $ (slug interno: fbi_vinculo)
 *
 * Pedido da Amanda 19/07/2026: a palavra "gerid" não pode mais existir no
 * sistema, nem na tela nem no banco.
 *
 * DEFENSIVA: o módulo tem self-heal (CREATE TABLE IF NOT EXISTS
 * fbi_vinculo_pesquisas + ADD COLUMN fbi_vinculo_positivo). Se alguém abrir a
 * tela entre o deploy e esta migração, o self-heal cria a tabela/coluna NOVA
 * vazia enquanto os dados reais seguem na antiga. Por isso cada passo detecta
 * os 4 cenários (só antiga / ambas-nova-vazia / ambas-nova-com-dados / só nova)
 * e nunca destrói dado sem avisar.
 *
 * Idempotente: pode rodar quantas vezes precisar.
 *
 * Key-protected.
 */

if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';

ini_set('display_errors', '1');
error_reporting(E_ALL);
header('Content-Type: text/plain; charset=utf-8');

echo "=== Migracao: GERID -> FBI \$ (slug fbi_vinculo) ===\n\n";

$pdo = db();
$db  = $pdo->query('SELECT DATABASE()')->fetchColumn();

function tabela_existe($pdo, $db, $t) {
    $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?");
    $q->execute(array($db, $t));
    return (int)$q->fetchColumn() > 0;
}
function coluna_existe($pdo, $db, $t, $c) {
    $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $q->execute(array($db, $t, $c));
    return (int)$q->fetchColumn() > 0;
}

// ─────────────────────────────────────────────────────────
// 1) Tabela gerid_pesquisas -> fbi_vinculo_pesquisas
// ─────────────────────────────────────────────────────────
echo "[1] Tabela de pesquisas\n";
$temVelha = tabela_existe($pdo, $db, 'gerid_pesquisas');
$temNova  = tabela_existe($pdo, $db, 'fbi_vinculo_pesquisas');

if ($temVelha && !$temNova) {
    $pdo->exec("RENAME TABLE gerid_pesquisas TO fbi_vinculo_pesquisas");
    echo "    [OK] renomeada (dados preservados)\n";
} elseif ($temVelha && $temNova) {
    $nVelha = (int)$pdo->query("SELECT COUNT(*) FROM gerid_pesquisas")->fetchColumn();
    $nNova  = (int)$pdo->query("SELECT COUNT(*) FROM fbi_vinculo_pesquisas")->fetchColumn();
    echo "    ambas existem — antiga={$nVelha} linhas, nova={$nNova} linhas\n";
    if ($nNova === 0) {
        // self-heal criou a nova vazia; descarta e promove a antiga
        $pdo->exec("DROP TABLE fbi_vinculo_pesquisas");
        $pdo->exec("RENAME TABLE gerid_pesquisas TO fbi_vinculo_pesquisas");
        echo "    [OK] nova estava vazia -> descartada; antiga promovida ({$nVelha} linhas)\n";
    } else {
        echo "    [ATENCAO] a NOVA ja tem dados. Nao vou mexer pra nao perder nada.\n";
        echo "              Resolver manualmente: comparar as duas e consolidar.\n";
    }
} elseif (!$temVelha && $temNova) {
    echo "    [SKIP] ja migrada\n";
} else {
    echo "    [SKIP] nenhuma das duas existe\n";
}

// ─────────────────────────────────────────────────────────
// 2) Coluna pipeline_leads.gerid_positivo -> fbi_vinculo_positivo
// ─────────────────────────────────────────────────────────
echo "\n[2] Coluna pipeline_leads (carimbo COM VINCULO)\n";
$colVelha = coluna_existe($pdo, $db, 'pipeline_leads', 'gerid_positivo');
$colNova  = coluna_existe($pdo, $db, 'pipeline_leads', 'fbi_vinculo_positivo');

if ($colVelha && !$colNova) {
    $pdo->exec("ALTER TABLE pipeline_leads CHANGE gerid_positivo fbi_vinculo_positivo TINYINT(1) NOT NULL DEFAULT 0");
    echo "    [OK] renomeada (valores preservados)\n";
} elseif ($colVelha && $colNova) {
    // self-heal criou a nova zerada — copia os 1 da antiga e derruba a antiga
    $afet = $pdo->exec("UPDATE pipeline_leads SET fbi_vinculo_positivo = gerid_positivo WHERE gerid_positivo = 1");
    $pdo->exec("ALTER TABLE pipeline_leads DROP COLUMN gerid_positivo");
    echo "    [OK] ambas existiam -> {$afet} carimbo(s) copiado(s); coluna antiga removida\n";
} elseif (!$colVelha && $colNova) {
    echo "    [SKIP] ja migrada\n";
} else {
    echo "    [SKIP] nenhuma das duas existe\n";
}

// ─────────────────────────────────────────────────────────
// 3) case_tasks.tipo — valor gravado nas linhas
// ─────────────────────────────────────────────────────────
echo "\n[3] case_tasks.tipo = 'gerid_contatos_empresa'\n";
try {
    $n = $pdo->exec("UPDATE case_tasks SET tipo = 'fbi_vinculo_contatos_empresa' WHERE tipo = 'gerid_contatos_empresa'");
    echo "    [OK] {$n} tarefa(s) atualizada(s)\n";
} catch (Exception $e) {
    echo "    [ERRO] " . $e->getMessage() . "\n";
}

// ─────────────────────────────────────────────────────────
// 4) user_permissions.module — permissao do modulo
// ─────────────────────────────────────────────────────────
echo "\n[4] user_permissions.module = 'gerid'\n";
try {
    $n = $pdo->exec("UPDATE user_permissions SET module = 'fbi_vinculo' WHERE module = 'gerid'");
    echo "    [OK] {$n} permissao(oes) atualizada(s)\n";
} catch (Exception $e) {
    echo "    [ERRO] " . $e->getMessage() . "\n";
}

// ─────────────────────────────────────────────────────────
// 5) configuracoes.chave — chaves gerid_* / ia_feature_gerid_*
// ─────────────────────────────────────────────────────────
echo "\n[5] configuracoes.chave contendo 'gerid'\n";
try {
    $rows = $pdo->query("SELECT chave FROM configuracoes WHERE chave LIKE '%gerid%'")->fetchAll(PDO::FETCH_COLUMN);
    if (!$rows) {
        echo "    [SKIP] nenhuma chave com 'gerid'\n";
    } else {
        $up = $pdo->prepare("UPDATE configuracoes SET chave = ? WHERE chave = ?");
        foreach ($rows as $velha) {
            $nova = str_replace('gerid', 'fbi_vinculo', $velha);
            // se a nova ja existir (self-heal criou default), remove a duplicata nova
            // e promove a antiga, que e a que tem o valor real configurado
            $chk = $pdo->prepare("SELECT COUNT(*) FROM configuracoes WHERE chave = ?");
            $chk->execute(array($nova));
            if ((int)$chk->fetchColumn() > 0) {
                $pdo->prepare("DELETE FROM configuracoes WHERE chave = ?")->execute(array($nova));
                echo "    (removida duplicata vazia '{$nova}')\n";
            }
            $up->execute(array($nova, $velha));
            echo "    [OK] '{$velha}' -> '{$nova}'\n";
        }
    }
} catch (Exception $e) {
    echo "    [ERRO] " . $e->getMessage() . "\n";
}

// ─────────────────────────────────────────────────────────
// 6) Conferencia final
// ─────────────────────────────────────────────────────────
echo "\n[6] Conferencia\n";
try {
    $n = (int)$pdo->query("SELECT COUNT(*) FROM fbi_vinculo_pesquisas")->fetchColumn();
    echo "    fbi_vinculo_pesquisas: {$n} pesquisa(s)\n";
    $c = (int)$pdo->query("SELECT COUNT(*) FROM pipeline_leads WHERE fbi_vinculo_positivo = 1")->fetchColumn();
    echo "    leads com carimbo COM VINCULO: {$c}\n";
    $sobrouT = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME LIKE '%gerid%'");
    $sobrouT->execute(array($db));
    $sobrouC = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND COLUMN_NAME LIKE '%gerid%'");
    $sobrouC->execute(array($db));
    echo "    tabelas com 'gerid' no nome: " . (int)$sobrouT->fetchColumn() . " (esperado 0)\n";
    echo "    colunas com 'gerid' no nome: " . (int)$sobrouC->fetchColumn() . " (esperado 0)\n";
} catch (Exception $e) {
    echo "    [ERRO] " . $e->getMessage() . "\n";
}

echo "\n=== Migracao concluida ===\n";
echo "OBS: linhas historicas do audit_log mantem a acao antiga ('gerid_*') —\n";
echo "     e historico do que ja aconteceu, reescrever falsearia o registro.\n";
