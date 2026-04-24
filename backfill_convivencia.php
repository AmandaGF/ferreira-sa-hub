<?php
/**
 * Backfill das respostas de convivência perdidas entre 01/04 e 24/04/2026.
 * Causa: dual-write quebrado (URL com www retornando 301).
 *
 * Pega todas as linhas de intake_visitas (banco antigo) criadas depois de
 * 01/04, pula as que já estão em form_submissions (protocol_original),
 * e faz o POST correto pro api_form.php do Hub.
 *
 * Também remove o registro #525 (teste do diagnóstico).
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('x'); }
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/core/database.php';
$pdoHub = db();

// 1. Limpa registro de teste
echo "=== Limpando #525 (teste do diagnóstico) ===\n";
$del = $pdoHub->exec("DELETE FROM form_submissions WHERE id = 525 AND payload_json LIKE '%Sayonara TESTE DUAL-WRITE%'");
echo "Removidas: {$del} linha(s)\n\n";

// 2. Conecta no banco antigo
require_once dirname(__DIR__) . '/convivencia_form/config.php';
$pdoOld = pdo();

// Busca todas depois de 01/04 (exclusivo — dia 01 já estava no Hub)
$q = $pdoOld->query("SELECT * FROM intake_visitas WHERE created_at > '2026-04-01 23:59:59' ORDER BY id ASC");
$velhas = $q->fetchAll(PDO::FETCH_ASSOC);
echo "=== Linhas em intake_visitas depois de 01/04: " . count($velhas) . " ===\n\n";

$apiUrl = 'https://ferreiraesa.com.br/conecta/publico/api_form.php';
$migrados = 0;
$ja = 0;
$erros = 0;

foreach ($velhas as $v) {
    echo "── #{$v['id']} [{$v['protocol']}] {$v['created_at']}\n";
    echo "   {$v['client_name']} | tel={$v['client_phone']} | papel={$v['relationship_role']}\n";

    // Já foi migrado? Busca por protocol_original no payload do Hub
    $chk = $pdoHub->prepare("SELECT id, protocol FROM form_submissions WHERE form_type='convivencia' AND payload_json LIKE ?");
    $chk->execute(array('%' . $v['protocol'] . '%'));
    $ex = $chk->fetch();
    if ($ex) {
        echo "   → JÁ migrado: Hub #{$ex['id']} ({$ex['protocol']}) — skip\n\n";
        $ja++;
        continue;
    }

    // Parse answers_json original pra extrair dados completos
    $answers = json_decode((string)$v['answers_json'], true) ?: array();

    // Monta payload idêntico ao que submit.php faria
    $payload = array(
        'form_type'         => 'convivencia',
        'client_name'       => $v['client_name'],
        'client_phone'      => $v['client_phone'],
        'client_email'      => $v['client_email'],
        'child_name'        => $v['child_name'],
        'child_age'         => (int)$v['child_age'],
        'relationship_role' => $v['relationship_role'],
        'answers'           => $answers,
        'protocol_original' => $v['protocol'],
        '_backfill_original_created_at' => $v['created_at'],
    );

    // Envia
    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, array(
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => array('Content-Type: application/json'),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FOLLOWLOCATION => true,
    ));
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode((string)$resp, true);
    if ($code === 200 && !empty($data['ok'])) {
        echo "   ✓ migrado: Hub #{$data['submission_id']} ({$data['protocol']}) client #{$data['client_id']}\n";

        // Ajusta created_at pro valor original (pra manter cronologia correta)
        $pdoHub->prepare("UPDATE form_submissions SET created_at = ? WHERE id = ?")
               ->execute(array($v['created_at'], $data['submission_id']));
        echo "   ✓ data retroativa ajustada pra {$v['created_at']}\n";
        $migrados++;
    } else {
        echo "   ✗ falha HTTP {$code}: " . substr((string)$resp, 0, 200) . "\n";
        $erros++;
    }
    echo "\n";
}

echo "=== RESUMO ===\n";
echo "Migrados: {$migrados}\n";
echo "Já existentes (pulados): {$ja}\n";
echo "Erros: {$erros}\n";
