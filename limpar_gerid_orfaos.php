<?php
/**
 * Limpeza dos ORFAOS do rename GERID -> FBI $ (Amanda 19/07/2026).
 *
 * PROBLEMA: o deploy2.php EXTRAI os arquivos do ZIP mas NAO APAGA os que
 * sairam do repositorio. Entao modules/gerid/index.php (codigo velho) continuou
 * no servidor. Quem abrisse o link antigo disparava o self-heal dele, que
 * RECRIAVA gerid_pesquisas e pipeline_leads.gerid_positivo — desfazendo a
 * migracao. Foi exatamente o que aconteceu.
 *
 * Este script: (1) apaga os arquivos orfaos, so depois de confirmar que o
 * substituto novo existe; (2) limpa a tabela/coluna recriadas, so se estiverem
 * VAZIAS e o destino novo tiver os dados.
 *
 * Idempotente. Key-protected.
 */

if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';

ini_set('display_errors', '1');
error_reporting(E_ALL);
header('Content-Type: text/plain; charset=utf-8');

echo "=== Limpeza dos orfaos GERID ===\n\n";

// ─────────────────────────────────────────────────────────
// PARTE 1 — arquivos orfaos no servidor
// ─────────────────────────────────────────────────────────
echo "[1] Arquivos orfaos\n";

// par: [arquivo velho a apagar, substituto novo que PRECISA existir]
$pares = array(
    array(__DIR__ . '/modules/gerid/index.php',        __DIR__ . '/modules/fbi_vinculo/index.php'),
    array(__DIR__ . '/modules/gerid/desktop.ini',      __DIR__ . '/modules/fbi_vinculo/index.php'),
    array(__DIR__ . '/core/functions_gerid_oficio.php', __DIR__ . '/core/functions_fbi_vinculo_oficio.php'),
    array(__DIR__ . '/migrar_gerid.php',                __DIR__ . '/migrar_fbi_vinculo.php'),
);

foreach ($pares as $p) {
    list($velho, $novo) = $p;
    $nomeVelho = str_replace(__DIR__ . '/', '', $velho);
    if (!file_exists($velho)) { echo "    [SKIP] {$nomeVelho} — ja nao existe\n"; continue; }
    if (!file_exists($novo)) {
        echo "    [ABORTA] {$nomeVelho} — substituto novo NAO existe, nao vou apagar\n";
        continue;
    }
    if (@unlink($velho)) echo "    [OK] apagado {$nomeVelho}\n";
    else                 echo "    [ERRO] nao consegui apagar {$nomeVelho}\n";
}

// remove a pasta antiga se ficou vazia
$dirVelho = __DIR__ . '/modules/gerid';
if (is_dir($dirVelho)) {
    $resto = array_diff(scandir($dirVelho), array('.', '..'));

    // error_log e desktop.ini sao lixo gerado (servidor / Windows), nao codigo.
    // Mostra o fim do error_log antes de apagar — pode ter erro util do modulo velho.
    foreach (array('error_log', 'desktop.ini') as $lixo) {
        $pathLixo = $dirVelho . '/' . $lixo;
        if (!file_exists($pathLixo)) continue;
        if ($lixo === 'error_log') {
            $tam = filesize($pathLixo);
            echo "    --- conteudo de modules/gerid/error_log ({$tam} bytes, ultimos 2000) ---\n";
            $txt = @file_get_contents($pathLixo, false, null, max(0, $tam - 2000));
            foreach (array_slice(array_filter(explode("\n", (string)$txt)), -15) as $ln) {
                echo "      " . $ln . "\n";
            }
            echo "    --- fim do log ---\n";
        }
        echo (@unlink($pathLixo) ? "    [OK] apagado modules/gerid/{$lixo}\n"
                                 : "    [ERRO] nao consegui apagar modules/gerid/{$lixo}\n");
    }

    $resto = array_diff(scandir($dirVelho), array('.', '..'));
    if (empty($resto)) {
        echo (@rmdir($dirVelho) ? "    [OK] pasta modules/gerid/ removida\n"
                                : "    [ERRO] nao consegui remover modules/gerid/\n");
    } else {
        echo "    [ATENCAO] modules/gerid/ ainda tem: " . implode(', ', $resto) . "\n";
    }
} else {
    echo "    [SKIP] pasta modules/gerid/ ja nao existe\n";
}

