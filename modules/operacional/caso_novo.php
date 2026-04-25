<?php
/**
 * Ferreira & Sa Hub — Cadastro Manual de Novo Processo
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();

$pdo = db();

// ─── AJAX: busca de clientes ────────────────────────────────────────
if (isset($_GET['ajax_busca_cliente'])) {
    header('Content-Type: application/json; charset=utf-8');
    $q = trim(isset($_GET['q']) ? $_GET['q'] : '');
    if (strlen($q) < 2) { echo '[]'; exit; }
    $stmt = $pdo->prepare(
        "SELECT id, name, cpf, phone FROM clients WHERE name LIKE ? ORDER BY name LIMIT 15"
    );
    $stmt->execute(array('%' . $q . '%'));
    echo json_encode($stmt->fetchAll());
    exit;
}

// ─── POST: gravar novo caso ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) {
        flash_set('error', 'Token CSRF invalido.');
        redirect(module_url('operacional', 'caso_novo.php'));
    }

    $client_id          = (int)($_POST['client_id'] ?? 0);
    $parte_re_nome      = trim($_POST['parte_re_nome'] ?? '');
    $parte_re_cpf_cnpj  = trim($_POST['parte_re_cpf_cnpj'] ?? '');

    // Filhos (para processos de alimentos)
    $filhosJson = null;
    $filhosNomes = $_POST['filho_nome'] ?? array();
    $filhosNasc = $_POST['filho_nascimento'] ?? array();
    $filhosCpf = $_POST['filho_cpf'] ?? array();
    if (!empty($filhosNomes)) {
        $filhos = array();
        for ($fi = 0; $fi < count($filhosNomes); $fi++) {
            $fn = trim($filhosNomes[$fi]);
            if ($fn === '') continue;
            $filhos[] = array(
                'nome' => $fn,
                'nascimento' => isset($filhosNasc[$fi]) ? trim($filhosNasc[$fi]) : '',
                'cpf' => isset($filhosCpf[$fi]) ? trim($filhosCpf[$fi]) : '',
            );
        }
        if (!empty($filhos)) $filhosJson = json_encode($filhos, JSON_UNESCAPED_UNICODE);
    }
    $title              = trim($_POST['title'] ?? '');
    $case_type          = trim($_POST['case_type'] ?? '');
    if ($case_type === 'outro') $case_type = trim($_POST['case_type_outro'] ?? 'Outro');
    $case_number        = trim($_POST['case_number'] ?? '');
    $court              = trim($_POST['court'] ?? '');
    $comarca            = trim($_POST['comarca'] ?? '');
    $comarca_uf         = trim($_POST['comarca_uf'] ?? '');
    $regional           = trim($_POST['regional'] ?? '');
    $sistema_tribunal   = trim($_POST['sistema_tribunal'] ?? '');
    $segredo_justica    = isset($_POST['segredo_justica']) ? 1 : 0;
    $departamento       = trim($_POST['departamento'] ?? 'operacional');
    $category           = in_array(($_POST['category'] ?? ''), array('judicial', 'extrajudicial')) ? $_POST['category'] : 'judicial';
    $distribution_date  = $_POST['distribution_date'] ?? '';
    $priority           = in_array(($_POST['priority'] ?? ''), array('urgente', 'alta', 'normal', 'baixa')) ? $_POST['priority'] : 'normal';
    $responsible_user_id = (int)($_POST['responsible_user_id'] ?? 0);
    $drive_folder_url   = trim($_POST['drive_folder_url'] ?? '');
    $notes              = trim($_POST['notes'] ?? '');
    $status             = trim($_POST['status'] ?? 'em_andamento');
    $migracao_antigo    = isset($_POST['migracao_antigo']) ? 1 : 0;

    // Validacoes basicas
    $errors = array();
    if ($title === '') { $errors[] = 'O titulo e obrigatorio.'; }
    if ($client_id < 1) { $errors[] = 'Selecione um cliente.'; }

    // Verificar duplicata por case_number
    if ($case_number !== '') {
        $stmtDup = $pdo->prepare("SELECT id, title FROM cases WHERE case_number = ? LIMIT 1");
        $stmtDup->execute(array($case_number));
        $dupCase = $stmtDup->fetch();
        if ($dupCase && empty($_POST['confirmar_duplicata'])) {
            flash_set('error', 'Já existe um processo com o nº ' . $case_number . ': "' . $dupCase['title'] . '" (Pasta #' . $dupCase['id'] . '). Se deseja criar mesmo assim, marque a opção de confirmação.');
            redirect(module_url('operacional', 'caso_novo.php'));
        }
    }

    if (!empty($errors)) {
        flash_set('error', implode(' ', $errors));
        redirect(module_url('operacional', 'caso_novo.php'));
    }

    // Processos incidentais / recursos
    $principalId = (int)($_POST['principal_id'] ?? $_GET['principal_id'] ?? 0) ?: null;
    $tipoRelacao = clean_str($_POST['tipo_relacao'] ?? $_GET['tipo_relacao'] ?? '', 50) ?: null;
    $tipoVinculo = clean_str($_POST['tipo_vinculo'] ?? $_GET['tipo_vinculo'] ?? '', 20) ?: null;
    if ($tipoVinculo && !in_array($tipoVinculo, array('incidental', 'recurso'))) $tipoVinculo = null;
    $isIncidental = $principalId ? 1 : 0;

    $sql = "INSERT INTO cases
        (client_id, parte_re_nome, parte_re_cpf_cnpj, filhos_json, title, case_type, case_number, court, comarca, comarca_uf, regional, sistema_tribunal, segredo_justica, departamento, category, distribution_date, status, priority, responsible_user_id, drive_folder_url, notes, processo_principal_id, tipo_relacao, tipo_vinculo, is_incidental, kanban_oculto, created_at, updated_at)
        VALUES
        (:client_id, :parte_re_nome, :parte_re_cpf_cnpj, :filhos_json, :title, :case_type, :case_number, :court, :comarca, :comarca_uf, :regional, :sistema_tribunal, :segredo_justica, :departamento, :category, :distribution_date, :status, :priority, :responsible_user_id, :drive_folder_url, :notes, :principal_id, :tipo_relacao, :tipo_vinculo, :is_incidental, :kanban_oculto, NOW(), NOW())";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array(
        ':client_id'          => $client_id,
        ':parte_re_nome'      => $parte_re_nome !== '' ? $parte_re_nome : null,
        ':parte_re_cpf_cnpj'  => $parte_re_cpf_cnpj !== '' ? $parte_re_cpf_cnpj : null,
        ':filhos_json'        => $filhosJson,
        ':title'              => $title,
        ':case_type'          => $case_type,
        ':case_number'        => $case_number,
        ':court'              => $court,
        ':comarca'            => $comarca,
        ':comarca_uf'         => $comarca_uf !== '' ? $comarca_uf : null,
        ':regional'           => $regional !== '' ? $regional : null,
        ':sistema_tribunal'   => $sistema_tribunal !== '' ? $sistema_tribunal : null,
        ':segredo_justica'    => $segredo_justica,
        ':departamento'       => $departamento !== '' ? $departamento : 'operacional',
        ':category'           => $category,
        ':distribution_date'  => $distribution_date !== '' ? $distribution_date : null,
        ':status'             => $status,
        ':priority'           => $priority,
        ':responsible_user_id'=> $responsible_user_id > 0 ? $responsible_user_id : null,
        ':drive_folder_url'   => $drive_folder_url !== '' ? $drive_folder_url : null,
        ':notes'              => $notes !== '' ? $notes : null,
        ':principal_id'       => $principalId,
        ':tipo_relacao'       => $tipoRelacao,
        ':tipo_vinculo'       => $tipoVinculo,
        ':is_incidental'      => $isIncidental,
        ':kanban_oculto'      => $migracao_antigo,
    ));

    $newId = (int)$pdo->lastInsertId();

    // ═══ Criar partes na tabela case_partes ═══
    // Cliente = papel selecionado (autor, réu ou rep. legal)
    $clientePapel = isset($_POST['cliente_papel']) ? $_POST['cliente_papel'] : 'autor';
    $clienteRepresenta = isset($_POST['cliente_representa']) ? $_POST['cliente_representa'] : '';
    $clienteParteId = null;
    if ($client_id > 0) {
        try {
            $cl = $pdo->prepare("SELECT name, cpf, rg, birth_date, profession, marital_status, email, phone, address_street, address_city, address_state, address_zip FROM clients WHERE id = ?");
            $cl->execute(array($client_id));
            $cliData = $cl->fetch();
            if ($cliData) {
                $pdo->prepare("INSERT INTO case_partes (case_id, papel, tipo_pessoa, nome, cpf, rg, nascimento, profissao, estado_civil, email, telefone, endereco, cidade, uf, client_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute(array($newId, $clientePapel, 'fisica', $cliData['name'], $cliData['cpf'], $cliData['rg'], $cliData['birth_date'], $cliData['profession'], $cliData['marital_status'], $cliData['email'], $cliData['phone'], $cliData['address_street'], $cliData['address_city'], $cliData['address_state'], $client_id));
                $clienteParteId = (int)$pdo->lastInsertId();
            }
        } catch (Exception $e) {}
    }

    // Partes adicionadas no formulário (múltiplas)
    $partesNomes = isset($_POST['partes_nome']) ? $_POST['partes_nome'] : array();
    $partesDocs = isset($_POST['partes_doc']) ? $_POST['partes_doc'] : array();
    $partesPapeis = isset($_POST['partes_papel']) ? $_POST['partes_papel'] : array();
    $partesTipos = isset($_POST['partes_tipo']) ? $_POST['partes_tipo'] : array();
    $partesClientIds = isset($_POST['partes_client_id']) ? $_POST['partes_client_id'] : array();
    for ($pi = 0; $pi < count($partesNomes); $pi++) {
        $pNome = trim($partesNomes[$pi]);
        if ($pNome === '') continue;
        $pDoc = isset($partesDocs[$pi]) ? trim($partesDocs[$pi]) : '';
        $pPapel = isset($partesPapeis[$pi]) ? $partesPapeis[$pi] : 'reu';
        $pTipo = isset($partesTipos[$pi]) ? $partesTipos[$pi] : 'fisica';
        $pClientId = isset($partesClientIds[$pi]) ? (int)$partesClientIds[$pi] : 0;
        try {
            if ($pTipo === 'juridica') {
                $pdo->prepare("INSERT INTO case_partes (case_id, papel, tipo_pessoa, razao_social, cnpj, client_id) VALUES (?,?,?,?,?,?)")
                    ->execute(array($newId, $pPapel, 'juridica', $pNome, $pDoc, $pClientId ?: null));
            } else {
                $pdo->prepare("INSERT INTO case_partes (case_id, papel, tipo_pessoa, nome, cpf, client_id) VALUES (?,?,?,?,?,?)")
                    ->execute(array($newId, $pPapel, 'fisica', $pNome, $pDoc, $pClientId ?: null));
            }
        } catch (Exception $e) {}
    }

    // Se cliente é Rep. Legal, vincular às partes representadas (autores ou réus)
    if ($clientePapel === 'representante_legal' && $clienteParteId && $clienteRepresenta) {
        $papelRep = ($clienteRepresenta === 'reus') ? array('reu','litisconsorte_passivo') : array('autor','litisconsorte_ativo');
        $placeholders = implode(',', array_fill(0, count($papelRep), '?'));
        $params = array_merge(array($clienteParteId, $newId), $papelRep, array($clienteParteId));
        try {
            $pdo->prepare("UPDATE case_partes SET representa_parte_id = NULL WHERE representa_parte_id = ? AND case_id = ?")->execute(array($clienteParteId, $newId));
            $pdo->prepare("UPDATE case_partes SET representa_parte_id = ? WHERE case_id = ? AND papel IN ($placeholders) AND id != ?")->execute($params);
        } catch (Exception $e) {}
    }

    // ═══ Import automático de andamentos + partes pendentes do email PJe ═══
    // Se este case_number tinha registros em email_monitor_pendentes (status='pendente'),
    // significa que andamentos do PJe chegaram por email mas o caso ainda não existia.
    // Agora que o caso existe:
    //   1. Varremos a caixa do Gmail (FROM do PJe + BODY contendo o CNJ) e
    //      importamos todos os movimentos pra case_andamentos (dedup por MD5).
    //   2. Adicionamos polo_ativo / polo_passivo (saved no pendente) como case_partes,
    //      ignorando siglas (A.G.F.) e linkando à `clients` se o nome bater.
    $importadosPje = 0;
    $partesAuto    = 0;
    if ($case_number !== '') {
        try {
            $stmtPendChk = $pdo->prepare("SELECT id, polo_ativo, polo_passivo FROM email_monitor_pendentes WHERE case_number = ? AND status = 'pendente' LIMIT 1");
            $stmtPendChk->execute(array($case_number));
            $pendRows = $stmtPendChk->fetchAll();
            $stmtPendChk->closeCursor();

            if (!empty($pendRows)) {
                $pendDados = $pendRows[0];
                require_once __DIR__ . '/../../includes/email_monitor_functions.php';

                // ─── 1. Import dos movimentos via IMAP ───────────────────
                $mboxImp = email_monitor_conectar_imap();
                if ($mboxImp) {
                    $userId = (int)current_user_id();
                    // Search server-side por FROM + BODY contendo o CNJ — evita varrer
                    // todos os emails (caixa pode ter milhares).
                    $emailsImp = @imap_search($mboxImp, 'FROM "' . IMAP_FROM_FILTER . '" BODY "' . $case_number . '"', SE_UID);
                    if (!is_array($emailsImp)) $emailsImp = array();
                    foreach ($emailsImp as $uidImp) {
                        try {
                            $parsedImp = email_monitor_parsear_email($mboxImp, $uidImp);
                            // Confirma CNJ exato (BODY pode ter falsos positivos com substring)
                            if ($parsedImp['cnj'] !== $case_number) continue;
                            foreach ($parsedImp['movimentos'] as $movImp) {
                                if (email_monitor_inserir_andamento($pdo, $newId, $movImp, (int)$segredo_justica, $userId)) {
                                    $importadosPje++;
                                }
                            }
                        } catch (Throwable $e) {
                            continue; // falha em 1 email não derruba o batch
                        }
                    }
                    @imap_close($mboxImp);
                }

                // ─── 2. Auto-cadastro de partes a partir dos polos ───────
                // Polos vêm da própria tabela email_monitor_pendentes (saved pelo cron).
                // Ignora siglas e evita duplicar partes já cadastradas pelo formulário.
                $polosAuto = array(
                    array('nome' => trim((string)$pendDados['polo_ativo']),   'papel' => 'autor'),
                    array('nome' => trim((string)$pendDados['polo_passivo']), 'papel' => 'reu'),
                );
                foreach ($polosAuto as $polo) {
                    $nomeP = $polo['nome'];
                    if ($nomeP === '') continue;
                    if (email_monitor_eh_so_iniciais($nomeP)) continue;

                    // Skip se já existe parte com esse nome no caso (cliente principal
                    // ou parte adicionada manualmente no formulário).
                    try {
                        $stmtChkP = $pdo->prepare(
                            "SELECT id FROM case_partes
                             WHERE case_id = ? AND (
                                LOWER(TRIM(COALESCE(nome,''))) = LOWER(TRIM(?))
                                OR LOWER(TRIM(COALESCE(razao_social,''))) = LOWER(TRIM(?))
                             ) LIMIT 1"
                        );
                        $stmtChkP->execute(array($newId, $nomeP, $nomeP));
                        $rowsP = $stmtChkP->fetchAll();
                        $stmtChkP->closeCursor();
                        if (!empty($rowsP)) continue;
                    } catch (Throwable $e) { continue; }

                    // Procura cliente existente pelo nome (case-insensitive, exato).
                    $clientPId  = null;
                    $clientPCpf = null;
                    try {
                        $stmtCli = $pdo->prepare("SELECT id, cpf, phone, email FROM clients WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1");
                        $stmtCli->execute(array($nomeP));
                        $rowsCli = $stmtCli->fetchAll();
                        $stmtCli->closeCursor();
                        if (!empty($rowsCli)) {
                            $clientPId  = (int)$rowsCli[0]['id'];
                            $clientPCpf = $rowsCli[0]['cpf'] ?: null;
                        }
                    } catch (Throwable $e) { /* ignora — segue sem link */ }

                    // INSERT — escolhe coluna conforme tipo_pessoa
                    try {
                        if (email_monitor_eh_pessoa_juridica($nomeP)) {
                            $pdo->prepare(
                                "INSERT INTO case_partes (case_id, papel, tipo_pessoa, razao_social, cnpj, client_id)
                                 VALUES (?, ?, 'juridica', ?, ?, ?)"
                            )->execute(array($newId, $polo['papel'], $nomeP, null, $clientPId));
                        } else {
                            $pdo->prepare(
                                "INSERT INTO case_partes (case_id, papel, tipo_pessoa, nome, cpf, client_id)
                                 VALUES (?, ?, 'fisica', ?, ?, ?)"
                            )->execute(array($newId, $polo['papel'], $nomeP, $clientPCpf, $clientPId));
                        }
                        $partesAuto++;
                    } catch (Throwable $e) { /* ignora — falha em 1 parte não derruba */ }
                }

                // ─── 3. Marca pendência como 'cadastrado' ────────────────
                // Independente do sucesso do IMAP — o caso EXISTE agora.
                $stmtUpdPend = $pdo->prepare("UPDATE email_monitor_pendentes SET status = 'cadastrado' WHERE case_number = ?");
                $stmtUpdPend->execute(array($case_number));
                $stmtUpdPend->closeCursor();
            }
        } catch (Throwable $e) {
            // Falha no import não deve impedir o cadastro do caso.
            // Log silencioso — usuário não precisa ver detalhes técnicos do IMAP.
        }
    }

    $msgSucesso = 'Processo cadastrado com sucesso!';
    if ($importadosPje > 0) {
        $msgSucesso .= ' ' . $importadosPje . ' andamento(s) importado(s) do email PJe.';
    }
    if ($partesAuto > 0) {
        $msgSucesso .= ' ' . $partesAuto . ' parte(s) cadastrada(s) automaticamente.';
    }
    flash_set('success', $msgSucesso);
    redirect(module_url('operacional', 'caso_ver.php?id=' . $newId));
}

