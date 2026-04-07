<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave invalida'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Fix: Atualizar mensagem das audiências já agendadas ===\n\n";

// Buscar audiências futuras que têm msg_cliente sem o link de audiências
$stmt = $pdo->query("SELECT id, titulo, msg_cliente, data_inicio FROM agenda_eventos
    WHERE tipo = 'audiencia'
    AND data_inicio >= NOW()
    AND msg_cliente IS NOT NULL
    AND msg_cliente != ''
    AND msg_cliente NOT LIKE '%ferreiraesa.com.br/audiencias%'");
$audiencias = $stmt->fetchAll();

echo "Audiências futuras sem link: " . count($audiencias) . "\n\n";

$fixed = 0;
foreach ($audiencias as $a) {
    $msgOriginal = $a['msg_cliente'];
    // Inserir o bloco de link antes de "Qualquer dúvida" ou no final
    $linkBloco = "\n\n*IMPORTANTE:* Acesse o link abaixo para informações essenciais sobre sua audiência:\nhttps://www.ferreiraesa.com.br/audiencias/";

    if (strpos($msgOriginal, 'Qualquer') !== false) {
        $msgNova = str_replace('Qualquer', $linkBloco . "\n\nQualquer", $msgOriginal);
    } else {
        $msgNova = $msgOriginal . $linkBloco;
    }

    $pdo->prepare("UPDATE agenda_eventos SET msg_cliente = ? WHERE id = ?")->execute(array($msgNova, $a['id']));
    echo "#{$a['id']} {$a['titulo']} ({$a['data_inicio']}) — ATUALIZADO\n";
    $fixed++;
}

echo "\n$fixed audiência(s) atualizada(s).\n=== FEITO ===\n";
