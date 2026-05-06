<?php
/**
 * API de Partes do Processo (CRUD + busca CPF/CNPJ)
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();

header('Content-Type: application/json; charset=utf-8');
$pdo = db();

// ── GET ──
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? 'listar';

    if ($action === 'listar') {
        $caseId = (int)($_GET['case_id'] ?? 0);
        if (!$caseId) { echo json_encode(array('error' => 'case_id obrigatório')); exit; }
        $stmt = $pdo->prepare(
            "SELECT p.*,
                    rep.nome as representa_nome,
                    (SELECT GROUP_CONCAT(rp.nome SEPARATOR ', ') FROM case_partes rp WHERE rp.representa_parte_id = p.id) as representado_por
             FROM case_partes p
             LEFT JOIN case_partes rep ON rep.id = p.representa_parte_id
             WHERE p.case_id = ?
             ORDER BY FIELD(p.papel,'autor','reu','representante_legal','terceiro_interessado','litisconsorte_ativo','litisconsorte_passivo'), p.id"
        );
        $stmt->execute(array($caseId));
        echo json_encode($stmt->fetchAll());
        exit;
    }

    if ($action === 'get') {
        $id = (int)($_GET['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM case_partes WHERE id = ?");
        $stmt->execute(array($id));
        echo json_encode($stmt->fetch() ?: array('error' => 'Não encontrada'));
        exit;
    }

    if ($action === 'buscar_cpf') {
        $cpf = preg_replace('/\D/', '', $_GET['q'] ?? '');
        if (strlen($cpf) < 11) { echo json_encode(array('found' => false)); exit; }

        // Usar helper centralizado
        $resultado = buscar_cpf($cpf);
        if (isset($resultado['erro'])) {
            echo json_encode(array('found' => false, 'source' => 'none'));
        } else {
            echo json_encode(array('found' => true, 'source' => $resultado['fonte'], 'data' => $resultado['dados']));
        }
        exit;
    }

    if ($action === 'buscar_nome_parte') {
        $q = trim($_GET['q'] ?? '');
        if (mb_strlen($q) < 3) { echo json_encode(array()); exit; }
        $pdo = db();
        // Buscar em case_partes (partes já cadastradas em outros processos)
        $stmt = $pdo->prepare(
            "SELECT DISTINCT nome, cpf, rg, nascimento, profissao, estado_civil, email, telefone, endereco, cidade, uf, cep, tipo_pessoa, cnpj, razao_social, nome_fantasia
             FROM case_partes
             WHERE nome LIKE ? OR razao_social LIKE ?
             ORDER BY nome
             LIMIT 10"
        );
        $like = '%' . $q . '%';
        $stmt->execute(array($like, $like));
        $results = $stmt->fetchAll();
        // Também buscar em clients (clientes cadastrados)
        $stmtCli = $pdo->prepare(
            "SELECT id as client_id, name as nome, cpf, rg, birth_date as nascimento, profession as profissao, marital_status as estado_civil,
                    email, phone as telefone, address_street as endereco, address_city as cidade, address_state as uf, address_zip as cep,
                    'fisica' as tipo_pessoa, NULL as cnpj, NULL as razao_social, NULL as nome_fantasia, 'cliente' as fonte
             FROM clients
             WHERE name LIKE ?
             ORDER BY name
             LIMIT 10"
        );
        $stmtCli->execute(array($like));
        $clientResults = $stmtCli->fetchAll();
        $results = array_merge($results, $clientResults);

        // Deduplicate by nome+cpf
        $seen = array();
        $unique = array();
        foreach ($results as $r) {
            $key = mb_strtolower($r['nome'] ?: $r['razao_social']) . '|' . ($r['cpf'] ?: $r['cnpj']);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $unique[] = $r;
        }
        // Limitar a 15
        $unique = array_slice($unique, 0, 15);
        echo json_encode($unique);
        exit;
    }

    if ($action === 'buscar_cnpj') {
        $cnpj = preg_replace('/\D/', '', $_GET['q'] ?? '');
        if (strlen($cnpj) < 14) { echo json_encode(array('found' => false)); exit; }

        // Buscar na ReceitaWS
        $ch = curl_init('https://www.receitaws.com.br/v1/cnpj/' . $cnpj);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => array('Accept: application/json'),
        ));
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 200) {
            $data = json_decode($body, true);
            if ($data && !isset($data['error'])) {
                echo json_encode(array('found' => true, 'source' => 'receitaws', 'data' => array(
                    'razao_social' => isset($data['nome']) ? $data['nome'] : '',
                    'nome_fantasia' => isset($data['fantasia']) ? $data['fantasia'] : '',
                    'cnpj' => $cnpj,
                    'email' => isset($data['email']) ? $data['email'] : '',
                    'telefone' => isset($data['telefone']) ? $data['telefone'] : '',
                    'endereco' => isset($data['logradouro']) ? $data['logradouro'] . ', ' . ($data['numero'] ?: 'S/N') : '',
                    'cidade' => isset($data['municipio']) ? $data['municipio'] : '',
                    'uf' => isset($data['uf']) ? $data['uf'] : '',
                    'cep' => isset($data['cep']) ? $data['cep'] : '',
                )));
                exit;
            }
        }
        echo json_encode(array('found' => false, 'source' => 'none'));
        exit;
    }

    if ($action === 'buscar_cliente') {
        $q = trim($_GET['q'] ?? '');
        if (strlen($q) < 2) { echo '[]'; exit; }
        $stmt = $pdo->prepare("SELECT id, name, cpf, phone FROM clients WHERE name LIKE ? OR cpf LIKE ? ORDER BY name LIMIT 10");
        $stmt->execute(array('%'.$q.'%', '%'.$q.'%'));
        echo json_encode($stmt->fetchAll());
        exit;
    }

    echo json_encode(array('error' => 'Ação inválida'));
    exit;
}

// ── POST ──
if ($_SERVER['REQUEST_METHOD'] !== 'POST') exit;
if (!validate_csrf()) { echo json_encode(array('error' => 'CSRF inválido', 'csrf' => generate_csrf_token())); exit; }
$newCsrf = generate_csrf_token();
$action = $_POST['action'] ?? '';

// ── CRIAR CLIENTE RÁPIDO (quick-add para vincular à parte) ──
if ($action === 'criar_cliente_rapido') {
    $nome = trim($_POST['nome'] ?? '');
    $cpf = preg_replace('/\D/', '', $_POST['cpf'] ?? '');
    $telefone = trim($_POST['telefone'] ?? '');

    if (!$nome) {
        echo json_encode(array('error' => 'Nome obrigatório', 'csrf' => $newCsrf));
        exit;
    }

    // Verificar se já existe por CPF
    if ($cpf) {
        $stmtChk = $pdo->prepare("SELECT id, name FROM clients WHERE REPLACE(REPLACE(REPLACE(cpf,'.',''),'-',''),' ','') = ? LIMIT 1");
        $stmtChk->execute(array($cpf));
        $existing = $stmtChk->fetch();
        if ($existing) {
            echo json_encode(array('ok' => true, 'client_id' => (int)$existing['id'], 'nome' => $existing['name'], 'ja_existia' => true, 'csrf' => $newCsrf));
            exit;
        }
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO clients (name, cpf, phone, source, created_at) VALUES (?, ?, ?, 'quick_add_caso', NOW())");
        $stmt->execute(array($nome, $cpf ?: null, $telefone ?: null));
        $newId = (int)$pdo->lastInsertId();
        audit_log('cliente_criar', 'clients', $newId, "Quick-add via caso_novo: $nome");
        echo json_encode(array('ok' => true, 'client_id' => $newId, 'nome' => $nome, 'csrf' => $newCsrf));
    } catch (Exception $e) {
        echo json_encode(array('error' => 'Erro ao criar cliente: ' . $e->getMessage(), 'csrf' => $newCsrf));
    }
    exit;
}

// ── SALVAR ──
if ($action === 'salvar') {
    $id = (int)($_POST['id'] ?? 0);
    $caseId = (int)($_POST['case_id'] ?? 0);
    $papel = $_POST['papel'] ?? 'reu';
    $tipoPessoa = $_POST['tipo_pessoa'] ?? 'fisica';

    if (!$caseId) { echo json_encode(array('error' => 'case_id obrigatório', 'csrf' => $newCsrf)); exit; }

    $campos = array(
        'nome' => trim($_POST['nome'] ?? ''),
        'cpf' => trim($_POST['cpf'] ?? ''),
        'rg' => trim($_POST['rg'] ?? ''),
        'nascimento' => $_POST['nascimento'] ?? null,
        'profissao' => trim($_POST['profissao'] ?? ''),
        'estado_civil' => trim($_POST['estado_civil'] ?? ''),
        'razao_social' => trim($_POST['razao_social'] ?? ''),
        'cnpj' => trim($_POST['cnpj'] ?? ''),
        'nome_fantasia' => trim($_POST['nome_fantasia'] ?? ''),
        'representante_nome' => trim($_POST['representante_nome'] ?? ''),
        'representante_cpf' => trim($_POST['representante_cpf'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'telefone' => trim($_POST['telefone'] ?? ''),
        'endereco' => trim($_POST['endereco'] ?? ''),
        'cidade' => trim($_POST['cidade'] ?? ''),
        'uf' => trim($_POST['uf'] ?? ''),
        'cep' => trim($_POST['cep'] ?? ''),
        'client_id' => (int)($_POST['client_id'] ?? 0) ?: null,
        'representa_parte_id' => (int)($_POST['representa_parte_id'] ?? 0) ?: null,
        'observacoes' => trim($_POST['observacoes'] ?? ''),
    );

    // Limpar campos vazios
    foreach ($campos as $k => $v) { if ($v === '' || $v === '0') $campos[$k] = null; }
    if ($campos['nascimento'] === '') $campos['nascimento'] = null;

    // SEGURANÇA: client_id só é válido em partes que PODEM ser cliente:
    //   autor / litisconsorte ativo (parte ativa direta)
    //   representante legal (mãe que representa filho menor — frequentemente é cliente)
    // Lado adverso (réu, recorrido, etc.) NUNCA é nosso cliente. Force NULL.
    $papelDoNossoLado = in_array($papel, array('autor', 'litisconsorte_ativo', 'representante_legal'), true);
    if (!$papelDoNossoLado) {
        $campos['client_id'] = null;
    }

    $nomeExibir = $tipoPessoa === 'juridica' ? ($campos['razao_social'] ?: $campos['nome_fantasia']) : $campos['nome'];
    if (!$nomeExibir) { echo json_encode(array('error' => 'Nome/Razão Social obrigatório', 'csrf' => $newCsrf)); exit; }

    if ($id) {
        $sql = "UPDATE case_partes SET case_id=?, papel=?, tipo_pessoa=?, nome=?, cpf=?, rg=?, nascimento=?, profissao=?, estado_civil=?, razao_social=?, cnpj=?, nome_fantasia=?, representante_nome=?, representante_cpf=?, email=?, telefone=?, endereco=?, cidade=?, uf=?, cep=?, client_id=?, representa_parte_id=?, observacoes=? WHERE id=?";
        $params = array($caseId, $papel, $tipoPessoa, $campos['nome'], $campos['cpf'], $campos['rg'], $campos['nascimento'], $campos['profissao'], $campos['estado_civil'], $campos['razao_social'], $campos['cnpj'], $campos['nome_fantasia'], $campos['representante_nome'], $campos['representante_cpf'], $campos['email'], $campos['telefone'], $campos['endereco'], $campos['cidade'], $campos['uf'], $campos['cep'], $campos['client_id'], $campos['representa_parte_id'], $campos['observacoes'], $id);
        $pdo->prepare($sql)->execute($params);
        audit_log('PARTE_EDITADA', 'case_parte', $id, $papel . ': ' . $nomeExibir);
    } else {
        $sql = "INSERT INTO case_partes (case_id, papel, tipo_pessoa, nome, cpf, rg, nascimento, profissao, estado_civil, razao_social, cnpj, nome_fantasia, representante_nome, representante_cpf, email, telefone, endereco, cidade, uf, cep, client_id, representa_parte_id, observacoes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
        $params = array($caseId, $papel, $tipoPessoa, $campos['nome'], $campos['cpf'], $campos['rg'], $campos['nascimento'], $campos['profissao'], $campos['estado_civil'], $campos['razao_social'], $campos['cnpj'], $campos['nome_fantasia'], $campos['representante_nome'], $campos['representante_cpf'], $campos['email'], $campos['telefone'], $campos['endereco'], $campos['cidade'], $campos['uf'], $campos['cep'], $campos['client_id'], $campos['representa_parte_id'], $campos['observacoes']);
        $pdo->prepare($sql)->execute($params);
        $id = (int)$pdo->lastInsertId();
        audit_log('PARTE_CRIADA', 'case_parte', $id, $papel . ': ' . $nomeExibir);
    }

    // Se é representante legal, vincular às partes selecionadas
    if ($papel === 'representante_legal' && $id) {
        $representaIds = isset($_POST['representa_ids']) ? trim($_POST['representa_ids']) : '';
        // Limpar vínculos anteriores deste representante
        try { $pdo->prepare("UPDATE case_partes SET representa_parte_id = NULL WHERE representa_parte_id = ? AND case_id = ?")->execute(array($id, $caseId)); } catch (Exception $e) {}
        // Vincular novos
        if ($representaIds) {
            $ids = array_filter(array_map('intval', explode(',', $representaIds)));
            foreach ($ids as $repId) {
                try { $pdo->prepare("UPDATE case_partes SET representa_parte_id = ? WHERE id = ? AND case_id = ?")->execute(array($id, $repId, $caseId)); } catch (Exception $e) {}
            }
        }
    }

    echo json_encode(array('ok' => true, 'id' => $id, 'csrf' => $newCsrf));
    exit;
}

// ── EXCLUIR ──
if ($action === 'excluir') {
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) { echo json_encode(array('error' => 'ID obrigatório', 'csrf' => $newCsrf)); exit; }
    // Limpar referências de representa_parte_id
    $pdo->prepare("UPDATE case_partes SET representa_parte_id = NULL WHERE representa_parte_id = ?")->execute(array($id));
    $pdo->prepare("DELETE FROM case_partes WHERE id = ?")->execute(array($id));
    audit_log('PARTE_EXCLUIDA', 'case_parte', $id);
    echo json_encode(array('ok' => true, 'csrf' => $newCsrf));
    exit;
}

echo json_encode(array('error' => 'Ação inválida'));
