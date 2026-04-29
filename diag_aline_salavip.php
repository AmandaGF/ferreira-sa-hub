<?php
ini_set('display_errors','1'); error_reporting(E_ALL);
require_once __DIR__ . '/core/database.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit; }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "==== 1. Todos os clients com 'aline' no nome ====\n";
try {
    $st = $pdo->prepare("SELECT id, name, cpf, email, phone, created_at FROM clients WHERE name LIKE '%aline%' ORDER BY id");
    $st->execute();
    foreach ($st->fetchAll() as $r) {
        echo "  client_id={$r['id']} | {$r['name']} | cpf={$r['cpf']} | email={$r['email']} | phone={$r['phone']} | created={$r['created_at']}\n";
    }
} catch (Exception $e) { echo "  ERRO: " . $e->getMessage() . "\n"; }

echo "\n==== 2. Cases por client_id que tem 'aline' ====\n";
try {
    $st = $pdo->prepare("SELECT c.id, c.client_id, c.title, c.case_type, c.salavip_ativo, c.created_at
                         FROM cases c JOIN clients cl ON cl.id = c.client_id
                         WHERE cl.name LIKE '%aline%' ORDER BY c.client_id, c.id");
    $st->execute();
    foreach ($st->fetchAll() as $r) {
        echo "  case {$r['id']} (client_id={$r['client_id']}) | {$r['title']} | salavip_ativo={$r['salavip_ativo']} | created={$r['created_at']}\n";
    }
} catch (Exception $e) { echo "  ERRO: " . $e->getMessage() . "\n"; }

echo "\n==== 3. salavip_usuarios com 'aline' ====\n";
$svUsuarios = [];
try {
    $st = $pdo->prepare("SELECT id, cliente_id, nome_exibicao, cpf, email, ativo, criado_em FROM salavip_usuarios WHERE nome_exibicao LIKE '%aline%' OR cpf LIKE '%aline%' ORDER BY id");
    $st->execute();
    $svUsuarios = $st->fetchAll();
    foreach ($svUsuarios as $r) {
        echo "  salavip_user_id={$r['id']} -> cliente_id={$r['cliente_id']} | {$r['nome_exibicao']} | cpf={$r['cpf']} | ativo={$r['ativo']}\n";
    }
} catch (Exception $e) { echo "  ERRO: " . $e->getMessage() . "\n"; }

echo "\n==== 4. Pra CADA salavip_usuario da Aline, quantos cases retornam na query do meus_processos ====\n";
try {
    $queryMP = $pdo->prepare("SELECT id, title, salavip_ativo FROM cases WHERE client_id = ? AND salavip_ativo = 1 ORDER BY opened_at DESC");
    foreach ($svUsuarios as $u) {
        $queryMP->execute([(int)$u['cliente_id']]);
        $rows = $queryMP->fetchAll();
        echo "  salavip_user_id={$u['id']} (cliente_id={$u['cliente_id']}, {$u['nome_exibicao']}) → " . count($rows) . " caso(s)\n";
        foreach ($rows as $rr) {
            echo "      case {$rr['id']} | {$rr['title']} | salavip_ativo={$rr['salavip_ativo']}\n";
        }
    }
} catch (Exception $e) { echo "  ERRO: " . $e->getMessage() . "\n"; }

echo "\n==== 4b. Reproduz EXATAMENTE o KPI do dashboard.php (cliente_id=432) ====\n";
try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM cases WHERE client_id = ? AND salavip_ativo = 1 AND status NOT IN ('cancelado','arquivado')");
    $st->execute([432]);
    echo "  KPI dashboard: " . (int)$st->fetchColumn() . " caso(s)\n";
} catch (Exception $e) { echo "  ERRO: " . $e->getMessage() . "\n"; }

