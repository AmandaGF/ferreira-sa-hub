<?php
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();
$slug = 'onboarding-colaborador';
$pdo->prepare("DELETE FROM treinamento_quiz WHERE modulo_slug = ?")->execute([$slug]);
$pdo->prepare("DELETE FROM treinamento_progresso WHERE modulo_slug = ?")->execute([$slug]);
$pdo->prepare("DELETE FROM treinamento_modulos WHERE slug = ?")->execute([$slug]);
echo "OK modulo '$slug' removido do banco.\n";
