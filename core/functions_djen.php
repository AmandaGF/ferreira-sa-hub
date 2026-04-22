<?php
/**
 * Funções compartilhadas do módulo DJen
 * Usado por:
 *   - modules/admin/djen_importar.php (fluxo manual — cola texto e revisa)
 *   - api/djen_ingest.php (endpoint da skill automatizada)
 */

/**
 * Parseia o texto bruto do DJen. Separa blocos por "Processo NNNNNNN-NN.AAAA.X.XX.XXXX".
 * Extrai campos opcionais "Resumo:" e "Orientação:" quando presentes no texto.
 */
function djen_parsear_texto($texto) {
    $publicacoes = array();
    $blocos = preg_split('/(?=Processo\s+\d{7}-\d{2}\.\d{4}\.\d{1,2}\.\d{2}\.\d{4})/u', $texto, -1, PREG_SPLIT_NO_EMPTY);
    foreach ($blocos as $bloco) {
        $bloco = trim($bloco);
        if (!$bloco) continue;
        $pub = array(
            'numero_processo'  => '',
            'orgao'            => '',
            'data_disp'        => date('Y-m-d'),
            'tipo_comunicacao' => 'intimacao',
            'meio'             => 'DJEN',
            'partes'           => array(),
            'advogados'        => array(),
            'conteudo'         => '',
            'segredo'          => false,
            'comarca'          => '',
            'resumo'           => '',
            'orientacao'       => '',
        );
        if (preg_match('/Processo\s+(\d{7}-\d{2}\.\d{4}\.\d{1,2}\.\d{2}\.\d{4})/u', $bloco, $m)) {
            $pub['numero_processo'] = $m[1];
        }
        if (!$pub['numero_processo']) continue;

        if (preg_match('/(?:Org[aã]o|Orgao)\s*[:\-]?\s*(.+?)(?:\n|Data)/ui', $bloco, $m)) {
            $pub['orgao'] = trim($m[1]);
        }
        if (preg_match('/Data de disponibiliza[cç][aã]o\s*[:\-]?\s*(\d{2}\/\d{2}\/\d{4})/ui', $bloco, $m)) {
            $p = explode('/', $m[1]);
            if (count($p) === 3) $pub['data_disp'] = $p[2] . '-' . $p[1] . '-' . $p[0];
        }
        if (preg_match('/Tipo de comunica[cç][aã]o\s*[:\-]?\s*(.+?)(?:\n)/ui', $bloco, $m)) {
            $tipo = strtolower(trim($m[1]));
            if (strpos($tipo, 'intima') !== false) $pub['tipo_comunicacao'] = 'intimacao';
            elseif (strpos($tipo, 'cita') !== false) $pub['tipo_comunicacao'] = 'citacao';
            elseif (strpos($tipo, 'edital') !== false) $pub['tipo_comunicacao'] = 'edital';
            elseif (strpos($tipo, 'despach') !== false) $pub['tipo_comunicacao'] = 'despacho';
            elseif (strpos($tipo, 'decis') !== false) $pub['tipo_comunicacao'] = 'decisao';
            elseif (strpos($tipo, 'sentenc') !== false) $pub['tipo_comunicacao'] = 'sentenca';
            elseif (strpos($tipo, 'acord') !== false) $pub['tipo_comunicacao'] = 'acordao';
            else $pub['tipo_comunicacao'] = 'outro';
        }
        if (stripos($bloco, 'SEGREDO DE JUSTI') !== false) $pub['segredo'] = true;

        if (preg_match('/Parte\(s\)(.*?)Advogado\(s\)/us', $bloco, $m)) {
            $linhas = array_filter(array_map('trim', explode("\n", trim($m[1]))));
            foreach ($linhas as $l) {
                $l = preg_replace('/^[\*\-\x{2022}]\s*/u', '', $l);
                if ($l && stripos($l, 'SEGREDO') === false) $pub['partes'][] = $l;
            }
        }
        if (preg_match('/Advogado\(s\)(.*?)(?:Poder Judici|$)/us', $bloco, $m)) {
            $linhas = array_filter(array_map('trim', explode("\n", trim($m[1]))));
            foreach ($linhas as $l) {
                $l = preg_replace('/^[\*\-\x{2022}]\s*/u', '', $l);
                if ($l && preg_match('/OAB/i', $l)) $pub['advogados'][] = $l;
            }
        }
        $pub['conteudo'] = $bloco;

        if (preg_match('/(?:^|\n)\s*Resumo\s*[:\-]\s*(.+?)(?=\n\s*(?:Orienta[cç][aã]o|Conte[uú]do|Poder Judici)|\n\n|$)/uis', $bloco, $mR)) {
            $pub['resumo'] = trim(preg_replace('/\s+/u', ' ', $mR[1]));
        }
        if (preg_match('/(?:^|\n)\s*Orienta[cç][aã]o\s*[:\-]\s*(.+?)(?=\n\s*(?:Conte[uú]do|Poder Judici|Resumo)|\n\n|$)/uis', $bloco, $mO)) {
            $pub['orientacao'] = trim(preg_replace('/\s+/u', ' ', $mO[1]));
        }
        if (preg_match('/Comarca\s+de\s+([^,\n]+)/ui', $pub['orgao'], $m)) {
            $pub['comarca'] = trim($m[1]);
        }

        $publicacoes[] = $pub;
    }
    return $publicacoes;
}

