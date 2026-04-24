<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('x'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Criar conversa pra Alícia (client_id=331) canal 24 ===\n\n";

// Confere se já não existe
$q = $pdo->prepare("SELECT id FROM zapi_conversas WHERE client_id = ? AND status != 'arquivado' LIMIT 1");
$q->execute(array(331));
$existe = $q->fetchColumn();
if ($existe) {
    echo "[SKIP] Alícia já tem conversa ativa #{$existe}\n";
    exit;
}

// Pega instância canal 24
$q = $pdo->query("SELECT id FROM zapi_instancias WHERE ddd = '24' LIMIT 1");
$instId = (int)$q->fetchColumn();
if (!$instId) { echo "[ERRO] instância DDD 24 não encontrada\n"; exit; }

// Cria conv
$pdo->prepare(
    "INSERT INTO zapi_conversas (instancia_id, telefone, nome_contato, client_id, canal, status, bot_ativo, eh_grupo)
     VALUES (?, ?, ?, ?, ?, 'aguardando', 0, 0)"
)->execute(array($instId, '5524998137649', 'Alícia de Carvalho Wogel', 331, '24'));

$novaConvId = (int)$pdo->lastInsertId();
echo "[OK] Conversa #{$novaConvId} criada\n";
echo "     client_id=331 (Alícia de Carvalho Wogel)\n";
echo "     canal=24 tel=5524998137649\n";
echo "     status=aguardando (aparece como aguardando resposta da equipe)\n";
echo "\nQuando a Alícia responder pelo WhatsApp, o webhook vai achar esta conv\n";
echo "via estratégia 0b (match por últimos 10 dígitos do telefone real).\n";
