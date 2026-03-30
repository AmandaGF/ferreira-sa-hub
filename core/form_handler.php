<?php
/**
 * Ferreira & Sá Hub — Handler para formulários públicos
 *
 * Os formulários existentes (Convivência, Gastos Pensão, Cadastro, etc.)
 * podem chamar este arquivo para:
 * 1. Salvar a resposta em form_submissions
 * 2. Auto-criar o cliente no CRM
 * 3. Auto-criar um lead no Pipeline
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';

/**
 * Salvar submissão de formulário e auto-cadastrar no CRM
 *
 * @param string $formType  Tipo do formulário (convivencia, gastos_pensao, cadastro_cliente, etc.)
 * @param array  $clientData  ['name' => ..., 'phone' => ..., 'email' => ...]
 * @param string $payloadJson  JSON completo com todas as respostas
 * @return array  ['submission_id' => ..., 'client_id' => ..., 'protocol' => ...]
 */
function process_form_submission($formType, $clientData, $payloadJson)
{
    $pdo = db();
    $protocol = generate_protocol(strtoupper(substr($formType, 0, 3)));

    $name  = isset($clientData['name']) ? clean_str($clientData['name'], 150) : null;
    $phone = isset($clientData['phone']) ? clean_str($clientData['phone'], 40) : null;
    $email = isset($clientData['email']) ? clean_str($clientData['email'], 190) : null;

    // Dados extras para preencher o CRM completo
    $cpf = isset($clientData['cpf']) ? clean_str($clientData['cpf'], 14) : null;
    $rg = isset($clientData['rg']) ? clean_str($clientData['rg'], 20) : null;
    $birthDate = isset($clientData['birth_date']) ? $clientData['birth_date'] : null;
    $profession = isset($clientData['profession']) ? clean_str($clientData['profession'], 100) : null;
    $maritalStatus = isset($clientData['marital_status']) ? clean_str($clientData['marital_status'], 30) : null;
    $addressStreet = isset($clientData['address_street']) ? clean_str($clientData['address_street'], 255) : null;
    $addressCity = isset($clientData['address_city']) ? clean_str($clientData['address_city'], 100) : null;
    $addressState = isset($clientData['address_state']) ? clean_str($clientData['address_state'], 2) : null;
    $addressZip = isset($clientData['address_zip']) ? clean_str($clientData['address_zip'], 10) : null;

    // 1. Salvar em form_submissions
    $stmt = $pdo->prepare(
        "INSERT INTO form_submissions (form_type, protocol, client_name, client_email, client_phone, status, payload_json, ip_address, user_agent, created_at)
         VALUES (?, ?, ?, ?, ?, 'novo', ?, ?, ?, NOW())"
    );
    $stmt->execute(array(
        $formType, $protocol, $name, $email, $phone,
        $payloadJson,
        isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null,
        isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 500) : null,
    ));
    $submissionId = (int)$pdo->lastInsertId();

    // 2. Auto-cadastrar no CRM + Pipeline SOMENTE para cadastro_cliente
    //    Outros formulários: só salvam a submissão, não entram no CRM/Pipeline
    $clientId = null;
    $leadId = null;

    $entersCrm = ($formType === 'cadastro_cliente');

    if ($name && $entersCrm) {
        // Verificar se já existe pelo telefone ou e-mail
        $existingClient = null;
        if ($phone) {
            $check = $pdo->prepare("SELECT id FROM clients WHERE phone = ? LIMIT 1");
            $check->execute(array($phone));
            $existingClient = $check->fetch();
        }
        if (!$existingClient && $email) {
            $check = $pdo->prepare("SELECT id FROM clients WHERE email = ? LIMIT 1");
            $check->execute(array($email));
            $existingClient = $check->fetch();
        }

        if ($existingClient) {
            $clientId = (int)$existingClient['id'];
        } else {
            $pdo->prepare(
                "INSERT INTO clients (name, cpf, rg, birth_date, phone, email, profession, marital_status, address_street, address_city, address_state, address_zip, source, notes, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
            )->execute(array(
                $name, $cpf, $rg, $birthDate ?: null,
                $phone, $email, $profession, $maritalStatus,
                $addressStreet, $addressCity, $addressState, $addressZip,
                'landing',
                'Auto-cadastrado via formulário: ' . $formType
            ));
            $clientId = (int)$pdo->lastInsertId();
        }

        // Vincular formulário ao cliente
        $pdo->prepare("UPDATE form_submissions SET linked_client_id = ? WHERE id = ?")
            ->execute(array($clientId, $submissionId));

        // 3. Auto-criar lead no Pipeline (estágio: cadastro_preenchido)
        $existingLead = null;
        if ($phone) {
            $check = $pdo->prepare("SELECT id FROM pipeline_leads WHERE phone = ? AND stage NOT IN ('contrato_assinado','finalizado','perdido') LIMIT 1");
            $check->execute(array($phone));
            $existingLead = $check->fetch();
        }

        if (!$existingLead) {
            $pdo->prepare(
                "INSERT INTO pipeline_leads (name, phone, email, source, stage, client_id, created_at) VALUES (?, ?, ?, 'landing', 'cadastro_preenchido', ?, NOW())"
            )->execute(array($name, $phone, $email, $clientId));
            $leadId = (int)$pdo->lastInsertId();

            $pdo->prepare("INSERT INTO pipeline_history (lead_id, to_stage, created_at) VALUES (?, 'cadastro_preenchido', NOW())")
                ->execute(array($leadId));
        }
    }

    // Notificar gestão sobre novo formulário
    $tipoLabel = $formType;
    $tipoLabels = array(
        'cadastro_cliente' => 'Cadastro de Cliente',
        'convivencia' => 'Convivência',
        'gastos_pensao' => 'Gastos Pensão',
        'calculadora' => 'Calculadora',
        'divorcio' => 'Divórcio',
        'alimentos' => 'Alimentos',
    );
    if (isset($tipoLabels[$formType])) $tipoLabel = $tipoLabels[$formType];
    notify_gestao(
        'Novo formulário recebido',
        ($name ? $name : 'Cliente') . ' preencheu ' . $tipoLabel . '. Protocolo: ' . $protocol,
        'pendencia',
        url('modules/formularios/ver.php?id=' . $submissionId),
        '📋'
    );

    return array(
        'submission_id' => $submissionId,
        'client_id' => $clientId,
        'lead_id' => $leadId,
        'protocol' => $protocol,
    );
}
