<?php
/**
 * Limpeza one-shot: remove client_id de partes adversas (réu, recorrido,
 * terceiro_interessado, litisconsorte_passivo).
 *
 * Bug histórico: alguns cadastros antigos colocaram client_id em partes
 * que NÃO são nossos clientes (ex: INSS como Réu). Isso fazia aparecer
 * badge "NOSSO CLIENTE" indevido. Esse script zera o client_id dessas
 * partes — não apaga o cliente da tabela clients (só desvincula).
 *
 * Uso: curl https://ferreiraesa.com.br/conecta/migrar_limpar_client_id_partes_adversas.php?key=fsa-hub-deploy-2026
 */
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';

if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') {
    http_response_code(403);
    exit('Forbidden.');
}

header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Limpando client_id de partes adversas ===\n\n";

// Lista o que vai ser afetado (pra log)
$st = $pdo->query("SELECT cp.id, cp.case_id, cp.papel, cp.client_id,
                          COALESCE(cp.razao_social, cp.nome) AS nome_parte,
                          c.name AS cliente_nome
                   FROM case_partes cp
                   LEFT JOIN clients c ON c.id = cp.client_id
                   WHERE cp.papel NOT IN ('autor', 'litisconsorte_ativo')
                     AND cp.client_id IS NOT NULL");
$linhas = $st->fetchAll();

if (empty($linhas)) {
    echo "Nenhuma parte adversa com client_id. Nada a limpar.\n";
    exit;
}

echo count($linhas) . " parte(s) adversa(s) encontrada(s) com client_id setado:\n\n";
foreach ($linhas as $l) {
    echo sprintf(
        "  - parte_id=%d | case_id=%d | papel=%s | parte='%s' | client_id=%d (cliente='%s')\n",
        $l['id'], $l['case_id'], $l['papel'], $l['nome_parte'], $l['client_id'], $l['cliente_nome'] ?? '?'
    );
}

echo "\nLimpando...\n";
$afetadas = $pdo->exec(
    "UPDATE case_partes
     SET client_id = NULL
     WHERE papel NOT IN ('autor', 'litisconsorte_ativo')
       AND client_id IS NOT NULL"
);

echo "OK — $afetadas linha(s) atualizada(s).\n";
echo "\nFim da limpeza.\n";
