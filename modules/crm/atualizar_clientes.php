<?php
/**
 * Atualizar clientes do CRM com dados dos formulários já importados
 * Relê o payload_json e preenche campos vazios no CRM
 */

if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
set_time_limit(300);
echo "=== Atualizando clientes com dados dos formularios ===\n\n";

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/database.php';
require_once __DIR__ . '/../../core/functions.php';

$pdo = db();

// Buscar formulários vinculados a clientes
$forms = $pdo->query(
    "SELECT fs.*, c.id as cid, c.cpf as c_cpf, c.rg as c_rg, c.birth_date as c_birth,
     c.profession as c_prof, c.marital_status as c_marital, c.address_street as c_street,
     c.address_city as c_city, c.address_state as c_state, c.address_zip as c_zip,
     c.phone as c_phone, c.email as c_email
     FROM form_submissions fs
     JOIN clients c ON c.id = fs.linked_client_id
     ORDER BY fs.created_at DESC"
)->fetchAll(PDO::FETCH_ASSOC);

echo "Formularios vinculados a clientes: " . count($forms) . "\n\n";

$updated = 0;
$processed = array();

foreach ($forms as $f) {
    $cid = (int)$f['cid'];
    if (isset($processed[$cid])) continue;
    $processed[$cid] = true;

    $payload = json_decode($f['payload_json'], true);
    if (!is_array($payload)) continue;

    // Mapear campos do payload para campos do CRM
    $updates = array();
    $params = array();

    // CPF
    $cpf = isset($payload['cpf']) ? $payload['cpf'] : (isset($payload['cpf_responsavel']) ? $payload['cpf_responsavel'] : null);
    if ($cpf && !$f['c_cpf']) { $updates[] = 'cpf = ?'; $params[] = trim($cpf); }

    // RG
    $rg = isset($payload['rg']) ? $payload['rg'] : null;
    if ($rg && !$f['c_rg']) { $updates[] = 'rg = ?'; $params[] = trim($rg); }

    // Nascimento
    $nasc = isset($payload['nascimento']) ? $payload['nascimento'] : (isset($payload['birth_date']) ? $payload['birth_date'] : null);
    if ($nasc && !$f['c_birth']) {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $nasc)) {
            $updates[] = 'birth_date = ?'; $params[] = $nasc;
        } elseif (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $nasc, $m)) {
            $updates[] = 'birth_date = ?'; $params[] = $m[3] . '-' . $m[2] . '-' . $m[1];
        }
    }

    // Profissão
    $prof = isset($payload['profissao']) ? $payload['profissao'] : null;
    if ($prof && !$f['c_prof']) { $updates[] = 'profession = ?'; $params[] = trim($prof); }

    // Estado civil
    $marital = isset($payload['estado_civil']) ? $payload['estado_civil'] : null;
    if ($marital && !$f['c_marital']) { $updates[] = 'marital_status = ?'; $params[] = trim($marital); }

    // Endereço
    $end = isset($payload['endereco']) ? $payload['endereco'] : null;
    if ($end && !$f['c_street']) { $updates[] = 'address_street = ?'; $params[] = trim($end); }

    // CEP
    $cep = isset($payload['cep']) ? $payload['cep'] : null;
    if ($cep && !$f['c_zip']) { $updates[] = 'address_zip = ?'; $params[] = trim($cep); }

    // Telefone (se vazio)
    $tel = isset($payload['celular']) ? $payload['celular'] : (isset($payload['whatsapp']) ? $payload['whatsapp'] : (isset($payload['client_phone']) ? $payload['client_phone'] : null));
    if ($tel && !$f['c_phone']) { $updates[] = 'phone = ?'; $params[] = trim($tel); }

    // Email (se vazio)
    $em = isset($payload['email']) ? $payload['email'] : (isset($payload['client_email']) ? $payload['client_email'] : null);
    if ($em && !$f['c_email']) { $updates[] = 'email = ?'; $params[] = trim($em); }

    if (!empty($updates)) {
        $params[] = $cid;
        $sql = 'UPDATE clients SET ' . implode(', ', $updates) . ', updated_at = NOW() WHERE id = ?';
        $pdo->prepare($sql)->execute($params);
        $updated++;
        echo "  ATUALIZADO #$cid: " . implode(', ', $updates) . "\n";
    }
}

echo "\n=== CONCLUIDO ===\n";
echo "Clientes atualizados: $updated\n";
echo "Clientes processados: " . count($processed) . "\n";
