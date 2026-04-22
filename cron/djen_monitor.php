<?php
/**
 * ============================================================
 * djen_monitor.php — Robô de monitoramento diário do DJEN
 * ============================================================
 *
 * PROPÓSITO:
 *   Puxa publicações do DJEN para as OABs monitoradas, gera
 *   resumo/orientação via Claude Haiku, monta texto no formato
 *   do parser do Hub e posta no endpoint /api/djen_ingest.php.
 *
 * MODOS DE EXECUÇÃO:
 *   1. CLI (cron cPanel, SSH):
 *      php djen_monitor.php --horario=auto
 *   2. HTTP com token (shell_exec do dashboard, quando disponível):
 *      djen_monitor.php?horario=manual&data=...&token=<TOKEN>
 *   3. Include inline (usado pelo dashboard quando shell_exec
 *      está desabilitado — hospedagem compartilhada):
 *      define('CLAUDIN_NO_AUTORUN', true);
 *      require_once '.../djen_monitor.php';
 *      claudin_executar('manual', '2026-04-22');
 *
 * ============================================================
 */

define('CLAUDIN_INCLUDED', true);

require_once __DIR__ . '/claudin_config.php';
require_once APP_ROOT . '/core/database.php';

date_default_timezone_set(TIMEZONE);
ini_set('display_errors', '0');
error_reporting(E_ALL);

// ============================================================
// Parse de argumento CLI ou $_REQUEST
// ============================================================
if (!function_exists('claudin_arg')) {
    function claudin_arg($chave, $default = null) {
        if (isset($_REQUEST[$chave])) return $_REQUEST[$chave];
        global $argv;
        if (!is_array($argv)) return $default;
        foreach ($argv as $a) {
            if (strpos($a, '--' . $chave . '=') === 0) {
                return substr($a, strlen('--' . $chave . '='));
            }
        }
        return $default;
    }
}

// ============================================================
// Helpers — datas
// ============================================================
function claudin_dia_util_anterior($dataRef) {
    $d = new DateTime($dataRef);
    do {
        $d->modify('-1 day');
    } while ((int)$d->format('N') >= 6);
    return $d->format('Y-m-d');
}

function claudin_calcular_data_fatal($dataDisp, $diasUteis) {
    if ($diasUteis <= 0) return null;
    $d = new DateTime($dataDisp);
    do { $d->modify('+1 day'); } while ((int)$d->format('N') >= 6);
    $cont = 0;
    while ($cont < $diasUteis) {
        $d->modify('+1 day');
        if ((int)$d->format('N') < 6) $cont++;
    }
    return $d->format('Y-m-d');
}

// ============================================================
// Helpers — log
// ============================================================
function claudin_rotacionar_log() {
    if (!file_exists(LOG_PATH)) return;
    if (filesize(LOG_PATH) < LOG_MAX_BYTES) return;
    $alvo = LOG_PATH . '.1';
    if (file_exists($alvo)) @unlink($alvo);
    @rename(LOG_PATH, $alvo);
}

function claudin_log($msg) {
    $dir = dirname(LOG_PATH);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $linha = '[' . date('Y-m-d H:i:s') . '] [PID ' . getmypid() . '] ' . $msg . "\n";
    @file_put_contents(LOG_PATH, $linha, FILE_APPEND);
    if (php_sapi_name() === 'cli') echo $linha;
}

// ============================================================
// Helpers — e-mail, HTTP
// ============================================================
function claudin_enviar_email($assunto, $corpo) {
    $headers = 'From: ' . EMAIL_REMETENTE . "\r\n" .
               'Reply-To: ' . EMAIL_REMETENTE . "\r\n" .
               'Content-Type: text/plain; charset=UTF-8' . "\r\n" .
               'X-Mailer: Claudin-DJEN-Monitor';
    foreach (EMAIL_ALERTAS as $para) {
        $ok = @mail($para, $assunto, $corpo, $headers);
        claudin_log('Email para ' . $para . ': ' . ($ok ? 'OK' : 'FALHA'));
    }
}

