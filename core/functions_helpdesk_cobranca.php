<?php
/**
 * Ferreira & Sá Conecta — Cobrança de CHAMADOS PARADOS (Helpdesk).
 *
 * Chamado aberto (status != resolvido/cancelado) sem movimento há +N horas →
 * notifica o(s) responsável(is) (sino + push) e manda um resumo no grupo do
 * WhatsApp (nomes no texto, máx 1×/throttle, horário comercial).
 *
 * Mesmo padrão do CRM Comercial (functions_comercial). Começa DESLIGADO.
 * Liga/desliga, grupo e horas: painel ⚙️ do Helpdesk (configuracoes helpdesk_cobranca_*).
 */

require_once __DIR__ . '/functions_notify.php';
require_once __DIR__ . '/functions_push.php';
require_once __DIR__ . '/functions_zapi.php';

/** Tabela de dedup (idempotente). */
function helpdesk_cobranca_self_heal($pdo)
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS helpdesk_cobranca (
            ticket_id INT NOT NULL PRIMARY KEY,
            ultima_atividade DATETIME NULL,
            alertado_em DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {}
}

/** Configs (chave/valor) com defaults. */
function helpdesk_cobranca_cfg($pdo)
{
    $cfg = array(
        'ativo'           => '0',
        'grupo_id'        => '',
        'grupo_canal'     => '24',   // grupo do time operacional/CX
        'horas'           => '24',   // horas sem movimento pra cobrar
        'janela_dias'     => '30',   // NÃO cobra chamados parados há mais que isso (evita spam de backlog antigo)
        'grupo_ultimo_em' => '',
    );
    try {
        $rows = $pdo->query("SELECT chave, valor FROM configuracoes WHERE chave LIKE 'helpdesk_cobranca_%'")->fetchAll();
        foreach ($rows as $r) {
            if ($r['chave'] === 'helpdesk_cobranca_ativo')            $cfg['ativo'] = $r['valor'];
            elseif ($r['chave'] === 'helpdesk_cobranca_grupo_id')     $cfg['grupo_id'] = $r['valor'];
            elseif ($r['chave'] === 'helpdesk_cobranca_canal')        $cfg['grupo_canal'] = $r['valor'];
            elseif ($r['chave'] === 'helpdesk_cobranca_horas')        $cfg['horas'] = $r['valor'];
            elseif ($r['chave'] === 'helpdesk_cobranca_janela_dias')  $cfg['janela_dias'] = $r['valor'];
            elseif ($r['chave'] === 'helpdesk_cobranca_grupo_ultimo_em') $cfg['grupo_ultimo_em'] = $r['valor'];
        }
    } catch (Exception $e) {}
    return $cfg;
}

function helpdesk_cobranca_set_cfg($pdo, $chave, $valor)
{
    $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES (?, ?)
                   ON DUPLICATE KEY UPDATE valor = VALUES(valor)")
        ->execute(array($chave, $valor));
}

/** Horário comercial: 9h–18h, seg–sex. */
function helpdesk_cobranca_em_horario()
{
    $h  = (int)date('H');
    $wd = (int)date('N');
    return ($wd <= 5) && ($h >= 9) && ($h < 18);
}

/** Mensagem do grupo (com variações). $partes = ["Fulano (2)", "Beltrano (1)"]. */
function helpdesk_cobranca_msg_grupo($partes, $horas)
{
    $lista = implode(', ', $partes);
    $link  = 'ferreiraesa.com.br/conecta/modules/helpdesk/';
    $vars = array(
        "🎫 Opa, time! Tem chamado parado há +{$horas}h esperando um empurrãozinho:\n\n*Parados:* {lista}\n\nBora destravar? 👉 {link}",
        "⏰ Psiu! Uns chamados estão criando teia de aranha 🕸️\n\n*Sem movimento:* {lista}\n\nUma olhadinha resolve! 🚀\n{link}",
        "🔔 Toc toc! Chamados aguardando retorno de vocês:\n\n{lista}\n\nVamo que vamo, o cliente agradece 💚\n{link}",
        "💬 Lembrete gentil: tem chamado sem resposta há um tempinho.\n\n*Na espera:* {lista}\n\nBora dar aquele gás 👉 {link}",
    );
    $msg = $vars[array_rand($vars)];
    return str_replace(array('{lista}', '{link}', '{horas}'), array($lista, $link, $horas), $msg);
}

/**
 * Motor da cobrança de chamados parados.
 * @param array $opts forcar_horario(bool), ignorar_ativo(bool), dry(bool)
 * @return array relatório
 */
