<?php
/**
 * fluxo_chave.php
 *
 * Mostra a chave dedicada do motor de fluxos (armazenada em /files/.fluxo_admin_key).
 * Útil pra Amanda copiar e usar no cronjob, sem precisar acessar o servidor.
 *
 * Protegido pela própria chave OU pela chave legacy (transição).
 *
 * Uso:
 *   curl -s "https://ferreiraesa.com.br/conecta/fluxo_chave.php?key=fsa-hub-deploy-2026"
 *   (depois de gerada, usar a chave nova)
 */

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_fluxos.php';

if (!_fluxo_admin_check_key($_GET['key'] ?? '')) { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');

$chave = _fluxo_admin_key();

echo "=== Chave dedicada do motor de fluxos ===\n\n";
echo "  $chave\n\n";
echo "Use essa chave nos endpoints admin do motor:\n";
echo "  /cron/zapi_fluxo_tick.php\n";
echo "  /toggle_fluxo_executor.php\n";
echo "  /seed_fluxo_demo.php\n";
echo "  /disparar_fluxo_demo.php\n";
echo "  /migrar_zapi_fluxos.php\n";
echo "  /migrar_zapi_fluxos_align.php\n";
echo "  /fluxo_chave.php (esse)\n\n";
echo "Chave legacy ('fsa-hub-deploy-2026') AINDA é aceita durante transição.\n";
echo "Quando todos os consumidores migrarem, removo a aceitação legacy via deploy.\n\n";
echo "Pra atualizar o cronjob no cPanel:\n";
echo "  * * * * * curl -s \"https://ferreiraesa.com.br/conecta/cron/zapi_fluxo_tick.php?key=$chave\" > /dev/null\n";