function claudin_http_post($url, $bodyJson, $headers = array(), $timeout = 30) {
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $bodyJson,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => false,
    ));
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return array('code' => $code, 'body' => $resp, 'error' => $err);
}

function claudin_http_get($url, $timeout = 45) {
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_HTTPHEADER     => array('Accept: application/json'),
        CURLOPT_SSL_VERIFYPEER => false,
    ));
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return array('code' => $code, 'body' => $resp, 'error' => $err);
}

// ============================================================
// Consulta paginada na API DJEN por OAB
// ============================================================
function claudin_buscar_djen($numeroOab, $uf, $dataAlvo) {
    $items = array();
    $pagina = 1;
    $maxPaginas = 50;

    while ($pagina <= $maxPaginas) {
        $params = array(
            'numeroOab'                  => $numeroOab,
            'ufOab'                      => $uf,
            'dataDisponibilizacaoInicio' => $dataAlvo,
            'dataDisponibilizacaoFim'    => $dataAlvo,
            'itensPorPagina'             => DJEN_ITENS_PAGINA,
            'pagina'                     => $pagina,
        );
        $url = DJEN_API_URL . '?' . http_build_query($params);
        $resp = claudin_http_get($url, DJEN_TIMEOUT_SEG);

        if ($resp['code'] !== 200) {
            throw new Exception('DJEN HTTP ' . $resp['code'] . ' OAB ' . $numeroOab . '/' . $uf . ' pag ' . $pagina . ': ' . substr($resp['body'] ?: $resp['error'], 0, 200));
        }
        $data = json_decode($resp['body'], true);
        if (!is_array($data)) {
            throw new Exception('DJEN resposta não-JSON OAB ' . $numeroOab . '/' . $uf);
        }

        $lote = array();
        if (isset($data['items']) && is_array($data['items'])) $lote = $data['items'];
        elseif (isset($data['data']) && is_array($data['data'])) $lote = $data['data'];
        elseif (isset($data[0]) && is_array($data[0])) $lote = $data;

        if (empty($lote)) break;
        $items = array_merge($items, $lote);
        if (count($lote) < DJEN_ITENS_PAGINA) break;
        $pagina++;
    }
    return $items;
}

