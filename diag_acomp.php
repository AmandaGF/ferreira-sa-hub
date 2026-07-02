<?php
if (($_GET['key']??'') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('no'); }
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Diag acompanhamento diário — " . date('d/m/Y H:i:s') . " ===\n\n";

// 1) Configs cadastradas
echo "--- Configs cadastradas ---\n";
try {
    $st = $pdo->query(
        "SELECT a.*, c.name AS client_name, c.phone AS client_phone, cs.title AS case_title
         FROM acompanhamento_msg_diario a
         JOIN clients c ON c.id = a.client_id
         JOIN cases cs ON cs.id = a.case_id
         ORDER BY a.id DESC"
    );
    $cfgs = $st->fetchAll(PDO::FETCH_ASSOC);
    echo "Total: " . count($cfgs) . "\n";
    foreach ($cfgs as $c) {
        echo "\n  cfg#{$c['id']} | ativo={$c['ativo']} | canal={$c['canal']} | horário={$c['horario_envio']} | dias_uteis_only={$c['dias_uteis_only']}\n";
        echo "  Cliente: {$c['client_name']} (id={$c['client_id']}) — tel={$c['client_phone']}\n";
        echo "  Caso: {$c['case_title']} (id={$c['case_id']})\n";
        echo "  Último envio: " . ($c['ultimo_envio_em'] ?: '(nunca)') . " | template_idx=" . ($c['ultimo_template_idx'] ?? '?') . "\n";
        echo "  Total envios: {$c['total_envios']}\n";
        echo "  Ultima_data_andamento_visto: " . ($c['ultima_data_andamento_visto'] ?: '(nunca)') . "\n";
        if (!empty($c['pausado_em'])) echo "  ⏸ PAUSADO em {$c['pausado_em']}: {$c['pausado_motivo']}\n";
    }
} catch (Exception $e) {
    echo "ERRO: " . $e->getMessage() . "\n";
}

// 2) Killswitch
echo "\n\n--- Killswitch ---\n";
$ks = $pdo->query("SELECT valor FROM configuracoes WHERE chave='acompanhamento_msg_diario_ativo'")->fetchColumn();
echo "  acompanhamento_msg_diario_ativo = " . ($ks === false ? '(não existe)' : "'{$ks}'") . "\n";

// 3) Estado dos canais Z-API
echo "\n--- Instâncias Z-API ---\n";
try {
    $ins = $pdo->query("SELECT ddd, nome, ativo, conectado, ultima_verificacao FROM zapi_instancias ORDER BY ddd")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($ins as $i) {
        $status = ($i['conectado'] == 1) ? '🟢 conectado' : '🔴 DESCONECTADO';
        echo "  canal {$i['ddd']} ({$i['nome']}): ativo={$i['ativo']} | {$status} | ultima_verif={$i['ultima_verificacao']}\n";
    }
} catch (Exception $e) { echo "  ERRO: " . $e->getMessage() . "\n"; }

// 4) Últimas mensagens enviadas pelo canal 24 (últimas 3h)
echo "\n--- Últimas 5 mensagens do canal 24 (enviada pelo Hub) ---\n";
try {
    $ms = $pdo->query(
        "SELECT m.id, m.created_at, m.direcao, m.status, m.zapi_message_id, LEFT(m.conteudo, 80) AS preview,
                co.telefone, co.nome_contato
         FROM zapi_mensagens m
         JOIN zapi_conversas co ON co.id = m.conversa_id
         WHERE co.canal='24' AND m.direcao='enviada' AND m.enviado_por_bot=0
         ORDER BY m.id DESC LIMIT 5"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($ms as $m) {
        $prev = str_replace(array("\n","\r"), ' | ', $m['preview']);
        echo "  #{$m['id']} em {$m['created_at']} status={$m['status']} msgid={$m['zapi_message_id']} tel={$m['telefone']} ({$m['nome_contato']})\n";
        echo "    {$prev}\n";
    }
} catch (Exception $e) { echo "  ERRO: " . $e->getMessage() . "\n"; }
