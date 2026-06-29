<?php
/**
 * Migração: configura killswitches dos lembretes D-1 automáticos
 * + ALTER em audiencias com colunas de tracking
 *
 * Uso: GET /migrar_lembretes_d1.php?key=fsa-hub-deploy-2026
 *
 * Default: AMBOS LIGADOS (Amanda pode desligar editando configuracoes).
 */
require_once __DIR__ . '/core/database.php';

$key = $_GET['key'] ?? '';
if ($key !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }

header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Migração: Lembretes D-1 automáticos ===\n\n";

// 1) Colunas em audiencias (idempotente)
$alters = array(
    "ALTER TABLE audiencias ADD COLUMN audiencista_avisado_em DATETIME NULL",
    "ALTER TABLE audiencias ADD COLUMN audiencista_avisado_por INT NULL",
);
foreach ($alters as $sql) {
    try { $pdo->exec($sql); echo "  ✓ {$sql}\n"; }
    catch (Exception $e) { echo "  - (já existe ou erro): " . $e->getMessage() . "\n"; }
}

// 2) Killswitches em configuracoes — default LIGADO ('1')
$configs = array(
    array('lembrete_d1_auto_cliente', '1', 'Envia WA D-1 automático pro cliente da audiência/CEJUSC/reunião presencial'),
    array('lembrete_d1_auto_audiencista', '1', 'Envia WA D-1 automático pro audiencista designado'),
);
foreach ($configs as $c) {
    list($chave, $valor, $desc) = $c;
    try {
        $st = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = ?");
        $st->execute(array($chave));
        $atual = $st->fetchColumn();
        if ($atual === false) {
            // Tenta detectar colunas (descricao/categoria pode não existir)
            $cols = array();
            try { $cols = $pdo->query("DESCRIBE configuracoes")->fetchAll(PDO::FETCH_COLUMN); } catch (Exception $e) {}
            if (in_array('descricao', $cols)) {
                $pdo->prepare("INSERT INTO configuracoes (chave, valor, descricao) VALUES (?,?,?)")
                    ->execute(array($chave, $valor, $desc));
            } else {
                $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES (?,?)")
                    ->execute(array($chave, $valor));
            }
            echo "  ✓ Criada: {$chave} = '{$valor}'\n";
        } else {
            echo "  - Já existe: {$chave} = '{$atual}' (mantido)\n";
        }
    } catch (Exception $e) {
        echo "  ✗ Erro em {$chave}: " . $e->getMessage() . "\n";
    }
}

echo "\n=== FIM ===\n";
echo "Pra desligar manualmente:\n";
echo "  UPDATE configuracoes SET valor='0' WHERE chave='lembrete_d1_auto_cliente';\n";
echo "  UPDATE configuracoes SET valor='0' WHERE chave='lembrete_d1_auto_audiencista';\n";
