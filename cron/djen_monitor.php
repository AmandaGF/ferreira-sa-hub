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
// Helpers — e-mail via Brevo (API autenticada, SPF/DKIM ok)
// ============================================================
// Lê credenciais da tabela configuracoes (brevo_api_key,
// brevo_sender_email, brevo_sender_name). mail() nativo não
// entregava porque o domínio não tinha SPF permitindo a
// TurboCloud enviar — Brevo resolve isso.
function claudin_enviar_email($assunto, $corpo, $htmlCustom = null) {
    try {
        $pdo = db();
    } catch (Exception $e) {
        return;
    }

    $brevoCfg = array('key' => '', 'email' => '', 'name' => 'Claudin DJEN');
    try {
        $rows = $pdo->query("SELECT chave, valor FROM configuracoes WHERE chave LIKE 'brevo_%'")->fetchAll();
        foreach ($rows as $r) {
            if ($r['chave'] === 'brevo_api_key')      $brevoCfg['key']   = $r['valor'];
            if ($r['chave'] === 'brevo_sender_email') $brevoCfg['email'] = $r['valor'];
            if ($r['chave'] === 'brevo_sender_name')  $brevoCfg['name']  = $r['valor'];
        }
    } catch (Exception $e) {
        claudin_log('Email: erro ao ler config Brevo — ' . $e->getMessage());
        return;
    }

    if (!$brevoCfg['key'] || !$brevoCfg['email']) {
        claudin_log('Email: Brevo não configurado (brevo_api_key ou brevo_sender_email vazio).');
        return;
    }

    // Amanda 09/07/2026: se veio HTML pronto (email diário estilizado), usa direto;
    // senão faz o fallback antigo (nl2br do texto plain).
    if ($htmlCustom !== null && $htmlCustom !== '') {
        $htmlBody = $htmlCustom;
    } else {
        $htmlBody = '<div style="font-family:Segoe UI,Arial,sans-serif;font-size:14px;color:#222;line-height:1.55;padding:10px;">'
                  . nl2br(htmlspecialchars($corpo, ENT_QUOTES, 'UTF-8'))
                  . '</div>';
    }

    foreach (EMAIL_ALERTAS as $para) {
        $data = array(
            'sender'      => array('name' => $brevoCfg['name'], 'email' => $brevoCfg['email']),
            'to'          => array(array('email' => $para)),
            'subject'     => $assunto,
            'htmlContent' => $htmlBody,
            'textContent' => $corpo,
        );

        $ch = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => array(
                'api-key: ' . $brevoCfg['key'],
                'Content-Type: application/json',
                'Accept: application/json',
            ),
            CURLOPT_POSTFIELDS     => json_encode($data),
            CURLOPT_SSL_VERIFYPEER => true,
        ));
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code >= 200 && $code < 300) {
            claudin_log('Email Brevo para ' . $para . ': OK (HTTP ' . $code . ')');
        } else {
            claudin_log('Email Brevo para ' . $para . ': FALHA HTTP ' . $code . ' — ' . substr((string)$resp, 0, 200));
        }
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
    $classe = isset($item['classe']) ? $item['classe'] : '';

    $systemPrompt = "Você é um paralegal experiente de um escritório de advocacia brasileiro (Ferreira & Sá Advocacia). Sua tarefa é analisar publicações do DJEN e produzir dois outputs curtíssimos.\n\n"
        . "ANTES DE TUDO, identifique o RITO/PROCEDIMENTO pelo órgão e pela classe processual. A escolha correta do prazo depende disso:\n\n"
        . "--- TABELA DE PRAZOS POR RITO ---\n"
        . "▸ JUIZADO ESPECIAL CÍVEL (Lei 9.099/95) — órgão contém 'Juizado Especial'/'JEC'/'Vara do Juizado':\n"
        . "    • Recurso Inominado: 10 dias úteis (NÃO é apelação!)\n"
        . "    • Embargos de declaração: 5 dias úteis\n"
        . "    • Recurso especial/extraordinário: 15 dias úteis\n"
        . "▸ JUIZADO ESPECIAL DA FAZENDA (Lei 12.153/2009) — 'Juizado Especial da Fazenda Pública':\n"
        . "    • Recurso Inominado: 10 dias úteis\n"
        . "▸ JUIZADO ESPECIAL FEDERAL (Lei 10.259/2001): igual ao JEC (Recurso Inominado 10 dias).\n"
        . "▸ JECRIM (Lei 9.099/95 — Juizado Especial Criminal): Apelação 10 dias.\n"
        . "▸ JUSTIÇA COMUM CÍVEL (CPC): Apelação 15 dias úteis, Embargos Decl 5 dias úteis, Agravo Instrumento 15 dias úteis, Contestação 15 dias úteis.\n"
        . "▸ JUSTIÇA DO TRABALHO (CLT): Recurso Ordinário 8 dias úteis, Agravo Petição 8 dias, Embargos Decl 5 dias úteis.\n"
        . "▸ JUSTIÇA PENAL COMUM (CPP, dias CORRIDOS): Apelação 5 dias, Embargos Decl 2 dias, RESE 5 dias.\n"
        . "▸ FAMÍLIA (CPC, dias úteis): Apelação 15 dias.\n\n"
        . "REGRAS GERAIS:\n"
        . "- CPC art. 219: prazos civis em dias úteis (exceto se lei dispuser diferente, ex: CPP).\n"
        . "- CPC art. 224 §3º: termo inicial é o primeiro dia útil seguinte à disponibilização.\n"
        . "- NUNCA diga 'apelação' quando for JEC — é Recurso Inominado.\n"
        . "- Sempre nomear o recurso correto para o rito identificado.\n\n"
        . "⛔ VOCABULÁRIO PROIBIDO (erros comuns de IA que não podem sair):\n"
        . "  ❌ 'TRÉPLICA' — NÃO EXISTE no CPC. O procedimento comum tem: petição inicial → contestação (réu) → RÉPLICA (autor, art. 350-351 CPC, 15 dias úteis) → provas → sentença. NÃO há resposta à réplica.\n"
        . "  ❌ 'CONTRARRAZÃO' fora de contexto de recurso. Contrarrazões só existem em resposta a APELAÇÃO/RECURSO. Ato ordinatório 'ao autor, em réplica' NÃO é resposta a contrarrazão — é intimação pra o AUTOR se manifestar sobre a CONTESTAÇÃO do réu.\n"
        . "  ❌ 'DÚPLICA' — não existe.\n\n"
        . "✅ ATOS ORDINATÓRIOS COMUNS — traduza corretamente:\n"
        . "  • 'ao autor, em réplica' / 'à parte autora, em réplica' → Autor deve apresentar RÉPLICA (15 dias úteis) contestando os argumentos da defesa apresentada pelo réu. NÃO é tréplica, NÃO é contrarrazão.\n"
        . "  • 'às partes, especifiquem provas' → Ambas as partes devem indicar as provas que pretendem produzir (15 dias úteis).\n"
        . "  • 'ciência às partes' / 'dê-se ciência' → Ciência simples, sem prazo do advogado (a menos que texto especifique).\n"
        . "  • 'em contrarrazões' → SÓ quando é recurso (apelação, agravo, etc). Não confundir com réplica.\n\n"
        . "OUTPUTS:\n"
        . "1. RESUMO (até 25 palavras): o que a publicação comunica, em português claro, sem juridiquês.\n"
        . "2. ORIENTAÇÃO (até 45 palavras): o que o advogado deve fazer e em quanto tempo. Mencione o rito identificado quando houver prazo (ex: 'JEC — interpor Recurso Inominado em 10 dias úteis até DD/MM/AAAA').\n"
        . "   - Inclua data fatal no formato DD/MM/AAAA quando houver prazo.\n"
        . "   - Para atos ordinatórios SEM prazo do advogado: \"Aguardar próximo despacho. Sem prazo imediato.\"\n"
        . "   - Para listas de distribuição: \"Ciência da distribuição. Sem prazo imediato.\"\n\n"
        . "Responda EXCLUSIVAMENTE em JSON válido, sem markdown, no formato:\n"
        . '{"resumo":"...","orientacao":"..."}';

    $userMsg = "Tipo: {$tipo}\nÓrgão: {$orgao}\n";
    if ($classe) $userMsg .= "Classe processual: {$classe}\n";
    $userMsg .= "Data de disponibilização: {$dataDispFmt}\n";
    if ($dataFatalFmt) $userMsg .= "Data fatal de referência (assumindo 15 dias úteis padrão): {$dataFatalFmt} — AJUSTE se o rito for diferente (ex: JEC/Inominado = 10 dias).\n";
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
                    $_resumo = trim($json['resumo']);
                    $_orient = trim($json['orientacao']);
                    // Amanda 22/07/2026: guard-rail contra vocabulário proibido
                    // ('tréplica' não existe no CPC comum; 'contrarrazão' só em
                    // recurso). Se aparecer, retry — nao envia texto errado.
                    $_txtCombo = mb_strtolower($_resumo . ' ' . $_orient, 'UTF-8');
                    $_temErroConceitual = preg_match('/tr[ée]plica|d[uú]plica/i', $_txtCombo)
                        || (preg_match('/contrarraz[aã]o|contrarrazoes|contrarrazões/i', $_txtCombo)
                            && !preg_match('/apela[cç][aã]o|recurso inominado|recurso ordin[aá]rio|agravo|recurso especial|recurso extraordin[aá]rio|embargos infringentes/i', $_txtCombo));
                    if ($_temErroConceitual && $tentativa < ANTHROPIC_RETRY_TENTATIVAS) {
                        $ultimoErro = 'guard-rail: texto contem termo conceitualmente errado (tréplica/contrarrazão fora de recurso). Retry.';
                        claudin_log($ultimoErro . ' — resumo=' . mb_substr($_resumo, 0, 80));
                        // Cai no retry (nao retorna)
                    } else {
                        return array(
                            'resumo'     => $_resumo,
                            'orientacao' => $_orient,
                            'falhou'     => false,
                        );
                    }
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

    // Amanda 09/07/2026: email diário de recortes (estilo LegalOne).
    // Envia SEMPRE que houver publicações importadas ou pendentes (sem case).
    // Formato HTML rico, agrupado por tribunal, com botões clicáveis pra pasta.
    try {
        if ($contadores['imported'] > 0 || $contadores['pending'] > 0) {
            $emailDiario = claudin_montar_email_diario_html($pdo, $dataAlvo, $contadores, $horario);
            if ($emailDiario && !empty($emailDiario['html'])) {
                claudin_enviar_email($emailDiario['assunto'], $emailDiario['texto'], $emailDiario['html']);
                claudin_log('Email diário de recortes enviado ({assunto}=' . $emailDiario['assunto'] . ')');
            }
        }
    } catch (Exception $eE) {
        claudin_log('Falha ao montar/enviar email diário: ' . $eE->getMessage());
    }

    claudin_log("Claudin finalizado com sucesso.");
    return array('status'=>$status,'contadores'=>$contadores,'tempo'=>$tempo);
}

