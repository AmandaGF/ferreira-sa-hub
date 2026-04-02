<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');

$action = isset($_GET['action']) ? $_GET['action'] : 'token';

if ($action === 'fix_deploy') {
    // Temporariamente comentar GITHUB_TOKEN no config para deploy usar URL publica
    $cfgPath = __DIR__ . '/core/config.php';
    $cfg = file_get_contents($cfgPath);
    $cfgBackup = $cfg;

    // Comentar a linha do GITHUB_TOKEN
    $cfg = str_replace("define('GITHUB_TOKEN'", "// define('GITHUB_TOKEN'", $cfg);
    file_put_contents($cfgPath, $cfg);
    echo "1. GITHUB_TOKEN desativado temporariamente\n";

    // Agora rodar deploy via URL publica
    echo "2. Baixando ZIP (URL publica)...\n";
    $url = 'https://codeload.github.com/AmandaGF/ferreira-sa-hub/zip/refs/heads/main';
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);
    $data = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if (!$data || strlen($data) < 1000 || $err) {
        file_put_contents($cfgPath, $cfgBackup);
        die("ERRO download: " . ($err ? $err : "tamanho=" . strlen($data)) . "\nConfig restaurado.\n");
    }
    echo "   OK (" . strlen($data) . " bytes)\n";

    $dir = rtrim(__DIR__, '/');
    $zf = $dir . '/tmp_fix.zip';
    file_put_contents($zf, $data);

    $za = new ZipArchive();
    $r = $za->open($zf);
    if ($r !== true) {
        @unlink($zf);
        file_put_contents($cfgPath, $cfgBackup);
        die("ERRO ZIP (code $r)\nConfig restaurado.\n");
    }

    $firstName = $za->getNameIndex(0);
    $prefix = substr($firstName, 0, strpos($firstName, '/') + 1);
    $prefixLen = strlen($prefix);
    echo "   Prefixo: $prefix\n";

    $count = 0;
    for ($i = 0; $i < $za->numFiles; $i++) {
        $name = $za->getNameIndex($i);
        if (strpos($name, $prefix) !== 0) continue;
        $rel = substr($name, $prefixLen);
        if ($rel === '' || $rel === false) continue;
        $target = $dir . '/' . $rel;
        if (substr($name, -1) === '/') {
            if (!is_dir($target)) { @mkdir($target, 0755, true); }
        } else {
            $tdir = dirname($target);
            if (!is_dir($tdir)) { @mkdir($tdir, 0755, true); }
            file_put_contents($target, $za->getFromIndex($i));
            @chmod($target, 0644);
            $count++;
        }
    }
    $za->close();
    @unlink($zf);
    echo "   $count arquivos extraidos\n";

    // Restaurar config COM token
    echo "3. Restaurando config.php com GITHUB_TOKEN...\n";
    file_put_contents($cfgPath, $cfgBackup);
    echo "   OK\n\n";

    echo "=== DEPLOY CONCLUIDO! $count arquivos ===\n";
    echo "deploy2.php atualizado para suportar repo privado.\n";
    exit;
}

// Ler arquivo do servidor
if ($action === 'readfile') {
    $path = isset($_GET['path']) ? $_GET['path'] : '';
    if (!$path) { die("Passe &path=caminho\n"); }
    // Segurança: só permite ler dentro de public_html
    $realPath = realpath($path);
    if (!$realPath || strpos($realPath, '/home/ferre315') !== 0) {
        // Tentar caminho relativo a public_html
        $realPath = realpath('/home/ferre315/public_html/' . $path);
    }
    if (!$realPath || !file_exists($realPath)) { die("Arquivo não encontrado: $path\n"); }
    echo "=== " . basename($realPath) . " ===\n\n";
    echo file_get_contents($realPath);
    exit;
}

