<?php
/**
 * Motor de fluxos do WhatsApp — núcleo do executor.
 *
 * Camada de I/O + execução sobre a família zapi_fluxo* criada em
 * migrar_zapi_fluxos.php (31/05/2026). Esta é a PRIMEIRA versão funcional:
 *   - 5 tipos de bloco básicos (mensagem, esperar, capturar, condicional, fim)
 *   - Resolução de variáveis {{nome}} e {{campo:chave}} no texto
 *   - Persistência de campos coletados em zapi_conversa_valor
 *   - Não altera webhook nem dispara cron automaticamente — só helpers
 *
 * Quem chama o executor (escopo futuro, ainda não plugado):
 *   - cron/zapi_fluxo_tick.php — varre execucoes com aguardando_ate vencido
 *   - api/zapi_webhook.php     — quando msg chega numa conversa em execução
 *   - UI de admin              — "Iniciar fluxo manualmente nessa conversa"
 *
 * Vocabulário de bloco (config_json):
 *   mensagem:    { "texto": "Oi {{nome}}, vamos confirmar seus dados." }
 *   esperar:     { "timeout_min": 60 }
 *   capturar:    { "campo": "telefone_alt", "trim": true }
 *   condicional: { "campo": "estado_civil", "operador": "igual"|"contem"|"vazio", "valor": "casado" }
 *                  → saídas: 'sim' / 'nao'
 *   fim:         { "motivo": "concluido"|"abandono"|"transferido_humano" }
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions_zapi.php';

// ─────────────────────────────────────────────────────────
// LEITURA DO GRAFO
// ─────────────────────────────────────────────────────────

/**
 * Carrega um fluxo COMPLETO: cabeçalho + blocos + arestas indexados pra navegação rápida.
 *
 * Retorna array:
 *   ['fluxo' => row, 'blocos' => [id => row], 'arestas' => [origem_id => [saida => destino_id]]]
 * Ou null se o fluxo não existe.
 */
function fluxo_carregar($fluxoId) {
    $pdo = db();
    $fluxoId = (int)$fluxoId;
    if ($fluxoId <= 0) return null;

    $st = $pdo->prepare("SELECT * FROM zapi_fluxo WHERE id = ?");
    $st->execute(array($fluxoId));
    $fluxo = $st->fetch();
    if (!$fluxo) return null;

    $blocos = array();
    $st = $pdo->prepare("SELECT * FROM zapi_fluxo_bloco WHERE fluxo_id = ?");
    $st->execute(array($fluxoId));
    foreach ($st->fetchAll() as $b) $blocos[(int)$b['id']] = $b;

    $arestas = array();
    $st = $pdo->prepare("SELECT * FROM zapi_fluxo_aresta WHERE fluxo_id = ?");
    $st->execute(array($fluxoId));
    foreach ($st->fetchAll() as $a) {
        $arestas[(int)$a['origem_bloco_id']][$a['saida']] = (int)$a['destino_bloco_id'];
    }

    return array('fluxo' => $fluxo, 'blocos' => $blocos, 'arestas' => $arestas);
}

/**
 * Acha o próximo bloco a partir de um bloco origem + saída nomeada.
 * Retorna o ID do destino, ou null se não há aresta correspondente.
 */
function fluxo_proximo_bloco_id($arestas, $origemId, $saida) {
    if ($saida === null) return null;
    if (!isset($arestas[$origemId])) return null;
    if (isset($arestas[$origemId][$saida])) return (int)$arestas[$origemId][$saida];
    // Fallback: se a saída solicitada não existe mas há uma 'default', usa
    if (isset($arestas[$origemId]['default'])) return (int)$arestas[$origemId]['default'];
    return null;
}

// ─────────────────────────────────────────────────────────
// EXECUÇÃO — INICIAR, AVANÇAR, PARAR
// ─────────────────────────────────────────────────────────

/**
 * Inicia uma nova execução de fluxo para uma conversa.
 *
 * Idempotência: se já existe execução EM ANDAMENTO ou AGUARDANDO pra essa
 * combinação (fluxo_id, conversa_id), retorna o ID da existente sem criar nova.
 * Isso evita disparos duplicados (ex: cliente manda duas msgs e dois gatilhos
 * caem ao mesmo tempo).
 *
 * Retorna o ID da execução (existente ou nova), ou null se o fluxo não existe.
 */
