<?php
/**
 * Ferreira & Sá Conecta — Integração DataJud (CNJ)
 *
 * API Pública DataJud — busca e sincroniza movimentações processuais.
 * Documentação: https://datajud-wiki.cnj.jus.br/
 */

define('DATAJUD_API_KEY', 'cDZHYzlZa0JadVREZDJCendQbXY6SkJlTzNjLV9TRENyQk1RdnFKZGRQdw==');
define('DATAJUD_BASE_URL', 'https://api-publica.datajud.cnj.jus.br/');

$GLOBALS['DATAJUD_INDICES'] = array(
    'TJRJ' => 'api_publica_tjrj',
    'TJSP' => 'api_publica_tjsp',
    'TJMG' => 'api_publica_tjmg',
    'TRF1' => 'api_publica_trf1',
    'TRF2' => 'api_publica_trf2',
    'JEF'  => 'api_publica_trf2',
    'TRT1' => 'api_publica_trt1',
    'TST'  => 'api_publica_tst',
    'STJ'  => 'api_publica_stj',
    'STF'  => 'api_publica_stf',
);

/**
 * Detectar tribunal a partir dos dados do caso
 */
function detectar_tribunal($case) {
    $mapa = array(
        'PJe TJRJ' => 'TJRJ', 'TJRJ' => 'TJRJ', 'eproc TJRJ' => 'TJRJ',
        'PJe TRF2' => 'TRF2', 'TRF2' => 'TRF2', 'JEF' => 'TRF2', 'eproc TRF2' => 'TRF2',
        'PJe TJSP' => 'TJSP', 'TJSP' => 'TJSP', 'eproc TJSP' => 'TJSP', 'esaj TJSP' => 'TJSP',
        'PJe TJMG' => 'TJMG', 'TJMG' => 'TJMG',
        'PJe TRF1' => 'TRF1', 'TRF1' => 'TRF1',
        'TST'      => 'TST',
        'STJ'      => 'STJ',
        'STF'      => 'STF',
    );

    $sistema = isset($case['sistema_tribunal']) ? trim($case['sistema_tribunal']) : '';
    if ($sistema && isset($mapa[$sistema])) {
        return $mapa[$sistema];
    }

    // Tentar detectar pelo número do processo (dígitos 14-17 = código do tribunal)
    $num = preg_replace('/\D/', '', isset($case['case_number']) ? $case['case_number'] : '');
    if (strlen($num) >= 17) {
        $codTribunal = substr($num, 13, 4);
        $tribunalPorCodigo = array(
            '8190' => 'TJRJ', '8260' => 'TJSP', '8130' => 'TJMG',
            '5001' => 'TRF1', '5002' => 'TRF2',
            '5000' => 'TST',  '8000' => 'STJ',  '1000' => 'STF',
        );
        if (isset($tribunalPorCodigo[$codTribunal])) {
            return $tribunalPorCodigo[$codTribunal];
        }
    }

    return 'TJRJ'; // fallback
}

/**
 * Buscar processo na API pública do DataJud
 *
 * @param string $numero_processo Número CNJ (com ou sem formatação)
 * @param string $tribunal Sigla do tribunal (TJRJ, TJSP, etc.)
 * @return array ['sucesso' => true, 'dados' => ...] ou ['erro' => '...']
 */
function datajud_buscar_processo($numero_processo, $tribunal = 'TJRJ') {
    $numero = preg_replace('/\D/', '', $numero_processo);
    if (strlen($numero) < 13) {
        return array('erro' => 'numero_invalido', 'msg' => 'Numero do processo invalido');
    }

    $indice = isset($GLOBALS['DATAJUD_INDICES'][$tribunal])
        ? $GLOBALS['DATAJUD_INDICES'][$tribunal]
        : 'api_publica_tjrj';

    $url = DATAJUD_BASE_URL . $indice . '/_search';

    $payload = json_encode(array(
        'query' => array(
            'match' => array(
                'numeroProcesso' => $numero
            )
        ),
        'size' => 1
    ));

    $ch = curl_init();
    curl_setopt_array($ch, array(
        CURLOPT_URL            => $url,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => array(
            'Content-Type: application/json',
            'Authorization: APIKey ' . DATAJUD_API_KEY,
        ),
        CURLOPT_SSL_VERIFYPEER => true,
    ));

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return array('erro' => 'curl_error', 'msg' => $curlError);
    }

    if ($httpCode === 401 || $httpCode === 403) {
        return array('erro' => 'auth_error', 'msg' => 'API Key invalida ou sem permissao (HTTP ' . $httpCode . ')');
    }

    if ($httpCode !== 200) {
        return array('erro' => 'http_' . $httpCode, 'msg' => 'Erro HTTP ' . $httpCode);
    }

    $data = json_decode($response, true);
    if (!$data) {
        return array('erro' => 'json_error', 'msg' => 'Resposta invalida da API');
    }

    $hits = isset($data['hits']['hits']) ? $data['hits']['hits'] : array();
    if (empty($hits)) {
        return array('erro' => 'nao_encontrado', 'msg' => 'Processo nao encontrado no DataJud');
    }

    return array('sucesso' => true, 'dados' => $hits[0]['_source']);
}

