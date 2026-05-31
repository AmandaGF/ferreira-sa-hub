<?php
/**
 * toggle_fluxo_executor.php
 *
 * Liga/desliga o executor de fluxos no webhook (killswitch).
 *
 * Default: OFF (configuracoes.zapi_fluxo_executor_ativo = '0' ou ausente)
 *
 * Uso:
 *   ?status            - mostra estado atual
 *   ?on                - liga (seta '1')
 *   ?off               - desliga (seta '0')
 *
 * Exemplos:
 *   curl -s "https://ferreiraesa.com.br/conecta/toggle_fluxo_executor.php?key=fsa-hub-deploy-2026&status"
 *   curl -s "https://ferreiraesa.com.br/conecta/toggle_fluxo_executor.php?key=fsa-hub-deploy-2026&on"
 *   curl -s "https://ferreiraesa.com.br/conecta/toggle_fluxo_executor.php?key=fsa-hub-deploy-2026&off"
 */

if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';

$pdo = db();
$CHAVE = 'zapi_fluxo_executor_ativo';

// Garante a tabela configuracoes existe (deve existir; sanity defensiva)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS configuracoes (
        chave VARCHAR(80) PRIMARY KEY,
        valor TEXT NULL,
        atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

function get_estado($pdo, $chave) {
    try {
        $st = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = ? LIMIT 1");
        $st->execute(array($chave));
        $v = $st->fetchColumn();
        return ($v === false) ? null : (string)$v;
    } catch (Exception $e) { return null; }
}

function set_estado($pdo, $chave, $valor) {
    // UPDATE primeiro - se a chave ja existe, atualiza e retorna rowCount>=1
    try {
        $upd = $pdo->prepare("UPDATE configuracoes SET valor = ? WHERE chave = ?");
        $upd->execute(array($valor, $chave));
        if ($upd->rowCount() > 0) return true;
    } catch (Exception $e) {
        echo "[ERRO UPDATE] " . $e->getMessage() . "\n";
    }
    // Senao, INSERT. Tenta com colunas (chave, valor) e ignora se tabela tem outras.
    try {
        $ins = $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES (?, ?)");
        $ins->execute(array($chave, $valor));
        return true;
    } catch (Exception $e) {
        echo "[ERRO INSERT] " . $e->getMessage() . "\n";
    }
    return false;
}

if (isset($_GET['on'])) {
    set_estado($pdo, $CHAVE, '1');
    echo "Executor LIGADO. Webhook vai processar fluxos a partir da proxima msg recebida.\n";
} elseif (isset($_GET['off'])) {
    set_estado($pdo, $CHAVE, '0');
    echo "Executor DESLIGADO. Webhook ignora fluxos.\n";
}

$atual = get_estado($pdo, $CHAVE);
echo "\nEstado atual de '$CHAVE': " . ($atual === null ? '(nao definido = OFF por default)' : "'$atual'") . "\n";
echo "Resolvido: " . ($atual === '1' ? 'LIGADO' : 'DESLIGADO') . "\n";
