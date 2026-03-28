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

    // 2. Auto-cadastrar no CRM (se tiver nome)
    $clientId = null;
    if ($name) {
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
            // Criar novo cliente
            $source = 'landing';
            if ($formType === 'calculadora_lead' || $formType === 'calculadora') $source = 'calculadora';

            $pdo->prepare(
                "INSERT INTO clients (name, phone, email, source, notes, created_at) VALUES (?, ?, ?, ?, ?, NOW())"
            )->execute(array(
                $name, $phone, $email, $source,
                'Auto-cadastrado via formulário: ' . $formType
            ));
            $clientId = (int)$pdo->lastInsertId();
        }

        // Vincular formulário ao cliente
        $pdo->prepare("UPDATE form_submissions SET linked_client_id = ? WHERE id = ?")
            ->execute(array($clientId, $submissionId));
    }

    // 3. Auto-criar lead no Pipeline (se não existir)
    $leadId = null;
    if ($name) {
        $existingLead = null;
        if ($phone) {
            $check = $pdo->prepare("SELECT id FROM pipeline_leads WHERE phone = ? AND stage NOT IN ('contrato','perdido') LIMIT 1");
            $check->execute(array($phone));
            $existingLead = $check->fetch();
        }

        if (!$existingLead) {
            $leadSource = 'landing';
            if ($formType === 'calculadora_lead' || $formType === 'calculadora') $leadSource = 'calculadora';
            elseif ($formType === 'convivencia') $leadSource = 'landing';
            elseif ($formType === 'gastos_pensao') $leadSource = 'landing';

            $caseType = '';
            if ($formType === 'convivencia') $caseType = 'Convivência';
            elseif ($formType === 'gastos_pensao') $caseType = 'Pensão Alimentícia';
            elseif ($formType === 'divorcio') $caseType = 'Divórcio';
            elseif ($formType === 'alimentos') $caseType = 'Alimentos';

            $pdo->prepare(
                "INSERT INTO pipeline_leads (name, phone, email, source, stage, case_type, client_id, created_at) VALUES (?, ?, ?, ?, 'novo', ?, ?, NOW())"
            )->execute(array($name, $phone, $email, $leadSource, $caseType ?: null, $clientId));
            $leadId = (int)$pdo->lastInsertId();

            // Registrar histórico
            $pdo->prepare("INSERT INTO pipeline_history (lead_id, to_stage, created_at) VALUES (?, 'novo', NOW())")
                ->execute(array($leadId));
        }
    }

    return array(
        'submission_id' => $submissionId,
        'client_id' => $clientId,
        'lead_id' => $leadId,
        'protocol' => $protocol,
    );
}