/**
 * Sincronizar caso com DataJud
 *
 * @param int $case_id ID do caso
 * @param string $tipo 'automatico' ou 'manual'
 * @param int|null $user_id Quem disparou (null = cron)
 * @return array Resultado da sincronização
 */
function datajud_sincronizar_caso($case_id, $tipo = 'automatico', $user_id = null) {
    $pdo = db();

    // 1. Buscar caso
    $stmt = $pdo->prepare("SELECT cs.*, c.name as client_name, c.id as client_id FROM cases cs LEFT JOIN clients c ON c.id = cs.client_id WHERE cs.id = ?");
    $stmt->execute(array($case_id));
    $case = $stmt->fetch();

    if (!$case) {
        return array('status' => 'erro', 'msg' => 'Caso nao encontrado');
    }

    $numero = trim($case['case_number'] ?: '');
    if (!$numero) {
        datajud_registrar_log($pdo, $case_id, 'erro', 0, 'Sem numero de processo cadastrado', $user_id, $tipo);
        return array('status' => 'erro', 'msg' => 'Sem numero de processo cadastrado');
    }

    // 2. Detectar tribunal
    $tribunal = detectar_tribunal($case);

    // 3. Chamar API
    $resultado = datajud_buscar_processo($numero, $tribunal);

    // 4. Processo não encontrado — pode ser sigiloso ou tribunal não coberto
    if (isset($resultado['erro'])) {
        $statusLog = ($resultado['erro'] === 'nao_encontrado') ? 'nao_encontrado' : 'erro';

        $pdo->prepare("UPDATE cases SET datajud_ultima_sync = NOW(), datajud_erro = ?, datajud_sincronizado = 0 WHERE id = ?")
            ->execute(array($resultado['msg'], $case_id));

        datajud_registrar_log($pdo, $case_id, $statusLog, 0, $resultado['msg'], $user_id, $tipo);

        return array('status' => $statusLog, 'msg' => $resultado['msg']);
    }

    // 5. Sucesso: importar movimentos novos
    $processo = $resultado['dados'];
    $movimentos = isset($processo['movimentos']) ? $processo['movimentos'] : array();

    // Buscar IDs de movimentos já importados
    $existentes = array();
    try {
        $stmtEx = $pdo->prepare("SELECT datajud_movimento_id FROM case_andamentos WHERE case_id = ? AND tipo_origem = 'datajud' AND datajud_movimento_id IS NOT NULL");
        $stmtEx->execute(array($case_id));
        foreach ($stmtEx->fetchAll() as $row) {
            $existentes[$row['datajud_movimento_id']] = true;
        }
    } catch (Exception $e) {}

    $novos = 0;
    $ultimoMovId = null;

    // Ordenar movimentos por data (mais antigo primeiro)
    usort($movimentos, function($a, $b) {
        $da = isset($a['dataHora']) ? $a['dataHora'] : '';
        $db = isset($b['dataHora']) ? $b['dataHora'] : '';
        return strcmp($da, $db);
    });

    $stmtInsert = $pdo->prepare(
        "INSERT INTO case_andamentos (case_id, data_andamento, tipo, descricao, tipo_origem, datajud_movimento_id, visivel_cliente, created_by, created_at)
         VALUES (?, ?, ?, ?, 'datajud', ?, ?, ?, NOW())"
    );

    $grauSigilo = isset($processo['grau']) ? (int)$processo['grau'] : 0;
    $visivelCliente = ($grauSigilo > 0) ? 0 : 1;

    foreach ($movimentos as $mov) {
        // Gerar ID único para o movimento
        $movId = datajud_gerar_movimento_id($mov);

        if (isset($existentes[$movId])) {
            continue; // já importado
        }

        // Extrair dados
        $dataHora = isset($mov['dataHora']) ? $mov['dataHora'] : date('Y-m-d H:i:s');
        $dataAnd = substr($dataHora, 0, 10); // YYYY-MM-DD

        $nomeMovimento = isset($mov['nome']) ? $mov['nome'] : '';
        $complementos = array();
        if (isset($mov['complementosTabelados']) && is_array($mov['complementosTabelados'])) {
            foreach ($mov['complementosTabelados'] as $comp) {
                if (isset($comp['descricao'])) {
                    $complementos[] = $comp['descricao'];
                } elseif (isset($comp['nome'])) {
                    $complementos[] = $comp['nome'];
                }
            }
        }
        if (isset($mov['complemento']) && $mov['complemento']) {
            $complementos[] = $mov['complemento'];
        }

        $descricao = $nomeMovimento;
        if (!empty($complementos)) {
            $descricao .= "\n" . implode(' | ', $complementos);
        }

        // Detectar tipo do andamento
        $tipoAnd = datajud_detectar_tipo_andamento($nomeMovimento);

        try {
            $stmtInsert->execute(array(
                $case_id,
                $dataAnd,
                $tipoAnd,
                $descricao,
                $movId,
                $visivelCliente,
                $user_id
            ));
            $novos++;
            $ultimoMovId = $movId;
        } catch (Exception $e) {
            // Duplicata ou erro — continuar
        }
    }

    // 6. Preencher campos vazios do caso
    datajud_atualizar_dados_caso($case_id, $processo);

    // 7. Atualizar caso
    $pdo->prepare("UPDATE cases SET datajud_sincronizado = 1, datajud_ultima_sync = NOW(), datajud_erro = NULL, datajud_ultimo_movimento_id = ? WHERE id = ?")
        ->execute(array($ultimoMovId, $case_id));

    // 8. Alertar equipe se houver novos movimentos
    if ($novos > 0) {
        datajud_alertar_movimentos($case_id, $novos, $case);
    }

    // 9. Registrar log
    $msgLog = $novos > 0 ? $novos . ' movimento(s) novo(s) importado(s)' : 'Nenhuma novidade';
    datajud_registrar_log($pdo, $case_id, 'sucesso', $novos, $msgLog, $user_id, $tipo);

    return array('status' => 'sucesso', 'novos' => $novos, 'msg' => $msgLog);
}