// ============================================================
// Claude Haiku — resumo + orientação com retry
// ============================================================
function claudin_chamar_claude($item, $dataFatalSugerida) {
    $apiKey = defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : '';
    if (!$apiKey || $apiKey === 'SUA_CHAVE_AQUI') {
        return array(
            'resumo'     => '[FALHA AI — chave Anthropic não configurada]',
            'orientacao' => 'Revisar publicação manualmente no Conecta.',
            'falhou'     => true,
        );
    }

    $teor = isset($item['texto']) ? $item['texto'] : '';
    $tipo = isset($item['tipoComunicacao']) ? $item['tipoComunicacao'] : 'Publicação';
    $orgao = isset($item['nomeOrgao']) ? $item['nomeOrgao'] : '';
    $dataDisp = isset($item['data_disponibilizacao']) ? $item['data_disponibilizacao'] : '';
    $dataDispFmt = $dataDisp ? date('d/m/Y', strtotime($dataDisp)) : '';
    $dataFatalFmt = $dataFatalSugerida ? date('d/m/Y', strtotime($dataFatalSugerida)) : null;

    $teor = mb_substr(trim($teor), 0, 4000, 'UTF-8');

    $systemPrompt = "Você é um paralegal experiente de um escritório de advocacia brasileiro (Ferreira & Sá Advocacia). Sua tarefa é analisar publicações do DJEN e produzir dois outputs curtíssimos:\n\n"
        . "1. RESUMO (até 25 palavras): o que a publicação comunica, em português claro, sem juridiquês.\n"
        . "2. ORIENTAÇÃO (até 35 palavras): o que o advogado deve fazer e em quanto tempo.\n"
        . "   - Regra CPC art. 219: prazos em dias úteis.\n"
        . "   - Regra CPC art. 224 §3º: termo inicial é o primeiro dia útil seguinte à disponibilização.\n"
        . "   - Quando houver prazo, inclua a data fatal no formato DD/MM/AAAA.\n"
        . "   - Para atos ordinatórios SEM prazo do advogado: \"Aguardar próximo despacho. Sem prazo imediato.\"\n"
        . "   - Para listas de distribuição: \"Ciência da distribuição. Sem prazo imediato.\"\n\n"
        . "Responda EXCLUSIVAMENTE em JSON válido, sem markdown, no formato:\n"
        . '{"resumo":"...","orientacao":"..."}';

    $userMsg = "Tipo: {$tipo}\nÓrgão: {$orgao}\nData de disponibilização: {$dataDispFmt}\n";
    if ($dataFatalFmt) $userMsg .= "Data fatal aproximada (15 dias úteis): {$dataFatalFmt} — use-a se aplicável.\n";
    $userMsg .= "\nTeor integral:\n{$teor}";

    $body = json_encode(array(
        'model'      => ANTHROPIC_MODEL,
        'max_tokens' => ANTHROPIC_MAX_TOKENS,
        'system'     => $systemPrompt,
        'messages'   => array(array('role' => 'user', 'content' => $userMsg)),
    ));

    $headers = array(
        'Content-Type: application/json',
        'x-api-key: ' . $apiKey,
        'anthropic-version: 2023-06-01',
    );

    $backoffs = ANTHROPIC_RETRY_BACKOFF;
    $ultimoErro = '';
    for ($tentativa = 1; $tentativa <= ANTHROPIC_RETRY_TENTATIVAS; $tentativa++) {
        $resp = claudin_http_post('https://api.anthropic.com/v1/messages', $body, $headers, ANTHROPIC_TIMEOUT_SEG);
        if ($resp['code'] >= 200 && $resp['code'] < 300) {
            $data = json_decode($resp['body'], true);
            $texto = isset($data['content'][0]['text']) ? trim($data['content'][0]['text']) : '';
            if ($texto) {
                if (preg_match('/\{[\s\S]*\}/', $texto, $m)) $texto = $m[0];
                $json = json_decode($texto, true);
                if (is_array($json) && isset($json['resumo'], $json['orientacao'])) {
                    return array(
                        'resumo'     => trim($json['resumo']),
                        'orientacao' => trim($json['orientacao']),
                        'falhou'     => false,
                    );
                }
            }
            $ultimoErro = 'resposta inesperada';
        } else {
            $ultimoErro = 'HTTP ' . $resp['code'] . ' ' . substr($resp['body'] ?: $resp['error'], 0, 120);
        }

        claudin_log('Claude tentativa ' . $tentativa . ' falhou: ' . $ultimoErro);
        if ($tentativa < ANTHROPIC_RETRY_TENTATIVAS) {
            $esp = isset($backoffs[$tentativa - 1]) ? $backoffs[$tentativa - 1] : 60;
            sleep($esp);
        }
    }

    return array(
        'resumo'     => '[FALHA AI — revisar manualmente]',
        'orientacao' => 'Revisar publicação manualmente no Conecta.',
        'falhou'     => true,
    );
}

// ============================================================
// Helpers — detecção de segredo + montagem de bloco
// ============================================================
function claudin_tem_segredo($item) {
    $orgao = isset($item['nomeOrgao']) ? $item['nomeOrgao'] : '';
    $classe = isset($item['classe']) ? $item['classe'] : '';
    if (preg_match('/Fam[íi]lia|Inf[âa]ncia|Adolesc[êe]ncia|Crian[cç]a/ui', $orgao)) return true;
    if (preg_match('/Fam[íi]lia|Alimentos|Divorcio|Div[óo]rcio|Guarda|Inf[âa]ncia/ui', $classe)) return true;
    if (!empty($item['destinatarios']) && is_array($item['destinatarios'])) {
        foreach ($item['destinatarios'] as $d) {
            $nome = isset($d['nome']) ? $d['nome'] : '';
            if (stripos($nome, 'EM SEGREDO') !== false) return true;
        }
    }
    return false;
}

