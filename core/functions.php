<?php
/**
 * Ferreira & Sá Conecta — Funções Utilitárias
 *
 * Ponto de entrada único — carrega todos os sub-módulos.
 * Para manutenção, edite os arquivos individuais:
 *
 *   functions_utils.php     — e(), redirect(), flash, CSRF, sanitização, formatação, URL, criptografia, paginação, audit_log
 *   functions_auth.php      — roles, permissões, can_access(), _permission_defaults()
 *   functions_notify.php    — notify(), notify_admins(), notify_gestao(), notificar_cliente()
 *   functions_cases.php     — find_or_create_client(), get_checklist_template(), generate_case_checklist()
 *   functions_pipeline.php  — funções legadas can_view_pipeline(), etc.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions_utils.php';
require_once __DIR__ . '/functions_auth.php';
require_once __DIR__ . '/functions_notify.php';
require_once __DIR__ . '/functions_cases.php';
require_once __DIR__ . '/functions_pipeline.php';
require_once __DIR__ . '/functions_cpfcnpj.php';
require_once __DIR__ . '/functions_gamificacao.php';
require_once __DIR__ . '/functions_prazos.php';
