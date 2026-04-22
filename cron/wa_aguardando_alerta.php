<?php
/**
 * Cron: alerta via Web Push quando há conversa WhatsApp aguardando atendente há muito tempo.
 *
 * Dispara push pra admin+gestao+cx quando detecta conversa:
 *   - status = 'aguardando'
 *   - atendente_id IS NULL
 *   - última mensagem entrante há >= 15 minutos
 *   - ainda não avisado hoje (evita spam)
 *
 * Recomendado rodar a cada 5 minutos via cPanel cron:
 *   php /home/ferre315/public_html/conecta/cron/wa_aguardando_alerta.php
 * Ou via HTTP com chave:
 *   https://ferreiraesa.com.br/conecta/cron/wa_aguardando_alerta.php?key=fsa-hub-deploy-2026
 */

$isCli = php_sapi_name() === 'cli';
if (!$isCli && ($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
if (!$isCli) { header('Content-Type: text/plain; charset=utf-8'); }

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/functions_push.php';

$pdo = db();

// Self-heal: coluna de controle pra não spammar (1 alerta por dia por conversa)
try { $pdo->exec("ALTER TABLE zapi_conversas ADD COLUMN push_alertada_em DATETIME DEFAULT NULL"); } catch (Exception $e) {}

echo "=== WA Aguardando — alerta push ===\n";
echo "Execução: " . date('Y-m-d H:i:s') . "\n\n";

// Busca conversas aguardando há >= 15 min sem atendente e ainda não alertadas hoje
$stmt = $pdo->query(
    "SELECT co.id, co.telefone, co.nome_contato, co.canal, co.client_id, co.lead_id,
            cl.name AS client_name, pl.name AS lead_name,
            (SELECT m.created_at FROM zapi_mensagens m
             WHERE m.conversa_id = co.id AND m.direcao = 'recebida'
             ORDER BY m.id DESC LIMIT 1) AS ultima_recebida_em
     FROM zapi_conversas co
     LEFT JOIN clients cl ON cl.id = co.client_id
     LEFT JOIN pipeline_leads pl ON pl.id = co.lead_id
     WHERE co.status = 'aguardando'
       AND co.atendente_id IS NULL
       AND (co.eh_grupo = 0 OR co.eh_grupo IS NULL)
       AND (co.push_alertada_em IS NULL OR DATE(co.push_alertada_em) != CURDATE())
     HAVING ultima_recebida_em IS NOT NULL
        AND ultima_recebida_em <= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
     ORDER BY ultima_recebida_em ASC
     LIMIT 20"
);
$pendentes = $stmt->fetchAll();

echo "Conversas aguardando há 15+ min sem atendente: " . count($pendentes) . "\n\n";

$upd = $pdo->prepare("UPDATE zapi_conversas SET push_alertada_em = NOW() WHERE id = ?");

foreach ($pendentes as $conv) {
    $nome = $conv['client_name'] ?: ($conv['lead_name'] ?: ($conv['nome_contato'] ?: $conv['telefone']));
    $minutos = max(15, (int)((time() - strtotime($conv['ultima_recebida_em'])) / 60));
    $titulo = '⏳ WhatsApp aguardando há ' . $minutos . ' min';
    $corpo = $nome . ' (canal ' . $conv['canal'] . ') — ninguém assumiu ainda';
    $url = '/conecta/modules/whatsapp/?conv=' . $conv['id'];

    echo "  • #{$conv['id']} {$nome} — {$minutos}min — notificando...\n";

    try {
        $res = push_notify_role(array('admin','gestao','cx'), $titulo, $corpo, $url, true);
        $upd->execute(array($conv['id']));
        echo "    ✓ enviadas=" . (int)($res['sent'] ?? 0) . "\n";
    } catch (Exception $e) {
        echo "    ⚠️ erro: " . $e->getMessage() . "\n";
    }
}

echo "\n=== FIM ===\n";