// Query livre
if ($action === 'query') {
    require_once __DIR__ . '/core/config.php';
    require_once __DIR__ . '/core/database.php';
    $pdo = db();
    $q = isset($_GET['q']) ? base64_decode($_GET['q']) : '';
    if (!$q) { die("Passe &q=BASE64\n"); }
    echo "SQL: $q\n\n";
    if (stripos(trim($q), 'SELECT') === 0) {
        $stmt = $pdo->query($q);
        $rows = $stmt->fetchAll();
        echo "Linhas: " . count($rows) . "\n\n";
        foreach ($rows as $r) { echo implode(' | ', $r) . "\n"; }
    } else {
        $affected = $pdo->exec($q);
        echo "Affected: $affected\n";
    }
    exit;
}

// Migrar pipeline comercial
if ($action === 'migrar_pipeline') {
    require_once __DIR__ . '/core/config.php';
    require_once __DIR__ . '/core/database.php';
    $pdo = db();
    $modo = isset($_GET['modo']) ? $_GET['modo'] : 'passo1';
    $sqlFile = file_get_contents(__DIR__ . '/migracao_pipeline_comercial.sql');
    if (!$sqlFile) { die("ERRO: arquivo SQL nao encontrado\n"); }
    $antes_clients = $pdo->query("SELECT COUNT(*) FROM clients")->fetchColumn();
    $antes_leads = $pdo->query("SELECT COUNT(*) FROM pipeline_leads")->fetchColumn();
    echo "ANTES: clients=$antes_clients, leads=$antes_leads\n\n";
    $host = defined('DB_HOST') ? DB_HOST : 'localhost';
    $db = defined('DB_NAME') ? DB_NAME : '';
    $user = defined('DB_USER') ? DB_USER : '';
    $pass = defined('DB_PASS') ? DB_PASS : '';
    $mysqli = new mysqli($host, $user, $pass, $db);
    $mysqli->set_charset('utf8mb4');
    if ($modo === 'passo1') {
        preg_match_all('/INSERT IGNORE INTO clients.*?;/s', $sqlFile, $matches);
        $total = 0;
        foreach ($matches[0] as $sql) { if ($mysqli->query($sql)) { $total += $mysqli->affected_rows; } else { echo "ERRO: " . $mysqli->error . "\n"; break; } }
        $depois = $pdo->query("SELECT COUNT(*) FROM clients")->fetchColumn();
        echo "PASSO 1: $total linhas inseridas\n";
        echo "DEPOIS: clients=$depois (+" . ($depois - $antes_clients) . " novos)\n";
    } elseif ($modo === 'passo2') {
        preg_match_all('/INSERT INTO pipeline_leads.*?;/s', $sqlFile, $matches);
        $total = 0; $erros = 0;
        foreach ($matches[0] as $sql) { if ($mysqli->query($sql)) { $total += $mysqli->affected_rows; } else { $erros++; echo "ERRO: " . mb_substr($mysqli->error, 0, 200) . "\n"; } }
        $depois = $pdo->query("SELECT COUNT(*) FROM pipeline_leads")->fetchColumn();
        echo "PASSO 2: $total leads inseridos ($erros erros)\n";
        echo "DEPOIS: leads=$depois (+" . ($depois - $antes_leads) . " novos)\n\n";
        echo "VERIFICACAO:\n";
        $stmt = $pdo->query("SELECT stage, COUNT(*) as qtd FROM pipeline_leads GROUP BY stage ORDER BY qtd DESC");
        foreach ($stmt->fetchAll() as $r) { echo "  {$r['stage']}: {$r['qtd']}\n"; }
    }
    $mysqli->close();
    exit;
}