function fluxo_iniciar($fluxoId, $conversaId, $iniciadoPorUserId = null) {
    $pdo = db();
    $fluxoId = (int)$fluxoId;
    $conversaId = (int)$conversaId;
    if ($fluxoId <= 0 || $conversaId <= 0) return null;

    $st = $pdo->prepare("SELECT id, bloco_inicial_id, ativo FROM zapi_fluxo WHERE id = ?");
    $st->execute(array($fluxoId));
    $fl = $st->fetch();
    if (!$fl) return null;
    if (!(int)$fl['ativo']) return null;

    // Já existe execução viva?
    $st = $pdo->prepare(
        "SELECT id FROM zapi_fluxo_execucao
         WHERE fluxo_id = ? AND conversa_id = ?
           AND estado IN ('em_andamento','aguardando')
         ORDER BY id DESC LIMIT 1"
    );
    $st->execute(array($fluxoId, $conversaId));
    $existing = $st->fetchColumn();
    if ($existing) return (int)$existing;

    // Cria nova execução começando pelo bloco inicial
    $inicial = $fl['bloco_inicial_id'] ? (int)$fl['bloco_inicial_id'] : null;
    if (!$inicial) {
        // Fallback: pega o primeiro bloco (menor id) do fluxo. Útil enquanto
        // a UI ainda não pediu pra marcar explicitamente o bloco inicial.
        $st2 = $pdo->prepare("SELECT id FROM zapi_fluxo_bloco WHERE fluxo_id = ? ORDER BY id ASC LIMIT 1");
        $st2->execute(array($fluxoId));
        $inicial = (int)$st2->fetchColumn() ?: null;
    }
    if (!$inicial) return null; // fluxo sem blocos — nada a fazer

    $pdo->prepare(
        "INSERT INTO zapi_fluxo_execucao (fluxo_id, conversa_id, bloco_atual_id, estado, iniciado_em)
         VALUES (?, ?, ?, 'em_andamento', NOW())"
    )->execute(array($fluxoId, $conversaId, $inicial));
    $execId = (int)$pdo->lastInsertId();

    $pdo->prepare("UPDATE zapi_fluxo SET execucoes = execucoes + 1 WHERE id = ?")->execute(array($fluxoId));

    return $execId;
}

/**
 * Marca uma execução como concluída/cancelada/erro. Não emite mensagem nenhuma.
 * Use motivos canônicos: 'concluido', 'cancelado', 'abandono', 'erro', 'transferido_humano'.
 */
function fluxo_parar($execId, $motivo = 'cancelado') {
    $pdo = db();
    $execId = (int)$execId;
    if ($execId <= 0) return;
    $estadoFinal = ($motivo === 'concluido') ? 'concluido' : 'cancelado';
    $pdo->prepare("UPDATE zapi_fluxo_execucao SET estado = ?, aguardando_ate = NULL WHERE id = ?")
        ->execute(array($estadoFinal, $execId));
}

/**
 * Avança a execução até parar (aguardar/fim/erro). Idempotente — pode ser
 * chamada várias vezes; se a execução está aguardando entrada, retorna sem mudar.
 *
 * @param int      $execId         ID da execução
 * @param ?string  $entradaUsuario Última msg do cliente (NULL se cron varrendo timeout)
 * @return array { estado, bloco_atual_id, aguardando_ate, passos_executados, erro? }
 */
