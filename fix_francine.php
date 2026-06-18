<?php
/** Conserto Francine x Alex (#2473) — separa em 2 clientes.
 *  1) Limpa do #2473 (Alex) os dados que sao da Francine.
 *  2) Cria a Francine como cliente proprio (dados do form #700).
 *  3) Cria lead da Francine no kanban (cadastro_preenchido) + history.
 *  4) Re-vincula o form #700 a Francine.
 *  Idempotente: se a Francine ja existir, nao recria.
 *  curl "https://ferreiraesa.com.br/conecta/fix_francine.php?key=fsa-hub-deploy-2026"
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Forbidden.'); }
header('Content-Type: text/plain; charset=utf-8');
error_reporting(E_ALL); ini_set('display_errors', '1');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

$ALEX_ID = 2473;
$FORM_ID = 700;
$PHONE   = '(21) 98192-6615';

// Dados reais da Francine (do form #700)
$F = array(
    'name' => 'Francine Batista da Costa',
    'cpf' => '066.569.257-90',
    'rg' => null,
    'birth_date' => '2008-12-08',
    'phone' => $PHONE,
    'email' => 'francinebatista050@gmail.com',
    'profession' => 'Desempregado',
    'marital_status' => 'Solteiro',
    'has_children' => 1,
    'children_names' => 'Noah Batista dos Santos Sousa',
    'address_street' => 'Rua Cariris, nº 14 - Parque Sarapuí',
    'address_city' => 'Duque de Caxias',
    'address_state' => 'RJ',
    'address_zip' => '25056-030',
    'pix_key' => 'Francinebatista050@gmail.com',
);

try {
    $pdo->beginTransaction();

    // ── 1. Limpa do Alex (#2473) os dados que sao da Francine ──
    $pdo->prepare(
        "UPDATE clients SET cpf=NULL, birth_date=NULL, profession=NULL, marital_status=NULL,
             has_children=NULL, children_names=NULL, pix_key=NULL,
             address_street=NULL, address_city=NULL, address_state=NULL, address_zip=NULL,
             updated_at=NOW()
         WHERE id = ?"
    )->execute(array($ALEX_ID));
    echo "1) Limpou dados da Francine do cliente #$ALEX_ID (Alex). Mantido: nome, email, telefone, foto.\n";

    // ── 2. Cria a Francine (se ainda nao existir) ──
    $chk = $pdo->prepare("SELECT id FROM clients WHERE name = ? AND cpf = ? LIMIT 1");
    $chk->execute(array($F['name'], $F['cpf']));
    $francineId = $chk->fetchColumn();

    if ($francineId) {
        echo "2) Francine ja existia como cliente #$francineId — nao recriou.\n";
    } else {
        $pdo->prepare(
            "INSERT INTO clients (name, cpf, rg, birth_date, phone, email, profession, marital_status,
                 has_children, children_names, address_street, address_city, address_state, address_zip,
                 pix_key, source, notes, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'landing', 'Separado do #$ALEX_ID (mesmo telefone do Alex)', NOW())"
        )->execute(array(
            $F['name'], $F['cpf'], $F['rg'], $F['birth_date'], $F['phone'], $F['email'],
            $F['profession'], $F['marital_status'], $F['has_children'], $F['children_names'],
            $F['address_street'], $F['address_city'], $F['address_state'], $F['address_zip'], $F['pix_key'],
        ));
        $francineId = (int)$pdo->lastInsertId();
        echo "2) Criou a Francine como cliente #$francineId.\n";
    }

    // ── 3. Cria o lead da Francine no kanban (se ainda nao existir) ──
    $chkL = $pdo->prepare("SELECT id FROM pipeline_leads WHERE client_id = ? LIMIT 1");
    $chkL->execute(array($francineId));
    $leadId = $chkL->fetchColumn();
    if ($leadId) {
        echo "3) Lead da Francine ja existia (#$leadId) — nao recriou.\n";
    } else {
        $pdo->prepare(
            "INSERT INTO pipeline_leads (name, phone, email, source, stage, client_id, notes, created_at)
             VALUES (?, ?, ?, 'landing', 'cadastro_preenchido', ?, 'Cadastro via formulario (separado do Alex #$ALEX_ID)', '2026-06-18 13:07:58')"
        )->execute(array($F['name'], $F['phone'], $F['email'], $francineId));
        $leadId = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO pipeline_history (lead_id, to_stage, created_at) VALUES (?, 'cadastro_preenchido', NOW())")
            ->execute(array($leadId));
        echo "3) Criou o lead da Francine #$leadId no estagio 'cadastro_preenchido'.\n";
    }

    // ── 4. Re-vincula o form #700 a Francine ──
    $pdo->prepare("UPDATE form_submissions SET linked_client_id = ? WHERE id = ?")
        ->execute(array($francineId, $FORM_ID));
    echo "4) Form #$FORM_ID re-vinculado ao cliente #$francineId (Francine).\n";

    $pdo->commit();
    echo "\nOK — concluido. Francine = cliente #$francineId, lead #$leadId.\n";
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "ERRO (rollback): " . $e->getMessage() . "\n";
}
