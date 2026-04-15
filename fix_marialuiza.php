<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') die('Acesso negado.');
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

// Atualizar dados da Leidiane (#2311) com dados do formulário
$f = $pdo->query("SELECT payload_json FROM form_submissions WHERE linked_client_id = 2311 AND form_type = 'cadastro_cliente' ORDER BY created_at DESC LIMIT 1")->fetchColumn();
if ($f) {
    $d = json_decode($f, true);
    echo "Payload encontrado:\n";
    echo "  Telefone: " . ($d['celular'] ?? '?') . "\n";
    echo "  Endereço: " . ($d['endereco'] ?? ($d['rua'] ?? '?')) . "\n";
    echo "  Cidade: " . ($d['cidade'] ?? '?') . "\n";
    echo "  UF: " . ($d['uf'] ?? '?') . "\n";
    echo "  CEP: " . ($d['cep'] ?? '?') . "\n";
    echo "  Profissão: " . ($d['profissao'] ?? '?') . "\n";
    echo "  Estado civil: " . ($d['estado_civil'] ?? '?') . "\n";
    echo "  Nascimento: " . ($d['nascimento'] ?? '?') . "\n";
    echo "  RG: " . ($d['rg'] ?? '?') . "\n";

    // Montar endereço
    $rua = $d['rua'] ?? '';
    $num = $d['numero'] ?? '';
    $comp = $d['complemento'] ?? '';
    $bairro = $d['bairro'] ?? '';
    $street = $rua;
    if ($num) $street .= ', nº ' . $num;
    if ($comp) $street .= ', ' . $comp;
    if ($bairro) $street .= ' - ' . $bairro;
    if (!$street) $street = $d['endereco'] ?? '';

    $pdo->prepare("UPDATE clients SET
        phone = COALESCE(NULLIF(phone,''), ?),
        address_street = COALESCE(NULLIF(address_street,''), ?),
        address_city = COALESCE(NULLIF(address_city,''), ?),
        address_state = COALESCE(NULLIF(address_state,''), ?),
        address_zip = COALESCE(NULLIF(address_zip,''), ?),
        profession = COALESCE(NULLIF(profession,''), ?),
        marital_status = COALESCE(NULLIF(marital_status,''), ?),
        birth_date = COALESCE(birth_date, ?),
        rg = COALESCE(NULLIF(rg,''), ?),
        pix_key = COALESCE(NULLIF(pix_key,''), ?)
        WHERE id = 2311"
    )->execute(array(
        $d['celular'] ?? null,
        $street ?: null,
        $d['cidade'] ?? null,
        $d['uf'] ?? null,
        $d['cep'] ?? null,
        $d['profissao'] ?? null,
        $d['estado_civil'] ?? null,
        ($d['nascimento'] ?? null) ?: null,
        $d['rg'] ?? null,
        $d['pix'] ?? null,
    ));
    echo "\n=== Client #2311 atualizado com dados do formulário ===\n";
} else {
    echo "Nenhum formulário encontrado para client #2311\n";
}
exit;

echo "=== Diagnóstico Maria Luiza De Assis (Client #2309) ===\n\n";

// Verificar dependências antes de excluir
$checks = array(
    'cases' => "SELECT id, title FROM cases WHERE client_id = 2309",
    'pipeline_leads' => "SELECT id, stage FROM pipeline_leads WHERE client_id = 2309",
    'form_submissions' => "SELECT id, form_type, protocol FROM form_submissions WHERE linked_client_id = 2309",
    'case_partes' => "SELECT id, case_id FROM case_partes WHERE client_id = 2309",
    'salavip_usuarios' => "SELECT id FROM salavip_usuarios WHERE client_id = 2309",
    'salavip_mensagens' => "SELECT id FROM salavip_mensagens WHERE cliente_id = 2309",
);

foreach ($checks as $tabela => $sql) {
    try {
        $rows = $pdo->query($sql)->fetchAll();
        echo "{$tabela}: " . count($rows) . "\n";
        foreach ($rows as $r) {
            echo "  " . json_encode($r) . "\n";
        }
    } catch (Exception $e) {
        echo "{$tabela}: SKIP ({$e->getMessage()})\n";
    }
}

// Client info
$c = $pdo->query("SELECT id, name, cpf, phone, email FROM clients WHERE id = 2309")->fetch();
echo "\nClient #2309: " . ($c ? $c['name'] . " | CPF=" . $c['cpf'] : 'NÃO EXISTE') . "\n";

// Leidiane info
$l = $pdo->query("SELECT id, name, cpf FROM clients WHERE id = 2311")->fetch();
echo "Client #2311: " . ($l ? $l['name'] . " | CPF=" . $l['cpf'] : 'NÃO EXISTE') . "\n";

if (isset($_GET['fix'])) {
    echo "\n=== EXCLUINDO Client #2309 (Maria Luiza) ===\n";

    // Revincular form_submissions ao client correto (Leidiane #2311)
    $n = $pdo->exec("UPDATE form_submissions SET linked_client_id = 2311 WHERE linked_client_id = 2309");
    echo "form_submissions revinculados: {$n}\n";

    // Revincular leads
    $n2 = $pdo->exec("UPDATE pipeline_leads SET client_id = 2311 WHERE client_id = 2309");
    echo "pipeline_leads revinculados: {$n2}\n";

    // Excluir client
    $pdo->exec("DELETE FROM clients WHERE id = 2309");
    echo "Client #2309 excluído.\n";

    echo "\n=== CONCLUÍDO ===\n";
} else {
    echo "\nAdicione &fix=1 para excluir.\n";
}
