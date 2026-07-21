<?php
/**
 * Ferreira & Sá Hub — Geração automática de ofício de desconto em folha
 *
 * Quando FBI $ retorna POSITIVO (parte executada tem vínculo empregatício),
 * a IA busca dados de contato da empresa online (Claude Sonnet + web_search)
 * e redige um oficio pronto pra ser enviado ao RH/Jurídico solicitando
 * desconto em folha da pensão alimentícia.
 *
 * Fluxo:
 *   1. fbi_vinculo_gerar_oficio_desconto($pdo, $pesquisaId)
 *   2. Chama Claude Sonnet com web_search_20250305
 *   3. Cria case_task com título "📮 Enviar oficio desconto folha - EMPRESA"
 *      + descrição contendo rascunho do oficio + contatos + fontes web
 *   4. Amanda revisa dentro da pasta do processo, envia por email/carta.
 *
 * Killswitch: configuracoes.fbi_vinculo_oficio_auto_ativo = '1' pra ligar.
 * Custo estimado: ~R$ 0,15-0,30 por ofício (Sonnet + web search 3 buscas).
 */

if (!function_exists('fbi_vinculo_oficio_auto_ativo')) {
function fbi_vinculo_oficio_auto_ativo() {
    // Usa o framework padrao de features IA (padrao ia_feature_X_enabled).
    // Amanda liga em /admin/ia_custo.php.
    require_once __DIR__ . '/functions_ia.php';
    if (function_exists('ia_feature_ativa')) return ia_feature_ativa('fbi_vinculo_oficio_desconto');
    try {
        $v = db()->query("SELECT valor FROM configuracoes WHERE chave='ia_feature_fbi_vinculo_oficio_desconto_enabled'")->fetchColumn();
        return $v === '1';
    } catch (Throwable $e) { return false; }
}
}

