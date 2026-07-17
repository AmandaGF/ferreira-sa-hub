<?php
// Dossiê completo de um caso — insumo para redação de minuta em sessão dedicada.
// Uso: diag_dossie_caso.php?key=...&id=938
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors', '1');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { die("Informe &id=NNN\n"); }

$s = $pdo->prepare("SELECT * FROM cases WHERE id = ?");
$s->execute([$id]);
$c = $s->fetch(PDO::FETCH_ASSOC);
if (!$c) { die("Caso #$id nao encontrado.\n"); }

echo "=== CASO #{$c['id']} ===\n";
foreach (['title','case_type','status','case_number','comarca','vara','drive_folder_url',
          'created_at','updated_at','elaborado_por_ia','client_id'] as $k) {
    if (array_key_exists($k, $c)) { echo "  $k: " . ($c[$k] ?? '(null)') . "\n"; }
}

echo "\n=== CLIENTE PRINCIPAL ===\n";
$s = $pdo->prepare("SELECT * FROM clients WHERE id = ?");
$s->execute([$c['client_id']]);
$cl = $s->fetch(PDO::FETCH_ASSOC);
if ($cl) {
    foreach (['id','name','cpf','rg','birth_date','phone','email','address','profissao',
              'estado_civil','nacionalidade'] as $k) {
        if (array_key_exists($k, $cl)) { echo "  $k: " . ($cl[$k] ?? '(null)') . "\n"; }
    }
} else { echo "  (sem cliente vinculado)\n"; }

echo "\n=== PARTES (case_partes) ===\n";
try {
    $s = $pdo->prepare("SELECT * FROM case_partes WHERE case_id = ? ORDER BY id");
    $s->execute([$id]);
    $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) { echo "  (nenhuma)\n"; }
    foreach ($rows as $r) {
        echo "  --- parte #{$r['id']} | papel: " . ($r['papel'] ?? '?') . "\n";
        foreach ($r as $k => $v) {
            if (in_array($k, ['id','case_id','papel'], true) || $v === null || $v === '') { continue; }
            echo "      $k: $v\n";
        }
    }
} catch (Exception $e) { echo "  ERRO: " . $e->getMessage() . "\n"; }

echo "\n=== CHECKLIST DE DOCUMENTOS (documentos_pendentes) ===\n";
try {
    $s = $pdo->prepare("SELECT descricao, status FROM documentos_pendentes
                        WHERE case_id = ? ORDER BY status, id");
    $s->execute([$id]);
    $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) { echo "  (nenhum)\n"; }
    foreach ($rows as $r) { printf("  [%-8s] %s\n", $r['status'], $r['descricao']); }
} catch (Exception $e) { echo "  ERRO: " . $e->getMessage() . "\n"; }

echo "\n=== ANDAMENTOS ===\n";
try {
    $s = $pdo->prepare("SELECT data_andamento, tipo, descricao FROM case_andamentos
                        WHERE case_id = ? ORDER BY id DESC LIMIT 20");
    $s->execute([$id]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
        echo "  [{$r['data_andamento']}] (" . ($r['tipo'] ?? '-') . ") " . mb_substr((string)$r['descricao'], 0, 200) . "\n";
    }
} catch (Exception $e) { echo "  ERRO: " . $e->getMessage() . "\n"; }

echo "\n=== TAREFAS ===\n";
try {
    $s = $pdo->prepare("SELECT title, status, due_date FROM case_tasks WHERE case_id = ? ORDER BY id DESC LIMIT 15");
    $s->execute([$id]);
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
        printf("  [%-8s] %-60s | vence: %s\n", $r['status'], mb_substr((string)$r['title'],0,60), $r['due_date'] ?: '-');
    }
} catch (Exception $e) { echo "  ERRO: " . $e->getMessage() . "\n"; }

echo "\n=== LEAD VINCULADO (dados comerciais/financeiros) ===\n";
try {
    $s = $pdo->prepare("SELECT * FROM pipeline_leads WHERE linked_case_id = ? OR client_id = ? ORDER BY id DESC LIMIT 2");
    $s->execute([$id, $c['client_id']]);
    $rows = $s->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) { echo "  (nenhum)\n"; }
    foreach ($rows as $r) {
        echo "  --- lead #{$r['id']} | stage: " . ($r['stage'] ?? '?') . " | linked_case_id: " . ($r['linked_case_id'] ?? '-') . "\n";
        foreach (['name','tipo_acao','descricao','observacoes','valor_acao','honorarios_cents','forma_pagamento','exito_percentual'] as $k) {
            if (!empty($r[$k])) { echo "      $k: " . mb_substr((string)$r[$k], 0, 400) . "\n"; }
        }
    }
} catch (Exception $e) { echo "  ERRO: " . $e->getMessage() . "\n"; }

echo "\n=== FIM ===\n";
