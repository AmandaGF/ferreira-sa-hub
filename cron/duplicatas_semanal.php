<?php
/**
 * Cron — Detector semanal de clientes duplicados
 *
 * Roda 1x por semana (segunda 8h). Detecta grupos por CPF, nome normalizado
 * e telefone normalizado. Compara com snapshot anterior em
 * configuracoes.duplicatas_ultimo_total. Se aumentou (ou primeira vez),
 * notifica admin/gestão via Hub.
 *
 * Frontend de merge: /modules/clientes/mesclar.php (já existente).
 *
 * Comando cron (cPanel via curl HTTP — projeto não roda CLI):
 *   curl https://ferreiraesa.com.br/conecta/cron/duplicatas_semanal.php?key=fsa-hub-deploy-2026
 */
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/functions_notify.php';

// Proteção mínima quando chamado via HTTP
if (php_sapi_name() !== 'cli') {
    $key = $_GET['key'] ?? '';
    if ($key !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
}

header('Content-Type: text/plain; charset=utf-8');
$pdo = db();
echo "=== Detector de Duplicatas — " . date('d/m/Y H:i') . " ===\n\n";

// ── 1. Detectar grupos ──
$dupsCpf = $pdo->query(
    "SELECT cpf, GROUP_CONCAT(id ORDER BY id) AS ids, COUNT(*) AS cnt
     FROM clients
     WHERE cpf IS NOT NULL AND cpf <> ''
     GROUP BY REPLACE(REPLACE(REPLACE(cpf,'.',''),'-',''),' ','')
     HAVING cnt > 1"
)->fetchAll(PDO::FETCH_ASSOC);

$dupsNome = $pdo->query(
    "SELECT UPPER(TRIM(REPLACE(REPLACE(REPLACE(name,'  ',' '),'  ',' '),'  ',' '))) AS nome_norm,
            GROUP_CONCAT(id ORDER BY id) AS ids, COUNT(*) AS cnt
     FROM clients
     WHERE name IS NOT NULL AND name <> ''
     GROUP BY nome_norm
     HAVING cnt > 1
     LIMIT 100"
)->fetchAll(PDO::FETCH_ASSOC);

$dupsTel = $pdo->query(
    "SELECT REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone,' ',''),'-',''),'(',''),')',''),'+','') AS tel_norm,
            GROUP_CONCAT(id ORDER BY id) AS ids, COUNT(*) AS cnt
     FROM clients
     WHERE phone IS NOT NULL AND phone <> '' AND LENGTH(phone) >= 8
     GROUP BY tel_norm
     HAVING cnt > 1
     LIMIT 100"
)->fetchAll(PDO::FETCH_ASSOC);

// Set de grupos únicos (mesma chave de ids = mesmo grupo, contar uma vez)
$gruposUnicos = array();
foreach (array_merge($dupsCpf, $dupsNome, $dupsTel) as $d) {
    $gruposUnicos[$d['ids']] = true;
}
$total = count($gruposUnicos);
$totCpf  = count($dupsCpf);
$totNome = count($dupsNome);
$totTel  = count($dupsTel);

echo "Grupos detectados: {$total} (CPF: {$totCpf}, nome: {$totNome}, telefone: {$totTel})\n";

// ── 2. Comparar com snapshot anterior ──
$snapshotAnterior = (int)$pdo->query("SELECT valor FROM configuracoes WHERE chave='duplicatas_ultimo_total'")->fetchColumn();
$diff = $total - $snapshotAnterior;
echo "Snapshot anterior: {$snapshotAnterior} | Diferença: " . ($diff >= 0 ? "+{$diff}" : $diff) . "\n";

// ── 3. Notificar se aumentou (ou primeira vez com mais de 5) ──
$deveNotificar = ($diff > 0) || ($snapshotAnterior === 0 && $total >= 5);
if ($deveNotificar) {
    $titulo = '🔁 Duplicatas de clientes detectadas';
    $msg = "Há {$total} grupo(s) de duplicatas: {$totCpf} por CPF, {$totNome} por nome igual, {$totTel} por telefone. ";
    if ($diff > 0) $msg .= "({$diff} novo(s) desde última verificação semanal.) ";
    $msg .= "Clique pra revisar e mesclar.";

    try {
        notify_admins($titulo, $msg, 'alerta', '/conecta/modules/clientes/mesclar.php', '🔁');
        notify_gestao($titulo, $msg, 'alerta', '/conecta/modules/clientes/mesclar.php', '🔁');
        echo "  ✓ Notificação enviada pra admins+gestão.\n";
    } catch (Exception $e) {
        echo "  ✗ Erro ao notificar: " . $e->getMessage() . "\n";
    }
} else {
    echo "  Nada novo desde a última semana. Sem notificação.\n";
}

// ── 4. Atualizar snapshot ──
try {
    $up = $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES ('duplicatas_ultimo_total', ?)
                         ON DUPLICATE KEY UPDATE valor = VALUES(valor)");
    $up->execute(array((string)$total));
    echo "  ✓ Snapshot atualizado pra {$total}.\n";
} catch (Exception $e) {
    echo "  ✗ Erro snapshot: " . $e->getMessage() . "\n";
}

echo "\n=== FIM ===\n";
