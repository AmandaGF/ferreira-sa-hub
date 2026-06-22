<?php
/**
 * Ferreira & Sá Conecta — CRM Comercial + cobrança de leads sem resposta (canal 21).
 *
 * Reforço comercial (22/06/2026): equipe maior, precisa de acompanhamento.
 *  - Página CRM Comercial (modules/crm_comercial) lista quem está PENDENTE DE RESPOSTA
 *    (última msg do lead) e quem precisa de FOLLOW-UP (última msg nossa, lead sumiu).
 *  - Cron cron/comercial_cobranca.php cobra o responsável quando o lead está há +5 min
 *    sem resposta (mesmo fluxo de notificação de lead novo) e avisa no grupo do WhatsApp.
 *
 * "Responsável" de uma conversa do canal 21 é volátil (atendente_id expira em 30 min),
 * então resolvemos por: atendente_id → quem mandou a última msg nossa → assigned_to do
 * pipeline → (nenhum) gestão.
 */

require_once __DIR__ . '/functions_notify.php';
require_once __DIR__ . '/functions_push.php';
require_once __DIR__ . '/functions_zapi.php';

/** Cria as tabelas de apoio se não existirem (idempotente). */
function comercial_self_heal($pdo)
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS comercial_lead_obs (
            conversa_id INT NOT NULL PRIMARY KEY,
            lead_id INT NULL,
            observacao TEXT NULL,
            proximo_followup DATE NULL,
            status VARCHAR(20) NULL,
            atualizado_por INT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {}
    // status: 'aquecendo' (fixa no follow-up) | 'resolvido' (sai do follow-up) | NULL
    try { $pdo->exec("ALTER TABLE comercial_lead_obs ADD COLUMN status VARCHAR(20) NULL"); } catch (Exception $e) {}
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS comercial_cobranca (
            conversa_id INT NOT NULL PRIMARY KEY,
            ultima_msg_id INT NOT NULL,
            responsavel_id INT NULL,
            alertado_em DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {}
}

/** Lê as configs (chave/valor) do módulo, com defaults. */
function comercial_cfg($pdo)
{
    $cfg = array(
        'ativo'           => '0',   // cobrança automática ligada?
        'grupo_id'        => '',     // ID do grupo WhatsApp (…@g.us ou …-group)
        'grupo_canal'     => '21',
        'min'             => '5',    // minutos sem resposta pra cobrar
        'grupo_ultimo_em' => '',
        'bomdia_data'     => '',     // último dia em que a msg de "bom dia" foi enviada
    );
    try {
        $rows = $pdo->query("SELECT chave, valor FROM configuracoes
                             WHERE chave LIKE 'comercial_cobranca_%' OR chave = 'comercial_grupo_id'")->fetchAll();
        foreach ($rows as $r) {
            if ($r['chave'] === 'comercial_cobranca_ativo')           $cfg['ativo'] = $r['valor'];
            elseif ($r['chave'] === 'comercial_grupo_id')             $cfg['grupo_id'] = $r['valor'];
            elseif ($r['chave'] === 'comercial_cobranca_canal')       $cfg['grupo_canal'] = $r['valor'];
            elseif ($r['chave'] === 'comercial_cobranca_min')         $cfg['min'] = $r['valor'];
            elseif ($r['chave'] === 'comercial_cobranca_grupo_ultimo_em') $cfg['grupo_ultimo_em'] = $r['valor'];
            elseif ($r['chave'] === 'comercial_cobranca_bomdia_data')     $cfg['bomdia_data'] = $r['valor'];
        }
    } catch (Exception $e) {}
    return $cfg;
}

/** Salva uma config chave/valor. */
function comercial_set_cfg($pdo, $chave, $valor)
{
    $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES (?, ?)
                   ON DUPLICATE KEY UPDATE valor = VALUES(valor)")
        ->execute(array($chave, $valor));
}

/** Resolve o user_id responsável por uma linha de conversa (ou null). */
function comercial_responsavel_id($row)
{
    if (!empty($row['atendente_id']))  return (int)$row['atendente_id'];
    if (!empty($row['ultimo_resp_id'])) return (int)$row['ultimo_resp_id'];
    if (!empty($row['assigned_to']))   return (int)$row['assigned_to'];
    return null;
}

/**
 * Busca conversas do canal 21 cuja ÚLTIMA mensagem tem a $direcao informada.
 *  - 'recebida' = última foi do lead  → devemos resposta (pendentes / cobrança)
 *  - 'enviada'  = última foi nossa     → lead sumiu (follow-up)
 *
 * @param int $diasMax    janela em dias sobre created_at da conversa (0 = sem limite)
 * @param int $minMinutos exige que a última msg seja MAIS VELHA que N min (0 = ignora)
 * @param int $maxMinutos exige que a última msg seja MAIS NOVA que N min (0 = ignora) —
 *                        usado pela cobrança pra só perseguir conversas recentes
 * @param string|null $statusEq se informado (ex: 'aquecendo'), ignora direção/janela e
 *                        traz só as conversas com esse status manual (lo.status)
 */
function comercial_fetch($pdo, $direcao, $diasMax = 45, $minMinutos = 0, $maxMinutos = 0, $limit = 300, $statusEq = null)
{
    if ($statusEq !== null) {
        // Modo "fixado": traz as conversas marcadas com esse status, independente de
        // direção da última msg ou janela de tempo (ex: leads "aquecendo").
        $where  = "co.canal = '21' AND (co.eh_grupo = 0 OR co.eh_grupo IS NULL) AND lo.status = ?";
        $params = array($statusEq);
    } else {
        $where  = "co.canal = '21' AND (co.eh_grupo = 0 OR co.eh_grupo IS NULL)
                   AND co.status NOT IN ('resolvido','arquivado') AND lm.direcao = ?";
        $params = array($direcao);
        if ($diasMax > 0)    $where .= " AND co.created_at >= DATE_SUB(NOW(), INTERVAL " . (int)$diasMax . " DAY)";
        if ($minMinutos > 0) $where .= " AND lm.created_at <= DATE_SUB(NOW(), INTERVAL " . (int)$minMinutos . " MINUTE)";
        if ($maxMinutos > 0) $where .= " AND lm.created_at >= DATE_SUB(NOW(), INTERVAL " . (int)$maxMinutos . " MINUTE)";
    }

    $sql = "SELECT co.id AS conversa_id, co.telefone, co.nome_contato, co.atendente_id,
                   co.lead_id, co.client_id, co.created_at AS conversa_em, co.status,
                   cl.name AS client_name, pl.name AS lead_name, pl.assigned_to, pl.stage, pl.case_type,
                   lm.id AS ultima_msg_id, lm.direcao AS ultima_direcao,
                   lm.created_at AS ultima_em, lm.conteudo AS ultima_texto,
                   (SELECT mm.enviado_por_id FROM zapi_mensagens mm
                     WHERE mm.conversa_id = co.id AND mm.direcao = 'enviada' AND mm.enviado_por_id IS NOT NULL
                     ORDER BY mm.id DESC LIMIT 1) AS ultimo_resp_id,
                   (SELECT MAX(mm.created_at) FROM zapi_mensagens mm
                     WHERE mm.conversa_id = co.id AND mm.direcao = 'enviada') AS ultima_nossa_em,
                   lo.observacao, lo.proximo_followup, lo.status
            FROM zapi_conversas co
            JOIN (
                SELECT m.conversa_id, m.id, m.direcao, m.created_at, m.conteudo
                FROM zapi_mensagens m
                JOIN (SELECT conversa_id, MAX(id) AS maxid FROM zapi_mensagens GROUP BY conversa_id) x
                  ON x.conversa_id = m.conversa_id AND x.maxid = m.id
            ) lm ON lm.conversa_id = co.id
            LEFT JOIN clients cl ON cl.id = co.client_id
            LEFT JOIN pipeline_leads pl ON pl.id = co.lead_id
            LEFT JOIN comercial_lead_obs lo ON lo.conversa_id = co.id
            WHERE $where
            ORDER BY lm.created_at ASC
            LIMIT " . (int)$limit;

    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

/** Mapa id→nome de todos os usuários (cacheado por request). */
function comercial_users_map($pdo)
{
    static $map = null;
    if ($map === null) {
        $map = array();
        foreach ($pdo->query("SELECT id, name FROM users")->fetchAll() as $u) {
            $map[(int)$u['id']] = $u['name'];
        }
    }
    return $map;
}

/** Horário comercial da cobrança: 9h–18h, de segunda a sexta. */
function comercial_em_horario()
{
    $h  = (int)date('H');
    $wd = (int)date('N'); // 1=seg … 7=dom
    return ($wd <= 5) && ($h >= 9) && ($h < 18);
}

/** Um timestamp caiu FORA do horário comercial? (antes das 9h, das 18h em diante, ou fim de semana) */
function comercial_fora_horario_ts($dt)
{
    if (!$dt) return false;
    $t  = strtotime($dt);
    $h  = (int)date('H', $t);
    $wd = (int)date('N', $t);
    return ($wd > 5) || ($h < 9) || ($h >= 18);
}

/**
 * Mensagem motivacional de "bom dia" — lista os leads que mandaram mensagem FORA do
 * horário comercial e seguem esperando resposta. Tom leve, com variações.
 */
function comercial_msg_bomdia($leads, $umap)
{
    $linhas = array();
    foreach ($leads as $r) {
        $nome   = $r['lead_name'] ? $r['lead_name'] : ($r['client_name'] ? $r['client_name'] : ($r['nome_contato'] ? $r['nome_contato'] : $r['telefone']));
        $respId = comercial_responsavel_id($r);
        $resp   = $respId ? (isset($umap[$respId]) ? trim(strtok($umap[$respId], ' ')) : ('#' . $respId)) : 'sem dono';
        $linhas[] = '• ' . $nome . ' — ' . $resp;
    }
    $link = 'ferreiraesa.com.br/conecta/modules/crm_comercial/';
    $aberturas = array(
        "☀️ *Bom dia, time dos sonhos!* Novo dia, novas conversões 💚",
        "🌅 *Bom dia, craques!* Bora fazer hoje valer a pena 🚀",
        "☀️ *Oi oi, time!* Cafézinho na mão e foco no cliente ☕💛",
        "🌞 *Bom dia!* Quem começa cedo abraça mais leads 😄",
        "☀️ *Bom dia, gente boa!* Hoje tem cliente novo esperando carinho 💚",
    );
    $msg  = $aberturas[array_rand($aberturas)] . "\n\n";
    $msg .= "Chegou gente *fora do horário* que tá esperando uma atenção logo cedo:\n\n";
    $msg .= implode("\n", $linhas) . "\n\n";
    $msg .= "Bora começar o dia no capricho! 👉 " . $link;
    return $msg;
}

/**
 * Monta a mensagem do grupo — divertida, empática e com VARIAÇÕES (sorteada a cada envio).
 * {lista} = "Nativânia (4), Maria (2)".  Tom: leve, motivador, sem cobrar feio.
 */
function comercial_msg_grupo($partes)
{
    $lista = implode(', ', $partes);
    $link  = 'ferreiraesa.com.br/conecta/modules/crm_comercial/';
    $vars = array(
        "👋 Opa, time! Tem gente esperando um alô de vocês 💚\n\n*Aguardando resposta:* {lista}\n\nBora fazer a mágica acontecer? 👉 {link}",
        "⏰ Psiu! Uns leads estão de olho no celular esperando 👀\n\n*Na espera:* {lista}\n\nUma resposta rapidinha muda tudo! 🚀\n{link}",
        "🌟 Fala, craques! Cada retorno rápido é um cliente mais feliz.\n\n*Esperando carinho:* {lista}\n\nBora abraçar esses leads 👉 {link}",
        "🔔 Toc toc! Tem lead querendo papo com vocês:\n\n{lista}\n\nVamo que vamo, vocês são fera! 💪\n{link}",
        "💬 Alguém chamou? Chegou gente nova querendo conversar:\n\n{lista}\n\nResponde com aquele jeitinho F&S 💛 {link}",
        "🚀 Combinado é combinado: ninguém fica no vácuo, né?\n\n*Aguardando:* {lista}\n\nDá aquele retorno e arrasa 👉 {link}",
        "😄 Ó, chegou cliente! Leadzinhos na espera:\n\n{lista}\n\nUm oi rapidinho que eles amam 💚\n{link}",
        "🧡 Fala, time dos sonhos! Tem gente contando com vocês:\n\n*Esperando resposta:* {lista}\n\nBora transformar conversa em cliente 👉 {link}",
        "👀 Psssiu... uns leads soltaram um 'oi' e tão esperando o de vocês:\n\n{lista}\n\nResponde rapidinho e faz a diferença! ✨ {link}",
        "💚 Lembrete fofo: cada minuto conta pra quem está esperando.\n\n*Na fila do carinho:* {lista}\n\nBora lá, vocês mandam bem demais! 👉 {link}",
    );
    $msg = $vars[array_rand($vars)];
    return str_replace(array('{lista}', '{link}'), array($lista, $link), $msg);
}

/**
 * Motor da cobrança. Notifica o responsável de cada lead pendente (+5 min sem resposta)
 * e, no máximo 1×/30min em horário comercial, manda o resumo no grupo.
 *
 * @param array $opts forcar_horario(bool), ignorar_ativo(bool), dry(bool)
 * @return array relatório
 */
function comercial_rodar_cobranca($pdo, $opts = array())
{
    comercial_self_heal($pdo);
    $forcarHorario = !empty($opts['forcar_horario']);
    $ignorarAtivo  = !empty($opts['ignorar_ativo']);
    $dry           = !empty($opts['dry']);

    $rep = array('ativo' => true, 'horario_ok' => true, 'pendentes' => 0,
                 'notificados' => 0, 'grupo_enviado' => false, 'detalhe' => array());

    $cfg = comercial_cfg($pdo);
    if ($cfg['ativo'] !== '1' && !$ignorarAtivo) { $rep['ativo'] = false; return $rep; }

    // Cobrança só roda em horário comercial (9h–18h, seg–sex). Fora disso, ninguém é cobrado.
    if (!comercial_em_horario() && !$forcarHorario) {
        $rep['horario_ok'] = false;
        return $rep;
    }

    $umap = comercial_users_map($pdo);

    // ── "Bom dia": 1×/dia, no 1º run do horário comercial (depois das 9h) ──
    // Lista os leads que mandaram mensagem FORA do horário e seguem pendentes.
    if (!$dry && !empty($cfg['grupo_id'])) {
        $hoje = date('Y-m-d');
        if (($cfg['bomdia_data'] ?? '') !== $hoje) {
            $foraPend = array();
            foreach (comercial_fetch($pdo, 'recebida', 0, 1, 72 * 60, 200) as $fr) {
                if (comercial_fora_horario_ts($fr['ultima_em'])) $foraPend[] = $fr;
            }
            if ($foraPend) {
                $canalBd = $cfg['grupo_canal'] ? $cfg['grupo_canal'] : '21';
                $rb = zapi_send_text($canalBd, $cfg['grupo_id'], comercial_msg_bomdia($foraPend, $umap));
                if (!empty($rb['ok'])) {
                    $rep['bomdia_enviado'] = true;
                    // segura a msg "normal" do grupo por 30min pra não duplicar logo após o bom dia
                    comercial_set_cfg($pdo, 'comercial_cobranca_grupo_ultimo_em', date('Y-m-d H:i:s'));
                    $cfg['grupo_ultimo_em'] = date('Y-m-d H:i:s');
                }
            }
            comercial_set_cfg($pdo, 'comercial_cobranca_bomdia_data', $hoje);
        }
    }

    $min  = max(1, (int)$cfg['min']);
    // Cobrança só persegue conversas RECENTES (última msg do lead nas últimas 48h).
    // O backlog mais antigo fica pra revisão manual no CRM Comercial (janela de 45 dias).
    $rows = comercial_fetch($pdo, 'recebida', 0, $min, 48 * 60, 200);
    $rep['pendentes'] = count($rows);
    if (!$rows) return $rep;

    // estado de dedup: já cobrei esta conversa por esta mensagem?
    $jaCobrado = array();
    foreach ($pdo->query("SELECT conversa_id, ultima_msg_id FROM comercial_cobranca")->fetchAll() as $c) {
        $jaCobrado[(int)$c['conversa_id']] = (int)$c['ultima_msg_id'];
    }
    $upsert = $pdo->prepare("INSERT INTO comercial_cobranca (conversa_id, ultima_msg_id, responsavel_id, alertado_em)
                             VALUES (?, ?, ?, NOW())
                             ON DUPLICATE KEY UPDATE ultima_msg_id = VALUES(ultima_msg_id),
                                                     responsavel_id = VALUES(responsavel_id), alertado_em = NOW()");

    $porResp = array(); // resp_id (0 = sem dono) => qtd de pendentes (pro grupo)
    foreach ($rows as $r) {
        $convId = (int)$r['conversa_id'];
        $respId = comercial_responsavel_id($r);
        $key    = $respId ? $respId : 0;
        $porResp[$key] = isset($porResp[$key]) ? $porResp[$key] + 1 : 1;

        // só notifica se a última mensagem é NOVA (não cobrar a mesma msg de novo)
        $jaMsg = isset($jaCobrado[$convId]) ? $jaCobrado[$convId] : 0;
        if ($jaMsg === (int)$r['ultima_msg_id']) continue;

        $nome  = $r['lead_name'] ? $r['lead_name'] : ($r['client_name'] ? $r['client_name'] : ($r['nome_contato'] ? $r['nome_contato'] : $r['telefone']));
        $mins  = max($min, (int)((time() - strtotime($r['ultima_em'])) / 60));
        $titulo = '🔥 Lead aguardando resposta';
        $corpo  = $nome . ' mandou mensagem há ' . $mins . ' min e ainda não foi respondido.';
        $link   = '/conecta/modules/whatsapp/?abrir=' . $convId . '&canal=21';

        if (!$dry) {
            if ($respId) {
                if (function_exists('notify')) notify($respId, $titulo, $corpo, 'urgencia', $link, '🔥');
                if (function_exists('push_notify')) { try { push_notify($respId, $titulo, $corpo, $link, true); } catch (Exception $e) {} }
            } else {
                if (function_exists('notify_gestao')) notify_gestao($titulo . ' (sem responsável)', $corpo, 'urgencia', $link, '🔥');
                if (function_exists('push_notify_role')) { try { push_notify_role(array('admin','gestao'), $titulo, $corpo, $link, true); } catch (Exception $e) {} }
            }
            $upsert->execute(array($convId, (int)$r['ultima_msg_id'], $respId));
        }
        $rep['notificados']++;
        $rep['detalhe'][] = array(
            'conversa'    => $convId,
            'nome'        => $nome,
            'responsavel' => $respId ? (isset($umap[$respId]) ? $umap[$respId] : ('#' . $respId)) : 'sem responsável',
            'min'         => $mins,
        );
    }

    // ── Mensagem no grupo (nomes no texto, máx 1×/30min em horário comercial) ──
    if (!empty($cfg['grupo_id']) && $porResp) {
        $ultimo   = $cfg['grupo_ultimo_em'] ? strtotime($cfg['grupo_ultimo_em']) : 0;
        $podeGrupo = $forcarHorario || ((time() - $ultimo) >= 30 * 60);
        if ($podeGrupo) {
            arsort($porResp);
            $partes = array();
            foreach ($porResp as $rid => $qt) {
                $nm = $rid ? (isset($umap[$rid]) ? $umap[$rid] : ('#' . $rid)) : 'sem responsável';
                $nm = trim(strtok($nm, ' ')); // primeiro nome
                $partes[] = $nm . ' (' . $qt . ')';
            }
            $msg = comercial_msg_grupo($partes);

            if (!$dry) {
                $canal = $cfg['grupo_canal'] ? $cfg['grupo_canal'] : '21';
                $res = zapi_send_text($canal, $cfg['grupo_id'], $msg);
                if (!empty($res['ok'])) {
                    comercial_set_cfg($pdo, 'comercial_cobranca_grupo_ultimo_em', date('Y-m-d H:i:s'));
                    $rep['grupo_enviado'] = true;
                } else {
                    $rep['grupo_erro'] = isset($res['erro']) ? $res['erro'] : 'falhou';
                }
            } else {
                $rep['grupo_preview'] = $msg;
            }
        }
    }

    return $rep;
}
