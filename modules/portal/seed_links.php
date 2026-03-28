<?php
/**
 * Importar links padrão para o Portal
 * Execute UMA VEZ pelo navegador e depois APAGUE este arquivo!
 * URL: ferreiraesa.com.br/conecta/modules/portal/seed_links.php
 */

require_once __DIR__ . '/../../core/middleware.php';
require_role('admin');

$pdo = db();

// Verificar se já tem links
$count = (int)$pdo->query('SELECT COUNT(*) FROM portal_links')->fetchColumn();
if ($count > 0) {
    echo '<p>Já existem ' . $count . ' links. <a href="index.php">Ir para Portal</a></p>';
    exit;
}

// [categoria, titulo, url, login, senha, hint, audience, favorito, ordem]
$links = [
    // ── Geral ──
    ['Geral', 'HelpDesk (Chamados)', 'https://www.ferreiraesa.com.br/helpdesk/', '', '', 'Acesso interno', 'internal', 0, 0],
    ['Geral', 'Admin (cadastro cliente / respostas)', 'https://www.ferreiraesa.com.br/admin/', '', '', 'Acesso interno', 'internal', 0, 1],
    ['Geral', 'Forms - Cadastro Inicial dos Clientes', 'https://www.ferreiraesa.com.br/cadastro_cliente', '', '', 'Enviar para cliente', 'client', 1, 2],
    ['Geral', 'Calculadora Pensão Alimentícia', 'https://www.ferreiraesa.com.br/calculadora/', '', '', 'Enviar para cliente', 'client', 1, 3],
    ['Geral', 'Formulário de Convivência', 'https://www.ferreiraesa.com.br/convivencia_form/', '', '', 'Enviar para cliente', 'client', 1, 4],
    ['Geral', 'Sistema - Gastos Mensais (admin)', 'https://www.ferreiraesa.com.br/gastos_pensao/admin/login.php', 'admin', 'Fs@2026!Pensa0#Admin', 'Admin interno para cálculo de pensão', 'internal', 1, 5],
    ['Geral', 'Gastos Mensais (site do cliente)', 'https://www.ferreiraesa.com.br/gastos_pensao', '', '', 'Enviar para cliente', 'client', 1, 6],
    ['Geral', 'Curatela', 'https://www.ferreiraesa.com.br/curatela/', '', '', 'Enviar para cliente', 'client', 0, 7],
    ['Geral', 'Informações para audiência', 'https://www.ferreiraesa.com.br/audiencias/', '', '', 'Enviar para cliente', 'client', 1, 8],
    ['Geral', 'Respostas Formulário Convivência (admin)', 'https://www.ferreiraesa.com.br/convivencia_form/admin', '', '', 'Acesso interno', 'internal', 0, 9],

    // ── Gestão ──
    ['Gestão', 'LegalOne', 'https://firm.legalone.com.br/home', 'Colab_01', 'Colab212823@', '', 'internal', 1, 0],
    ['Gestão', 'CRM (planilha – gestão de oportunidades)', 'https://1drv.ms/x/s!AtQzOGk9m0klh6gI0tn1tBfwUE-aFg?e=OrHdI1', '', '', '', 'internal', 0, 1],
    ['Gestão', 'Office (Login)', '', 'FERREIRA_E_SA_ADVOCACIA@wkxu.onmicrosoft.com', 'Fs2024@00', '', 'internal', 1, 2],
    ['Gestão', 'Tramitação Inteligente (PREV)', '', 'luizeduardo.sa.adv@gmail.com', 'F.s2025', 'Plataforma previdenciária', 'internal', 0, 3],
    ['Gestão', 'Notion', '', '', '', 'Cole o link do workspace/página', 'internal', 0, 4],

    // ── Financeiro ──
    ['Financeiro', 'Asaas', 'https://www.asaas.com/login/auth', '', '', '', 'internal', 0, 0],
    ['Financeiro', 'BB DJO – Depósito Judicial (TED)', 'https://www63.bb.com.br/portalbb/djo/id/resgate/tedDadosConsulta,802,4647,506540,0,1,1.bbx', '', '', '', 'internal', 0, 1],

    // ── Operacional ──
    ['Operacional', 'Google Ads', 'https://ads.google.com/intl/pt-BR_br/start/overview-ha/', '', '', 'Login: advocaciaferreiraesa@gmail.com', 'internal', 0, 0],
    ['Operacional', 'Meta Ads – Gerenciador', 'https://www.facebook.com/business/tools/ads-manager', '', '', 'Usuário: advocaciaferreiraesa', 'internal', 0, 1],
    ['Operacional', 'Business Facebook (principal)', 'https://business.facebook.com/latest?asset_id=104794049270527&business_id=135278729427575', '', '', '', 'internal', 0, 2],
    ['Operacional', 'Google Meu Negócio', 'https://www.google.com/search?q=FERREIRA+E+S%C3%81+ADVOGADO+EM+RESENDE', '', '', '', 'internal', 0, 3],
    ['Operacional', 'BotConversa', 'https://app.botconversa.com.br/', '', '', '', 'internal', 0, 4],
];

$stmt = $pdo->prepare(
    'INSERT INTO portal_links (category, title, url, username, password_encrypted, hint, audience, is_favorite, sort_order, created_by)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);

$userId = current_user_id();
$imported = 0;

foreach ($links as $l) {
    $passEnc = !empty($l[4]) ? encrypt_value($l[4]) : null;
    $stmt->execute([
        $l[0], $l[1], $l[2] ?: null, $l[3] ?: null,
        $passEnc, $l[5] ?: null, $l[6], $l[7], $l[8], $userId
    ]);
    $imported++;
}

echo '<h2 style="font-family:sans-serif;color:green">✓ ' . $imported . ' links importados!</h2>';
echo '<p style="font-family:sans-serif"><a href="index.php">Ir para o Portal</a></p>';
echo '<p style="font-family:sans-serif;color:red"><strong>APAGUE este arquivo do servidor!</strong></p>';