// Finalizar pasta_apta no pipeline
if ($action === 'finalizar_pipeline') {
    require_once __DIR__ . '/core/config.php';
    require_once __DIR__ . '/core/database.php';
    $pdo = db();
    $modo = isset($_GET['modo']) ? $_GET['modo'] : 'select';
    $file = __DIR__ . '/finalizar_pasta_apta_pipeline.sql';
    if (!file_exists($file)) { die("ERRO: arquivo nao existe em $file\n"); }
    $sql = file_get_contents($file);
    echo "Arquivo: " . strlen($sql) . " bytes\n";
    $p1 = strpos($sql, 'AND name IN (');
    echo "Posicao AND name IN: $p1\n";
    if ($p1 === false) { die("ERRO: texto 'AND name IN (' nao encontrado\n"); }
    $p2 = strpos($sql, ');', $p1);
    echo "Posicao fechamento: $p2\n";
    if ($p2 === false) { die("ERRO: fechamento nao encontrado\n"); }
    $nameList = substr($sql, $p1 + 13, $p2 - $p1 - 13);
    echo "Lista: " . strlen($nameList) . " chars\n\n";
    if ($modo === 'select') {
        $count = $pdo->query("SELECT COUNT(*) FROM pipeline_leads WHERE stage = 'pasta_apta' AND name IN ($nameList)")->fetchColumn();
        echo "Total a mover: $count\n";
    } elseif ($modo === 'update') {
        $count = $pdo->query("SELECT COUNT(*) FROM pipeline_leads WHERE stage = 'pasta_apta' AND name IN ($nameList)")->fetchColumn();
        echo "1. SELECT: $count leads\n";
        $affected = $pdo->exec("UPDATE pipeline_leads SET stage='finalizado', converted_at=IFNULL(converted_at,NOW()), updated_at=NOW() WHERE stage='pasta_apta' AND name IN ($nameList)");
        echo "2. UPDATE: $affected linhas\n\n3. Verificacao:\n";
        $stmt = $pdo->query("SELECT stage, COUNT(*) as qtd FROM pipeline_leads GROUP BY stage ORDER BY qtd DESC");
        foreach ($stmt->fetchAll() as $r) { echo "   {$r['stage']}: {$r['qtd']}\n"; }
    }
    exit;
}

// Adicionar config
if ($action === 'set_config') {
    $key = isset($_GET['k']) ? $_GET['k'] : '';
    $val = isset($_GET['v']) ? $_GET['v'] : '';
    if (!$key || !$val) { die("Use: &k=NOME&v=VALOR\n"); }
    $cfgPath = __DIR__ . '/core/config.php';
    $cfg = file_get_contents($cfgPath);
    if (strpos($cfg, $key) !== false) {
        $cfg = preg_replace("/define\('" . preg_quote($key) . "',\s*'[^']*'\)/", "define('" . $key . "', '" . addslashes($val) . "')", $cfg);
        echo "Atualizado: $key\n";
    } else {
        $line = "\ndefine('" . $key . "', '" . addslashes($val) . "');\n";
        $cfg .= $line;
        echo "Adicionado: $key\n";
    }
    file_put_contents($cfgPath, $cfg);
    exit;
}

// Gerar ENCRYPT_KEY
if ($action === 'gen_key') {
    $cfgPath = __DIR__ . '/core/config.php';
    $cfg = file_get_contents($cfgPath);
    $newKey = bin2hex(random_bytes(32));
    $cfg = preg_replace("/define\('ENCRYPT_KEY',\s*'[^']*'\)/", "define('ENCRYPT_KEY', '$newKey')", $cfg);
    file_put_contents($cfgPath, $cfg);
    echo "ENCRYPT_KEY gerada: " . substr($newKey, 0, 10) . "...\n";
    echo "Agora rode o seed_links.php\n";
    exit;
}

// Desativar GITHUB_TOKEN (para forçar deploy via URL pública)
if ($action === 'disable_token') {
    $cfgPath = __DIR__ . '/core/config.php';
    $cfg = file_get_contents($cfgPath);
    $cfg = preg_replace("/\n?\/\/\s*GitHub Token.*\ndefine\('GITHUB_TOKEN'[^\n]*\n/", "\n", $cfg);
    $cfg = str_replace("define('GITHUB_TOKEN'", "// define('GITHUB_TOKEN'", $cfg);
    file_put_contents($cfgPath, $cfg);
    echo "GITHUB_TOKEN desativado no config.php\n";
    exit;
}

