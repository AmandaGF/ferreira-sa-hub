<?php
/**
 * Migração: Criar tabela message_templates
 * Acesse: ferreiraesa.com.br/conecta/migrar_mensagens.php?key=fsa-hub-deploy-2026
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';

$pdo = db();

$sql = "CREATE TABLE IF NOT EXISTS `message_templates` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `category` VARCHAR(60) NOT NULL COMMENT 'Categoria: documentos, onboarding, cobranca, audiencia, contrato, geral',
    `title` VARCHAR(190) NOT NULL,
    `body` TEXT NOT NULL COMMENT 'Corpo da mensagem com placeholders: {nome}, {tipo_acao}, etc',
    `placeholders` VARCHAR(500) DEFAULT NULL COMMENT 'Lista de placeholders disponíveis',
    `for_whatsapp` TINYINT(1) NOT NULL DEFAULT 1,
    `for_email` TINYINT(1) NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order` INT NOT NULL DEFAULT 0,
    `created_by` INT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_category` (`category`),
    INDEX `idx_active` (`is_active`),
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

try {
    $pdo->exec($sql);
    echo "Tabela 'message_templates' criada!\n\n";
} catch (Exception $e) {
    echo "ERRO: " . $e->getMessage() . "\n";
}

// Seed com mensagens padrão
$count = (int)$pdo->query("SELECT COUNT(*) FROM message_templates")->fetchColumn();
if ($count > 0) {
    echo "Ja existem $count templates.\n";
    exit;
}

$templates = array(
    // ── DOCUMENTOS ──
    array('documentos', 'Solicitação de documentos - Geral', "Olá, {nome}! Tudo bem?\n\nPara darmos andamento no seu caso, precisamos que envie os seguintes documentos:\n\n- Documento de identidade (RG ou CNH)\n- CPF\n- Comprovante de residência atualizado\n- Comprovante de renda\n\nPode enviar por aqui mesmo, por foto ou PDF.\n\nQualquer dúvida, estou à disposição!\n\nAtt,\nEquipe Ferreira & Sá", '{nome}', 1, 0, 0),

    array('documentos', 'Solicitação de documentos - Alimentos', "Olá, {nome}!\n\nPara darmos andamento na sua ação de alimentos, precisamos dos seguintes documentos:\n\n- RG e CPF (seus e do(s) menor(es))\n- Certidão de nascimento do(s) filho(s)\n- Comprovante de residência\n- Comprovante de renda (se houver)\n- Comprovante de despesas do(s) menor(es) (escola, saúde, alimentação)\n\nEnvie por aqui mesmo. Obrigada!\n\nAtt,\nEquipe Ferreira & Sá", '{nome}', 1, 0, 1),

    array('documentos', 'Solicitação de documentos - Divórcio', "Olá, {nome}!\n\nPara o processo de divórcio, precisamos dos seguintes documentos:\n\n- RG e CPF de ambos os cônjuges\n- Certidão de casamento atualizada\n- Certidão de nascimento dos filhos (se houver)\n- Comprovante de residência\n- Declaração de IR (último exercício)\n- Relação de bens a partilhar\n\nEnvie por aqui. Qualquer dúvida, estamos à disposição!\n\nAtt,\nEquipe Ferreira & Sá", '{nome}', 1, 0, 2),

    array('documentos', 'Solicitação de documentos - Inventário', "Olá, {nome}!\n\nPara o inventário, precisamos dos seguintes documentos:\n\n- Certidão de óbito\n- Certidão de casamento do(a) falecido(a)\n- Certidão de nascimento dos herdeiros\n- Testamento (se houver)\n- Matrícula atualizada dos imóveis\n- CRLV dos veículos\n- Extratos bancários na data do óbito\n- Última declaração de IR do(a) falecido(a)\n\nEnvie por aqui. Obrigada!\n\nAtt,\nEquipe Ferreira & Sá", '{nome}', 1, 0, 3),

    array('documentos', 'Solicitação de documentos - Consumidor', "Olá, {nome}!\n\nPara sua ação de consumidor, precisamos:\n\n- RG e CPF\n- Comprovante de residência\n- Nota fiscal ou comprovante de compra\n- Contrato (se houver)\n- Prints de conversas com a empresa\n- Protocolo de reclamação (SAC/Procon)\n- Fotos do produto/serviço com defeito\n\nEnvie tudo por aqui. Obrigada!\n\nAtt,\nEquipe Ferreira & Sá", '{nome}', 1, 0, 4),

    array('documentos', 'Cobrança de documentos pendentes', "Olá, {nome}! Tudo bem?\n\nPassando para lembrar que ainda estamos aguardando os documentos para dar andamento no seu caso.\n\nSem eles, infelizmente não conseguimos prosseguir. Precisa de ajuda com algum deles?\n\nEstamos à disposição!\n\nAtt,\nEquipe Ferreira & Sá", '{nome}', 1, 0, 5),

    // ── ONBOARDING ──
    array('onboarding', 'Boas-vindas - Novo cliente', "Olá, {nome}! 🎉\n\nSeja muito bem-vindo(a) ao escritório Ferreira & Sá Advocacia!\n\nA partir de agora, nossa equipe estará acompanhando o seu caso. Vamos trabalhar juntos para o melhor resultado possível.\n\nEm breve entraremos em contato para coletar os documentos necessários e agendar uma reunião inicial.\n\nQualquer dúvida, pode nos chamar por aqui!\n\nAtt,\nEquipe Ferreira & Sá\n(24) 99205-0096", '{nome}', 1, 0, 0),

    array('onboarding', 'Agendamento de reunião', "Olá, {nome}!\n\nGostaríamos de agendar uma reunião para alinharmos os próximos passos do seu caso.\n\nQual o melhor dia e horário para você?\n\nNossa sede fica na Rua Dr. Aldrovando de Oliveira, 140 - Ano Bom, Barra Mansa/RJ.\n\nCaso prefira, podemos realizar por videochamada.\n\nAguardamos seu retorno!\n\nAtt,\nEquipe Ferreira & Sá", '{nome}', 1, 0, 1),

    // ── CONTRATO ──
    array('contrato', 'Envio de contrato para assinatura', "Olá, {nome}!\n\nSegue em anexo o contrato de honorários para sua análise e assinatura.\n\nApós a assinatura, daremos início imediato ao seu caso.\n\nQualquer dúvida sobre as cláusulas, estamos à disposição para esclarecer.\n\nAtt,\nEquipe Ferreira & Sá", '{nome}', 1, 0, 0),

    array('contrato', 'Confirmação de contrato assinado', "Olá, {nome}!\n\nConfirmamos o recebimento do seu contrato assinado. ✅\n\nA partir de agora, sua demanda está oficialmente em andamento. Nossa equipe operacional já está cuidando do seu caso.\n\nManteremos você informado(a) sobre cada etapa.\n\nAtt,\nEquipe Ferreira & Sá", '{nome}', 1, 0, 1),

    // ── AUDIÊNCIA ──
    array('audiencia', 'Orientações para audiência', "Olá, {nome}!\n\nSua audiência está agendada. Seguem algumas orientações importantes:\n\n📅 Data: {data_audiencia}\n📍 Local: {local_audiencia}\n\n✅ Chegue com 30 minutos de antecedência\n✅ Leve documento com foto (RG ou CNH)\n✅ Vista-se de forma adequada\n✅ Mantenha a calma e responda apenas o que for perguntado\n✅ Não interrompa a outra parte\n\nNos encontramos lá! Qualquer dúvida, entre em contato.\n\nAtt,\nEquipe Ferreira & Sá", '{nome}, {data_audiencia}, {local_audiencia}', 1, 0, 0),

    // ── ANDAMENTO ──
    array('andamento', 'Atualização de andamento processual', "Olá, {nome}!\n\nPassando para informar sobre o andamento do seu processo:\n\n{atualizacao}\n\nQualquer dúvida, estamos à disposição.\n\nAtt,\nEquipe Ferreira & Sá", '{nome}, {atualizacao}', 1, 0, 0),

    array('andamento', 'Processo distribuído', "Olá, {nome}!\n\nInformamos que seu processo foi distribuído com sucesso! 🎉\n\nNúmero do processo: {numero_processo}\nVara: {vara}\n\nA partir de agora, acompanharemos todos os andamentos e manteremos você informado(a).\n\nAtt,\nEquipe Ferreira & Sá", '{nome}, {numero_processo}, {vara}', 1, 0, 1),

    // ── FINANCEIRO ──
    array('financeiro', 'Lembrete de pagamento', "Olá, {nome}!\n\nEsperamos que esteja tudo bem. Passando para lembrar que há uma parcela de honorários em aberto.\n\nCaso já tenha efetuado o pagamento, por favor desconsidere esta mensagem.\n\nDados para transferência:\nAgência: 0001\nConta Corrente: 5224012-7\nInstituição: 403 - Cora SCD\nNome: Ferreira e Sá Advocacia\nCNPJ: 51.294.223/0001-40\n\nQualquer dúvida, estamos à disposição.\n\nAtt,\nEquipe Ferreira & Sá", '{nome}', 1, 0, 0),

    // ── GERAL ──
    array('geral', 'Mensagem de agradecimento', "Olá, {nome}!\n\nAgradecemos pela confiança em nosso escritório. Foi um prazer atendê-lo(a)!\n\nCaso precise de qualquer assistência jurídica no futuro, não hesite em nos procurar.\n\nDesejamos tudo de melhor!\n\nAtt,\nEquipe Ferreira & Sá Advocacia\n(24) 99205-0096", '{nome}', 1, 0, 0),

    array('geral', 'Indicação de cliente', "Olá, {nome}!\n\nVocê conhece alguém que está precisando de orientação jurídica?\n\nNosso escritório atua nas áreas de Família, Consumidor, Imobiliário, Trabalhista e muito mais.\n\nIndique para nós! Será um prazer atender.\n\n📱 (24) 99205-0096\n🌐 www.ferreiraesa.com.br\n\nAtt,\nEquipe Ferreira & Sá", '{nome}', 1, 0, 1),

    // ── OFÍCIO PENSÃO (que já existia no portal) ──
    array('oficio', 'Ofício pensão - Solicitar contato RH', "Prezado(a), boa tarde!\n\nMeu nome é Amanda, advogada inscrita na OAB-RJ 163.260, e estou atuando em processo de fixação de pensão alimentícia onde um de seus colaboradores é o pai da criança.\n\nInformo que há uma decisão judicial determinando o desconto da pensão alimentícia diretamente na folha de pagamento de seu empregado, e, para que essa medida seja efetivada, necessito enviar o ofício diretamente ao setor de Recursos Humanos da empresa.\n\nAssim, solicito, por gentileza, que me informe o endereço de e-mail ou contato do setor responsável, a fim de que eu possa encaminhar o referido ofício e cumprir o que foi determinado pelo juiz.\n\nDesde já, agradeço pela atenção e colaboração!\n\nFico à disposição para quaisquer esclarecimentos.\n\nAtenciosamente,\nAmanda Ferreira\nOAB-RJ 163.260", '', 0, 1, 0),

    array('oficio', 'Ofício pensão - Envio com dados bancários', "Prezada(o), bom dia!\n\nMeu nome é Amanda, advogada inscrita na OAB-RJ 163.260, e estou atuando em processo de fixação de pensão alimentícia onde um de seus colaboradores é o pai da criança.\n\nInformo que envio em anexo a decisão judicial determinando o desconto da pensão alimentícia diretamente na folha de pagamento do senhor [NOME DO COLABORADOR, CARGO, MATRÍCULA].\n\nDADOS PARA DEPÓSITO:\nConta corrente n. [NÚMERO]\nAgência [NÚMERO], na [BANCO]\nTitularidade da Representante Legal, [NOME], CPF [NÚMERO]\n\nAssim, solicito, por gentileza, que me confirme o recebimento do presente para que possamos informar ao juiz sobre o cumprimento da decisão.\n\nDesde já, agradeço pela atenção e colaboração!\n\nFico à disposição para quaisquer esclarecimentos.\n\nAtenciosamente,\nAmanda Ferreira\nOAB-RJ 163.260\n(24) 99205-0096", '', 0, 1, 1),
);

$stmt = $pdo->prepare(
    "INSERT INTO message_templates (category, title, body, placeholders, for_whatsapp, for_email, sort_order)
     VALUES (?, ?, ?, ?, ?, ?, ?)"
);

$imported = 0;
foreach ($templates as $t) {
    $stmt->execute($t);
    $imported++;
}

echo "$imported templates inseridos!\n";
echo "\nPronto!\n";
