<?php
/**
 * Diag: pergunta pra Z-API se um numero tem WhatsApp ativo.
 * Usa o endpoint /phone-exists -- mesma checagem que o WhatsApp Business faz
 * antes de mandar msg. Z-API consulta o servidor da Meta.
 *
 * Uso: ?key=XXX&phone=34661457631
 *      ?key=XXX&phone=+34661457631
 *      ?key=XXX&phone=21999999999&ddd=24
 */
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_zapi.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
header('Content-Type: text/plain; charset=utf-8');

$phone = trim($_GET['phone'] ?? '');
$ddd = trim($_GET['ddd'] ?? '24'); // default canal 24 (CX/Operacional)
if (!$phone) { echo "Use ?phone=NUMERO&ddd=21|24\n"; exit; }

echo "=== CONSULTA Z-API /phone-exists ===\n";
echo "Telefone consultado: $phone\n";
echo "Instancia (DDD canal): $ddd\n\n";

$resp = zapi_phone_exists($ddd, $phone);

echo "RESPOSTA:\n";
echo "  exists:    " . ($resp['exists'] ? 'TRUE (numero tem WhatsApp ativo)' : 'FALSE (numero NAO tem WhatsApp)') . "\n";
echo "  phone:     " . ($resp['phone'] ?? '—') . "\n";
echo "  lid:       " . ($resp['lid'] ?? '—') . "\n";
echo "  http_code: " . ($resp['http_code'] ?? '—') . "\n";
echo "  erro:      " . ($resp['erro'] ?? '—') . "\n\n";

echo "=== INTERPRETACAO ===\n";
if ($resp['exists']) {
    echo "OK: Z-API confirma que o numero tem WhatsApp ativo.\n";
    echo "Se mensagens ainda nao chegam, problema pode ser:\n";
    echo "  - Restricao Meta Business (account level)\n";
    echo "  - Cliente bloqueou o WhatsApp do escritorio\n";
    echo "  - Cliente esta em pais com restricao Meta API\n";
} else {
    echo "Z-API diz que o numero NAO tem WhatsApp ativo.\n";
    echo "Possiveis causas:\n";
    echo "  - Numero correto mas conta WA inativa/excluida\n";
    echo "  - Numero esta errado (digite trocado, etc)\n";
    echo "  - Cliente usa WA com OUTRO numero\n";
}
