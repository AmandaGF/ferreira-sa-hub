<?php
/**
 * Ferreira & Sá Conecta — Endpoint único de busca CPF/CNPJ
 *
 * GET /conecta/api/buscar_documento.php?doc=00000000000
 * Detecta automaticamente CPF (11) ou CNPJ (14)
 * Retorna JSON com dados encontrados
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// Forçar reload do functions_cpfcnpj.php
if (function_exists('opcache_invalidate')) {
    @opcache_invalidate(__DIR__ . '/../core/functions_cpfcnpj.php', true);
    @opcache_invalidate(__DIR__ . '/../core/functions.php', true);
}

// Permitir acesso público (formulários públicos) e autenticado
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/functions.php';

$doc = isset($_GET['doc']) ? preg_replace('/\D/', '', $_GET['doc']) : '';

if (!$doc || (strlen($doc) !== 11 && strlen($doc) !== 14)) {
    echo json_encode(array('erro' => 'Informe CPF (11 dígitos) ou CNPJ (14 dígitos)'));
    exit;
}

$resultado = buscar_documento($doc);

// Audit log (se autenticado)
if (isset($_SESSION['user']['id'])) {
    $tipo = strlen($doc) === 11 ? 'cpf' : 'cnpj';
    $fonte = isset($resultado['fonte']) ? $resultado['fonte'] : 'erro';
    try {
        audit_log('consulta_' . $tipo, null, null, $doc . ' → ' . $fonte);
    } catch (Exception $e) {}
}

echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
