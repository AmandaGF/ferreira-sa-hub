<?php
/**
 * Migração one-shot: converte tarefas órfãs (tipo IS NULL que DEVERIAM ser reais)
 * em tipo='outros' pra elas voltarem a aparecer no card "Tarefas" da pasta do processo.
 *
 * Contexto: bug histórico — formulário "Nova tarefa" em caso_ver.php inseria sem tipo.
 * Tarefas caíam no mesmo bucket do checklist de documentos (tipo=NULL) e sumiam da UI.
 * Fix de código aplicado no commit eb47dbd; este script corrige o passivo.
 *
 * Heurística:
 *   - SEGURO (auto-converte): tipo IS NULL E (assigned_to IS NOT NULL OU due_date IS NOT NULL)
 *     → checklist nunca tem responsável nem prazo; se tem, é órfã.
 *   - AMBÍGUO (só lista): tipo IS NULL E ambos NULL
 *     → pode ser checklist legítimo OU órfã com só título digitado. Decisão manual.
 */
require_once __DIR__ . '/core/database.php';

$key = $_GET['key'] ?? '';
if ($key !== 'fsa-hub-deploy-2026') {
    http_response_code(403);
    exit('Chave inválida');
}

$pdo = db();
$dryRun = !isset($_GET['exec']);

header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><meta charset="utf-8"><title>Migrar tarefas órfãs</title>';
echo '<style>body{font-family:Inter,Arial,sans-serif;max-width:1100px;margin:2rem auto;padding:0 1rem;color:#052228} h1,h2{color:#052228} table{border-collapse:collapse;width:100%;margin:1rem 0;font-size:13px} th,td{border:1px solid #d1d5db;padding:.4rem .6rem;text-align:left} th{background:#f3f4f6} .badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600} .ok{background:#d1fae5;color:#065f46} .warn{background:#fef3c7;color:#78350f} .act{background:#052228;color:#fff;padding:.6rem 1.2rem;border-radius:8px;text-decoration:none;display:inline-block;margin-top:1rem;font-weight:700} .muted{color:#6b7280;font-size:13px}</style>';

echo '<h1>Migrar tarefas órfãs — case_tasks com tipo NULL</h1>';
echo '<p class="muted">Modo atual: <strong>' . ($dryRun ? 'DRY RUN (simulação)' : 'EXECUÇÃO REAL') . '</strong></p>';

// ── 1. Candidatas seguras ──
$sqlSeguras = "SELECT ct.id, ct.case_id, ct.title, ct.status, ct.assigned_to, ct.due_date, ct.created_at,
                      c.title AS case_title, u.name AS assigned_name
               FROM case_tasks ct
               LEFT JOIN cases c ON c.id = ct.case_id
               LEFT JOIN users u ON u.id = ct.assigned_to
               WHERE (ct.tipo IS NULL OR ct.tipo = '')
                 AND (ct.assigned_to IS NOT NULL OR ct.due_date IS NOT NULL)
               ORDER BY ct.created_at DESC";
$seguras = $pdo->query($sqlSeguras)->fetchAll();

echo '<h2>🟢 Órfãs seguras (auto-conserta): ' . count($seguras) . '</h2>';
echo '<p class="muted">Tem responsável ou prazo — checklist nunca tem. Serão convertidas para tipo=\'outros\'.</p>';

