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
 * recebe ($nomeCliente, $tituloProcesso, $obsExtra) e devolve string.
 * Rotação garantida: nunca envia o mesmo índice do último envio.
 */
if (!function_exists('acompanhamento_templates')) {
function acompanhamento_templates() {
    return array(
        // 0
        function($n, $t, $o) {
            return "Olá, {$n}! ☀️\n\nPassando pra dizer que continuamos acompanhando de perto o seu processo. Hoje ainda não houve nova movimentação, mas estamos atentos a qualquer despacho.\n\nAssim que houver novidade, avisamos por aqui na hora.\n\nEquipe Ferreira & Sá Advocacia 🤝";
        },
        // 1
        function($n, $t, $o) {
            return "Bom dia, {$n}!\n\nSó pra manter você informada: verificamos hoje e ainda não houve movimentação nova no seu processo. Continuamos monitorando diariamente.\n\nQualquer atualização, você será a primeira a saber. ✨\n\nEquipe Ferreira & Sá Advocacia";
        },
        // 2
        function($n, $t, $o) {
            return "Oi, {$n}! 👋\n\nAcabamos de checar o andamento do seu processo — sem movimentações novas por enquanto. Estamos de olho.\n\nCaso surja qualquer despacho ou intimação, entramos em contato imediatamente.\n\nUm bom dia pra você! 💐\n\nEquipe Ferreira & Sá Advocacia";
        },
        // 3
        function($n, $t, $o) {
            return "Olá, {$n}!\n\nInformamos que hoje ainda não houve nova movimentação no seu processo. Sabemos que a espera não é fácil, mas queremos que fique tranquila: estamos acompanhando dia após dia.\n\nQualquer novidade avisamos aqui.\n\nEquipe Ferreira & Sá Advocacia 🌸";
        },
        // 4
        function($n, $t, $o) {
            return "Bom dia, {$n}! 🌞\n\nMais um dia de acompanhamento — sem alterações no seu processo até agora. Isso é comum em algumas fases processuais, então não se preocupe.\n\nSeguimos monitorando por aqui.\n\nEquipe Ferreira & Sá Advocacia";
        },
        // 5
        function($n, $t, $o) {
            return "Oi, {$n}!\n\nCheckin diário: verificamos o seu processo e ele continua na mesma situação de ontem, sem despachos ou movimentações novas. Nada preocupante — é o ritmo do Judiciário mesmo.\n\nAssim que houver algo, avisamos.\n\nAbraço da Equipe Ferreira & Sá Advocacia 💛";
        },
        // 6
        function($n, $t, $o) {
            return "Olá, {$n}!\n\nBom dia! Passando aqui pra dizer que estamos acompanhando o seu processo diariamente. Hoje ainda sem movimentação nova.\n\nContinuamos atentas a qualquer alteração — nada passa despercebido pra gente.\n\nEquipe Ferreira & Sá Advocacia 🤝";
        },
        // 7
        function($n, $t, $o) {
            return "{$n}, bom dia!\n\nMais um dia sem alteração no seu processo. Sabemos que essa espera pode gerar ansiedade — por isso mantemos você informada todo dia, mesmo quando não há novidade.\n\nEstamos aqui, monitorando por você.\n\nEquipe Ferreira & Sá Advocacia ✨";
        },
        // 8
        function($n, $t, $o) {
            return "Oi, {$n}! 😊\n\nHoje o seu processo continua sem movimentação nova. Isso não é motivo pra preocupação — é normal ficar alguns dias sem despacho, especialmente em determinadas fases.\n\nSeguimos acompanhando.\n\nBom dia pra você!\nEquipe Ferreira & Sá Advocacia";
        },
        // 9
        function($n, $t, $o) {
            return "Olá, {$n}!\n\nAcompanhamento de hoje: sem alterações no processo. Continuamos monitorando diariamente e agiremos assim que houver qualquer despacho.\n\nBom dia! Um abraço.\n\nEquipe Ferreira & Sá Advocacia 💐";
        },
        // 10
        function($n, $t, $o) {
            return "{$n}, bom dia! ☕\n\nSó pra deixar registrado: acompanhamos hoje e o seu processo permanece sem movimentação nova. Nada mudou desde ontem.\n\nEstamos com você nessa. 💪\n\nEquipe Ferreira & Sá Advocacia";
        },
        // 11
        function($n, $t, $o) {
            return "Oi, {$n}!\n\nCheckin do dia: o seu processo segue no mesmo estágio, sem novidades por enquanto. Mantemos vigilância diária.\n\nQualquer despacho, notificação ou movimentação nova, você é a primeira a saber.\n\nUm bom dia! 🌷\nEquipe Ferreira & Sá Advocacia";
        },
        // 12
        function($n, $t, $o) {
            return "Olá, {$n}!\n\nBom dia! Verificamos hoje e o seu processo continua sem alterações. Isso é normal — nem todo dia tem despacho, mas nós continuamos acompanhando.\n\nAssim que houver qualquer novidade, avisamos aqui.\n\nEquipe Ferreira & Sá Advocacia 🤝";
        },
        // 13
        function($n, $t, $o) {
            return "{$n}, oi! 👋\n\nMais um dia acompanhando o seu processo. Sem movimentações novas até agora, mas ficamos de olho.\n\nSe surgir qualquer coisa, entramos em contato de imediato. Pode ficar tranquila!\n\nEquipe Ferreira & Sá Advocacia 💛";
        },
    );
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
