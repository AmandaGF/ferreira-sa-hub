<?php
/**
 * Migração: killswitch + whitelist de usuários da Petição Geral com IA.
 * Default LIGADO ('1') porque foi explicitamente pedido pela Amanda em 29/06.
 *
 * Uso: GET /migrar_peticao_ia.php?key=fsa-hub-deploy-2026
 */
require_once __DIR__ . '/core/database.php';

$key = $_GET['key'] ?? '';
if ($key !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }

header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Migração: Petição Geral com IA ===\n\n";

$chave = 'ia_feature_peticao_ia_enabled';
try {
    $st = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = ?");
    $st->execute(array($chave));
    $atual = $st->fetchColumn();
    if ($atual === false) {
        $cols = $pdo->query("DESCRIBE configuracoes")->fetchAll(PDO::FETCH_COLUMN);
        if (in_array('descricao', $cols)) {
            $pdo->prepare("INSERT INTO configuracoes (chave, valor, descricao) VALUES (?,?,?)")
                ->execute(array($chave, '1', 'Liga a tela Documentos > Petição Geral com IA (Sonnet/Haiku)'));
        } else {
            $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES (?,?)")
                ->execute(array($chave, '1'));
        }
        echo "  ✓ Criada: {$chave} = '1' (LIGADO)\n";
    } else {
        echo "  - Já existe: {$chave} = '{$atual}' (mantido)\n";
    }
} catch (Exception $e) {
    echo "  ✗ Erro: " . $e->getMessage() . "\n";
}

echo "\nPra desligar:\n";
echo "  UPDATE configuracoes SET valor='0' WHERE chave='{$chave}';\n";

echo "\n=== FIM ===\n";
