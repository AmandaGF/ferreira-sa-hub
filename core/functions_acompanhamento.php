<?php
/**
 * Helpers pra acompanhamento diário via WhatsApp
 * (feature Amanda 01/07/2026 — cliente ansiosa que quer msg todo dia).
 *
 * Regras:
 * - Só envia quando NÃO teve andamento novo desde o último envio (ou desde ontem
 *   se nunca enviou)
 * - Templates rotativos (nunca repete o mesmo do dia anterior)
 * - Assinatura sempre "Equipe Ferreira & Sá Advocacia" (memória)
 * - Só dias úteis (feriados nacionais fixos ignorados)
 */

/**
 * Retorna array com ~14 templates variados. Cada template é uma função que
 * recebe um array $ctx com contexto rico:
 *   $ctx = [
 *     'nome'          => primeiro nome do cliente
 *     'tipo_processo' => label amigável do tipo (ex: 'ação de indenização por danos morais')
 *                        ou string vazia se não houver
 *     'polo_oposto'   => nome da parte adversa (ex: 'SAMSUNG') ou string vazia
 *     'obs'           => observação interna livre (raramente usada)
 *   ]
 * Cada template decide se usa cada campo (com ternários pra omitir se vazio).
 * Rotação garantida: nunca envia o mesmo índice do último envio.
 */
