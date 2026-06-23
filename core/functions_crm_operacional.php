<?php
/**
 * Ferreira & Sá Conecta — CRM Operacional (canal 24 + petições iniciais pendentes).
 *
 * Espelho do CRM Comercial (core/functions_comercial.php) mas voltado pra equipe
 * operacional/CX (canal 24) e pro Kanban de cases. 3 frentes:
 *  - Pendentes de resposta (canal 24, última msg foi do cliente)
 *  - Follow-up (canal 24, última msg foi nossa e o cliente sumiu)
 *  - Petições iniciais pendentes (cases em em_elaboracao / aguardando_prazo,
 *    ordenadas por mais tempo na coluna — proxy: cases.updated_at).
 *
 * NÃO tem cobrança automática nem cron — só painel visual por enquanto.
 */

/** Cria a tabela de obs se não existir (idempotente). */
function crm_op_self_heal($pdo)
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS crm_operacional_obs (
            conversa_id INT NOT NULL PRIMARY KEY,
            observacao TEXT NULL,
            proximo_followup DATE NULL,
            status VARCHAR(20) NULL,
            atualizado_por INT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Exception $e) {}
}

/** Resolve user_id responsável de uma conversa (igual ao comercial). */
function crm_op_responsavel_id($row)
{
    if (!empty($row['atendente_id']))    return (int)$row['atendente_id'];
    if (!empty($row['ultimo_resp_id']))  return (int)$row['ultimo_resp_id'];
    if (!empty($row['responsible_user_id'])) return (int)$row['responsible_user_id'];
    return null;
}

/** Mapa id→nome (cache estático por request). */
function crm_op_users_map($pdo)
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

/** "há 2h 10min", "há 3 dias"… */
function crm_op_tempo($dt)
{
    if (!$dt) return '—';
    $s = time() - strtotime($dt);
    if ($s < 60)    return 'agora';
    $m = (int)($s / 60);
    if ($m < 60)    return 'há ' . $m . ' min';
    $h = (int)($m / 60);
    if ($h < 24)    return 'há ' . $h . 'h' . ($m % 60 ? ' ' . ($m % 60) . 'min' : '');
    $d = (int)($h / 24);
    return 'há ' . $d . ' dia' . ($d > 1 ? 's' : '');
}

/**
 * Busca conversas do canal 24 cuja ÚLTIMA mensagem tem a $direcao informada.
 *  - 'recebida' = última foi do cliente → devemos resposta
 *  - 'enviada'  = última foi nossa     → cliente sumiu (follow-up)
 *
 * Faz JOIN com o case ATIVO do cliente (mais recente) pra trazer dono + título.
 */
function crm_op_fetch_wa($pdo, $direcao, $diasMax = 45, $limit = 300, $statusEq = null)
{
    if ($statusEq !== null) {
        $where  = "co.canal = '24' AND (co.eh_grupo = 0 OR co.eh_grupo IS NULL) AND lo.status = ?";
        $params = array($statusEq);
    } else {
        $where  = "co.canal = '24' AND (co.eh_grupo = 0 OR co.eh_grupo IS NULL)
                   AND co.status NOT IN ('resolvido','arquivado') AND lm.direcao = ?";
        $params = array($direcao);
        if ($diasMax > 0) $where .= " AND co.created_at >= DATE_SUB(NOW(), INTERVAL " . (int)$diasMax . " DAY)";
    }

    // Subquery: o case ativo mais recente do cliente da conversa
    // (stage NOT IN arquivado/concluido, ordenado por updated_at DESC LIMIT 1).
    $sql = "SELECT co.id AS conversa_id, co.telefone, co.nome_contato, co.atendente_id,
                   co.client_id, co.created_at AS conversa_em, co.status,
                   cl.name AS client_name,
                   lm.id AS ultima_msg_id, lm.direcao AS ultima_direcao,
                   lm.created_at AS ultima_em, lm.conteudo AS ultima_texto,
                   (SELECT mm.enviado_por_id FROM zapi_mensagens mm
                     WHERE mm.conversa_id = co.id AND mm.direcao = 'enviada'
                       AND mm.enviado_por_id IS NOT NULL
                     ORDER BY mm.id DESC LIMIT 1) AS ultimo_resp_id,
                   (SELECT MAX(mm.created_at) FROM zapi_mensagens mm
                     WHERE mm.conversa_id = co.id AND mm.direcao = 'enviada') AS ultima_nossa_em,
                   (SELECT cs.id FROM cases cs
                     WHERE cs.client_id = co.client_id
                       AND cs.stage NOT IN ('arquivado','concluido')
                     ORDER BY cs.updated_at DESC LIMIT 1) AS case_id,
                   (SELECT cs.title FROM cases cs
                     WHERE cs.client_id = co.client_id
                       AND cs.stage NOT IN ('arquivado','concluido')
                     ORDER BY cs.updated_at DESC LIMIT 1) AS case_title,
                   (SELECT cs.stage FROM cases cs
                     WHERE cs.client_id = co.client_id
                       AND cs.stage NOT IN ('arquivado','concluido')
                     ORDER BY cs.updated_at DESC LIMIT 1) AS case_stage,
                   (SELECT cs.responsible_user_id FROM cases cs
                     WHERE cs.client_id = co.client_id
                       AND cs.stage NOT IN ('arquivado','concluido')
                     ORDER BY cs.updated_at DESC LIMIT 1) AS responsible_user_id,
                   lo.observacao, lo.proximo_followup, lo.status
            FROM zapi_conversas co
            JOIN (
                SELECT m.conversa_id, m.id, m.direcao, m.created_at, m.conteudo
                FROM zapi_mensagens m
                JOIN (SELECT conversa_id, MAX(id) AS maxid FROM zapi_mensagens GROUP BY conversa_id) x
                  ON x.conversa_id = m.conversa_id AND x.maxid = m.id
            ) lm ON lm.conversa_id = co.id
            LEFT JOIN clients cl ON cl.id = co.client_id
            LEFT JOIN crm_operacional_obs lo ON lo.conversa_id = co.id
            WHERE $where
            ORDER BY lm.created_at ASC
            LIMIT " . (int)$limit;

    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

/**
 * Busca cases em "petição inicial pendente":
 *  - stage = em_elaboracao  → Pasta Apta (redação em curso)
 *  - stage = aguardando_prazo → Aguard. Distribuição (redigida, aguarda protocolo)
 * Ordenado por mais tempo na coluna (proxy: updated_at ASC).
 */
function crm_op_fetch_peticoes($pdo, $limit = 200)
{
    $sql = "SELECT cs.id, cs.title, cs.stage, cs.responsible_user_id,
                   cs.updated_at, cs.created_at,
                   cl.name AS client_name,
                   DATEDIFF(NOW(), cs.updated_at) AS dias_parado
            FROM cases cs
            LEFT JOIN clients cl ON cl.id = cs.client_id
            WHERE cs.stage IN ('em_elaboracao','aguardando_prazo')
            ORDER BY cs.updated_at ASC
            LIMIT " . (int)$limit;
    return $pdo->query($sql)->fetchAll();
}
