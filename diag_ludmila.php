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

// 6) Payload das últimas submissões (pra ver se dados úteis chegaram)
if ($subs) {
    echo "── 6) Payload da submission mais recente (primeiros 800 chars) ──\n";
    $latest = $subs[0];
    $st = $pdo->prepare("SELECT payload_json FROM form_submissions WHERE id = ?");
    $st->execute(array($latest['id']));
    $payload = (string)$st->fetchColumn();
    echo substr($payload, 0, 800) . (strlen($payload) > 800 ? "\n… (truncado, tamanho total " . strlen($payload) . ")" : "") . "\n";
}

echo "\n=== FIM ===\n";
