<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
set_time_limit(120);
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

$aplicar = !empty($_GET['aplicar']);

echo "=== SCAN: partes com dados que Agenda de Contatos NAO tem ===\n";
echo "Modo: " . ($aplicar ? 'APLICAR sync retroativo' : 'SO SCAN (adicione &aplicar=1 pra aplicar)') . "\n\n";

// Puxa todas as partes com client_id, junto com dados do cliente atual
$sql = "SELECT cp.id AS parte_id, cp.case_id, cp.papel, cp.tipo_pessoa,
               cp.nome, cp.cpf, cp.rg, cp.nascimento, cp.profissao, cp.estado_civil,
               cp.razao_social, cp.cnpj, cp.email, cp.telefone, cp.endereco, cp.cidade, cp.uf, cp.cep,
               cp.client_id,
               c.name AS c_name, c.cpf AS c_cpf, c.rg AS c_rg, c.birth_date AS c_birth,
               c.profession AS c_prof, c.marital_status AS c_estciv, c.email AS c_email,
               c.phone AS c_phone, c.address_street AS c_end, c.address_city AS c_cidade,
               c.address_state AS c_uf, c.address_zip AS c_cep,
               cs.title AS case_title
        FROM case_partes cp
        JOIN clients c ON c.id = cp.client_id
        LEFT JOIN cases cs ON cs.id = cp.case_id
        WHERE cp.client_id IS NOT NULL AND cp.client_id > 0
        ORDER BY cp.id ASC";

$partes = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
echo "Total de partes com client_id: " . count($partes) . "\n\n";

// Contadores
$countPorCampo = array(
    'name' => 0, 'cpf' => 0, 'rg' => 0, 'birth_date' => 0, 'profession' => 0,
    'marital_status' => 0, 'email' => 0, 'phone' => 0,
    'address_street' => 0, 'address_city' => 0, 'address_state' => 0, 'address_zip' => 0,
);
$clientesQueGanhamDado = array();
$totalCamposUpdate = 0;
$partesCom = 0;
$linhasDetalhe = array();

foreach ($partes as $p) {
    $tipoJur = !empty($p['cnpj']) || !empty($p['razao_social']);
    $nomeParte = $tipoJur ? ($p['razao_social'] ?? '') : ($p['nome'] ?? '');
    $docParte  = $tipoJur ? ($p['cnpj'] ?? '')         : ($p['cpf']  ?? '');

    $mapa = array(
        'name'           => array($nomeParte, $p['c_name']),
        'cpf'            => array($docParte, $p['c_cpf']),
        'rg'             => array($p['rg'], $p['c_rg']),
        'birth_date'     => array($p['nascimento'], $p['c_birth']),
        'profession'     => array($p['profissao'], $p['c_prof']),
        'marital_status' => array($p['estado_civil'], $p['c_estciv']),
        'email'          => array($p['email'], $p['c_email']),
        'phone'          => array($p['telefone'], $p['c_phone']),
        'address_street' => array($p['endereco'], $p['c_end']),
        'address_city'   => array($p['cidade'], $p['c_cidade']),
        'address_state'  => array($p['uf'], $p['c_uf']),
        'address_zip'    => array($p['cep'], $p['c_cep']),
    );

    $camposNesta = array();
    foreach ($mapa as $col => list($valP, $valC)) {
        $valP = trim((string)$valP); $valC = trim((string)$valC);
        if ($valP === '' || $valC !== '') continue;
        $camposNesta[$col] = $valP;
        $countPorCampo[$col]++;
        $totalCamposUpdate++;
    }

    if (!empty($camposNesta)) {
        $partesCom++;
        $clientesQueGanhamDado[$p['client_id']] = true;
        if (count($linhasDetalhe) < 20) {
            $linhasDetalhe[] = sprintf("  parte#%d cliente#%d '%s' (%s) — vai adicionar: %s",
                $p['parte_id'], $p['client_id'], substr($p['case_title']??'',0,40), $p['papel'],
                implode(', ', array_keys($camposNesta)));
        }

        if ($aplicar) {
            sincronizar_parte_com_cliente($pdo, array(
                'nome'=>$p['nome'], 'cpf'=>$p['cpf'], 'rg'=>$p['rg'],
                'nascimento'=>$p['nascimento'], 'profissao'=>$p['profissao'],
                'estado_civil'=>$p['estado_civil'], 'razao_social'=>$p['razao_social'],
                'cnpj'=>$p['cnpj'], 'email'=>$p['email'], 'telefone'=>$p['telefone'],
                'endereco'=>$p['endereco'], 'cidade'=>$p['cidade'], 'uf'=>$p['uf'], 'cep'=>$p['cep'],
            ), (int)$p['client_id']);
        }
    }
}

echo "─── Resultados ───\n";
echo "  Partes com dados novos p/ sincronizar: $partesCom\n";
echo "  Clientes que ganhariam ao menos 1 dado: " . count($clientesQueGanhamDado) . "\n";
echo "  Total de campos a preencher: $totalCamposUpdate\n\n";

echo "─── Por campo ───\n";
arsort($countPorCampo);
foreach ($countPorCampo as $col => $qtd) {
    if ($qtd > 0) printf("  %-20s %d\n", $col, $qtd);
}

echo "\n─── Amostra (top 20) ───\n";
foreach ($linhasDetalhe as $l) echo $l . "\n";

if ($aplicar) {
    echo "\n─── APLICADO ───\n";
    echo "  Sync retroativo rodou. Verifique alguns clients pra confirmar.\n";
} else {
    echo "\n─── Como aplicar ───\n";
    echo "  Adicione &aplicar=1 na URL. Preenche SO campos vazios em clients — nao sobrescreve.\n";
}