if (!function_exists('acompanhamento_templates')) {
function acompanhamento_templates() {
    // helper de referência ao processo — usa tipo se tiver, senão "processo"
    $ref = function($ctx) {
        if (!empty($ctx['tipo_processo'])) return 'sua ' . $ctx['tipo_processo'];
        return 'seu processo';
    };
    // helper de menção à parte adversa (opcional, entre parênteses)
    $adv = function($ctx) {
        if (!empty($ctx['polo_oposto'])) return ' (contra ' . $ctx['polo_oposto'] . ')';
        return '';
    };
    return array(
        // 0
        function($ctx) use ($ref, $adv) {
            return "Olá, {$ctx['nome']}! ☀️\n\nPassando pra dizer que continuamos acompanhando de perto {$ref($ctx)}{$adv($ctx)}. Hoje ainda não houve nova movimentação, mas estamos atentos a qualquer despacho.\n\nAssim que houver novidade, avisamos por aqui na hora.\n\nEquipe Ferreira & Sá Advocacia 🤝";
        },
        // 1
        function($ctx) use ($ref, $adv) {
            return "Bom dia, {$ctx['nome']}!\n\nSó pra manter você informada: verificamos hoje e ainda não houve movimentação nova em {$ref($ctx)}{$adv($ctx)}. Continuamos monitorando diariamente.\n\nQualquer atualização, você será a primeira a saber. ✨\n\nEquipe Ferreira & Sá Advocacia";
        },
        // 2
        function($ctx) use ($ref, $adv) {
            return "Oi, {$ctx['nome']}! 👋\n\nAcabamos de checar o andamento de {$ref($ctx)}{$adv($ctx)} — sem movimentações novas por enquanto. Estamos de olho.\n\nCaso surja qualquer despacho ou intimação, entramos em contato imediatamente.\n\nUm bom dia pra você! 💐\n\nEquipe Ferreira & Sá Advocacia";
        },
        // 3
        function($ctx) use ($ref, $adv) {
            return "Olá, {$ctx['nome']}!\n\nInformamos que hoje ainda não houve nova movimentação em {$ref($ctx)}{$adv($ctx)}. Sabemos que a espera não é fácil, mas queremos que fique tranquila: estamos acompanhando dia após dia.\n\nQualquer novidade avisamos aqui.\n\nEquipe Ferreira & Sá Advocacia 🌸";
        },
        // 4
        function($ctx) use ($ref, $adv) {
            return "Bom dia, {$ctx['nome']}! 🌞\n\nMais um dia de acompanhamento — sem alterações em {$ref($ctx)}{$adv($ctx)} até agora. Isso é comum em algumas fases processuais, então não se preocupe.\n\nSeguimos monitorando por aqui.\n\nEquipe Ferreira & Sá Advocacia";
        },
        // 5
        function($ctx) use ($ref, $adv) {
            return "Oi, {$ctx['nome']}!\n\nCheckin diário: verificamos {$ref($ctx)}{$adv($ctx)} e ele continua na mesma situação de ontem, sem despachos ou movimentações novas. Nada preocupante — é o ritmo do Judiciário mesmo.\n\nAssim que houver algo, avisamos.\n\nAbraço da Equipe Ferreira & Sá Advocacia 💛";
        },
        // 6
        function($ctx) use ($ref, $adv) {
            return "Olá, {$ctx['nome']}!\n\nBom dia! Passando aqui pra dizer que estamos acompanhando {$ref($ctx)}{$adv($ctx)} diariamente. Hoje ainda sem movimentação nova.\n\nContinuamos atentas a qualquer alteração — nada passa despercebido pra gente.\n\nEquipe Ferreira & Sá Advocacia 🤝";
        },
        // 7
        function($ctx) use ($ref, $adv) {
            return "{$ctx['nome']}, bom dia!\n\nMais um dia sem alteração em {$ref($ctx)}{$adv($ctx)}. Sabemos que essa espera pode gerar ansiedade — por isso mantemos você informada todo dia, mesmo quando não há novidade.\n\nEstamos aqui, monitorando por você.\n\nEquipe Ferreira & Sá Advocacia ✨";
        },
        // 8
        function($ctx) use ($ref, $adv) {
            return "Oi, {$ctx['nome']}! 😊\n\nHoje {$ref($ctx)}{$adv($ctx)} continua sem movimentação nova. Isso não é motivo pra preocupação — é normal ficar alguns dias sem despacho, especialmente em determinadas fases.\n\nSeguimos acompanhando.\n\nBom dia pra você!\nEquipe Ferreira & Sá Advocacia";
        },
        // 9
        function($ctx) use ($ref, $adv) {
            return "Olá, {$ctx['nome']}!\n\nAcompanhamento de hoje: sem alterações em {$ref($ctx)}{$adv($ctx)}. Continuamos monitorando diariamente e agiremos assim que houver qualquer despacho.\n\nBom dia! Um abraço.\n\nEquipe Ferreira & Sá Advocacia 💐";
        },
        // 10
        function($ctx) use ($ref, $adv) {
            return "{$ctx['nome']}, bom dia! ☕\n\nSó pra deixar registrado: acompanhamos hoje e {$ref($ctx)}{$adv($ctx)} permanece sem movimentação nova. Nada mudou desde ontem.\n\nEstamos com você nessa. 💪\n\nEquipe Ferreira & Sá Advocacia";
        },
        // 11
        function($ctx) use ($ref, $adv) {
            return "Oi, {$ctx['nome']}!\n\nCheckin do dia: {$ref($ctx)}{$adv($ctx)} segue no mesmo estágio, sem novidades por enquanto. Mantemos vigilância diária.\n\nQualquer despacho, notificação ou movimentação nova, você é a primeira a saber.\n\nUm bom dia! 🌷\nEquipe Ferreira & Sá Advocacia";
        },
        // 12
        function($ctx) use ($ref, $adv) {
            return "Olá, {$ctx['nome']}!\n\nBom dia! Verificamos hoje e {$ref($ctx)}{$adv($ctx)} continua sem alterações. Isso é normal — nem todo dia tem despacho, mas nós continuamos acompanhando.\n\nAssim que houver qualquer novidade, avisamos aqui.\n\nEquipe Ferreira & Sá Advocacia 🤝";
        },
        // 13
        function($ctx) use ($ref, $adv) {
            return "{$ctx['nome']}, oi! 👋\n\nMais um dia acompanhando {$ref($ctx)}{$adv($ctx)}. Sem movimentações novas até agora, mas ficamos de olho.\n\nSe surgir qualquer coisa, entramos em contato de imediato. Pode ficar tranquila!\n\nEquipe Ferreira & Sá Advocacia 💛";
        },
    );
}
}

/**
 * Busca dados do case pra montar contexto rico dos templates:
 * - tipo_processo: nome amigável (ex: "ação de indenização por danos morais")
 * - polo_oposto: nome da parte adversa (ré/requerida)
 */
