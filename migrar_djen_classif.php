<?php
/**
 * Migração: cria killswitch da classificação estruturada DJEN
 * (default DESLIGADO — Amanda ativa em /admin/ia_custo.php quando quiser)
 *
 * Uso: GET /migrar_djen_classif.php?key=fsa-hub-deploy-2026
 */
require_once __DIR__ . '/core/database.php';

$key = $_GET['key'] ?? '';
if ($key !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }

header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Migração: DJEN classificação estruturada ===\n\n";

$chave = 'ia_feature_djen_classif_estruturada_enabled';
try {
    $st = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = ?");
    $st->execute(array($chave));
    $atual = $st->fetchColumn();
    if ($atual === false) {
        $cols = $pdo->query("DESCRIBE configuracoes")->fetchAll(PDO::FETCH_COLUMN);
        if (in_array('descricao', $cols)) {
            $pdo->prepare("INSERT INTO configuracoes (chave, valor, descricao) VALUES (?,?,?)")
                ->execute(array($chave, '0', 'Liga classificação estruturada DJEN (tipo_recurso, dias_uteis, parte) via Haiku'));
        } else {
            $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES (?,?)")
                ->execute(array($chave, '0'));
        }
        echo "  ✓ Criada: {$chave} = '0' (DESLIGADO)\n";
    } else {
        echo "  - Já existe: {$chave} = '{$atual}'\n";
    }
} catch (Exception $e) {
    echo "  ✗ Erro: " . $e->getMessage() . "\n";
}

echo "\nPra LIGAR a classificação:\n";
echo "  UPDATE configuracoes SET valor='1' WHERE chave='ia_feature_djen_classif_estruturada_enabled';\n";
echo "Depois agendar cron 1x/dia 9h:\n";
echo "  curl https://ferreiraesa.com.br/conecta/cron/djen_classificar_estruturado.php?key=fsa-hub-deploy-2026\n";

echo "\n=== FIM ===\n";
