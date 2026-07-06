<?php
/**
 * Adiciona MAIS variações de templates do Jorjão.
 * Só INSERE — não apaga nem modifica os existentes.
 * Idempotente: se ja tem uma frase igual, pula (compara pelos primeiros 60 chars).
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors', '1');
error_reporting(E_ALL);
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Adicionar mais templates do Jorjão ===\n\n";

// 15 novas variações por tocada (fora as ja existentes)
$novos = array(

    // ── CONTRATO ASSINADO (+15) ──────────────────────────────
    array('contrato_assinado', "⚽🥅 *É GOOOOOOOOOOOOL!* ⚽🥅\n\nContrato do(a) *[cliente]* assinado! ✍️\n💼 [tipo_caso]\n🎯 Bola na rede por *[comercial]*\n\n_Público em delírio! Vamo que vamo, time!_ 🏟️🔥"),
    array('contrato_assinado', "🎯 *NA MOSCA!* 🎯\n\nMais uma vitória do time comercial!\n👤 Cliente: *[cliente]*\n💼 [tipo_caso]\n🏆 Fechou: *[comercial]*\n📅 [hoje]\n\n_Craque de campeonato, você aí!_ 🥇⚖️"),
    array('contrato_assinado', "🔔🔔🔔 *SINO TOCANDO SEM PARAR!* 🔔🔔🔔\n\n[cliente] agora é da família Ferreira & Sá! 🤝\n💼 [tipo_caso]\n🎯 Fechado por: *[comercial]*\n\n_Bora manter esse ritmo, meu povo!_ 🚀💪"),
    array('contrato_assinado', "💥 *EXPLODIU!* 💥\n\nAcabou de virar cliente: *[cliente]*\n💼 Ação: [tipo_caso]\n👏 Mestre(a) do fechamento: *[comercial]*\n\n_Contrato assinado, coração cheio de gratidão!_ 🙏✨"),
    array('contrato_assinado', "🎊🎊 *SEXTOU COM CONTRATO NA MÃO!* 🎊🎊\n_(bom, hoje é [hoje] mas o clima é de sexta!)_\n\n👤 [cliente]\n💼 [tipo_caso]\n🎯 Craque(a): *[comercial]*\n\n_Papelzinho assinado, alegria garantida!_ 🥳"),
    array('contrato_assinado', "⚖️🏛️ *SENTENÇA: PROCEDENTE!* 🏛️⚖️\n_(o cliente decidiu — Ferreira & Sá foi o(a) escolhido(a)!)_\n\n👤 *[cliente]* · 💼 [tipo_caso]\n🎯 *[comercial]* fechou lindamente\n\n_Bata o martelo, doutor(a)! Contrato assinado!_ 🔨"),
    array('contrato_assinado', "🚀 *DECOLAGEM AUTORIZADA!* 🛫\n\nDestino: sucesso jurídico ✈️\nPassageiro(a): *[cliente]*\nComandante do voo: *[comercial]*\n💼 [tipo_caso] · [hoje]\n\n_Boa viagem, cliente! A tripulação Ferreira & Sá está com você!_ 🎯"),
    array('contrato_assinado', "🎬 *ACABOU DE ENTRAR NO ELENCO!* 🎬\n\n🌟 [cliente] agora é estrela da casa!\n💼 [tipo_caso]\n🎯 Roteiro fechado por: *[comercial]*\n\n_Que venha muita cena boa! 🎭_ 🎊"),
    array('contrato_assinado', "💼✨ *NOVO CONTRATO NA COLEÇÃO!* ✨💼\n\n📌 Cliente: *[cliente]*\n📌 Serviço: [tipo_caso]\n📌 Vendedor(a) de ouro: *[comercial]*\n\n_Cada 'sim' é uma vida transformada. Bora, time!_ 🏆"),
    array('contrato_assinado', "🎯 *ACERTOU EM CHEIO, [comercial]!* 🎯\n\n[cliente] agora tá com a gente! 🤝\n💼 [tipo_caso]\n\n_Assim que se faz — trabalho, dedicação e resultado!_ 👏🔥"),
    array('contrato_assinado', "🥂 *BRINDE! 🥂*\n\n📌 A quem: *[cliente]*, novo(a) cliente da casa\n📌 Por quê: [tipo_caso]\n📌 Graças a quem: *[comercial]*\n\n_Saúde, time! Que venham os próximos!_ 🍾🎉"),
    array('contrato_assinado', "🎪 *ATRAÇÃO PRINCIPAL DO DIA!* 🎪\n\nSenhoras e senhores... 🎤 *[cliente]* acabou de assinar o contrato!\n💼 [tipo_caso]\n🎯 Apresentado por: *[comercial]*\n\n_Espetáculo garantido! 👏👏👏_"),
    array('contrato_assinado', "🏔️ *TOPO DO MUNDO!* 🏔️\n\n[comercial] plantou a bandeira Ferreira & Sá em mais um cume! 🚩\n👤 [cliente] · 💼 [tipo_caso]\n\n_Escalada perfeita! Rumo à próxima montanha!_ 🎯"),
    array('contrato_assinado', "🎁 *PRESENTE DO DIA!* 🎁\n\nContrato novinho, do jeito que a gente gosta!\n👤 *[cliente]* · 💼 [tipo_caso]\n🎯 Embrulhado por: *[comercial]*\n📅 [hoje]\n\n_Data pra guardar! Bora arrasar!_ 🎊"),
    array('contrato_assinado', "🔥🔥🔥 *TÁ VOANDO ESSE COMERCIAL!* 🔥🔥🔥\n\nMais um pra conta!\n👤 [cliente] · 💼 [tipo_caso]\n🎯 Assinatura em [hoje] via *[comercial]*\n\n_Não tem pra ninguém! Vamo com tudo, time!_ 💪⚡"),

    // ── PETIÇÃO DISTRIBUÍDA (+15) ────────────────────────────
    array('peticao_distribuida', "🚀🚀 *AJUIZAMENTO DECOLOU!* 🚀🚀\n\nSaiu da caneta e foi pro fórum:\n👤 *[cliente]* · 💼 [tipo_caso]\n📄 Processo: [numero_processo]\n👏 Autor(a) da peça: *[operacional]*\n\n_Boa, doutora! Bora processar!_ ⚖️💥"),
    array('peticao_distribuida', "⚖️🎪 *ABRE-ALAS PRA PETIÇÃO!* 🎪⚖️\n\nAcabou de entrar em cena a inicial de *[cliente]*!\n📄 [numero_processo]\n💼 [tipo_caso]\n🎯 Redigida com carinho por *[operacional]*\n\n_Que o juiz seja piedoso e a sentença rápida!_ 🙏"),
    array('peticao_distribuida', "📮 *ENTREGUE AO CORREIO... digo, ao PJe!* 📮\n\n[cliente] tem processo tramitando! 🎉\n📄 [numero_processo] · [tipo_caso]\n👏 *[operacional]* mandou lindo!\n\n_Agora o cartório que se vire com o resto kkkk_ ⚖️"),
    array('peticao_distribuida', "🎯 *ALVO ACERTADO: TRIBUNAL!* 🏛️\n\nMais uma inicial ajuizada:\n👤 [cliente] · 💼 [tipo_caso]\n📄 [numero_processo]\n🎯 Craque(a): *[operacional]*\n\n_Fez o serviço direitinho, hein!_ 👏🎊"),
    array('peticao_distribuida', "⚡ *NA VELOCIDADE DA LUZ!* ⚡\n\nPeti de *[cliente]* já tá no fórum!\n💼 [tipo_caso] · Nº [numero_processo]\n🏃‍♀️ Corrida feita por: *[operacional]*\n\n_Nem esquentou a impressora e já foi!_ 🖨️🚀"),
    array('peticao_distribuida', "🏛️⚖️ *ALÔ, PODER JUDICIÁRIO!* ⚖️🏛️\n\nTem processo novo pra vocês:\n📄 [numero_processo]\n👤 [cliente] · 💼 [tipo_caso]\n🎯 Assinado por: *[operacional]*\n\n_Recebam com carinho, viu?_ 🙏✨"),
    array('peticao_distribuida', "🎬 *AÇÃO!* 🎬 _(dessa vez a expressão é literal!)_\n\n👤 Protagonista: *[cliente]*\n💼 Enredo: [tipo_caso]\n📄 Nº do processo: [numero_processo]\n🎯 Direção: *[operacional]*\n\n_Que venham as próximas cenas!_ 🎭⚖️"),
    array('peticao_distribuida', "📄✨ *PETIÇÃO ARTESANAL SAINDO DO FORNO!* ✨📄\n\nRecheio: fatos + direito bem temperados 🌶️\nCliente: *[cliente]*\nAção: [tipo_caso]\nProcesso: [numero_processo]\nChef(a): *[operacional]*\n\n_Dá pra sentir o aroma do pedido procedente daqui!_ 🍰"),
    array('peticao_distribuida', "🥇 *MEDALHA DE OURO PRA [operacional]!* 🥇\n\nAcaba de distribuir com maestria:\n👤 [cliente] · 💼 [tipo_caso]\n📄 [numero_processo]\n\n_Pódio garantido! Que venha a próxima!_ 🏆✨"),
    array('peticao_distribuida', "🎊 *NASCEU UM PROCESSO!* 🎊\n\n👶 Peso: nem sei quantas laudas\n📄 Nome: [numero_processo]\n👤 Cliente: *[cliente]*\n💼 [tipo_caso]\n👏 Parteira(o): *[operacional]*\n\n_Que cresça saudável e com pedido procedente!_ 🍼⚖️"),
    array('peticao_distribuida', "🏹 *FLECHA CERTEIRA!* 🎯\n\n[operacional] mirou e acertou o cartório!\n👤 [cliente] · 💼 [tipo_caso]\n📄 [numero_processo]\n\n_Robin Hood da advocacia, meu povo!_ 🏹⚖️"),
    array('peticao_distribuida', "⚖️💪 *MAIS UMA NA CONTA DO TIME!* 💪⚖️\n\nProcessou geral hoje, hein!\n👤 [cliente] · 💼 [tipo_caso]\n📄 [numero_processo]\n🎯 *[operacional]* na área!\n\n_Bora limpar essa coluna 'aguardando distribuição'!_ 🧹"),
    array('peticao_distribuida', "🎈 *PARABÉNS PRA [operacional]!* 🎈\n\nDistribuição hoje, [hoje]!\n👤 Cliente: *[cliente]*\n💼 Ação: [tipo_caso]\n📄 [numero_processo]\n\n_Merece bolo, refri e três aplausos!_ 🎂👏👏👏"),
    array('peticao_distribuida', "🔥 *ESTÁ QUENTE NO FÓRUM!* 🔥\n\nAcaba de entrar mais um processo:\n📄 [numero_processo]\n👤 [cliente] · 💼 [tipo_caso]\n🎯 *[operacional]* mandou embora!\n\n_Servidor(a) do TJ vai ter trabalho hoje kkkk_ 😂⚖️"),
    array('peticao_distribuida', "🌟 *ESTRELA DO DIA: [operacional]!* 🌟\n\nDistribuiu com estilo:\n👤 *[cliente]* · 💼 [tipo_caso]\n📄 [numero_processo]\n\n_Aplausos pra essa pessoa incrível! 👏👏_"),

    // ── PRAZO CUMPRIDO (+15) ─────────────────────────────────
    array('prazo_cumprido', "🏆 *MEDALHA DE PONTUALIDADE!* 🏆\n\n*[operacional]* fechou o prazo:\n📌 [tipo_prazo]\n👤 [cliente]\n📄 [processo]\n\n_Que orgulho desse time responsável!_ 👏✨"),
    array('prazo_cumprido', "⏳➡️✅ *NO LIMITE MAS FOI!* ⏳\n_(ou com folga, sei lá, o importante é que foi!)_\n\n👤 [cliente] · 📄 [processo]\n📌 [tipo_prazo]\n🎯 Nas mãos de *[operacional]*\n\n_Adrenalina de advogado é assim mesmo, né?_ 😂⚖️"),
    array('prazo_cumprido', "🎯 *ALVO CUMPRIDO!* 🎯\n\n📌 [tipo_prazo] · [cliente]\n📄 [processo]\n🏹 Arqueiro(a) de precisão: *[operacional]*\n\n_Robin Hood da advocacia, meu povo!_ 🏆"),
    array('prazo_cumprido', "🚨 *ALERTA DESATIVADO!* 🚨\n\nMais um prazo entregue no tempo:\n📌 [tipo_prazo]\n👤 [cliente] · 📄 [processo]\n💪 *[operacional]* que fez acontecer\n\n_Pode dormir tranquilo(a) hoje!_ 😴💤"),
    array('prazo_cumprido', "⚡ *RELÂMPAGO CUMPRIU!* ⚡\n\n*[operacional]* voou pra entregar:\n📌 [tipo_prazo]\n👤 [cliente]\n📄 [processo]\n\n_Mais rápido(a) que juiz assinando sentença de mérito_ 🌩️😅"),
    array('prazo_cumprido', "🎉 *SEM PRECLUSÃO POR AQUI!* 🎉\n\n📌 Prazo: [tipo_prazo]\n👤 Cliente: [cliente]\n📄 Processo: [processo]\n👏 Herói(oína) do dia: *[operacional]*\n\n_Preclusão? Nem sei o que é isso, meu povo!_ 💪⚖️"),
    array('prazo_cumprido', "🏁 *CHECKERED FLAG!* 🏁\n_(no automobilismo, quer dizer que o carro completou o circuito)_\n\nPrazo de *[tipo_prazo]* concluído por *[operacional]*!\n👤 [cliente] · 📄 [processo]\n\n_Ayrton Senna da advocacia detected!_ 🏎️🏆"),
    array('prazo_cumprido', "🧗 *ESCALOU E CHEGOU AO TOPO!* 🧗\n\n[operacional] venceu mais uma:\n📌 [tipo_prazo]\n👤 [cliente]\n📄 [processo]\n\n_Everest do prazo processual conquistado!_ 🏔️🏆"),
    array('prazo_cumprido', "🎊 *PONTUALIDADE BRITÂNICA!* 🎊\n\n🍵 [operacional] parece Rainha Elizabeth II hoje:\n📌 [tipo_prazo]\n👤 [cliente]\n📄 [processo]\n\n_Chegou no horário exato! God save the operacional!_ 👑🇬🇧"),
    array('prazo_cumprido', "🎭 *SEM DRAMA HOJE!* 🎭\n\nPrazo entregue sem correria de última hora:\n📌 [tipo_prazo]\n👤 [cliente]\n📄 [processo]\n🎯 *[operacional]* que planejou direitinho\n\n_Assim que se faz — organização é tudo!_ 📅✨"),
    array('prazo_cumprido', "🧘 *TRANQUILIDADE NIVEL EXPERT!* 🧘\n\n*[operacional]* zen mode ativado — prazo entregue:\n📌 [tipo_prazo]\n👤 [cliente] · 📄 [processo]\n\n_Ohmmm... produtividade elevada!_ 🕉️⚖️"),
    array('prazo_cumprido', "🎯 *ARQUEIRO(A) DO ANO!* 🎯\n\nCumpriu com precisão cirúrgica:\n📌 [tipo_prazo]\n👤 [cliente]\n📄 [processo]\n🏹 [operacional] no comando\n\n_Cada tiro, um acerto! Sensacional!_ 🏆"),
    array('prazo_cumprido', "🥊 *NOCAUTE NO PRAZO!* 🥊\n\n💥 *[operacional]* venceu a luta:\n📌 [tipo_prazo]\n👤 [cliente]\n📄 [processo]\n\n_Round 1: preclusão. Vencedor(a): [operacional]!_ 🏆🥊"),
    array('prazo_cumprido', "🎂 *DIA DE COMEMORAR!* 🎂\n\nPrazo processual entregue:\n📌 [tipo_prazo]\n👤 Cliente: [cliente]\n📄 [processo]\n👏 *[operacional]* fez bonito\n\n_Merece o pedaço de bolo maior! 🍰_"),
    array('prazo_cumprido', "🌟 *SUPERSTAR DO DIA!* 🌟\n\n[operacional] entregou:\n📌 [tipo_prazo]\n👤 [cliente]\n📄 [processo]\n\n_Hollywood, LA. Cinema, glamour e prazo cumprido!_ 🎬✨"),

    // ── NOVIDADE NO HUB (+8) ─────────────────────────────────
    array('novidade_hub', "🎁🎁🎁 *SURPRESA QUENTINHA!* 🎁🎁🎁\n\n✨ *[titulo]*\n\n[descricao]\n\n📚 Treinamento aqui: [link]\n\n_Bora aprender fazendo, meu povo! Ponto no ranking te aguarda!_ 🏆"),
    array('novidade_hub', "⚡ *UPDATE NO HUB!* ⚡\n\n🆕 *[titulo]*\n\n[descricao]\n\n🎓 Vem estudar: [link]\n\n_Ficou mais fácil o dia a dia! Faz o treinamento pra desbloquear o pod!_ 🎯"),
    array('novidade_hub', "📣 *ATENÇÃO, TIME!* 📣\n\nSaiu do forno agora:\n🎯 *[titulo]*\n\n[descricao]\n\n👉 Treinamento rapidinho: [link]\n\n_Escritório antenado é escritório vencedor!_ 🏆✨"),
    array('novidade_hub', "🚀🚀 *NOVA FUNÇÃO EM ÓRBITA!* 🚀🚀\n\n🌍 *[titulo]*\n\n[descricao]\n\n🎓 Curso rápido: [link]\n\n_Não fica no chão, vem voar com a gente!_ ✈️"),
    array('novidade_hub', "🎪 *SHOW ATRAÇÃO PRINCIPAL!* 🎪\n\nSenhoras e senhores, com vocês...\n🌟 *[titulo]*\n\n[descricao]\n\n🎓 Assiste a apresentação: [link]\n\n_Ingresso grátis pra equipe! 🎫_"),
    array('novidade_hub', "🎉 *FEATURE FRESQUINHA NO CARDÁPIO!* 🎉\n\n🍽️ *[titulo]*\n\n[descricao]\n\n👨‍🍳 Receita completa: [link]\n\n_Bom apetite, time! E deixa ponto no ranking de gorjeta!_ 🏆💰"),
    array('novidade_hub', "💡 *IDEIA BRILHANTE VIROU REALIDADE!* 💡\n\n✨ *[titulo]*\n\n[descricao]\n\n🎓 Como usar: [link]\n\n_Escritório inovador tem time que aprende sempre!_ 📚⚡"),
    array('novidade_hub', "🎯 *LANÇAMENTO EXCLUSIVO!* 🎯\n\nAcaba de sair no Hub:\n🌟 *[titulo]*\n\n[descricao]\n\n🎓 Aprende aqui: [link]\n\n_Você é o(a) primeiro(a) a saber! Bora testar!_ 🚀"),

);

// Idempotência: compara pelos primeiros 60 chars pra evitar duplicar em rodadas
$stExiste = $pdo->prepare("SELECT COUNT(*) FROM jorjao_templates WHERE tocada = ? AND LEFT(template, 60) = ?");
$stInsert = $pdo->prepare("INSERT INTO jorjao_templates (tocada, template, ativo, ordem) VALUES (?, ?, 1, ?)");

$maxOrdem = array();
foreach ($pdo->query("SELECT tocada, MAX(ordem) AS m FROM jorjao_templates GROUP BY tocada")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $maxOrdem[$r['tocada']] = (int)$r['m'];
}

$ins = 0; $pulou = 0;
foreach ($novos as $n) {
    list($tocada, $tpl) = $n;
    $chave = mb_substr($tpl, 0, 60);
    $stExiste->execute(array($tocada, $chave));
    if ((int)$stExiste->fetchColumn() > 0) { $pulou++; continue; }
    $maxOrdem[$tocada] = isset($maxOrdem[$tocada]) ? $maxOrdem[$tocada] + 1 : 100;
    $stInsert->execute(array($tocada, $tpl, $maxOrdem[$tocada]));
    $ins++;
}

echo "✓ {$ins} templates novos inseridos\n";
echo "✓ {$pulou} pulados (ja existiam com mesma abertura)\n\n";

// Total por tocada
echo "=== Total atual por tocada ===\n";
foreach ($pdo->query("SELECT tocada, COUNT(*) AS n FROM jorjao_templates WHERE ativo = 1 GROUP BY tocada ORDER BY tocada")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "  {$r['tocada']}: {$r['n']} variações\n";
}
echo "\n=== FIM ===\n";
