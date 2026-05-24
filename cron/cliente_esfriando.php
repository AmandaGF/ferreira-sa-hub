<?php
/**
 * cron/cliente_esfriando.php — Detector de cliente esfriando (SEM IA).
 *
 * Calcula um score numérico (0-100) pra cada cliente ATIVO com base em
 * sinais de desengajamento. Atualiza clients.esfriando_score + motivos +
 * timestamp. UI usa o score pra mostrar badge laranja/vermelho.
 *
 * Sinais (pontos somam — quanto maior, pior):
 *   +30  — Última mensagem WhatsApp há > 14 dias (cliente parou de falar)
 *   +20  — Último andamento no processo há > 30 dias (caso parado)
 *   +20  — Cobrança em aberto vencida há > 5 dias (inadimplência inicial)
 *   +15  — Tarefa do responsável vencida há > 7 dias (operacional travado)
 *   +10  — Nenhum contato registrado nos últimos 30 dias (silêncio total)
 *
 * Faixas:
 *   < 30 — OK (não destaca)
 *   30-59 — "Atenção" (badge amarelo)
 *   60+  — "Esfriando" (badge laranja/vermelho)
 *
 * Uso (cPanel cron, 1x ao dia, ex: 6h):
 *   curl -s "https://ferreiraesa.com.br/conecta/cron/cliente_esfriando.php?key=fsa-hub-deploy-2026"
 *
 * Não usa IA — custo zero. Cálculo puro em SQL/PHP.
 */

if (php_sapi_name() !== 'cli' && ($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') {
    http_response_code(403); exit('Negado.');
}

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/functions_ia.php';

@set_time_limit(180);
header('Content-Type: text/plain; charset=utf-8');
echo "=== Detector de cliente esfriando ===\n";
echo date('d/m/Y H:i:s') . "\n\n";

if (!ia_feature_ativa('cliente_esfriando')) {
    echo "Feature desligada. Saindo.\n";
    exit;
}

$pdo = db();

// A lógica vive em core/functions_ia.php → ia_recalcular_esfriando_clientes()
// (compartilhada com o botão "Recalcular agora" do Painel do Dia).
$r = ia_recalcular_esfriando_clientes($pdo);
echo "Clientes ativos analisados: " . $r['processados'] . "\n\n";
echo "Resultado:\n";
echo "  🔴 esfriando (≥80):  {$r['esfriando']}\n";
echo "  🟡 atenção  (40-79): {$r['atencao']}\n";
echo "  ✅ ok        (<40):  {$r['ok']}\n\n";

if (!empty($r['top'])) {
    usort($r['top'], function($a, $b) { return $b['score'] - $a['score']; });
    echo "TOP " . min(20, count($r['top'])) . " ALERTAS:\n";
    foreach (array_slice($r['top'], 0, 20) as $a) {
        echo "  #{$a['id']} ({$a['score']}) {$a['name']} — {$a['motivos']}\n";
    }
}
echo "\n=== FIM ===\n";