function claudin_montar_bloco($item, $resumo, $orientacao) {
    $cnj = isset($item['numeroprocessocommascara']) ? $item['numeroprocessocommascara'] : (isset($item['numero_processo']) ? $item['numero_processo'] : '');
    $orgao = isset($item['nomeOrgao']) ? $item['nomeOrgao'] : '';
    $sigla = isset($item['siglaTribunal']) ? $item['siglaTribunal'] : '';
    $orgaoCompleto = trim($orgao . ($sigla ? ' — ' . $sigla : ''));
    $dataDisp = isset($item['data_disponibilizacao']) ? $item['data_disponibilizacao'] : '';
    $dataDispFmt = $dataDisp ? date('d/m/Y', strtotime($dataDisp)) : '';
    $tipo = isset($item['tipoComunicacao']) ? $item['tipoComunicacao'] : 'Intimação';
    $teor = isset($item['texto']) ? trim($item['texto']) : '';

    $partes = array();
    if (!empty($item['destinatarios']) && is_array($item['destinatarios'])) {
        foreach ($item['destinatarios'] as $d) {
            $n = isset($d['nome']) ? trim($d['nome']) : '';
            if ($n && stripos($n, 'EM SEGREDO') === false) $partes[] = $n;
        }
    }

    $advs = array();
    if (!empty($item['destinatarioadvogados']) && is_array($item['destinatarioadvogados'])) {
        foreach ($item['destinatarioadvogados'] as $a) {
            $adv = isset($a['advogado']) ? $a['advogado'] : array();
            $nome = isset($adv['nome']) ? trim($adv['nome']) : '';
            $oab = isset($adv['numero_oab']) ? trim($adv['numero_oab']) : '';
            $oabUf = isset($adv['uf_oab']) ? trim($adv['uf_oab']) : '';
            if ($nome) {
                $linha = $nome;
                if ($oab) $linha .= ' - OAB/' . ($oabUf ?: 'RJ') . ' ' . $oab;
                $advs[] = $linha;
            }
        }
    }

    $linhas = array();
    $linhas[] = 'Processo ' . $cnj;
    $linhas[] = 'Orgão: ' . $orgaoCompleto;
    $linhas[] = 'Data de disponibilização: ' . $dataDispFmt;
    $linhas[] = 'Tipo de comunicação: ' . $tipo;
    $linhas[] = '';
    $linhas[] = 'Parte(s)';
    if ($partes) foreach ($partes as $p) $linhas[] = '- ' . $p; else $linhas[] = '- (não informadas)';
    $linhas[] = '';
    $linhas[] = 'Advogado(s)';
    if ($advs) foreach ($advs as $a) $linhas[] = '- ' . $a; else $linhas[] = '- (não informados)';
    $linhas[] = '';
    $linhas[] = 'Resumo: ' . $resumo;
    $linhas[] = 'Orientação: ' . $orientacao;

    if (claudin_tem_segredo($item)) {
        $linhas[] = '';
        $linhas[] = 'SEGREDO DE JUSTIÇA';
    }

    $linhas[] = '';
    $linhas[] = $teor;

    return implode("\n", $linhas);
}

function claudin_enviar_ao_ingest($payloadText) {
    $body = json_encode(array('text' => $payloadText));
    $headers = array('Content-Type: application/json');
    $resp = claudin_http_post(DJEN_INGEST_URL, $body, $headers, 90);
    if ($resp['code'] !== 200) {
        throw new Exception('Ingest HTTP ' . $resp['code'] . ': ' . substr($resp['body'] ?: $resp['error'], 0, 300));
    }
    $data = json_decode($resp['body'], true);
    if (!is_array($data) || !isset($data['ok'])) {
        throw new Exception('Ingest resposta inválida: ' . substr($resp['body'], 0, 200));
    }
    return $data;
}

