<?php
/**
 * Remove o favorito órfão do GERID da barra de TODOS os usuários.
 *
 * BUG (Amanda 19/07/2026): depois do rename GERID -> FBI $, quem tinha o GERID
 * fixado na barra de favoritos ficou com um link morto (404) e SEM COMO TIRAR —
 * a única forma de remover um favorito era a estrela ☆ do item na sidebar, e o
 * item GERID não existe mais lá. Favorito órfão = impossível de remover pela UI.
 *
 * Este script apaga essas linhas de user_favoritos. O ✕ novo nos chips da barra
 * (templates/sidebar.php) impede que o problema se repita com outros módulos.
 *
 * Key-protected. Idempotente.
 */

if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';

ini_set('display_errors', '1');
error_reporting(E_ALL);
header('Content-Type: text/plain; charset=utf-8');

echo "=== Remover favorito orfao do GERID ===\n\n";

try {
    $pdo = db();

    // 1) Quem tem o favorito quebrado
    $sel = $pdo->query(
        "SELECT uf.user_id, uf.fav_id, uf.label, uf.href, u.name
           FROM user_favoritos uf
           LEFT JOIN users u ON u.id = uf.user_id
          WHERE uf.fav_id = 'gerid'
             OR uf.href LIKE '%/modules/gerid%'
             OR uf.label LIKE '%GERID%'
          ORDER BY u.name"
    )->fetchAll(PDO::FETCH_ASSOC);

    if (!$sel) {
        echo "[SKIP] Nenhum usuario tem favorito do GERID. Nada a fazer.\n";
    } else {
        echo "Encontrados " . count($sel) . " favorito(s) quebrado(s):\n";
        foreach ($sel as $r) {
            echo "  - {$r['name']} (user #{$r['user_id']}) | fav_id='{$r['fav_id']}' | label='{$r['label']}'\n";
        }

        // 2) Apaga
        $n = $pdo->exec(
            "DELETE FROM user_favoritos
              WHERE fav_id = 'gerid'
                 OR href LIKE '%/modules/gerid%'
                 OR label LIKE '%GERID%'"
        );
        echo "\n[OK] {$n} linha(s) removida(s) de user_favoritos\n";
    }

    // 3) Conferencia
    $rest = (int)$pdo->query(
        "SELECT COUNT(*) FROM user_favoritos
          WHERE fav_id = 'gerid' OR href LIKE '%/modules/gerid%' OR label LIKE '%GERID%'"
    )->fetchColumn();
    echo "\n[CONFERENCIA] favoritos com GERID restantes: {$rest} (esperado 0)\n";

    // 4) Sanidade: ninguem deve ter ficado com href apontando pra modulo inexistente
    $orfaos = $pdo->query(
        "SELECT DISTINCT href FROM user_favoritos WHERE href LIKE '%/modules/%'"
    )->fetchAll(PDO::FETCH_COLUMN);
    $quebrados = array();
    foreach ($orfaos as $h) {
        if (preg_match('#/modules/([a-z0-9_]+)#i', (string)$h, $m)) {
            if (!is_dir(__DIR__ . '/modules/' . $m[1])) $quebrados[] = $h;
        }
    }
    if ($quebrados) {
        echo "\n[ATENCAO] ainda ha favoritos apontando pra modulo inexistente:\n";
        foreach ($quebrados as $q) echo "   {$q}\n";
        echo "   (agora da pra remover pelo ✕ na propria barra de favoritos)\n";
    } else {
        echo "[CONFERENCIA] nenhum favorito aponta pra modulo inexistente\n";
    }

} catch (Exception $e) {
    echo "ERRO: " . $e->getMessage() . "\n";
    http_response_code(500);
}

echo "\n=== Fim ===\n";
