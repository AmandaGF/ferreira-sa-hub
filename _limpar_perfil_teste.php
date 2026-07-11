<?php
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

// Lista perfis suspeitos (nome curto, tudo zerado)
$rows = $pdo->query("SELECT id, nome, slug, ticket_min, ticket_max, verba_min, verba_max FROM presenca_perfil WHERE CHAR_LENGTH(nome) <= 2 OR (verba_min = 0 AND verba_max = 0 AND ticket_min IS NULL AND ticket_max IS NULL)")->fetchAll(PDO::FETCH_ASSOC);
echo "Perfis suspeitos:\n";
foreach ($rows as $r) print_r($r);
if (empty($rows)) { echo "  (nenhum)\n"; exit; }

// Verifica se está sendo usado em envio ou regra
foreach ($rows as $r) {
    $usoRegra = (int)$pdo->query("SELECT COUNT(*) FROM presenca_regra WHERE perfil_id = $r[id]")->fetchColumn();
    $usoEnvio = (int)$pdo->query("SELECT COUNT(*) FROM presenca_envio WHERE perfil_id = $r[id]")->fetchColumn();
    echo "  id=$r[id] '$r[nome]' → regras=$usoRegra envios=$usoEnvio\n";
    if ($usoEnvio > 0) { echo "  (pulando: tem envios vinculados)\n"; continue; }
    if ($usoRegra > 0) $pdo->prepare("DELETE FROM presenca_regra WHERE perfil_id = ?")->execute(array($r['id']));
    $pdo->prepare("DELETE FROM presenca_perfil WHERE id = ?")->execute(array($r['id']));
    echo "  ✓ removido (com $usoRegra regras associadas)\n";
}
echo "\nOK\n";
