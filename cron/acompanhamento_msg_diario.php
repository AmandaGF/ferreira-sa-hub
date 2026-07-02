<?php
/**
 * Cron — envio diário de mensagem de acompanhamento pra clientes ansiosos.
 *
 * Regra: só envia quando NÃO houve andamento processual novo desde o último
 * envio (ou desde ontem, se nunca enviou). Escolhe template diferente do
 * último dia pra não parecer robô. Só dias úteis (feriados nacionais fixos
 * fora — móveis passa direto).
 *
 * Sugestão cron cPanel (a cada hora):
 *   0 * * * * curl -s "https://ferreiraesa.com.br/conecta/cron/acompanhamento_msg_diario.php?key=fsa-hub-deploy-2026"
 *
 * Killswitch: configuracoes.acompanhamento_msg_diario_ativo = '0' desliga tudo.
 */
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/functions_zapi.php';
require_once __DIR__ . '/../core/functions_acompanhamento.php';

if (php_sapi_name() !== 'cli') {
    $key = $_GET['key'] ?? '';
    if ($key !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
}

header('Content-Type: text/plain; charset=utf-8');
$pdo = db();
echo "=== Acompanhamento diário — " . date('d/m/Y H:i') . " ===\n\n";

// Killswitch
$killswitch = $pdo->query("SELECT valor FROM configuracoes WHERE chave='acompanhamento_msg_diario_ativo'")->fetchColumn();
if ($killswitch === '0') {
    echo "Killswitch DESLIGADO. Saindo.\n";
    exit;
}

$tsNow = time();
$hojeStr = date('Y-m-d');
$agoraMin = (int)date('H', $tsNow) * 60 + (int)date('i', $tsNow);
$weekday = (int)date('N', $tsNow); // 1=seg, 7=dom
$ehFeriado = acompanhamento_eh_feriado($tsNow);

echo "Agora: {$hojeStr} " . date('H:i', $tsNow) . " (weekday={$weekday}, feriado=" . ($ehFeriado?'sim':'nao') . ")\n\n";

// Só entre 6h e 20h — segurança
$horaAtual = (int)date('H', $tsNow);
if ($horaAtual < 6 || $horaAtual >= 21) {
    echo "Fora do janela horária (6-20h). Saindo.\n";
    exit;
}

// Busca configs ativas
try {
    $st = $pdo->query(
        "SELECT a.*, c.name AS client_name, c.phone AS client_phone, cs.title AS case_title
         FROM acompanhamento_msg_diario a
         JOIN clients c ON c.id = a.client_id
         JOIN cases cs ON cs.id = a.case_id
         WHERE a.ativo = 1"
    );
    $configs = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    echo "Tabela acompanhamento_msg_diario ainda não existe. Rode migrar_acompanhamento_diario.php\n";
    exit;
}

echo "Configs ativas: " . count($configs) . "\n\n";
$totEnv = 0; $totPul = 0; $totErr = 0;

foreach ($configs as $cfg) {
    $tag = "cfg#{$cfg['id']} {$cfg['client_name']}";
    echo "▸ {$tag}\n";

    // Se dias_uteis_only e hoje for sab/dom/feriado, pula
    if (!empty($cfg['dias_uteis_only']) && ($weekday >= 6 || $ehFeriado)) {
        echo "  ⊘ Pulado: fim de semana ou feriado (dias_uteis_only=1)\n";
        $totPul++; continue;
    }

    // Horário de envio (compara com agora — só envia se agora >= horário)
    $hrCfg = explode(':', (string)$cfg['horario_envio']);
    $minCfg = ((int)$hrCfg[0]) * 60 + ((int)($hrCfg[1] ?? 0));
    if ($agoraMin < $minCfg) {
        echo "  ⏳ Ainda não chegou a hora ({$cfg['horario_envio']})\n";
        $totPul++; continue;
    }

    // Já enviou hoje? Compara data de ultimo_envio_em com hoje
    if (!empty($cfg['ultimo_envio_em']) && date('Y-m-d', strtotime($cfg['ultimo_envio_em'])) === $hojeStr) {
        echo "  ⊘ Já enviou hoje às " . date('H:i', strtotime($cfg['ultimo_envio_em'])) . "\n";
        $totPul++; continue;
    }

    // Verifica se teve andamento novo desde ontem (ou desde último envio)
    $desde = !empty($cfg['ultima_data_andamento_visto'])
        ? $cfg['ultima_data_andamento_visto']
        : date('Y-m-d', strtotime('-1 day'));
    $teveAndamento = acompanhamento_teve_andamento_desde($pdo, (int)$cfg['case_id'], $desde);
    if ($teveAndamento) {
        echo "  📄 TEVE andamento novo desde {$desde} — não envia, só atualiza data\n";
        try {
            $pdo->prepare("UPDATE acompanhamento_msg_diario SET ultima_data_andamento_visto = ? WHERE id = ?")
                ->execute(array($hojeStr, (int)$cfg['id']));
        } catch (Exception $e) {}
        $totPul++; continue;
    }

    if (empty($cfg['client_phone'])) {
        echo "  ✗ Cliente sem telefone\n";
        $totErr++; continue;
    }

    // OK, vai enviar. Escolhe template diferente do último
    list($novoIdx, $tplFn) = acompanhamento_escolher_template(isset($cfg['ultimo_template_idx']) ? (int)$cfg['ultimo_template_idx'] : null);
    if (!is_callable($tplFn)) {
        echo "  ✗ Template inválido idx={$novoIdx}\n";
        $totErr++; continue;
    }

    // Bug r1 (02/07 Amanda): duas mensagens saíam quando o cron demorava e
    // outro cron começava. Fix: UPDATE atômico do ultimo_envio_em ANTES do
    // envio, com WHERE que exige "não ter enviado hoje". Se rowCount=0,
    // alguém já processou (ou outro cron rodando em paralelo) — pula.
    try {
        $upLock = $pdo->prepare(
            "UPDATE acompanhamento_msg_diario
             SET ultimo_envio_em = NOW(),
                 ultimo_template_idx = ?,
                 ultima_data_andamento_visto = ?,
                 total_envios = total_envios + 1
             WHERE id = ?
               AND (ultimo_envio_em IS NULL OR DATE(ultimo_envio_em) < CURDATE())"
        );
        $upLock->execute(array((int)$novoIdx, $hojeStr, (int)$cfg['id']));
        $locked = $upLock->rowCount();
    } catch (Exception $e) { $locked = 0; }

    if ($locked < 1) {
        echo "  ⊘ Reserva perdida (já enviado por outra execução hoje) — pula sem enviar\n";
        $totPul++; continue;
    }

    // Monta contexto rico (tipo de processo + polo oposto + saudação por hora)
    $nomeCliente = trim(explode(' ', trim($cfg['client_name']))[0]);
    $ctx = acompanhamento_montar_contexto_caso($pdo, (int)$cfg['case_id'], $nomeCliente, (string)($cfg['obs'] ?? ''), $tsNow);
    $texto = $tplFn($ctx);

    $canal = ($cfg['canal'] === '21') ? '21' : '24';
    $r = zapi_send_text($canal, $cfg['client_phone'], $texto);
    if (!empty($r['ok'])) {
        echo "  ✓ Enviado (canal={$canal} tpl={$novoIdx} tipo='{$ctx['tipo_processo']}' polo='{$ctx['polo_oposto']}')\n";
        $totEnv++;
    } else {
        // Rollback do lock: reverte para não bloquear tentativa nova mais tarde
        // (mas se falhar aqui, aceita o "buraco" — não é crítico)
        try {
            $pdo->prepare(
                "UPDATE acompanhamento_msg_diario
                 SET ultimo_envio_em = NULL,
                     total_envios = GREATEST(0, total_envios - 1)
                 WHERE id = ?"
            )->execute(array((int)$cfg['id']));
        } catch (Exception $e) {}
        $erro = isset($r['erro']) ? $r['erro'] : 'desconhecido';
        echo "  ✗ Falha Z-API: {$erro} (lock revertido)\n";
        $totErr++;
    }
}

echo "\n═════════\n";
echo "Enviados: {$totEnv} | Pulados: {$totPul} | Erros: {$totErr}\n";