function claudin_salvar_run($pdo, $exeEm, $dataAlvo, $horario, $contadores, $status, $oabsStr, $tempo, $payloadBytes, $erroTexto = null) {
    try {
        $pdo->prepare(
            "INSERT INTO claudin_runs
             (executado_em, data_alvo, horario, total_parsed, imported, duplicated, pending, errors,
              oabs_consultadas, tempo_execucao_segundos, status, payload_bytes, erro_texto)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)"
        )->execute(array(
            $exeEm, $dataAlvo, $horario,
            $contadores['total_parsed'], $contadores['imported'],
            $contadores['duplicated'], $contadores['pending'], $contadores['errors'],
            $oabsStr, $tempo, $status, $payloadBytes, $erroTexto,
        ));
        return (int)$pdo->lastInsertId();
    } catch (Exception $e) {
        claudin_log('ERRO ao gravar claudin_runs: ' . $e->getMessage());
        return 0;
    }
}

// ============================================================
// FUNÇÃO PRINCIPAL — pode ser chamada via include
// ============================================================
function claudin_executar($horario, $dataAlvo) {
    claudin_rotacionar_log();
    claudin_log(str_repeat('=', 60));
    claudin_log("Claudin iniciado — horario={$horario} data_alvo={$dataAlvo}");

    $tIni = microtime(true);
    $exeEm = date('Y-m-d H:i:s');

    try {
        $pdo = db();
    } catch (Exception $e) {
        claudin_log('ERRO conexão DB: ' . $e->getMessage());
        throw $e;
    }

    $oabsLabels = array();
    foreach (OABS_MONITORADAS as $oab) {
        $oabsLabels[] = $oab[0] . '/' . $oab[1];
    }
    $oabsStr = implode(', ', $oabsLabels);

    // 1 — Coleta items, deduplicando por hash
    $itemsPorHash = array();
    foreach (OABS_MONITORADAS as $oab) {
        list($numero, $uf, $nome) = $oab;
        try {
            claudin_log("→ Consultando DJEN: OAB {$numero}/{$uf} ({$nome})");
            $lote = claudin_buscar_djen($numero, $uf, $dataAlvo);
            claudin_log("  {$numero}/{$uf}: " . count($lote) . ' publicações');
            foreach ($lote as $it) {
                $hash = isset($it['hash']) ? $it['hash'] : md5(json_encode($it));
                if (!isset($itemsPorHash[$hash])) $itemsPorHash[$hash] = $it;
            }
        } catch (Exception $e) {
            claudin_log('  ERRO na OAB ' . $numero . '/' . $uf . ': ' . $e->getMessage());
        }
    }

    $totalUnico = count($itemsPorHash);
    claudin_log("Total único (pós-deduplicação por hash): {$totalUnico}");

    // 2 — Zero pubs
    if ($totalUnico === 0) {
        $tempo = round(microtime(true) - $tIni, 2);
        claudin_salvar_run(
            $pdo, $exeEm, $dataAlvo, $horario,
            array('total_parsed'=>0,'imported'=>0,'duplicated'=>0,'pending'=>0,'errors'=>0),
            'parcial', $oabsStr, $tempo, 0
        );
        claudin_log("Nenhuma publicação encontrada para {$dataAlvo}.");
        claudin_enviar_email(
            'Claudin ' . $horario . 'h: 0 publicações em ' . date('d/m/Y', strtotime($dataAlvo)),
            "Nenhuma publicação encontrada.\n\nData-alvo: {$dataAlvo}\nOABs consultadas: {$oabsStr}\nDuração: {$tempo}s\n\nSe a data tem publicações esperadas, verifique a API do DJEN ou o log: " . LOG_PATH
        );
        return array('status'=>'parcial','total_parsed'=>0,'tempo'=>$tempo);
    }

    // 3 — Pra cada pub, pede resumo/orientação ao Claude e monta bloco
    $blocos = array();
    $prazoDefault = 15;
    foreach ($itemsPorHash as $hash => $it) {
        $dataDispItem = isset($it['data_disponibilizacao']) ? $it['data_disponibilizacao'] : $dataAlvo;
        $dataFatal = claudin_calcular_data_fatal($dataDispItem, $prazoDefault);
        $ia = claudin_chamar_claude($it, $dataFatal);
        $bloco = claudin_montar_bloco($it, $ia['resumo'], $ia['orientacao']);
        $blocos[] = $bloco;
    }

    $payload = implode("\n\n", $blocos) . "\n";
    $payloadBytes = strlen($payload);
    claudin_log("Payload montado: {$totalUnico} blocos, {$payloadBytes} bytes");

    // 4 — Envia ao endpoint
    try {
        $respIngest = claudin_enviar_ao_ingest($payload);
    } catch (Exception $e) {
        $tempo = round(microtime(true) - $tIni, 2);
        claudin_salvar_run(
            $pdo, $exeEm, $dataAlvo, $horario,
            array('total_parsed'=>$totalUnico,'imported'=>0,'duplicated'=>0,'pending'=>0,'errors'=>$totalUnico),
            'falha', $oabsStr, $tempo, $payloadBytes, $e->getMessage()
        );
        claudin_log('FALHA NO INGEST: ' . $e->getMessage());
        claudin_enviar_email(
            'Claudin ' . $horario . 'h: ERRO ao enviar ao Hub',
            "Falha ao postar {$totalUnico} publicações no endpoint do Hub.\n\nErro: " . $e->getMessage() . "\n\nLog: " . LOG_PATH
        );
        throw $e;
    }

    $contadores = array(
        'total_parsed' => isset($respIngest['total_parsed']) ? (int)$respIngest['total_parsed'] : 0,
        'imported'     => isset($respIngest['imported']) ? (int)$respIngest['imported'] : 0,
        'duplicated'   => isset($respIngest['duplicated']) ? (int)$respIngest['duplicated'] : 0,
        'pending'      => isset($respIngest['pending']) ? (int)$respIngest['pending'] : 0,
        'errors'       => isset($respIngest['errors']) ? count($respIngest['errors']) : 0,
    );

    $somaContadores = $contadores['imported'] + $contadores['duplicated'] + $contadores['pending'] + $contadores['errors'];
    $invarianteOk = ($contadores['total_parsed'] === $somaContadores);

    $status = 'ok';
    if (!$invarianteOk || $contadores['errors'] > 0) $status = 'falha';
    elseif ($contadores['pending'] > 0) $status = 'parcial';

    $tempo = round(microtime(true) - $tIni, 2);
    claudin_salvar_run($pdo, $exeEm, $dataAlvo, $horario, $contadores, $status, $oabsStr, $tempo, $payloadBytes);

    claudin_log(sprintf(
        "Resultado: parsed=%d imported=%d duplicated=%d pending=%d errors=%d status=%s tempo=%ss",
        $contadores['total_parsed'], $contadores['imported'], $contadores['duplicated'],
        $contadores['pending'], $contadores['errors'], $status, $tempo
    ));

    // 5 — E-mail de alerta quando necessário
    $precisaEmail = false;
    $motivos = array();
    if (!$invarianteOk) { $precisaEmail = true; $motivos[] = "invariante quebrada (parsed={$contadores['total_parsed']}, soma={$somaContadores})"; }
    if ($contadores['errors'] > 0) { $precisaEmail = true; $motivos[] = "{$contadores['errors']} erros de importação"; }
    if ($contadores['pending'] > 0) { $precisaEmail = true; $motivos[] = "{$contadores['pending']} pendentes precisam de vinculação"; }

    if ($precisaEmail) {
        $assunto = 'Claudin ' . $horario . 'h: ' .
            ($contadores['pending'] > 0 ? $contadores['pending'] . ' pendentes precisam de vinculação'
                : ($contadores['errors'] > 0 ? 'ERROS na importação' : 'atenção necessária'));

        $corpo = "Execução: " . date('d/m/Y H:i', strtotime($exeEm)) . "\n"
               . "Data-alvo: " . date('d/m/Y', strtotime($dataAlvo)) . "\n"
               . "OABs: {$oabsStr}\n"
               . "Tempo: {$tempo}s\n\n"
               . "Parsed: {$contadores['total_parsed']}\n"
               . "Importadas: {$contadores['imported']}\n"
               . "Duplicadas: {$contadores['duplicated']}\n"
               . "Pendentes: {$contadores['pending']}\n"
               . "Erros: {$contadores['errors']}\n\n"
               . "Motivos: " . implode('; ', $motivos) . "\n\n";

        if ($contadores['pending'] > 0 && !empty($respIngest['details'])) {
            $corpo .= "CNJs pendentes:\n";
            foreach ($respIngest['details'] as $d) {
                if (isset($d['status']) && $d['status'] === 'pending' && isset($d['numero'])) {
                    $corpo .= '  - ' . $d['numero'] . "\n";
                }
            }
            $corpo .= "\n";
        }

        $corpo .= "Revisar em: https://ferreiraesa.com.br/conecta/modules/admin/djen_importar.php\n"
               .  "Dashboard: https://ferreiraesa.com.br/conecta/modules/admin/claudin_dashboard.php\n"
               .  "Log: " . LOG_PATH . "\n";

        claudin_enviar_email($assunto, $corpo);
    }

    claudin_log("Claudin finalizado com sucesso.");
    return array('status'=>$status,'contadores'=>$contadores,'tempo'=>$tempo);
}

