<?php
/**
 * Reparo: zera dia_todo=0 em eventos que NUNCA deveriam ser "dia todo"
 * (audiencia, reuniao_cliente, mediacao_cejusc, balcao_virtual, ligacao,
 * reuniao_interna) mas que ficaram travados pelo bug do isset() (corrigido
 * no commit anterior).
 *
 * Preserva dia_todo=1 em: prazo, onboarding, pessoal (onde faz sentido).
 *
 * Acesso: ferreiraesa.com.br/conecta/reparar_dia_todo_audiencias.php?key=fsa-hub-deploy-2026
 * Dry-run default. Aplicar de verdade: &aplicar=1
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$aplicar = ($_GET['aplicar'] ?? '0') === '1';

echo "=== Reparo dia_todo em audiencias/reunioes/etc ===\n";
echo $aplicar ? "MODO: APLICAR\n\n" : "MODO: DRY-RUN (use &aplicar=1 pra gravar)\n\n";

$tiposAfetados = array('audiencia','reuniao_cliente','mediacao_cejusc','balcao_virtual','ligacao','reuniao_interna');
// Lista hard-coded eh ok aqui — todos vem de array literal, sem input do usuario.
$tiposIn = "'" . implode("','", $tiposAfetados) . "'";

echo "Buscando candidatos (tipos: $tiposIn)...\n";

try {
    $st = $pdo->query(
        "SELECT id, titulo, tipo, data_inicio, hora_inicio, dia_todo
         FROM agenda_eventos
         WHERE dia_todo = 1
           AND tipo IN ($tiposIn)
           AND hora_inicio IS NOT NULL
           AND hora_inicio != '00:00:00'
         ORDER BY data_inicio DESC LIMIT 100"
    );
    $candidatos = $st->fetchAll();
    echo "Encontrados: " . count($candidatos) . "\n\n";
} catch (Exception $e) {
    echo "ERRO no SELECT: " . $e->getMessage() . "\n";
    exit;
}

echo "Candidatos (primeiros 100):\n";
if (empty($candidatos)) {
    echo "  Nenhum evento com dia_todo=1 + hora_inicio definida nestes tipos.\n";
} else {
    foreach ($candidatos as $e) {
        echo "  #" . str_pad((string)$e['id'], 5, ' ') . " [" . str_pad($e['tipo'], 18, ' ') . "] "
            . substr((string)$e['titulo'], 0, 50)
            . " | " . $e['data_inicio'] . " hora=" . substr((string)$e['hora_inicio'], 0, 5) . "\n";
    }
}

if ($aplicar && !empty($candidatos)) {
    $stUpd = $pdo->exec(
        "UPDATE agenda_eventos SET dia_todo = 0
         WHERE dia_todo = 1
           AND tipo IN ($tiposIn)
           AND hora_inicio IS NOT NULL
           AND hora_inicio != '00:00:00'"
    );
    echo "\n[OK] Atualizados: " . (int)$stUpd . " eventos.\n";
} else if ($aplicar) {
    echo "\nNada a aplicar.\n";
}

// Lista o que TEM dia_todo=1 nesses tipos mas SEM hora — esses casos eu nao toco
// (a Amanda pode ter intencionalmente marcado dia inteiro pra alguma audiencia
// sem horario definido). Apenas reporta.
echo "\n--- Eventos dia_todo=1 SEM hora (nao tocados — talvez intencionais) ---\n";
$stOk = $pdo->query("SELECT id, titulo, tipo, data_inicio FROM agenda_eventos
                     WHERE dia_todo = 1 AND tipo IN ($tiposIn)
                       AND (hora_inicio IS NULL OR hora_inicio = '00:00:00')
                     ORDER BY data_inicio DESC LIMIT 30");
$semHora = $stOk->fetchAll();
if (empty($semHora)) echo "  Nenhum.\n";
else foreach ($semHora as $e) {
    echo "  #" . str_pad((string)$e['id'], 5, ' ') . " [" . str_pad($e['tipo'], 18, ' ') . "] "
        . substr((string)$e['titulo'], 0, 50) . " | " . $e['data_inicio'] . "\n";
}

echo "\n[FIM]\n";