if (!function_exists('fbi_vinculo_gerar_oficio_desconto')) {
/**
 * Gera oficio de desconto em folha via IA + web search.
 * @return array{ok:bool, erro:?string, task_id:?int, texto:?string}
 */
function fbi_vinculo_gerar_oficio_desconto(PDO $pdo, $pesquisaId, $modo = 'oficio') {
    require_once __DIR__ . '/functions_ia.php';
    // Amanda 10/07/2026: 2 modos suportados —
    //   'oficio'   (default): identifica empresa + busca contatos + REDIGE oficio pronto
    //   'contatos': so identifica empresa + busca contatos (util quando so precisa
    //               ligar/mandar email antes de decidir se vale oficio formal)
    $modo = in_array($modo, array('oficio','contatos'), true) ? $modo : 'oficio';

    // 1) Busca dados da pesquisa + case + cliente
    $st = $pdo->prepare(
        "SELECT g.id, g.parte_nome, g.parte_cpf, g.parente, g.resultado, g.tem_vinculo,
                g.case_id, g.created_by,
                c.title AS case_title, c.case_number, c.client_id,
                cl.name AS client_name, cl.cpf AS client_cpf
         FROM fbi_vinculo_pesquisas g
         LEFT JOIN cases c    ON c.id  = g.case_id
         LEFT JOIN clients cl ON cl.id = c.client_id
         WHERE g.id = ?"
    );
    $st->execute(array((int)$pesquisaId));
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if (!$p) return array('ok' => false, 'erro' => 'Pesquisa nao encontrada', 'task_id' => null, 'texto' => null);
    if ((int)$p['tem_vinculo'] !== 1) return array('ok' => false, 'erro' => 'Pesquisa nao eh POSITIVA', 'task_id' => null, 'texto' => null);
    if (empty($p['case_id'])) return array('ok' => false, 'erro' => 'Sem case vinculado (nao criamos tarefa avulsa)', 'task_id' => null, 'texto' => null);

    // Dedup por modo — cada modo tem sufixo distinto pra permitir gerar os 2
    $tipoTk = ($modo === 'contatos') ? 'fbi_vinculo_contatos_empresa' : 'oficio_desconto_folha';
    $tagTk = ($modo === 'contatos') ? '[fbi_vinculo-contatos#' : '[fbi_vinculo#';
    try {
        $stChk = $pdo->prepare("SELECT id FROM case_tasks WHERE case_id = ? AND tipo = ? AND title LIKE ? LIMIT 1");
        $stChk->execute(array((int)$p['case_id'], $tipoTk, '%' . $tagTk . (int)$pesquisaId . ']%'));
        if ($stChk->fetchColumn()) {
            return array('ok' => false, 'erro' => 'Ja existe tarefa desse tipo pra essa pesquisa', 'task_id' => null, 'texto' => null);
        }
    } catch (Throwable $e) { /* schema pode nao ter tipos, ignora */ }

    // 2) Prompt system pra IA — muda conforme modo
    if ($modo === 'contatos') {
        $system = <<<PROMPT
Voce e uma assistente juridica do escritorio Ferreira & Sa Advocacia, especializada
em execucao de alimentos.

TAREFA: quando uma pesquisa FBI $ confirma que o alimentante possui vinculo
empregaticio, voce vai APENAS:

1. IDENTIFICAR a empresa empregadora a partir do texto do resultado da pesquisa.
2. BUSCAR na internet, via web search, os contatos oficiais da empresa:
   endereco fisico, e-mail(s) de RH / juridico / departamento pessoal, telefone.
   Foque em fontes oficiais (site da propria empresa, CNPJ.ws, Reclame Aqui,
   LinkedIn corporativo). Use no maximo 3 buscas.
3. NAO redija oficio — o objetivo aqui e so preparar os dados para contato inicial
   (Amanda decide se abrira oficio formal depois).

FORMATO DA RESPOSTA (JSON UNICO, sem markdown fences):
{
  "empresa_identificada": "Nome da Empresa LTDA",
  "cnpj": "00.000.000/0000-00 ou null se nao encontrou",
  "endereco": "Rua..., Cidade/UF, CEP",
  "contatos": [
    {"tipo": "email_rh", "valor": "rh@empresa.com.br", "fonte": "site oficial"},
    {"tipo": "email_juridico", "valor": "juridico@...", "fonte": "..."},
    {"tipo": "telefone", "valor": "(11) ...", "fonte": "..."}
  ],
  "observacoes_amanda": "O que a Amanda precisa saber antes de fazer contato (ex: horario de atendimento, canal preferido, se atende por whatsapp corporativo)",
  "fontes_web": ["url1", "url2"]
}

Se nao conseguiu identificar a empresa, retorne:
{"empresa_identificada": null, "erro": "Nao foi possivel identificar a empresa no texto do resultado."}
PROMPT;
        $userMsgFinal = "Agora: identifique a empresa e busque os contatos oficiais para contato inicial. NAO redija oficio.";
    } else {
    $system = <<<PROMPT
Voce e uma assistente juridica do escritorio Ferreira & Sa Advocacia, especializada
em execucao de alimentos.

TAREFA: quando uma pesquisa FBI $ confirma que o alimentante (devedor de pensao)
possui vinculo empregaticio, voce vai:

1. IDENTIFICAR a empresa empregadora a partir do texto do resultado da pesquisa
   (o pesquisador escreve o nome/CNPJ/cargo la em texto livre).
2. BUSCAR na internet, via web search, os contatos oficiais da empresa:
   endereco fisico, e-mail(s) de RH / juridico / departamento pessoal,
   telefone. Foque em fontes oficiais (site da propria empresa, CNPJ.ws,
   Reclame Aqui, LinkedIn corporativo). Use no maximo 3 buscas.
3. REDIGIR um oficio profissional pronto pra ser enviado, no formato juridico
   brasileiro, solicitando desconto em folha do valor da pensao alimenticia
   fixada judicialmente contra o funcionario.

REGRAS DO OFICIO:
- Assinatura sempre "Equipe Ferreira & Sa Advocacia" (NUNCA "Dra. Amanda").
- Enderecar ao "Departamento de Recursos Humanos" (ou juridico se especifico).
- Mencionar: nome completo do alimentante, CPF, cargo (se souber), n do
  processo judicial, Vara/Tribunal, mensalidade a ser descontada e conta
  bancaria pra deposito (essa Amanda vai preencher — use placeholder [CONTA]).
- Fundamento juridico: art. 528 e 529 do CPC + Sumula 309/STJ.
- Prazo de 10 dias para implementacao.
- Tom formal-cordial, seco, sem enrolação.

FORMATO DA RESPOSTA (JSON UNICO, sem markdown fences):
{
  "empresa_identificada": "Nome da Empresa LTDA",
  "cnpj": "00.000.000/0000-00 ou null se nao encontrou",
  "endereco": "Rua..., Cidade/UF, CEP",
  "contatos": [
    {"tipo": "email_rh", "valor": "rh@empresa.com.br", "fonte": "site oficial"},
    {"tipo": "email_juridico", "valor": "juridico@...", "fonte": "..."},
    {"tipo": "telefone", "valor": "(11) ...", "fonte": "..."}
  ],
  "corpo_oficio": "OFICIO N XXX/2026\\n\\nAo Departamento...\\n[TEXTO COMPLETO]",
  "observacoes_amanda": "Bulletpoints com o que Amanda precisa CONFIRMAR/PREENCHER antes de enviar (ex: valor exato, conta bancaria, prazo do juiz, cargo se nao identificou)",
  "fontes_web": ["url1", "url2"]
}

Se voce NAO conseguiu identificar a empresa a partir do texto, retorne:
{"empresa_identificada": null, "erro": "Nao foi possivel identificar a empresa no texto do resultado. Peca ao Luiz Eduardo pra complementar a pesquisa com nome/CNPJ da empregadora."}
PROMPT;
        $userMsgFinal = "Agora: identifique a empresa, busque contatos online, e redija o oficio.";
    }

    // 3) User message com dados
    $userMsg = "PESQUISA FBI $ (id " . (int)$p['id'] . "):\n\n"
             . "Parte adversa (alimentante que possui vinculo): " . $p['parte_nome']
             . ($p['parte_cpf'] ? " (CPF " . $p['parte_cpf'] . ")" : '') . "\n"
             . ($p['parente'] ? "Relacao com o cliente: " . $p['parente'] . " (do cliente)\n" : '')
             . "\nRESULTADO DA PESQUISA (texto livre do Luiz Eduardo):\n"
             . '"""' . "\n"
             . (string)$p['resultado'] . "\n"
             . '"""' . "\n\n"
             . "CONTEXTO DO PROCESSO:\n"
             . "- Nosso cliente (credor da pensao): " . ($p['client_name'] ?? '?') . "\n"
             . "- Numero do processo: " . ($p['case_number'] ?: 'ainda nao distribuido') . "\n"
             . "- Titulo do caso: " . ($p['case_title'] ?? '?') . "\n\n"
             . $userMsgFinal;

    // 4) Chamada IA com web_search
    $modelo = 'claude-sonnet-4-6';
    $resp = ia_chamar(
        'fbi_vinculo_oficio_desconto',
        $modelo,
        $system,
        array(array('role' => 'user', 'content' => $userMsg)),
        array(
            'max_tokens'          => 3500,
            'temperature'         => 0.4,
            'bypass_killswitch'   => true,
            'bypass_user_whitelist' => true,
            'contexto'            => 'fbi_vinculo#' . (int)$p['id'] . ' case#' . (int)$p['case_id'],
            'tools' => array(array(
                'type'     => 'web_search_20250305',
                'name'     => 'web_search',
                'max_uses' => 3,
            )),
        )
    );

    if (empty($resp['ok']) || empty($resp['texto'])) {
        return array('ok' => false, 'erro' => 'IA falhou: ' . ($resp['erro'] ?? 'sem texto'), 'task_id' => null, 'texto' => null);
    }
    $textoBruto = trim($resp['texto']);

    // 5) Extrai JSON da resposta (Claude as vezes envolve em ``` — remove)
    $j = null;
    $tentativas = array($textoBruto);
    if (preg_match('/\{[\s\S]*\}/', $textoBruto, $m)) $tentativas[] = $m[0];
    foreach ($tentativas as $t) {
        $tentativa = json_decode($t, true);
        if (is_array($tentativa)) { $j = $tentativa; break; }
    }
    if (!$j) {
        // JSON invalido — grava mesmo assim como tarefa manual pra revisao
        $j = array(
            'empresa_identificada' => 'Empresa a identificar',
            'erro' => 'IA respondeu texto nao-JSON, ver conteudo cru abaixo',
        );
    }

    if (!empty($j['erro']) && (empty($j['corpo_oficio']) && $modo !== 'contatos')) {
        // Cria tarefa curta pra Amanda saber que a IA nao conseguiu
        $tituloTk = '⚠️ FBI $ positivo: IA nao gerou ' . ($modo === 'contatos' ? 'contatos' : 'oficio')
                  . ' - ' . $p['parte_nome'] . ' ' . $tagTk . (int)$p['id'] . ']';
        $descTk = "A pesquisa FBI $ de " . $p['parte_nome'] . " deu POSITIVO, mas a IA nao conseguiu identificar/gerar os dados automaticamente.\n\n"
                . "Motivo: " . $j['erro'] . "\n\n"
                . "Peca ao Luiz Eduardo pra complementar a pesquisa com nome + CNPJ da empregadora e refaca manualmente.\n\n"
                . "---\nResposta bruta da IA:\n" . mb_substr($textoBruto, 0, 1500);
    } else {
        // Monta tarefa com rascunho completo (oficio) ou so contatos
        $empresaLbl = !empty($j['empresa_identificada']) ? $j['empresa_identificada'] : 'Empresa nao identificada';
        $tituloTk = ($modo === 'contatos')
            ? '📞 Contatos empresa - ' . $empresaLbl . ' ' . $tagTk . (int)$p['id'] . ']'
            : '📮 Enviar oficio desconto folha - ' . $empresaLbl . ' ' . $tagTk . (int)$p['id'] . ']';

        $descPartes = array();
        $descPartes[] = "🎯 EMPRESA IDENTIFICADA: " . $empresaLbl
                      . (!empty($j['cnpj']) ? " (CNPJ " . $j['cnpj'] . ")" : '');
        if (!empty($j['endereco'])) $descPartes[] = "📍 Endereco: " . $j['endereco'];

        if (!empty($j['contatos']) && is_array($j['contatos'])) {
            $descPartes[] = "\n📞 CONTATOS ENCONTRADOS:";
            foreach ($j['contatos'] as $ct) {
                if (!is_array($ct)) continue;
                $descPartes[] = "   • " . ($ct['tipo'] ?? '?') . ": " . ($ct['valor'] ?? '?')
                             . (!empty($ct['fonte']) ? " (fonte: " . $ct['fonte'] . ")" : '');
            }
        }

        if (!empty($j['observacoes_amanda'])) {
            $descPartes[] = "\n⚠️ AMANDA, ANTES DE ENVIAR CONFIRME:\n" . $j['observacoes_amanda'];
        }

        if (!empty($j['fontes_web']) && is_array($j['fontes_web'])) {
            $descPartes[] = "\n🔗 Fontes web usadas: " . implode(' · ', $j['fontes_web']);
        }

        if ($modo === 'contatos') {
            $descPartes[] = "\n" . str_repeat('=', 60)
                         . "\n📞 Use estes dados pra fazer contato inicial com a empresa.\n"
                         . "Se decidir enviar oficio formal depois, volte na aba FBI $ e clique em 'Gerar oficio desconto folha'.\n"
                         . str_repeat('=', 60);
        } else {
            $descPartes[] = "\n" . str_repeat('=', 60) . "\n📄 RASCUNHO DO OFICIO (revise antes de enviar):\n"
                         . str_repeat('=', 60) . "\n\n"
                         . (!empty($j['corpo_oficio']) ? $j['corpo_oficio'] : '[IA nao gerou corpo]');
        }

        $descTk = implode("\n", $descPartes);
    }

    // 6) INSERT task
    try {
        $assignedTo = !empty($p['created_by']) ? (int)$p['created_by'] : null;
        $stTk = $pdo->prepare(
            "INSERT INTO case_tasks (case_id, title, tipo, descricao, assigned_to, due_date, prioridade, status, sort_order, created_at)
             VALUES (?, ?, ?, ?, ?, ?, 'alta', 'a_fazer', 0, NOW())"
        );
        $stTk->execute(array(
            (int)$p['case_id'],
            mb_substr($tituloTk, 0, 250),
            $tipoTk,
            $descTk,
            $assignedTo,
            date('Y-m-d', strtotime('+5 days')),
        ));
        $taskId = (int)$pdo->lastInsertId();

        // Andamento interno pra ficar registrado
        try {
            $pdo->prepare("INSERT INTO case_andamentos (case_id, data_andamento, tipo, descricao, created_by, visivel_cliente, created_at)
                           VALUES (?, ?, 'fbi_vinculo', ?, ?, 0, NOW())")
                ->execute(array(
                    (int)$p['case_id'], date('Y-m-d'),
                    '🤖 IA gerou rascunho de oficio de desconto em folha para "' . ($j['empresa_identificada'] ?? '?') . '" — task #' . $taskId,
                    $assignedTo ?: 1,
                ));
        } catch (Throwable $e) {}

        if (function_exists('audit_log')) {
            try { audit_log('fbi_vinculo_oficio_gerado', 'fbi_vinculo', (int)$p['id'], 'task_id=' . $taskId . ' empresa=' . ($j['empresa_identificada'] ?? '?')); } catch (Throwable $e) {}
        }

        return array('ok' => true, 'erro' => null, 'task_id' => $taskId, 'case_id' => (int)$p['case_id'], 'modo' => $modo, 'texto' => $descTk);
    } catch (Throwable $e) {
        return array('ok' => false, 'erro' => 'INSERT task: ' . $e->getMessage(), 'task_id' => null, 'texto' => null);
    }
}
}
