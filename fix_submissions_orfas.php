<?php
/**
 * Corrige submissions de cadastro_cliente que não foram vinculadas
 * e não geraram lead no pipeline (bug do find_or_create_client)
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions.php';

$pdo = db();
$dryRun = !isset($_GET['executar']);
echo "=== CORRIGIR SUBMISSIONS ÓRFÃS ===\n";
echo $dryRun ? ">>> SIMULAÇÃO <<<\n\n" : ">>> EXECUTANDO <<<\n\n";

// Buscar submissions sem linked_client_id (exceto teste)
$stmt = $pdo->query(
    "SELECT id, form_type, client_name, client_phone, client_email, created_at
     FROM form_submissions
     WHERE linked_client_id IS NULL AND client_name IS NOT NULL AND client_name != ''
     AND client_name NOT LIKE '%teste%' AND client_name NOT LIKE '%Teste%'
     ORDER BY id DESC"
);
$submissions = $stmt->fetchAll();
echo "Submissions órfãs: " . count($submissions) . "\n\n";

$vinculados = 0;
$leadsCreados = 0;

foreach ($submissions as $fs) {
    echo "--- #{$fs['id']} {$fs['client_name']} ({$fs['form_type']}) ---\n";

    $name = trim($fs['client_name']);
    $phone = trim($fs['client_phone'] ?: '');
    $email = trim($fs['client_email'] ?: '');

    // Buscar cliente existente
    $clientId = null;

    // Por telefone (8 últimos dígitos)
    if ($phone) {
        $phoneLast8 = substr(preg_replace('/\D/', '', $phone), -8);
        if (strlen($phoneLast8) >= 8) {
            $chk = $pdo->prepare("SELECT id, name FROM clients WHERE phone LIKE ? LIMIT 1");
            $chk->execute(array('%' . $phoneLast8));
            $row = $chk->fetch();
            if ($row) {
                $clientId = (int)$row['id'];
                echo "  Encontrado por telefone: #{$clientId} {$row['name']}\n";
            }
        }
    }

    // Por email
    if (!$clientId && $email) {
        $chk = $pdo->prepare("SELECT id, name FROM clients WHERE email = ? LIMIT 1");
        $chk->execute(array($email));
        $row = $chk->fetch();
        if ($row) {
            $clientId = (int)$row['id'];
            echo "  Encontrado por email: #{$clientId} {$row['name']}\n";
        }
    }

    // Por nome exato
    if (!$clientId) {
        $chk = $pdo->prepare("SELECT id, name FROM clients WHERE name = ? LIMIT 1");
        $chk->execute(array($name));
        $row = $chk->fetch();
        if ($row) {
            $clientId = (int)$row['id'];
            echo "  Encontrado por nome: #{$clientId} {$row['name']}\n";
        }
    }

    if (!$clientId) {
        echo "  AVISO: cliente não encontrado, pulando\n\n";
        continue;
    }

    if ($dryRun) {
        echo "  [SIMULAÇÃO] Vincularia submission #{$fs['id']} → cliente #{$clientId}\n";
    } else {
        // Vincular submission
        $pdo->prepare("UPDATE form_submissions SET linked_client_id = ? WHERE id = ?")
            ->execute(array($clientId, $fs['id']));
        echo "  [OK] Submission vinculada\n";
        $vinculados++;
    }

    // Verificar se tem lead no pipeline
    if ($fs['form_type'] === 'cadastro_cliente') {
        $leadExists = false;
        if ($phone) {
            $chk = $pdo->prepare("SELECT id FROM pipeline_leads WHERE phone = ? LIMIT 1");
            $chk->execute(array($phone));
            if ($chk->fetch()) $leadExists = true;
        }
        if (!$leadExists) {
            $chk = $pdo->prepare("SELECT id FROM pipeline_leads WHERE name = ? AND client_id = ? LIMIT 1");
            $chk->execute(array($name, $clientId));
            if ($chk->fetch()) $leadExists = true;
        }

        if ($leadExists) {
            echo "  Lead já existe no pipeline\n";
        } else {
            if ($dryRun) {
                echo "  [SIMULAÇÃO] Criaria lead no pipeline: $name\n";
            } else {
                $pdo->prepare(
                    "INSERT INTO pipeline_leads (name, phone, email, source, stage, client_id, created_at)
                     VALUES (?, ?, ?, 'landing', 'cadastro_preenchido', ?, ?)"
                )->execute(array($name, $phone, $email, $clientId, $fs['created_at']));
                $leadId = (int)$pdo->lastInsertId();

                $pdo->prepare("INSERT INTO pipeline_history (lead_id, to_stage, created_at) VALUES (?, 'cadastro_preenchido', ?)")
                    ->execute(array($leadId, $fs['created_at']));

                echo "  [OK] Lead #{$leadId} criado no pipeline\n";
                $leadsCreados++;
            }
        }
    }

    echo "\n";
}

echo "=== RESUMO ===\n";
echo "Submissions vinculadas: $vinculados\n";
echo "Leads criados no pipeline: $leadsCreados\n";
if ($dryRun) echo "\n>>> Para executar: adicione &executar <<<\n";
echo "\n=== FIM ===\n";