// ─── GET: exibir formulario ─────────────────────────────────────────
$users = $pdo->query("SELECT id, name FROM users WHERE is_active = 1 ORDER BY name")->fetchAll();

// Pré-carregar cliente se vier via ?client_id=
$preClient = null;
$preFilhos = array();
if (isset($_GET['client_id']) && (int)$_GET['client_id'] > 0) {
    $stmtPre = $pdo->prepare("SELECT id, name, cpf, phone, children_names FROM clients WHERE id = ?");
    $stmtPre->execute(array((int)$_GET['client_id']));
    $preClient = $stmtPre->fetch();

    // Tentar puxar filhos do formulário de convivência/gastos
    if ($preClient) {
        try {
            $stmtFilhos = $pdo->prepare("SELECT payload_json FROM form_submissions WHERE linked_client_id = ? AND form_type IN ('convivencia','gastos_pensao','cadastro_cliente') ORDER BY created_at DESC LIMIT 1");
            $stmtFilhos->execute(array($preClient['id']));
            $formFilhos = $stmtFilhos->fetch();
            if ($formFilhos && $formFilhos['payload_json']) {
                $payload = json_decode($formFilhos['payload_json'], true);
                if (isset($payload['children']) && is_array($payload['children'])) {
                    foreach ($payload['children'] as $ch) {
                        if (isset($ch['name']) && $ch['name']) {
                            $preFilhos[] = array('nome' => $ch['name'], 'nascimento' => isset($ch['birth_date']) ? $ch['birth_date'] : '', 'cpf' => '');
                        }
                    }
                }
                if (empty($preFilhos) && isset($payload['nome_filho_referente']) && $payload['nome_filho_referente']) {
                    $preFilhos[] = array('nome' => $payload['nome_filho_referente'], 'nascimento' => '', 'cpf' => '');
                }
            }
            // Fallback: children_names do cadastro
            if (empty($preFilhos) && $preClient['children_names']) {
                $nomes = preg_split('/[,;eE]/', $preClient['children_names']);
                foreach ($nomes as $n) {
                    $n = trim($n);
                    if ($n) $preFilhos[] = array('nome' => $n, 'nascimento' => '', 'cpf' => '');
                }
            }
        } catch (Exception $e) {}
    }
}

