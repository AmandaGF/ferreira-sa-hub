<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
error_reporting(E_ALL); ini_set('display_errors','1');
set_time_limit(60);
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();
header('Content-Type: text/plain; charset=utf-8');
function flushln($s){ echo $s . "\n"; ob_flush(); flush(); }

flushln("INICIO");
ob_implicit_flush(true);
while (ob_get_level() > 0) ob_end_flush();

try {
    flushln("== teste 1: total de conversas ==");
    $tot = (int)$pdo->query("SELECT COUNT(*) FROM zapi_conversas")->fetchColumn();
    flushln("  total=$tot");
} catch (Exception $e) { flushln("  ERRO: " . $e->getMessage()); }

try {
    flushln("== teste 2: busca exata por telefone 24999242710 ==");
    $st = $pdo->prepare("SELECT id, contato_nome, contato_telefone, canal, status FROM zapi_conversas WHERE contato_telefone = ? LIMIT 5");
    $st->execute(array('24999242710'));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        flushln("  id={$r['id']} tel={$r['contato_telefone']} canal={$r['canal']} status={$r['status']} nome={$r['contato_nome']}");
    }
} catch (Exception $e) { flushln("  ERRO: " . $e->getMessage()); }

try {
    flushln("== teste 3: busca com prefixo 55 ==");
    $st = $pdo->prepare("SELECT id, contato_nome, contato_telefone, canal, status FROM zapi_conversas WHERE contato_telefone = ? LIMIT 5");
    $st->execute(array('5524999242710'));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        flushln("  id={$r['id']} tel={$r['contato_telefone']} canal={$r['canal']} status={$r['status']} nome={$r['contato_nome']}");
    }
} catch (Exception $e) { flushln("  ERRO: " . $e->getMessage()); }

try {
    flushln("== teste 4: termina em 24999242710 ==");
    $st = $pdo->prepare("SELECT id, contato_nome, contato_telefone, canal, status FROM zapi_conversas WHERE contato_telefone LIKE ? LIMIT 5");
    $st->execute(array('%24999242710'));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        flushln("  id={$r['id']} tel={$r['contato_telefone']} canal={$r['canal']} status={$r['status']} nome={$r['contato_nome']}");
    }
} catch (Exception $e) { flushln("  ERRO: " . $e->getMessage()); }

try {
    flushln("== teste 5: busca por nome Tamires ==");
    $st = $pdo->prepare("SELECT id, contato_nome, contato_telefone, canal, status FROM zapi_conversas WHERE contato_nome LIKE ? LIMIT 10");
    $st->execute(array('%amir%'));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        flushln("  id={$r['id']} tel={$r['contato_telefone']} canal={$r['canal']} status={$r['status']} nome={$r['contato_nome']}");
    }
} catch (Exception $e) { flushln("  ERRO: " . $e->getMessage()); }

flushln("== FIM ==");
