<?php
/**
 * Diag: cadastro_cliente gravando VARIAS linhas por envio (duplicacao).
 * Mostra timestamp ao segundo, IP, user-agent e se os payloads sao identicos,
 * pra distinguir "navegador reenviou" de "servidor inseriu em loop".
 *
 * Uso: curl "https://ferreiraesa.com.br/conecta/diag_form_dup.php?key=fsa-hub-deploy-2026"
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Forbidden.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

// 1. Grupos de duplicatas (mesmo nome+telefone no mesmo minuto)
echo "=== 1. Grupos de duplicatas (cadastro_cliente) ===\n";
$grp = $pdo->query(
    "SELECT client_name, client_phone,
            DATE_FORMAT(created_at, '%Y-%m-%d %H:%i') AS minuto,
            COUNT(*) AS qt,
            MIN(created_at) AS t0, MAX(created_at) AS t1,
            COUNT(DISTINCT ip_address) AS ips,
            COUNT(DISTINCT payload_json) AS payloads_distintos
     FROM form_submissions
     WHERE form_type = 'cadastro_cliente'
     GROUP BY client_name, client_phone, minuto
     HAVING qt > 1
     ORDER BY t1 DESC LIMIT 15"
)->fetchAll();
if (!$grp) { echo "Nenhum grupo duplicado encontrado.\n"; }
foreach ($grp as $g) {
    echo sprintf("%-28s %-16s %sx  [%s..%s]  ips=%s  payloads_distintos=%s\n",
        substr($g['client_name'], 0, 28), $g['client_phone'], $g['qt'],
        substr($g['t0'], 11), substr($g['t1'], 11), $g['ips'], $g['payloads_distintos']);
}
echo "\n";

// 2. Detalhe das ultimas 12 linhas (pra ver segundos, IP, UA)
echo "=== 2. Ultimas 12 linhas (detalhe) ===\n";
$rows = $pdo->query(
    "SELECT id, protocol, created_at, client_name, client_phone, ip_address,
            LEFT(user_agent, 40) AS ua, LENGTH(payload_json) AS tam_payload
     FROM form_submissions WHERE form_type = 'cadastro_cliente'
     ORDER BY id DESC LIMIT 12"
)->fetchAll();
foreach ($rows as $r) {
    echo sprintf("#%-6s %-15s %s  %-22s %-15s ip=%-15s ua=%s tam=%s\n",
        $r['id'], $r['protocol'], $r['created_at'], substr($r['client_name'], 0, 22),
        $r['client_phone'], $r['ip_address'] ?: '?', $r['ua'] ?: '?', $r['tam_payload']);
}
echo "\n=== FIM ===\n";