// ─────────────────────────────────────────────────────────
// PARTE 2 — restos no banco (recriados pelo self-heal velho)
// ─────────────────────────────────────────────────────────
echo "\n[2] Restos no banco\n";

$pdo = db();
$db  = $pdo->query('SELECT DATABASE()')->fetchColumn();

function _tab($pdo, $db, $t) {
    $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?");
    $q->execute(array($db, $t)); return (int)$q->fetchColumn() > 0;
}
function _col($pdo, $db, $t, $c) {
    $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $q->execute(array($db, $t, $c)); return (int)$q->fetchColumn() > 0;
}

// 2a) tabela gerid_pesquisas recriada vazia
if (_tab($pdo, $db, 'gerid_pesquisas')) {
    $nVelha = (int)$pdo->query("SELECT COUNT(*) FROM gerid_pesquisas")->fetchColumn();
    $nNova  = _tab($pdo, $db, 'fbi_vinculo_pesquisas')
            ? (int)$pdo->query("SELECT COUNT(*) FROM fbi_vinculo_pesquisas")->fetchColumn() : -1;
    echo "    gerid_pesquisas={$nVelha} linhas | fbi_vinculo_pesquisas={$nNova} linhas\n";
    if ($nVelha === 0 && $nNova > 0) {
        $pdo->exec("DROP TABLE gerid_pesquisas");
        echo "    [OK] gerid_pesquisas estava vazia -> removida\n";
    } elseif ($nVelha > 0) {
        echo "    [ATENCAO] gerid_pesquisas TEM {$nVelha} linha(s) — NAO removida.\n";
        echo "              Foram criadas depois da migracao; consolidar manualmente.\n";
    } else {
        echo "    [ATENCAO] fbi_vinculo_pesquisas nao existe/vazia — nao mexi.\n";
    }
} else {
    echo "    [SKIP] gerid_pesquisas nao existe\n";
}

// 2b) coluna pipeline_leads.gerid_positivo recriada
if (_col($pdo, $db, 'pipeline_leads', 'gerid_positivo')) {
    if (_col($pdo, $db, 'pipeline_leads', 'fbi_vinculo_positivo')) {
        // preserva qualquer carimbo que tenha sido marcado na coluna velha
        $copiados = $pdo->exec("UPDATE pipeline_leads SET fbi_vinculo_positivo = 1 WHERE gerid_positivo = 1 AND fbi_vinculo_positivo = 0");
        $pdo->exec("ALTER TABLE pipeline_leads DROP COLUMN gerid_positivo");
        echo "    [OK] gerid_positivo removida ({$copiados} carimbo(s) recuperado(s) antes)\n";
    } else {
        $pdo->exec("ALTER TABLE pipeline_leads CHANGE gerid_positivo fbi_vinculo_positivo TINYINT(1) NOT NULL DEFAULT 0");
        echo "    [OK] gerid_positivo renomeada (nova nao existia)\n";
    }
} else {
    echo "    [SKIP] coluna gerid_positivo nao existe\n";
}

// ─────────────────────────────────────────────────────────
// PARTE 3 — conferencia
// ─────────────────────────────────────────────────────────
echo "\n[3] Conferencia final\n";
$qt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME LIKE '%gerid%'");
$qt->execute(array($db));
$qc = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND COLUMN_NAME LIKE '%gerid%' AND COLUMN_NAME NOT LIKE '%sugerid%'");
$qc->execute(array($db));
echo "    tabelas com 'gerid': " . (int)$qt->fetchColumn() . " (esperado 0)\n";
echo "    colunas com 'gerid' (ignorando 'sugerido'): " . (int)$qc->fetchColumn() . " (esperado 0)\n";
echo "    pesquisas em fbi_vinculo_pesquisas: "
     . (_tab($pdo, $db, 'fbi_vinculo_pesquisas') ? (int)$pdo->query("SELECT COUNT(*) FROM fbi_vinculo_pesquisas")->fetchColumn() : 'TABELA AUSENTE') . "\n";
echo "    modules/gerid/ ainda existe? " . (is_dir($dirVelho) ? 'SIM' : 'nao') . "\n";

echo "\n=== Limpeza concluida ===\n";