if (!function_exists('acompanhamento_montar_contexto_caso')) {
function acompanhamento_montar_contexto_caso(PDO $pdo, $caseId, $nomeCliente, $obsExtra = '') {
    $ctx = array(
        'nome' => $nomeCliente,
        'tipo_processo' => '',
        'polo_oposto' => '',
        'obs' => (string)$obsExtra,
    );
    try {
        $st = $pdo->prepare("SELECT case_type FROM cases WHERE id = ?");
        $st->execute(array((int)$caseId));
        $ct = trim((string)$st->fetchColumn());
        // Mapa de tipos técnicos → texto amigável (case_type pode vir tanto
        // do dropdown do módulo quanto texto livre)
        $mapa = array(
            'consumidor' => 'ação de direito do consumidor',
            'indenizacao' => 'ação de indenização',
            'danos_morais' => 'ação de indenização por danos morais',
            'trabalhista' => 'reclamação trabalhista',
            'alimentos' => 'ação de alimentos',
            'revisional_alimentos' => 'ação revisional de alimentos',
            'execucao_alimentos' => 'execução de alimentos',
            'divorcio' => 'ação de divórcio',
            'divorcio_consensual' => 'ação de divórcio consensual',
            'divorcio_litigioso' => 'ação de divórcio litigioso',
            'guarda' => 'ação de guarda',
            'guarda_convivencia' => 'ação de guarda e regulamentação de convivência',
            'convivencia' => 'ação de regulamentação de convivência',
            'inventario' => 'ação de inventário',
            'usucapiao' => 'ação de usucapião',
            'investigacao_paternidade' => 'ação de investigação de paternidade',
            'salario_maternidade' => 'ação de salário-maternidade',
            'auxilio_doenca' => 'ação de auxílio-doença',
            'familia' => 'demanda de direito de família',
            'responsabilidade_civil' => 'ação de responsabilidade civil',
        );
        $ctLow = mb_strtolower($ct, 'UTF-8');
        if (isset($mapa[$ctLow])) $ctx['tipo_processo'] = $mapa[$ctLow];
        elseif ($ct !== '' && $ct !== 'outro' && mb_strlen($ct) < 60) {
            // Texto livre curto: usa direto em minúsculas ("Consumidor" → "consumidor")
            $ctx['tipo_processo'] = 'ação de ' . $ctLow;
        }
    } catch (Exception $e) {}

    // Parte adversa (papel: reu / requerido / litisconsorte_passivo / terceiro)
    try {
        $st = $pdo->prepare(
            "SELECT COALESCE(NULLIF(nome,''), NULLIF(razao_social,''), NULLIF(representante_nome,''), NULLIF(nome_fantasia,'')) AS nome_parte
             FROM case_partes
             WHERE case_id = ?
               AND papel IN ('reu','requerido','litisconsorte_passivo','terceiro_interessado')
               AND COALESCE(eh_nosso_cliente,0) = 0
             ORDER BY FIELD(papel,'reu','requerido','litisconsorte_passivo','terceiro_interessado'), id
             LIMIT 1"
        );
        $st->execute(array((int)$caseId));
        $polo = trim((string)$st->fetchColumn());
        if ($polo !== '') {
            // Primeiro nome ou razão social encurtada (evita nomes de 8 palavras)
            $palavras = preg_split('/\s+/', $polo);
            if (count($palavras) > 3) {
                // Mantém só as 3 primeiras palavras significativas
                $polo = implode(' ', array_slice($palavras, 0, 3));
            }
            $ctx['polo_oposto'] = $polo;
        }
    } catch (Exception $e) {}

    return $ctx;
}
}

/** Escolhe um template DIFERENTE do último usado (rotação anti-repetição). */
if (!function_exists('acompanhamento_escolher_template')) {
function acompanhamento_escolher_template($ultimoIdx = null) {
    $templates = acompanhamento_templates();
    $total = count($templates);
    if ($total === 0) return array(0, null);
    if ($total === 1) return array(0, $templates[0]);
    // Pseudo-aleatório baseado no dia (evita repetir mesmo se cron reprocessar)
    // + garantir != ultimoIdx
    $base = (int)date('z') + (int)date('Y'); // muda por dia
    $idx = $base % $total;
    if ($ultimoIdx !== null && $idx === (int)$ultimoIdx) {
        $idx = ($idx + 1) % $total;
    }
    return array($idx, $templates[$idx]);
}
}

/**
 * Verifica se o case teve andamento NOVO desde a data indicada.
 * Retorna true = teve andamento novo (NÃO deve enviar msg).
 */
if (!function_exists('acompanhamento_teve_andamento_desde')) {
function acompanhamento_teve_andamento_desde(PDO $pdo, $caseId, $desdeDate) {
    if (!$caseId) return false;
    try {
        $st = $pdo->prepare(
            "SELECT COUNT(*) FROM case_andamentos
             WHERE case_id = ?
               AND data_andamento >= ?
               AND tipo NOT IN ('gerid','cancelamento','observacao_interna')"
        );
        $st->execute(array($caseId, $desdeDate));
        return (int)$st->fetchColumn() > 0;
    } catch (Exception $e) { return false; }
}
}

/**
 * Lista de feriados nacionais fixos (mm-dd). Móveis (carnaval/páscoa/corpus)
 * ficam de fora — se cair num deles, a msg vai. Amanda ajusta manual.
 */
if (!function_exists('acompanhamento_eh_feriado')) {
function acompanhamento_eh_feriado($ts) {
    $md = date('m-d', $ts);
    $feriados = array('01-01','04-21','05-01','09-07','10-12','11-02','11-15','11-20','12-25');
    return in_array($md, $feriados, true);
}
}
