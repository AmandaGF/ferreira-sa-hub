<?php
/**
 * Diag v2: clientes com docs GED mas sem acesso ativo na Central VIP.
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
error_reporting(E_ALL); ini_set('display_errors', '1');
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "=== AUDITORIA: Clientes com docs GED que NAO conseguem acessar ===\n\n";

// Pega todos os cliente_id que tem ao menos 1 doc visivel
$rows = $pdo->query("
    SELECT g.cliente_id, c.name AS cliente_nome, c.email, c.phone,
           COUNT(*) AS qtd_docs,
           MAX(g.compartilhado_em) AS ultimo_doc_em
    FROM salavip_ged g
    LEFT JOIN clients c ON c.id = g.cliente_id
    WHERE g.visivel_cliente = 1
    GROUP BY g.cliente_id, c.name, c.email, c.phone
    ORDER BY ultimo_doc_em DESC
")->fetchAll();

$semUsuario = array();
$inativos   = array();
$ativos     = array();

foreach ($rows as $r) {
    $u = $pdo->prepare("SELECT id, email, ativo, ultimo_acesso, token_ativacao FROM salavip_usuarios WHERE cliente_id = ? ORDER BY id DESC LIMIT 1");
    $u->execute([$r['cliente_id']]);
    $uRow = $u->fetch();

    if (!$uRow) {
        $semUsuario[] = $r;
    } elseif ((int)$uRow['ativo'] === 0) {
        $r['_user'] = $uRow;
        $inativos[] = $r;
    } else {
        $r['_user'] = $uRow;
        $ativos[] = $r;
    }
}

echo "📊 RESUMO\n";
echo "  Clientes com docs visiveis: " . count($rows) . "\n";
echo "  ✅ ATIVOS (estao acessando ou podem acessar): " . count($ativos) . "\n";
echo "  ⚠️  INATIVOS (precisam ativar conta - email de convite nao foi clicado): " . count($inativos) . "\n";
echo "  ❌ SEM USUARIO CADASTRADO (nunca foi criado acesso): " . count($semUsuario) . "\n";

if ($inativos) {
    echo "\n\n⚠️  CLIENTES INATIVOS COM DOCS ESPERANDO ACESSO:\n";
    echo "(receberam docs mas nao ativaram a conta da Central VIP)\n\n";
    foreach ($inativos as $r) {
        printf("cliente#%-5d | %s\n", $r['cliente_id'], $r['cliente_nome']);
        printf("    email-cliente: %s\n", $r['email'] ?: '(sem email no cadastro)');
        printf("    email-vip:     %s\n", $r['_user']['email'] ?: '(sem email no usuario VIP)');
        printf("    %d docs · ultimo em %s · user VIP #%d (token_ativacao=%s)\n\n",
            $r['qtd_docs'], $r['ultimo_doc_em'], $r['_user']['id'], !empty($r['_user']['token_ativacao']) ? 'sim' : 'nao');
    }
}

if ($semUsuario) {
    echo "\n\n❌ CLIENTES SEM USUARIO CADASTRADO NA CENTRAL VIP:\n";
    echo "(receberam docs mas nem existe conta para eles logarem)\n\n";
    foreach ($semUsuario as $r) {
        printf("cliente#%-5d | %s\n", $r['cliente_id'], $r['cliente_nome']);
        printf("    email: %s · telefone: %s\n", $r['email'] ?: '(sem email)', $r['phone'] ?: '(sem telefone)');
        printf("    %d docs · ultimo em %s\n\n", $r['qtd_docs'], $r['ultimo_doc_em']);
    }
}

if ($ativos) {
    echo "\n\n✅ CLIENTES OK (com usuario ATIVO):\n\n";
    foreach ($ativos as $r) {
        $ultAcc = $r['_user']['ultimo_acesso'] ?? null;
        $jaAcessou = $ultAcc ? "✓ ultimo acesso $ultAcc" : "ainda nao logou apos ativar";
        printf("  cliente#%-5d %-30s docs=%d %s\n", $r['cliente_id'], mb_substr($r['cliente_nome'], 0, 30), $r['qtd_docs'], $jaAcessou);
    }
}

echo "\n\nFIM.\n";