if (count($seguras) > 0) {
    echo '<table><tr><th>ID</th><th>Processo</th><th>Título</th><th>Status</th><th>Responsável</th><th>Prazo</th><th>Criada</th></tr>';
    foreach ($seguras as $t) {
        echo '<tr>';
        echo '<td>' . (int)$t['id'] . '</td>';
        echo '<td>' . htmlspecialchars($t['case_title'] ?? '[sem caso]') . ' <span class="muted">(#' . (int)$t['case_id'] . ')</span></td>';
        echo '<td><strong>' . htmlspecialchars($t['title']) . '</strong></td>';
        echo '<td>' . htmlspecialchars($t['status']) . '</td>';
        echo '<td>' . htmlspecialchars($t['assigned_name'] ?? '—') . '</td>';
        echo '<td>' . htmlspecialchars($t['due_date'] ?? '—') . '</td>';
        echo '<td>' . htmlspecialchars($t['created_at']) . '</td>';
        echo '</tr>';
    }
    echo '</table>';

    if (!$dryRun) {
        $ids = array_map(function($t){ return (int)$t['id']; }, $seguras);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("UPDATE case_tasks SET tipo='outros' WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        $afetadas = $stmt->rowCount();
        echo '<p class="badge ok">✓ ' . $afetadas . ' tarefa(s) convertida(s) para tipo=\'outros\'.</p>';
    }
}

// ── 2. Ambíguas ──
$sqlAmbiguas = "SELECT ct.id, ct.case_id, ct.title, ct.status, ct.created_at,
                       c.title AS case_title
                FROM case_tasks ct
                LEFT JOIN cases c ON c.id = ct.case_id
                WHERE (ct.tipo IS NULL OR ct.tipo = '')
                  AND ct.assigned_to IS NULL
                  AND ct.due_date IS NULL
                ORDER BY ct.created_at DESC";
$ambiguas = $pdo->query($sqlAmbiguas)->fetchAll();

// Títulos conhecidos do checklist — extraídos de get_checklist_template()
$titulosChecklist = array(
    'RG (todas as partes)','CPF (todas as partes)','Comprovante de residência atualizado','Procuração assinada','Contrato de honorários assinado',
    'Procuração','Contrato de honorários','Documentos comprobatórios do caso','Procuração dos herdeiros','Procuração assinada',
    'Certidão de nascimento/casamento','Comprovante de renda','Documentos pessoais de todos os envolvidos','Provas documentais pertinentes',
    'Nota fiscal / comprovante de compra','Contrato de prestação de serviço','Prints de conversas (WhatsApp, e-mail)','Fotos do produto/serviço defeituoso','Protocolo de reclamação (SAC/Procon)','Comprovante de pagamento',
    'Boletim de ocorrência (se aplicável)','Laudos médicos / exames','Fotos e provas do dano','Comprovantes de despesas decorrentes','Prints de conversas relevantes','Testemunhas (nomes e contatos)',
    'CTPS (Carteira de Trabalho)','Contrato de trabalho','Últimos 3 holerites/contracheques','Termo de rescisão (TRCT)','Guias do FGTS','Extrato do FGTS','Aviso prévio','Comprovante de horas extras (se houver)','Atestados médicos (se houver)',
    'Boletim de ocorrência','Extratos bancários com transações fraudulentas','Prints das transações não reconhecidas','Protocolo de contestação no banco','Resposta do banco à contestação','Comprovante de abertura de conta',
    'Matrícula atualizada do imóvel','Contrato de compra e venda','Escritura pública','IPTU','Certidão negativa de ônus reais','Planta do imóvel / habite-se','Contrato de locação (se aplicável)',
    'Matrícula do imóvel (ou certidão negativa)','Comprovantes de posse (contas, IPTU, correspondências)','Planta e memorial descritivo','ART do engenheiro/arquiteto','Declaração de confrontantes','Fotos do imóvel','Certidão do registro de imóveis',
);
$titulosChecklistNorm = array_map(function($s){ return mb_strtolower(trim($s)); }, $titulosChecklist);

$ambiguasReais = array();
foreach ($ambiguas as $t) {
    $tituloNorm = mb_strtolower(trim($t['title']));
    if (!in_array($tituloNorm, $titulosChecklistNorm, true)) {
        $ambiguasReais[] = $t;
    }
}

echo '<h2>🟡 Ambíguas NÃO-checklist (título livre, sem responsável, sem prazo): ' . count($ambiguasReais) . '</h2>';
echo '<p class="muted">Título não bate com nenhum item do checklist padrão. Provavelmente são órfãs também, mas você pode revisar antes.</p>';

if (count($ambiguasReais) > 0) {
    echo '<table><tr><th>ID</th><th>Processo</th><th>Título</th><th>Status</th><th>Criada</th><th>Ação</th></tr>';
    foreach ($ambiguasReais as $t) {
        echo '<tr>';
        echo '<td>' . (int)$t['id'] . '</td>';
        echo '<td>' . htmlspecialchars($t['case_title'] ?? '[sem caso]') . ' <span class="muted">(#' . (int)$t['case_id'] . ')</span></td>';
        echo '<td><strong>' . htmlspecialchars($t['title']) . '</strong></td>';
        echo '<td>' . htmlspecialchars($t['status']) . '</td>';
        echo '<td>' . htmlspecialchars($t['created_at']) . '</td>';
        echo '<td><a href="?key=fsa-hub-deploy-2026&exec=1&converter=' . (int)$t['id'] . '">Converter esta</a></td>';
        echo '</tr>';
    }
    echo '</table>';
}

// ── 3. Conversão pontual de uma ambígua ──
if (isset($_GET['converter']) && !$dryRun) {
    $idConv = (int)$_GET['converter'];
    if ($idConv > 0) {
        $stmt = $pdo->prepare("UPDATE case_tasks SET tipo='outros' WHERE id = ?");
        $stmt->execute(array($idConv));
        echo '<p class="badge ok">✓ Tarefa #' . $idConv . ' convertida.</p>';
    }
}

// ── Rodapé ──
if ($dryRun) {
    echo '<hr><p><strong>Isso é só simulação.</strong> Nada foi alterado no banco.</p>';
    echo '<a class="act" href="?key=fsa-hub-deploy-2026&exec=1">▶ Executar de verdade (converte as ' . count($seguras) . ' seguras)</a>';
} else {
    echo '<hr><p class="muted">Execução concluída em ' . date('d/m/Y H:i:s') . '.</p>';
}
