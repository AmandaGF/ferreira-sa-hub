<?php
/**
 * Ferreira & Sá Conecta — Funções do Pipeline
 *
 * Funções legadas de verificação de acesso ao pipeline e operacional.
 * Mantidas para compatibilidade com código existente.
 */

// ── Funções legadas (wrappers para can_access) ──
function can_view_pipeline(): bool { return can_access('pipeline'); }
function can_move_pipeline_comercial(): bool { return can_access('pipeline_mover_comercial'); }
function can_move_pipeline_cx(): bool { return can_access('pipeline_mover_cx'); }
function can_view_operacional(): bool { return can_access('operacional'); }
function can_move_operacional(): bool { return can_access('operacional_mover'); }
