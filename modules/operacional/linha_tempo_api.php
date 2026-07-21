<?php
/**
 * Ferreira & Sá Hub — API da Linha do Tempo do Cliente
 *
 * Endpoints do editor (modules/operacional/linha_tempo.php) e do card
 * da aba "Linha do Tempo" em caso_ver.php.
 *
 * Sempre POST + CSRF. Resposta sempre JSON — o middleware devolve 401/403
 * em JSON quando a sessao morre (front trata com fsaMostrarSessaoExpirada).
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();
require_access('operacional');

require_once APP_ROOT . '/core/functions_linha_tempo.php';

@ob_start();
function lt_json($data, $status = 200) {
    while (@ob_get_level() > 0) { @ob_end_clean(); }
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($data);
    exit;
}
function lt_erro($msg, $status = 400) { lt_json(array('ok' => false, 'erro' => $msg), $status); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') lt_erro('Método não permitido.', 405);
if (!validate_csrf())                      lt_erro('Sessão expirada — recarregue a página.', 403);

$pdo    = db();
$acao   = isset($_POST['action']) ? trim((string)$_POST['action']) : '';
$caseId = isset($_POST['case_id']) ? (int)$_POST['case_id'] : 0;

lt_self_heal($pdo);

// ── Todas as ações operam sobre um caso existente ─────────────────
if ($caseId <= 0) lt_erro('Caso não informado.');
$stCaso = $pdo->prepare("SELECT id, title, client_id FROM cases WHERE id = ?");
$stCaso->execute(array($caseId));
$caso = $stCaso->fetch();
if (!$caso) lt_erro('Caso não encontrado.', 404);

$tl = lt_get_or_create($pdo, $caseId, (int)current_user_id());

switch ($acao) {

// ─────────────────────────────────────────────────────────────────
//  Cabeçalho, painel, blocos de texto, mídia e trava
// ─────────────────────────────────────────────────────────────────
case 'salvar_config':
    $gate = ($_POST['gate'] ?? 'cpf') === 'aberto' ? 'aberto' : 'cpf';
    $cpf  = preg_replace('/\D/', '', (string)($_POST['gate_cpf'] ?? ''));
    if ($gate === 'cpf' && $cpf !== '' && strlen($cpf) !== 11) {
        lt_erro('O CPF da trava precisa ter 11 dígitos.');
    }

    $midiaUrl = trim((string)($_POST['midia_url'] ?? ''));
    if ($midiaUrl !== '' && !preg_match('~^https://~i', $midiaUrl)) {
        lt_erro('O link do vídeo/áudio precisa começar com https://');
    }
    $midiaTipo = ($_POST['midia_tipo'] ?? '') === 'audio' ? 'audio' : 'video';

    $st = $pdo->prepare(
        "UPDATE case_timeline SET
            titulo = ?, lede = ?,
            gate = ?, gate_cpf = ?, gate_label = ?,
            painel_ok = ?, painel_atencao = ?, painel_acao = ?,
            pedidos = ?, pedidos_auto = ?, proximos_passos = ?, fecho = ?,
            midia_url = ?, midia_tipo = ?, midia_titulo = ?,
            atualizado_em = NOW()
         WHERE id = ?"
    );
    $st->execute(array(
        mb_substr(trim((string)($_POST['titulo'] ?? '')), 0, 200),
        trim((string)($_POST['lede'] ?? '')),
        $gate,
        $cpf !== '' ? $cpf : null,
        mb_substr(trim((string)($_POST['gate_label'] ?? '')), 0, 120),
        trim((string)($_POST['painel_ok'] ?? '')),
        trim((string)($_POST['painel_atencao'] ?? '')),
        trim((string)($_POST['painel_acao'] ?? '')),
        trim((string)($_POST['pedidos'] ?? '')),
        !empty($_POST['pedidos_auto']) ? 1 : 0,
        trim((string)($_POST['proximos_passos'] ?? '')),
        trim((string)($_POST['fecho'] ?? '')),
        $midiaUrl !== '' ? $midiaUrl : null,
        $midiaUrl !== '' ? $midiaTipo : null,
        mb_substr(trim((string)($_POST['midia_titulo'] ?? '')), 0, 200),
        (int)$tl['id'],
    ));
    lt_json(array('ok' => true, 'msg' => 'Salvo.'));

// ─────────────────────────────────────────────────────────────────
//  Marcos
// ─────────────────────────────────────────────────────────────────
case 'salvar_marco':
    $id     = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $titulo = trim((string)($_POST['titulo'] ?? ''));
    if ($titulo === '') lt_erro('O marco precisa de um título.');

    $data = trim((string)($_POST['data_evento'] ?? ''));
    if ($data !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) $data = '';

    $tipo = strtolower(trim((string)($_POST['tipo'] ?? 'outro')));
    if (!in_array($tipo, lt_tipos_validos(), true)) $tipo = 'outro';

    $campos = array(
        mb_substr($titulo, 0, 200),
        trim((string)($_POST['texto'] ?? '')),
        trim((string)($_POST['nota'] ?? '')),
        $data !== '' ? $data : null,
        mb_substr(trim((string)($_POST['data_label'] ?? '')), 0, 60),
        $tipo,
        !empty($_POST['destaque']) ? 1 : 0,
        !empty($_POST['visivel']) ? 1 : 0,
    );

    if ($id > 0) {
        // Confere que o marco é DESTA linha do tempo antes de mexer
        $stChk = $pdo->prepare("SELECT id FROM case_timeline_eventos WHERE id = ? AND timeline_id = ?");
        $stChk->execute(array($id, (int)$tl['id']));
        if (!$stChk->fetchColumn()) lt_erro('Marco não encontrado.', 404);

        // editado_manual = 1 protege o marco da próxima geração por IA
        $campos[] = $id;
        $pdo->prepare(
            "UPDATE case_timeline_eventos SET
                titulo = ?, texto = ?, nota = ?, data_evento = ?, data_label = ?,
                tipo = ?, destaque = ?, visivel = ?,
                editado_manual = 1, atualizado_em = NOW()
             WHERE id = ?"
        )->execute($campos);
        lt_json(array('ok' => true, 'id' => $id, 'msg' => 'Marco salvo.'));
    }

    $ordem = (int)$pdo->query(
        "SELECT COALESCE(MAX(ordem), 0) + 1 FROM case_timeline_eventos WHERE timeline_id = " . (int)$tl['id']
    )->fetchColumn();
    array_push($campos, (int)$tl['id'], $ordem);
    $pdo->prepare(
        "INSERT INTO case_timeline_eventos
            (titulo, texto, nota, data_evento, data_label, tipo, destaque, visivel,
             timeline_id, ordem, editado_manual, criado_em)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())"
    )->execute($campos);
    lt_json(array('ok' => true, 'id' => (int)$pdo->lastInsertId(), 'msg' => 'Marco criado.'));

case 'excluir_marco':
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    if ($id <= 0) lt_erro('Marco não informado.');
    $st = $pdo->prepare("DELETE FROM case_timeline_eventos WHERE id = ? AND timeline_id = ?");
    $st->execute(array($id, (int)$tl['id']));
    if (!$st->rowCount()) lt_erro('Marco não encontrado.', 404);
    lt_json(array('ok' => true, 'msg' => 'Marco excluído.'));

case 'reordenar':
    $ids = isset($_POST['ids']) ? (array)$_POST['ids'] : array();
    if (!$ids) lt_erro('Nada para reordenar.');
    $st = $pdo->prepare("UPDATE case_timeline_eventos SET ordem = ? WHERE id = ? AND timeline_id = ?");
    $i = 0;
    foreach ($ids as $id) {
        $id = (int)$id;
        if ($id <= 0) continue;
        $st->execute(array(++$i, $id, (int)$tl['id']));
    }
    lt_json(array('ok' => true, 'msg' => 'Ordem salva.'));

// ─────────────────────────────────────────────────────────────────
//  Rascunho por IA
// ─────────────────────────────────────────────────────────────────
case 'gerar_ia':
    require_once APP_ROOT . '/core/functions_ia.php';

    if (!ia_feature_ativa('linha_tempo')) {
        lt_erro('O rascunho por IA está desligado. Ligue em Admin → Custos de IA → "Rascunho da Linha do Tempo do Cliente".');
    }

    @set_time_limit(180);
    $r = ia_linha_tempo_gerar($caseId, (int)current_user_id());
    if (empty($r['ok'])) lt_erro((string)$r['erro']);

    $d = $r['dados'];

    // Preenche só os campos de texto que ainda estão VAZIOS — nunca
    // atropela o que a Amanda já escreveu.
    $sets = array();
    $vals = array();
    $sugestoes = array(
        'titulo'          => $d['titulo'],
        'lede'            => $d['lede'],
        'painel_ok'       => $d['painel']['ok'],
        'painel_atencao'  => $d['painel']['atencao'],
        'painel_acao'     => $d['painel']['acao'],
        'proximos_passos' => implode("\n", $d['proximos_passos']),
        'fecho'           => $d['fecho'],
    );
    foreach ($sugestoes as $col => $valor) {
        if ($valor === '' || trim((string)$tl[$col]) !== '') continue;
        $sets[] = "$col = ?";
        $vals[] = $valor;
    }
    if ($sets) {
        $vals[] = (int)$tl['id'];
        $pdo->prepare("UPDATE case_timeline SET " . implode(', ', $sets) . ", atualizado_em = NOW() WHERE id = ?")
            ->execute($vals);
    }

    // Marcos: apaga só os que vieram da IA e NÃO foram tocados a mão.
    $pdo->prepare("DELETE FROM case_timeline_eventos WHERE timeline_id = ? AND gerado_ia = 1 AND editado_manual = 0")
        ->execute(array((int)$tl['id']));

    // Não recria marco de um andamento que já virou marco editado a mão
    $jaManuais = $pdo->prepare(
        "SELECT andamento_id FROM case_timeline_eventos
         WHERE timeline_id = ? AND andamento_id IS NOT NULL"
    );
    $jaManuais->execute(array((int)$tl['id']));
    $ocupados = array_map('intval', $jaManuais->fetchAll(PDO::FETCH_COLUMN));

    $ordem = (int)$pdo->query(
        "SELECT COALESCE(MAX(ordem), 0) FROM case_timeline_eventos WHERE timeline_id = " . (int)$tl['id']
    )->fetchColumn();

    $stIns = $pdo->prepare(
        "INSERT INTO case_timeline_eventos
            (timeline_id, data_evento, titulo, texto, nota, tipo, destaque, visivel,
             ordem, andamento_id, gerado_ia, editado_manual, criado_em)
         VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, 1, 0, NOW())"
    );
    $criados = 0;
    foreach ($d['marcos'] as $m) {
        if ($m['andamento_id'] !== null && in_array((int)$m['andamento_id'], $ocupados, true)) continue;
        $stIns->execute(array(
            (int)$tl['id'], $m['data'], $m['titulo'], $m['texto'], $m['nota'],
            $m['tipo'], $m['destaque'], ++$ordem, $m['andamento_id'],
        ));
        $criados++;
    }

    // Renumera pra ordem cronológica ficar coerente com os marcos manuais
    lt_renumerar($pdo, (int)$tl['id']);

    audit_log('linha_tempo_gerar_ia', 'cases', $caseId,
              $criados . ' marco(s) gerado(s) — R$ ' . number_format((float)$r['custo_brl'], 4, ',', '.'));

    lt_json(array(
        'ok'        => true,
        'criados'   => $criados,
        'custo_brl' => (float)$r['custo_brl'],
        'msg'       => $criados . ' marco(s) gerado(s) pela IA. Revise antes de publicar.',
    ));

// ─────────────────────────────────────────────────────────────────
//  Publicação
// ─────────────────────────────────────────────────────────────────
case 'publicar':
    $nMarcos = (int)$pdo->query(
        "SELECT COUNT(*) FROM case_timeline_eventos WHERE timeline_id = " . (int)$tl['id'] . " AND visivel = 1"
    )->fetchColumn();
    if (!$nMarcos) lt_erro('Adicione pelo menos um marco visível antes de publicar.');

    if ($tl['gate'] === 'cpf' && !preg_match('/^\d{11}$/', (string)$tl['gate_cpf'])) {
        lt_erro('A trava está em CPF, mas nenhum CPF válido foi informado. Preencha o CPF ou mude a trava para "link aberto".');
    }

    $pdo->prepare("UPDATE case_timeline SET publicado = 1, publicado_em = NOW(), atualizado_em = NOW() WHERE id = ?")
        ->execute(array((int)$tl['id']));
    audit_log('linha_tempo_publicar', 'cases', $caseId, 'Linha do tempo publicada (' . $nMarcos . ' marcos)');
    lt_json(array('ok' => true, 'msg' => 'Publicado. O link já abre pro cliente.', 'url' => lt_url_publica($tl['token'])));

case 'despublicar':
    $pdo->prepare("UPDATE case_timeline SET publicado = 0, atualizado_em = NOW() WHERE id = ?")
        ->execute(array((int)$tl['id']));
    audit_log('linha_tempo_despublicar', 'cases', $caseId, 'Linha do tempo despublicada');
    lt_json(array('ok' => true, 'msg' => 'Despublicado. O link parou de abrir.'));

case 'regerar_token':
    $novo = lt_novo_token($pdo);
    $pdo->prepare("UPDATE case_timeline SET token = ?, atualizado_em = NOW() WHERE id = ?")
        ->execute(array($novo, (int)$tl['id']));
    audit_log('linha_tempo_regerar_token', 'cases', $caseId, 'Link antigo invalidado');
    lt_json(array('ok' => true, 'msg' => 'Link novo gerado. O link antigo parou de funcionar.',
                  'url' => lt_url_publica($novo)));

// ─────────────────────────────────────────────────────────────────
//  Envio no WhatsApp — sempre com revisão da Amanda antes
// ─────────────────────────────────────────────────────────────────
case 'preview_whatsapp':
    if (!(int)$tl['publicado']) lt_erro('Publique a linha do tempo antes de enviar pro cliente.');

    $stCli = $pdo->prepare("SELECT name, phone FROM clients WHERE id = ?");
    $stCli->execute(array((int)$caso['client_id']));
    $cli = $stCli->fetch();
    if (!$cli) lt_erro('Este caso não tem cliente principal vinculado.');

    $fone = preg_replace('/\D/', '', (string)$cli['phone']);
    if (strlen($fone) < 10) lt_erro('O cliente não tem telefone válido cadastrado.');

    // Primeiro nome, pra mensagem não ficar robótica
    $partesNome = preg_split('/\s+/', trim((string)$cli['name']));
    $primeiro   = $partesNome ? $partesNome[0] : '';

    $urlLonga = lt_url_publica($tl['token']);
    $urlCurta = $urlLonga;
    try {
        require_once APP_ROOT . '/core/functions_shortlinks.php';
        if (function_exists('sl_criar_short_link')) {
            $c = sl_criar_short_link($urlLonga, array('case_id' => $caseId, 'origem' => 'linha_tempo'));
            if ($c) $urlCurta = $c;
        }
    } catch (Throwable $e) { /* sem shortlink, manda a URL longa */ }

    $msg = "Oi, " . $primeiro . "! Tudo bem?\n\n"
         . "Preparamos uma página exclusiva contando a história do seu processo do começo até onde estamos hoje, "
         . "em linguagem simples e sem juridiquês.\n\n"
         . $urlCurta . "\n\n";
    $msg .= $tl['gate'] === 'cpf'
          ? "Para abrir, é só informar o seu CPF — assim ninguém além de você consegue ver.\n\n"
          : "É só clicar para abrir.\n\n";
    $msg .= "Qualquer dúvida, estamos por aqui.\n\n"
          . "Equipe Ferreira & Sá Advocacia";

    lt_json(array(
        'ok'       => true,
        'mensagem' => $msg,
        'telefone' => $fone,
        'cliente'  => (string)$cli['name'],
        'url'      => $urlCurta,
    ));

case 'enviar_whatsapp':
    if (!(int)$tl['publicado']) lt_erro('Publique a linha do tempo antes de enviar pro cliente.');

    $mensagem = trim((string)($_POST['mensagem'] ?? ''));
    $fone     = preg_replace('/\D/', '', (string)($_POST['telefone'] ?? ''));
    if ($mensagem === '')      lt_erro('A mensagem está vazia.');
    if (strlen($fone) < 10)    lt_erro('Telefone inválido.');

    require_once APP_ROOT . '/core/functions_zapi.php';

    // Canal 24 (CX/Operacional) — é acompanhamento de processo, não comercial.
    // zapi_send_text normaliza o número internamente.
    $r = zapi_send_text('24', $fone, $mensagem);
    if (empty($r['ok'])) {
        lt_erro('O WhatsApp não aceitou o envio: ' . (string)($r['erro'] ?? 'erro desconhecido'));
    }

    audit_log('linha_tempo_enviar_wa', 'cases', $caseId, 'Link enviado para ' . $fone);
    lt_json(array('ok' => true, 'msg' => 'Enviado pro WhatsApp do cliente.'));

default:
    lt_erro('Ação desconhecida: ' . $acao);
}
