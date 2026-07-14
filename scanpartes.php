<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

// Partes SEM client_id, mas com CPF ou telefone — tentar match com clients existentes
echo "=== PARTES SEM client_id QUE BATEM COM CLIENTES EXISTENTES ===\n\n";

$normFone = function ($s) {
    $s = preg_replace('/\D+/', '', $s ?? '');
    return substr($s, -11);
};
$normCPF = function ($s) { return preg_replace('/\D+/', '', $s ?? ''); };

// Carrega clients em cache: por CPF e por telefone normalizados
$idxCPF = array();
$idxFone = array();
$idxNome = array();
foreach ($pdo->query("SELECT id, name, cpf, phone FROM clients") as $c) {
    $cpf = $normCPF($c['cpf']);
    if ($cpf && strlen($cpf) === 11) $idxCPF[$cpf][] = $c;
    $f = $normFone($c['phone']);
    if ($f && strlen($f) >= 10) $idxFone[$f][] = $c;
    $n = mb_strtolower(trim($c['name']));
    if ($n) $idxNome[$n][] = $c;
}

$partes = $pdo->query("SELECT cp.id parte_id, cp.case_id, cp.nome, cp.cpf, cp.telefone, cp.papel, cs.title, cs.status
                       FROM case_partes cp
                       LEFT JOIN cases cs ON cs.id = cp.case_id
                       WHERE (cp.client_id IS NULL OR cp.client_id = 0)
                         AND (cp.nome IS NOT NULL AND cp.nome <> '')
                         AND (cp.cpf IS NOT NULL AND cp.cpf <> '' OR cp.telefone IS NOT NULL AND cp.telefone <> '')")->fetchAll(PDO::FETCH_ASSOC);

$matches = array();
foreach ($partes as $p) {
    $cpf = $normCPF($p['cpf']);
    $fone = $normFone($p['telefone']);
    $nome = mb_strtolower(trim($p['nome']));
    $achou = null;
    $motivo = '';
    // CPF é o mais forte
    if ($cpf && strlen($cpf) === 11 && !empty($idxCPF[$cpf])) {
        $achou = $idxCPF[$cpf][0];
        $motivo = "CPF";
    } elseif ($fone && strlen($fone) >= 10 && !empty($idxFone[$fone])) {
        // Confirmar que o nome bate (pelo menos primeiro nome)
        $cands = $idxFone[$fone];
        foreach ($cands as $c) {
            $primP = mb_strtolower(strtok($nome, ' '));
            $primC = mb_strtolower(strtok($c['name'], ' '));
            if ($primP && $primC && $primP === $primC) {
                $achou = $c;
                $motivo = "Telefone + primeiro nome";
                break;
            }
        }
    } elseif (!empty($idxNome[$nome])) {
        // Match por nome exato + algum dado que confirme
        $cands = $idxNome[$nome];
        if (count($cands) === 1) {
            $achou = $cands[0];
            $motivo = "Nome exato (único)";
        }
    }
    if ($achou) {
        $matches[] = array('parte' => $p, 'client' => $achou, 'motivo' => $motivo);
    }
}

echo "Total de partes sem client_id: " . count($partes) . "\n";
echo "Total de MATCHES sugeridos: " . count($matches) . "\n\n";

// Agrupar por confiança
$porMotivo = array();
foreach ($matches as $m) $porMotivo[$m['motivo']][] = $m;

foreach ($porMotivo as $motivo => $lista) {
    echo "\n### $motivo (" . count($lista) . ") ###\n";
    foreach ($lista as $i => $m) {
        $p = $m['parte']; $c = $m['client'];
        printf("  [%d] parte#%d '%s' (cpf=%s tel=%s papel=%s) case#%s '%s' [%s]\n",
            $i+1, $p['parte_id'], $p['nome'], $p['cpf']?:'-', $p['telefone']?:'-', $p['papel'],
            $p['case_id'], substr($p['title']??'',0,40), $p['status']);
        printf("       ↳ client#%d '%s' cpf=%s tel=%s\n",
            $c['id'], $c['name'], $c['cpf']?:'-', $c['phone']?:'-');
    }
}

echo "\n=== FIM ===\n";