/**
 * Limpa conteúdo de publicação pra exibição em tela.
 * A API DJEN devolve HTML completo (html/head/meta/style/body/section/b/...) —
 * precisamos extrair só o texto legível, decodificar entidades (&oacute; → ó)
 * e normalizar espaços em branco.
 */
function djen_conteudo_limpo($html, $maxLen = null) {
    if ($html === null || $html === '') return '';
    // Remove <head>, <style> e <script> (com conteúdo)
    $txt = preg_replace('#<(head|style|script)[^>]*>.*?</\1>#si', ' ', $html);
    // Substitui tags de bloco e <br> por quebra
    $txt = preg_replace('#</(p|div|section|tr|li|h[1-6])>|<br\s*/?>#i', "\n", $txt);
    // Tira todas as outras tags
    $txt = strip_tags($txt);
    // Decodifica entidades (&oacute; &ordm; &amp; etc)
    $txt = html_entity_decode($txt, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // Normaliza whitespace: \r\n → \n, colapsa linhas em branco extras, tira espaços em linha
    $txt = str_replace("\r\n", "\n", $txt);
    $txt = preg_replace("/[ \t]+/", ' ', $txt);
    $txt = preg_replace("/\n{3,}/", "\n\n", $txt);
    $txt = trim($txt);
    if ($maxLen !== null && mb_strlen($txt) > $maxLen) {
        $txt = mb_substr($txt, 0, $maxLen) . '…';
    }
    return $txt;
}

function djen_prazo_sugerido($tipo) {
    $prazos = array('intimacao'=>15,'citacao'=>15,'decisao'=>15,'sentenca'=>15,'despacho'=>5,'acordao'=>15,'edital'=>20,'outro'=>0);
    return isset($prazos[$tipo]) ? $prazos[$tipo] : 0;
}

function djen_calcular_data_fim($dataInicio, $dias) {
    if (!$dias) return null;
    if (function_exists('calcular_prazo_completo')) {
        $res = calcular_prazo_completo($dataInicio, $dias, 'dias', null);
        return isset($res['data_fatal']) ? $res['data_fatal'] : null;
    }
    try {
        $atual = new DateTime($dataInicio);
        $atual->modify('+1 day');
        $cont = 0;
        while ($cont < $dias) {
            if ((int)$atual->format('N') < 6) $cont++;
            if ($cont < $dias) $atual->modify('+1 day');
        }
        return $atual->format('Y-m-d');
    } catch (Exception $e) { return null; }
}

/**
 * Encontra pasta do processo pelo CNJ (inclui arquivados — mas prioriza ativos).
 */
function djen_buscar_case_por_cnj($pdo, $cnj) {
    $num = preg_replace('/\D/', '', $cnj);
    if (!$num) return null;
    $stmt = $pdo->prepare(
        "SELECT cs.id, cs.title, cs.comarca, cs.case_type, cs.responsible_user_id, cs.status,
                c.name AS client_name, c.id AS client_id
         FROM cases cs
         LEFT JOIN clients c ON c.id = cs.client_id
         WHERE REPLACE(REPLACE(REPLACE(cs.case_number,'-',''),'.',''),'/','') = ?
         ORDER BY FIELD(cs.status,'arquivado','cancelado','concluido') ASC, cs.id DESC
         LIMIT 1"
    );
    $stmt->execute(array($num));
    return $stmt->fetch();
}

/**
 * Importa uma publicação no sistema: publicação + tarefa de prazo + evento agenda + andamento trancado + notify.
 * Retorna ['pub_id'=>N, 'task_id'=>N|null] OU ['duplicated'=>true] OU false em caso de erro.
 */
function djen_importar_publicacao($pdo, $pub, $caseId, $userId) {
    if (!$caseId) return false;

    // Garante colunas de resumo/orientação
    try { $pdo->exec("ALTER TABLE case_publicacoes ADD COLUMN resumo_ia TEXT NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE case_publicacoes ADD COLUMN orientacao_ia TEXT NULL"); } catch (Exception $e) {}

    $dataDisp = $pub['data_disp'] ?: date('Y-m-d');
    $tipoPub  = $pub['tipo_comunicacao'] ?: 'intimacao';
    $conteudo = trim($pub['conteudo'] ?: '');
    $orgao    = trim($pub['orgao'] ?: '');
    $resumo   = trim($pub['resumo'] ?? $pub['resumo_ia'] ?? '');
    $orient   = trim($pub['orientacao'] ?? $pub['orientacao_ia'] ?? '');
    $prazoDias = djen_prazo_sugerido($tipoPub);
    $dataFim   = djen_calcular_data_fim($dataDisp, $prazoDias);

    if (!$conteudo) return false;

    // Pega título + responsável do caso
    $stmtC = $pdo->prepare("SELECT title, responsible_user_id FROM cases WHERE id = ?");
    $stmtC->execute(array($caseId));
    $caso = $stmtC->fetch();
    if (!$caso) return false;
    $tituloCase = $caso['title'] ?: ('Caso #' . $caseId);
    $responsavel = (int)$caso['responsible_user_id'] ?: ($userId ?: 1);

    // Dedup
    $stmtDup = $pdo->prepare(
        "SELECT id FROM case_publicacoes
         WHERE case_id = ? AND data_disponibilizacao = ? AND tipo_publicacao = ?
         AND LEFT(conteudo, 100) = LEFT(?, 100) LIMIT 1"
    );
    $stmtDup->execute(array($caseId, $dataDisp, $tipoPub, $conteudo));
    if ($stmtDup->fetch()) return array('duplicated' => true);

    // INSERT publicação
    $pdo->prepare(
        "INSERT INTO case_publicacoes
         (case_id, data_disponibilizacao, conteudo, caderno, tribunal,
          tipo_publicacao, fonte, prazo_dias, data_prazo_fim, status_prazo,
          visivel_cliente, resumo_ia, orientacao_ia, criado_por, created_at)
         VALUES (?,?,?,'DJEN',?,?,'manual',?,?,'pendente',0,?,?,?,NOW())"
    )->execute(array(
        $caseId, $dataDisp, $conteudo, $orgao, $tipoPub,
        $prazoDias ?: null, $dataFim, $resumo ?: null, $orient ?: null, $userId
    ));
    $pubId = (int)$pdo->lastInsertId();

    $taskId = null;
    if ($dataFim) {
        $tipoLbl = array('intimacao'=>'INTIMAÇÃO','citacao'=>'CITAÇÃO','despacho'=>'DESPACHO','decisao'=>'DECISÃO','sentenca'=>'SENTENÇA','acordao'=>'ACÓRDÃO','edital'=>'EDITAL','outro'=>'PUBLICAÇÃO');
        $lbl = isset($tipoLbl[$tipoPub]) ? $tipoLbl[$tipoPub] : 'PUBLICAÇÃO';
        $prazoAlerta = date('Y-m-d', strtotime($dataFim . ' -3 days'));

        $pdo->prepare(
            "INSERT INTO case_tasks
             (case_id, title, descricao, tipo, subtipo, due_date, prazo_alerta,
              status, prioridade, assigned_to, created_at)
             VALUES (?,?,?,'prazo','prazo_publicacao',?,?,'a_fazer','alta',?,NOW())"
        )->execute(array(
            $caseId,
            'PRAZO - ' . $lbl . ' | ' . $tituloCase,
            'Prazo de ' . $prazoDias . 'du a partir de ' . date('d/m/Y', strtotime($dataDisp)) . '. Vence: ' . date('d/m/Y', strtotime($dataFim)),
            $dataFim, $prazoAlerta, $responsavel
        ));
        $taskId = (int)$pdo->lastInsertId();
        $pdo->prepare("UPDATE case_publicacoes SET task_id = ? WHERE id = ?")->execute(array($taskId, $pubId));

        $pdo->prepare(
            "INSERT INTO agenda_eventos
             (case_id, titulo, descricao, data_inicio, data_fim, dia_todo,
              tipo, responsavel_id, created_by, created_at)
             VALUES (?,?,?,?,?,1,'prazo',?,?,NOW())"
        )->execute(array(
            $caseId, 'Publicação: ' . $lbl . ' | ' . $tituloCase,
            mb_substr($conteudo, 0, 300, 'UTF-8'),
            $dataDisp . ' 08:00:00', $dataDisp . ' 08:30:00',
            $responsavel, $userId ?: $responsavel
        ));
        $agendaId = (int)$pdo->lastInsertId();
        if ($agendaId) {
            try { $pdo->prepare("UPDATE case_publicacoes SET agenda_id = ? WHERE id = ?")->execute(array($agendaId, $pubId)); } catch (Exception $e) {}
        }

        if ($responsavel && function_exists('notify') && $userId && $responsavel !== $userId) {
            notify($responsavel, 'Novo prazo: ' . $lbl,
                'Vence em ' . date('d/m/Y', strtotime($dataFim)) . ' - ' . $tituloCase,
                'warning', url('modules/operacional/caso_ver.php?id=' . $caseId), '');
        }
    }

    // Andamento TRANCADO (visivel_cliente=0)
    try {
        $tipoAndLbl = array('intimacao'=>'Intimação','citacao'=>'Citação','despacho'=>'Despacho','decisao'=>'Decisão','sentenca'=>'Sentença','acordao'=>'Acórdão','edital'=>'Edital','outro'=>'Publicação');
        $lblAnd = isset($tipoAndLbl[$tipoPub]) ? $tipoAndLbl[$tipoPub] : 'Publicação';
        $descAnd = '📢 ' . $lblAnd . ' — DJen (' . date('d/m/Y', strtotime($dataDisp)) . ')';
        if ($resumo) $descAnd .= "\n\n📝 Resumo: " . $resumo;
        if ($orient) $descAnd .= "\n⚖️ Orientação: " . $orient;
        if ($dataFim) $descAnd .= "\n⏰ Prazo fatal: " . date('d/m/Y', strtotime($dataFim));
        $descAnd .= "\n\n— Conteúdo completo —\n" . mb_substr($conteudo, 0, 2000, 'UTF-8');

        $pdo->prepare(
            "INSERT INTO case_andamentos
             (case_id, data_andamento, tipo, descricao, visivel_cliente, created_by, created_at)
             VALUES (?,?,'publicacao',?,0,?,NOW())"
        )->execute(array($caseId, $dataDisp, $descAnd, $userId ?: $responsavel));
    } catch (Exception $e) {}

    if (function_exists('audit_log')) audit_log('PUBLICACAO_IMPORTADA_DJEN', 'case', $caseId, 'pub_id=' . $pubId . ' via=skill');

    return array('pub_id' => $pubId, 'task_id' => $taskId);
}

/**
 * Salva publicação sem pasta correspondente em djen_pending pra revisão humana.
 */
function djen_salvar_pendente($pdo, $pub) {
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS djen_pending (
                id INT AUTO_INCREMENT PRIMARY KEY,
                numero_processo VARCHAR(40) NOT NULL,
                data_disp DATE NULL,
                tipo_comunicacao VARCHAR(30) NULL,
                orgao VARCHAR(200) NULL,
                comarca VARCHAR(100) NULL,
                partes TEXT NULL,
                advogados TEXT NULL,
                conteudo TEXT NOT NULL,
                resumo TEXT NULL,
                orientacao TEXT NULL,
                segredo TINYINT(1) DEFAULT 0,
                status ENUM('pendente','importado','descartado') DEFAULT 'pendente',
                case_id INT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_numero (numero_processo),
                INDEX idx_status (status)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } catch (Exception $e) {}

    // Dedup
    $stmt = $pdo->prepare("SELECT id FROM djen_pending WHERE numero_processo = ? AND data_disp = ? AND LEFT(conteudo,100) = LEFT(?,100) AND status = 'pendente' LIMIT 1");
    $stmt->execute(array($pub['numero_processo'], $pub['data_disp'], $pub['conteudo']));
    if ($stmt->fetch()) return array('duplicated' => true);

    $pdo->prepare(
        "INSERT INTO djen_pending
         (numero_processo, data_disp, tipo_comunicacao, orgao, comarca, partes, advogados, conteudo, resumo, orientacao, segredo)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)"
    )->execute(array(
        $pub['numero_processo'],
        $pub['data_disp'] ?: null,
        $pub['tipo_comunicacao'] ?: 'outro',
        $pub['orgao'] ?: '',
        $pub['comarca'] ?: '',
        !empty($pub['partes']) ? json_encode($pub['partes'], JSON_UNESCAPED_UNICODE) : null,
        !empty($pub['advogados']) ? json_encode($pub['advogados'], JSON_UNESCAPED_UNICODE) : null,
        $pub['conteudo'],
        !empty($pub['resumo']) ? $pub['resumo'] : null,
        !empty($pub['orientacao']) ? $pub['orientacao'] : null,
        !empty($pub['segredo']) ? 1 : 0,
    ));
    return array('id' => (int)$pdo->lastInsertId());
}
