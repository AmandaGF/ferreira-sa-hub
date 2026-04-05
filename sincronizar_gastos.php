<?php
/**
 * Sincronizar pensao_respostas (banco antigo) → form_submissions (Conecta)
 * Roda manualmente ou via cron
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave inválida'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');

$pdo = db(); // Conecta

echo "=== Sincronizar Gastos Pensão (banco antigo → Conecta) ===\n\n";

// Conectar ao banco antigo
try {
    $pdoAntigo = new PDO(
        'mysql:host=localhost;dbname=ferre3151357_gastos_pensao;charset=utf8mb4',
        'ferre3151357_pensao_user',
        'Ar192114@',
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC)
    );
    echo "[OK] Conectado ao banco antigo\n\n";
} catch (Exception $e) {
    echo "[ERRO] Não consegui conectar ao banco antigo: " . $e->getMessage() . "\n";
    exit;
}

// Buscar todos os registros do banco antigo
$rows = $pdoAntigo->query("SELECT * FROM pensao_respostas ORDER BY created_at DESC")->fetchAll();
echo "Registros no banco antigo: " . count($rows) . "\n\n";

$sincronizados = 0;
$jaExiste = 0;
$erros = 0;

foreach ($rows as $r) {
    $protocolo = $r['protocolo'] ?? '';

    // Verificar se já existe no Conecta (por protocolo)
    $check = $pdo->prepare("SELECT id FROM form_submissions WHERE protocol = ? OR (form_type = 'gastos_pensao' AND client_name = ? AND DATE(created_at) = DATE(?))");
    $check->execute(array($protocolo, $r['nome_responsavel'] ?? '', $r['created_at'] ?? ''));
    if ($check->fetch()) {
        echo "[SKIP] {$protocolo} — {$r['nome_responsavel']} — já existe\n";
        $jaExiste++;
        continue;
    }

    // Montar payload_json com todos os dados
    $payload = array();
    foreach ($r as $key => $val) {
        if ($key === 'id' || $key === 'protocolo' || $key === 'created_at' || $key === 'ip' || $key === 'user_agent') continue;
        if ($val !== null && $val !== '') $payload[$key] = $val;
    }

    // Inserir no Conecta
    try {
        $pdo->prepare(
            "INSERT INTO form_submissions (form_type, protocol, client_name, client_phone, client_email, status, payload_json, ip_address, created_at)
             VALUES (?,?,?,?,?,?,?,?,?)"
        )->execute(array(
            'gastos_pensao',
            $protocolo ?: ('GST-SYNC-' . substr(md5(uniqid()), 0, 8)),
            $r['nome_responsavel'] ?? '',
            $r['whatsapp'] ?? '',
            '',
            'novo',
            json_encode($payload, JSON_UNESCAPED_UNICODE),
            $r['ip'] ?? '',
            $r['created_at'] ?? date('Y-m-d H:i:s'),
        ));
        echo "[OK] {$protocolo} — {$r['nome_responsavel']} — sincronizado\n";
        $sincronizados++;
    } catch (Exception $e) {
        echo "[ERRO] {$protocolo}: " . $e->getMessage() . "\n";
        $erros++;
    }
}

echo "\n=== RESULTADO ===\n";
echo "Total no banco antigo: " . count($rows) . "\n";
echo "Sincronizados agora: $sincronizados\n";
echo "Já existiam: $jaExiste\n";
echo "Erros: $erros\n";
echo "\n=== FIM ===\n";
