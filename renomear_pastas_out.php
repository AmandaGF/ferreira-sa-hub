<?php
/**
 * DESATIVADO. O Apps Script atual NAO tem handler action=renameFolder — ao
 * receber action desconhecida ele cria uma pasta "Sem nome" (footgun).
 * Enquanto o handler nao existir no script.google.com, este endpoint nao
 * chama o Apps Script. Rename das 3 pastas foi feito manualmente pela Amanda.
 * Ver memoria: apps_script_acao_desconhecida_cria_sem_nome.
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave invalida'); }
header('Content-Type: text/plain; charset=utf-8');
echo "Endpoint desativado: Apps Script sem handler renameFolder.\n";
echo "Adicionar o handler em script.google.com antes de reativar.\n";
