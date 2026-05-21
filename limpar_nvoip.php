<?php
/**
 * limpar_nvoip.php — Remove os resíduos do sistema de ligações Nvoip do SERVIDOR.
 *
 * O serviço Nvoip foi cancelado. O código já saiu do repositório, mas o deploy
 * só EXTRAI arquivos do ZIP — não apaga os que sumiram do repo. Este script
 * apaga os arquivos órfãos diretamente no servidor e remove as credenciais
 * Nvoip da tabela `configuracoes`.
 *
 * NÃO mexe na tabela `ligacoes_historico` nem na coluna `users.nvoip_ramal` —
 * o histórico de ligações antigas é PRESERVADO a pedido da Amanda.
 *
 * Uso (uma vez): https://ferreiraesa.com.br/conecta/limpar_nvoip.php?key=fsa-hub-deploy-2026
 * Depois de rodar, este arquivo pode ser removido do repositório.
 */
require_once __DIR__ . '/core/database.php';

if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') {
    http_response_code(403);
    exit('Acesso negado.');
}

header('Content-Type: text/plain; charset=utf-8');
echo "=== Limpeza do sistema de ligações Nvoip ===\n\n";

// 1) Arquivos órfãos no servidor
$arquivos = array(
    'core/functions_nvoip.php',
    'api/nvoip_api.php',
    'modules/admin/nvoip.php',
    'modules/ligacoes/index.php',
    'assets/js/nvoip.js',
    'assets/css/nvoip.css',
    'migrar_nvoip.php',
);
echo "1. Arquivos:\n";
foreach ($arquivos as $rel) {
    $abs = __DIR__ . '/' . $rel;
    if (file_exists($abs)) {
        echo (@unlink($abs) ? "   [APAGADO]  " : "   [FALHOU]  ") . $rel . "\n";
    } else {
        echo "   [JA NAO EXISTE] " . $rel . "\n";
    }
}

// Pasta modules/ligacoes/ — remove se ficou vazia
$dirLig = __DIR__ . '/modules/ligacoes';
if (is_dir($dirLig)) {
    $resto = array_diff(scandir($dirLig), array('.', '..', 'desktop.ini'));
    if (empty($resto)) {
        @unlink($dirLig . '/desktop.ini');
        echo (@rmdir($dirLig) ? "   [PASTA REMOVIDA] modules/ligacoes/\n" : "   [PASTA MANTIDA]  modules/ligacoes/ (rmdir falhou)\n");
    } else {
        echo "   [PASTA MANTIDA]  modules/ligacoes/ (ainda tem: " . implode(', ', $resto) . ")\n";
    }
}

// 2) Credenciais Nvoip na tabela configuracoes
echo "\n2. Configurações (credenciais):\n";
$chaves = array(
    'nvoip_napikey', 'nvoip_numbersip', 'nvoip_user_token',
    'nvoip_access_token', 'nvoip_refresh_token', 'nvoip_token_expiry',
    'nvoip_webphone_email', 'nvoip_webphone_senha', 'nvoip_webphone_url',
);
try {
    $pdo = db();
    $stmt = $pdo->prepare("DELETE FROM configuracoes WHERE chave = ?");
    foreach ($chaves as $ch) {
        $stmt->execute(array($ch));
        echo "   [" . ($stmt->rowCount() > 0 ? "REMOVIDA" : "ausente ") . "] " . $ch . "\n";
    }
} catch (Exception $e) {
    echo "   ERRO ao limpar configuracoes: " . $e->getMessage() . "\n";
}

// 3) Módulo de treinamento "ligacoes-nvoip" + perguntas do quiz
echo "\n3. Treinamento:\n";
try {
    $pdo = isset($pdo) ? $pdo : db();
    $d1 = $pdo->prepare("DELETE FROM treinamento_quiz WHERE modulo_slug = 'ligacoes-nvoip'");
    $d1->execute();
    echo "   [quiz]   " . $d1->rowCount() . " pergunta(s) removida(s)\n";
    $d2 = $pdo->prepare("DELETE FROM treinamento_modulos WHERE slug = 'ligacoes-nvoip'");
    $d2->execute();
    echo "   [modulo] " . ($d2->rowCount() > 0 ? "removido" : "ausente") . "\n";
} catch (Exception $e) {
    echo "   ERRO ao limpar treinamento: " . $e->getMessage() . "\n";
}

echo "\n4. Preservado (NÃO tocado):\n";
echo "   - tabela ligacoes_historico (histórico de chamadas antigas)\n";
echo "   - coluna users.nvoip_ramal\n";

echo "\n=== Concluído ===\n";
echo "Pode remover este limpar_nvoip.php do repositório.\n";
