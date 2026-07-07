<?php
/**
 * Cron do Jorjão: resumo diário 19h do grupo WhatsApp com Claude Haiku.
 *
 * Config cron cPanel (todo dia às 19h):
 *   0 19 * * *  curl -s "https://ferreiraesa.com.br/conecta/cron/jorjao_resumo_diario.php?key=fsa-hub-deploy-2026"
 *
 * Killswitches lidos de configuracoes:
 *   - jorjao_resumo_diario_ativo  (0/1) — default 0
 *   - jorjao_resumo_diario_hora   (0-23) — default 19
 *   - jorjao_resumo_diario_min_msgs (int) — default 5 (não resumir dia fraco)
 *
 * Grupo alvo: mesmo do contrato assinado (comemoracao_contrato_grupo_id).
 * Custo Anthropic Haiku ~R$ 0,05/dia com 100-200 msgs.
 * Idempotente: se rodar 2x no mesmo dia, marca `jorjao_resumo_ultimo_em` e pula.
 */

$isCli = php_sapi_name() === 'cli';
if (!$isCli && ($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') {
    http_response_code(403); die('Acesso negado.');
}
$forcar = isset($_GET['forcar']) && $_GET['forcar'] === '1';

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/functions_zapi.php';
require_once __DIR__ . '/../core/functions_ia.php';
require_once __DIR__ . '/../core/functions_comemoracao.php';
require_once __DIR__ . '/../core/functions_jorjao.php';

if (!$isCli) { header('Content-Type: text/plain; charset=utf-8'); }
$pdo = db();
$hoje = date('Y-m-d');
echo "[" . date('Y-m-d H:i:s') . "] === jorjao_resumo_diario ===\n";

// Config
$ativo = (string)$pdo->query("SELECT valor FROM configuracoes WHERE chave='jorjao_resumo_diario_ativo'")->fetchColumn();
$horaAlvo = (int)$pdo->query("SELECT valor FROM configuracoes WHERE chave='jorjao_resumo_diario_hora'")->fetchColumn() ?: 19;
$minMsgs  = (int)$pdo->query("SELECT valor FROM configuracoes WHERE chave='jorjao_resumo_diario_min_msgs'")->fetchColumn() ?: 5;

if ($ativo !== '1' && !$forcar) { echo "KILLSWITCH DESLIGADO (jorjao_resumo_diario_ativo=0). Passe ?forcar=1 pra testar.\n"; exit; }

// Só roda se hora bate — evita spam se cPanel rodou fora de hora
if (!$forcar && (int)date('H') !== $horaAlvo) {
    echo "Hora atual (" . date('H') . ") != hora alvo ({$horaAlvo}). Pulando.\n";
    exit;
}

// Idempotência: se já rodou hoje, pula
$ultimo = (string)$pdo->query("SELECT valor FROM configuracoes WHERE chave='jorjao_resumo_ultimo_em'")->fetchColumn();
if (!$forcar && $ultimo === $hoje) { echo "Ja foi enviado hoje ({$hoje}). Pulando.\n"; exit; }

// Grupo alvo
$g = jorjao_grupo_config();
if (!$g['grupo_id'] || !in_array($g['canal'], array('21','24'), true)) {
    echo "Grupo/canal não configurado. Configure em /admin/comemorar_contrato.php primeiro.\n";
    exit;
}
echo "Grupo alvo: {$g['grupo_id']} (canal {$g['canal']})\n";

// Buscar conversa_id do grupo.
// Amanda 06/07/2026: config guarda com '@g.us' (necessário pra Z-API entregar
// no ENVIO — sem sufixo ela gera messageId sintético e nunca entrega). Mas em
// zapi_conversas.telefone o valor fica só o numérico (sem sufixo). Então
// normalizo antes de comparar: strip do sufixo pra bater com o que está no DB.
$grupoIdBusca = str_replace(array('@g.us','@s.whatsapp.net','@lid'), '', $g['grupo_id']);
$stConv = $pdo->prepare("SELECT co.id FROM zapi_conversas co
                        JOIN zapi_instancias i ON i.id = co.instancia_id
                        WHERE i.ddd = ? AND (co.telefone = ? OR co.telefone = ?)
                        LIMIT 1");
$stConv->execute(array($g['canal'], $grupoIdBusca, $g['grupo_id']));
$convId = (int)$stConv->fetchColumn();
if (!$convId) { echo "Conversa do grupo não encontrada no DB. O grupo teve movimento hoje?\n  (procurei por '{$grupoIdBusca}' e '{$g['grupo_id']}' no canal {$g['canal']})\n"; exit; }
echo "  Conversa do grupo: #{$convId}\n";

// Msgs do dia (últimas 500 pra proteger token)
$stMsg = $pdo->prepare("SELECT m.direcao, m.tipo, m.conteudo, m.created_at,
                              m.autor_nome, u.name AS user_nome
                       FROM zapi_mensagens m
                       LEFT JOIN users u ON u.id = m.enviado_por_id
                       WHERE m.conversa_id = ? AND DATE(m.created_at) = ?
                       ORDER BY m.created_at ASC
                       LIMIT 500");
$stMsg->execute(array($convId, $hoje));
$msgs = $stMsg->fetchAll(PDO::FETCH_ASSOC);
$totMsgs = count($msgs);
echo "Msgs do grupo hoje: {$totMsgs}\n";

if ($totMsgs < $minMsgs) {
    echo "Dia fraco (< {$minMsgs} msgs). Não resume.\n";
    exit;
}

// Monta transcrição pra IA (só texto — ignora audio/imagem/etc)
$transcricao = "";
$autoresVistos = array();
foreach ($msgs as $m) {
    if ($m['tipo'] !== 'texto' || !$m['conteudo']) continue;
    $hora = date('H:i', strtotime($m['created_at']));
    $quem = $m['user_nome'] ?: ($m['autor_nome'] ?: ($m['direcao'] === 'enviada' ? 'Equipe' : 'Cliente/Grupo'));
    $autoresVistos[$quem] = true;
    $txt = mb_substr(preg_replace('/\s+/', ' ', $m['conteudo']), 0, 400);
    $transcricao .= "[{$hora}] {$quem}: {$txt}\n";
}
$transcricao = mb_substr($transcricao, 0, 12000); // ~3k tokens no input

if (mb_strlen(trim($transcricao)) < 100) {
    echo "Transcricao muito curta (só audios/imagens?). Pula.\n";
    exit;
}

echo "Transcricao " . strlen($transcricao) . " chars. Autores: " . count($autoresVistos) . "\n";

// Prompt do Jorjão
$system = <<<PROMPT
Você é o "Jorjão", mascote do escritório Ferreira & Sá Advocacia. Personalidade:
- Tio brincalhão, animador de festa, narrador de futebol
- Usa gírias tipo "Bora!", "Craque!", "Fecha, campeão!", "Meu jovem"
- Emojis fartos: 🎉🔔🏆🚀⚖️💪🎯🥳
- Faz brincadeira com a rotina jurídica ("Bata o martelo!", "Golaço!", "Preclusão que nada!")
- Tom leve, animado, celebra o time

Sua missão: resumir o que aconteceu no grupo do WhatsApp do escritório HOJE.
Formato: 4-8 tópicos curtos com emoji, em bullet, mostrando o que rolou.
Destaque:
- Decisões importantes ⚖️
- Vitórias/comemorações 🏆
- Dúvidas em aberto ❓
- Combinados pendentes 📌
- Elogios pro time 👏

Assine no final com "🐻 Abraço do Jorjão".
NÃO invente informação — só use o que está na transcrição. Se algo não está claro, não force.
Máximo 12 linhas.
PROMPT;

$userMsg = "Aqui está a conversa do grupo hoje ({$hoje}):\n\n" . $transcricao . "\n\nFaz o resumo do dia no seu estilo.";

$resp = ia_chamar(
    'jorjao_resumo',
    'claude-haiku-4-5-20251001',
    $system,
    array(array('role' => 'user', 'content' => $userMsg)),
    array('max_tokens' => 600, 'temperature' => 0.9, 'bypass_killswitch' => true, 'bypass_user_whitelist' => true)
);

if (empty($resp['ok']) || empty($resp['texto'])) {
    echo "IA falhou: " . ($resp['erro'] ?? '?') . "\n";
    exit;
}

$resumoTexto = trim($resp['texto']);
echo "\n--- RESUMO GERADO ---\n{$resumoTexto}\n---\n";
echo "Tokens: in={$resp['input_tokens']} out={$resp['output_tokens']} custo=R$" . number_format($resp['custo_brl'], 4) . "\n";

// Envia no grupo
$msgFinal = "📆 *Resumo do dia — " . date('d/m/Y') . "* 📆\n\n" . $resumoTexto;
$rSend = zapi_send_text($g['canal'], $g['grupo_id'], $msgFinal);

if (!empty($rSend['ok'])) {
    echo "✓ Resumo enviado no grupo!\n";
    // Marca como enviado hoje
    $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES ('jorjao_resumo_ultimo_em', ?)
                   ON DUPLICATE KEY UPDATE valor = VALUES(valor)")->execute(array($hoje));
} else {
    echo "✕ Falhou envio: " . ($rSend['erro'] ?? '?') . "\n";
}
