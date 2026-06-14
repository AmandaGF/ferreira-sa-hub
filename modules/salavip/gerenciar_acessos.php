<?php
/**
 * Compatibilidade: nome antigo "gerenciar_acessos.php" (que ficou em bookmarks)
 * redireciona para o arquivo atual "acessos.php" (Gerenciar Acessos da Central VIP).
 * Preserva a query string. Pode ser removido quando não houver mais atalhos antigos.
 */
$qs = isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '' ? '?' . $_SERVER['QUERY_STRING'] : '';
header('Location: acessos.php' . $qs, true, 302);
exit;
