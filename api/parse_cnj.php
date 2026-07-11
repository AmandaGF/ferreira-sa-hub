<?php
/**
 * Endpoint AJAX: parse do numero CNJ (Amanda 10/07/2026).
 * GET /conecta/api/parse_cnj.php?cnj=XXXXXX
 * Retorna JSON com uf, segmento, tribunal_nome, comarca (se conhecida).
 * Usado no auto-preenchimento dos formularios de cadastro/edicao de case.
 */
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/middleware.php';
require_login();
require_once __DIR__ . '/../core/functions_cnj_parser.php';

header('Content-Type: application/json; charset=utf-8');
echo json_encode(parse_cnj($_GET['cnj'] ?? ''));