/**
 * Preencher APENAS campos vazios do caso com dados do DataJud
 */
function datajud_atualizar_dados_caso($case_id, $processo) {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT * FROM cases WHERE id = ?");
    $stmt->execute(array($case_id));
    $case = $stmt->fetch();
    if (!$case) return;

    $updates = array();
    $params = array();

    // orgaoJulgador.nome → court
    if (empty($case['court']) && isset($processo['orgaoJulgador']['nome'])) {
        $updates[] = "court = ?";
        $params[] = $processo['orgaoJulgador']['nome'];
    }

    // classeProcessual.nome → case_type (só se vazio)
    if (empty($case['case_type']) && isset($processo['classe']['nome'])) {
        $updates[] = "case_type = ?";
        $params[] = $processo['classe']['nome'];
    }

    // grau → segredo_justica (só se não definido manualmente)
    if (isset($processo['nivelSigilo']) && (int)$processo['nivelSigilo'] > 0 && empty($case['segredo_justica'])) {
        $updates[] = "segredo_justica = 1";
    }

    // dataAjuizamento → distribution_date
    if (empty($case['distribution_date']) && isset($processo['dataAjuizamento'])) {
        $dataAjuiz = substr($processo['dataAjuizamento'], 0, 10);
        if ($dataAjuiz) {
            $updates[] = "distribution_date = ?";
            $params[] = $dataAjuiz;
        }
    }

    if (!empty($updates)) {
        $params[] = $case_id;
        $pdo->prepare("UPDATE cases SET " . implode(', ', $updates) . ", updated_at = NOW() WHERE id = ?")->execute($params);
    }

    // Inserir partes se não existirem
    if (isset($processo['partes']) && is_array($processo['partes'])) {
        datajud_importar_partes($case_id, $processo['partes'], $case['client_id']);
    }
}

/**
 * Importar partes do processo do DataJud
 */
