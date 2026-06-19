<?php
/** Levantamento p/ follow-up: estado real do banco (read-only).
 *  curl "https://ferreiraesa.com.br/conecta/diag_followup_levant.php?key=fsa-hub-deploy-2026"
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Forbidden.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();
function tryq($pdo,$label,$sql){ echo "--- $label ---\n"; try { $r=$pdo->query($sql); foreach($r->fetchAll(PDO::FETCH_ASSOC) as $row){ echo '  '.implode(' | ', array_map(function($k,$v){return "$k=$v";}, array_keys($row), array_values($row)))."\n"; } } catch(Exception $e){ echo "  [erro] ".$e->getMessage()."\n"; } echo "\n"; }

echo "=== LEVANTAMENTO FOLLOW-UP — ".date('Y-m-d H:i')." ===\n\n";

// 1. Colunas de pipeline_leads
echo "--- pipeline_leads: TODAS as colunas ---\n";
try { foreach($pdo->query("SHOW COLUMNS FROM pipeline_leads")->fetchAll(PDO::FETCH_ASSOC) as $c){ echo "  {$c['Field']}  ({$c['Type']})".($c['Default']!==null?" def={$c['Default']}":'')."\n"; } } catch(Exception $e){ echo "  [erro] ".$e->getMessage()."\n"; }
echo "\n";

// 2. Tabelas de fluxo
echo "--- tabelas com 'fluxo' no nome ---\n";
try { foreach($pdo->query("SHOW TABLES LIKE '%fluxo%'")->fetchAll(PDO::FETCH_NUM) as $t){ echo "  {$t[0]}\n"; } } catch(Exception $e){ echo "  [erro] ".$e->getMessage()."\n"; }
echo "\n";

// 3. Schema do zapi_fluxo (cabecalho do fluxo)
echo "--- zapi_fluxo: colunas ---\n";
try { foreach($pdo->query("SHOW COLUMNS FROM zapi_fluxo")->fetchAll(PDO::FETCH_ASSOC) as $c){ echo "  {$c['Field']} ({$c['Type']})\n"; } } catch(Exception $e){ echo "  [erro] ".$e->getMessage()."\n"; }
echo "\n";

// 4. Fluxos EXISTENTES + nº de blocos
tryq($pdo,"FLUXOS cadastrados (zapi_fluxo) + nº blocos",
  "SELECT f.id, f.nome, f.ativo, (SELECT COUNT(*) FROM zapi_fluxo_bloco b WHERE b.fluxo_id=f.id) AS blocos FROM zapi_fluxo f ORDER BY f.id");

// 5. Execucoes por estado
tryq($pdo,"Execucoes de fluxo por estado","SELECT estado, COUNT(*) qt FROM zapi_fluxo_execucao GROUP BY estado");

// 6. Como o fluxo eh disparado? procurar coluna gatilho/trigger/auto/stage em zapi_fluxo (ja listado acima) + automacoes
echo "--- tabelas com 'automac' ou 'gatilho' ---\n";
try { foreach($pdo->query("SHOW TABLES LIKE '%automac%'")->fetchAll(PDO::FETCH_NUM) as $t){ echo "  {$t[0]}\n"; } } catch(Exception $e){ echo "  [erro] ".$e->getMessage()."\n"; }
echo "\n";

// 7. message_templates: categorias + contagem
tryq($pdo,"message_templates por categoria","SELECT category, COUNT(*) qt FROM message_templates GROUP BY category");

// 8. case_tasks tem lead_id?
echo "--- case_tasks: colunas (procurar lead_id) ---\n";
try { foreach($pdo->query("SHOW COLUMNS FROM case_tasks")->fetchAll(PDO::FETCH_ASSOC) as $c){ echo "  {$c['Field']} ({$c['Type']})\n"; } } catch(Exception $e){ echo "  [erro] ".$e->getMessage()."\n"; }
echo "\n";

// 9. configuracoes: metas + horario comercial
tryq($pdo,"configuracoes meta_* / horario / comercial","SELECT chave, LEFT(valor,60) AS valor FROM configuracoes WHERE chave LIKE 'meta_%' OR chave LIKE '%horario%' OR chave LIKE '%comercial%' OR chave LIKE 'zapi_%' ORDER BY chave");

// 10. leads novos hoje (volume p/ dimensionar speed-to-lead)
tryq($pdo,"Volume leads ultimos 7 dias","SELECT DATE(created_at) d, COUNT(*) qt FROM pipeline_leads WHERE created_at >= DATE_SUB(NOW(),INTERVAL 7 DAY) GROUP BY DATE(created_at) ORDER BY d DESC");

echo "=== FIM ===\n";
