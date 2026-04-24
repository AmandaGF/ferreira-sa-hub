<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('x'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== RESUMO backfill ===\n";
$total = (int)$pdo->query("SELECT COUNT(*) FROM clients WHERE phone IS NOT NULL AND phone != ''")->fetchColumn();
$comLid = (int)$pdo->query("SELECT COUNT(*) FROM clients WHERE whatsapp_lid IS NOT NULL AND whatsapp_lid != ''")->fetchColumn();
$checados = (int)$pdo->query("SELECT COUNT(*) FROM clients WHERE whatsapp_lid_checado_em IS NOT NULL")->fetchColumn();
$semWpp = $checados - $comLid;
echo "Total de clientes com telefone:     {$total}\n";
echo "Já checados via /phone-exists:      {$checados}\n";
echo "  → Com @lid (tem WhatsApp):        {$comLid}\n";
echo "  → Sem WhatsApp:                   {$semWpp}\n";
echo "Restantes (não checados):           " . ($total - $checados) . "\n";

echo "\n=== VARREDURA: conversas ativas com @lid DIVERGENTE do cadastro do cliente ===\n";
echo "(casos iguais ao da Alícia/Eduarda — conv vinculada a cliente cujo @lid real é outro)\n\n";

$q = $pdo->query(
    "SELECT co.id AS conv_id, co.canal, co.telefone AS conv_tel, co.chat_lid AS conv_lid,
            co.status AS conv_status,
            c.id AS cli_id, c.name AS cli_name, c.phone AS cli_phone, c.whatsapp_lid AS cli_lid
     FROM zapi_conversas co
     JOIN clients c ON c.id = co.client_id
     WHERE co.client_id IS NOT NULL
       AND co.chat_lid IS NOT NULL AND co.chat_lid != ''
       AND c.whatsapp_lid IS NOT NULL AND c.whatsapp_lid != ''
       AND co.chat_lid != c.whatsapp_lid
       AND co.status != 'arquivado'
     ORDER BY co.updated_at DESC"
);
$divergentes = $q->fetchAll();
echo "Total de conversas com divergência: " . count($divergentes) . "\n\n";

if ($divergentes) {
    foreach ($divergentes as $r) {
        echo "[DIVERGÊNCIA] conv #{$r['conv_id']} ({$r['conv_status']}) → client #{$r['cli_id']} {$r['cli_name']}\n";
        echo "  Conv.........: tel={$r['conv_tel']} chat_lid={$r['conv_lid']}\n";
        echo "  Cliente real.: tel={$r['cli_phone']} whatsapp_lid={$r['cli_lid']}\n";
        echo "  → Mensagens nessa conv foram pra PESSOA DIFERENTE do cliente cadastrado\n\n";
    }
}