// ============================================================
// AUTO-RUN — só se não foi marcado "no autorun" (include manual)
// ============================================================
if (!defined('CLAUDIN_NO_AUTORUN')) {
    $isCli = (php_sapi_name() === 'cli');
    if (!$isCli) {
        $tokenRecebido = isset($_REQUEST['token']) ? $_REQUEST['token'] : '';
        if (!hash_equals(CLAUDIN_MANUAL_TOKEN, $tokenRecebido)) {
            http_response_code(403);
            exit('Acesso negado.');
        }
    }

    $horarioArg = claudin_arg('horario', 'auto');
    $dataAlvoArg = claudin_arg('data', null);

    if (!in_array($horarioArg, array('08', '19', 'manual', 'auto'), true)) {
        echo "ERRO: --horario deve ser 08, 19, manual ou auto\n";
        exit(1);
    }

    if ($horarioArg === 'auto') {
        $horarioArg = ((int)date('H') < 12) ? '08' : '19';
    }

    if ($horarioArg === 'manual') {
        if (!$dataAlvoArg || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataAlvoArg)) {
            echo "ERRO: --horario=manual exige --data=YYYY-MM-DD\n";
            exit(1);
        }
        $dataAlvoCalc = $dataAlvoArg;
    } elseif ($horarioArg === '08') {
        $dataAlvoCalc = claudin_dia_util_anterior(date('Y-m-d'));
    } else {
        $dataAlvoCalc = date('Y-m-d');
    }

    set_exception_handler(function($e) use ($horarioArg, $dataAlvoCalc) {
        claudin_log('EXCEPTION FATAL: ' . $e->getMessage());
        try {
            $pdo = db();
            claudin_salvar_run(
                $pdo, date('Y-m-d H:i:s'), $dataAlvoCalc, $horarioArg,
                array('total_parsed'=>0,'imported'=>0,'duplicated'=>0,'pending'=>0,'errors'=>1),
                'falha', '', 0, 0, $e->getMessage()
            );
        } catch (Exception $e2) {}
        claudin_enviar_email(
            'Claudin ' . $horarioArg . 'h: ERRO de infraestrutura',
            "Execução abortou por falha grave.\n\nData-alvo: {$dataAlvoCalc}\nErro: " . $e->getMessage() . "\n\nLog: " . LOG_PATH
        );
        exit(1);
    });

    claudin_executar($horarioArg, $dataAlvoCalc);
    exit(0);
}