// ============================================================
// Email diário estilo LegalOne — Amanda 09/07/2026
// Monta HTML rico com publicações capturadas hoje, agrupadas por tribunal,
// com botões clicáveis pra abrir a pasta no Hub.
// ============================================================
function claudin_montar_email_diario_html($pdo, $dataAlvo, $contadores, $horario) {
    // Busca publicações importadas HOJE (na tabela case_publicacoes),
    // vinculadas a case + cliente pra ter dados completos
    $st = $pdo->prepare(
        "SELECT p.id, p.case_id, p.data_disponibilizacao, p.tribunal, p.tipo_publicacao,
                p.prazo_dias, p.data_prazo_fim, p.resumo_ia, p.orientacao_ia, p.conteudo,
                p.created_at,
                c.title AS case_title, c.case_number,
                cl.name AS client_name
         FROM case_publicacoes p
         LEFT JOIN cases c ON c.id = p.case_id
         LEFT JOIN clients cl ON cl.id = c.client_id
         WHERE p.data_disponibilizacao = ? OR DATE(p.created_at) = CURDATE()
         ORDER BY p.tribunal, p.created_at DESC
         LIMIT 100"
    );
    $st->execute(array($dataAlvo));
    $pubs = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$pubs) return null;

    $totalCap = count($pubs);
    $dataFmt = date('d/m/Y', strtotime($dataAlvo));
    $assunto = '📬 Recortes DJEN ' . $dataFmt . ' — ' . $totalCap . ' publicaç' . ($totalCap === 1 ? 'ão' : 'ões') . ' capturada' . ($totalCap === 1 ? '' : 's');

    // Agrupar por tribunal
    $porTribunal = array();
    foreach ($pubs as $p) {
        $key = trim((string)$p['tribunal']) ?: 'Sem tribunal identificado';
        if (!isset($porTribunal[$key])) $porTribunal[$key] = array();
        $porTribunal[$key][] = $p;
    }

    $tiposLbl = array(
        'intimacao' => '📢 Intimação', 'citacao' => '📩 Citação',
        'despacho' => '⚖️ Despacho', 'decisao' => '⚖️ Decisão',
        'sentenca' => '🏛️ Sentença', 'acordao' => '🏛️ Acórdão',
        'edital' => '📃 Edital', 'outro' => '📄 Publicação',
    );

    // ---------- HTML ----------
    $h = '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
       . '<body style="margin:0;background:#faf7f2;font-family:Georgia,\'Times New Roman\',serif;color:#1a1a1f;">'
       . '<div style="max-width:660px;margin:0 auto;background:#faf7f2;">'

       // Header
       . '<div style="background:linear-gradient(135deg,#052228,#0a3238);color:#fff;padding:1.6rem 1.4rem;border-radius:0 0 12px 12px;">'
       . '<div style="font-size:.7rem;letter-spacing:.14em;text-transform:uppercase;color:#B87333;font-family:-apple-system,\'Segoe UI\',Arial,sans-serif;font-weight:700;margin-bottom:.4rem;">Ferreira &amp; Sá Advocacia · Recortes do dia</div>'
       . '<h1 style="margin:0;font-size:1.6rem;font-weight:600;line-height:1.2;font-family:Georgia,serif;">' . $totalCap . ' publicaç' . ($totalCap === 1 ? 'ão' : 'ões') . ' capturada' . ($totalCap === 1 ? '' : 's') . ' em ' . $dataFmt . '</h1>'
       . '<p style="margin:.6rem 0 0;font-size:.9rem;opacity:.85;font-family:-apple-system,\'Segoe UI\',Arial,sans-serif;">Execução ' . htmlspecialchars($horario, ENT_QUOTES, 'UTF-8') . 'h · '
       . (int)$contadores['imported'] . ' importadas · ' . (int)$contadores['duplicated'] . ' duplicadas · '
       . (int)$contadores['pending'] . ' aguardando vínculo</p>'
       . '</div>';

    // Blocos por tribunal
    foreach ($porTribunal as $trib => $itens) {
        $h .= '<div style="padding:1.4rem 1.2rem .4rem;">'
            . '<div style="font-family:-apple-system,\'Segoe UI\',Arial,sans-serif;font-size:.68rem;letter-spacing:.12em;text-transform:uppercase;color:#B87333;font-weight:700;padding-bottom:.4rem;border-bottom:1px solid #d5cdba;margin-bottom:.9rem;">🏛️ ' . htmlspecialchars($trib, ENT_QUOTES, 'UTF-8') . ' · ' . count($itens) . '</div>'
            . '</div>';

        foreach ($itens as $p) {
            $tipoLbl = $tiposLbl[$p['tipo_publicacao']] ?? '📄 Publicação';
            $temPasta = !empty($p['case_id']);
            $urlPasta = $temPasta ? ('https://ferreiraesa.com.br/conecta/modules/operacional/caso_ver.php?id=' . (int)$p['case_id']) : '';
            $descrPreview = trim(preg_replace('/\s+/', ' ', strip_tags((string)$p['conteudo'])));
            if (mb_strlen($descrPreview) > 320) $descrPreview = mb_substr($descrPreview, 0, 320) . '…';

            $prazoTxt = '';
            if (!empty($p['data_prazo_fim'])) {
                $diasRest = (int)((strtotime($p['data_prazo_fim']) - strtotime(date('Y-m-d'))) / 86400);
                $corPrazo = $diasRest <= 3 ? '#a33a2a' : ($diasRest <= 7 ? '#c76e15' : '#065f46');
                $prazoTxt = '<div style="display:inline-block;background:' . $corPrazo . '15;border:1px solid ' . $corPrazo . '40;color:' . $corPrazo . ';padding:4px 10px;border-radius:6px;font-size:.75rem;font-weight:700;margin-top:.5rem;">⏰ Prazo fatal ' . date('d/m/Y', strtotime($p['data_prazo_fim'])) . ' (' . $diasRest . 'd)</div>';
            }

            $h .= '<div style="margin:0 1.2rem 1rem;background:#fff;border:1px solid #e3ddcf;border-left:4px solid #B87333;border-radius:8px;padding:1rem 1.1rem;box-shadow:0 1px 2px rgba(5,34,40,.04);">'

                // Cabeçalho do card
                . '<div style="font-family:-apple-system,\'Segoe UI\',Arial,sans-serif;font-size:.72rem;color:#6b6559;letter-spacing:.06em;text-transform:uppercase;font-weight:600;margin-bottom:.4rem;">'
                . htmlspecialchars($tipoLbl, ENT_QUOTES, 'UTF-8')
                . ' · ' . date('d/m/Y', strtotime($p['data_disponibilizacao']))
                . '</div>'

                // Titulo (case ou "sem vínculo")
                . '<div style="font-family:Georgia,serif;font-size:1.05rem;color:#052228;font-weight:600;line-height:1.35;margin-bottom:.15rem;">'
                . ($temPasta ? htmlspecialchars($p['case_title'] ?: 'Caso #' . $p['case_id'], ENT_QUOTES, 'UTF-8') : '<span style="color:#a33a2a;">⚠️ Sem pasta vinculada</span>')
                . '</div>';

            // Cliente + processo
            $meta = array();
            if (!empty($p['client_name'])) $meta[] = '👤 ' . htmlspecialchars($p['client_name'], ENT_QUOTES, 'UTF-8');
            if (!empty($p['case_number'])) $meta[] = '<span style="font-family:ui-monospace,Consolas,monospace;color:#4a4740;">' . htmlspecialchars($p['case_number'], ENT_QUOTES, 'UTF-8') . '</span>';
            if ($meta) $h .= '<div style="font-family:-apple-system,\'Segoe UI\',Arial,sans-serif;font-size:.82rem;color:#4a4740;margin-bottom:.5rem;">' . implode(' · ', $meta) . '</div>';

            // Resumo IA (se houver) + orientação IA
            if (!empty($p['resumo_ia'])) {
                $h .= '<div style="background:#faf7f2;border-left:2px solid #B87333;padding:.55rem .75rem;font-size:.85rem;line-height:1.5;color:#1a1a1f;font-family:Georgia,serif;margin:.4rem 0;border-radius:0 6px 6px 0;">'
                    . '<span style="font-family:-apple-system,\'Segoe UI\',Arial,sans-serif;font-size:.65rem;font-weight:700;color:#B87333;letter-spacing:.06em;text-transform:uppercase;">🤖 Resumo IA</span><br>'
                    . nl2br(htmlspecialchars(mb_substr($p['resumo_ia'], 0, 500), ENT_QUOTES, 'UTF-8')) . '</div>';
                if (!empty($p['orientacao_ia'])) {
                    $h .= '<div style="background:#fff7ed;border-left:2px solid #c76e15;padding:.55rem .75rem;font-size:.82rem;line-height:1.5;color:#3d2b0e;font-family:Georgia,serif;margin:.4rem 0;border-radius:0 6px 6px 0;">'
                        . '<span style="font-family:-apple-system,\'Segoe UI\',Arial,sans-serif;font-size:.65rem;font-weight:700;color:#c76e15;letter-spacing:.06em;text-transform:uppercase;">💡 O que fazer</span><br>'
                        . nl2br(htmlspecialchars(mb_substr($p['orientacao_ia'], 0, 400), ENT_QUOTES, 'UTF-8')) . '</div>';
                }
            }

            // Amanda 09/07/2026: TEXTO INTEGRO da publicação (sempre — nao so quando IA falha).
            // Limita a 8000 chars pra nao estourar Gmail (que corta em ~102KB). Se exceder,
            // avisa e linka pra pasta. Renderiza como HTML se veio com tags, senão nl2br
            // do texto plain.
            $conteudoCompleto = trim((string)$p['conteudo']);
            if ($conteudoCompleto !== '') {
                $temHtml = (strip_tags($conteudoCompleto) !== $conteudoCompleto);
                $LIMITE = 4000; // seguro pra caber varias publicacoes sem Gmail truncar (102KB por email)
                $foiCortado = false;
                if (mb_strlen($conteudoCompleto) > $LIMITE) {
                    if ($temHtml) {
                        // Corta mantendo estrutura razoavel
                        $conteudoCompleto = mb_substr($conteudoCompleto, 0, $LIMITE);
                    } else {
                        $conteudoCompleto = mb_substr($conteudoCompleto, 0, $LIMITE);
                    }
                    $foiCortado = true;
                }
                if ($temHtml) {
                    // Sanitiza mantendo tags basicas
                    $conteudoRender = strip_tags($conteudoCompleto, '<p><br><b><strong><i><em><ul><ol><li><span><div>');
                } else {
                    $conteudoRender = nl2br(htmlspecialchars($conteudoCompleto, ENT_QUOTES, 'UTF-8'));
                }
                $h .= '<div style="background:#fdfcf9;border:1px solid #e3ddcf;padding:.7rem .9rem;font-size:.82rem;line-height:1.6;color:#1a1a1f;font-family:Georgia,serif;margin:.5rem 0;border-radius:6px;">'
                    . '<div style="font-family:-apple-system,\'Segoe UI\',Arial,sans-serif;font-size:.65rem;font-weight:700;color:#052228;letter-spacing:.08em;text-transform:uppercase;margin-bottom:.4rem;padding-bottom:.35rem;border-bottom:1px solid #e3ddcf;">📄 Íntegra da publicação</div>'
                    . '<div style="color:#1a1a1f;">' . $conteudoRender . '</div>'
                    . ($foiCortado ? '<div style="margin-top:.5rem;padding-top:.4rem;border-top:1px dashed #d5cdba;font-size:.72rem;color:#8a8378;font-family:-apple-system,\'Segoe UI\',Arial,sans-serif;font-style:italic;">… texto truncado (' . number_format(mb_strlen(trim((string)$p['conteudo']))) . ' caracteres no total). Ver íntegra completa na pasta →</div>' : '')
                    . '</div>';
            }

            // Prazo
            if ($prazoTxt) $h .= $prazoTxt;

            // Botões
            $h .= '<div style="margin-top:.9rem;">';
            if ($temPasta) {
                $h .= '<a href="' . htmlspecialchars($urlPasta, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;background:#052228;color:#fff;padding:.55rem 1.1rem;border-radius:6px;text-decoration:none;font-size:.8rem;font-weight:600;font-family:-apple-system,\'Segoe UI\',Arial,sans-serif;">Abrir pasta →</a> ';
            } else {
                $h .= '<a href="https://ferreiraesa.com.br/conecta/modules/admin/djen_importar.php" style="display:inline-block;background:#a33a2a;color:#fff;padding:.55rem 1.1rem;border-radius:6px;text-decoration:none;font-size:.8rem;font-weight:600;font-family:-apple-system,\'Segoe UI\',Arial,sans-serif;">Vincular manualmente →</a> ';
            }
            $h .= '</div></div>';
        }
    }

    // Rodapé
    $h .= '<div style="margin-top:1.5rem;padding:1.4rem 1.2rem;background:#052228;color:#f2ede2;border-radius:12px 12px 0 0;text-align:center;font-family:-apple-system,\'Segoe UI\',Arial,sans-serif;">'
        . '<p style="margin:0 0 .5rem;font-size:.85rem;">'
        . '<a href="https://ferreiraesa.com.br/conecta/modules/admin/djen_importar.php" style="color:#d29a5f;text-decoration:underline;font-weight:600;">Ver todas as publicações no Hub Conecta →</a></p>'
        . '<p style="margin:0;font-size:.7rem;color:#8a8378;letter-spacing:.06em;">Claudin · Monitoramento diário do DJEN · Ferreira &amp; Sá Advocacia</p>'
        . '</div>'
        . '</div></body></html>';

    // Texto fallback (pra clients que bloqueiam HTML)
    $texto = "Recortes DJEN " . $dataFmt . " — " . $totalCap . " publicacoes capturadas.\n\n";
    foreach ($porTribunal as $trib => $itens) {
        $texto .= "== " . $trib . " (" . count($itens) . ") ==\n";
        foreach ($itens as $p) {
            $texto .= "  - " . ($tiposLbl[$p['tipo_publicacao']] ?? 'Publicacao') . " · " . date('d/m/Y', strtotime($p['data_disponibilizacao']));
            if (!empty($p['case_number'])) $texto .= " · " . $p['case_number'];
            if (!empty($p['client_name'])) $texto .= " · " . $p['client_name'];
            if (!empty($p['data_prazo_fim'])) $texto .= " · PRAZO " . date('d/m/Y', strtotime($p['data_prazo_fim']));
            $texto .= "\n";
        }
        $texto .= "\n";
    }
    $texto .= "Ver todas: https://ferreiraesa.com.br/conecta/modules/admin/djen_importar.php\n";

    return array(
        'assunto' => $assunto,
        'html'    => $h,
        'texto'   => $texto,
    );
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
