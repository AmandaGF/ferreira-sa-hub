<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
ini_set('display_errors', '1');
error_reporting(E_ALL);
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Renata da Silva de Amorim ===\n";
$st = $pdo->prepare("SELECT su.id, su.cliente_id, su.ativo, su.ultimo_acesso, su.criado_em, su.atualizado_em,
                            (su.senha_hash IS NOT NULL AND su.senha_hash != '') AS tem_senha,
                            (su.token_ativacao IS NOT NULL AND su.token_ativacao != '') AS tem_token,
                            su.token_expira, c.name AS cliente_nome, c.email
                     FROM salavip_usuarios su
                     JOIN clients c ON c.id = su.cliente_id
                     WHERE c.name LIKE '%Renata%Amorim%' OR c.name LIKE '%Renata da Silva%'
                     ORDER BY su.id DESC");
$st->execute();
foreach ($st->fetchAll() as $r) {
    echo "  ID sv: {$r['id']} | cliente_id: {$r['cliente_id']}\n";
    echo "  Cliente: {$r['cliente_nome']} ({$r['email']})\n";
    echo "  ativo: " . ((int)$r['ativo']) . " | tem_senha: " . ($r['tem_senha'] ? 'SIM' : 'NAO') . " | tem_token_pendente: " . ($r['tem_token'] ? 'SIM' : 'NAO') . "\n";
    echo "  ultimo_acesso: " . ($r['ultimo_acesso'] ?: '(nunca)') . "\n";
    echo "  criado em: {$r['criado_em']} | atualizado em: {$r['atualizado_em']}\n";
    echo "  token_expira: " . ($r['token_expira'] ?: '—') . "\n\n";
}

echo "=== Audit log relacionado (ultimos 20) ===\n";
$st = $pdo->prepare("SELECT al.criado_em, al.user_id, al.acao, al.descricao, al.entity_id, u.name AS usuario
                     FROM audit_log al
                     LEFT JOIN users u ON u.id = al.user_id
                     WHERE (al.acao LIKE '%salavip%' OR al.acao LIKE '%reset%' OR al.acao LIKE '%vip%')
                        AND al.descricao LIKE '%Renata%'
                     ORDER BY al.criado_em DESC LIMIT 20");
$st->execute();
foreach ($st->fetchAll() as $r) {
    echo "  {$r['criado_em']} | por {$r['usuario']} | {$r['acao']} | {$r['descricao']}\n";
}

echo "\n=== Total de salavip_usuarios com tem_senha=SIM mas ativo=0 (suspeitos de bug) ===\n";
$n = (int)$pdo->query("SELECT COUNT(*) FROM salavip_usuarios WHERE ativo=0 AND senha_hash IS NOT NULL AND senha_hash != ''")->fetchColumn();
echo "  $n usuario(s) com senha cadastrada mas marcados como bloqueados.\n";
echo "  (provavelmente bug do reset_salavip que mete ativo=0 mesmo apos ativacao)\n";

$nComUltAcesso = (int)$pdo->query("SELECT COUNT(*) FROM salavip_usuarios WHERE ativo=0 AND ultimo_acesso IS NOT NULL")->fetchColumn();
echo "  $nComUltAcesso desses ja LOGARAM ao menos uma vez (forte indicio de bug).\n";
