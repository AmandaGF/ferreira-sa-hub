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
    array('Ferramentas de Gestao', 'LegalOne - Gestao de Clientes e Processos', 'https://firm.legalone.com.br/home', 'Colab_01', 'Colab212823@', 'Sistema principal de gestao juridica', 'internal', 1, 0),
    array('Ferramentas de Gestao', 'CRM - Gestao de Oportunidades', 'https://1drv.ms/x/s!AtQzOGk9m0klh6gI0tn1tBfwUE-aFg?e=OrHdI1', '', '', 'Planilha Excel no OneDrive', 'internal', 0, 1),
    array('Ferramentas de Gestao', 'E-mail Marketing', '', '', '', 'Ferramenta de e-mail marketing do escritorio', 'internal', 0, 2),
    array('Ferramentas de Gestao', 'Notion', '', '', '', 'Workspace de organizacao do escritorio', 'internal', 0, 3),
    array('Ferramentas de Gestao', 'Office 365', '', 'FERREIRA_E_SA_ADVOCACIA@wkxu.onmicrosoft.com', 'Fs2024@00', 'Login Microsoft Office', 'internal', 1, 4),
    array('Ferramentas de Gestao', 'Tramitacao Inteligente (PREV)', '', 'luizeduardo.sa.adv@gmail.com', 'F.s2025', 'Plataforma previdenciaria', 'internal', 0, 5),

    // ══════════════════════════════════════════════
    // 2. FERRAMENTAS OPERACIONAIS
    // ══════════════════════════════════════════════
    array('Ferramentas Operacionais', 'Google ADS', 'https://ads.google.com/intl/pt-BR_br/start/overview-ha/', '', '', 'Login: advocaciaferreiraesa@gmail.com', 'internal', 0, 0),
    array('Ferramentas Operacionais', 'Meta ADS - Gerenciador de Anuncios', 'https://www.facebook.com/business/tools/ads-manager', '', '', 'Usuario: advocaciaferreiraesa', 'internal', 0, 1),
    array('Ferramentas Operacionais', 'Business Facebook (PRINCIPAL)', 'https://business.facebook.com/latest?asset_id=104794049270527&business_id=135278729427575', '', '', 'Principal local de gestao Facebook', 'internal', 1, 2),
    array('Ferramentas Operacionais', 'Google Meu Negocio', 'https://www.google.com/search?q=FERREIRA+E+S%C3%81+ADVOGADO+EM+RESENDE', '', '', 'Perfil do escritorio no Google', 'internal', 0, 3),
    array('Ferramentas Operacionais', 'BotConversa', 'https://app.botconversa.com.br/', '', '', 'Automacao de WhatsApp', 'internal', 0, 4),
    array('Ferramentas Operacionais', 'Trello', '', '', '', 'Organizacao de tarefas', 'internal', 0, 5),
    array('Ferramentas Operacionais', 'ZapSign', 'https://app.zapsign.com.br/acesso/entrar', '', '', 'Assinador de contratos - e-mail: amandaguedesferreira@gmail.com', 'internal', 0, 6),
    array('Ferramentas Operacionais', 'Jusfy (Calculadoras)', 'https://app.jusfy.com.br/', '', '', 'Calculadoras juridicas', 'internal', 0, 7),
    array('Ferramentas Operacionais', 'JusBrasil', 'https://www.jusbrasil.com.br/', '', '', 'e-mail: amandaguedesferreira@gmail.com', 'internal', 0, 8),
    array('Ferramentas Operacionais', 'AROnline', 'https://ar-online.com.br/', '', '', 'Aviso de Recebimento online', 'internal', 0, 9),
    array('Ferramentas Operacionais', 'PESQUISA CNJ PRAZOS', 'https://comunica.pje.jus.br/', '', '', 'Consulta de comunicacoes processuais', 'internal', 1, 10),
    array('Ferramentas Operacionais', 'E-Notariado (App)', 'https://play.google.com/store/apps/details?id=br.org.enotariado.app', '', '', 'App de servicos notariais', 'internal', 0, 11),
    array('Ferramentas Operacionais', 'E-Notariado - Video Tutorial', 'https://1drv.ms/f/s!AtQzOGk9m0klh8AGipLSgBjCuxEDiQ?e=zbhAiD', '', '', 'Video explicativo do E-Notariado', 'internal', 0, 12),
    array('Ferramentas Operacionais', 'SuperFrete', 'https://superfrete.com/', '', '', 'Plataforma de fretes', 'internal', 0, 13),

    // ══════════════════════════════════════════════
    // 3. FERRAMENTAS FINANCEIRAS
    // ══════════════════════════════════════════════
    array('Ferramentas Financeiras', 'Asaas', 'https://www.asaas.com/login/auth', '', '', 'Plataforma financeira', 'internal', 0, 0),
    array('Ferramentas Financeiras', 'BB DJO - Deposito Judicial (TED)', 'https://www63.bb.com.br/portalbb/djo/id/resgate/tedDadosConsulta,802,4647,506540,0,1,1.bbx', '', '', 'Descobrir processo do deposito judicial', 'internal', 0, 1),

    // ══════════════════════════════════════════════
    // 4. FERRAMENTAS DE BUSCAS / DADOS
    // ══════════════════════════════════════════════
    array('Ferramentas de Buscas / Dados', 'SeguroCred', 'https://sistema.segurocred.com.br/', '', '', 'Consultas de credito', 'internal', 0, 0),
    array('Ferramentas de Buscas / Dados', 'Date Solutions', 'https://www.datecode.com.br/login', '', '', 'e-mail: amandaguedesferreira@gmail.com', 'internal', 0, 1),
    array('Ferramentas de Buscas / Dados', 'Felix Consultas', 'https://sistema.fenixconsultas.com.br/painel/dashboardhome', '', '', 'Sistema pago a parte. Pedir login e senha se for muito necessario o uso.', 'internal', 0, 2),
    array('Ferramentas de Buscas / Dados', 'SEEU - Pessoa Presa', 'https://seeu.pje.jus.br/seeu/', '', '', 'Descobrir se a pessoa esta presa', 'internal', 0, 3),
    array('Ferramentas de Buscas / Dados', 'Portal Transparencia - Previdencia RJ', 'https://www.rioprevidencia.rj.gov.br/PortalRP/Transparencia/AposentadosePensionistas/index.htm', '', '', 'Aposentados e Pensionistas do RJ', 'internal', 0, 4),
    array('Ferramentas de Buscas / Dados', 'CENSEC', 'https://censec.org.br/', '', '', 'Central Notarial de Servicos Eletronicos', 'internal', 0, 5),
    array('Ferramentas de Buscas / Dados', 'Consultar Cadastro de Veiculo RJ', 'https://www.rj.gov.br/servico/consultar-cadastro-de-veiculo31', '', '', 'Consulta veicular no estado do RJ', 'internal', 0, 6),

    // ══════════════════════════════════════════════
    // 5. LINKS UTEIS
    // ══════════════════════════════════════════════
    array('Links Uteis', 'Forms - Cadastro Inicial dos Clientes', 'https://www.ferreiraesa.com.br/cadastro_cliente', '', '', 'Enviar para cliente via WhatsApp', 'client', 1, 0),
    array('Links Uteis', 'Calculadora Pensao Alimenticia', 'https://www.ferreiraesa.com.br/calculadora/', '', '', 'Enviar para cliente', 'client', 1, 1),
    array('Links Uteis', 'Formulario de Convivencia', 'https://www.ferreiraesa.com.br/convivencia_form/', '', '', 'Enviar para cliente', 'client', 1, 2),
    array('Links Uteis', 'Gastos Mensais (site do cliente)', 'https://www.ferreiraesa.com.br/gastos_pensao', '', '', 'Enviar para cliente', 'client', 1, 3),
    array('Links Uteis', 'Gastos Mensais (admin)', 'https://www.ferreiraesa.com.br/gastos_pensao/admin/login.php', 'admin', 'Fs@2026!Pensa0#Admin', 'Painel admin do calculo de pensao', 'internal', 0, 4),
    array('Links Uteis', 'Curatela', 'https://www.ferreiraesa.com.br/curatela/', '', '', 'Enviar para cliente', 'client', 0, 5),
    array('Links Uteis', 'Informacoes para Audiencia', 'https://www.ferreiraesa.com.br/audiencias/', '', '', 'Enviar para cliente', 'client', 1, 6),
    array('Links Uteis', 'Lista de Documentos Indispensaveis', 'https://www.notion.so/LISTA-DE-DOCUMENTOS-INDISPENS-VEIS-41cbf4deaf4b48bfbd52ebd63cf76616', '', '', 'Lista no Notion', 'internal', 0, 7),

    // ══════════════════════════════════════════════
    // 6. DIREITO IMOBILIARIO
    // ══════════════════════════════════════════════
    array('Direito Imobiliario', 'Documentos para Matricula de Imoveis', 'https://www.registrodeimoveis.org.br/servicos/matricula', '', '', 'Portal do Registro de Imoveis', 'internal', 0, 0),
    array('Direito Imobiliario', 'RI Digital', 'https://www.ridigital.gov.br', '', '', 'Registro de Imoveis Digital', 'internal', 0, 1),

    // ══════════════════════════════════════════════
    // 7. DIREITO SUCESSORIO
    // ══════════════════════════════════════════════
    array('Direito Sucessorio', 'Certidao de Pagamento ITD - RJ', 'https://atendimentodigitalrj.fazenda.rj.gov.br/pages/private/emissaoCertidaoITD.faces', '', '', 'Imposto sobre Transmissao Causa Mortis', 'internal', 0, 0),

    // ══════════════════════════════════════════════
    // 8. FICHAS DE ATENDIMENTO
    // ══════════════════════════════════════════════
    array('Fichas de Atendimento', 'Familia e Sucessoes', 'https://www.notion.so/FAM-LIA-E-SUCESS-ES-7daa29d9a9c04217b01e95c9b22aaffa', '', '', 'Ficha de atendimento - Familia e Sucessoes', 'internal', 1, 0),
    array('Fichas de Atendimento', 'Consumidor', 'https://www.notion.so/CONSUMIDOR-75a5ff82e6484c909bbcdd4c6672482c', '', '', 'Ficha de atendimento - Consumidor', 'internal', 1, 1),

    // ══════════════════════════════════════════════
    // 9. CURSOS
    // ══════════════════════════════════════════════
    array('Cursos', 'Vendas: Vini - CURSO OBRIGATORIO', 'https://1drv.ms/f/s!AtQzOGk9m0klh6houKQncF9Yqw32aw?e=uf0JBN', '', '', 'Curso obrigatorio para todos os colaboradores', 'internal', 1, 0),

    // ══════════════════════════════════════════════
    // 10. DEPARTAMENTO OPERACIONAL
    // ══════════════════════════════════════════════
    array('Dept. Operacional', 'PLAMJUR - Modelos de Pecas', 'https://drive.google.com/drive/folders/11kgOB1P7pU3FwvrLmmc_vlfAUVjObM5w?usp=sharing', '', '', 'Banco de pecas juridicas no Google Drive', 'internal', 1, 0),
    array('Dept. Operacional', 'Kit de Pecas - SOBRAL', 'https://www.notion.so/9683ebd3607248aeb84e4ede2ce6afcf', '', '', 'Kit de pecas do Sobral no Notion', 'internal', 1, 1),
    array('Dept. Operacional', 'Info sobre Devedor', 'https://www.notion.so/Para-informa-es-sobre-devedor-f41178c6ed98442b9958b2124f03f951', '', '', 'Links uteis para informacoes sobre devedor', 'internal', 0, 2),
    array('Dept. Operacional', 'Planilha de Execucoes', 'https://1drv.ms/x/s!AtQzOGk9m0klh7MaENLmL0P_kAkUng?e=ebVxo9', '', '', 'Controle de execucoes no Excel', 'internal', 1, 3),
    array('Dept. Operacional', 'Conciliacao e Mediacao', 'https://www.notion.so/CONCILIA-O-E-MEDIA-O-1036f16b22c380cc8784fbee2ad68870', '', '', 'Procedimentos de conciliacao e mediacao', 'internal', 0, 4),
    array('Dept. Operacional', 'Certidoes Imobiliarias', 'https://www.registrodeimoveis.org.br/servicos/certidao', '', '', 'Solicitar certidoes de imoveis', 'internal', 0, 5),
    array('Dept. Operacional', 'Busca de Mandados de Prisao', 'https://portalbnmp.cnj.jus.br/#/pesquisa-peca', '', '', 'Portal Nacional de Mandados de Prisao - CNJ', 'internal', 0, 6),
    array('Dept. Operacional', 'Localizacao de Presos - RJ', 'https://www.rj.gov.br/servico/localizacao-de-presos115', '', '', 'Descobrir se a pessoa esta presa no RJ', 'internal', 0, 7),
    array('Dept. Operacional', 'Qualificacoes de Empresas', 'https://www.notion.so/QUALIFICA-ES-DE-EMPRESAS-3408cc0e0f77483eb44c0b80541be5ef', '', '', 'Modelos de qualificacao de empresas', 'internal', 0, 8),

    // ══════════════════════════════════════════════
    // 11. AREAS DE ATUACAO
    // ══════════════════════════════════════════════
    array('Areas de Atuacao', 'Imobiliario', 'https://www.notion.so/IMOBILI-RIO-0df65592fc7846aa8e9351c81cf6c29a', '', '', 'Procedimentos e modelos - Direito Imobiliario', 'internal', 0, 0),
    array('Areas de Atuacao', 'Extrajudicial', 'https://www.notion.so/EXTRAJUDICIAL-d5db146e7f4349baab04e172006c5e13', '', '', 'Procedimentos extrajudiciais', 'internal', 0, 1),
    array('Areas de Atuacao', 'Fraudes Bancarias', 'https://www.notion.so/FRAUDES-BANC-RIAS-39c107f576d04f0b932e0adc0c17ab09', '', '', 'Procedimentos - Fraudes Bancarias', 'internal', 0, 2),
    array('Areas de Atuacao', 'Familia e Sucessoes', 'https://www.notion.so/FAM-LIA-E-SUCESS-ES-10d6f16b22c380b4a54ff5838ee42951', '', '', 'Procedimentos - Familia e Sucessoes', 'internal', 0, 3),
    array('Areas de Atuacao', 'Mediacao e Conciliacao Pre-Processuais', 'https://www.notion.so/MEDIA-O-E-CONCILIA-O-PR-PROCESSUAIS-1746f16b22c380729083e44c7bec6562', '', '', 'Procedimentos de mediacao pre-processual', 'internal', 0, 4),

    // ══════════════════════════════════════════════
    // 12. MODELOS PARA ENVIOS
    // ══════════════════════════════════════════════
    array('Modelos para Envios', 'E-mail Oficio Pensao - Modelo 1 (Solicitar contato RH)', '', '', '', "Prezado(a), boa tarde!\n\nMeu nome e Amanda, advogada inscrita na OAB-RJ 163.260, e estou atuando em processo de fixacao de pensao alimenticia onde um de seus colaboradores e o pai da crianca.\n\nInformo que ha uma decisao judicial determinando o desconto da pensao alimenticia diretamente na folha de pagamento de seu empregado, e, para que essa medida seja efetivada, necessito enviar o oficio diretamente ao setor de Recursos Humanos da empresa.\n\nAssim, solicito, por gentileza, que me informe o endereco de e-mail ou contato do setor responsavel, a fim de que eu possa encaminhar o referido oficio e cumprir o que foi determinado pelo juiz.\n\nDesde ja, agradeco pela atencao e colaboracao!\n\nFico a disposicao para quaisquer esclarecimentos.\n\nAtenciosamente,\nAmanda Ferreira\nOAB-RJ 163.260", 'internal', 1, 0),

    array('Modelos para Envios', 'E-mail Oficio Pensao - Modelo 2 (Envio com dados bancarios)', '', '', '', "Prezada(o), bom dia!\n\nMeu nome e Amanda, advogada inscrita na OAB-RJ 163.260, e estou atuando em processo de fixacao de pensao alimenticia onde um de seus colaboradores e o pai da crianca.\n\nInformo que envio em anexo a decisao judicial determinando o desconto da pensao alimenticia diretamente na folha de pagamento do senhor [NOME DO COLABORADOR, CARGO, MATRICULA].\n\nDADOS PARA DEPOSITO:\nConta corrente n. [NUMERO]\nAgencia [NUMERO], na [BANCO]\nTitularidade da Representante Legal, [NOME], CPF [NUMERO]\n\nAssim, solicito, por gentileza, que me confirme o recebimento do presente para que possamos informar ao juiz sobre o cumprimento da decisao.\n\nDesde ja, agradeco pela atencao e colaboracao!\n\nFico a disposicao para quaisquer esclarecimentos.\n\nAtenciosamente,\nAmanda Ferreira\nOAB-RJ 163.260\n(24) 99205-0096", 'internal', 1, 1),

    // ══════════════════════════════════════════════
    // 13. PLATAFORMAS (LOGIN)
    // ══════════════════════════════════════════════
    array('Plataformas (Login)', 'LegalOne', 'https://firm.legalone.com.br/home', 'Colab_01', 'Colab212823@', 'Sistema de gestao juridica', 'internal', 1, 0),
    array('Plataformas (Login)', 'JusBrasil', 'https://www.jusbrasil.com.br/iniciar-pesquisa/', 'amandaguedesferreira@gmail.com', 'Fs282123@', 'Pesquisa juridica', 'internal', 1, 1),
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
            $l[0], $l[1], $l[2] ? $l[2] : null, $l[3] ? $l[3] : null,
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
