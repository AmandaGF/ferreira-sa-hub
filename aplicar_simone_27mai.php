<?php
// 27/05/2026 — Amanda pediu:
// 1) tirar acesso da Simone (user#5) ao WhatsApp (todos) + CRM
// 2) deixar PREV so com cards onde ela e responsavel
// 3) banner global de instabilidade na plataforma

if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }

require_once __DIR__ . '/core/database.php';
$pdo = db();
header('Content-Type: text/plain; charset=utf-8');

$simoneId = 5;

echo "=== Aplicando ajustes da Simone (#$simoneId) ===\n\n";

// Bloqueia WhatsApp e CRM
$bloquear = array('whatsapp','whatsapp_21','whatsapp_24','crm');
foreach ($bloquear as $mod) {
    $pdo->prepare("INSERT INTO user_permissions (user_id, module, allowed) VALUES (?, ?, 0)
                   ON DUPLICATE KEY UPDATE allowed = 0")
        ->execute(array($simoneId, $mod));
    echo "  BLOCK  $mod\n";
}

// Libera prev_so_meus (forca filtro de responsavel no PREV)
$pdo->prepare("INSERT INTO user_permissions (user_id, module, allowed) VALUES (?, 'prev_so_meus', 1)
               ON DUPLICATE KEY UPDATE allowed = 1")
    ->execute(array($simoneId));
echo "  ALLOW  prev_so_meus (PREV filtrado por responsable_user_id = $simoneId)\n";

// Banner global
$msg = '⚠ Plataforma em ajustes — pode haver instabilidade nas próximas horas. Se algo travar, recarregue (Ctrl+Shift+R) ou avise a Amanda.';
$pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES ('aviso_global_msg', ?)
               ON DUPLICATE KEY UPDATE valor = VALUES(valor)")
    ->execute(array($msg));
echo "\n  BANNER ativado: \"$msg\"\n";

echo "\nPra desativar o banner depois: setar 'aviso_global_msg' = '' (vazio) em configuracoes.\n";
echo "Pra rodar de novo (idempotente): mesmo URL.\n";
