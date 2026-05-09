<?php
/**
 * Relatório de atividades + tempo de uso da Simone (user_id=5).
 *
 * Fontes:
 *  - users.last_login_at (último login)
 *  - audit_log: actions registradas (quanto, quando, o quê)
 *  - Estima "sessões ativas" agrupando audit_log por janela de inatividade
 *    (gap >= 30min = nova sessão)
 *  - Lista atividades por dia + por tipo de action
 *
 * Uso: curl https://ferreiraesa.com.br/conecta/diag_atividade_simone.php?key=fsa-hub-deploy-2026
 */
require_once __DIR__ . '/core/middleware.php';
require_login();
require_role('admin'); // Só admin (Amanda / Luiz) — relatório sensível de monitoramento

header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

// Identifica Simone — tenta por user_id=5 (memória), fallback por nome
$st = $pdo->query("SELECT id, name, email, role, is_active, last_login_at, created_at
                   FROM users WHERE id = 5 OR LOWER(name) LIKE '%simone%'
                   ORDER BY id LIMIT 1");
$simone = $st->fetch();
if (!$simone) {
    echo "Usuária 'Simone' não encontrada.\n";
    exit;
}

echo "═══════════════════════════════════════════════════════════════\n";
echo "  RELATÓRIO DE ATIVIDADE — " . strtoupper($simone['name']) . "\n";
echo "  Gerado em " . date('d/m/Y H:i') . "\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

echo "👤 IDENTIFICAÇÃO\n";
echo "----------------------------------------------------------------\n";
echo sprintf("  ID:           %d\n", $simone['id']);
echo sprintf("  Nome:         %s\n", $simone['name']);
echo sprintf("  E-mail:       %s\n", $simone['email'] ?: '(sem e-mail)');
echo sprintf("  Cargo:        %s\n", $simone['role']);
echo sprintf("  Ativa:        %s\n", $simone['is_active'] ? 'Sim' : 'NÃO');
echo sprintf("  Cadastrada:   %s\n", $simone['created_at']);
echo sprintf("  Último login: %s\n", $simone['last_login_at'] ?: 'NUNCA');
echo "\n";

$uid = (int)$simone['id'];

// ── Período: últimos 30 dias por padrão (configurável via ?dias=N)
$diasPeriodo = max(1, min(365, (int)($_GET['dias'] ?? 30)));
$dataInicio = date('Y-m-d', strtotime("-{$diasPeriodo} days"));
echo "📅 PERÍODO ANALISADO\n";
echo "----------------------------------------------------------------\n";
echo "  Últimos {$diasPeriodo} dias (desde {$dataInicio})\n";
echo "\n";

// ── 1) Total de ações registradas
$st = $pdo->prepare("SELECT COUNT(*) FROM audit_log WHERE user_id = ? AND created_at >= ?");
$st->execute(array($uid, $dataInicio . ' 00:00:00'));
$totalAcoes = (int)$st->fetchColumn();

echo "📊 RESUMO GERAL\n";
echo "----------------------------------------------------------------\n";
echo sprintf("  Total de ações: %d\n", $totalAcoes);
echo sprintf("  Média/dia:      %.1f\n", $totalAcoes / $diasPeriodo);
echo "\n";

if ($totalAcoes === 0) {
    echo "Sem atividade registrada no período.\n";
    echo "(Audit log captura ações como mover Kanban, editar caso, criar tarefa, etc.)\n";
    exit;
}

// ── 2) Sessões estimadas (gap de 30 min entre ações = nova sessão)
$st = $pdo->prepare("SELECT created_at FROM audit_log
                     WHERE user_id = ? AND created_at >= ?
                     ORDER BY created_at ASC");
$st->execute(array($uid, $dataInicio . ' 00:00:00'));
$timestamps = $st->fetchAll(PDO::FETCH_COLUMN);

$sessoes = array();
$sesInicio = null; $sesFim = null;
$gapNovaSessao = 30 * 60; // 30 min
foreach ($timestamps as $ts) {
    $t = strtotime($ts);
    if ($sesInicio === null) {
        $sesInicio = $t; $sesFim = $t;
    } elseif (($t - $sesFim) > $gapNovaSessao) {
        $sessoes[] = array('inicio' => $sesInicio, 'fim' => $sesFim, 'duracao' => $sesFim - $sesInicio);
        $sesInicio = $t; $sesFim = $t;
    } else {
        $sesFim = $t;
    }
}
if ($sesInicio !== null) {
    $sessoes[] = array('inicio' => $sesInicio, 'fim' => $sesFim, 'duracao' => $sesFim - $sesInicio);
}

$totalSegundos = 0;
foreach ($sessoes as $s) $totalSegundos += $s['duracao'];

function fmt_dur($seg) {
    if ($seg < 60) return $seg . 's';
    if ($seg < 3600) return floor($seg / 60) . 'min';
    $h = floor($seg / 3600);
    $m = floor(($seg % 3600) / 60);
    return $h . 'h' . ($m ? ' ' . $m . 'min' : '');
}

echo "⏱  TEMPO ATIVO ESTIMADO\n";
echo "----------------------------------------------------------------\n";
echo "  Método: agrupa ações por janela de 30min sem atividade.\n";
echo "  Cada bloco contínuo é uma 'sessão'. Sessões com 1 ação só\n";
echo "  contam como 0 (sem como medir duração).\n\n";
echo sprintf("  Sessões estimadas:    %d\n", count($sessoes));
echo sprintf("  Tempo ativo total:    %s\n", fmt_dur($totalSegundos));
echo sprintf("  Sessão média:         %s\n", count($sessoes) ? fmt_dur($totalSegundos / count($sessoes)) : '—');
$sesMaisLonga = 0;
foreach ($sessoes as $s) if ($s['duracao'] > $sesMaisLonga) $sesMaisLonga = $s['duracao'];
echo sprintf("  Sessão mais longa:    %s\n", fmt_dur($sesMaisLonga));
echo "\n";

// ── 3) Atividade por dia
$st = $pdo->prepare("SELECT DATE(created_at) AS dia, COUNT(*) AS qt
                     FROM audit_log
                     WHERE user_id = ? AND created_at >= ?
                     GROUP BY DATE(created_at) ORDER BY dia DESC");
$st->execute(array($uid, $dataInicio . ' 00:00:00'));
$diasComAcao = $st->fetchAll();

echo "📅 ATIVIDADE POR DIA\n";
echo "----------------------------------------------------------------\n";
$diasSemana = array('dom','seg','ter','qua','qui','sex','sáb');
foreach ($diasComAcao as $d) {
    $dt = new DateTime($d['dia']);
    $diaSem = $diasSemana[(int)$dt->format('w')];
    // Tempo daquele dia (soma duracao das sessões com data nesse dia)
    $segDia = 0;
    foreach ($sessoes as $s) {
        if (date('Y-m-d', $s['inicio']) === $d['dia']) $segDia += $s['duracao'];
    }
    echo sprintf("  %s (%s)  %3d ações  %s\n",
        date('d/m/Y', strtotime($d['dia'])), $diaSem, $d['qt'], fmt_dur($segDia)
    );
}
echo "\n";

// ── 4) Top ações
$st = $pdo->prepare("SELECT action, COUNT(*) AS qt
                     FROM audit_log
                     WHERE user_id = ? AND created_at >= ?
                     GROUP BY action ORDER BY qt DESC LIMIT 25");
$st->execute(array($uid, $dataInicio . ' 00:00:00'));
$topAcoes = $st->fetchAll();

echo "🎯 TIPOS DE AÇÃO MAIS FREQUENTES (top 25)\n";
echo "----------------------------------------------------------------\n";
foreach ($topAcoes as $a) {
    echo sprintf("  %5d × %s\n", $a['qt'], $a['action']);
}
echo "\n";

// ── 5) Últimas 30 ações com detalhe
$st = $pdo->prepare("SELECT created_at, action, entity_type, entity_id, LEFT(details, 80) AS det
                     FROM audit_log
                     WHERE user_id = ? AND created_at >= ?
                     ORDER BY created_at DESC LIMIT 30");
$st->execute(array($uid, $dataInicio . ' 00:00:00'));
$ultimas = $st->fetchAll();

echo "📝 ÚLTIMAS 30 AÇÕES\n";
echo "----------------------------------------------------------------\n";
foreach ($ultimas as $u) {
    $alvo = $u['entity_type'] ? $u['entity_type'] . '#' . $u['entity_id'] : '';
    echo sprintf("  %s  %-30s  %-20s  %s\n",
        $u['created_at'], substr($u['action'], 0, 30), substr($alvo, 0, 20), $u['det']
    );
}
echo "\n";

// ── 6) Distribuição por hora do dia
$st = $pdo->prepare("SELECT HOUR(created_at) AS h, COUNT(*) AS qt
                     FROM audit_log
                     WHERE user_id = ? AND created_at >= ?
                     GROUP BY HOUR(created_at) ORDER BY h");
$st->execute(array($uid, $dataInicio . ' 00:00:00'));
$porHora = $st->fetchAll(PDO::FETCH_KEY_PAIR);

echo "🕐 DISTRIBUIÇÃO POR HORA DO DIA\n";
echo "----------------------------------------------------------------\n";
$max = max($porHora ?: array(1));
for ($h = 0; $h < 24; $h++) {
    $qt = (int)($porHora[$h] ?? 0);
    $bar = $qt ? str_repeat('█', max(1, (int)round($qt / $max * 30))) : '';
    echo sprintf("  %02d:00  %4d  %s\n", $h, $qt, $bar);
}
echo "\n";

echo "═══════════════════════════════════════════════════════════════\n";
echo "  Fim do relatório.\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo "\nNotas:\n";
echo "  • Tempo ativo é uma ESTIMATIVA — só captura ações que disparam audit_log\n";
echo "    (mover Kanban, editar caso, criar tarefa, mudar status, etc.).\n";
echo "  • Tempo só lendo (sem clicar) NÃO é medido.\n";
echo "  • Pra mudar período: ?key=...&dias=N (default 30, max 365)\n";
