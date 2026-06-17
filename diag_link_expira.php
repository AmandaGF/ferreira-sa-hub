<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
ini_set('display_errors','1');
error_reporting(E_ALL);
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

$tokenAlvo = '4ff2a2658b89086c84239337fbdbead93239a6212909ebe069dec4012ba621b0';

echo "=== Procurando o token do print ===\n";
$st = $pdo->prepare("SELECT su.id, su.cliente_id, su.ativo, su.token_ativacao, su.token_expira, su.atualizado_em,
                            (su.senha_hash IS NOT NULL AND su.senha_hash != '') AS tem_senha,
                            c.name AS cliente_nome, c.email
                     FROM salavip_usuarios su
                     LEFT JOIN clients c ON c.id = su.cliente_id
                     WHERE su.token_ativacao = ?");
$st->execute(array($tokenAlvo));
$r = $st->fetch(PDO::FETCH_ASSOC);
if (!$r) {
    echo "  Token NAO ACHADO no banco. Pode ter sido sobrescrito por outro reenvio mais recente.\n";
    echo "  Vou listar os ultimos 5 reenvios:\n\n";
} else {
    echo "  ACHEI:\n";
    echo "  sv #{$r['id']} | cliente='{$r['cliente_nome']}' ({$r['email']})\n";
    echo "  ativo: " . ((int)$r['ativo']) . " | tem_senha: " . ($r['tem_senha'] ? 'SIM' : 'NAO') . "\n";
    echo "  token_expira: " . ($r['token_expira'] ?: '(NULL)') . "\n";
    echo "  agora     : " . date('Y-m-d H:i:s') . "\n";
    if ($r['token_expira']) {
        $expira = strtotime($r['token_expira']);
        $agora = time();
        $diff = $expira - $agora;
        if ($diff < 0) {
            echo "  ⚠️ JA EXPIROU ha " . round(abs($diff)/3600, 1) . "h\n";
        } else {
            echo "  ✓ Valido por mais " . round($diff/3600, 1) . "h\n";
        }
    }
    echo "  atualizado_em: {$r['atualizado_em']}\n\n";
}

echo "=== Audit log: tudo relacionado a Central VIP nas ultimas 4h ===\n";
$st = $pdo->prepare("SELECT al.criado_em, al.user_id, al.acao, al.entidade, al.entity_id, al.descricao, u.name AS user_name
                     FROM audit_log al
                     LEFT JOIN users u ON u.id = al.user_id
                     WHERE al.criado_em > DATE_SUB(NOW(), INTERVAL 4 HOUR)
                       AND (al.acao LIKE '%vip%' OR al.acao LIKE '%salavip%' OR al.acao LIKE '%reset%' OR al.acao LIKE '%reenviar%')
                     ORDER BY al.criado_em DESC LIMIT 20");
$st->execute();
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
if (!$rows) echo "  Nenhum evento.\n";
foreach ($rows as $a) {
    $u = $a['user_name'] ?: '(?)';
    echo "  {$a['criado_em']} | por $u | {$a['acao']} | ent={$a['entidade']}#{$a['entity_id']} | {$a['descricao']}\n";
}

echo "\n=== Onde ha codigo gerando link de ativacao (busca grep no arquivo) ===\n";
$files = array(
    'modules/salavip/acessos.php',
    'modules/crm/api.php',
    'modules/whatsapp/api.php',
    'modules/clientes/ver.php',
);
foreach ($files as $f) {
    $full = __DIR__ . '/' . $f;
    if (!is_file($full)) { echo "  [$f] (não existe)\n"; continue; }
    $cnt = $full ? @file_get_contents($full) : '';
    $linhas = explode("\n", $cnt);
    foreach ($linhas as $i => $l) {
        if (stripos($l, 'token_ativacao') !== false && stripos($l, 'UPDATE') !== false) {
            $temExpira = stripos($l, 'token_expira') !== false;
            echo "  [$f:" . ($i+1) . "] " . ($temExpira ? '✓ inclui token_expira' : '⚠️ NAO inclui token_expira!') . "\n";
            echo "    > " . trim(substr($l, 0, 180)) . "\n";
        }
    }
}
