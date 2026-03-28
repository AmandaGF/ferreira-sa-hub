<?php
/**
 * Seed: Popular Portal de Links com dados completos do Notion
 * Execute pelo navegador logado como ADMIN:
 *   ferreiraesa.com.br/conecta/modules/portal/seed_links.php
 * Depois APAGUE este arquivo!
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../../core/middleware.php';
require_role('admin');

$pdo = db();
$userId = current_user_id();
echo '<pre style="font-family:sans-serif;">';

// Limpar links antigos
$pdo->exec('DELETE FROM portal_links');
echo '<p style="font-family:sans-serif;color:orange;">Links antigos apagados.</p>';

// Estrutura: [categoria, titulo, url, login, senha, hint, audience, favorito, ordem]
$links = array(

    // ══════════════════════════════════════════════
    // 1. FERRAMENTAS DE GESTAO
    // ══════════════════════════════════════════════
    array('Ferramentas de Gestão', 'LegalOne - Gestão de Clientes e Processos', 'https://firm.legalone.com.br/home', 'Colab_01', 'Colab212823@', 'Sistema principal de gestão jurídica', 'internal', 1, 0),
    array('Ferramentas de Gestão', 'CRM - Gestão de Oportunidades', 'https://1drv.ms/x/s!AtQzOGk9m0klh6gI0tn1tBfwUE-aFg?e=OrHdI1', '', '', 'Planilha Excel no OneDrive', 'internal', 0, 1),
    array('Ferramentas de Gestão', 'E-mail Marketing', '', '', '', 'Ferramenta de e-mail marketing do escritório', 'internal', 0, 2),
    array('Ferramentas de Gestão', 'Notion', '', '', '', 'Workspace de organização do escritório', 'internal', 0, 3),
    array('Ferramentas de Gestão', 'Office 365', '', 'FERREIRA_E_SA_ADVOCACIA@wkxu.onmicrosoft.com', 'Fs2024@00', 'Login Microsoft Office', 'internal', 1, 4),
    array('Ferramentas de Gestão', 'Tramitação Inteligente (PREV)', '', 'luizeduardo.sa.adv@gmail.com', 'F.s2025', 'Plataforma previdenciária', 'internal', 0, 5),

    // ══════════════════════════════════════════════
    // 2. FERRAMENTAS OPERACIONAIS
    // ══════════════════════════════════════════════
    array('Ferramentas Operacionais', 'Google ADS', 'https://ads.google.com/intl/pt-BR_br/start/overview-ha/', '', '', 'Login: advocaciaferreiraesa@gmail.com', 'internal', 0, 0),
    array('Ferramentas Operacionais', 'Meta ADS - Gerenciador de Anúncios', 'https://www.facebook.com/business/tools/ads-manager', '', '', 'Usuário: advocaciaferreiraesa', 'internal', 0, 1),
    array('Ferramentas Operacionais', 'Business Facebook (PRINCIPAL)', 'https://business.facebook.com/latest?asset_id=104794049270527&business_id=135278729427575', '', '', 'Principal local de gestão Facebook', 'internal', 1, 2),
    array('Ferramentas Operacionais', 'Google Meu Negócio', 'https://www.google.com/search?q=FERREIRA+E+S%C3%81+ADVOGADO+EM+RESENDE', '', '', 'Perfil do escritório no Google', 'internal', 0, 3),
    array('Ferramentas Operacionais', 'BotConversa', 'https://app.botconversa.com.br/', '', '', 'Automação de WhatsApp', 'internal', 0, 4),
    array('Ferramentas Operacionais', 'Trello', '', '', '', 'Organização de tarefas', 'internal', 0, 5),
    array('Ferramentas Operacionais', 'ZapSign', 'https://app.zapsign.com.br/acesso/entrar', '', '', 'Assinador de contratos - e-mail: amandaguedesferreira@gmail.com', 'internal', 0, 6),
    array('Ferramentas Operacionais', 'Jusfy (Calculadoras)', 'https://app.jusfy.com.br/', '', '', 'Calculadoras jurídicas', 'internal', 0, 7),
    array('Ferramentas Operacionais', 'JusBrasil', 'https://www.jusbrasil.com.br/', '', '', 'e-mail: amandaguedesferreira@gmail.com', 'internal', 0, 8),
    array('Ferramentas Operacionais', 'AROnline', 'https://ar-online.com.br/', '', '', 'Aviso de Recebimento online', 'internal', 0, 9),
    array('Ferramentas Operacionais', 'PESQUISA CNJ PRAZOS', 'https://comunica.pje.jus.br/', '', '', 'Consulta de comunicações processuais', 'internal', 1, 10),
    array('Ferramentas Operacionais', 'E-Notariado (App)', 'https://play.google.com/store/apps/details?id=br.org.enotariado.app', '', '', 'App de serviços notariais', 'internal', 0, 11),
    array('Ferramentas Operacionais', 'E-Notariado - Vídeo Tutorial', 'https://1drv.ms/f/s!AtQzOGk9m0klh8AGipLSgBjCuxEDiQ?e=zbhAiD', '', '', 'Vídeo explicativo do E-Notariado', 'internal', 0, 12),
    array('Ferramentas Operacionais', 'SuperFrete', 'https://superfrete.com/', '', '', 'Plataforma de fretes', 'internal', 0, 13),

    // ══════════════════════════════════════════════
    // 3. FERRAMENTAS FINANCEIRAS
    // ══════════════════════════════════════════════
    array('Ferramentas Financeiras', 'Asaas', 'https://www.asaas.com/login/auth', '', '', 'Plataforma de cobranças e pagamentos', 'internal', 0, 0),
    array('Ferramentas Financeiras', 'BB DJO - Depósito Judicial (TED)', 'https://www63.bb.com.br/portalbb/djo/id/resgate/tedDadosConsulta,802,4647,506540,0,1,1.bbx', '', '', 'Descobrir processo do depósito judicial', 'internal', 0, 1),

    // ══════════════════════════════════════════════
    // 4. FERRAMENTAS DE BUSCAS / DADOS
    // ══════════════════════════════════════════════
    array('Ferramentas de Buscas / Dados', 'SeguroCred', 'https://sistema.segurocred.com.br/', '', '', 'Consultas de crédito', 'internal', 0, 0),
    array('Ferramentas de Buscas / Dados', 'Date Solutions', 'https://www.datecode.com.br/login', '', '', 'e-mail: amandaguedesferreira@gmail.com', 'internal', 0, 1),
    array('Ferramentas de Buscas / Dados', 'Felix Consultas', 'https://sistema.fenixconsultas.com.br/painel/dashboardhome', '', '', 'Sistema pago a parte. Pedir login e senha se for muito necessário o uso.', 'internal', 0, 2),
    array('Ferramentas de Buscas / Dados', 'SEEU - Pessoa Presa', 'https://seeu.pje.jus.br/seeu/', '', '', 'Descobrir se a pessoa está presa', 'internal', 0, 3),
    array('Ferramentas de Buscas / Dados', 'Portal Transparência - Previdência RJ', 'https://www.rioprevidencia.rj.gov.br/PortalRP/Transparência/AposentadosePensionistas/index.htm', '', '', 'Aposentados e Pensionistas do RJ', 'internal', 0, 4),
    array('Ferramentas de Buscas / Dados', 'CENSEC', 'https://censec.org.br/', '', '', 'Central Notarial de Serviços Eletrônicos', 'internal', 0, 5),
    array('Ferramentas de Buscas / Dados', 'Consultar Cadastro de Veículo RJ', 'https://www.rj.gov.br/servico/consultar-cadastro-de-veiculo31', '', '', 'Consulta veicular no estado do RJ', 'internal', 0, 6),

    // ══════════════════════════════════════════════
    // 5. LINKS UTEIS
    // ══════════════════════════════════════════════
    array('Links Úteis', 'Forms - Cadastro Inicial dos Clientes', 'https://www.ferreiraesa.com.br/cadastro_cliente', '', '', 'Enviar para cliente via WhatsApp', 'client', 1, 0),
    array('Links Úteis', 'Calculadora Pensão Alimentícia', 'https://www.ferreiraesa.com.br/calculadora/', '', '', 'Enviar para cliente', 'client', 1, 1),
    array('Links Úteis', 'Formulário de Convivência', 'https://www.ferreiraesa.com.br/convivencia_form/', '', '', 'Enviar para cliente', 'client', 1, 2),
    array('Links Úteis', 'Gastos Mensais (site do cliente)', 'https://www.ferreiraesa.com.br/gastos_pensão', '', '', 'Enviar para cliente', 'client', 1, 3),
    array('Links Úteis', 'Gastos Mensais (admin)', 'https://www.ferreiraesa.com.br/gastos_pensão/admin/login.php', 'admin', 'Fs@2026!Pensa0#Admin', 'Painel admin do cálculo de pensão', 'internal', 0, 4),
    array('Links Úteis', 'Curatela', 'https://www.ferreiraesa.com.br/curatela/', '', '', 'Enviar para cliente', 'client', 0, 5),
    array('Links Úteis', 'Informações para Audiência', 'https://www.ferreiraesa.com.br/audiencias/', '', '', 'Enviar para cliente', 'client', 1, 6),
    array('Links Úteis', 'Lista de Documentos Indispensáveis', 'https://www.notion.so/LISTA-DE-DOCUMENTOS-INDISPENS-VEIS-41cbf4deaf4b48bfbd52ebd63cf76616', '', '', 'Lista no Notion', 'internal', 0, 7),

    // ══════════════════════════════════════════════
    // 6. DIREITO IMOBILIARIO
    // ══════════════════════════════════════════════
    array('Direito Imobiliário', 'Documentos para Matrícula de Imóveis', 'https://www.registrodeimoveis.org.br/servicos/matricula', '', '', 'Portal do Registro de Imóveis', 'internal', 0, 0),
    array('Direito Imobiliário', 'RI Digital', 'https://www.ridigital.gov.br', '', '', 'Registro de Imóveis Digital', 'internal', 0, 1),

    // ══════════════════════════════════════════════
    // 7. DIREITO SUCESSORIO
    // ══════════════════════════════════════════════
    array('Direito Sucessório', 'Certidão de Pagamento ITD - RJ', 'https://atendimentodigitalrj.fazenda.rj.gov.br/pages/private/emissaoCertidãoITD.faces', '', '', 'Imposto sobre Transmissão Causa Mortis', 'internal', 0, 0),

    // ══════════════════════════════════════════════
    // 8. FICHAS DE ATENDIMENTO
    // ══════════════════════════════════════════════
    array('Fichas de Atendimento', 'Família e Sucessões', 'https://www.notion.so/FAM-LIA-E-SUCESS-ES-7daa29d9a9c04217b01e95c9b22aaffa', '', '', 'Ficha de atendimento - Família e Sucessões', 'internal', 1, 0),
    array('Fichas de Atendimento', 'Consumidor', 'https://www.notion.so/CONSUMIDOR-75a5ff82e6484c909bbcdd4c6672482c', '', '', 'Ficha de atendimento - Consumidor', 'internal', 1, 1),

    // ══════════════════════════════════════════════
    // 9. CURSOS
    // ══════════════════════════════════════════════
    array('Cursos', 'Vendas: Vini - CURSO OBRIGATÓRIO', 'https://1drv.ms/f/s!AtQzOGk9m0klh6houKQncF9Yqw32aw?e=uf0JBN', '', '', 'Curso obrigatório para todos os colaboradores', 'internal', 1, 0),

    // ══════════════════════════════════════════════
    // 10. DEPARTAMENTO OPERACIONAL
    // ══════════════════════════════════════════════
    array('Dept. Operacional', 'PLAMJUR - Modelos de Peças', 'https://drive.google.com/drive/folders/11kgOB1P7pU3FwvrLmmc_vlfAUVjObM5w?usp=sharing', '', '', 'Banco de peças jurídicas no Google Drive', 'internal', 1, 0),
    array('Dept. Operacional', 'Kit de Peças - SOBRAL', 'https://www.notion.so/9683ebd3607248aeb84e4ede2ce6afcf', '', '', 'Kit de peças do Sobral no Notion', 'internal', 1, 1),
    array('Dept. Operacional', 'Info sobre Devedor', 'https://www.notion.so/Para-informa-es-sobre-devedor-f41178c6ed98442b9958b2124f03f951', '', '', 'Links úteis para informações sobre devedor', 'internal', 0, 2),
    array('Dept. Operacional', 'Planilha de Execuções', 'https://1drv.ms/x/s!AtQzOGk9m0klh7MaENLmL0P_kAkUng?e=ebVxo9', '', '', 'Controle de execuções no Excel', 'internal', 1, 3),
    array('Dept. Operacional', 'Conciliação e Mediação', 'https://www.notion.so/CONCILIA-O-E-MEDIA-O-1036f16b22c380cc8784fbee2ad68870', '', '', 'Procedimentos de conciliação e mediação', 'internal', 0, 4),
    array('Dept. Operacional', 'Certidões Imobiliárias', 'https://www.registrodeimoveis.org.br/servicos/certidao', '', '', 'Solicitar certidões de imóveis', 'internal', 0, 5),
    array('Dept. Operacional', 'Busca de Mandados de Prisão', 'https://portalbnmp.cnj.jus.br/#/pesquisa-peca', '', '', 'Portal Nacional de Mandados de Prisão - CNJ', 'internal', 0, 6),
    array('Dept. Operacional', 'Localização de Presos - RJ', 'https://www.rj.gov.br/servico/localizacao-de-presos115', '', '', 'Descobrir se a pessoa está presa no RJ', 'internal', 0, 7),
    array('Dept. Operacional', 'Qualificações de Empresas', 'https://www.notion.so/QUALIFICA-ES-DE-EMPRESAS-3408cc0e0f77483eb44c0b80541be5ef', '', '', 'Modelos de qualificação de empresas', 'internal', 0, 8),

    // ══════════════════════════════════════════════
    // 11. AREAS DE ATUACAO
    // ══════════════════════════════════════════════
    array('Áreas de Atuação', 'Imobiliário', 'https://www.notion.so/IMOBILI-RIO-0df65592fc7846aa8e9351c81cf6c29a', '', '', 'Procedimentos e modelos - Direito Imobiliário', 'internal', 0, 0),
    array('Áreas de Atuação', 'Extrajudicial', 'https://www.notion.so/EXTRAJUDICIAL-d5db146e7f4349baab04e172006c5e13', '', '', 'Procedimentos extrajudiciais', 'internal', 0, 1),
    array('Áreas de Atuação', 'Fraudes Bancárias', 'https://www.notion.so/FRAUDES-BANC-RIAS-39c107f576d04f0b932e0adc0c17ab09', '', '', 'Procedimentos - Fraudes Bancárias', 'internal', 0, 2),
    array('Áreas de Atuação', 'Família e Sucessões', 'https://www.notion.so/FAM-LIA-E-SUCESS-ES-10d6f16b22c380b4a54ff5838ee42951', '', '', 'Procedimentos - Família e Sucessões', 'internal', 0, 3),
    array('Áreas de Atuação', 'Mediação e Conciliação Pré-Processuais', 'https://www.notion.so/MEDIA-O-E-CONCILIA-O-PR-PROCESSUAIS-1746f16b22c380729083e44c7bec6562', '', '', 'Procedimentos de mediação pré-processual', 'internal', 0, 4),

    // ══════════════════════════════════════════════
    // 12. MODELOS PARA ENVIOS
    // ══════════════════════════════════════════════
    array('Modelos para Envios', 'E-mail Ofício Pensão - Modelo 1 (Solicitar contato RH)', '', '', '', "Prezado(a), boa tarde!\n\nMeu nome é Amanda, advogada inscrita na OAB-RJ 163.260, e estou atuando em processo de fixação de pensão alimentícia onde um de seus colaboradores é o pai da criança.\n\nInformo que há uma decisão judicial determinando o desconto da pensão alimentícia diretamente na folha de pagamento de seu empregado, e, para que essa medida seja efetivada, necessito enviar o ofício diretamente ao setor de Recursos Humanos da empresa.\n\nAssim, solicito, por gentileza, que me informe o endereço de e-mail ou contato do setor responsável, a fim de que eu possa encaminhar o referido ofício e cumprir o que foi determinado pelo juiz.\n\nDesde já, agradeço pela atenção e colaboração!\n\nFico a disposição para quaisquer esclarecimentos.\n\nAtenciosamente,\nAmanda Ferreira\nOAB-RJ 163.260", 'internal', 1, 0),

    array('Modelos para Envios', 'E-mail Ofício Pensão - Modelo 2 (Envio com dados bancários)', '', '', '', "Prezada(o), bom dia!\n\nMeu nome é Amanda, advogada inscrita na OAB-RJ 163.260, e estou atuando em processo de fixação de pensão alimentícia onde um de seus colaboradores é o pai da criança.\n\nInformo que envio em anexo a decisão judicial determinando o desconto da pensão alimentícia diretamente na folha de pagamento do senhor [NOME DO COLABORADOR, CARGO, MATRÍCULA].\n\nDADOS PARA DEPÓSITO:\nConta corrente n. [NÚMERO]\nAgencia [NÚMERO], na [BANCO]\nTitularidade da Representante Legal, [NOME], CPF [NÚMERO]\n\nAssim, solicito, por gentileza, que me confirme o recebimento do presente para que possamos informar ao juiz sobre o cumprimento da decisão.\n\nDesde já, agradeço pela atenção e colaboração!\n\nFico a disposição para quaisquer esclarecimentos.\n\nAtenciosamente,\nAmanda Ferreira\nOAB-RJ 163.260\n(24) 99205-0096", 'internal', 1, 1),

    // ══════════════════════════════════════════════
    // 13. PLATAFORMAS (LOGIN)
    // ══════════════════════════════════════════════
    array('Plataformas (Login)', 'LegalOne', 'https://firm.legalone.com.br/home', 'Colab_01', 'Colab212823@', 'Sistema de gestão jurídica', 'internal', 1, 0),
    array('Plataformas (Login)', 'JusBrasil', 'https://www.jusbrasil.com.br/iniciar-pesquisa/', 'amandaguedesferreira@gmail.com', 'Fs282123@', 'Pesquisa jurídica', 'internal', 1, 1),
    array('Plataformas (Login)', 'Fire Buscas', 'https://facebusca.com/app', 'FES2026', 'Fs212823@', 'Plataforma de buscas', 'internal', 1, 2),
    array('Plataformas (Login)', 'E-mail de Andamentos Processuais', '', 'andamentosfes@gmail.com', 'Andamentos2023@', 'Login no Webmail para acompanhar andamentos', 'internal', 1, 3),
);

$stmt = $pdo->prepare(
    'INSERT INTO portal_links (category, title, url, username, password_encrypted, hint, audience, is_favorite, sort_order, created_by)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);

$imported = 0;

foreach ($links as $idx => $l) {
    try {
        $passEnc = !empty($l[4]) ? encrypt_value($l[4]) : null;
        $stmt->execute(array(
            $l[0], $l[1], $l[2] ? $l[2] : '', $l[3] ? $l[3] : null,
            $passEnc, $l[5] ? $l[5] : null, $l[6], $l[7], $l[8], $userId
        ));
        $imported++;
    } catch (Exception $ex) {
        echo 'ERRO no link #' . $idx . ' (' . $l[1] . '): ' . $ex->getMessage() . "\n";
    }
}

echo '<h2 style="font-family:sans-serif;color:green">&#10003; ' . $imported . ' links importados com sucesso!</h2>';
echo '<h3 style="font-family:sans-serif;">Categorias criadas:</h3>';
echo '<ul style="font-family:sans-serif;">';

$cats = $pdo->query('SELECT category, COUNT(*) as total FROM portal_links GROUP BY category ORDER BY category')->fetchAll();
foreach ($cats as $c) {
    echo '<li><strong>' . htmlspecialchars($c['category']) . '</strong> - ' . $c['total'] . ' links</li>';
}
echo '</ul>';

echo '<p style="font-family:sans-serif"><a href="index.php">Ir para o Portal</a></p>';
echo '<p style="font-family:sans-serif;color:red"><strong>APAGUE este arquivo do servidor!</strong></p>';
