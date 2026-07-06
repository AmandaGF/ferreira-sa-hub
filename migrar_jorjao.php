<?php
/**
 * Migração: expansão do Jorjão pra 5 tocadas com mensagens variadas.
 * - Nova tabela jorjao_templates (uma linha por variação de mensagem)
 * - Nova coluna cases.jorjao_distribuicao_tocado (trava anti-duplicidade)
 * - Killswitches por tocada em configuracoes
 * - Seeds com 4-5 templates engracados por tocada (estilo Jorjão)
 *
 * Amanda 06/07/2026.
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors', '1');
error_reporting(E_ALL);
while (ob_get_level() > 0) { ob_end_clean(); }
register_shutdown_function(function() {
    $e = error_get_last();
    if ($e && in_array($e['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR))) {
        echo "\n[FATAL] {$e['message']} em {$e['file']}:{$e['line']}\n";
    }
});
set_time_limit(300);
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();
function _flush($msg = '') { if ($msg !== '') echo $msg; @ob_flush(); @flush(); }

echo "=== Migração Jorjão (expansão pra 5 tocadas) ===\n\n";

// 1) Tabela de templates (variações do Jorjão)
$pdo->exec("CREATE TABLE IF NOT EXISTS jorjao_templates (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tocada ENUM('contrato_assinado','peticao_distribuida','prazo_cumprido','novidade_hub') NOT NULL,
    template TEXT NOT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    ordem INT NOT NULL DEFAULT 0,
    ultima_vez_usado DATETIME NULL,
    criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tocada_ativo (tocada, ativo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
_flush("✓ Tabela jorjao_templates criada/verificada\n");

// 2) Coluna trava anti-duplicidade em cases (petição distribuída)
try {
    $pdo->exec("ALTER TABLE cases ADD COLUMN jorjao_distribuicao_tocado TINYINT(1) NOT NULL DEFAULT 0");
    _flush("✓ Coluna cases.jorjao_distribuicao_tocado adicionada\n");
} catch (Exception $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false) {
        echo "  [SKIP] cases.jorjao_distribuicao_tocado ja existe\n";
    } else throw $e;
}

// Marca cases JA existentes com case_number ou em_andamento como 'tocado' pra
// não bombardear o grupo com anos de historico ao ligar a feature.
$pdo->exec("UPDATE cases SET jorjao_distribuicao_tocado = 1
            WHERE jorjao_distribuicao_tocado = 0
              AND (case_number IS NOT NULL AND case_number <> '' OR stage = 'em_andamento')");
_flush("✓ Cases historicos marcados como tocado (só dispara pra novos daqui pra frente)\n");

// 3) Killswitches por tocada
$switches = array(
    'jorjao_peticao_distribuida_ativo' => '0',   // desligado por default — Amanda liga quando revisar
    'jorjao_prazo_cumprido_ativo'      => '0',
    'jorjao_novidade_hub_ativo'        => '1',   // manual, ligado por default (voce clica pra disparar)
    'jorjao_resumo_diario_ativo'       => '0',
    'jorjao_resumo_diario_hora'        => '19',
    'jorjao_resumo_diario_min_msgs'    => '5',   // não resumir se dia teve < 5 msgs
);
foreach ($switches as $chave => $valor) {
    $pdo->prepare("INSERT IGNORE INTO configuracoes (chave, valor) VALUES (?, ?)")
        ->execute(array($chave, $valor));
}
_flush("✓ Killswitches criados (todos DESLIGADOS por default — voce ativa quando quiser)\n");

// 4) Seeds — templates engraçados por tocada
// Variáveis suportadas por tocada:
//   contrato_assinado:    [cliente] [comercial] [valor] [tipo_caso] [hoje]
//   peticao_distribuida:  [cliente] [operacional] [tipo_caso] [numero_processo] [hoje]
//   prazo_cumprido:       [cliente] [operacional] [tipo_prazo] [processo] [hoje]
//   novidade_hub:         [titulo] [descricao] [link] [hoje]
$seeds = array(
    // ── CONTRATO ASSINADO (5 variações) ──────────────────────────────
    array('contrato_assinado', "🎉🔔 *CONTRATO FECHADO!* 🔔🎉\n\nBora comemorar, time! ✨\n\n👤 Cliente: *[cliente]*\n💼 Caso: [tipo_caso]\n🎯 Fechado por: *[comercial]*\n📅 Data: [hoje]\n\n_Mais uma família escolheu a Ferreira & Sá!_ 💪\n\n🚀 Cada contrato é uma vida transformada. Bora com tudo, galera! 🏆"),
    array('contrato_assinado', "⚖️🚀 *GOOOOOOL DA [comercial]!* ⚽\n\n[cliente] fechou! Não teve pra ninguém! 💥\n\n💼 Caso: [tipo_caso]\n📅 [hoje]\n\n_Bora, time! Que venham os próximos!_ 🏆"),
    array('contrato_assinado', "🥳 *Fecha a conta, garçom!* 🥂\n\n🎯 [comercial] fechou o contrato da(o) *[cliente]*!\n💼 Serviço: [tipo_caso]\n\n_Escritório crescendo, família confiando. Bora manter o ritmo!_ 💪🔥"),
    array('contrato_assinado', "🏆 *MAIS UM PRA COLEÇÃO!* 🏆\n\n[cliente] agora é oficialmente da casa 🎊\n👏 Palmas pra *[comercial]* que fechou lindo!\n💼 [tipo_caso] · [hoje]\n\n_Segura o kanban que ta pegando fogo, meu povo!_ 🔥"),
    array('contrato_assinado', "💥 *BATIDA DE MARTELO NA VITÓRIA!* ⚖️\n\nAcaba de entrar na base: *[cliente]* ([tipo_caso])\n🎯 Craque do fechamento: *[comercial]*\n\n_Assim que se faz! Bora pra próxima, time!_ 🚀🏆"),

    // ── PETIÇÃO DISTRIBUÍDA (5 variações) ────────────────────────────
    array('peticao_distribuida', "⚖️🎯 *PETIÇÃO NO MUNDO!* 🚀\n\nAcabou de sair da caneta: *[cliente]* ([tipo_caso])\n📄 Processo: [numero_processo]\n👏 Parabéns, *[operacional]*!\n\n_Que o juiz seja generoso e o cliente feliz!_ 🏛️✨"),
    array('peticao_distribuida', "🏛️ *DISTRIBUÍDA COM MAESTRIA!* 📄\n\n[cliente] agora tem processo tramitando! 🎊\n💼 [tipo_caso] · protocolo em [numero_processo]\n🎯 Craque(a): *[operacional]*\n\n_Boa, time! Vamo pra briga jurídica!_ ⚖️💪"),
    array('peticao_distribuida', "📄💥 *PAPEL VOANDO NO PJe!* 🌪️\n\nA petição inicial da(o) *[cliente]* está no ar!\n⚖️ Ação: [tipo_caso]\n🔢 Nº [numero_processo]\n\n_Mandou bem demais, [operacional]! 👏👏👏_"),
    array('peticao_distribuida', "🎊 *AJUIZOU! 🎊*\n\n[cliente] com processo [numero_processo] distribuído com sucesso ✨\n💼 [tipo_caso]\n👏 Nas mãos habilidosas de *[operacional]*\n\n_Agora é aguardar o juiz mexer o cursor kkkk_ ⚖️"),
    array('peticao_distribuida', "🚀 *DECOLOU!* ✈️\n\nProcesso da(o) *[cliente]* saiu do escritório e foi pro fórum!\n📄 [tipo_caso] · [numero_processo]\n💪 Parabéns *[operacional]* pela dedicação!\n\n_Um a menos na fila 'aguardando distribuição'. Bora limpar essa coluna, time!_ 🧹⚖️"),

    // ── PRAZO CUMPRIDO (5 variações) ─────────────────────────────────
    array('prazo_cumprido', "⏰✅ *PRAZO BATIDO NO TEMPO!* 🎯\n\n[operacional] cumpriu o prazo de *[tipo_prazo]* pra(o) [cliente]!\n📄 Processo: [processo]\n\n_Nem no minuto 45 do 2º tempo! Craque(a) demais!_ ⚽🏆"),
    array('prazo_cumprido', "🎉 *PRECLUSÃO QUE NADA!* 💪\n\nAcabou de cumprir: *[tipo_prazo]* — [cliente]\n👏 Nas mãos de *[operacional]*\n📄 [processo] · [hoje]\n\n_Sem sustos, sem atraso. Do jeito que a gente gosta!_ ⚖️✨"),
    array('prazo_cumprido', "🏃💨 *CORRENDO MAIS QUE FÓRUM NA SEXTA!* 🎊\n\n*[operacional]* fechou o prazo de *[tipo_prazo]*\n👤 [cliente]\n📄 [processo]\n\n_Tá em ritmo de olimpíada essa doutora, hein!_ 🥇⚖️"),
    array('prazo_cumprido', "🔒 *FECHADO!* ✅\n\n[tipo_prazo] entregue no prazo pela craque *[operacional]*\n👤 Cliente: [cliente]\n📄 Processo: [processo]\n\n_Um a menos no sino do desespero! Bora pra próxima!_ 🔔🏆"),
    array('prazo_cumprido', "⚖️🎯 *SE APRESENTE, DOUTOR!* 📄\n\n*[operacional]* cumpriu o prazo de *[tipo_prazo]*\n👤 [cliente]\n📄 [processo]\n\n_Não é hoje que a preclusão me pega. Bola pra frente!_ ⚽💪"),

    // ── NOVIDADE NO HUB (4 variações) ────────────────────────────────
    array('novidade_hub', "🎁 *NOVIDADE QUENTINHA NO HUB!* 🔥\n\n📌 *[titulo]*\n\n[descricao]\n\n👉 Bora aprender: [link]\n\n_Fazer o treinamento vale ponto pro ranking! Não fica pra trás, time!_ 🏆⚡"),
    array('novidade_hub', "🚀 *ATUALIZAÇÃO NO HUB!* 🚀\n\n✨ *[titulo]*\n\n[descricao]\n\n🎓 Treinamento aqui: [link]\n\n_Vem aprender, ganhar ponto e mandar bem no dia a dia!_ 💪🎯"),
    array('novidade_hub', "🔔📢 *ATENÇÃO, ATENÇÃO!* 📢🔔\n\nChegou coisa nova no Hub, meu povo!\n\n🎯 *[titulo]*\n[descricao]\n\n👇 Todo mundo faz o treinamento:\n[link]\n\n_Escritório moderno é assim: sempre um passo à frente!_ ✨⚖️"),
    array('novidade_hub', "🎊 *NOVA FUNÇÃO NA ÁREA!* 🎊\n\n🆕 *[titulo]*\n\n[descricao]\n\n🎓 Vem estudar aqui: [link]\n\n_Treinamento rápido, ponto no ranking, produtividade lá em cima!_ 🚀🏆"),
);

// Idempotência: se ja tem seeds pra essa tocada, não duplica
$stCount = $pdo->prepare("SELECT COUNT(*) FROM jorjao_templates WHERE tocada = ?");
$stInsert = $pdo->prepare("INSERT INTO jorjao_templates (tocada, template, ordem) VALUES (?, ?, ?)");
$ordemPorTocada = array();
$insN = 0;
foreach ($seeds as $s) {
    list($tocada, $template) = $s;
    $stCount->execute(array($tocada));
    if ((int)$stCount->fetchColumn() >= 3) continue; // ja tem pelo menos 3 variações
    if (!isset($ordemPorTocada[$tocada])) $ordemPorTocada[$tocada] = 0;
    $stInsert->execute(array($tocada, $template, $ordemPorTocada[$tocada]++));
    $insN++;
}
_flush("✓ {$insN} templates inseridos como seed\n");

echo "\n=== FIM ===\n";
echo "\nProximos passos:\n";
echo "  1. Ativar tocadas em /admin/comemorar_contrato.php (todas OFF por default)\n";
echo "  2. Configurar 2 cronjobs novos no cPanel:\n";
echo "     */10 * * * *  curl -s https://ferreiraesa.com.br/conecta/cron/jorjao_tick.php?key=fsa-hub-deploy-2026\n";
echo "     0 19 * * *    curl -s https://ferreiraesa.com.br/conecta/cron/jorjao_resumo_diario.php?key=fsa-hub-deploy-2026\n";
