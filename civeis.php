<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Departamentos existentes em cases (status ativo) ===\n";
foreach ($pdo->query("SELECT COALESCE(departamento,'(vazio)') AS dpto, COUNT(*) c
                      FROM cases
                      WHERE status NOT IN ('arquivado','cancelado','finalizado','concluido','renunciamos')
                        AND COALESCE(kanban_oculto,0) = 0
                      GROUP BY dpto ORDER BY c DESC") as $r) {
    printf("  %-30s %d\n", $r['dpto'], $r['c']);
}

echo "\n=== TIPOS DE AÇÃO (case_type) com contagens — ATIVOS ===\n";
foreach ($pdo->query("SELECT COALESCE(case_type,'(vazio)') AS t, COUNT(*) c
                      FROM cases
                      WHERE status NOT IN ('arquivado','cancelado','finalizado','concluido','renunciamos')
                        AND COALESCE(kanban_oculto,0) = 0
                      GROUP BY t ORDER BY c DESC LIMIT 40") as $r) {
    printf("  %-45s %d\n", substr($r['t'],0,44), $r['c']);
}

echo "\n=== Total ATIVOS por departamento CIVIL (heurística) ===\n";
// Heurística: departamento contém 'civel' OU case_type sem ser Família/Previdenciário/Criminal/Trabalhista
$civeis = (int)$pdo->query("SELECT COUNT(*) FROM cases
                            WHERE status NOT IN ('arquivado','cancelado','finalizado','concluido','renunciamos')
                              AND COALESCE(kanban_oculto,0) = 0
                              AND (LOWER(COALESCE(departamento,'')) LIKE '%civ%'
                                   OR LOWER(COALESCE(departamento,'')) = 'civel'
                                   OR LOWER(COALESCE(departamento,'')) = 'cível')")->fetchColumn();
echo "  cases.departamento contém 'civ': $civeis\n";

$naoCivel = (int)$pdo->query("SELECT COUNT(*) FROM cases
                              WHERE status NOT IN ('arquivado','cancelado','finalizado','concluido','renunciamos')
                                AND COALESCE(kanban_oculto,0) = 0
                                AND (LOWER(COALESCE(departamento,'')) IN ('familia','família','previdenciario','previdenciário','trabalhista','criminal','penal')
                                     OR COALESCE(kanban_prev,0) = 1)")->fetchColumn();
$totalAtivos = (int)$pdo->query("SELECT COUNT(*) FROM cases
                                 WHERE status NOT IN ('arquivado','cancelado','finalizado','concluido','renunciamos')
                                   AND COALESCE(kanban_oculto,0) = 0")->fetchColumn();
echo "  Total ativos: $totalAtivos\n";
echo "  Não-cível (família/prev/trab/criminal): $naoCivel\n";
echo "  Resto (potencialmente cível): " . ($totalAtivos - $naoCivel) . "\n";

echo "\n=== Excluindo tipos claramente NÃO-cíveis ===\n";
$naoCivelTipos = array('Alimentos','Guarda','Convivência','Divórcio','Divorcio','Divórcio Litigioso','Divórcio Consensual',
    'Investigação de Paternidade','Investigacao de Paternidade','Regulamentação de Visitas','Regulamentacao de Visitas',
    'Exoneração de Alimentos','Exoneracao de Alimentos','Reconhecimento de Paternidade','Adoção','Adocao',
    'União Estável','Uniao Estavel','Alienação Parental','Alienacao Parental','Interdição','Interdicao',
    'Pensão','Pensao','Inventário','Inventario','Partilha','Curatela','Tutela',
    'BPC','LOAS','Aposentadoria','Auxílio-Doença','Auxilio-Doenca','Auxílio-Acidente','Auxilio-Acidente','Pensão por Morte',
    'Salário-Maternidade','INSS');
$ph = implode(',', array_fill(0, count($naoCivelTipos), '?'));
$stCiv = $pdo->prepare("SELECT COUNT(*) FROM cases
    WHERE status NOT IN ('arquivado','cancelado','finalizado','concluido','renunciamos')
      AND COALESCE(kanban_oculto,0) = 0
      AND COALESCE(kanban_prev,0) = 0
      AND (case_type IS NULL OR case_type NOT IN ($ph))");
$stCiv->execute($naoCivelTipos);
$civilPuro = (int)$stCiv->fetchColumn();
echo "  ~Cíveis 'puros' (excluindo Família/PREV/tipos previdenciários): $civilPuro\n";

echo "\n=== Amostra dos ~cíveis (top 30 mais recentes) ===\n";
$stAmostra = $pdo->prepare("SELECT id, title, case_type, departamento, status
    FROM cases WHERE status NOT IN ('arquivado','cancelado','finalizado','concluido','renunciamos')
      AND COALESCE(kanban_oculto,0) = 0 AND COALESCE(kanban_prev,0) = 0
      AND (case_type IS NULL OR case_type NOT IN ($ph))
    ORDER BY updated_at DESC LIMIT 30");
$stAmostra->execute($naoCivelTipos);
foreach ($stAmostra as $r) {
    printf("  #%d %-30s | %s | %s\n", $r['id'], substr($r['case_type']?:'-',0,30), $r['departamento']?:'-', substr($r['title'],0,50));
}
