<?php
/**
 * Cron: alerta push pros responsáveis quando ofícios pendentes estão parados há muito tempo.
 *
 * Dispara notificação quando:
 *   - status_oficio IN ('aguardando_contato_rh', 'oficio_enviado', 'em_cobranca')
 *   - ultima_atividade_em <= 7 dias atrás
 *   - alerta_cobranca_em é NULL ou <= 3 dias atrás (pra não spammar diariamente)
 *   - tem data_envio preenchida (ofício realmente enviado)
 *
 * Recomendado rodar 1x por dia via cPanel:
 *   0 9 * * * php /home/ferre315/public_html/conecta/cron/oficios_cobranca.php
 */

$isCli = php_sapi_name() === 'cli';
if (!$isCli && ($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
if (!$isCli) { header('Content-Type: text/plain; charset=utf-8'); }

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/functions_push.php';

$pdo = db();

// Self-heal preventivo (caso o cron rode antes da primeira abertura do módulo)
try { $pdo->exec("ALTER TABLE oficios_enviados ADD COLUMN status_oficio VARCHAR(40) DEFAULT 'aguardando_contato_rh'"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE oficios_enviados ADD COLUMN ultima_atividade_em DATETIME DEFAULT NULL"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE oficios_enviados ADD COLUMN alerta_cobranca_em DATETIME DEFAULT NULL"); } catch (Exception $e) {}

echo "=== Cron: Ofícios em cobrança ===\n";
echo "Execução: " . date('Y-m-d H:i:s') . "\n\n";

$statusAtivos = array('aguardando_contato_rh', 'oficio_enviado', 'em_cobranca');
$in = "'" . implode("','", $statusAtivos) . "'";

$sql = "SELECT o.id, o.empregador, o.empresa_cnpj, o.funcionario_nome, o.status_oficio,
               o.data_envio, o.ultima_atividade_em, o.case_id, o.client_id,
               DATEDIFF(NOW(), COALESCE(o.ultima_atividade_em, o.data_envio, o.created_at)) AS dias_parado,
               c.title AS case_title, cl.name AS client_name
        FROM oficios_enviados o
        LEFT JOIN cases c ON c.id = o.case_id
        LEFT JOIN clients cl ON cl.id = o.client_id
        WHERE o.status_oficio IN ($in)
          AND o.data_envio IS NOT NULL
          AND COALESCE(o.ultima_atividade_em, o.data_envio, o.created_at) <= DATE_SUB(NOW(), INTERVAL 7 DAY)
          AND (o.alerta_cobranca_em IS NULL OR o.alerta_cobranca_em <= DATE_SUB(NOW(), INTERVAL 3 DAY))
        ORDER BY dias_parado DESC";

$pendentes = $pdo->query($sql)->fetchAll();
echo "Ofícios pendentes há 7+ dias sem atividade: " . count($pendentes) . "\n\n";

$upd = $pdo->prepare("UPDATE oficios_enviados SET alerta_cobranca_em = NOW() WHERE id = ?");

foreach ($pendentes as $of) {
    $dias = (int)$of['dias_parado'];
    $titulo = '⏰ Ofício parado há ' . $dias . ' dias — cobrar?';
    $corpo = $of['empregador'] . ' · ' . ($of['client_name'] ?: $of['funcionario_nome'] ?: 'Pensão')
           . ' — status: ' . $of['status_oficio'];
    $url = '/conecta/modules/oficios/novo_oficio.php?id=' . $of['id'];

    echo "  • Ofício #{$of['id']} {$of['empregador']} ({$dias}d parado) → push admin+gestao\n";
    try {
        push_notify_role(array('admin','gestao'), $titulo, $corpo, $url, false);
        $upd->execute(array($of['id']));
    } catch (Exception $e) {
        echo "    ⚠️ erro push: " . $e->getMessage() . "\n";
    }
}

echo "\n=== FIM ===\n";