function helpdesk_cobranca_run($pdo, $opts = array())
{
    helpdesk_cobranca_self_heal($pdo);
    $forcarHorario = !empty($opts['forcar_horario']);
    $ignorarAtivo  = !empty($opts['ignorar_ativo']);
    $dry           = !empty($opts['dry']);

    $rep = array('ativo' => true, 'horario_ok' => true, 'parados' => 0,
                 'notificados' => 0, 'grupo_enviado' => false, 'detalhe' => array());

    $cfg = helpdesk_cobranca_cfg($pdo);
    if ($cfg['ativo'] !== '1' && !$ignorarAtivo) { $rep['ativo'] = false; return $rep; }
    if (!helpdesk_cobranca_em_horario() && !$forcarHorario) { $rep['horario_ok'] = false; return $rep; }

    $horas = max(1, (int)$cfg['horas']);
    $janelaDias = max(1, (int)$cfg['janela_dias']);

    // Chamados abertos sem movimento ENTRE +N horas e a janela máxima (evita cobrar
    // backlog antigo — só o que "esfriou" recentemente). + responsáveis (GROUP_CONCAT)
    $sql = "SELECT t.id, t.title, t.status, t.updated_at, t.requester_id,
                   TIMESTAMPDIFF(HOUR, t.updated_at, NOW()) AS horas_parado,
                   GROUP_CONCAT(ta.user_id) AS assignee_ids
            FROM tickets t
            LEFT JOIN ticket_assignees ta ON ta.ticket_id = t.id
            WHERE t.status NOT IN ('resolvido','cancelado','arquivado','concluido','fechado')
              AND t.updated_at <= DATE_SUB(NOW(), INTERVAL " . (int)$horas . " HOUR)
              AND t.updated_at >= DATE_SUB(NOW(), INTERVAL " . (int)$janelaDias . " DAY)
            GROUP BY t.id
            ORDER BY t.updated_at ASC
            LIMIT 200";
    try { $rows = $pdo->query($sql)->fetchAll(); }
    catch (Exception $e) { $rep['erro'] = $e->getMessage(); return $rep; }

    $rep['parados'] = count($rows);
    if (!$rows) return $rep;

    // Dedup: já cobrei este chamado por esta atividade? (re-lembra a cada 24h)
    $jaCobrado = array();
    foreach ($pdo->query("SELECT ticket_id, ultima_atividade, alertado_em FROM helpdesk_cobranca")->fetchAll() as $c) {
        $jaCobrado[(int)$c['ticket_id']] = array('ua' => $c['ultima_atividade'], 'ae' => $c['alertado_em']);
    }
    $upsert = $pdo->prepare("INSERT INTO helpdesk_cobranca (ticket_id, ultima_atividade, alertado_em)
                             VALUES (?, ?, NOW())
                             ON DUPLICATE KEY UPDATE ultima_atividade = VALUES(ultima_atividade), alertado_em = NOW()");

    $umap = array();
    foreach ($pdo->query("SELECT id, name FROM users")->fetchAll() as $u) { $umap[(int)$u['id']] = $u['name']; }

    $porResp = array(); // resp_id (0 = sem dono) => qtd
    foreach ($rows as $r) {
        $tid = (int)$r['id'];
        $assignees = array_filter(array_map('intval', explode(',', (string)$r['assignee_ids'])));
        // Contabiliza pro grupo (por responsável; sem dono agrupa em 0)
        if ($assignees) { foreach ($assignees as $aid) { $porResp[$aid] = isset($porResp[$aid]) ? $porResp[$aid] + 1 : 1; } }
        else { $porResp[0] = isset($porResp[0]) ? $porResp[0] + 1 : 1; }

        // Dedup: pula se já cobrei nesta MESMA atividade nas últimas 24h
        $prev = isset($jaCobrado[$tid]) ? $jaCobrado[$tid] : null;
        if ($prev && $prev['ua'] === $r['updated_at'] && $prev['ae'] && (time() - strtotime($prev['ae'])) < 24 * 3600) {
            continue;
        }

        $hrs   = max($horas, (int)$r['horas_parado']);
        $titulo = '🎫 Chamado parado há ' . $hrs . 'h';
        $corpo  = '#' . $tid . ' "' . mb_substr((string)$r['title'], 0, 60) . '" sem movimento há ' . $hrs . 'h.';
        $link   = '/conecta/modules/helpdesk/ver.php?id=' . $tid;

        if (!$dry) {
            if ($assignees) {
                foreach ($assignees as $aid) {
                    if (function_exists('notify')) notify($aid, $titulo, $corpo, 'urgencia', $link, '🎫');
                    if (function_exists('push_notify')) { try { push_notify($aid, $titulo, $corpo, $link, true); } catch (Exception $e) {} }
                }
            } else {
                if (function_exists('notify_gestao')) notify_gestao($titulo . ' (sem responsável)', $corpo, 'urgencia', $link, '🎫');
                if (function_exists('push_notify_role')) { try { push_notify_role(array('admin','gestao'), $titulo, $corpo, $link, true); } catch (Exception $e) {} }
            }
            $upsert->execute(array($tid, $r['updated_at']));
        }
        $rep['notificados']++;
        $rep['detalhe'][] = array('ticket' => $tid, 'titulo' => $r['title'], 'horas' => $hrs,
            'responsaveis' => $assignees ? implode(', ', array_map(function($a) use ($umap){ return isset($umap[$a]) ? $umap[$a] : ('#' . $a); }, $assignees)) : 'sem responsável');
    }

    // Resumo no grupo (1×/throttle 30min em horário comercial)
    if (!empty($cfg['grupo_id']) && $porResp) {
        $ultimo = $cfg['grupo_ultimo_em'] ? strtotime($cfg['grupo_ultimo_em']) : 0;
        $podeGrupo = $forcarHorario || ((time() - $ultimo) >= 30 * 60);
        if ($podeGrupo) {
            arsort($porResp);
            $partes = array();
            foreach ($porResp as $rid => $qt) {
                $nm = $rid ? (isset($umap[$rid]) ? $umap[$rid] : ('#' . $rid)) : 'sem responsável';
                $nm = trim(strtok($nm, ' '));
                $partes[] = $nm . ' (' . $qt . ')';
            }
            $msg = helpdesk_cobranca_msg_grupo($partes, $horas);
            if (!$dry) {
                $canal = $cfg['grupo_canal'] ? $cfg['grupo_canal'] : '24';
                $res = zapi_send_text($canal, $cfg['grupo_id'], $msg);
                if (!empty($res['ok'])) {
                    helpdesk_cobranca_set_cfg($pdo, 'helpdesk_cobranca_grupo_ultimo_em', date('Y-m-d H:i:s'));
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
