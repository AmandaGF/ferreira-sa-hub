<?php
/** Colunas reais de agenda_eventos, pipeline_history, zapi_templates (read-only)
 *  curl "https://ferreiraesa.com.br/conecta/diag_followup_schema.php?key=fsa-hub-deploy-2026"
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Forbidden.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();
foreach (array('agenda_eventos','pipeline_history','zapi_templates') as $t) {
    echo "=== $t ===\n";
    try { foreach ($pdo->query("SHOW COLUMNS FROM $t")->fetchAll(PDO::FETCH_ASSOC) as $c) { echo "  {$c['Field']} ({$c['Type']})".($c['Null']==='NO'?' NOT NULL':'').($c['Default']!==null?" def={$c['Default']}":'')."\n"; } } catch(Exception $e){ echo "  [erro] ".$e->getMessage()."\n"; }
    echo "\n";
}
// tipos de evento ja usados na agenda
echo "=== agenda_eventos: tipos em uso ===\n";
try { foreach ($pdo->query("SELECT tipo, COUNT(*) qt FROM agenda_eventos GROUP BY tipo ORDER BY qt DESC")->fetchAll(PDO::FETCH_ASSOC) as $r){ echo "  {$r['tipo']} ({$r['qt']})\n"; } } catch(Exception $e){ echo "  [erro] ".$e->getMessage()."\n"; }
echo "\n";
// templates zapi existentes (nome+canal)
echo "=== zapi_templates existentes ===\n";
try { foreach ($pdo->query("SELECT id, nome, canal FROM zapi_templates ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $r){ echo "  #{$r['id']} [{$r['canal']}] {$r['nome']}\n"; } } catch(Exception $e){ echo "  [erro] ".$e->getMessage()."\n"; }
echo "\n=== FIM ===\n";
