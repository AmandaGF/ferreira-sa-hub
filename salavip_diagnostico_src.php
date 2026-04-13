<?php
/**
 * Sala VIP — Diagnóstico do Banco de Dados (SOMENTE LEITURA)
 * Conecta ao banco ferre3151357_conecta e lista estrutura das tabelas.
 * Acesso: ?chave=fs2026
 * NENHUMA alteração é feita no banco — apenas SELECT, SHOW e DESCRIBE.
 */

if (($_GET['chave'] ?? '') !== 'fs2026') {
    http_response_code(403);
    die('Acesso negado.');
}

// Ler credenciais do config.php do Conecta Hub (sem modificar nada)
$configPath = __DIR__ . '/conecta/core/config.php';
if (!file_exists($configPath)) {
    die('Config do Conecta não encontrado em: ' . $configPath);
}

// Definir constantes necessárias antes de incluir o config
// (evita que o config tente definir APP_ROOT relativo)
if (!defined('APP_ROOT')) define('APP_ROOT', __DIR__ . '/conecta');

// Capturar apenas as constantes de banco — incluir config em escopo isolado
(function() use ($configPath) {
    // O config define DB_HOST, DB_NAME, DB_USER, DB_PASS, DB_CHARSET
    require_once $configPath;
})();

// Conectar ao banco (somente leitura)
try {
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    die('Erro de conexão: ' . $e->getMessage());
}

// Tabelas para inspecionar em detalhe
$tabelasAlvo = [
    'cases', 'clients', 'case_andamentos', 'andamentos', 'movimentacoes',
    'documentos', 'documentos_pendentes', 'agenda_eventos', 'agenda',
    'asaas_cobrancas', 'contratos_financeiros', 'notifications', 'audit_log'
];

// Categorias para o checklist
$categorias = [
    'Processos'   => ['cases'],
    'Clientes'    => ['clients'],
    'Andamentos'  => ['case_andamentos', 'andamentos', 'movimentacoes'],
    'Documentos'  => ['documentos', 'documentos_pendentes'],
    'Agenda'      => ['agenda_eventos', 'agenda'],
    'Financeiro'  => ['asaas_cobrancas', 'contratos_financeiros'],
    'Notificações'=> ['notifications'],
    'Auditoria'   => ['audit_log'],
];

// Tabelas para verificar coluna visivel_cliente
$tabelasVisivel = ['case_andamentos', 'andamentos', 'movimentacoes', 'documentos', 'documentos_pendentes', 'agenda_eventos', 'agenda'];

// Buscar todas as tabelas existentes
$allTables = [];
$stmt = $pdo->query("SHOW TABLES");
while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
    $allTables[] = $row[0];
}

// Buscar DESCRIBE das tabelas alvo
$describes = [];
foreach ($tabelasAlvo as $t) {
    if (in_array($t, $allTables)) {
        try {
            $desc = $pdo->query("DESCRIBE `$t`")->fetchAll();
            $describes[$t] = $desc;
        } catch (Exception $e) {
            $describes[$t] = 'ERRO: ' . $e->getMessage();
        }
    }
}

// Verificar visivel_cliente
$visivelCheck = [];
foreach ($tabelasVisivel as $t) {
    if (isset($describes[$t]) && is_array($describes[$t])) {
        $tem = false;
        foreach ($describes[$t] as $col) {
            if ($col['Field'] === 'visivel_cliente') { $tem = true; break; }
        }
        $visivelCheck[$t] = $tem;
    }
}

