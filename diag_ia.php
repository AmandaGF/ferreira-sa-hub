<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Negado.'); }
ini_set('display_errors', '1'); error_reporting(E_ALL);
header('Content-Type: text/plain; charset=utf-8');
echo "=== DIAG IA ===\n\n";

echo "1) carregando config + database + auth + middleware + functions_utils\n";
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/middleware.php';
require_once __DIR__ . '/core/functions_utils.php';
echo "   ok\n";

echo "2) carregando functions_ia\n";
require_once __DIR__ . '/core/functions_ia.php';
echo "   ok\n";

echo "3) db()\n";
$pdo = db();
echo "   ok\n";

echo "4) ia_gasto_mes_atual()\n";
$x = ia_gasto_mes_atual();
echo "   resultado: $x\n";

echo "5) ia_orcamento_mes()\n";
$x = ia_orcamento_mes();
echo "   resultado: $x\n";

echo "6) ia_cambio_brl()\n";
$x = ia_cambio_brl();
echo "   resultado: $x\n";

echo "7) SELECT ia_usage_log (mes corrente, por feature)\n";
$r = $pdo->query("SELECT feature, COUNT(*) n, COALESCE(SUM(custo_brl),0) brl, COALESCE(SUM(input_tokens),0) inT, COALESCE(SUM(output_tokens),0) outT
                  FROM ia_usage_log WHERE YEAR(created_at)=YEAR(NOW()) AND MONTH(created_at)=MONTH(NOW())
                  GROUP BY feature ORDER BY brl DESC")->fetchAll(PDO::FETCH_ASSOC);
echo "   rows: " . count($r) . "\n";

echo "8) SELECT ia_usage_log por user (com JOIN users)\n";
$r = $pdo->query("SELECT u.name, l.user_id, COUNT(*) n, COALESCE(SUM(l.custo_brl),0) brl
                  FROM ia_usage_log l LEFT JOIN users u ON u.id = l.user_id
                  WHERE YEAR(l.created_at)=YEAR(NOW()) AND MONTH(l.created_at)=MONTH(NOW())
                  GROUP BY l.user_id ORDER BY brl DESC")->fetchAll(PDO::FETCH_ASSOC);
echo "   rows: " . count($r) . "\n";

echo "9) SELECT ia_usage_log por dia\n";
$r = $pdo->query("SELECT DATE(created_at) d, COALESCE(SUM(custo_brl),0) brl, COUNT(*) n
                  FROM ia_usage_log WHERE created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
                  GROUP BY DATE(created_at) ORDER BY d ASC")->fetchAll(PDO::FETCH_ASSOC);
echo "   rows: " . count($r) . "\n";

echo "10) SELECT ultimas 20 chamadas\n";
$r = $pdo->query("SELECT l.*, u.name AS user_name FROM ia_usage_log l LEFT JOIN users u ON u.id = l.user_id ORDER BY l.id DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
echo "   rows: " . count($r) . "\n";

echo "11) SELECT users ativos\n";
$r = $pdo->query("SELECT id, name, role FROM users WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
echo "   rows: " . count($r) . "\n";

echo "12) cfg() de orcamento_mensal_reais\n";
$st = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = ?");
$st->execute(array('ia_orcamento_mensal_reais'));
echo "   valor: " . $st->fetchColumn() . "\n";

echo "\nTUDO OK ATE AQUI — o erro deve estar no HTML/template.\n";
