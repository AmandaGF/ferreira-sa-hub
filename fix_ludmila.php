<?php
/**
 * Fix Ludmila: cria cliente novo com dados corretos (CPF 139.063.907-05),
 * revincula lead #1391 e submission #753 pra ele.
 *
 * Ana Maria (#462) fica intacta — só perdeu o vínculo indevido.
 *
 * Idempotente: se rodar 2 vezes, pega o cliente já criado (dedup por CPF).
 * Amanda 06/07/2026.
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors', '1');
error_reporting(E_ALL);
while (ob_get_level() > 0) { ob_end_clean(); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    echo "\n[PHP ERROR $errno] $errstr em $errfile:$errline\n"; @flush();
    return false;
});
register_shutdown_function(function() {
    $e = error_get_last();
    if ($e && in_array($e['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR))) {
        echo "\n[FATAL] {$e['message']} em {$e['file']}:{$e['line']}\n";
    }
});

echo "=== FIX LUDMILA ===\n\n";

$SUBMISSION_ID = 753;
$LEAD_ID       = 1391;
$CLIENT_ERRADO = 462;   // Ana Maria — NÃO tocar
$CPF_LUDMILA   = '13906390705';

// 1) Confere o estado atual antes de mexer
echo "── Estado ANTES ──\n";
$st = $pdo->prepare("SELECT id, name, linked_client_id FROM form_submissions WHERE id = ?");
$st->execute(array($SUBMISSION_ID));
$sub = $st->fetch(PDO::FETCH_ASSOC);
if (!$sub) { die("✕ Submission #{$SUBMISSION_ID} nao encontrada. Abortando.\n"); }
echo "  Submission #{$SUBMISSION_ID} vinculada a cliente #{$sub['linked_client_id']}\n";

$st = $pdo->prepare("SELECT id, name, client_id, stage FROM pipeline_leads WHERE id = ?");
$st->execute(array($LEAD_ID));
$lead = $st->fetch(PDO::FETCH_ASSOC);
if (!$lead) { die("✕ Lead #{$LEAD_ID} nao encontrado. Abortando.\n"); }
echo "  Lead #{$LEAD_ID} '{$lead['name']}' vinculado a cliente #{$lead['client_id']} (stage: {$lead['stage']})\n";

if ((int)$sub['linked_client_id'] !== $CLIENT_ERRADO || (int)$lead['client_id'] !== $CLIENT_ERRADO) {
    echo "\n⚠ Estado inesperado — vinculo ja nao aponta pra #{$CLIENT_ERRADO}. Verificar antes de prosseguir.\n";
    echo "  submission.linked_client_id=" . $sub['linked_client_id'] . "\n";
    echo "  lead.client_id=" . $lead['client_id'] . "\n";
    echo "\nProsseguindo do mesmo jeito (idempotente).\n";
}
echo "\n";

// 2) Cria/pega cliente Ludmila (dedup por CPF, idempotente)
echo "── Criar/localizar cliente Ludmila ──\n";
$st = $pdo->prepare("SELECT id FROM clients WHERE REPLACE(REPLACE(REPLACE(cpf,'.',''),'-',''),' ','') = ? LIMIT 1");
$st->execute(array($CPF_LUDMILA));
$existingId = (int)$st->fetchColumn();

if ($existingId) {
    $CLIENT_NOVO = $existingId;
    echo "  ✓ Cliente Ludmila JA EXISTE (#{$CLIENT_NOVO}) — reusando (idempotente)\n\n";
} else {
    // Lê o payload da submission #753 pra recuperar os dados originais
    $st = $pdo->prepare("SELECT payload_json FROM form_submissions WHERE id = ?");
    $st->execute(array($SUBMISSION_ID));
    $payload = json_decode((string)$st->fetchColumn(), true) ?: array();

    // Mapeia campos do form pra colunas da tabela clients
    $nome    = $payload['nome']         ?? 'Ludmila Silva Nunes';
    $cpfFmt  = $payload['cpf']          ?? '139.063.907-05';
    $rg      = $payload['rg']           ?? null;
    $nasc    = $payload['nascimento']   ?? null;
    $prof    = $payload['profissao']    ?? null;
    $ec      = strtolower($payload['estado_civil'] ?? '');
    $tel     = $payload['celular']      ?? null;
    $email   = $payload['email']        ?? null;
    $cep     = $payload['cep']          ?? null;
    $rua     = trim(($payload['rua'] ?? '') . ($payload['numero'] ? ', ' . $payload['numero'] : ''));
    if (!empty($payload['bairro'])) $rua .= ' - ' . $payload['bairro'];
    $cidade  = $payload['cidade']       ?? null;
    $uf      = $payload['uf']           ?? null;
    $pix     = $payload['pix']          ?? null;
    $filhos  = !empty($payload['filhos']) && strtolower($payload['filhos']) === 'sim' ? 1 : 0;
    $nfilhos = $payload['nome_filhos']  ?? null;

    // Normaliza estado_civil pra vocabulario do sistema
    $ecMap = array('casado' => 'casado', 'solteiro' => 'solteiro', 'divorciado' => 'divorciado',
                   'viuvo' => 'viuvo', 'viúvo' => 'viuvo', 'uniao estavel' => 'uniao_estavel',
                   'união estável' => 'uniao_estavel');
    $ecFinal = $ecMap[$ec] ?? $ec;

    $notes = "Auto-cadastrado via formulario cadastro_cliente (protocolo CAD-8B9223722B, submission #{$SUBMISSION_ID}).\n"
           . "Remediado em " . date('d/m/Y H:i') . ": dedup por email tinha vinculado erradamente ao cliente #{$CLIENT_ERRADO} (Ana Maria Silveira da Silva, mae).\n"
           . "CPF diferente confirma que sao pessoas distintas — bug corrigido em form_handler.";

    $pdo->prepare(
        "INSERT INTO clients (name, cpf, rg, birth_date, phone, email, profession, marital_status,
                              gender, has_children, children_names,
                              address_street, address_city, address_state, address_zip,
                              pix_key, source, notes, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'feminino', ?, ?,
                 ?, ?, ?, ?, ?, 'formulario_remediado', ?, NOW())"
    )->execute(array(
        $nome, $cpfFmt, $rg, $nasc ?: null,
        $tel, $email, $prof, $ecFinal,
        $filhos, $nfilhos,
        $rua ?: null, $cidade, $uf, $cep,
        $pix, $notes
    ));
    $CLIENT_NOVO = (int)$pdo->lastInsertId();
    echo "  ✓ Cliente Ludmila CRIADO (#{$CLIENT_NOVO}) com CPF {$cpfFmt}\n\n";
}

// 3) Revincula lead #1391
echo "── Revincular lead #{$LEAD_ID} ──\n";
$pdo->prepare("UPDATE pipeline_leads SET client_id = ?, updated_at = NOW() WHERE id = ?")
    ->execute(array($CLIENT_NOVO, $LEAD_ID));
echo "  ✓ pipeline_leads.client_id: {$lead['client_id']} → {$CLIENT_NOVO}\n";

// 4) Revincula submission #753
echo "── Revincular submission #{$SUBMISSION_ID} ──\n";
$pdo->prepare("UPDATE form_submissions SET linked_client_id = ? WHERE id = ?")
    ->execute(array($CLIENT_NOVO, $SUBMISSION_ID));
echo "  ✓ form_submissions.linked_client_id: {$sub['linked_client_id']} → {$CLIENT_NOVO}\n\n";

// 5) Confirma o estado depois
echo "── Estado DEPOIS ──\n";
$st = $pdo->prepare("SELECT id, name, cpf, phone, email FROM clients WHERE id = ?");
$st->execute(array($CLIENT_NOVO));
$c = $st->fetch(PDO::FETCH_ASSOC);
echo "  Cliente Ludmila (#{$c['id']}):\n";
echo "    nome: {$c['name']} · cpf: {$c['cpf']} · tel: {$c['phone']} · email: {$c['email']}\n";

$st = $pdo->prepare("SELECT id, name, cpf FROM clients WHERE id = ?");
$st->execute(array($CLIENT_ERRADO));
$a = $st->fetch(PDO::FETCH_ASSOC);
echo "  Cliente Ana Maria (#{$a['id']}) — INTACTA:\n";
echo "    nome: {$a['name']} · cpf: {$a['cpf']}\n";

// 6) Log leve no audit_log (sem depender de current_user)
try {
    $pdo->prepare("INSERT INTO audit_log (user_id, action, entity_type, entity_id, description, created_at)
                   VALUES (NULL, ?, 'clients', ?, ?, NOW())")
        ->execute(array(
            'fix_ludmila_dedup', $CLIENT_NOVO,
            "Criou cliente novo Ludmila #{$CLIENT_NOVO} (CPF {$CPF_LUDMILA}), revinculou lead #{$LEAD_ID} e submission #{$SUBMISSION_ID}. Bug: dedup por email tinha colado no cliente #{$CLIENT_ERRADO} (Ana Maria)."
        ));
    echo "  ✓ Audit log gravado\n";
} catch (Exception $e) { echo "  (audit_log falhou: " . $e->getMessage() . ")\n"; }

echo "\n=== FIM ===\n";
echo "Agora abra o Kanban Comercial e a Maria consegue gerar procuracao no nome certo.\n";