// Corrigir Kanban: em_elaboracao -> distribuido
if ($action === 'fix_kanban') {
    require_once __DIR__ . '/core/config.php';
    require_once __DIR__ . '/core/database.php';
    $pdo = db();
    $modo = isset($_GET['modo']) ? $_GET['modo'] : 'select';

    $sqlFile = file_get_contents(__DIR__ . '/correcao_final_portal.sql');
    $selectStart = strpos($sqlFile, "SELECT id, title, status, created_at, case_number");
    $selectEnd = strpos($sqlFile, "ORDER BY title;");
    if ($selectStart === false || $selectEnd === false) { die("ERRO: SQL nao encontrado no arquivo\n"); }

    $selectSql = substr($sqlFile, $selectStart, $selectEnd - $selectStart + strlen("ORDER BY title"));
    $selectSql = str_replace("status = 'pasta_apta'", "status = 'em_elaboracao'", $selectSql);

    $whereStart = strpos($selectSql, "AND (");
    $whereEnd = strrpos($selectSql, ")");
    $whereClause = substr($selectSql, $whereStart, $whereEnd - $whereStart + 1);

    if ($modo === 'select') {
        echo "=== SELECT DE CONFERENCIA ===\n\n";
        $stmt = $pdo->query($selectSql);
        $rows = $stmt->fetchAll();
        echo "TOTAL: " . count($rows) . " linhas\n\n";
        foreach ($rows as $r) {
            echo "#{$r['id']} | {$r['title']} | proc: {$r['case_number']}\n";
        }
    } elseif ($modo === 'update') {
        echo "=== CORRECAO KANBAN ===\n\n";
        $stmt = $pdo->query($selectSql);
        $rows = $stmt->fetchAll();
        echo "1. SELECT: " . count($rows) . " linhas\n\n";
        if (count($rows) === 0) { die("ABORTADO: 0 linhas\n"); }
        $updateSql = "UPDATE cases SET status = 'distribuido', updated_at = NOW() WHERE status = 'em_elaboracao' $whereClause";
        $affected = $pdo->exec($updateSql);
        echo "2. UPDATE: $affected linhas atualizadas\n\n";
        echo "3. Verificacao:\n";
        $stmt = $pdo->query("SELECT status, COUNT(*) as qtd FROM cases GROUP BY status ORDER BY qtd DESC");
        foreach ($stmt->fetchAll() as $r) { echo "   {$r['status']}: {$r['qtd']}\n"; }
        echo "\nCONCLUIDO!\n";
    }
    exit;
}

// Corrigir banco
if ($action === 'fix_db') {
    $u = isset($_GET['u']) ? $_GET['u'] : '';
    $p = isset($_GET['p']) ? $_GET['p'] : '';
    if (!$u || !$p) { die("Use: &u=USUARIO&p=SENHA\n"); }
    $cfgPath = __DIR__ . '/core/config.php';
    $cfg = file_get_contents($cfgPath);
    $cfg = preg_replace("/define\('DB_USER',\s*'[^']*'\)/", "define('DB_USER', '$u')", $cfg);
    $cfg = preg_replace("/define\('DB_PASS',\s*'[^']*'\)/", "define('DB_PASS', '$p')", $cfg);
    file_put_contents($cfgPath, $cfg);
    echo "DB corrigido! USER=$u\n";
    exit;
}

// Acao padrao: adicionar token
$token = isset($_GET['t']) ? trim($_GET['t']) : '';
if (empty($token)) {
    die("Passe o token: ?key=...&t=SEU_TOKEN\nOu use: ?key=...&action=fix_deploy\n");
}

$cfgPath = __DIR__ . '/core/config.php';
$cfg = file_get_contents($cfgPath);

if (strpos($cfg, 'GITHUB_TOKEN') !== false) {
    echo "GITHUB_TOKEN ja existe no config.php!\n";
    exit;
}

$line = "\n// GitHub Token (repo privado)\ndefine('GITHUB_TOKEN', '" . addslashes($token) . "');\n";
if (strpos($cfg, '?>') !== false) {
    $cfg = str_replace('?>', $line . '?>', $cfg);
} else {
    $cfg .= $line;
}
file_put_contents($cfgPath, $cfg);
echo "GITHUB_TOKEN adicionado!\n";
