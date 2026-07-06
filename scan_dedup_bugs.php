<?php
/**
 * Scan: quantos clientes tem email igual mas CPF diferente
 * (indicando merge errado como o caso Ludmila).
 * Read-only — só relata.
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors', '1');
error_reporting(E_ALL);
while (ob_get_level() > 0) { ob_end_clean(); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== SCAN DEDUP BUGS ===\n";
echo "Buscando pares de clientes: mesmo email, CPFs diferentes.\n\n";

// 1) Pares email=igual, CPF=diferente (ambos preenchidos)
$sql = "SELECT c1.id AS a_id, c1.name AS a_name, c1.cpf AS a_cpf,
               c2.id AS b_id, c2.name AS b_name, c2.cpf AS b_cpf,
               c1.email
        FROM clients c1
        JOIN clients c2 ON c1.email = c2.email
                        AND c1.id < c2.id
        WHERE c1.email IS NOT NULL AND c1.email <> ''
          AND c1.cpf IS NOT NULL AND c1.cpf <> ''
          AND c2.cpf IS NOT NULL AND c2.cpf <> ''
          AND REPLACE(REPLACE(REPLACE(c1.cpf,'.',''),'-',''),' ','') <>
              REPLACE(REPLACE(REPLACE(c2.cpf,'.',''),'-',''),' ','')
        ORDER BY c1.email
        LIMIT 100";
$pares = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

echo "── 1) Pares CONHECIDOS (ambos com CPF preenchido, diferentes, email igual) ──\n";
if (empty($pares)) {
    echo "  ✓ Nenhum par encontrado — banco limpo.\n\n";
} else {
    echo "  ⚠ " . count($pares) . " pares encontrados:\n\n";
    foreach ($pares as $p) {
        echo "  Email: {$p['email']}\n";
        echo "    A #{$p['a_id']} — {$p['a_name']} (CPF {$p['a_cpf']})\n";
        echo "    B #{$p['b_id']} — {$p['b_name']} (CPF {$p['b_cpf']})\n\n";
    }
}

// 2) Submissions vinculadas a clientes com nome MUITO DIFERENTE
// (indicativo de merge errado: form no nome A, ficha do cliente é B)
echo "── 2) Submissions com nome diferente do cliente vinculado ──\n";
$sql2 = "SELECT s.id AS sub_id, s.form_type, s.client_name AS form_name, s.client_email,
                s.created_at, c.id AS cli_id, c.name AS cli_name, c.cpf
         FROM form_submissions s
         JOIN clients c ON c.id = s.linked_client_id
         WHERE s.linked_client_id IS NOT NULL
           AND s.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
           AND s.client_name IS NOT NULL AND s.client_name <> ''
           AND LOWER(TRIM(s.client_name)) <> LOWER(TRIM(c.name))
         ORDER BY s.created_at DESC
         LIMIT 60";
$subs = $pdo->query($sql2)->fetchAll(PDO::FETCH_ASSOC);

if (empty($subs)) {
    echo "  ✓ Nenhuma submission suspeita nos últimos 90d.\n\n";
} else {
    // Filtrar casos onde a diferença é so acento/espaço/sobrenome parcial
    // (heurística: se sobrenome principal bate, provavelmente é a mesma pessoa)
    $suspeitos = array();
    foreach ($subs as $s) {
        $formN = mb_strtolower(preg_replace('/\s+/', ' ', trim($s['form_name'])));
        $cliN  = mb_strtolower(preg_replace('/\s+/', ' ', trim($s['cli_name'])));
        // Se um contém o outro, é provavelmente edição do mesmo nome
        if (strpos($cliN, $formN) !== false || strpos($formN, $cliN) !== false) continue;
        // Compara primeiros nomes — se são iguais, também é provavelmente OK
        $pf = strtok($formN, ' ');
        $pc = strtok($cliN, ' ');
        if ($pf === $pc) continue;
        $suspeitos[] = $s;
    }
    if (empty($suspeitos)) {
        echo "  ✓ Nenhum caso realmente suspeito (variações de acento/formato).\n\n";
    } else {
        echo "  ⚠ " . count($suspeitos) . " submissions suspeitas (nome do form ≠ nome do cliente vinculado):\n\n";
        foreach ($suspeitos as $s) {
            echo "  Sub #{$s['sub_id']} ({$s['form_type']}, {$s['created_at']}):\n";
            echo "    Form disse:  {$s['form_name']} · {$s['client_email']}\n";
            echo "    Colou em:    #{$s['cli_id']} {$s['cli_name']} (CPF {$s['cpf']})\n\n";
        }
    }
}

// 3) Leads com nome muito diferente do cliente vinculado
echo "── 3) Leads pipeline com nome diferente do cliente vinculado ──\n";
$sql3 = "SELECT l.id AS lead_id, l.name AS lead_name, l.stage, l.created_at,
                c.id AS cli_id, c.name AS cli_name, c.cpf
         FROM pipeline_leads l
         JOIN clients c ON c.id = l.client_id
         WHERE l.client_id IS NOT NULL
           AND l.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
           AND l.name IS NOT NULL AND l.name <> ''
           AND LOWER(TRIM(l.name)) <> LOWER(TRIM(c.name))
         ORDER BY l.created_at DESC
         LIMIT 60";
$leads = $pdo->query($sql3)->fetchAll(PDO::FETCH_ASSOC);

$leadsSusp = array();
foreach ($leads as $l) {
    $ln = mb_strtolower(preg_replace('/\s+/', ' ', trim($l['lead_name'])));
    $cn = mb_strtolower(preg_replace('/\s+/', ' ', trim($l['cli_name'])));
    if (strpos($cn, $ln) !== false || strpos($ln, $cn) !== false) continue;
    if (strtok($ln, ' ') === strtok($cn, ' ')) continue;
    $leadsSusp[] = $l;
}

if (empty($leadsSusp)) {
    echo "  ✓ Nenhum lead suspeito.\n\n";
} else {
    echo "  ⚠ " . count($leadsSusp) . " leads suspeitos:\n\n";
    foreach ($leadsSusp as $l) {
        echo "  Lead #{$l['lead_id']} ({$l['stage']}, {$l['created_at']}):\n";
        echo "    Lead name:  {$l['lead_name']}\n";
        echo "    Cliente:    #{$l['cli_id']} {$l['cli_name']} (CPF {$l['cpf']})\n\n";
    }
}

echo "=== FIM ===\n";
