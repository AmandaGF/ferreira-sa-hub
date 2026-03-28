<?php
/**
 * Popular CRM com dados dos formulários já importados
 * Cria clientes a partir de form_submissions que ainda não estão vinculados
 */

if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
set_time_limit(300);
echo "=== Populando CRM com dados dos formularios ===\n\n";

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/database.php';
require_once __DIR__ . '/../../core/functions.php';

$pdo = db();

// Buscar todos os formulários sem cliente vinculado
$forms = $pdo->query(
    "SELECT * FROM form_submissions WHERE linked_client_id IS NULL ORDER BY created_at ASC"
)->fetchAll(PDO::FETCH_ASSOC);

echo "Formularios sem cliente vinculado: " . count($forms) . "\n\n";

$created = 0;
$linked = 0;
$skipped = 0;

foreach ($forms as $f) {
    $name = $f['client_name'];
    $phone = $f['client_phone'];
    $email = $f['client_email'];

    // Sem nome = pular
    if (empty($name) || trim($name) === '' || $name === 'null') {
        $skipped++;
        continue;
    }

    // Tentar extrair mais dados do payload
    $payload = json_decode($f['payload_json'], true);
    if (!is_array($payload)) $payload = array();

    // Dados extras do payload
    $cpf = isset($payload['cpf']) ? $payload['cpf'] : (isset($payload['cpf_responsavel']) ? $payload['cpf_responsavel'] : null);
    $rg = isset($payload['rg']) ? $payload['rg'] : null;
    $nascimento = isset($payload['nascimento']) ? $payload['nascimento'] : (isset($payload['birth_date']) ? $payload['birth_date'] : null);
    $profissao = isset($payload['profissao']) ? $payload['profissao'] : null;
    $estadoCivil = isset($payload['estado_civil']) ? $payload['estado_civil'] : null;
    $endereco = isset($payload['endereco']) ? $payload['endereco'] : null;
    $cidade = isset($payload['cidade']) ? $payload['cidade'] : null;
    $uf = isset($payload['uf']) ? $payload['uf'] : null;
    $cep = isset($payload['cep']) ? $payload['cep'] : null;
    $pix = isset($payload['pix']) ? $payload['pix'] : null;

    // Pegar telefone do payload se não tiver no campo principal
    if (empty($phone)) {
        $phone = isset($payload['celular']) ? $payload['celular'] : (isset($payload['whatsapp']) ? $payload['whatsapp'] : (isset($payload['client_phone']) ? $payload['client_phone'] : null));
    }
    if (empty($email)) {
        $email = isset($payload['email']) ? $payload['email'] : (isset($payload['client_email']) ? $payload['client_email'] : null);
    }
    if (empty($name)) {
        $name = isset($payload['nome']) ? $payload['nome'] : (isset($payload['nome_responsavel']) ? $payload['nome_responsavel'] : (isset($payload['client_name']) ? $payload['client_name'] : null));
    }

    if (empty($name)) { $skipped++; continue; }

    // Limpar telefone para comparação
    $phoneClean = $phone ? preg_replace('/\D/', '', $phone) : '';

    // Verificar se já existe no CRM (por telefone, email ou CPF)
    $existingId = null;

    if ($phoneClean && strlen($phoneClean) >= 10) {
        $check = $pdo->prepare("SELECT id FROM clients WHERE REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '(', ''), ')', ''), '-', ''), '+', '') LIKE ? LIMIT 1");
        $check->execute(array('%' . substr($phoneClean, -9) . '%'));
        $ex = $check->fetch();
        if ($ex) $existingId = (int)$ex['id'];
    }

    if (!$existingId && $email && trim($email) !== '') {
        $check = $pdo->prepare("SELECT id FROM clients WHERE email = ? LIMIT 1");
        $check->execute(array(trim($email)));
        $ex = $check->fetch();
        if ($ex) $existingId = (int)$ex['id'];
    }

    if (!$existingId && $cpf && trim($cpf) !== '') {
        $check = $pdo->prepare("SELECT id FROM clients WHERE cpf = ? LIMIT 1");
        $check->execute(array(trim($cpf)));
        $ex = $check->fetch();
        if ($ex) $existingId = (int)$ex['id'];
    }

    if ($existingId) {
        // Vincular formulário ao cliente existente
        $pdo->prepare("UPDATE form_submissions SET linked_client_id = ? WHERE id = ?")
            ->execute(array($existingId, $f['id']));
        $linked++;
        echo "  VINCULADO: " . $name . " -> Cliente #$existingId\n";
    } else {
        // Criar novo cliente
        $source = 'landing';
        if ($f['form_type'] === 'calculadora_lead') $source = 'calculadora';
        elseif ($f['form_type'] === 'cadastro_cliente') $source = 'landing';
        elseif ($f['form_type'] === 'convivencia') $source = 'landing';
        elseif ($f['form_type'] === 'gastos_pensao') $source = 'landing';

        // Validar data de nascimento
        if ($nascimento && !preg_match('/^\d{4}-\d{2}-\d{2}/', $nascimento)) {
            // Tentar converter dd/mm/yyyy
            $parts = explode('/', $nascimento);
            if (count($parts) === 3) {
                $nascimento = $parts[2] . '-' . $parts[1] . '-' . $parts[0];
            } else {
                $nascimento = null;
            }
        }

        $notes = 'Auto-importado de: ' . $f['form_type'] . ' (protocolo: ' . $f['protocol'] . ')';
        if ($pix) $notes .= "\nPIX: " . $pix;

        $stmt = $pdo->prepare(
            "INSERT INTO clients (name, cpf, rg, birth_date, email, phone, address_street, address_city, address_state, address_zip, profession, marital_status, source, notes, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute(array(
            trim($name),
            $cpf ? trim($cpf) : null,
            $rg ? trim($rg) : null,
            $nascimento ?: null,
            $email ? trim($email) : null,
            $phone ? trim($phone) : null,
            $endereco ? trim($endereco) : null,
            $cidade ? trim($cidade) : null,
            $uf ? trim($uf) : null,
            $cep ? trim($cep) : null,
            $profissao ? trim($profissao) : null,
            $estadoCivil ? trim($estadoCivil) : null,
            $source,
            $notes,
            $f['created_at']
        ));
        $newClientId = (int)$pdo->lastInsertId();

        // Vincular formulário
        $pdo->prepare("UPDATE form_submissions SET linked_client_id = ? WHERE id = ?")
            ->execute(array($newClientId, $f['id']));

        $created++;
        echo "  CRIADO: " . $name . " -> Cliente #$newClientId";
        if ($cpf) echo " (CPF: $cpf)";
        if ($phone) echo " (Tel: $phone)";
        echo "\n";
    }
}

echo "\n=== CONCLUIDO ===\n";
echo "Clientes criados: $created\n";
echo "Vinculados a existentes: $linked\n";
echo "Ignorados (sem nome): $skipped\n";

$totalClients = (int)$pdo->query("SELECT COUNT(*) FROM clients")->fetchColumn();
echo "Total de clientes no CRM agora: $totalClients\n";
