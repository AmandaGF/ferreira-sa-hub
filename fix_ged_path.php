<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Migrar case_type: sem acento → com acento ===\n\n";

$map = [
    'Execucao de Alimentos' => 'Execução de Alimentos',
    'Exoneracao de Alimentos' => 'Exoneração de Alimentos',
    'Divorcio Consensual' => 'Divórcio Consensual',
    'Divorcio Litigioso' => 'Divórcio Litigioso',
    'Dissolucao de Uniao Estavel' => 'Dissolução de União Estável',
    'Reconhecimento de Uniao Estavel' => 'Reconhecimento de União Estável',
    'Regulamentacao de Convivencia' => 'Regulamentação de Convivência',
    'Modificacao de Guarda' => 'Modificação de Guarda',
    'Busca e Apreensao de Menor' => 'Busca e Apreensão de Menor',
    'Investigacao de Paternidade' => 'Investigação de Paternidade',
    'Negatoria de Paternidade' => 'Negatória de Paternidade',
    'Adocao' => 'Adoção',
    'Interdicao' => 'Interdição',
    'Alienacao Parental' => 'Alienação Parental',
    'Inventario Judicial' => 'Inventário Judicial',
    'Inventario Extrajudicial' => 'Inventário Extrajudicial',
    'Alvara Judicial' => 'Alvará Judicial',
    'Indenizacao / Danos Morais' => 'Indenização / Danos Morais',
    'Indenizacao / Danos Materiais' => 'Indenização / Danos Materiais',
    'Obrigacao de Fazer' => 'Obrigação de Fazer',
    'Cobranca' => 'Cobrança',
    'Execucao de Titulo Extrajudicial' => 'Execução de Título Extrajudicial',
    'Cumprimento de Sentenca' => 'Cumprimento de Sentença',
    'Revisao Contratual' => 'Revisão Contratual',
    'Fraude Bancaria' => 'Fraude Bancária',
    'Negativacao Indevida' => 'Negativação Indevida',
    'Usucapiao' => 'Usucapião',
    'Reintegracao de Posse' => 'Reintegração de Posse',
    'Imissao na Posse' => 'Imissão na Posse',
    'Adjudicacao Compulsoria' => 'Adjudicação Compulsória',
    'Reclamacao Trabalhista' => 'Reclamação Trabalhista',
    'Execucao Trabalhista' => 'Execução Trabalhista',
    'Auxilio-Doenca / Incapacidade' => 'Auxílio-Doença / Incapacidade',
    'Pensao por Morte' => 'Pensão por Morte',
    'Revisao de Beneficio' => 'Revisão de Benefício',
    'Mandado de Seguranca' => 'Mandado de Segurança',
    'Regulamentacao de Convivencia' => 'Regulamentação de Convivência',
];

$stmt = $pdo->prepare("UPDATE cases SET case_type = ? WHERE case_type = ?");
$total = 0;
foreach ($map as $old => $new) {
    $stmt->execute([$new, $old]);
    $n = $stmt->rowCount();
    if ($n > 0) {
        echo "$old → $new: $n registros\n";
        $total += $n;
    }
}
echo "\nTotal atualizado: $total\n";
