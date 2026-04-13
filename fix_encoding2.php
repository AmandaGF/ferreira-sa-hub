<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

$apply = isset($_GET['apply']);

echo "=== Andamentos corrompidos do case 734 ===\n\n";
$broken = $pdo->query("SELECT id, tipo, tipo_origem, created_at, LEFT(descricao, 150) as trecho FROM case_andamentos WHERE case_id = 734 AND (descricao LIKE '%ï¿½%' OR descricao LIKE '%i¿½%') ORDER BY id")->fetchAll();
echo "Total: " . count($broken) . "\n\n";
foreach ($broken as $b) {
    echo "#" . $b['id'] . " tipo=" . $b['tipo'] . " origem=" . ($b['tipo_origem'] ?: 'manual') . " criado=" . $b['created_at'] . "\n";
    echo "  " . $b['trecho'] . "\n\n";
}

// Tentar corrigir substituindo ï¿½ por caracteres acentuados comuns
// ï¿½ = UTF-8 encoding de U+FFFD = replacement character
// Mas no contexto jurídico brasileiro, podemos inferir:
if ($apply) {
    echo "=== Aplicando substituições ===\n";
    $rows = $pdo->query("SELECT id, descricao FROM case_andamentos WHERE case_id = 734 AND descricao LIKE '%ï¿½%'")->fetchAll();
    foreach ($rows as $r) {
        $text = $r['descricao'];
        // Padrões comuns: aï¿½ï¿½o = ação, ï¿½ = ê/ã/ç/etc
        // Substituição genérica: remover ï¿½ e deixar o texto legível, embora imperfeito
        // Melhor abordagem: detectar o byte antes e inferir
        $patterns = array(
            'aï¿½ï¿½o' => 'ação', 'aï¿½ï¿½es' => 'ações',
            'ï¿½ï¿½o' => 'ção', 'ï¿½ï¿½es' => 'ções',
            'constituiï¿½ï¿½' => 'constituição',
            'decisï¿½o' => 'decisão', 'pensï¿½o' => 'pensão',
            'Nï¿½O' => 'NÃO', 'nï¿½o' => 'não',
            'ALTERAï¿½ï¿½O' => 'ALTERAÇÃO',
            'CONDIï¿½ï¿½ES' => 'CONDIÇÕES',
            'SITUAï¿½ï¿½O' => 'SITUAÇÃO',
            'obrigaï¿½ï¿½es' => 'obrigações', 'obrigaï¿½es' => 'obrigações',
            'exclusï¿½es' => 'exclusões',
            'alimentï¿½cia' => 'alimentícia',
            'PROCEDï¿½NCIA' => 'PROCEDÊNCIA',
            'justiï¿½a' => 'justiça',
            'contestaï¿½ï¿½o' => 'contestação',
            'diligï¿½nc' => 'diligênc',
            'rï¿½' => 'ré', ' rï¿½ ' => ' ré ',
            'deficiï¿½ncia' => 'deficiência', 'deficiï¿½ncias' => 'deficiências',
            'interdiï¿½ï¿½o' => 'interdição',
            'retificaï¿½ï¿½o' => 'retificação',
            'deverï¿½' => 'deverá',
            'ï¿½' => 'ê', // fallback genérico (não ideal mas melhor que ï¿½)
            'salï¿½rio' => 'salário', 'mï¿½nimo' => 'mínimo',
            'famï¿½lia' => 'família',
            'juï¿½zo' => 'juízo', 'Juï¿½zo' => 'Juízo',
            'probatï¿½ria' => 'probatória',
            'sentanï¿½' => 'sentanç',
            'perï¿½cia' => 'perícia', 'psiquiï¿½trica' => 'psiquiátrica',
            'mï¿½dica' => 'médica',
            'desnecessï¿½ria' => 'desnecessária',
            'Pï¿½blico' => 'Público', 'pï¿½blico' => 'público',
            'manifestaï¿½ï¿½o' => 'manifestação',
            'Ministï¿½rio' => 'Ministério',
            'apresentaï¿½ï¿½o' => 'apresentação',
            'procuraï¿½ï¿½o' => 'procuração',
            'Solicitaï¿½ï¿½o' => 'Solicitação',
            'Habilitaï¿½ï¿½o' => 'Habilitação',
            'disponï¿½veis' => 'disponíveis',
            'nomeaï¿½ï¿½o' => 'nomeação',
            'legï¿½timas' => 'legítimas',
            'estï¿½o' => 'estão',
            'dilaï¿½ï¿½o' => 'dilação',
            'manutenï¿½ï¿½o' => 'manutenção',
            'controvï¿½rsia' => 'controvérsia',
            'reduï¿½ï¿½o' => 'redução',
            'apï¿½s' => 'após',
            'existï¿½ncia' => 'existência',
            'capacidade' => 'capacidade',
            'serviï¿½o' => 'serviço',
            'binï¿½mio' => 'binômio',
            'incompatï¿½veis' => 'incompatíveis',
            'ï¿½rea' => 'área',
            'alegaï¿½ï¿½es' => 'alegações',
            'subsistï¿½ncia' => 'subsistência',
            'residï¿½ncia' => 'residência',
            'Conforme' => 'Conforme',
            'incapacidade' => 'incapacidade',
        );

        $fixed = $text;
        foreach ($patterns as $from => $to) {
            $fixed = str_replace($from, $to, $fixed);
        }

        if ($fixed !== $text) {
            $pdo->prepare("UPDATE case_andamentos SET descricao = ? WHERE id = ?")->execute(array($fixed, $r['id']));
            echo "#" . $r['id'] . " corrigido\n";
            echo "  " . mb_substr($fixed, 0, 120) . "\n\n";
        }
    }
}

if (!$apply) echo "\n>>> Modo simulação. Para aplicar: &apply=1\n";
