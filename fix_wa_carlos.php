<?php
/**
 * Reparo pontual: vincula conversas WA do Carlos Antônio ao cliente.
 * Dry-run default. Pra aplicar: &aplicar=1
 *
 * ferreiraesa.com.br/conecta/fix_wa_carlos.php?key=fsa-hub-deploy-2026
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();
$aplicar = ($_GET['aplicar'] ?? '0') === '1';

echo "=== Reparo WA — Carlos Antônio ===\n";
echo $aplicar ? "MODO: APLICAR\n\n" : "MODO: DRY-RUN (use &aplicar=1 pra valer)\n\n";

// 1. Localiza Carlos Antônio
$st = $pdo->query("SELECT id, name, phone, phone2 FROM clients WHERE name LIKE 'Carlos Ant%' ORDER BY name");
$candidatos = $st->fetchAll();
if (empty($candidatos)) { echo "Nenhum Carlos Antonio encontrado.\n"; exit; }

echo "Carlos encontrados:\n";
foreach ($candidatos as $c) {
    echo "  #" . $c['id'] . " — " . $c['name'] . " | phone=" . ($c['phone'] ?: '-') . " | phone2=" . ($c['phone2'] ?: '-') . "\n";
}
echo "\n";

foreach ($candidatos as $c) {
    $clientId = (int)$c['id'];
    echo "--- Processando #{$clientId} ({$c['name']}) ---\n";

    $telefones = array();
    foreach (array($c['phone'], $c['phone2']) as $tel) {
        $tel = trim((string)$tel);
        if ($tel === '') continue;
        $digitos = preg_replace('/\D/', '', $tel);
        if (strlen($digitos) < 8) continue;
        $telefones[] = substr($digitos, -8);
    }
    $telefones = array_unique($telefones);
    echo "  Sufixos: [" . implode(', ', $telefones) . "]\n";

    foreach ($telefones as $sufixo) {
        $stConv = $pdo->prepare(
            "SELECT id, telefone, nome_contato, client_id, canal, ultima_mensagem, ultima_msg_em
             FROM zapi_conversas
             WHERE COALESCE(eh_grupo,0) = 0
               AND RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(telefone,' ',''),'-',''),'(',''),')',''), 8) = ?
             ORDER BY ultima_msg_em DESC"
        );
        $stConv->execute(array($sufixo));
        $convs = $stConv->fetchAll();
        if (empty($convs)) {
            echo "  [sufixo {$sufixo}] nenhuma conversa WA com esse numero.\n";
            continue;
        }
        echo "  [sufixo {$sufixo}] " . count($convs) . " conversa(s):\n";
        foreach ($convs as $cv) {
            $statusVinculo = '';
            if ($cv['client_id'] == $clientId) $statusVinculo = 'JA VINCULADA';
            else if ($cv['client_id']) $statusVinculo = 'vinculada a OUTRO client_id=' . $cv['client_id'];
            else $statusVinculo = 'ORFA (client_id=NULL) — VINCULAR';

            echo "    conv#{$cv['id']} canal={$cv['canal']} tel={$cv['telefone']} contato='" . substr((string)$cv['nome_contato'], 0, 30) . "' ult='" . substr((string)$cv['ultima_mensagem'], 0, 40) . "' [{$statusVinculo}]\n";

            if ($aplicar && $cv['client_id'] != $clientId) {
                $pdo->prepare("UPDATE zapi_conversas SET client_id = ? WHERE id = ?")->execute(array($clientId, $cv['id']));
                echo "      -> VINCULADO ao cliente #{$clientId}\n";
            }
        }
    }
    echo "\n";
}

echo "[FIM]\n";
echo $aplicar ? "Aplicado. Recarregue o caso e o WhatsApp pra ver o resultado.\n" : "Rodou em DRY-RUN. Se os candidatos acima sao os corretos, rode de novo com &aplicar=1.\n";
