<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "<pre>";
echo "PHP: " . PHP_VERSION . "\n";
echo "Server: " . ($_SERVER['HTTP_HOST'] ?? '?') . "\n";
echo "URI: " . ($_SERVER['REQUEST_URI'] ?? '?') . "\n\n";

echo "1. Config...\n";
try {
    require_once __DIR__ . '/core/config.php';
    echo "   OK - DB_NAME=" . DB_NAME . "\n";
} catch (Exception $e) {
    echo "   ERRO: " . $e->getMessage() . "\n";
}

echo "2. Database...\n";
try {
    require_once __DIR__ . '/core/database.php';
    $pdo = db();
    $v = $pdo->query("SELECT 1")->fetchColumn();
    echo "   OK - query=$v\n";
} catch (Exception $e) {
    echo "   ERRO: " . $e->getMessage() . "\n";
}

echo "3. Functions...\n";
try {
    require_once __DIR__ . '/core/functions.php';
    echo "   OK\n";
} catch (Exception $e) {
    echo "   ERRO: " . $e->getMessage() . "\n";
}

echo "4. Auth...\n";
try {
    require_once __DIR__ . '/core/auth.php';
    echo "   OK\n";
} catch (Exception $e) {
    echo "   ERRO: " . $e->getMessage() . "\n";
}

echo "5. Users table...\n";
try {
    $users = $pdo->query("SELECT id, name, email, role, is_active FROM users LIMIT 5")->fetchAll();
    foreach ($users as $u) {
        echo "   - [{$u['id']}] {$u['name']} ({$u['email']}) role={$u['role']} active={$u['is_active']}\n";
    }
} catch (Exception $e) {
    echo "   ERRO: " . $e->getMessage() . "\n";
}

echo "6. Session...\n";
echo "   ID: " . session_id() . "\n";
echo "   Status: " . session_status() . "\n";

echo "\nTudo OK!\n";
echo "</pre>";
