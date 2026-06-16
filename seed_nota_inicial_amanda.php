<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

// Garante que a tabela existe
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS notas_pessoais (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        titulo VARCHAR(200) NOT NULL DEFAULT '',
        conteudo MEDIUMTEXT NOT NULL,
        fixada TINYINT(1) NOT NULL DEFAULT 0,
        status ENUM('ativa','arquivada','feita') NOT NULL DEFAULT 'ativa',
        cor VARCHAR(20) DEFAULT NULL,
        criada_em DATETIME DEFAULT CURRENT_TIMESTAMP,
        atualizada_em DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_user_status (user_id, status, fixada, atualizada_em),
        INDEX idx_user_atual (user_id, atualizada_em)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

$uid = 1; // Amanda
$titulo = 'Duplicatas no Conecta após importação Legal One';

// Evita duplicar se rodar de novo
$check = $pdo->prepare("SELECT id FROM notas_pessoais WHERE user_id = ? AND titulo = ? LIMIT 1");
$check->execute(array($uid, $titulo));
if ($check->fetchColumn()) {
    echo "✓ Nota já existe pra Amanda. Pulando.\n";
    exit;
}

$conteudo = <<<TXT
PENDÊNCIA — Duplicatas no Conecta após importação de processos arquivados (Legal One → Conecta)

Contexto: foram importados 317 processos arquivados do Legal One para o Conecta (ferreiraesa.com.br/conecta), todos com status "ARQUIVADO (OUTROS)". Total do Conecta passou de 467 → 784. A importação gerou 2 duplicatas que precisam ser resolvidas manualmente (exclusão é tarefa humana, por segurança).

═══════════════════════════════════════════
AÇÃO NECESSÁRIA — revisar e excluir duplicados:
═══════════════════════════════════════════

🔸 CNJ 5000781-30.2026.4.02.5109 — Enayle Garcia Fontes
   • Manter: id 908 ("Enayle Fontes x Pensão por Morte", Em Andamento) — pré-existente
   • Avaliar exclusão: id 1185 ("Enayle Fontes x INSS", Arquivado) — criado na importação
   • Link: https://ferreiraesa.com.br/conecta/modules/operacional/caso_ver.php?id=1185

🔸 CNJ 5000933-78.2026.4.02.5109 — Nilceia Henrique de Andrade Marchiori
   • Manter: id 947 ("Nilceia Marchiori x Auxílio...", Em Andamento) — pré-existente
   • Avaliar exclusão: id 1186 ("Nilceia Marchiori x INSS...", Arquivado) — criado na importação
   • Link: https://ferreiraesa.com.br/conecta/modules/operacional/caso_ver.php?id=1186

Obs.: ambos escaparam da deduplicação porque já existiam no Conecta com status "Em Andamento" (não constavam como arquivados na origem). Decidir se o correto é excluir o duplicado arquivado ou atualizar o status do registro original.

═══════════════════════════════════════════
PARA CONHECIMENTO — 7 duplicatas pré-existentes
═══════════════════════════════════════════
(NÃO criadas pela importação, ambos IDs antigos — avaliar limpeza num momento oportuno):

• 0808177-37.2024.8.19.0045 (ids 699/765)
• 0801106-71.2026.8.19.0058 (ids 880/893)
• 0800536-46.2026.8.19.0071 (ids 806/912)
• 0129381-53.2023.8.19.0001 (ids 918/933)
• 0005706-98.2026.8.16.0173 (ids 636/878)
• 0800571-40.2025.8.19.0071 (ids 850/935)
• 0814901-66.2024.8.19.0042 (ids 836/931)
TXT;

$pdo->prepare("INSERT INTO notas_pessoais (user_id, titulo, conteudo, fixada, cor) VALUES (?,?,?,1,'amarela')")
    ->execute(array($uid, $titulo, $conteudo));

echo "✓ Nota criada e fixada no topo pra Amanda (user_id=1).\n";
echo "Acesse: https://ferreiraesa.com.br/conecta/modules/notas/\n";