function fluxo_avancar($execId, $entradaUsuario = null) {
    $pdo = db();
    $execId = (int)$execId;
    if ($execId <= 0) return array('estado' => 'erro', 'erro' => 'exec_id invalido');

    // Carrega execução
    $st = $pdo->prepare("SELECT * FROM zapi_fluxo_execucao WHERE id = ?");
    $st->execute(array($execId));
    $exec = $st->fetch();
    if (!$exec) return array('estado' => 'erro', 'erro' => 'execucao nao encontrada');
    if (in_array($exec['estado'], array('concluido','cancelado','erro'), true)) {
        return array('estado' => $exec['estado'], 'bloco_atual_id' => (int)$exec['bloco_atual_id']);
    }

    // Carrega grafo e conversa
    $grafo = fluxo_carregar((int)$exec['fluxo_id']);
    if (!$grafo) return array('estado' => 'erro', 'erro' => 'fluxo nao encontrado');

    $st = $pdo->prepare("SELECT * FROM zapi_conversas WHERE id = ?");
    $st->execute(array((int)$exec['conversa_id']));
    $conversa = $st->fetch();
    if (!$conversa) return array('estado' => 'erro', 'erro' => 'conversa nao encontrada');

    $blocoAtualId = (int)$exec['bloco_atual_id'];
    $passos = 0;
    $maxPassos = 50; // proteção contra loop infinito
    $erro = null;
    $estadoFinal = 'em_andamento';
    $aguardarAte = null;
    $entradaPendente = $entradaUsuario; // só é consumida pelo primeiro bloco que precisa dela

    while ($blocoAtualId && $passos < $maxPassos) {
        $passos++;
        if (!isset($grafo['blocos'][$blocoAtualId])) {
            $erro = "bloco $blocoAtualId nao existe no grafo";
            $estadoFinal = 'erro';
            break;
        }
        $bloco = $grafo['blocos'][$blocoAtualId];

        $res = _fluxo_aplicar_bloco($bloco, $conversa, $entradaPendente);
        // Bloco consumiu a entrada? Limpa pra próximos blocos não reusarem
        if (!empty($res['consome_entrada'])) $entradaPendente = null;

        if (!empty($res['erro'])) { $erro = $res['erro']; $estadoFinal = 'erro'; break; }
        if (!empty($res['fim']))  { $estadoFinal = 'concluido'; break; }

        if (!empty($res['aguardar_ate'])) {
            $aguardarAte = $res['aguardar_ate'];
            $estadoFinal = 'aguardando';
            break;
        }

        // Avança pela saída escolhida
        $proximoId = fluxo_proximo_bloco_id($grafo['arestas'], $blocoAtualId, $res['saida'] ?? 'default');
        if (!$proximoId) {
            // Sem aresta de saída = fim implícito
            $estadoFinal = 'concluido';
            break;
        }
        $blocoAtualId = $proximoId;
    }

    if ($passos >= $maxPassos && $estadoFinal === 'em_andamento') {
        $erro = "limite de passos ($maxPassos) atingido — possivel loop infinito";
        $estadoFinal = 'erro';
    }

    // Persiste estado novo
    $pdo->prepare(
        "UPDATE zapi_fluxo_execucao
            SET bloco_atual_id = ?, estado = ?, aguardando_ate = ?, tentativas = tentativas + 1
          WHERE id = ?"
    )->execute(array($blocoAtualId ?: null, $estadoFinal, $aguardarAte, $execId));

    $ret = array(
        'estado' => $estadoFinal,
        'bloco_atual_id' => $blocoAtualId,
        'aguardando_ate' => $aguardarAte,
        'passos_executados' => $passos,
    );
    if ($erro) $ret['erro'] = $erro;
    return $ret;
}

/**
 * Helper pra plug no webhook: se a conversa tem execução AGUARDANDO,
 * trata a msg recebida como entrada do fluxo e avança. Retorna true se houve
 * fluxo processado, false se não havia execução viva.
 *
 * Versão minimal (sem gatilhos automáticos). Pra uso geral fora do webhook,
 * preferir esta. O webhook usa fluxo_processar_webhook() que também avalia
 * gatilhos automáticos.
 */
function fluxo_processar_msg_recebida($conversaId, $textoMsg) {
    $pdo = db();
    $conversaId = (int)$conversaId;
    $st = $pdo->prepare(
        "SELECT id FROM zapi_fluxo_execucao
         WHERE conversa_id = ? AND estado IN ('em_andamento','aguardando')
         ORDER BY id DESC LIMIT 1"
    );
    $st->execute(array($conversaId));
    $execId = (int)$st->fetchColumn();
    if (!$execId) return false;

    fluxo_avancar($execId, $textoMsg);
    return true;
}