// Pré-preenchimento via query string (usado pela página Email Monitor → aba Pendentes)
$preCaseNumber = trim($_GET['case_number'] ?? '');
$preTitle      = trim($_GET['title'] ?? '');
$preOrgao      = trim($_GET['orgao'] ?? '');

// Parse do órgão (texto completo do PJe) → vara + comarca + UF.
// Exemplos:
//   "1ª VARA DE FAMÍLIA DA COMARCA DE RESENDE"
//     → vara: "1ª VARA DE FAMÍLIA"          comarca: "RESENDE"
//   "VARA DE FAMÍLIA, DA INFÂNCIA, DA JUVENTUDE E DO IDOSO DA COMARCA DE BARRA DO PIRAÍ"
//     → vara: "VARA DE FAMÍLIA, DA INFÂNCIA, DA JUVENTUDE E DO IDOSO"   comarca: "BARRA DO PIRAÍ"
//   "4ª VARA DE FAMÍLIA DA REGIONAL DE CAMPO GRANDE"
//     → vara: "4ª VARA DE FAMÍLIA"          comarca: "CAMPO GRANDE"
//   "1ª VARA DE FAMÍLIA DA COMARCA DA CAPITAL"
//     → vara: "1ª VARA DE FAMÍLIA"          comarca: "CAPITAL"
//
// UF = sempre 'RJ' quando vem do Email Monitor (cron filtra emails só do TJRJ).
$preVaraOrgao    = '';
$preComarcaOrgao = '';
$preUfOrgao      = '';
if ($preOrgao !== '') {
    // Non-greedy primeiro grupo + regex em flag /u (UTF-8) — match com a ÚLTIMA
    // ocorrência de "DA COMARCA" ou "DA REGIONAL" pra suportar varas com várias
    // "DA"/"DE" no nome (ex: VARA DE FAMÍLIA, DA INFÂNCIA, DA JUVENTUDE...)
    if (preg_match('/^(.+?)\s+D[AO]\s+(?:COMARCA|REGIONAL)\s+(?:DE|DA|DAS|DO|DOS)\s+(.+)$/iu', $preOrgao, $matchOrgao)) {
        $preVaraOrgao    = trim($matchOrgao[1]);
        $preComarcaOrgao = trim($matchOrgao[2]);
    } else {
        // Sem padrão "DA COMARCA/REGIONAL DE X" — coloca o órgão completo na vara
        $preVaraOrgao = $preOrgao;
    }
    // Email Monitor só processa emails do TJRJ (IMAP_FROM_FILTER=tjrj.pjeadm-LD@tjrj.jus.br)
    // — então sempre que vier órgão por aqui, a UF é RJ.
    $preUfOrgao = 'RJ';
}

// Status para cadastro MANUAL de processo (não entra no Kanban Operacional)
// Para aparecer no Kanban, o processo deve vir pelo fluxo do Pipeline
$statusLabels = array(
    'em_andamento' => 'Processo em Andamento',
    'suspenso'     => 'Processo Suspenso',
    'arquivado'    => 'Processo Finalizado / Arquivado',
    'renunciamos'  => 'Renunciamos',
);

$departamentos = array(
    'operacional'    => 'Operacional',
    'administrativo' => 'Administrativo',
    'comercial'      => 'Comercial',
    'financeiro'     => 'Financeiro',
);

$sistemasTribunal = array(
    ''      => '— Selecionar —',
    'PJE'   => 'PJe (Processo Judicial Eletrônico)',
    'DCP'   => 'DCP (Distribuição e Controle Processual)',
    'ESAJ'  => 'e-SAJ',
    'EPROC' => 'EPROC',
    'PROJUDI' => 'PROJUDI',
    'TUCUJURIS' => 'TUCUJURIS',
    'SEI'   => 'SEI',
    'OUTRO' => 'Outro',
);

