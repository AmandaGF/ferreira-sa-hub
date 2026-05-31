<?php
// migrar_zapi_fluxos_align.php
// Alinha o tipo de zapi_fluxo_execucao.conversa_id e zapi_conversa_valor.conversa_id
// pra bater com zapi_conversas.id (INT signed, NÃO unsigned).
//
// Por que: a migracao anterior (migrar_zapi_fluxos.php) criou as colunas como
// INT UNSIGNED, mas zapi_conversas.id e INT signed. JOIN entre elas funciona,
// mas MySQL pode nao usar o indice por causa do sign mismatch. Como as duas
// tabelas estao vazias (foram criadas agora ha pouco), ALTER MODIFY e
// instantaneo e seguro.
//
// Idempotente: verifica o tipo atual antes de mexer. Rodar 2x e seguro.
// NAO toca em zapi_conversas nem em nenhuma tabela existente.
//
// Disparar: curl -s "https://ferreiraesa.com.br/conecta/migrar_zapi_fluxos_align.php?key=fsa-hub-deploy-2026"

if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Alinhamento de tipos: zapi_fluxo_execucao.conversa_id / zapi_conversa_valor.conversa_id ===\n\n";

// Confirma tipo atual de zapi_conversas.id (verdade absoluta)
$ref = $pdo->query("SHOW COLUMNS FROM zapi_conversas LIKE 'id'")->fetch();
if (!$ref) {
    echo "ERRO: zapi_conversas nao encontrada. Abortando.\n";
    exit;
}
echo "Referencia zapi_conversas.id: Type='{$ref['Type']}' Null='{$ref['Null']}' Key='{$ref['Key']}' Extra='{$ref['Extra']}'\n\n";

$alvos = array('zapi_fluxo_execucao', 'zapi_conversa_valor');

foreach ($alvos as $tab) {
    echo "--- $tab.conversa_id ---\n";

    // Verifica existencia da tabela
    $existe = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($tab))->fetchColumn();
    if (!$existe) {
        echo "  [PULA] tabela nao existe (rode migrar_zapi_fluxos.php antes)\n\n";
        continue;
    }

    // Tipo atual da coluna
    $col = $pdo->query("SHOW COLUMNS FROM $tab LIKE 'conversa_id'")->fetch();
    if (!$col) {
        echo "  [PULA] coluna conversa_id nao existe em $tab\n\n";
        continue;
    }
    echo "  ANTES: Type='{$col['Type']}' Null='{$col['Null']}'\n";

    // Detecta UNSIGNED no Type
    $temUnsigned = (stripos($col['Type'], 'unsigned') !== false);
    if (!$temUnsigned) {
        echo "  [OK ja alinhado] coluna ja e signed\n\n";
        continue;
    }

    // Sanity: tabela DEVE estar vazia (foi criada ha pouco). Se nao estiver,
    // aborta esse alvo - alterar tipo com dados e' mais delicado, exige plano.
    $qtd = (int)$pdo->query("SELECT COUNT(*) FROM $tab")->fetchColumn();
    if ($qtd > 0) {
        echo "  [ABORT] $tab tem $qtd linhas - nao mexer automaticamente.\n";
        echo "          Migracao manual com plano de dados necessaria.\n\n";
        continue;
    }
    echo "  Linhas em $tab: 0 (seguro pra ALTER MODIFY)\n";

    try {
        $pdo->exec("ALTER TABLE $tab MODIFY conversa_id INT NOT NULL");
        $colDepois = $pdo->query("SHOW COLUMNS FROM $tab LIKE 'conversa_id'")->fetch();
        echo "  DEPOIS: Type='{$colDepois['Type']}' Null='{$colDepois['Null']}'\n";
        echo "  [OK alinhado]\n\n";
    } catch (PDOException $e) {
        error_log('[migrar_zapi_fluxos_align] ' . $tab . ': ' . $e->getMessage());
        echo "  [FALHA] " . $e->getMessage() . "\n\n";
    }
}

echo "--- Verificacao cruzada final ---\n";
$ref = $pdo->query("SHOW COLUMNS FROM zapi_conversas LIKE 'id'")->fetch();
echo "zapi_conversas.id              = {$ref['Type']}\n";
foreach ($alvos as $tab) {
    $col = $pdo->query("SHOW COLUMNS FROM $tab LIKE 'conversa_id'")->fetch();
    if ($col) {
        $match = (stripos($col['Type'], 'unsigned') === stripos($ref['Type'], 'unsigned')) ? 'OK' : 'MISMATCH';
        echo str_pad("$tab.conversa_id", 30) . " = {$col['Type']}  [$match]\n";
    }
}

echo "\n=== Fim ===\n";