/**
 * Avalia se algum fluxo ATIVO deve auto-disparar pra essa mensagem.
 * Critérios suportados (gatilho_tipo):
 *   manual         → nunca auto-dispara
 *   primeira_msg   → dispara se $ehPrimeira === true e canal bate
 *   palavra_chave  → dispara se uma palavra de gatilho_config["palavras"]
 *                    aparece em $textoMsg (case-insensitive, busca substring)
 *
 * Compatibilidade de canal: fluxo com canal NULL = qualquer canal.
 *
 * Em caso de múltiplos fluxos elegíveis, escolhe o ID MENOR (primeiro criado).
 * Retorna o ID do fluxo a disparar, ou null se nenhum bate.
 */
function fluxo_buscar_gatilho_automatico($canal, $textoMsg, $ehPrimeira) {
    $pdo = db();
    $textoMsg = (string)$textoMsg;
    $textoLower = mb_strtolower($textoMsg, 'UTF-8');

    // Busca fluxos ativos com gatilho automático compatível com canal
    $st = $pdo->prepare(
        "SELECT id, gatilho_tipo, gatilho_config, canal
           FROM zapi_fluxo
          WHERE ativo = 1
            AND gatilho_tipo IN ('primeira_msg','palavra_chave')
            AND (canal IS NULL OR canal = '' OR canal = ?)
          ORDER BY id ASC"
    );
    $st->execute(array((string)$canal));
    foreach ($st->fetchAll() as $f) {
        $tipo = (string)$f['gatilho_tipo'];

        if ($tipo === 'primeira_msg') {
            if ($ehPrimeira) return (int)$f['id'];
            continue;
        }

        if ($tipo === 'palavra_chave') {
            $cfg = json_decode((string)$f['gatilho_config'], true) ?: array();
            $palavras = isset($cfg['palavras']) && is_array($cfg['palavras']) ? $cfg['palavras'] : array();
            foreach ($palavras as $p) {
                $p = trim(mb_strtolower((string)$p, 'UTF-8'));
                if ($p === '') continue;
                if (mb_strpos($textoLower, $p) !== false) return (int)$f['id'];
            }
            continue;
        }
    }
    return null;
}

/**
 * Helper completo pra chamada do webhook: processa fluxo numa conversa
 * considerando execução viva E gatilhos automáticos.
 *
 * Ordem de avaliação:
 *   1. Já existe execução viva pra essa conv? → avança ela (entradaUsuario = msg)
 *   2. Senão, algum fluxo ativo tem gatilho que casa? → inicia e avança
 *   3. Senão, retorna false (nada a fazer — webhook segue pro bot Haiku, etc.)
 *
 * Retorna true se houve qualquer processamento, false se passou batido.
 * NÃO levanta exceção — caller deve fazer try/catch como segurança extra.
 */
function fluxo_processar_webhook($conversaId, $canal, $textoMsg, $ehPrimeira) {
    $pdo = db();
    $conversaId = (int)$conversaId;
    if ($conversaId <= 0) return false;

    // 1. Execução viva tem prioridade
    $st = $pdo->prepare(
        "SELECT id FROM zapi_fluxo_execucao
         WHERE conversa_id = ? AND estado IN ('em_andamento','aguardando')
         ORDER BY id DESC LIMIT 1"
    );
    $st->execute(array($conversaId));
    $execId = (int)$st->fetchColumn();
    if ($execId) {
        fluxo_avancar($execId, $textoMsg);
        return true;
    }

    // 2. Tenta gatilho automático
    $fluxoId = fluxo_buscar_gatilho_automatico($canal, $textoMsg, $ehPrimeira);
    if ($fluxoId) {
        $novoExecId = fluxo_iniciar($fluxoId, $conversaId);
        if ($novoExecId) {
            // Avança passando a msg como entrada (útil pra fluxos que querem
            // capturar/condicionar logo no primeiro bloco)
            fluxo_avancar($novoExecId, $textoMsg);
            return true;
        }
    }

    return false;
}

// ─────────────────────────────────────────────────────────
// APLICAÇÃO POR TIPO DE BLOCO
// ─────────────────────────────────────────────────────────

