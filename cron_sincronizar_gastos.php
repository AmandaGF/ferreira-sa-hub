<?php
/**
 * CRON: Sincronizar gastos pensão do banco antigo → Conecta
 * Configurar no cPanel: a cada 1 hora
 * Comando: php /home/ferre3151357/public_html/conecta/cron_sincronizar_gastos.php
 */

// Só permitir CLI ou com chave
if (php_sapi_name() !== 'cli' && ($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') {
    http_response_code(403);
    exit('Acesso negado');
}

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';

$pdo = db();
$log = '';

function clog($msg) {
    global $log;
    $log .= date('H:i:s') . " $msg\n";
}

clog("=== Sincronização gastos pensão ===");

// Conectar ao banco antigo
try {
    $pdoAntigo = new PDO(
        'mysql:host=localhost;dbname=ferre3151357_gastos_pensao;charset=utf8mb4',
        'ferre3151357_pensao_user',
        'Ar192114@',
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC)
    );
} catch (Exception $e) {
    clog("ERRO conexão banco antigo: " . $e->getMessage());
    if (php_sapi_name() !== 'cli') { header('Content-Type: text/plain'); echo $log; }
    exit;
}

// Buscar registros do banco antigo que não existem no Conecta
$rows = $pdoAntigo->query("SELECT * FROM pensao_respostas ORDER BY created_at DESC")->fetchAll();
$sincronizados = 0;

foreach ($rows as $r) {
    $protocolo = $r['protocolo'] ?? '';
    $nome = $r['nome_responsavel'] ?? '';
    $data = $r['created_at'] ?? '';

    // Verificar se já existe
    $check = $pdo->prepare("SELECT id FROM form_submissions WHERE protocol = ? OR (form_type = 'gastos_pensao' AND client_name = ? AND DATE(created_at) = DATE(?))");
    $check->execute(array($protocolo, $nome, $data));
    if ($check->fetch()) continue;

    // Montar payload
    $payload = array();
    foreach ($r as $key => $val) {
        if (in_array($key, array('id', 'protocolo', 'created_at', 'ip', 'user_agent'))) continue;
        if ($val !== null && $val !== '') $payload[$key] = $val;
    }

    try {
        $pdo->prepare(
            "INSERT INTO form_submissions (form_type, protocol, client_name, client_phone, status, payload_json, ip_address, created_at)
             VALUES (?,?,?,?,?,?,?,?)"
        )->execute(array(
            'gastos_pensao',
            $protocolo ?: ('GST-SYNC-' . substr(md5(uniqid()), 0, 8)),
            $nome,
            $r['whatsapp'] ?? '',
            'novo',
            json_encode($payload, JSON_UNESCAPED_UNICODE),
            $r['ip'] ?? '',
            $data ?: date('Y-m-d H:i:s'),
        ));
        $sincronizados++;
        clog("OK: $nome ($protocolo)");
    } catch (Exception $e) {
        clog("ERRO: $nome - " . $e->getMessage());
    }
}

if ($sincronizados > 0) {
    clog("$sincronizados novo(s) sincronizado(s)");
} else {
    clog("Nenhum novo registro");
}

// ═══ SINCRONIZAR CONVIVÊNCIA (banco antigo) ═══
clog("");
clog("=== Sincronização convivência ===");

try {
    $pdoConv = new PDO(
        'mysql:host=localhost;dbname=ferre3151357_bd_convivencia;charset=utf8mb4',
        'ferre3151357_admin_convivencia',
        'Ar192114@',
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC)
    );

    $rowsConv = $pdoConv->query("SELECT * FROM intake_visitas ORDER BY created_at DESC")->fetchAll();
    $sincConv = 0;

    foreach ($rowsConv as $r) {
        $protocolo = $r['protocol'] ?? '';
        $nome = $r['client_name'] ?? '';
        $data = $r['created_at'] ?? '';

        // Verificar se já existe
        $check = $pdo->prepare("SELECT id FROM form_submissions WHERE protocol = ? OR (form_type = 'convivencia' AND client_name = ? AND DATE(created_at) = DATE(?))");
        $check->execute(array($protocolo, $nome, $data));
        if ($check->fetch()) continue;

        // Montar payload
        $payload = array();
        if (isset($r['answers_json']) && $r['answers_json']) {
            $payload = json_decode($r['answers_json'], true) ?: array();
        }
        $payload['child_name'] = $r['child_name'] ?? '';
        $payload['child_age'] = $r['child_age'] ?? '';
        $payload['relationship_role'] = $r['relationship_role'] ?? '';

        try {
            $pdo->prepare(
                "INSERT INTO form_submissions (form_type, protocol, client_name, client_phone, client_email, status, payload_json, ip_address, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?)"
            )->execute(array(
                'convivencia',
                $protocolo ?: ('CVV-SYNC-' . substr(md5(uniqid()), 0, 8)),
                $nome,
                $r['client_phone'] ?? '',
                $r['client_email'] ?? '',
                'novo',
                json_encode($payload, JSON_UNESCAPED_UNICODE),
                $r['ip_address'] ?? '',
                $data ?: date('Y-m-d H:i:s'),
            ));
            $sincConv++;
            clog("OK conv: $nome ($protocolo)");
        } catch (Exception $e) {
            clog("ERRO conv: $nome - " . $e->getMessage());
        }
    }

    if ($sincConv > 0) {
        clog("$sincConv novo(s) convivência sincronizado(s)");
    } else {
        clog("Nenhum novo registro de convivência");
    }
} catch (Exception $e) {
    clog("ERRO conexão banco convivência: " . $e->getMessage());
}

clog("=== FIM ===");

if (php_sapi_name() !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
    echo $log;
}
