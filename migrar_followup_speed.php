<?php
/** Migração ITEM 1 (Speed-to-lead): coluna primeiro_contato_em + toggles (OFF) + templates A1.
 *  curl "https://ferreiraesa.com.br/conecta/migrar_followup_speed.php?key=fsa-hub-deploy-2026"
 *  Idempotente.
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Forbidden.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();
echo "=== Migração Speed-to-lead ===\n\n";

// 1. Coluna primeiro_contato_em
try { $pdo->exec("ALTER TABLE pipeline_leads ADD COLUMN primeiro_contato_em DATETIME NULL AFTER converted_at"); echo "[OK] coluna primeiro_contato_em criada\n"; }
catch (Exception $e) { echo "[SKIP] primeiro_contato_em (" . substr($e->getMessage(),0,60) . ")\n"; }

// 2. Toggles — nascem DESLIGADOS (ON DUPLICATE preserva valor existente)
function cfgDef($pdo, $chave, $valor) {
    $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES (?,?) ON DUPLICATE KEY UPDATE chave=chave")->execute(array($chave, $valor));
}
cfgDef($pdo, 'followup_ativo', '0');
cfgDef($pdo, 'followup_speed_to_lead', '0');
echo "[OK] toggles followup_ativo=0, followup_speed_to_lead=0 (preservados se já existiam)\n";

// 3. Templates A1 (canal 21) — só cria se não existir
function tplSeed($pdo, $nome, $conteudo) {
    $e = $pdo->prepare("SELECT id FROM zapi_templates WHERE nome = ? LIMIT 1"); $e->execute(array($nome));
    if ($e->fetch()) { echo "  [SKIP] template '$nome' ja existe\n"; return; }
    $pdo->prepare("INSERT INTO zapi_templates (nome, conteudo, canal, categoria, ativo, created_at) VALUES (?,?, '21', 'followup', 1, NOW())")
        ->execute(array($nome, $conteudo));
    echo "  [OK] template criado: '$nome'\n";
}

$a1 = "Olá! 😊 Aqui é da equipe do Ferreira & Sá Advocacia, especializada em Direito de Família.\n"
    . "Recebemos seu contato sobre {{tema}} e queremos te ajudar a entender seus direitos — sem nenhum compromisso.\n"
    . "Pra começar, como é seu nome?";

$a1ind = "Oi! 😊 Aqui é da equipe do Ferreira & Sá Advocacia. Soubemos que você foi indicado(a) até nós para uma orientação sobre {{tema}}.\n"
       . "Ficamos felizes com a confiança! Pra te atender do jeito certo, como é seu nome?";

$a1fora = "Olá! 😊 Aqui é da equipe do Ferreira & Sá Advocacia. Recebemos seu contato sobre {{tema}} e já está em mãos.\n"
        . "Nosso atendimento é de segunda a sexta, das 10h às 18h — assim que abrir, a gente te chama pra te ajudar. Até já! 💙";

echo "\nTemplates A1:\n";
tplSeed($pdo, 'Follow A1 - Abertura (form/anuncio)', $a1);
tplSeed($pdo, 'Follow A1 - Abertura (indicacao)', $a1ind);
tplSeed($pdo, 'Follow A1 - Fora de horario', $a1fora);

echo "\n=== CONCLUÍDO === (kill switch DESLIGADO — nada dispara até ativar)\n";
