<?php
/**
 * API — favoritos da sidebar por usuário (persistência no servidor).
 * POST form: csrf_token + favoritos (JSON array [{id,label,icon,href}], máx 20).
 * Substitui a lista inteira do usuário logado. Retorna {ok:true, total:N}.
 */
require_once __DIR__ . '/../core/middleware.php';
require_login(); // se AJAX e sem sessão -> JSON 401 (X-Requested-With)

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(array('ok' => false, 'erro' => 'método inválido'));
    exit;
}
if (!validate_csrf()) {
    http_response_code(403);
    echo json_encode(array('ok' => false, 'erro' => 'CSRF inválido'));
    exit;
}

$uid = current_user_id();
if (!$uid) { http_response_code(401); echo json_encode(array('ok'=>false,'erro'=>'sem sessão')); exit; }

$raw = $_POST['favoritos'] ?? '[]';
$lista = json_decode($raw, true);
if (!is_array($lista)) $lista = array();

// Sanitiza: máx 20, campos esperados, tamanhos limitados
// (Amanda 29/06: subiu 10→20 também no frontend em sidebar.php)
$clean = array();
$vistos = array();
foreach ($lista as $f) {
    if (!is_array($f)) continue;
    $id    = trim((string)($f['id'] ?? ''));
    $label = trim((string)($f['label'] ?? ''));
    $icon  = trim((string)($f['icon'] ?? ''));
    $href  = trim((string)($f['href'] ?? ''));
    if ($id === '' || $href === '') continue;
    if (isset($vistos[$id])) continue;
    $vistos[$id] = true;
    $clean[] = array(
        'id'    => mb_substr($id, 0, 120),
        'label' => mb_substr($label !== '' ? $label : $id, 0, 160),
        'icon'  => mb_substr($icon, 0, 40),
        'href'  => mb_substr($href, 0, 255),
    );
    if (count($clean) >= 20) break;
}

$pdo = db();
try {
    // Self-heal: garante a tabela (caso migração não tenha rodado ainda)
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_favoritos (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        fav_id VARCHAR(120) NOT NULL,
        label VARCHAR(160) NOT NULL,
        icon VARCHAR(40) DEFAULT '',
        href VARCHAR(255) NOT NULL,
        ordem INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_user_fav (user_id, fav_id),
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->beginTransaction();
    $pdo->prepare("DELETE FROM user_favoritos WHERE user_id = ?")->execute(array($uid));
    if ($clean) {
        $ins = $pdo->prepare("INSERT INTO user_favoritos (user_id, fav_id, label, icon, href, ordem)
                              VALUES (?,?,?,?,?,?)");
        foreach ($clean as $i => $f) {
            $ins->execute(array($uid, $f['id'], $f['label'], $f['icon'], $f['href'], $i));
        }
    }
    $pdo->commit();
    echo json_encode(array('ok' => true, 'total' => count($clean)));
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(array('ok' => false, 'erro' => 'falha ao salvar'));
}
