<?php
/**
 * ============================================================
 * claudin_config.php — Configurações do monitor automático DJEN
 * ============================================================
 *
 * PROPÓSITO:
 *   Centraliza chaves, URLs e parâmetros do robô (Claudin) que
 *   puxa publicações do DJEN, pede resumo/orientação ao Claude
 *   e envia para o endpoint /conecta/api/djen_ingest.php.
 *
 * COMO ESTE ARQUIVO FUNCIONA:
 *   - Faz require_once de core/config.php, que já carrega:
 *       ANTHROPIC_API_KEY, APP_ROOT, timezone, error reporting.
 *   - Adiciona constantes específicas do Claudin (modelo,
 *     endpoint, e-mails, OABs, caminho do log, token manual).
 *
 * O QUE A AMANDA PRECISA EDITAR NESTE ARQUIVO:
 *   NADA. Está 100% pronto. As únicas constantes "sensíveis"
 *   (ANTHROPIC_API_KEY, credenciais do banco) já estão em
 *   core/config.php — arquivo preservado pelo script de deploy
 *   e que nunca vai pro git.
 *
 * DEPENDÊNCIAS:
 *   - core/config.php (deve existir no servidor com chaves ok)
 *
 * SEGURANÇA:
 *   - Pasta /cron/ será protegida pelo .htaccess (Passo 3).
 *   - Este arquivo bloqueia acesso HTTP direto via if no topo.
 *
 * ============================================================
 */

// --- Bloqueia acesso HTTP direto (defesa em profundidade) ---
// Se alguém tentar abrir /cron/claudin_config.php pelo navegador,
// e o .htaccess falhar, este if ainda impede execução.
if (php_sapi_name() !== 'cli' && !defined('CLAUDIN_INCLUDED')) {
    http_response_code(403);
    die('Acesso negado.');
}

// --- Carrega config principal do Hub (DB, ANTHROPIC_API_KEY, APP_ROOT) ---
require_once __DIR__ . '/../core/config.php';

// Garante timezone mesmo se o script for chamado sem o core
date_default_timezone_set('America/Sao_Paulo');

// ============================================================
// 1. Modelo Claude a ser usado
// ============================================================
// Haiku 4.5 é rápido, barato e suficiente para resumos curtos
// de publicações jurídicas. Custo aproximado: R$ 0,01 por pub.
if (!defined('ANTHROPIC_MODEL')) {
    define('ANTHROPIC_MODEL', 'claude-haiku-4-5-20251001');
}


// ============================================================
// 2. Endpoint do próprio Hub Conecta que recebe o payload
// ============================================================
// Esse endpoint já existe e está testado — recebe o texto
// consolidado das publicações e distribui para as pastas.
define('DJEN_INGEST_URL', 'https://ferreiraesa.com.br/conecta/api/djen_ingest.php?key=fsa-hub-deploy-2026');


// ============================================================
// 3. E-mails para alertas automáticos
// ============================================================
// O robô só manda e-mail quando:
//   - não encontrou nenhuma publicação (suspeito)
//   - tem pendências aguardando revisão
//   - aconteceu erro de rede ou infraestrutura
//   - invariante total_parsed ≠ imported+duplicated+pending
// Quando tudo ocorre bem, silêncio total (não vira spam).
define('EMAIL_ALERTAS', array(
    'andamentosfes@gmail.com',
));

// Remetente "técnico" dos e-mails automáticos do Claudin.
define('EMAIL_REMETENTE', 'claudin@ferreiraesa.com.br');


// ============================================================
// 4. OABs monitoradas no DJEN
// ============================================================
// Cada tupla: [numero_oab, uf, nome_advogado].
// O robô consulta a API do DJEN uma vez por OAB e deduplica
// pelo campo "hash" retornado pela API (mesma pub pode vir
// pra duas OABs do mesmo escritório).
define('OABS_MONITORADAS', array(
    array('163260', 'RJ', 'Amanda Guedes Ferreira'),
    array('248755', 'RJ', 'Luiz Eduardo de Sá Silva Marcelino'),
    array('523473', 'SP', 'Luiz Eduardo de Sá Silva Marcelino'),
));


// ============================================================
// 5. Timezone oficial
// ============================================================
define('TIMEZONE', 'America/Sao_Paulo');


// ============================================================
// 6. Caminho do arquivo de log (calculado automaticamente)
// ============================================================
// Usa APP_ROOT definido em core/config.php — funciona em
// qualquer ambiente sem hardcodar /home/USUARIO/.
// Resultado típico no servidor: /home/ferre315/public_html/conecta/cron/logs/claudin.log
define('LOG_PATH', APP_ROOT . '/cron/logs/claudin.log');


// ============================================================
// 7. Rotação do log (10 MB)
// ============================================================
// Quando claudin.log passar de 10 MB, o script renomeia para
// claudin.log.1 e recomeça. Evita arquivo gigante.
define('LOG_MAX_BYTES', 10 * 1024 * 1024);


// ============================================================
// 8. Token secreto para execução manual via dashboard
// ============================================================
// Quando a Amanda clica "Rodar agora" no dashboard, o PHP
// chama o djen_monitor.php via shell_exec passando este token.
// Assim o script aceita execução não-CLI apenas com token
// válido — sem abrir brecha de rede.
define('CLAUDIN_MANUAL_TOKEN', 'clm-7f3a9b2e-xyz-ff-sa-advocacia-2026-trigger');


// ============================================================
// 9. Parâmetros da API DJEN
// ============================================================
define('DJEN_API_URL',      'https://comunicaapi.pje.jus.br/api/v1/comunicacao');
define('DJEN_ITENS_PAGINA', 100);
define('DJEN_TIMEOUT_SEG',  45);


// ============================================================
// 10. Retry para chamada à API Anthropic (resumo/orientação)
// ============================================================
// 3 tentativas com backoff exponencial: 30s → 60s → 120s.
// Se falhar mesmo assim, publicação é importada com marcação
// "[FALHA AI — revisar manualmente]" — nunca aborta execução.
define('ANTHROPIC_RETRY_TENTATIVAS', 3);
define('ANTHROPIC_RETRY_BACKOFF',    array(30, 60, 120));
define('ANTHROPIC_MAX_TOKENS',       400);
define('ANTHROPIC_TIMEOUT_SEG',      30);