/**
 * Aplica um bloco e devolve a decisão de roteamento.
 * Retorno:
 *   ['saida' => string|null, 'aguardar_ate' => null|datetime, 'fim' => bool,
 *    'consome_entrada' => bool, 'erro' => string?]
 *
 * Convenções:
 *   - saida='default' → segue a aresta padrão (a maioria dos blocos)
 *   - saida=null + fim=true → encerra execução
 *   - saida=null + aguardar_ate=DT → pausa até DT (ou até cliente responder)
 */
function _fluxo_aplicar_bloco($bloco, $conversa, $entradaUsuario) {
    $cfg = array();
    if (!empty($bloco['config_json'])) {
        $tmp = json_decode($bloco['config_json'], true);
        if (is_array($tmp)) $cfg = $tmp;
    }
    $tipo = (string)$bloco['tipo'];

    switch ($tipo) {

        case 'mensagem': {
            $texto = trim((string)($cfg['texto'] ?? ''));
            if ($texto === '') {
                return array('saida' => 'default', 'aguardar_ate' => null, 'fim' => false);
            }
            $texto = _fluxo_resolver_vars($texto, $conversa);
            $env = zapi_send_text($conversa['canal'], $conversa['telefone'], $texto);
            try {
                db()->prepare(
                    "INSERT INTO zapi_mensagens (conversa_id, direcao, tipo, conteudo, enviado_por_bot, status)
                     VALUES (?, 'enviada', 'texto', ?, 1, ?)"
                )->execute(array(
                    (int)$conversa['id'],
                    mb_substr($texto, 0, 5000),
                    !empty($env['ok']) ? 'enviada' : 'falhou',
                ));
            } catch (Exception $e) { /* não bloqueia o fluxo */ }
            return array('saida' => 'default', 'aguardar_ate' => null, 'fim' => false);
        }

        case 'esperar': {
            // Se já chegou entrada do cliente, NÃO pausa: segue.
            if ($entradaUsuario !== null && trim((string)$entradaUsuario) !== '') {
                return array('saida' => 'default', 'aguardar_ate' => null, 'fim' => false);
            }
            $timeoutMin = (int)($cfg['timeout_min'] ?? 60);
            if ($timeoutMin < 1) $timeoutMin = 60;
            $aguardarAte = date('Y-m-d H:i:s', time() + $timeoutMin * 60);
            return array('saida' => null, 'aguardar_ate' => $aguardarAte, 'fim' => false);
        }

        case 'capturar': {
            $chave = trim((string)($cfg['campo'] ?? ''));
            if ($chave === '' || $entradaUsuario === null) {
                return array('saida' => 'default', 'aguardar_ate' => null, 'fim' => false, 'consome_entrada' => true);
            }
            $valor = (string)$entradaUsuario;
            if (!empty($cfg['trim'])) $valor = trim($valor);
            fluxo_valor_set((int)$conversa['id'], $chave, $valor);
            return array('saida' => 'default', 'aguardar_ate' => null, 'fim' => false, 'consome_entrada' => true);
        }

        case 'condicional': {
            $chave = (string)($cfg['campo'] ?? '');
            $op    = (string)($cfg['operador'] ?? 'igual');
            $alvo  = (string)($cfg['valor'] ?? '');
            $atual = (string)(fluxo_valor_get((int)$conversa['id'], $chave) ?? '');
            $bate = false;
            switch ($op) {
                case 'igual':       $bate = (strcasecmp($atual, $alvo) === 0); break;
                case 'diferente':   $bate = (strcasecmp($atual, $alvo) !== 0); break;
                case 'contem':      $bate = ($alvo !== '' && mb_stripos($atual, $alvo) !== false); break;
                case 'nao_contem':  $bate = ($alvo === '' || mb_stripos($atual, $alvo) === false); break;
                case 'vazio':       $bate = (trim($atual) === ''); break;
                case 'preenchido':  $bate = (trim($atual) !== ''); break;
                default:            $bate = false;
            }
            return array('saida' => ($bate ? 'sim' : 'nao'), 'aguardar_ate' => null, 'fim' => false);
        }

        case 'fim': {
            return array('saida' => null, 'aguardar_ate' => null, 'fim' => true);
        }

        default:
            return array(
                'saida' => null, 'aguardar_ate' => null, 'fim' => true,
                'erro' => "tipo de bloco desconhecido: '$tipo'",
            );
    }
}

