<?php
/**
 * Diag temporário: rastrear onde os dados da Ludmila param.
 * Amanda relatou que Maria diz que a Ludmila preencheu formulário mas
 * os dados não aparecem para gerar documentos.
 *
 * Queima e apaga após uso.
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors', '1');
error_reporting(E_ALL);
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== DIAG LUDMILA ===\n\n";

// 1) form_submissions — todas as submissões com Ludmila no nome
echo "── 1) form_submissions (últimas 15 com 'Ludmila') ──\n";
$st = $pdo->prepare("SELECT id, form_type, protocol, client_name, client_email, client_phone,
                            status, linked_client_id, ip_address, created_at
                     FROM form_submissions
                     WHERE client_name LIKE ? OR client_email LIKE ?
                     ORDER BY created_at DESC LIMIT 15");
$st->execute(array('%udmila%', '%udmila%'));
$subs = $st->fetchAll(PDO::FETCH_ASSOC);
if (!$subs) {
    echo "  ✕ NENHUMA SUBMISSÃO ENCONTRADA COM NOME 'LUDMILA'\n\n";
} else {
    foreach ($subs as $s) {
        echo "  #{$s['id']} · {$s['form_type']} · {$s['created_at']}\n";
        echo "    Nome:      {$s['client_name']}\n";
        echo "    Email:     " . ($s['client_email'] ?: '(vazio)') . "\n";
        echo "    Telefone:  " . ($s['client_phone'] ?: '(vazio)') . "\n";
        echo "    Status:    {$s['status']}\n";
        echo "    Protocolo: {$s['protocol']}\n";
        echo "    Cliente vinculado: " . ($s['linked_client_id'] ? "#{$s['linked_client_id']}" : "✕ NÃO VINCULADO") . "\n";
        echo "    IP: {$s['ip_address']}\n";
        echo "\n";
    }
}

// 2) clients — busca cliente com nome Ludmila
echo "── 2) clients (nome contém 'Ludmila') ──\n";
$st = $pdo->prepare("SELECT id, name, phone, email, cpf, created_at, updated_at
                     FROM clients WHERE name LIKE ? ORDER BY created_at DESC LIMIT 5");
$st->execute(array('%udmila%'));
$clis = $st->fetchAll(PDO::FETCH_ASSOC);
if (!$clis) {
    echo "  ✕ NENHUM CLIENTE ENCONTRADO COM NOME 'LUDMILA'\n\n";
} else {
    foreach ($clis as $c) {
        echo "  #{$c['id']} · {$c['name']}\n";
        echo "    CPF:      " . ($c['cpf'] ?: '(vazio)') . "\n";
        echo "    Telefone: " . ($c['phone'] ?: '(vazio)') . "\n";
        echo "    Email:    " . ($c['email'] ?: '(vazio)') . "\n";
        echo "    Criado:   {$c['created_at']}\n";
        echo "    Atualiz.: {$c['updated_at']}\n";
        echo "\n";
    }
}

// 3) pipeline_leads
echo "── 3) pipeline_leads (nome contém 'Ludmila') ──\n";
$st = $pdo->prepare("SELECT l.id, l.name, l.stage, l.phone, l.case_type, l.valor_acao,
                            l.honorarios_cents, l.forma_pagamento, l.client_id, l.linked_case_id,
                            l.created_at, l.updated_at
                     FROM pipeline_leads l WHERE l.name LIKE ? ORDER BY l.created_at DESC LIMIT 8");
$st->execute(array('%udmila%'));
$leads = $st->fetchAll(PDO::FETCH_ASSOC);
if (!$leads) {
    echo "  ✕ NENHUM LEAD ENCONTRADO COM NOME 'LUDMILA'\n\n";
} else {
    foreach ($leads as $l) {
        echo "  #{$l['id']} · {$l['name']} · stage={$l['stage']}\n";
        echo "    Telefone: " . ($l['phone'] ?: '(vazio)') . "\n";
        echo "    Ação: " . ($l['case_type'] ?: '(vazio)') . " · Valor: " . ($l['valor_acao'] ?: '(vazio)') . " · Honor cents: " . ($l['honorarios_cents'] ?: '0') . "\n";
        echo "    Forma pag: " . ($l['forma_pagamento'] ?: '(vazio)') . "\n";
        echo "    Cliente vinculado: " . ($l['client_id'] ? "#{$l['client_id']}" : "✕") . " | Case vinculado: " . ($l['linked_case_id'] ? "#{$l['linked_case_id']}" : "✕") . "\n";
        echo "    Criado: {$l['created_at']} · Atualiz: {$l['updated_at']}\n";
        echo "\n";
    }
}

// 4) cases (pastas)
echo "── 4) cases (título contém 'Ludmila') ──\n";
$st = $pdo->prepare("SELECT id, title, case_number, stage, client_id, status, created_at
                     FROM cases WHERE title LIKE ? ORDER BY created_at DESC LIMIT 5");
$st->execute(array('%udmila%'));
$cases = $st->fetchAll(PDO::FETCH_ASSOC);
if (!$cases) {
    echo "  ✕ NENHUMA PASTA ENCONTRADA COM 'LUDMILA' NO TÍTULO\n\n";
} else {
    foreach ($cases as $c) {
        echo "  #{$c['id']} · {$c['title']} · stage={$c['stage']} · status={$c['status']}\n";
        echo "    CNJ: " . ($c['case_number'] ?: '(vazio)') . "\n";
        echo "    Cliente: " . ($c['client_id'] ? "#{$c['client_id']}" : "(vazio)") . "\n";
        echo "    Criado: {$c['created_at']}\n";
        echo "\n";
    }
}

// 5) Se achou submission mas sem vínculo, mostrar por que não vinculou
if ($subs) {
    echo "── 5) Diagnóstico do vínculo cliente/submission ──\n";
    foreach ($subs as $s) {
        if ($s['linked_client_id']) {
            echo "  Submission #{$s['id']} → cliente #{$s['linked_client_id']} (OK)\n";
        } else {
            echo "  Submission #{$s['id']} SEM VÍNCULO. Tentando resolver…\n";
            // Simular o dedup: telefone → email → nome
            $tel = $s['client_phone'];
            $email = $s['client_email'];
            $nome = $s['client_name'];
            if ($tel) {
                $telNorm = preg_replace('/\D/', '', $tel);
                $stC = $pdo->prepare("SELECT id, name, phone FROM clients WHERE REGEXP_REPLACE(phone, '[^0-9]', '') = ? LIMIT 3");
                try {
                    $stC->execute(array($telNorm));
                    $match = $stC->fetchAll(PDO::FETCH_ASSOC);
                    if ($match) {
                        echo "    Match por telefone '$telNorm': ";
                        foreach ($match as $m) echo "#{$m['id']} ({$m['name']}) ";
                        echo "\n";
                    } else echo "    Sem match por telefone '$telNorm'\n";
                } catch (Exception $e) {
                    // REGEXP_REPLACE só funciona no MySQL 8+ — fallback manual
                    $stC = $pdo->query("SELECT id, name, phone FROM clients WHERE phone LIKE '%" . substr($telNorm, -8) . "%' LIMIT 3");
                    $match = $stC->fetchAll(PDO::FETCH_ASSOC);
                    if ($match) {
                        echo "    Match por sufixo do telefone: ";
                        foreach ($match as $m) echo "#{$m['id']} ({$m['name']}) ";
                        echo "\n";
                    } else echo "    Sem match por sufixo do telefone\n";
                }
            } else echo "    (submission sem telefone)\n";
        }
    }
    echo "\n";
}

// 6) Payload COMPLETO da submission mais recente (pra ver quais campos vieram)
if ($subs) {
    echo "── 6) Payload COMPLETO da submission #{$subs[0]['id']} ──\n";
    $st = $pdo->prepare("SELECT payload_json FROM form_submissions WHERE id = ?");
    $st->execute(array($subs[0]['id']));
    $payload = (string)$st->fetchColumn();
    $arr = json_decode($payload, true);
    if (is_array($arr)) {
        foreach ($arr as $k => $v) {
            $vs = is_scalar($v) ? (string)$v : json_encode($v, JSON_UNESCAPED_UNICODE);
            echo "  {$k}: " . mb_substr($vs, 0, 200) . "\n";
        }
    } else {
        echo "  (payload não é JSON válido)\n";
        echo substr($payload, 0, 1200) . "\n";
    }
    echo "\n";
}

// 7) QUEM É o cliente #462 (o vinculado pelas outras tabelas)
$vinculadoId = null;
if ($subs) $vinculadoId = (int)$subs[0]['linked_client_id'];
if (!$vinculadoId && !empty($leads)) $vinculadoId = (int)$leads[0]['client_id'];

if ($vinculadoId) {
    echo "── 7) CLIENTE #{$vinculadoId} (o que foi vinculado) ──\n";
    $st = $pdo->prepare("SELECT * FROM clients WHERE id = ?");
    $st->execute(array($vinculadoId));
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if ($c) {
        foreach ($c as $k => $v) {
            $vs = is_scalar($v) ? (string)$v : json_encode($v, JSON_UNESCAPED_UNICODE);
            if ($v === null || $v === '') continue;
            echo "  {$k}: " . mb_substr($vs, 0, 200) . "\n";
        }
    } else {
        echo "  ✕ CLIENTE #{$vinculadoId} NÃO EXISTE MAIS (deletado?)\n";
    }
    echo "\n";

    // 8) Outros leads/cases desse cliente #462
    echo "── 8) Outros leads/cases do cliente #{$vinculadoId} ──\n";
    $st = $pdo->prepare("SELECT id, name, stage, phone, case_type, created_at FROM pipeline_leads WHERE client_id = ? ORDER BY created_at DESC");
    $st->execute(array($vinculadoId));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $l) {
        echo "  LEAD #{$l['id']} · {$l['name']} · stage={$l['stage']} · ação={$l['case_type']} · {$l['created_at']}\n";
    }
    $st = $pdo->prepare("SELECT id, title, stage FROM cases WHERE client_id = ? ORDER BY created_at DESC");
    $st->execute(array($vinculadoId));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $ca) {
        echo "  CASE #{$ca['id']} · {$ca['title']} · stage={$ca['stage']}\n";
    }
    echo "\n";
}

// 9) Todos os leads criados hoje pra ver se algum outro tem os dados que faltam
echo "── 9) Leads criados hoje (últimas 10, stage elaboracao_docs) ──\n";
$st = $pdo->query("SELECT id, name, phone, case_type, valor_acao, honorarios_cents,
                          forma_pagamento, client_id, created_at
                   FROM pipeline_leads
                   WHERE DATE(created_at) = CURDATE() AND stage='elaboracao_docs'
                   ORDER BY created_at DESC LIMIT 10");
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $l) {
    $temDados = ($l['case_type'] || $l['valor_acao'] || $l['honorarios_cents'] > 0);
    $marker = $temDados ? '✓' : '✕';
    echo "  {$marker} #{$l['id']} · {$l['name']} · ação=" . ($l['case_type'] ?: 'VAZIO') .
         " · honor=" . ($l['honorarios_cents'] ?: 0) . "c · " . $l['created_at'] . "\n";
}

echo "\n=== FIM ===\n";
