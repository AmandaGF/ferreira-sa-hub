<?php
/**
 * Copia payload_json REAL dos 3 registros de gastos que foram importados com '{}'
 * Lê de ferre3151357_gastos_pensao.pensao_respostas e atualiza em ferre3151357_conecta.form_submissions
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
set_time_limit(120);

require_once __DIR__ . '/core/config.php';

// Conectar no banco de gastos (mesmo servidor, mesmo user com acesso cruzado)
$host = defined('DB_HOST') ? DB_HOST : 'localhost';
$user = 'ferre3151357_admin';
$pass = 'Ar192114@';

$dryRun = !isset($_GET['executar']);
echo "=== COPIAR PAYLOAD GASTOS → CONECTA ===\n";
echo $dryRun ? ">>> MODO SIMULAÇÃO (adicione &executar) <<<\n\n" : ">>> EXECUTANDO <<<\n\n";

// Protocolos com payload vazio
$protocolos = array('A47179BA6973', 'D3AB1708779E', '508ACF582015');

try {
    // Conectar no banco de gastos
    $pdoGastos = new PDO(
        "mysql:host=$host;dbname=ferre3151357_gastos_pensao;charset=utf8mb4",
        $user, $pass,
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
    );
    echo "Conectado em ferre3151357_gastos_pensao OK\n\n";

    // Conectar no Conecta
    $pdoConecta = new PDO(
        "mysql:host=$host;dbname=ferre3151357_conecta;charset=utf8mb4",
        $user, $pass,
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
    );
    echo "Conectado em ferre3151357_conecta OK\n\n";

    $atualizados = 0;

    foreach ($protocolos as $proto) {
        echo "--- Protocolo: $proto ---\n";

        // Buscar na tabela original
        $stmt = $pdoGastos->prepare("SELECT * FROM pensao_respostas WHERE protocolo = ? LIMIT 1");
        $stmt->execute(array($proto));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            echo "  NÃO ENCONTRADO no gastos_pensao!\n\n";
            continue;
        }

        echo "  Encontrado: " . ($row['nome_responsavel'] ?? '?') . "\n";

        // Montar payload completo a partir de todas as colunas
        $payload = array();
        foreach ($row as $col => $val) {
            if ($col === 'id') continue;
            if ($val !== null && $val !== '') {
                $payload[$col] = $val;
            }
        }

        // Se já tem payload_json na origem, usar ele
        if (!empty($row['payload_json']) && $row['payload_json'] !== '{}') {
            $payloadJson = $row['payload_json'];
            echo "  Usando payload_json original (" . strlen($payloadJson) . " bytes)\n";
        } else {
            // Montar a partir das colunas
            $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
            echo "  Montado a partir das colunas (" . strlen($payloadJson) . " bytes)\n";
        }

        // Verificar no Conecta
        $chk = $pdoConecta->prepare("SELECT id, payload_json FROM form_submissions WHERE protocol = ? LIMIT 1");
        $chk->execute(array($proto));
        $existing = $chk->fetch(PDO::FETCH_ASSOC);

        if (!$existing) {
            echo "  NÃO ENCONTRADO no Conecta! Pulando.\n\n";
            continue;
        }

        $currentPayload = $existing['payload_json'] ?? '';
        echo "  Conecta ID #{$existing['id']} — payload atual: " . strlen($currentPayload) . " bytes\n";

        if ($currentPayload !== '{}' && $currentPayload !== '' && $currentPayload !== 'null') {
            echo "  JÁ TEM PAYLOAD! Pulando.\n\n";
            continue;
        }

        if ($dryRun) {
            echo "  [SIMULAÇÃO] Atualizaria com " . strlen($payloadJson) . " bytes\n\n";
        } else {
            $upd = $pdoConecta->prepare("UPDATE form_submissions SET payload_json = ? WHERE id = ?");
            $upd->execute(array($payloadJson, $existing['id']));
            echo "  [OK] Payload atualizado!\n\n";
            $atualizados++;
        }
    }

    echo "=== RESUMO ===\n";
    echo "Atualizados: $atualizados / " . count($protocolos) . "\n";
    if ($dryRun) echo "\n>>> Para executar: adicione &executar <<<\n";

} catch (PDOException $e) {
    echo "ERRO DB: " . $e->getMessage() . "\n";
}

echo "\n=== FIM ===\n";
