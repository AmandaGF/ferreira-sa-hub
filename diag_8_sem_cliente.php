<?php
/**
 * Diag: investiga os 8 clientes que não bateram na simulação da migração PREV.
 * URL: /diag_8_sem_cliente.php?key=fsa-hub-deploy-2026
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$busca = array(
    'Eliane Rosalina'                  => array('eliane', 'rosalin'),
    'Jose Herickson'                   => array('herickson', 'erickson'),
    'Luciana (BPC Kaio)'               => array('kaio', 'luciana'),
    'Elaine Cristina Bilha'            => array('bilha', 'elaine'),
    'Luciana Berteges'                 => array('berteges', 'bertege'),
    'Jorge Antônio Peñaranda Panda'    => array('panda', 'penaranda', 'peñaranda'),
);

foreach ($busca as $nomeOriginal => $termos) {
    echo "\n========== {$nomeOriginal} ==========\n";
    foreach ($termos as $t) {
        $st = $pdo->prepare("SELECT id, name, cpf, phone, created_at FROM clients WHERE name LIKE ? ORDER BY id LIMIT 10");
        $st->execute(array('%' . $t . '%'));
        $rows = $st->fetchAll();
        if ($rows) {
            echo "  Termo '$t' → " . count($rows) . " matches:\n";
            foreach ($rows as $r) {
                $age = $r['created_at'] ? substr($r['created_at'], 0, 10) : '?';
                printf("    #%-5d %s  CPF=%s tel=%s criado=%s\n", $r['id'], mb_substr($r['name'], 0, 50), $r['cpf'] ?: '-', $r['phone'] ?: '-', $age);
            }
        }
    }
}

echo "\n\n========== BÔNUS: Beatriz Peñaranda (já matched) — pra ver se há outros Peñaranda ==========\n";
$st = $pdo->prepare("SELECT id, name FROM clients WHERE name LIKE '%aranda%' OR name LIKE '%enaranda%'");
$st->execute();
foreach ($st->fetchAll() as $r) printf("  #%-5d %s\n", $r['id'], $r['name']);
