<?php
/**
 * Conta emails UNSEEN do PJe na caixa andamentosfes@gmail.com
 * (sem alterar status — apenas imap_search com SE_UID e count).
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/includes/email_monitor_functions.php';

if (!function_exists('imap_open')) { echo "ext php-imap nao disponivel\n"; exit; }

$mailbox = '{' . IMAP_HOST . ':' . IMAP_PORT . '/imap/ssl/novalidate-cert}INBOX';
$mbox = @imap_open($mailbox, IMAP_USER_FETCH, IMAP_APP_PASS, 0, 1);
if (!$mbox) { echo "Falha conexao IMAP: " . imap_last_error() . "\n"; exit; }

$from = IMAP_FROM_FILTER;

$buscas = array(
    'UNSEEN total (atual)'           => 'UNSEEN FROM "' . $from . '"',
    'UNSEEN desde 15-Apr-2026'       => 'UNSEEN FROM "' . $from . '" SINCE "15-Apr-2026"',
    'UNSEEN desde 01-May-2026'       => 'UNSEEN FROM "' . $from . '" SINCE "01-May-2026"',
    'UNSEEN antes de 15-Apr-2026'    => 'UNSEEN FROM "' . $from . '" BEFORE "15-Apr-2026"',
    'TOTAL na caixa (lidos+nao)'     => 'FROM "' . $from . '"',
);

foreach ($buscas as $rotulo => $criterio) {
    $r = @imap_search($mbox, $criterio, SE_UID);
    $n = is_array($r) ? count($r) : 0;
    printf("  %-32s %d\n", $rotulo . ':', $n);
}

imap_close($mbox);
