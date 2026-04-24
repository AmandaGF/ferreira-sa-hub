<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('x'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== FIX Eduarda (client_id=652) ===\n\n";

// 1) Arquivar conv 648 (errada — @lid 188102421278840 é outra pessoa)
echo "--- Arquivando conv 648 (era de outra pessoa vinculada erradamente) ---\n";
$pdo->prepare("UPDATE zapi_conversas SET client_id = NULL, status = 'arquivado' WHERE id = 648")->execute();
echo "[OK] Conv 648 desvinculada e arquivada\n\n";

// 2) Criar conv nova canal 24 com dados corretos da Eduarda
echo "--- Criando conv nova com @lid real da Eduarda (29910705934341@lid) ---\n";

// Verifica se já existe conv ativa com o tel 5521973698089 que não seja a 648
$q = $pdo->prepare("SELECT id FROM zapi_conversas WHERE telefone = ? AND canal = '24' AND id != 648 AND status != 'arquivado' LIMIT 1");
$q->execute(array('5521973698089'));
$ja = $q->fetchColumn();
if ($ja) {
    echo "[SKIP] Já existe conv ativa #{$ja} com esse telefone. Vinculando Eduarda nela.\n";
    $pdo->prepare("UPDATE zapi_conversas SET client_id = 652, chat_lid = '29910705934341@lid' WHERE id = ?")->execute(array($ja));
    echo "[OK] Conv #{$ja} vinculada à Eduarda com chat_lid correto\n";
    exit;
}

// Pega instância 24
$instId = (int)$pdo->query("SELECT id FROM zapi_instancias WHERE ddd = '24' LIMIT 1")->fetchColumn();
if (!$instId) { echo "[ERRO] instância DDD 24 não encontrada\n"; exit; }
echo "instancia_id = {$instId}\n";

try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $stmt = $pdo->prepare(
        "INSERT INTO zapi_conversas (instancia_id, telefone, nome_contato, client_id, canal, status, bot_ativo, eh_grupo, chat_lid)
         VALUES (?, '5521973698089', 'EDUARDA DO NASCIMENTO PIMENTA', 652, '24', 'aguardando', 0, 0, '29910705934341@lid')"
    );
    $stmt->execute(array($instId));
    $novaId = (int)$pdo->lastInsertId();
    echo "[OK] Conv #{$novaId} criada canal 24 vinculada a client=652 (Eduarda) com chat_lid=29910705934341@lid\n";
} catch (PDOException $e) {
    echo "[ERRO PDO] " . $e->getMessage() . "\n";
    // Fallback: revincula conv 648, limpa chat_lid pro match correto na próxima msg recebida
    echo "\n[FALLBACK] Revinculando conv 648 à Eduarda e limpando chat_lid contaminado\n";
    $pdo->prepare("UPDATE zapi_conversas SET client_id = 652, chat_lid = NULL, nome_contato = 'EDUARDA DO NASCIMENTO PIMENTA', status = 'aguardando' WHERE id = 648")->execute();
    echo "[OK] Conv 648 agora tem client_id=652, chat_lid=NULL (será reatribuído pelo próximo webhook)\n";
    echo "Obs: histórico antigo (msgs que foram pra outra pessoa) continua visível na conv 648 —\n";
    echo "a partir daqui, msgs novas entram corretamente se forem da Eduarda real.\n";
}
echo "\nAgora Amanda pode enviar msg pra Eduarda nessa conversa (tel=5521973698089).\n";
echo "A msg vai pro WhatsApp real dela.\n";
