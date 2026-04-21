<?php
/**
 * Diagnóstico das fotos de perfil do WhatsApp.
 * Uso: curl "https://ferreiraesa.com.br/conecta/diag_fotos_wa.php?key=fsa-hub-deploy-2026"
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit; }
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== DIAG FOTOS DE PERFIL WhatsApp ===\n\n";

// Totais
$total    = (int)$pdo->query("SELECT COUNT(*) FROM zapi_conversas WHERE COALESCE(eh_grupo,0) = 0")->fetchColumn();
$comUrl   = (int)$pdo->query("SELECT COUNT(*) FROM zapi_conversas WHERE COALESCE(eh_grupo,0) = 0 AND foto_perfil_url IS NOT NULL AND foto_perfil_url != ''")->fetchColumn();
$semUrl   = $total - $comUrl;
$nunca    = (int)$pdo->query("SELECT COUNT(*) FROM zapi_conversas WHERE COALESCE(eh_grupo,0) = 0 AND foto_perfil_atualizada IS NULL")->fetchColumn();
$stale    = (int)$pdo->query("SELECT COUNT(*) FROM zapi_conversas WHERE COALESCE(eh_grupo,0) = 0 AND foto_perfil_atualizada < DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();

echo "Total conversas (sem grupos): $total\n";
echo "Com foto_perfil_url preenchida: $comUrl\n";
echo "SEM foto_perfil_url: $semUrl\n";
echo "Nunca sincronizado: $nunca\n";
echo "Sync > 7 dias atrás (stale): $stale\n\n";

// Clientes com foto local salva
$comFotoLocal = (int)$pdo->query("SELECT COUNT(*) FROM clients WHERE foto_path IS NOT NULL AND foto_path != ''")->fetchColumn();
echo "Clientes com foto_path local (Central VIP): $comFotoLocal\n\n";

// Amostras de conversas recentes
echo "--- 15 CONVERSAS RECENTES (com estado da foto) ---\n";
$recs = $pdo->query("
    SELECT co.id, co.nome_contato, co.telefone, co.client_id,
           (CASE WHEN co.foto_perfil_url IS NULL OR co.foto_perfil_url = '' THEN 'VAZIO' ELSE 'TEM URL' END) as estado_url,
           co.foto_perfil_atualizada,
           cl.foto_path as foto_cliente_local
    FROM zapi_conversas co
    LEFT JOIN clients cl ON cl.id = co.client_id
    WHERE COALESCE(co.eh_grupo,0) = 0
    ORDER BY co.id DESC
    LIMIT 15
")->fetchAll();

foreach ($recs as $r) {
    $foto = $r['foto_cliente_local'] ? '📁 LOCAL' : $r['estado_url'];
    $sync = $r['foto_perfil_atualizada'] ?: 'nunca';
    echo sprintf("#%-6d %-30s | cliente=%s | foto=%s | sync=%s\n",
        $r['id'],
        substr($r['nome_contato'] ?: '(sem nome)', 0, 30),
        $r['client_id'] ?: '-',
        $foto,
        $sync
    );
}

echo "\n--- Se muitos estão VAZIO/nunca: clicar no botão '🖼️ Atualizar fotos' ---\n";
echo "--- Se muitos tem URL mas não renderizam: URLs Z-API expiraram, precisa re-sync ---\n";