// Contagem de registros
$counts = [];
foreach ($tabelasAlvo as $t) {
    if (in_array($t, $allTables)) {
        try {
            $counts[$t] = (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
        } catch (Exception $e) {
            $counts[$t] = -1;
        }
    }
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sala VIP — Diagnóstico DB</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { background: #0f172a; color: #e2e8f0; font-family: 'Segoe UI', system-ui, sans-serif; padding: 20px; line-height: 1.6; }
h1 { color: #f8fafc; font-size: 1.5rem; margin-bottom: 8px; }
h2 { color: #38bdf8; font-size: 1.1rem; margin: 24px 0 8px; border-bottom: 1px solid #334155; padding-bottom: 4px; }
h3 { color: #a78bfa; font-size: .95rem; margin: 16px 0 6px; }
.container { max-width: 1100px; margin: 0 auto; }
.badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: .75rem; font-weight: 700; }
.badge-ok { background: #065f46; color: #6ee7b7; }
.badge-no { background: #7f1d1d; color: #fca5a5; }
.badge-count { background: #1e3a5f; color: #93c5fd; }
.info { background: #1e293b; border: 1px solid #334155; border-radius: 8px; padding: 12px 16px; margin-bottom: 8px; font-size: .85rem; }
table { width: 100%; border-collapse: collapse; margin-bottom: 16px; font-size: .82rem; }
th { background: #1e293b; color: #94a3b8; text-align: left; padding: 6px 10px; border: 1px solid #334155; font-weight: 600; }
td { padding: 5px 10px; border: 1px solid #334155; color: #cbd5e1; }
tr:nth-child(even) td { background: rgba(255,255,255,.02); }
.checklist { list-style: none; }
.checklist li { padding: 6px 0; border-bottom: 1px solid #1e293b; display: flex; align-items: center; gap: 8px; }
.check { font-size: 1rem; }
.footer { margin-top: 32px; text-align: center; color: #475569; font-size: .75rem; }
.grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
@media (max-width: 768px) { .grid2 { grid-template-columns: 1fr; } }
</style>
</head>
<body>
<div class="container">

<h1>🔍 Sala VIP — Diagnóstico do Banco de Dados</h1>
<div class="info">
    Banco: <strong><?= htmlspecialchars(DB_NAME) ?></strong> |
    Total de tabelas: <strong><?= count($allTables) ?></strong> |
    Gerado em: <strong><?= date('d/m/Y H:i:s') ?></strong> |
    <span class="badge badge-ok">SOMENTE LEITURA</span>
</div>

<!-- ══════════ CHECKLIST ══════════ -->
<h2>📋 Checklist: Mapeamento de Tabelas por Categoria</h2>
<div class="grid2">
<?php foreach ($categorias as $cat => $tabelas): ?>
<div class="info">
    <h3><?= $cat ?></h3>
    <ul class="checklist">
    <?php foreach ($tabelas as $t): ?>
        <li>
            <?php if (in_array($t, $allTables)): ?>
                <span class="check">✅</span>
                <strong style="color:#6ee7b7;"><?= $t ?></strong>
                <span class="badge badge-count"><?= number_format($counts[$t] ?? 0) ?> registros</span>
            <?php else: ?>
                <span class="check">❌</span>
                <span style="color:#fca5a5;text-decoration:line-through;"><?= $t ?></span>
                <span style="color:#64748b;font-size:.75rem;">não existe</span>
            <?php endif; ?>
        </li>
    <?php endforeach; ?>
    </ul>
</div>
<?php endforeach; ?>
</div>

<!-- ══════════ VISIVEL_CLIENTE ══════════ -->
<h2>👁️ Coluna <code>visivel_cliente</code> — Verificação</h2>
<div class="info">
<?php if (empty($visivelCheck)): ?>
    <p style="color:#94a3b8;">Nenhuma tabela relevante encontrada para verificar.</p>
<?php else: ?>
    <ul class="checklist">
    <?php foreach ($visivelCheck as $t => $tem): ?>
        <li>
            <span class="check"><?= $tem ? '✅' : '⚠️' ?></span>
            <strong><?= $t ?></strong>
            <span class="badge <?= $tem ? 'badge-ok' : 'badge-no' ?>"><?= $tem ? 'TEM visivel_cliente' : 'NÃO TEM visivel_cliente' ?></span>
        </li>
    <?php endforeach; ?>
    </ul>
<?php endif; ?>
</div>

<!-- ══════════ TODAS AS TABELAS ══════════ -->
<h2>📦 Todas as Tabelas do Banco (<?= count($allTables) ?>)</h2>
<div class="info" style="column-count:3;column-gap:16px;">
<?php foreach ($allTables as $t):
    $isAlvo = in_array($t, $tabelasAlvo);
?>
    <div style="padding:2px 0;<?= $isAlvo ? 'color:#38bdf8;font-weight:600;' : '' ?>"><?= $isAlvo ? '→ ' : '  ' ?><?= $t ?></div>
<?php endforeach; ?>
</div>

<!-- ══════════ ESTRUTURA DETALHADA ══════════ -->
<h2>🏗️ Estrutura Detalhada (DESCRIBE)</h2>
<?php foreach ($tabelasAlvo as $t): ?>
    <?php if (isset($describes[$t])): ?>
        <h3>
            <?= $t ?>
            <span class="badge badge-count"><?= number_format($counts[$t] ?? 0) ?> registros</span>
            <?php if (isset($visivelCheck[$t])): ?>
                <span class="badge <?= $visivelCheck[$t] ? 'badge-ok' : 'badge-no' ?>"><?= $visivelCheck[$t] ? '👁️ visivel_cliente' : '⚠️ sem visivel_cliente' ?></span>
            <?php endif; ?>
        </h3>
        <?php if (is_string($describes[$t])): ?>
            <div class="info" style="color:#fca5a5;"><?= htmlspecialchars($describes[$t]) ?></div>
        <?php else: ?>
            <table>
                <thead><tr><th>Campo</th><th>Tipo</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr></thead>
                <tbody>
                <?php foreach ($describes[$t] as $col): ?>
                <tr>
                    <td style="<?= $col['Field'] === 'visivel_cliente' ? 'color:#fbbf24;font-weight:700;' : '' ?>"><?= htmlspecialchars($col['Field']) ?></td>
                    <td><?= htmlspecialchars($col['Type']) ?></td>
                    <td><?= $col['Null'] ?></td>
                    <td><?= $col['Key'] ?: '-' ?></td>
                    <td><?= $col['Default'] !== null ? htmlspecialchars($col['Default']) : '<span style="color:#64748b;">NULL</span>' ?></td>
                    <td><?= $col['Extra'] ?: '-' ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    <?php else: ?>
        <h3 style="color:#64748b;text-decoration:line-through;"><?= $t ?> — não existe</h3>
    <?php endif; ?>
<?php endforeach; ?>

<div class="footer">
    Sala VIP Diagnóstico — Ferreira & Sá Hub — <?= date('Y') ?><br>
    Script somente leitura. Nenhuma alteração foi feita no banco.
</div>

</div>
</body>
</html>