echo "\n==== 4c. Status de cada caso da Aline 432 ====\n";
try {
    $st = $pdo->prepare("SELECT id, title, status, salavip_ativo, kanban_oculto FROM cases WHERE client_id = ? ORDER BY id");
    $st->execute([432]);
    foreach ($st->fetchAll() as $r) {
        echo "  case {$r['id']} | {$r['title']} | status={$r['status']} | salavip_ativo={$r['salavip_ativo']} | kanban_oculto={$r['kanban_oculto']}\n";
    }
} catch (Exception $e) { echo "  ERRO: " . $e->getMessage() . "\n"; }

echo "\n==== 4d. case_andamentos do case 710 (visivel_cliente=1) ====\n";
try {
    $st = $pdo->prepare("SELECT id, data_andamento, descricao, visivel_cliente, created_at FROM case_andamentos WHERE case_id = 710 AND visivel_cliente = 1 ORDER BY data_andamento DESC, created_at DESC LIMIT 3");
    $st->execute();
    foreach ($st->fetchAll() as $r) {
        echo "  and {$r['id']} | data={$r['data_andamento']} | created={$r['created_at']}\n";
        echo "      desc=" . substr($r['descricao'] ?? '', 0, 100) . "\n";
    }
} catch (Exception $e) { echo "  ERRO: " . $e->getMessage() . "\n"; }

echo "\n==== 4e. Tenta CAPTURAR a renderizacao real do meus_processos.php pra cliente_id=432 ====\n";
echo "  (simulando sessao via injecao temporaria)\n";
$_SESSION['salavip_user_id'] = 103;
$_SESSION['salavip_cliente_id'] = 432;
$_SESSION['salavip_nome_exibicao'] = 'ALINE FERNANDES CARVALHO';
$_SESSION['salavip_cpf'] = '111.779.687-62';
$_SESSION['salavip_email'] = 'ac4730955@gmail.com';
$_SESSION['salavip_logado_em'] = date('Y-m-d H:i:s');
$_SESSION['salavip_ultimo_atividade'] = time();

ob_start();
try {
    define('SALAVIP_DIAG_INJECTED', 1);
    // chama via include numa "sandbox" - se der erro, captura
    chdir(__DIR__ . '/salavip_src');
    @include __DIR__ . '/salavip_src/pages/meus_processos.php';
} catch (Throwable $e) {
    echo "  EXCEPTION: " . $e->getMessage() . "\n";
}
$html = ob_get_clean();
$cardCount = substr_count($html, 'sv-card--processo');
echo "  Tamanho HTML capturado: " . strlen($html) . " bytes\n";
echo "  'sv-card--processo' aparece: $cardCount vez(es)\n";
echo "  'sv-empty' aparece: " . substr_count($html, 'sv-empty') . " vez(es)\n";
echo "  'processos ativos' aparece: " . substr_count($html, 'processos ativo') . " vez(es)\n";
// Procura erros no html
if (preg_match('/(Fatal error|Warning|Notice|Parse error)[^<\n]+/i', $html, $m)) {
    echo "  ERRO ENCONTRADO NO HTML: " . substr($m[0], 0, 200) . "\n";
}

echo "\n==== 5. Token de impersonate mais recente da Aline ====\n";
try {
    $st = $pdo->prepare("SELECT t.id, t.salavip_user_id, t.admin_user_id, t.usado_em, t.expira_em, t.criado_em, u.nome_exibicao, u.cliente_id
                         FROM salavip_impersonate_tokens t JOIN salavip_usuarios u ON u.id = t.salavip_user_id
                         WHERE u.nome_exibicao LIKE '%aline%' ORDER BY t.id DESC LIMIT 5");
    $st->execute();
    foreach ($st->fetchAll() as $r) {
        echo "  token id={$r['id']} | salavip_user_id={$r['salavip_user_id']} (cliente_id={$r['cliente_id']}, {$r['nome_exibicao']}) | usado={$r['usado_em']} | criado={$r['criado_em']}\n";
    }
} catch (Exception $e) { echo "  ERRO: " . $e->getMessage() . "\n"; }
