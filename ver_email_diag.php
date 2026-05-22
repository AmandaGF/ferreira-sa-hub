<?php
/**
 * ver_email_diag.php — Diagnóstico do Email Monitor (andamentos por e-mail).
 * Uso: https://ferreiraesa.com.br/conecta/ver_email_diag.php?key=fsa-hub-deploy-2026
 * Script de uso único — remover do repo depois.
 */
require_once __DIR__ . '/core/database.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Negado.'); }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== DIAGNÓSTICO EMAIL MONITOR ===\n";
echo "Agora: " . date('d/m/Y H:i:s') . "\n\n";

// 1. Últimas execuções do cron
echo "── 1. ÚLTIMAS 15 EXECUÇÕES (email_monitor_log) ──\n";
try {
    $rows = $pdo->query("SELECT executado_em, emails_lidos, andamentos_inseridos, emails_ignorados, duplicatas_ignoradas, erros, modo, detalhes
                         FROM email_monitor_log ORDER BY executado_em DESC LIMIT 15")->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        echo "  (nenhuma execução registrada — o cron NUNCA rodou ou a tabela está vazia)\n";
    }
    foreach ($rows as $r) {
        echo sprintf("  %s [%s] lidos=%d inseridos=%d ignorados=%d dup=%d erros=%d\n",
            $r['executado_em'], $r['modo'], $r['emails_lidos'], $r['andamentos_inseridos'],
            $r['emails_ignorados'], $r['duplicatas_ignoradas'], $r['erros']);
        if (!empty($r['detalhes']) && ($r['erros'] > 0 || $r['emails_ignorados'] > 0)) {
            $d = trim($r['detalhes']);
            if (strlen($d) > 600) $d = substr($d, 0, 600) . '…';
            echo "      detalhes: " . str_replace("\n", "\n      ", $d) . "\n";
        }
    }
} catch (Exception $e) { echo "  ERRO: " . $e->getMessage() . "\n"; }

// 2. Pendentes
echo "\n── 2. PENDENTES DE CADASTRO (email_monitor_pendentes, status='pendente') ──\n";
try {
    $tot = (int)$pdo->query("SELECT COUNT(*) FROM email_monitor_pendentes WHERE status='pendente'")->fetchColumn();
    echo "  Total pendentes: $tot\n";
    $rows = $pdo->query("SELECT case_number, polo_ativo, polo_passivo, orgao, ultimo_movimento_data, total_emails_recebidos, primeira_vez, ultima_vez
                         FROM email_monitor_pendentes WHERE status='pendente' ORDER BY ultima_vez DESC LIMIT 40")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        echo sprintf("  CNJ %s | %s x %s\n      órgão: %s | últ.mov: %s | emails: %d | 1ª: %s | últ: %s\n",
            $r['case_number'], mb_substr((string)$r['polo_ativo'],0,40), mb_substr((string)$r['polo_passivo'],0,40),
            mb_substr((string)$r['orgao'],0,50), $r['ultimo_movimento_data'], $r['total_emails_recebidos'],
            $r['primeira_vez'], $r['ultima_vez']);
    }
} catch (Exception $e) { echo "  ERRO: " . $e->getMessage() . "\n"; }

// 3. Últimos andamentos importados por e-mail
echo "\n── 3. ÚLTIMOS 25 ANDAMENTOS IMPORTADOS (tipo_origem='email_pje') ──\n";
try {
    $rows = $pdo->query("SELECT ca.id, ca.case_id, ca.data_andamento, ca.created_at, ca.descricao, cs.case_number, cs.title
                         FROM case_andamentos ca LEFT JOIN cases cs ON cs.id = ca.case_id
                         WHERE ca.tipo_origem = 'email_pje' ORDER BY ca.created_at DESC LIMIT 25")->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) echo "  (nenhum andamento com origem email_pje)\n";
    foreach ($rows as $r) {
        echo sprintf("  #%d caso=%d [%s] %s | importado %s\n      %s\n",
            $r['id'], $r['case_id'], $r['case_number'], $r['data_andamento'], $r['created_at'],
            mb_substr(trim(preg_replace('/\s+/',' ',(string)$r['descricao'])),0,110));
    }
} catch (Exception $e) { echo "  ERRO: " . $e->getMessage() . "\n"; }

// 4. Cobertura: andamentos email_pje por dia (últimos 20 dias)
echo "\n── 4. ANDAMENTOS email_pje POR DIA DE IMPORTAÇÃO (últimos 20 dias) ──\n";
try {
    $rows = $pdo->query("SELECT DATE(created_at) d, COUNT(*) n FROM case_andamentos
                         WHERE tipo_origem='email_pje' AND created_at >= DATE_SUB(NOW(), INTERVAL 20 DAY)
                         GROUP BY DATE(created_at) ORDER BY d DESC")->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) echo "  (nada importado nos últimos 20 dias)\n";
    foreach ($rows as $r) echo "  {$r['d']}: {$r['n']} andamento(s)\n";
} catch (Exception $e) { echo "  ERRO: " . $e->getMessage() . "\n"; }

echo "\n=== FIM ===\n";
