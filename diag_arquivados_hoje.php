<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('x'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();
$hoje = date('Y-m-d');

echo "=== Tudo que parece arquivamento HOJE ({$hoje}) ===\n\n";

echo "--- 1) cases com status='arquivado' E updated_at hoje ---\n";
$r = $pdo->prepare("SELECT id, title, status, kanban_oculto, closed_at, updated_at FROM cases WHERE status='arquivado' AND DATE(updated_at)=? ORDER BY updated_at DESC");
$r->execute(array($hoje));
foreach ($r->fetchAll() as $c) echo "  case#{$c['id']} | {$c['title']} | status={$c['status']} | oculto={$c['kanban_oculto']} | closed={$c['closed_at']} | updated={$c['updated_at']}\n";

echo "\n--- 2) cases com closed_at = HOJE (mesmo se status já voltou) ---\n";
$r = $pdo->prepare("SELECT id, title, status, kanban_oculto, closed_at, updated_at FROM cases WHERE closed_at = ? ORDER BY updated_at DESC");
$r->execute(array($hoje));
foreach ($r->fetchAll() as $c) echo "  case#{$c['id']} | {$c['title']} | status={$c['status']} | oculto={$c['kanban_oculto']} | closed={$c['closed_at']} | updated={$c['updated_at']}\n";

echo "\n--- 3) cases com kanban_oculto=1 E updated_at hoje (excluindo restauracoes do diag) ---\n";
$r = $pdo->prepare("SELECT id, title, status, kanban_oculto, updated_at FROM cases WHERE kanban_oculto=1 AND DATE(updated_at)=? ORDER BY updated_at DESC");
$r->execute(array($hoje));
foreach ($r->fetchAll() as $c) echo "  case#{$c['id']} | {$c['title']} | status={$c['status']} | oculto={$c['kanban_oculto']} | updated={$c['updated_at']}\n";

echo "\n--- 4) AUDIT_LOG hoje — ações de arquivamento ---\n";
$r = $pdo->prepare("SELECT created_at, user_id, action, entity_type, entity_id, details FROM audit_log
                    WHERE DATE(created_at) = ?
                      AND (action LIKE '%arquiv%' OR action='ocultar_kanban' OR action='kanban_oculto' OR action='merge_cases' OR action='merge_cases_absorbed')
                    ORDER BY id DESC LIMIT 200");
$r->execute(array($hoje));
foreach ($r->fetchAll() as $a) echo "  {$a['created_at']} | uid={$a['user_id']} | {$a['action']} | {$a['entity_type']}#{$a['entity_id']} | " . substr($a['details'], 0, 150) . "\n";

echo "\n--- 5) LEADS arquivados/kanban_oculto hoje ---\n";
try { $pdo->exec("ALTER TABLE pipeline_leads ADD COLUMN kanban_oculto TINYINT(1) DEFAULT 0"); } catch (Exception $e) {}
$r = $pdo->prepare("SELECT id, name, stage, kanban_oculto, arquivado_em, updated_at FROM pipeline_leads
                    WHERE DATE(updated_at) = ?
                      AND (stage='arquivado' OR kanban_oculto=1)
                    ORDER BY updated_at DESC");
$r->execute(array($hoje));
foreach ($r->fetchAll() as $l) echo "  lead#{$l['id']} | {$l['name']} | stage={$l['stage']} | oculto={$l['kanban_oculto']} | arq_em={$l['arquivado_em']} | updated={$l['updated_at']}\n";

echo "\n=== FIM ===\n";