function datajud_importar_partes($case_id, $partes, $client_id) {
    $pdo = db();

    // Verificar se já existem partes cadastradas
    try {
        $count = $pdo->prepare("SELECT COUNT(*) FROM case_partes WHERE case_id = ?");
        $count->execute(array($case_id));
        if ((int)$count->fetchColumn() > 0) {
            return; // já tem partes — não sobrescrever
        }
    } catch (Exception $e) {
        return;
    }

    $stmtParte = $pdo->prepare(
        "INSERT INTO case_partes (case_id, nome, tipo_pessoa, papel, created_at) VALUES (?, ?, ?, ?, NOW())"
    );

    foreach ($partes as $parte) {
        $nome = isset($parte['nome']) ? trim($parte['nome']) : '';
        if (!$nome) continue;

        $polo = isset($parte['polo']) ? strtolower($parte['polo']) : '';
        $papel = 'interessado';
        if ($polo === 'at' || $polo === 'ativo') $papel = 'autor';
        elseif ($polo === 'pa' || $polo === 'passivo') $papel = 'reu';

        $tipoPessoa = (isset($parte['tipoPessoa']) && strtoupper($parte['tipoPessoa']) === 'JURIDICA') ? 'PJ' : 'PF';

        try {
            $stmtParte->execute(array($case_id, $nome, $tipoPessoa, $papel));
        } catch (Exception $e) {}
    }
}

/**
 * Gerar ID único para um movimento (para evitar duplicatas)
 */
function datajud_gerar_movimento_id($mov) {
    $codigo = isset($mov['codigo']) ? $mov['codigo'] : '';
    $dataHora = isset($mov['dataHora']) ? $mov['dataHora'] : '';
    $nome = isset($mov['nome']) ? $mov['nome'] : '';
    return md5($codigo . '|' . $dataHora . '|' . $nome);
}

/**
 * Detectar tipo de andamento a partir do nome do movimento
 */
function datajud_detectar_tipo_andamento($nome) {
    $nome = mb_strtolower($nome, 'UTF-8');

    if (strpos($nome, 'despacho') !== false) return 'despacho';
    if (strpos($nome, 'decisao') !== false || strpos($nome, 'decisão') !== false) return 'decisao';
    if (strpos($nome, 'sentenca') !== false || strpos($nome, 'sentença') !== false) return 'sentenca';
    if (strpos($nome, 'audiencia') !== false || strpos($nome, 'audiência') !== false) return 'audiencia';
    if (strpos($nome, 'peticao') !== false || strpos($nome, 'petição') !== false || strpos($nome, 'juntada') !== false) return 'peticao_juntada';
    if (strpos($nome, 'intimacao') !== false || strpos($nome, 'intimação') !== false) return 'intimacao';
    if (strpos($nome, 'citacao') !== false || strpos($nome, 'citação') !== false) return 'citacao';
    if (strpos($nome, 'acordo') !== false || strpos($nome, 'conciliacao') !== false) return 'acordo';
    if (strpos($nome, 'recurso') !== false || strpos($nome, 'apelacao') !== false || strpos($nome, 'agravo') !== false || strpos($nome, 'embargo') !== false) return 'recurso';
    if (strpos($nome, 'cumprimento') !== false || strpos($nome, 'execucao') !== false) return 'cumprimento';
    if (strpos($nome, 'diligencia') !== false || strpos($nome, 'diligência') !== false) return 'diligencia';

    return 'movimentacao';
}

/**
 * Alertar equipe sobre novos movimentos
 */
function datajud_alertar_movimentos($case_id, $qtd, $case) {
    $titulo = $qtd . ' nova(s) movimentacao(oes) — ' . ($case['title'] ?: 'Caso #' . $case_id);
    $link = url('modules/operacional/caso_ver.php?id=' . $case_id);

    // Notificar responsável
    $respId = isset($case['responsible_user_id']) ? (int)$case['responsible_user_id'] : 0;
    if ($respId > 0) {
        notify($respId, $titulo, 'DataJud importou ' . $qtd . ' movimento(s) novo(s)', 'info', $link, '');
    }

    // Notificar gestão
    notify_gestao($titulo, 'DataJud: ' . $qtd . ' movimento(s) novo(s)', 'info', $link, '');
}

/**
 * Registrar log de sincronização
 */
function datajud_registrar_log($pdo, $case_id, $status, $movimentos_novos, $mensagem, $user_id, $tipo) {
    try {
        $pdo->prepare(
            "INSERT INTO datajud_sync_log (case_id, status, movimentos_novos, mensagem, sincronizado_por, tipo, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())"
        )->execute(array($case_id, $status, $movimentos_novos, $mensagem, $user_id, $tipo));
    } catch (Exception $e) {}
}
