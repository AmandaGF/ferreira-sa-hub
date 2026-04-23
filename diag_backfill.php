<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') {
    http_response_code(403);
    exit('Chave inválida.');
}
header('Content-Type: text/plain; charset=utf-8');
$path = __DIR__ . '/cron/logs/backfill_progress.json';
echo "=== DIAG backfill Claudin ===\n\n";
echo "Arquivo: $path\n";
echo "Existe: " . (file_exists($path) ? 'sim' : 'não') . "\n";
if (!file_exists($path)) exit;
echo "Tamanho: " . filesize($path) . " bytes\n";
echo "Última modif: " . date('Y-m-d H:i:s', filemtime($path)) . "\n";
echo "Agora: " . date('Y-m-d H:i:s') . "\n";
$dif = time() - filemtime($path);
echo "Tempo desde última modif: " . floor($dif/60) . "min " . ($dif%60) . "s\n\n";
echo "=== CONTEÚDO ===\n";
$d = json_decode(@file_get_contents($path), true);
echo "started_at: " . ($d['started_at'] ?? '—') . "\n";
echo "ended_at: " . ($d['ended_at'] ?? '(vazio — rodando)') . "\n";
echo "total_dias: " . ($d['total_dias'] ?? 0) . "\n";
echo "processados: " . ($d['processados'] ?? 0) . "\n";
echo "pulados: " . ($d['pulados'] ?? 0) . "\n\n";
echo "--- Dias ---\n";
foreach (($d['dias'] ?? []) as $dia) {
    echo sprintf(
        "%s | %-14s | ini %s | fim %s | parsed %s importadas %s | %s\n",
        $dia['data'] ?? '',
        $dia['status'] ?? '',
        $dia['iniciado_em'] ?? '—',
        $dia['finalizado_em'] ?? '—',
        $dia['parsed'] ?? '—',
        $dia['imported'] ?? '—',
        $dia['motivo'] ?? ''
    );
}
