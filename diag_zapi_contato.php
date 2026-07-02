<?php
/**
 * Diagnóstico READ-ONLY de uma conversa específica por nome/telefone.
 * Mostra: telefone salvo (detecta @lid), chat_lid, canal, status, client vinculado,
 * últimas mensagens com direção+status, e checa /phone-exists do número real.
 *
 * Não escreve nada. Só leitura + 1 GET na Z-API (phone-exists).
 *
 *   curl -s "https://ferreiraesa.com.br/conecta/diag_zapi_contato.php?key=fsa-hub-deploy-2026&q=jane"
 *   curl -s "...&q=taina"
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
set_time_limit(60);
ini_set('display_errors', '1');

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_zapi.php';
$pdo = db();

$q = trim($_GET['q'] ?? '');
if ($q === '') { die("Passe &q=<nome ou telefone>\n"); }

echo "=== DIAGNÓSTICO CONTATO: \"{$q}\" ===\n\n";

$like = '%' . $q . '%';
$sql = "SELECT cv.*, cl.name AS cliente_nome
        FROM zapi_conversas cv
        LEFT JOIN clients cl ON cl.id = cv.client_id
        WHERE cv.nome_contato LIKE ? OR cv.telefone LIKE ? OR cl.name LIKE ?
        ORDER BY cv.ultima_msg_em DESC
        LIMIT 10";
$st = $pdo->prepare($sql);
$st->execute(array($like, $like, $like));
$convs = $st->fetchAll(PDO::FETCH_ASSOC);

if (!$convs) { echo "Nenhuma conversa encontrada com \"{$q}\".\n"; exit; }

echo count($convs) . " conversa(s) encontrada(s):\n\n";

foreach ($convs as $cv) {
    $tel = (string)$cv['telefone'];
    $ehLid = (stripos($tel, '@lid') !== false || stripos($tel, '@s.whatsapp.net') !== false);
    echo "────────────────────────────────────────\n";
    echo "conv id={$cv['id']}  canal={$cv['canal']}  status={$cv['status']}  bot_ativo=" . ($cv['bot_ativo'] ?? '?') . "\n";
    echo "nome_contato=\"{$cv['nome_contato']}\"  cliente_vinc=\"" . ($cv['cliente_nome'] ?? '(nenhum)') . "\" (client_id=" . ($cv['client_id'] ?? '0') . ")\n";
    echo "telefone=\"{$tel}\"" . ($ehLid ? "  ⚠️ É UM @lid/jid — envio pode virar FANTASMA (200 mas não entrega)!" : "") . "\n";
    echo "chat_lid=\"" . ($cv['chat_lid'] ?? '') . "\"  eh_grupo=" . ($cv['eh_grupo'] ?? '0') . "\n";
    echo "ultima_msg_em={$cv['ultima_msg_em']}  nao_lidas=" . ($cv['nao_lidas'] ?? '?') . "\n";

    // Últimas 8 mensagens
    $ms = $pdo->prepare("SELECT id, direcao, tipo, status, zapi_message_id, LEFT(conteudo,60) c, created_at
                         FROM zapi_mensagens WHERE conversa_id = ? ORDER BY id DESC LIMIT 8");
    $ms->execute(array($cv['id']));
    $msgs = $ms->fetchAll(PDO::FETCH_ASSOC);
    echo "  últimas mensagens (mais nova primeiro):\n";
    foreach ($msgs as $m) {
        $temId = $m['zapi_message_id'] ? 'zid=sim' : 'zid=VAZIO';
        echo "    #{$m['id']} [{$m['direcao']}/{$m['tipo']}] status={$m['status']} {$temId} {$m['created_at']}  \"" . str_replace("\n"," ",$m['c']) . "\"\n";
    }

    // Checa phone-exists no número real (só se não for lid/grupo)
    $digits = preg_replace('/\D/', '', $tel);
    if (!$ehLid && ($cv['eh_grupo'] ?? 0) == 0 && strlen($digits) >= 10) {
        $pe = zapi_phone_exists($cv['canal'], $digits);
        echo "  phone-exists({$digits} via canal {$cv['canal']}): "
           . "exists=" . var_export($pe['exists'] ?? null, true)
           . "  http=" . ($pe['http_code'] ?? '?')
           . (isset($pe['erro']) && $pe['erro'] ? "  erro={$pe['erro']}" : "")
           . (isset($pe['phone']) ? "  phone_norm={$pe['phone']}" : "") . "\n";
    }
    echo "\n";
}

echo "=== FIM ===\n";