/**
 * Resolve variáveis em texto: {{nome}}, {{telefone}}, {{campo:chave}}.
 * Variáveis não resolvidas viram string vazia (não deixa "{{xxx}}" vazar pro cliente).
 */
function _fluxo_resolver_vars($texto, $conversa) {
    $convId = (int)($conversa['id'] ?? 0);
    return preg_replace_callback('/\{\{([a-zA-Z_][a-zA-Z0-9_:]*)\}\}/', function ($m) use ($conversa, $convId) {
        $expr = $m[1];
        if (strpos($expr, 'campo:') === 0) {
            $chave = substr($expr, 6);
            return (string)(fluxo_valor_get($convId, $chave) ?? '');
        }
        switch ($expr) {
            case 'nome':     return (string)($conversa['nome_contato'] ?? '');
            case 'telefone': return (string)($conversa['telefone'] ?? '');
            case 'canal':    return (string)($conversa['canal'] ?? '');
            default:         return '';
        }
    }, $texto);
}

// ─────────────────────────────────────────────────────────
// CAMPOS E VALORES (intake estruturado)
// ─────────────────────────────────────────────────────────

/**
 * Busca campo pela chave. Retorna a row ou null.
 */
function fluxo_campo_buscar($chave) {
    $chave = trim((string)$chave);
    if ($chave === '') return null;
    $st = db()->prepare("SELECT * FROM zapi_campo WHERE chave = ? LIMIT 1");
    $st->execute(array($chave));
    $row = $st->fetch();
    return $row ?: null;
}

/**
 * Cria campo se não existe. Retorna o ID (existente ou novo).
 */
function fluxo_campo_criar($chave, $nome, $tipo = 'texto', $descricao = null) {
    $existing = fluxo_campo_buscar($chave);
    if ($existing) return (int)$existing['id'];
    db()->prepare(
        "INSERT INTO zapi_campo (chave, nome, tipo, descricao) VALUES (?, ?, ?, ?)"
    )->execute(array(trim($chave), trim($nome), $tipo, $descricao));
    return (int)db()->lastInsertId();
}

/**
 * Grava (upsert) o valor de um campo pra uma conversa. Cria o campo
 * silenciosamente se ainda não existe (tipo 'texto', nome = chave humanizada).
 */
function fluxo_valor_set($conversaId, $chave, $valor) {
    $conversaId = (int)$conversaId;
    $chave = trim((string)$chave);
    if ($conversaId <= 0 || $chave === '') return;
    $campoId = fluxo_campo_criar($chave, ucfirst(str_replace('_', ' ', $chave)));
    db()->prepare(
        "INSERT INTO zapi_conversa_valor (conversa_id, campo_id, valor)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE valor = VALUES(valor), atualizado_em = NOW()"
    )->execute(array($conversaId, $campoId, (string)$valor));
}

/**
 * Lê o valor de um campo pra uma conversa. Retorna a string ou null se não há.
 */
function fluxo_valor_get($conversaId, $chave) {
    $conversaId = (int)$conversaId;
    $campo = fluxo_campo_buscar($chave);
    if (!$campo) return null;
    $st = db()->prepare(
        "SELECT valor FROM zapi_conversa_valor WHERE conversa_id = ? AND campo_id = ? LIMIT 1"
    );
    $st->execute(array($conversaId, (int)$campo['id']));
    $v = $st->fetchColumn();
    return ($v === false) ? null : $v;
}

/**
 * Lê todos os valores coletados de uma conversa (chave => valor).
 * Útil pra Fábrica de Petições puxar o intake completo.
 */
function fluxo_valores_da_conversa($conversaId) {
    $conversaId = (int)$conversaId;
    if ($conversaId <= 0) return array();
    $st = db()->prepare(
        "SELECT c.chave, v.valor
           FROM zapi_conversa_valor v
           JOIN zapi_campo c ON c.id = v.campo_id
          WHERE v.conversa_id = ?"
    );
    $st->execute(array($conversaId));
    $out = array();
    foreach ($st->fetchAll() as $r) $out[$r['chave']] = $r['valor'];
    return $out;
}