$pageTitle = 'Novo Processo';
require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.form-novo-caso { max-width:780px; margin:0 auto; }
.form-novo-caso .card { margin-bottom:1.25rem; }
.form-novo-caso .form-row { display:flex; gap:1rem; flex-wrap:wrap; margin-bottom:.85rem; }
.form-novo-caso .form-col { flex:1; min-width:220px; }
.form-novo-caso label { display:block; font-size:.78rem; font-weight:700; color:var(--petrol-900); margin-bottom:.3rem; text-transform:uppercase; letter-spacing:.3px; }
.form-novo-caso .form-input,
.form-novo-caso .form-select,
.form-novo-caso textarea { width:100%; }
.form-novo-caso textarea { min-height:80px; resize:vertical; }
.busca-cliente-wrap { position:relative; }
.busca-cliente-results { position:absolute; top:100%; left:0; right:0; background:#fff; border:1px solid var(--border); border-radius:0 0 var(--radius) var(--radius); max-height:220px; overflow-y:auto; z-index:50; display:none; box-shadow:0 4px 16px rgba(0,0,0,.12); }
.busca-cliente-results div:hover { background:rgba(215,171,144,.15); }
.cliente-selecionado { display:inline-flex; align-items:center; gap:.5rem; background:rgba(184,115,51,.1); border:1px solid #B87333; border-radius:8px; padding:.35rem .75rem; font-size:.82rem; font-weight:600; color:#B87333; margin-top:.35rem; }
.cliente-selecionado button { background:none; border:none; color:#dc2626; cursor:pointer; font-size:.9rem; padding:0 2px; }
</style>

<div class="form-novo-caso">
    <a href="<?= module_url('operacional') ?>" class="btn btn-outline btn-sm" style="margin-bottom:1rem;">&#8592; Voltar</a>

    <div class="card">
        <div class="card-header" style="background:linear-gradient(135deg, var(--petrol-900), var(--petrol-500)); color:#fff; border-radius:var(--radius-lg) var(--radius-lg) 0 0;">
            <h3 style="color:#fff;">Cadastrar Novo Processo</h3>
        </div>
        <div class="card-body">
            <form method="POST" action="<?= module_url('operacional', 'caso_novo.php') ?>" id="formNovoCaso">
                <?= csrf_input() ?>
                <?php
                $prePrincipalId = (int)($_GET['principal_id'] ?? 0);
                $preTipoRelacao = $_GET['tipo_relacao'] ?? '';
                $preTipoVinculo = $_GET['tipo_vinculo'] ?? '';
                $isRecursoCriacao = ($preTipoVinculo === 'recurso');
                $princData = null;
                $princPartes = array();
                if ($prePrincipalId):
                    $stmtPrinc = $pdo->prepare("SELECT * FROM cases WHERE id = ?");
                    $stmtPrinc->execute(array($prePrincipalId));
                    $princData = $stmtPrinc->fetch();
                    $princTitle = $princData ? $princData['title'] : '';
                    // Buscar partes do processo principal
                    try {
                        $stmtPartes = $pdo->prepare("SELECT papel, tipo_pessoa, nome, cpf, rg, nascimento, profissao, estado_civil, email, telefone, endereco, cidade, uf, cnpj, razao_social, client_id FROM case_partes WHERE case_id = ? ORDER BY id");
                        $stmtPartes->execute(array($prePrincipalId));
                        $princPartes = $stmtPartes->fetchAll();
                    } catch (Exception $e) {}
                ?>
                <input type="hidden" name="principal_id" value="<?= $prePrincipalId ?>">
                <input type="hidden" name="tipo_relacao" value="<?= e($preTipoRelacao) ?>">
                <input type="hidden" name="tipo_vinculo" value="<?= e($preTipoVinculo) ?>">
                <div style="background:linear-gradient(135deg,<?= $isRecursoCriacao ? '#b45309,#d97706' : '#6366f1,#4f46e5' ?>);color:#fff;border-radius:var(--radius);padding:.75rem 1rem;margin-bottom:1rem;font-size:.85rem;">
                    <?= $isRecursoCriacao ? '📜 Criando recurso de' : '📎 Criando processo incidental de' ?>: <strong><?= e($princTitle ?: "Processo #$prePrincipalId") ?></strong>
                    <?php if ($preTipoRelacao): ?> — <span style="background:rgba(255,255,255,.2);padding:1px 8px;border-radius:4px;font-size:.75rem;"><?= e($preTipoRelacao) ?></span><?php endif; ?>
                </div>
                <div style="background:#fefce8;border:1.5px solid #facc15;border-radius:8px;padding:.6rem .8rem;margin-bottom:1rem;font-size:.78rem;color:#854d0e;">
                    💡 Os dados do processo principal foram pré-preenchidos abaixo. Confira e altere o que for necessário (os campos em <span style="background:#fef08a;padding:0 4px;border-radius:3px;font-weight:700;">destaque</span> vieram do processo principal).
                </div>
                <?php endif; ?>

                <!-- Cliente -->
                <div class="form-row">
                    <div class="form-col" style="flex:2;">
                        <label>Cliente * (nosso cliente)</label>
                        <div class="busca-cliente-wrap">
                            <input type="text" id="buscaCliente" class="form-input" placeholder="Digite o nome do cliente..." autocomplete="off"<?= $preClient ? ' style="display:none;"' : '' ?>>
                            <div id="buscaResultados" class="busca-cliente-results"></div>
                        </div>
                        <input type="hidden" name="client_id" id="clientId" value="<?= $preClient ? $preClient['id'] : '' ?>">
                        <div id="clienteSelecionado">
                            <?php if ($preClient): ?>
                                <span class="cliente-selecionado"><?= e($preClient['name']) ?> <button type="button" onclick="limparCliente()">&times;</button></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="form-col" style="max-width:180px;">
                        <label>Papel do cliente</label>
                        <select name="cliente_papel" id="clientePapel" class="form-select" onchange="mudouPapelCliente()">
                            <?php if ($isRecursoCriacao): ?>
                            <option value="autor">Recorrente</option>
                            <option value="reu">Recorrido</option>
                            <?php else: ?>
                            <option value="autor">Autor</option>
                            <option value="reu">Réu</option>
                            <?php endif; ?>
                            <option value="terceiro_interessado">Terceiro Interessado</option>
                            <option value="representante_legal">Rep. Legal</option>
                        </select>
                    </div>
                    <div class="form-col" style="max-width:180px;" id="clienteRepBox" style="display:none;">
                        <label>Representa</label>
                        <select name="cliente_representa" id="clienteRepresenta" class="form-select">
                            <option value="autores"><?= $isRecursoCriacao ? 'Os Recorrentes' : 'Os Autores' ?></option>
                            <option value="reus"><?= $isRecursoCriacao ? 'Os Recorridos' : 'Os Réus' ?></option>
                        </select>
                    </div>
                </div>

                <!-- Partes do Processo -->
                <div style="background:#f8f9fa;border:1.5px solid var(--border);border-radius:10px;padding:.8rem 1rem;margin-bottom:1rem;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.5rem;">
                        <label style="font-weight:700;font-size:.85rem;">Partes do Processo</label>
                        <button type="button" onclick="addParteRow()" class="btn btn-outline btn-sm" style="font-size:.72rem;">+ Adicionar Parte</button>
                    </div>
                    <p style="font-size:.72rem;color:var(--text-muted);margin:0 0 .5rem;">O cliente selecionado acima será vinculado ao processo. Adicione as demais partes abaixo.</p>
                    <div id="partesRows">
                        <?php
                        // Se criando incidental/recurso, pré-preencher partes do processo principal
                        $partesPreFill = array();
                        if (!empty($princPartes)) {
                            // Filtrar partes que NÃO são o cliente (já está acima)
                            foreach ($princPartes as $pp) {
                                if ($pp['client_id'] && $preClient && (int)$pp['client_id'] === (int)$preClient['id']) continue;
                                $partesPreFill[] = $pp;
                            }
                        }
                        if (!empty($partesPreFill)):
                            foreach ($partesPreFill as $pp):
                                $ppDoc = $pp['tipo_pessoa'] === 'juridica' ? ($pp['cnpj'] ?: '') : ($pp['cpf'] ?: '');
                                $ppNome = $pp['tipo_pessoa'] === 'juridica' ? ($pp['razao_social'] ?: $pp['nome']) : $pp['nome'];
                        ?>
                        <?php $_tipoPP = $pp['tipo_pessoa']==='juridica' ? 'juridica' : 'fisica'; ?>
                        <div class="parte-row" style="display:grid;grid-template-columns:140px 1fr 1fr 28px;gap:.5rem;align-items:end;padding:.5rem .6rem;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;margin-bottom:.5rem;">
                            <div>
                                <label style="font-size:.68rem;color:var(--text-muted);font-weight:600;">Papel</label>
                                <select name="partes_papel[]" class="form-select" style="font-size:.82rem;">
                                    <?php if ($isRecursoCriacao): ?>
                                    <option value="reu" <?= $pp['papel']==='reu'?'selected':'' ?>>Recorrido</option>
                                    <option value="autor" <?= $pp['papel']==='autor'?'selected':'' ?>>Recorrente</option>
                                    <?php else: ?>
                                    <option value="reu" <?= $pp['papel']==='reu'?'selected':'' ?>>Réu</option>
                                    <option value="autor" <?= $pp['papel']==='autor'?'selected':'' ?>>Autor</option>
                                    <?php endif; ?>
                                    <option value="representante_legal" <?= $pp['papel']==='representante_legal'?'selected':'' ?>>Rep. Legal</option>
                                    <option value="terceiro_interessado" <?= $pp['papel']==='terceiro_interessado'?'selected':'' ?>>3º Interessado</option>
                                    <option value="litisconsorte_ativo" <?= $pp['papel']==='litisconsorte_ativo'?'selected':'' ?>>Litis. Ativo</option>
                                    <option value="litisconsorte_passivo" <?= $pp['papel']==='litisconsorte_passivo'?'selected':'' ?>>Litis. Passivo</option>
                                </select>
                            </div>
                            <div>
                                <label style="font-size:.68rem;color:var(--text-muted);font-weight:600;display:flex;align-items:center;gap:.4rem;">
                                    CPF/CNPJ
                                    <span class="parte-tipo-badge" data-tipo="<?= $_tipoPP ?>" style="padding:1px 7px;border-radius:8px;font-size:.6rem;font-weight:700;<?= $_tipoPP==='juridica' ? 'background:#dbeafe;color:#1e40af;' : 'background:#dcfce7;color:#166534;' ?>"><?= $_tipoPP==='juridica' ? 'PJ' : 'PF' ?></span>
                                </label>
                                <input type="hidden" name="partes_tipo[]" value="<?= $_tipoPP ?>">
                                <input type="text" name="partes_doc[]" class="form-input" style="font-size:.82rem;" value="<?= e($ppDoc) ?>" maxlength="18" data-busca-doc placeholder="Digite CPF ou CNPJ">
                            </div>
                            <div>
                                <label style="font-size:.68rem;color:var(--text-muted);font-weight:600;">Nome / Razão Social</label>
                                <input type="text" name="partes_nome[]" class="form-input" style="font-size:.82rem;" value="<?= e($ppNome) ?>">
                            </div>
                            <button type="button" onclick="this.closest('.parte-row').remove()" style="background:none;border:none;color:#dc2626;cursor:pointer;font-size:1rem;padding:4px;" title="Remover">&#10005;</button>
                        </div>
                        <?php endforeach; ?>
                        <?php else: ?>
                        <!-- Parte adversa padrão (em branco) -->
                        <div class="parte-row" style="display:grid;grid-template-columns:140px 1fr 1fr auto 28px;gap:.5rem;align-items:end;padding:.5rem .6rem;background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;margin-bottom:.5rem;">
                            <div>
                                <label style="font-size:.68rem;color:var(--text-muted);font-weight:600;">Papel</label>
                                <select name="partes_papel[]" class="form-select" style="font-size:.82rem;">
                                    <?php if ($isRecursoCriacao): ?>
                                    <option value="reu" selected>Recorrido</option>
                                    <option value="autor">Recorrente</option>
                                    <?php else: ?>
                                    <option value="reu" selected>Réu</option>
                                    <option value="autor">Autor</option>
                                    <?php endif; ?>
                                    <option value="representante_legal">Rep. Legal</option>
                                    <option value="terceiro_interessado">3º Interessado</option>
                                    <option value="litisconsorte_ativo">Litis. Ativo</option>
                                    <option value="litisconsorte_passivo">Litis. Passivo</option>
                                </select>
                            </div>
                            <div>
                                <label style="font-size:.68rem;color:var(--text-muted);font-weight:600;display:flex;align-items:center;gap:.4rem;">
                                    CPF/CNPJ
                                    <span class="parte-tipo-badge" data-tipo="fisica" style="padding:1px 7px;border-radius:8px;font-size:.6rem;font-weight:700;background:#f1f5f9;color:#64748b;">—</span>
                                </label>
                                <input type="hidden" name="partes_tipo[]" value="fisica">
                                <input type="text" name="partes_doc[]" class="form-input" style="font-size:.82rem;" placeholder="Digite CPF ou CNPJ" maxlength="18" data-busca-doc>
                            </div>
                            <div>
                                <label style="font-size:.68rem;color:var(--text-muted);font-weight:600;">Nome / Razão Social</label>
                                <input type="text" name="partes_nome[]" class="form-input" style="font-size:.82rem;" placeholder="Nome da parte">
                            </div>
                            <div style="display:flex;align-items:center;gap:4px;">
                                <input type="hidden" name="partes_client_id[]" value="0" class="parte-client-id">
                                <label style="font-size:.62rem;color:#B87333;cursor:pointer;display:flex;align-items:center;gap:2px;white-space:nowrap;" title="Marcar como nosso cliente">
                                    <input type="checkbox" class="parte-eh-cliente" style="width:14px;height:14px;" onchange="toggleParteCliente(this)">
                                    <span class="parte-cliente-label">Cliente</span>
                                </label>
                            </div>
                            <button type="button" onclick="this.closest('.parte-row').remove()" style="background:none;border:none;color:#dc2626;cursor:pointer;font-size:1rem;padding:4px;" title="Remover">&#10005;</button>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <!-- Campos legados (hidden, para backward compatibility) -->
                <input type="hidden" name="parte_re_nome" id="parteReNomeHidden" value="">
                <input type="hidden" name="parte_re_cpf_cnpj" id="parteReCpfHidden" value="">

                <!-- Filhos (aparece para alimentos) -->
                <div id="secaoFilhos" style="display:none;margin-bottom:.85rem;padding:1rem;background:rgba(184,115,51,.06);border:1.5px solid rgba(184,115,51,.2);border-radius:10px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.6rem;">
                        <label style="margin:0;color:#B87333;">Filho(s) — Requerente(s)</label>
                        <button type="button" onclick="addFilho()" class="btn btn-outline btn-sm" style="font-size:.72rem;color:#B87333;border-color:#B87333;">+ Adicionar filho</button>
                    </div>
                    <div id="listaFilhos">
                        <?php if (!empty($preFilhos)): ?>
                            <?php foreach ($preFilhos as $fi => $f): ?>
                            <div class="form-row filho-row" style="margin-bottom:.5rem;align-items:flex-end;">
                                <div class="form-col"><label style="font-size:.7rem;">Nome completo</label><input type="text" name="filho_nome[]" class="form-input" value="<?= e($f['nome']) ?>" placeholder="Nome do(a) filho(a)"></div>
                                <div class="form-col" style="max-width:160px;"><label style="font-size:.7rem;">Nascimento</label><input type="date" name="filho_nascimento[]" class="form-input" value="<?= e($f['nascimento']) ?>"></div>
                                <div class="form-col" style="max-width:160px;"><label style="font-size:.7rem;">CPF</label><input type="text" name="filho_cpf[]" class="form-input" value="<?= e($f['cpf']) ?>" placeholder="000.000.000-00"></div>
                                <?php if ($fi > 0): ?><button type="button" onclick="this.parentElement.remove();" style="background:none;border:none;color:#dc2626;cursor:pointer;font-size:1rem;padding:0 4px;margin-bottom:6px;">✕</button><?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="form-row filho-row" style="margin-bottom:.5rem;align-items:flex-end;">
                                <div class="form-col"><label style="font-size:.7rem;">Nome completo</label><input type="text" name="filho_nome[]" class="form-input" placeholder="Nome do(a) filho(a)"></div>
                                <div class="form-col" style="max-width:160px;"><label style="font-size:.7rem;">Nascimento</label><input type="date" name="filho_nascimento[]" class="form-input"></div>
                                <div class="form-col" style="max-width:160px;"><label style="font-size:.7rem;">CPF</label><input type="text" name="filho_cpf[]" class="form-input" placeholder="000.000.000-00"></div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Titulo -->
                <div class="form-row">
                    <div class="form-col">
                        <label>Nome da Pasta / Título *</label>
                        <input type="text" name="title" id="tituloProcesso" class="form-input" required placeholder="Ex: Maria Silva x João Santos" value="<?= e($preTitle) ?>">
                    </div>
                </div>

                <!-- Tipo de acao + Categoria -->
                <div class="form-row">
                    <div class="form-col">
                        <label>Tipo de Ação</label>
                        <select name="case_type" class="form-select" id="selCaseType" onchange="document.getElementById('caseTypeOutro').style.display=this.value==='outro'?'block':'none'">
                            <option value="">Selecione...</option>
                            <optgroup label="Família">
                                <option value="Alimentos">Alimentos</option>
                                <option value="Revisional de Alimentos">Revisional de Alimentos</option>
                                <option value="Execução de Alimentos">Execução de Alimentos</option>
                                <option value="Execução de Alimentos - Rito Prisão">Execução de Alimentos - Rito Prisão</option>
                                <option value="Execução de Alimentos - Rito Penhora">Execução de Alimentos - Rito Penhora</option>
                                <option value="Exoneração de Alimentos">Exoneração de Alimentos</option>
                                <option value="Oferta de Alimentos">Oferta de Alimentos</option>
                                <option value="Divórcio Consensual">Divórcio Consensual</option>
                                <option value="Divórcio Litigioso">Divórcio Litigioso</option>
                                <option value="Dissolução de União Estável">Dissolução de União Estável</option>
                                <option value="Reconhecimento de União Estável">Reconhecimento de União Estável</option>
                                <option value="Guarda">Guarda</option>
                                <option value="Guarda Compartilhada">Guarda Compartilhada</option>
                                <option value="Regulamentação de Convivência">Regulamentação de Convivência</option>
                                <option value="Modificação de Guarda">Modificação de Guarda</option>
                                <option value="Busca e Apreensão de Menor">Busca e Apreensão de Menor</option>
                                <option value="Investigação de Paternidade">Investigação de Paternidade</option>
                                <option value="Negatória de Paternidade">Negatória de Paternidade</option>
                                <option value="Adoção">Adoção</option>
                                <option value="Tutela / Curatela">Tutela / Curatela</option>
                                <option value="Interdição">Interdição</option>
                                <option value="Alienação Parental">Alienação Parental</option>
                            </optgroup>
                            <optgroup label="Sucessões">
                                <option value="Inventário Judicial">Inventário Judicial</option>
                                <option value="Inventário Extrajudicial">Inventário Extrajudicial</option>
                                <option value="Alvará Judicial">Alvará Judicial</option>
                                <option value="Arrolamento de Bens">Arrolamento de Bens</option>
                                <option value="Testamento">Testamento</option>
                            </optgroup>
                            <optgroup label="Cível">
                                <option value="Consumidor">Consumidor</option>
                                <option value="Indenização / Danos Morais">Indenização / Danos Morais</option>
                                <option value="Indenização / Danos Materiais">Indenização / Danos Materiais</option>
                                <option value="Obrigação de Fazer">Obrigação de Fazer</option>
                                <option value="Cobrança">Cobrança</option>
                                <option value="Execução de Título Extrajudicial">Execução de Título Extrajudicial</option>
                                <option value="Cumprimento de Sentença">Cumprimento de Sentença</option>
                                <option value="Revisão Contratual">Revisão Contratual</option>
                                <option value="Fraude Bancária">Fraude Bancária</option>
                                <option value="Negativação Indevida">Negativação Indevida</option>
                            </optgroup>
                            <optgroup label="Imobiliário">
                                <option value="Usucapião">Usucapião</option>
                                <option value="Despejo">Despejo</option>
                                <option value="Reintegração de Posse">Reintegração de Posse</option>
                                <option value="Imissão na Posse">Imissão na Posse</option>
                                <option value="Adjudicação Compulsória">Adjudicação Compulsória</option>
                            </optgroup>
                            <optgroup label="Trabalhista">
                                <option value="Reclamação Trabalhista">Reclamação Trabalhista</option>
                                <option value="Execução Trabalhista">Execução Trabalhista</option>
                            </optgroup>
                            <optgroup label="Previdenciário">
                                <option value="Aposentadoria">Aposentadoria</option>
                                <option value="Auxílio-Doença / Incapacidade">Auxílio-Doença / Incapacidade</option>
                                <option value="BPC / LOAS">BPC / LOAS</option>
                                <option value="Pensao por Morte">Pensão por Morte</option>
                                <option value="Revisao de Beneficio">Revisão de Benefício</option>
                            </optgroup>
                            <optgroup label="Outros">
                                <option value="Medida Protetiva">Medida Protetiva</option>
                                <option value="Habeas Corpus">Habeas Corpus</option>
                                <option value="Mandado de Seguranca">Mandado de Segurança</option>
                                <option value="outro">Outro (especificar)</option>
                            </optgroup>
                        </select>
                        <input type="text" name="case_type_outro" id="caseTypeOutro" class="form-input" placeholder="Especifique o tipo..." style="display:none;margin-top:4px;">
                    </div>
                    <div class="form-col" style="max-width:220px;">
                        <label>Categoria</label>
                        <select name="category" class="form-select">
                            <option value="judicial">Judicial</option>
                            <option value="extrajudicial">Extrajudicial</option>
                        </select>
                    </div>
                </div>

                <!-- Numero do Processo + Vara -->
                <div class="form-row">
                    <div class="form-col">
                        <label>N. do Processo</label>
                        <input type="text" name="case_number" class="form-input" placeholder="0000000-00.0000.0.00.0000" value="<?= e($preCaseNumber) ?>">
                    </div>
                    <div class="form-col">
                        <label>Vara</label>
                        <?php $preVara = ($princData && $princData['court']) ? $princData['court'] : $preVaraOrgao; ?>
                        <input type="text" name="court" class="form-input" placeholder="Ex: 1a Vara de Familia" value="<?= e($preVara) ?>"<?= $preVara ? ' style="background:#fef9c3;"' : '' ?>>
                    </div>
                </div>

                <!-- UF + Comarca (cidade) + Data de Distribuição -->
                <div class="form-row">
                    <?php $preUf = ($princData && $princData['comarca_uf']) ? $princData['comarca_uf'] : $preUfOrgao; ?>
                    <div class="form-col" style="max-width:120px;">
                        <label>Estado (UF)</label>
                        <select name="comarca_uf" id="comarcaUf" class="form-select" onchange="filtrarCidades()"<?= $preUf ? ' style="background:#fef9c3;"' : '' ?>>
                            <option value="">UF</option>
                            <?php
                            $ufs = array('AC','AL','AM','AP','BA','CE','DF','ES','GO','MA','MG','MS','MT','PA','PB','PE','PI','PR','RJ','RN','RO','RR','RS','SC','SE','SP','TO');
                            foreach ($ufs as $uf): ?>
                                <option value="<?= $uf ?>" <?= ($preUf === $uf) ? 'selected' : '' ?>><?= $uf ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php $preComarca = ($princData && $princData['comarca']) ? $princData['comarca'] : $preComarcaOrgao; ?>
                    <div class="form-col">
                        <label>Comarca (Cidade)</label>
                        <input type="text" name="comarca" id="comarcaCidade" class="form-input" placeholder="Digite a cidade..." autocomplete="off" list="listaCidades" value="<?= e($preComarca) ?>"<?= $preComarca ? ' style="background:#fef9c3;"' : '' ?>>
                        <datalist id="listaCidades"></datalist>
                    </div>
                    <div class="form-col" style="max-width:200px;">
                        <label>Data de Distribuição</label>
                        <input type="date" name="distribution_date" class="form-input">
                    </div>
                </div>

                <!-- Regional (ex: Madureira, Campo Grande, Bangu) -->
                <?php $preRegional = ($princData && $princData['regional']) ? $princData['regional'] : ''; ?>
                <div class="form-row">
                    <div class="form-col" style="max-width:180px;">
                        <label>Tem Regional?</label>
                        <select id="temRegional" class="form-select" onchange="document.getElementById('campoRegional').style.display=this.value==='sim'?'block':'none';">
                            <option value="nao" <?= !$preRegional ? 'selected' : '' ?>>Não</option>
                            <option value="sim" <?= $preRegional ? 'selected' : '' ?>>Sim</option>
                        </select>
                    </div>
                    <div class="form-col" id="campoRegional" style="<?= $preRegional ? '' : 'display:none;' ?>">
                        <label>Qual Regional?</label>
                        <input type="text" name="regional" class="form-input" placeholder="Ex: Madureira, Campo Grande, Bangu..." value="<?= e($preRegional) ?>"<?= $preRegional ? ' style="background:#fef9c3;"' : '' ?>>
                    </div>
                </div>

                <!-- Sistema + Segredo de Justiça -->
                <?php
                    $preSistema = ($princData && $princData['sistema_tribunal']) ? $princData['sistema_tribunal'] : '';
                    $preSegredo = ($princData && $princData['segredo_justica']) ? (int)$princData['segredo_justica'] : 0;
                ?>
                <div class="form-row">
                    <div class="form-col">
                        <label>Sistema do Tribunal</label>
                        <select name="sistema_tribunal" class="form-select"<?= $preSistema ? ' style="background:#fef9c3;"' : '' ?>>
                            <?php foreach ($sistemasTribunal as $k => $v): ?>
                                <option value="<?= $k ?>" <?= ($preSistema === $k) ? 'selected' : '' ?>><?= $v ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-col" style="max-width:200px;">
                        <label>Segredo de Justiça</label>
                        <div style="display:flex;align-items:center;gap:.5rem;height:42px;">
                            <input type="checkbox" name="segredo_justica" id="segredoJustica" value="1" style="width:18px;height:18px;cursor:pointer;" <?= $preSegredo ? 'checked' : '' ?>>
                            <label for="segredoJustica" style="font-size:.85rem;font-weight:400;text-transform:none;letter-spacing:0;cursor:pointer;margin:0;">Sim, é segredo</label>
                        </div>
                    </div>
                </div>

                <!-- Status + Prioridade -->
                <div class="form-row">
                    <div class="form-col">
                        <label>Status</label>
                        <select name="status" class="form-select">
                            <?php foreach ($statusLabels as $k => $v): ?>
                                <option value="<?= $k ?>" <?= $k === 'em_andamento' ? 'selected' : '' ?>><?= $v ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-col" style="max-width:200px;">
                        <label>Prioridade</label>
                        <select name="priority" class="form-select">
                            <option value="baixa">Baixa</option>
                            <option value="normal" selected>Normal</option>
                            <option value="alta">Alta</option>
                            <option value="urgente">Urgente</option>
                        </select>
                    </div>
                </div>

                <!-- Departamento + Responsavel -->
                <div class="form-row">
                    <div class="form-col" style="max-width:220px;">
                        <label>Departamento</label>
                        <select name="departamento" class="form-select">
                            <?php foreach ($departamentos as $k => $v): ?>
                                <option value="<?= $k ?>" <?= $k === 'operacional' ? 'selected' : '' ?>><?= $v ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-col" style="max-width:320px;">
                        <label>Responsável</label>
                        <select name="responsible_user_id" class="form-select">
                            <option value="">-- Selecionar --</option>
                            <?php foreach ($users as $u): ?>
                                <option value="<?= $u['id'] ?>"><?= e($u['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Link Drive -->
                <div class="form-row">
                    <div class="form-col">
                        <label>Link Pasta Google Drive (opcional)</label>
                        <input type="url" name="drive_folder_url" class="form-input" placeholder="https://drive.google.com/drive/folders/...">
                    </div>
                </div>

                <!-- Observações -->
                <div class="form-row">
                    <div class="form-col">
                        <label>Observações</label>
                        <textarea name="notes" class="form-input" placeholder="Informações adicionais sobre o caso..."></textarea>
                    </div>
                </div>

                <!-- Migração do sistema antigo -->
                <div style="margin-top:1rem;padding:.75rem 1rem;background:#fef3c7;border-left:4px solid #d97706;border-radius:6px;">
                    <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-size:.85rem;color:#78350f;font-weight:600;">
                        <input type="checkbox" name="migracao_antigo" value="1" style="width:18px;height:18px;cursor:pointer;">
                        <span>🗂️ Migração do sistema antigo — não criar card em nenhum Kanban</span>
                    </label>
                    <p style="margin:.4rem 0 0 26px;font-size:.72rem;color:#92400e;line-height:1.4;">
                        Marque se este processo já existe no sistema antigo e está só sendo cadastrado aqui pra histórico. A pasta é criada normalmente (você acessa por Processos/Clientes), mas NÃO aparece nos Kanbans Operacional/PREV.
                    </p>
                </div>

                <!-- Submit -->
                <div style="display:flex;gap:.75rem;justify-content:flex-end;margin-top:1rem;padding-top:1rem;border-top:1px solid var(--border);">
                    <a href="<?= module_url('operacional') ?>" class="btn btn-outline">Cancelar</a>
                    <button type="submit" class="btn btn-primary" style="background:#B87333;min-width:180px;">Cadastrar Processo</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function() {
    var input = document.getElementById('buscaCliente');
    var results = document.getElementById('buscaResultados');
    var hiddenId = document.getElementById('clientId');
    var selDiv = document.getElementById('clienteSelecionado');
    var timer = null;

    input.addEventListener('input', function() {
        clearTimeout(timer);
        var q = this.value.trim();
        if (q.length < 2) { results.style.display = 'none'; return; }
        timer = setTimeout(function() {
            var xhr = new XMLHttpRequest();
            xhr.open('GET', '<?= module_url("operacional", "caso_novo.php") ?>?ajax_busca_cliente=1&q=' + encodeURIComponent(q));
            xhr.onload = function() {
                try {
                    var clientes = JSON.parse(xhr.responseText);
                    if (!clientes.length) {
                        results.innerHTML = '<div style="padding:8px 12px;font-size:.82rem;color:var(--text-muted);">Nenhum encontrado</div>';
                        results.style.display = 'block';
                        return;
                    }
                    var html = '';
                    for (var i = 0; i < clientes.length; i++) {
                        var cl = clientes[i];
                        html += '<div data-id="' + cl.id + '" data-name="' + (cl.name || '').replace(/"/g, '&quot;') + '" style="padding:8px 12px;cursor:pointer;border-bottom:1px solid #eee;font-size:.82rem;">';
                        html += '<strong>' + (cl.name || '') + '</strong>';
                        if (cl.cpf) html += ' <span style="color:var(--text-muted);">— CPF: ' + cl.cpf + '</span>';
                        if (cl.phone) html += ' <span style="color:var(--text-muted);">— ' + cl.phone + '</span>';
                        html += '</div>';
                    }
                    results.innerHTML = html;
                    results.style.display = 'block';

                    // Bind click
                    var items = results.querySelectorAll('div[data-id]');
                    for (var j = 0; j < items.length; j++) {
                        items[j].addEventListener('click', function() {
                            selecionarCliente(this.getAttribute('data-id'), this.getAttribute('data-name'));
                        });
                    }
                } catch(e) { results.style.display = 'none'; }
            };
            xhr.send();
        }, 300);
    });

    // Fechar dropdown ao clicar fora
    document.addEventListener('click', function(ev) {
        if (!input.contains(ev.target) && !results.contains(ev.target)) {
            results.style.display = 'none';
        }
    });

    function selecionarCliente(id, name) {
        hiddenId.value = id;
        input.value = '';
        input.style.display = 'none';
        results.style.display = 'none';
        selDiv.innerHTML = '<span class="cliente-selecionado">' + name + ' <button type="button" onclick="limparCliente()">&times;</button></span>';
        // Pré-preencher título da pasta: "Primeiro Último x "
        var titulo = document.getElementById('tituloProcesso');
        if (titulo && !titulo.value) {
            var partes = name.trim().split(/\s+/);
            var primeiro = partes[0] || '';
            var ultimo = partes.length > 1 ? partes[partes.length - 1] : '';
            titulo.value = primeiro + ' ' + ultimo + ' x ';
            titulo.focus();
            // Colocar cursor no final
            titulo.setSelectionRange(titulo.value.length, titulo.value.length);
        }
    }

    window.limparCliente = function() {
        hiddenId.value = '';
        input.value = '';
        input.style.display = '';
        selDiv.innerHTML = '';
        input.focus();
    };

    // Validacao antes de enviar
    document.getElementById('formNovoCaso').addEventListener('submit', function(ev) {
        if (!hiddenId.value || hiddenId.value === '0') {
            ev.preventDefault();
            alert('Selecione um cliente antes de cadastrar.');
            input.focus();
        }
    });
})();

// ── Filhos (para processos de alimentos) ──
function addFilho() {
    var html = '<div class="form-row filho-row" style="margin-bottom:.5rem;align-items:flex-end;">';
    html += '<div class="form-col"><label style="font-size:.7rem;">Nome completo</label><input type="text" name="filho_nome[]" class="form-input" placeholder="Nome do(a) filho(a)"></div>';
    html += '<div class="form-col" style="max-width:160px;"><label style="font-size:.7rem;">Nascimento</label><input type="date" name="filho_nascimento[]" class="form-input"></div>';
    html += '<div class="form-col" style="max-width:160px;"><label style="font-size:.7rem;">CPF</label><input type="text" name="filho_cpf[]" class="form-input" placeholder="000.000.000-00"></div>';
    html += '<button type="button" onclick="this.parentElement.remove();" style="background:none;border:none;color:#dc2626;cursor:pointer;font-size:1rem;padding:0 4px;margin-bottom:6px;">✕</button>';
    html += '</div>';
    document.getElementById('listaFilhos').insertAdjacentHTML('beforeend', html);
}

// Mostrar seção filhos quando tipo contém "alimentos"
var caseTypeInput = document.querySelector('input[name="case_type"]');
if (caseTypeInput) {
    function checkAlimentos() {
        var val = caseTypeInput.value.toLowerCase();
        var secao = document.getElementById('secaoFilhos');
        if (secao) {
            secao.style.display = (val.indexOf('alimento') !== -1 || val.indexOf('pensão') !== -1 || val.indexOf('pensao') !== -1 || val.indexOf('guarda') !== -1) ? 'block' : 'none';
        }
    }
    caseTypeInput.addEventListener('input', checkAlimentos);
    caseTypeInput.addEventListener('change', checkAlimentos);
    checkAlimentos(); // Verificar estado inicial
}

// ── Máscara CPF/CNPJ + Busca automática ──
function formatarCpfCnpj(el) {
    var v = el.value.replace(/\D/g, '');
    if (v.length <= 11) {
        // CPF: 000.000.000-00
        v = v.replace(/(\d{3})(\d)/, '$1.$2');
        v = v.replace(/(\d{3})(\d)/, '$1.$2');
        v = v.replace(/(\d{3})(\d{1,2})$/, '$1-$2');
    } else {
        // CNPJ: 00.000.000/0000-00
        v = v.replace(/^(\d{2})(\d)/, '$1.$2');
        v = v.replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3');
        v = v.replace(/\.(\d{3})(\d)/, '.$1/$2');
        v = v.replace(/(\d{4})(\d{1,2})$/, '$1-$2');
    }
    el.value = v;
}

function mudouPapelCliente() {
    var p = document.getElementById('clientePapel').value;
    document.getElementById('clienteRepBox').style.display = (p === 'representante_legal') ? '' : 'none';
}
mudouPapelCliente();

var _isRecurso = <?= $isRecursoCriacao ? 'true' : 'false' ?>;
function addParteRow() {
    var labelReu = _isRecurso ? 'Recorrido' : 'Réu';
    var labelAutor = _isRecurso ? 'Recorrente' : 'Autor';
    var html = '<div class="parte-row" style="display:grid;grid-template-columns:140px 1fr 1fr auto 28px;gap:.5rem;align-items:end;padding:.5rem .6rem;background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;margin-bottom:.5rem;">'
        + '<div><label style="font-size:.68rem;color:var(--text-muted);font-weight:600;">Papel</label>'
        + '<select name="partes_papel[]" class="form-select" style="font-size:.82rem;">'
        + '<option value="reu">' + labelReu + '</option><option value="autor">' + labelAutor + '</option>'
        + '<option value="representante_legal">Rep. Legal</option><option value="terceiro_interessado">3º Interessado</option>'
        + '<option value="litisconsorte_ativo">Litis. Ativo</option><option value="litisconsorte_passivo">Litis. Passivo</option></select></div>'
        + '<div><label style="font-size:.68rem;color:var(--text-muted);font-weight:600;display:flex;align-items:center;gap:.4rem;">CPF/CNPJ '
        + '<span class="parte-tipo-badge" data-tipo="fisica" style="padding:1px 7px;border-radius:8px;font-size:.6rem;font-weight:700;background:#f1f5f9;color:#64748b;">—</span></label>'
        + '<input type="hidden" name="partes_tipo[]" value="fisica">'
        + '<input type="text" name="partes_doc[]" class="form-input" style="font-size:.82rem;" placeholder="Digite CPF ou CNPJ" maxlength="18" data-busca-doc></div>'
        + '<div><label style="font-size:.68rem;color:var(--text-muted);font-weight:600;">Nome / Razão Social</label>'
        + '<input type="text" name="partes_nome[]" class="form-input" style="font-size:.82rem;" placeholder="Nome da parte"></div>'
        + '<div style="display:flex;align-items:center;gap:4px;"><input type="hidden" name="partes_client_id[]" value="0" class="parte-client-id">'
        + '<label style="font-size:.62rem;color:#B87333;cursor:pointer;display:flex;align-items:center;gap:2px;white-space:nowrap;" title="Marcar como nosso cliente">'
        + '<input type="checkbox" class="parte-eh-cliente" style="width:14px;height:14px;" onchange="toggleParteCliente(this)"><span class="parte-cliente-label">Cliente</span></label></div>'
        + '<button type="button" onclick="this.closest(\'.parte-row\').remove()" style="background:none;border:none;color:#dc2626;cursor:pointer;font-size:1rem;padding:4px;" title="Remover">&#10005;</button></div>';
    document.getElementById('partesRows').insertAdjacentHTML('beforeend', html);
}

// Auto-detect PF/PJ + máscara + busca de nome (delegated, pega linhas estáticas e dinâmicas)
(function() {
    var container = document.getElementById('partesRows');
    if (!container) return;

    container.addEventListener('input', function(ev) {
        var el = ev.target;
        if (!el.matches || !el.matches('input[data-busca-doc]')) return;

        // Conta dígitos e decide tipo ANTES de aplicar a máscara
        var digitos = el.value.replace(/\D/g, '');
        var row = el.closest('.parte-row');
        if (row) {
            var hiddenTipo = row.querySelector('input[type="hidden"][name="partes_tipo[]"]');
            var badge = row.querySelector('.parte-tipo-badge');
            var novoTipo = null;
            if (digitos.length >= 12) {
                novoTipo = 'juridica';
            } else if (digitos.length >= 1) {
                novoTipo = 'fisica';
            }
            if (novoTipo) {
                if (hiddenTipo) hiddenTipo.value = novoTipo;
                if (badge) {
                    badge.setAttribute('data-tipo', novoTipo);
                    if (novoTipo === 'juridica') {
                        badge.textContent = 'PJ';
                        badge.style.cssText = 'padding:1px 7px;border-radius:8px;font-size:.6rem;font-weight:700;background:#dbeafe;color:#1e40af;';
                    } else {
                        badge.textContent = 'PF';
                        badge.style.cssText = 'padding:1px 7px;border-radius:8px;font-size:.6rem;font-weight:700;background:#dcfce7;color:#166534;';
                    }
                }
            } else if (badge) {
                // Campo vazio — volta pro estado neutro
                badge.textContent = '—';
                badge.style.cssText = 'padding:1px 7px;border-radius:8px;font-size:.6rem;font-weight:700;background:#f1f5f9;color:#64748b;';
            }
        }

        // Aplica máscara (CPF ou CNPJ conforme tamanho)
        formatarCpfCnpj(el);
    });

    container.addEventListener('blur', function(ev) {
        var el = ev.target;
        if (!el.matches || !el.matches('input[data-busca-doc]')) return;
        buscarDocParte(el);
    }, true);
})();

// Toggle marcar parte como cliente — busca na base e vincula
function toggleParteCliente(checkbox) {
    var row = checkbox.closest('.parte-row');
    var hiddenId = row.querySelector('.parte-client-id');
    var label = row.querySelector('.parte-cliente-label');
    var inputNome = row.querySelector('input[name="partes_nome[]"]');
    var inputDoc = row.querySelector('input[name="partes_doc[]"]');

    if (!checkbox.checked) {
        hiddenId.value = '0';
        label.textContent = 'Cliente';
        label.style.color = '';
        return;
    }

    var nome = inputNome ? inputNome.value.trim() : '';
    var doc = inputDoc ? inputDoc.value.replace(/\D/g, '') : '';

    if (!nome && !doc) {
        alert('Preencha o nome ou CPF da parte primeiro.');
        checkbox.checked = false;
        return;
    }

    label.textContent = 'Buscando...';
    var url = '<?= module_url('shared', 'partes_api.php') ?>?action=buscar_nome_parte&q=' + encodeURIComponent(nome || doc);

    fetch(url)
        .then(function(r) { return r.json(); })
        .then(function(results) {
            // Filtrar só os que vieram de clients (têm client_id)
            var clientes = results.filter(function(r) { return r.client_id && r.fonte === 'cliente'; });

            if (clientes.length === 0) {
                // Oferecer cadastro rápido
                if (!confirm('Cliente "' + nome + '" não foi encontrado na base.\n\nDeseja CADASTRAR agora e vincular à parte?\n\n(Nome e CPF serão usados. Complete os demais dados depois no CRM.)')) {
                    checkbox.checked = false;
                    label.textContent = 'Cliente';
                    return;
                }
                label.textContent = 'Cadastrando...';
                var fd = new FormData();
                fd.append('action', 'criar_cliente_rapido');
                fd.append('nome', nome);
                fd.append('cpf', doc);
                fd.append('csrf_token', '<?= generate_csrf_token() ?>');
                fetch('<?= module_url('shared', 'partes_api.php') ?>', { method: 'POST', body: fd })
                    .then(function(r) { return r.json(); })
                    .then(function(res) {
                        if (res.error) {
                            alert('Erro: ' + res.error);
                            checkbox.checked = false;
                            label.textContent = 'Cliente';
                            return;
                        }
                        hiddenId.value = res.client_id;
                        label.textContent = (res.ja_existia ? '✓ ' : '✓ Novo: ') + res.nome.split(' ').slice(0, 2).join(' ');
                        label.style.color = '#059669';
                    })
                    .catch(function() {
                        alert('Erro ao cadastrar cliente.');
                        checkbox.checked = false;
                        label.textContent = 'Cliente';
                    });
                return;
            }

            if (clientes.length === 1) {
                hiddenId.value = clientes[0].client_id;
                label.textContent = '✓ ' + (clientes[0].nome || nome).split(' ').slice(0, 2).join(' ');
                label.style.color = '#059669';
                return;
            }

            // Múltiplos: pedir para escolher
            var opcoes = clientes.map(function(c, i) { return (i + 1) + '. ' + c.nome + ' (' + (c.cpf || 'sem CPF') + ')'; }).join('\n');
            var escolha = prompt('Vários clientes encontrados. Digite o número:\n\n' + opcoes);
            var idx = parseInt(escolha) - 1;
            if (isNaN(idx) || idx < 0 || idx >= clientes.length) {
                checkbox.checked = false;
                label.textContent = 'Cliente';
                return;
            }
            hiddenId.value = clientes[idx].client_id;
            label.textContent = '✓ ' + clientes[idx].nome.split(' ').slice(0, 2).join(' ');
            label.style.color = '#059669';
        })
        .catch(function() {
            alert('Erro ao buscar cliente. Tente novamente.');
            checkbox.checked = false;
            label.textContent = 'Cliente';
        });
}

// Preencher campos legados antes de submeter (backward compatibility)
document.getElementById('formNovoCaso').addEventListener('submit', function() {
    var nomes = document.querySelectorAll('input[name="partes_nome[]"]');
    var docs = document.querySelectorAll('input[name="partes_doc[]"]');
    var papeis = document.querySelectorAll('select[name="partes_papel[]"]');
    // Pegar o primeiro réu para campos legados
    for (var i = 0; i < papeis.length; i++) {
        if (papeis[i].value === 'reu' && nomes[i] && nomes[i].value.trim()) {
            document.getElementById('parteReNomeHidden').value = nomes[i].value;
            document.getElementById('parteReCpfHidden').value = docs[i] ? docs[i].value : '';
            break;
        }
    }
});

function buscarDocParte(el) {
    var doc = el.value.replace(/\D/g, '');
    var row = el.closest('.parte-row');
    if (!row) return;
    var nomeInput = row.querySelector('input[name="partes_nome[]"]');
    if (!nomeInput || nomeInput.value.trim() !== '') return;

    if (doc.length === 14) {
        // CNPJ — ReceitaWS
        nomeInput.placeholder = 'Buscando CNPJ...';
        var xhr = new XMLHttpRequest();
        xhr.open('GET', 'https://www.receitaws.com.br/v1/cnpj/' + doc);
        xhr.timeout = 8000;
        xhr.onload = function() {
            try {
                var data = JSON.parse(xhr.responseText);
                if (data.nome) {
                    nomeInput.value = data.nome;
                    nomeInput.style.borderColor = '#059669';
                    setTimeout(function(){ nomeInput.style.borderColor = ''; }, 2000);
                } else {
                    nomeInput.placeholder = 'Nome da parte';
                }
            } catch(e) { nomeInput.placeholder = 'Nome da parte'; }
        };
        xhr.onerror = function() { nomeInput.placeholder = 'Nome da parte'; };
        xhr.send();
    } else if (doc.length === 11) {
        // CPF — busca unificada (base interna + API externa)
        nomeInput.placeholder = 'Buscando CPF...';
        var xhr2 = new XMLHttpRequest();
        xhr2.open('GET', '<?= url("publico/api_cpf.php") ?>?cpf=' + doc);
        xhr2.timeout = 12000;
        xhr2.onload = function() {
            try {
                var r = JSON.parse(xhr2.responseText);
                if (r.status === 'OK' && r.nome) {
                    nomeInput.value = r.nome;
                    nomeInput.style.borderColor = '#059669';
                    setTimeout(function(){ nomeInput.style.borderColor = ''; }, 2000);
                } else {
                    nomeInput.placeholder = 'Não encontrado. Digite manualmente.';
                    setTimeout(function(){ nomeInput.placeholder = 'Nome da parte'; }, 3000);
                }
            } catch(e) { nomeInput.placeholder = 'Nome da parte'; }
        };
        xhr2.onerror = function() { nomeInput.placeholder = 'Nome da parte'; };
        xhr2.send();
    }
}

function buscarCpfCnpj() {
    // Legacy — mantida para compatibilidade mas não mais usada
    return;

    // Se nome já está preenchido, não buscar
    if (nomeInput.value.trim() !== '') return;

    if (doc.length === 14) {
        // CNPJ — buscar na ReceitaWS (gratuita)
        loading.style.display = 'inline';
        var xhr = new XMLHttpRequest();
        xhr.open('GET', 'https://www.receitaws.com.br/v1/cnpj/' + doc);
        xhr.timeout = 8000;
        xhr.onload = function() {
            loading.style.display = 'none';
            try {
                var data = JSON.parse(xhr.responseText);
                if (data.nome) {
                    nomeInput.value = data.nome;
                    nomeInput.style.borderColor = '#059669';
                    setTimeout(function() { nomeInput.style.borderColor = ''; }, 2000);
                }
            } catch(e) {}
        };
        xhr.onerror = function() { loading.style.display = 'none'; };
        xhr.ontimeout = function() { loading.style.display = 'none'; };
        xhr.send();
    } else if (doc.length === 11) {
        // CPF — buscar na base interna de clientes
        loading.style.display = 'inline';
        var cpfFormatado = doc.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4');
        var xhr2 = new XMLHttpRequest();
        xhr2.open('GET', '<?= module_url("operacional", "caso_novo.php") ?>?ajax_busca_cliente=1&q=' + encodeURIComponent(cpfFormatado));
        xhr2.onload = function() {
            loading.style.display = 'none';
            try {
                var clientes = JSON.parse(xhr2.responseText);
                if (clientes.length > 0) {
                    nomeInput.value = clientes[0].name;
                    nomeInput.style.borderColor = '#059669';
                    setTimeout(function() { nomeInput.style.borderColor = ''; }, 2000);
                }
            } catch(e) {}
        };
        xhr2.onerror = function() { loading.style.display = 'none'; };
        xhr2.send();
    }
}

// ── Busca de cidades por UF (API IBGE) ──
var cidadesCache = {};
function filtrarCidades() {
    var uf = document.getElementById('comarcaUf').value;
    var datalist = document.getElementById('listaCidades');
    var inputCidade = document.getElementById('comarcaCidade');
    datalist.innerHTML = '';
    inputCidade.value = '';
    if (!uf) return;

    if (cidadesCache[uf]) {
        preencherCidades(cidadesCache[uf]);
        return;
    }

    var xhr = new XMLHttpRequest();
    xhr.open('GET', 'https://servicodados.ibge.gov.br/api/v1/localidades/estados/' + uf + '/municipios?orderBy=nome');
    xhr.onload = function() {
        try {
            var cidades = JSON.parse(xhr.responseText);
            var nomes = [];
            for (var i = 0; i < cidades.length; i++) {
                nomes.push(cidades[i].nome);
            }
            cidadesCache[uf] = nomes;
            preencherCidades(nomes);
        } catch(e) {}
    };
    xhr.send();
}

function preencherCidades(nomes) {
    var datalist = document.getElementById('listaCidades');
    datalist.innerHTML = '';
    for (var i = 0; i < nomes.length; i++) {
        var opt = document.createElement('option');
        opt.value = nomes[i];
        datalist.appendChild(opt);
    }
}

// Pré-carregamento da lista de cidades quando UF vem pré-preenchida pelo
// servidor (caso_novo aberto via ?orgao=... do Email Monitor → UF=RJ).
// Difere de filtrarCidades(): não limpa o valor digitado em comarcaCidade,
// só popula o datalist pra o autocomplete funcionar.
(function() {
    var ufEl = document.getElementById('comarcaUf');
    if (!ufEl || !ufEl.value) return;
    var uf = ufEl.value;
    if (cidadesCache[uf]) {
        preencherCidades(cidadesCache[uf]);
        return;
    }
    var xhr = new XMLHttpRequest();
    xhr.open('GET', 'https://servicodados.ibge.gov.br/api/v1/localidades/estados/' + uf + '/municipios?orderBy=nome');
    xhr.onload = function() {
        try {
            var cidades = JSON.parse(xhr.responseText);
            var nomes = [];
            for (var i = 0; i < cidades.length; i++) nomes.push(cidades[i].nome);
            cidadesCache[uf] = nomes;
            preencherCidades(nomes);
        } catch(e) {}
    };
    xhr.send();
})();

// ── Pré-selecionar tipo de ação do processo principal ──
<?php if ($princData && $princData['case_type']): ?>
(function() {
    var sel = document.getElementById('selCaseType');
    var preType = <?= json_encode($princData['case_type']) ?>;
    for (var i = 0; i < sel.options.length; i++) {
        if (sel.options[i].value === preType) {
            sel.selectedIndex = i;
            sel.style.background = '#fef9c3';
            break;
        }
    }
    // Seção de filhos (para alimentos)
    if (preType.toLowerCase().indexOf('aliment') !== -1) {
        var sf = document.getElementById('secaoFilhos');
        if (sf) sf.style.display = 'block';
    }
})();
<?php endif; ?>
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
