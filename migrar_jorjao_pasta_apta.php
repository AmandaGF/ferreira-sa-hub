<?php
/**
 * Migracao Jorjao — adiciona tocada "pasta_apta" (Amanda 09/07/2026).
 * Quando CX marca pasta como apta (status muda pra em_elaboracao),
 * Jorjao parabeniza no grupo.
 *
 * Rodar via: /conecta/migrar_jorjao_pasta_apta.php?key=fsa-hub-deploy-2026
 */
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';

if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }

header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Migração Jorjão pasta_apta (Amanda 09/07/2026) ===\n\n";

// 1) Amplia ENUM de tocada em jorjao_templates
try {
    $pdo->exec("ALTER TABLE jorjao_templates MODIFY COLUMN tocada
                ENUM('contrato_assinado','peticao_distribuida','prazo_cumprido','novidade_hub','pasta_apta') NOT NULL");
    echo "✓ ENUM jorjao_templates.tocada atualizado (adicionado 'pasta_apta')\n";
} catch (Throwable $e) {
    echo "✗ ALTER TABLE falhou: " . $e->getMessage() . "\n";
    echo "(Se o valor ja existir no ENUM, tudo OK — segue o baile)\n";
}

// 2) Killswitches — OFF por default (Amanda decide quando ligar)
$configs = array(
    'jorjao_pasta_apta_ativa'   => '0',
    'jorjao_pasta_apta_modo_ia' => '0',
);
foreach ($configs as $chave => $valor) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM configuracoes WHERE chave = ?");
    $st->execute(array($chave));
    if ((int)$st->fetchColumn() === 0) {
        $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES (?, ?)")
            ->execute(array($chave, $valor));
        echo "✓ Config '$chave' criada com valor '$valor'\n";
    } else {
        echo "· Config '$chave' já existe (mantida)\n";
    }
}

// 3) Seeds — templates variados pra pasta_apta (Amanda quer descontração)
// Variaveis suportadas: [cliente] [tipo_caso] [responsavel] [cx] [hoje]
$seeds = array(
    "🎯 *PASTA APTA! CHEGOU A HORA!* 🎯\n\nCliente: *[cliente]*\nCaso: [tipo_caso]\n📂 Preparada pela [cx]\n\n_Bora redigir, time! Papel voando no PJe em 3, 2, 1…_ ⚖️🚀",

    "📂✨ *PASTA APTA — [cliente]!* ✨\n\nA [cx] deixou tudo redondinho! Agora é com o time de redação: *bota o senhor Jorge orgulhoso!* 🎉\n\nTipo: [tipo_caso] · [hoje]",

    "🐻 *Preclusão que nada — é PASTA APTA!* 🐻\n\nCliente [cliente] pronto pra briga!\nAgradecimento especial pra [cx] que deixou tudo nos conformes 💪\n\nBora redigir esse [tipo_caso]! 📝",

    "⚖️ *É O VEIO DOS PRAZOS AQUI, Ó!* 📢\n\nMais uma pasta APTA saindo do forno:\n👤 *[cliente]*\n📁 [tipo_caso]\n🎯 Montada pela craque [cx]\n\nVambora, redação! Não deixa esfriar! 🔥",

    "🎉🔔 *PASTA APTA — [cliente]!* 🔔🎉\n\n[cx] fechou a preparação com maestria. Agora a bola tá com o time operacional!\n\nQue venha uma peça de encher os olhos! 🚀⚖️",
);

$stChk = $pdo->prepare("SELECT COUNT(*) FROM jorjao_templates WHERE tocada = 'pasta_apta'");
$stChk->execute();
$jaTem = (int)$stChk->fetchColumn();
if ($jaTem >= 3) {
    echo "· Templates pasta_apta ja tem $jaTem seeds (nao duplica)\n";
} else {
    $stIns = $pdo->prepare("INSERT INTO jorjao_templates (tocada, template, ordem, ativo) VALUES ('pasta_apta', ?, ?, 1)");
    foreach ($seeds as $i => $tpl) {
        $stIns->execute(array($tpl, $i + 1));
    }
    echo "✓ " . count($seeds) . " templates pasta_apta cadastrados\n";
}

echo "\n=== Concluido ===\n";
echo "Proximo passo: Amanda ativa em /admin/jorjao.php (checkbox 'pasta_apta')\n";
