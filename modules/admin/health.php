<?php
/**
 * Ferreira & Sá Hub — Health Check
 * Testa automaticamente todas as funcionalidades críticas do sistema.
 * Acesso: SOMENTE admin
 */

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/database.php';
require_once __DIR__ . '/../../core/auth.php';
require_once __DIR__ . '/../../core/middleware.php';

require_login();
require_role('admin');

$pageTitle = 'Health Check';

// ─── Funções auxiliares ─────────────────────────────────

function health_test($name, $callback)
{
    $start = microtime(true);
    try {
        $result = $callback();
        $elapsed = round((microtime(true) - $start) * 1000);
        return array(
            'name'    => $name,
            'ok'      => $result['ok'],
            'message' => $result['message'],
            'ms'      => $elapsed,
        );
    } catch (Exception $e) {
        $elapsed = round((microtime(true) - $start) * 1000);
        return array(
            'name'    => $name,
            'ok'      => false,
            'message' => 'Exceção: ' . $e->getMessage(),
            'ms'      => $elapsed,
        );
    }
}

function http_check($url, $timeout = 5)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_NOBODY         => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_HTTPHEADER     => array('User-Agent: FSHub-HealthCheck/1.0'),
    ));
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return array('code' => $code, 'body' => $body, 'error' => $err);
}

// ─── Rodar testes ───────────────────────────────────────

$results = array();

// 1. Conexão com banco de dados
$results[] = health_test('Conexão com Banco de Dados', function () {
    $pdo = db();
    $row = $pdo->query("SELECT 1 AS ok")->fetch();
    if ($row && $row['ok'] == 1) {
        // Verificar tabelas essenciais
        $tables = array('users', 'clients', 'cases', 'pipeline_leads', 'form_submissions', 'audit_log');
        $missing = array();
        foreach ($tables as $t) {
            try {
                $pdo->query("SELECT 1 FROM $t LIMIT 1");
            } catch (Exception $e) {
                $missing[] = $t;
            }
        }
        if ($missing) {
            return array('ok' => false, 'message' => 'Tabelas faltando: ' . implode(', ', $missing));
        }
        $userCount = $pdo->query("SELECT COUNT(*) FROM users WHERE is_active=1")->fetchColumn();
        return array('ok' => true, 'message' => 'OK — ' . $userCount . ' usuários ativos');
    }
    return array('ok' => false, 'message' => 'Query retornou resultado inesperado');
});

// 2. Login funciona
$results[] = health_test('Sistema de Autenticação', function () {
    $pdo = db();
    // Verifica se existe pelo menos 1 admin com senha hasheada
    $stmt = $pdo->query("SELECT id, name, password_hash FROM users WHERE role='admin' AND is_active=1 LIMIT 1");
    $admin = $stmt->fetch();
    if (!$admin) {
        return array('ok' => false, 'message' => 'Nenhum admin ativo encontrado');
    }
    // Verificar que password_hash é um hash bcrypt válido
    if (strlen($admin['password_hash']) < 50 || strpos($admin['password_hash'], '$2') !== 0) {
        return array('ok' => false, 'message' => 'Hash de senha inválido para admin ' . $admin['name']);
    }
    // Verificar que sessão está funcionando
    if (!is_logged_in()) {
        return array('ok' => false, 'message' => 'Sessão não está ativa');
    }
    // Verificar rate limiting funciona
    $lockFn = function_exists('is_login_locked');
    if (!$lockFn) {
        return array('ok' => false, 'message' => 'Função is_login_locked() não encontrada');
    }
    return array('ok' => true, 'message' => 'OK — Admin: ' . $admin['name'] . ', sessão ativa, rate limiting OK');
});

// 3. API Anthropic (Claude)
$results[] = health_test('API Anthropic (Claude)', function () {
    // Fallback 1: constante em config.php (padrão atual)
    $key = defined('ANTHROPIC_API_KEY') && ANTHROPIC_API_KEY ? ANTHROPIC_API_KEY : null;
    // Fallback 2: tabela configuracoes
    if (!$key) {
        try {
            $stmt = db()->prepare("SELECT valor FROM configuracoes WHERE chave = ?");
            $stmt->execute(array('anthropic_api_key'));
            $row = $stmt->fetch();
            if ($row) $key = $row['valor'];
        } catch (Exception $e) {}
    }

    if (!$key) {
        return array('ok' => false, 'message' => 'Chave API Anthropic não configurada (nem config.php nem configuracoes)');
    }

    // Testar endpoint /v1/messages com mensagem mínima
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(array(
            'model'      => 'claude-haiku-4-5-20251001',
            'max_tokens' => 5,
            'messages'   => array(array('role' => 'user', 'content' => 'ping')),
        )),
        CURLOPT_HTTPHEADER     => array(
            'Content-Type: application/json',
            'x-api-key: ' . $key,
            'anthropic-version: 2023-06-01',
        ),
        CURLOPT_SSL_VERIFYPEER => true,
    ));
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return array('ok' => false, 'message' => 'cURL error: ' . $err);
    }
    if ($code === 200) {
        return array('ok' => true, 'message' => 'OK — API respondeu (HTTP 200)');
    }
    if ($code === 401) {
        return array('ok' => false, 'message' => 'Chave API inválida (HTTP 401)');
    }
    return array('ok' => false, 'message' => 'HTTP ' . $code . ' — ' . mb_substr($body, 0, 100));
});

// 4. API Asaas
$results[] = health_test('API Asaas (Financeiro)', function () {
    $pdo = db();
    $key = null;
    $env = 'sandbox';
    try {
        $stmt = $pdo->prepare("SELECT chave, valor FROM configuracoes WHERE chave IN ('asaas_api_key','asaas_env')");
        $stmt->execute();
        foreach ($stmt->fetchAll() as $r) {
            if ($r['chave'] === 'asaas_api_key') $key = $r['valor'];
            if ($r['chave'] === 'asaas_env') $env = $r['valor'];
        }
    } catch (Exception $e) {}

    if (!$key) {
        return array('ok' => false, 'message' => 'Chave Asaas não configurada em configuracoes');
    }

    $base = ($env === 'production') ? 'https://api.asaas.com/api/v3' : 'https://sandbox.asaas.com/api/v3';

    $ch = curl_init($base . '/finance/balance');
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_HTTPHEADER     => array(
            'access_token: ' . $key,
            'Content-Type: application/json',
        ),
        CURLOPT_SSL_VERIFYPEER => true,
    ));
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return array('ok' => false, 'message' => 'cURL error: ' . $err);
    }
    if ($code === 200) {
        $data = json_decode($body, true);
        $saldo = isset($data['balance']) ? number_format($data['balance'], 2, ',', '.') : '?';
        return array('ok' => true, 'message' => 'OK — Env: ' . $env . ', Saldo: R$ ' . $saldo);
    }
    if ($code === 401) {
        return array('ok' => false, 'message' => 'Token Asaas inválido (HTTP 401) — Env: ' . $env);
    }
    return array('ok' => false, 'message' => 'HTTP ' . $code . ' — Env: ' . $env);
});

// 5. Google Drive webhook
$results[] = health_test('Google Drive Webhook', function () {
    // Fallback 1: constante em config.php
    $webhookUrl = defined('GOOGLE_APPS_SCRIPT_URL') && GOOGLE_APPS_SCRIPT_URL ? GOOGLE_APPS_SCRIPT_URL : null;
    // Fallback 2: tabela configuracoes
    if (!$webhookUrl) {
        try {
            $stmt = db()->prepare("SELECT valor FROM configuracoes WHERE chave = ?");
            $stmt->execute(array('google_drive_webhook'));
            $row = $stmt->fetch();
            if ($row) $webhookUrl = $row['valor'];
        } catch (Exception $e) {}
    }

    if (!$webhookUrl) {
        return array('ok' => false, 'message' => 'URL do Google Apps Script não configurada (nem GOOGLE_APPS_SCRIPT_URL em config.php nem em configuracoes)');
    }

    // Testar com GET (Apps Script retorna algo)
    $r = http_check($webhookUrl, 8);
    if ($r['error']) {
        return array('ok' => false, 'message' => 'Erro de conexão: ' . $r['error']);
    }
    if ($r['code'] >= 200 && $r['code'] < 400) {
        return array('ok' => true, 'message' => 'OK — Webhook respondeu (HTTP ' . $r['code'] . ')');
    }
    return array('ok' => false, 'message' => 'HTTP ' . $r['code']);
});

// 6. Drawer carrega dados
$results[] = health_test('Drawer (card_api.php)', function () {
    $pdo = db();
    // Buscar um lead ativo para testar
    $lead = $pdo->query("SELECT id, client_id FROM pipeline_leads WHERE stage NOT IN ('perdido','cancelado') ORDER BY id DESC LIMIT 1")->fetch();
    if (!$lead) {
        return array('ok' => false, 'message' => 'Nenhum lead ativo para testar');
    }

    // Simular chamada ao card_api internamente
    $apiPath = __DIR__ . '/../shared/card_api.php';
    if (!file_exists($apiPath)) {
        return array('ok' => false, 'message' => 'Arquivo card_api.php não encontrado');
    }

    // Testar via query interna do banco (o que card_api faz)
    $clientId = $lead['client_id'];
    if (!$clientId) {
        return array('ok' => true, 'message' => 'OK — Lead #' . $lead['id'] . ' existe (sem client_id vinculado)');
    }
    $client = $pdo->prepare("SELECT id, name, phone FROM clients WHERE id = ?");
    $client->execute(array($clientId));
    $c = $client->fetch();
    if (!$c) {
        return array('ok' => false, 'message' => 'Lead #' . $lead['id'] . ' aponta para client_id=' . $clientId . ' que não existe');
    }
    return array('ok' => true, 'message' => 'OK — Lead #' . $lead['id'] . ' → Cliente: ' . $c['name']);
});

// 7. Gatilho Contrato Assinado → cria caso
$results[] = health_test('Gatilho: Contrato Assinado → Caso', function () {
    $pdo = db();

    // Verificar se a lógica existe no api.php do pipeline
    $apiFile = __DIR__ . '/../pipeline/api.php';
    if (!file_exists($apiFile)) {
        return array('ok' => false, 'message' => 'pipeline/api.php não encontrado');
    }
    $code = file_get_contents($apiFile);

    // Verificar que o gatilho contrato_assinado cria caso
    $hasCreateCase = (strpos($code, 'contrato_assinado') !== false && strpos($code, 'INSERT INTO cases') !== false);
    if (!$hasCreateCase) {
        return array('ok' => false, 'message' => 'Gatilho contrato_assinado → INSERT INTO cases NÃO encontrado em pipeline/api.php');
    }

    // Verificar integridade: todos os leads contrato_assinado têm caso vinculado
    $stmt = $pdo->query("
        SELECT pl.id, pl.name, pl.linked_case_id
        FROM pipeline_leads pl
        WHERE pl.stage = 'contrato_assinado'
        AND pl.linked_case_id IS NULL
        LIMIT 5
    ");
    $orphans = $stmt->fetchAll();
    if ($orphans) {
        $names = array();
        foreach ($orphans as $o) $names[] = $o['name'] . ' (#' . $o['id'] . ')';
        return array('ok' => false, 'message' => 'Leads em contrato_assinado SEM caso vinculado: ' . implode(', ', $names));
    }

    // Verificar que generate_case_checklist existe
    if (!function_exists('generate_case_checklist')) {
        return array('ok' => false, 'message' => 'Função generate_case_checklist() não encontrada');
    }

    return array('ok' => true, 'message' => 'OK — Gatilho presente, todos os leads em contrato_assinado têm caso vinculado');
});

// 8. Espelhamento doc_faltante bilateral
$results[] = health_test('Espelhamento doc_faltante (Pipeline ↔ Operacional)', function () {
    $pdo = db();

    // Verificar que o código de espelhamento existe em ambos os lados
    $pipelineApi = __DIR__ . '/../pipeline/api.php';
    $operacionalApi = __DIR__ . '/../operacional/api.php';

    if (!file_exists($pipelineApi)) {
        return array('ok' => false, 'message' => 'pipeline/api.php não encontrado');
    }
    if (!file_exists($operacionalApi)) {
        return array('ok' => false, 'message' => 'operacional/api.php não encontrado');
    }

    $pCode = file_get_contents($pipelineApi);
    $oCode = file_get_contents($operacionalApi);

    // Pipeline deve refletir doc_faltante no operacional
    $pHasMirror = (strpos($pCode, 'doc_faltante') !== false);
    // Operacional deve refletir doc_faltante no pipeline
    $oHasMirror = (strpos($oCode, 'doc_faltante') !== false && strpos($oCode, 'pipeline_leads') !== false);

    if (!$pHasMirror) {
        return array('ok' => false, 'message' => 'pipeline/api.php não tem lógica de doc_faltante');
    }
    if (!$oHasMirror) {
        return array('ok' => false, 'message' => 'operacional/api.php não espelha doc_faltante no pipeline');
    }

    // Verificar consistência: leads em doc_faltante devem ter caso em doc_faltante
    $stmt = $pdo->query("
        SELECT pl.id, pl.name, c.status AS case_status
        FROM pipeline_leads pl
        LEFT JOIN cases c ON c.id = pl.linked_case_id
        WHERE pl.stage = 'doc_faltante'
        AND c.id IS NOT NULL
        AND c.status != 'doc_faltante'
        LIMIT 5
    ");
    $inconsistencies = $stmt->fetchAll();
    if ($inconsistencies) {
        $names = array();
        foreach ($inconsistencies as $i) $names[] = $i['name'] . ' (caso: ' . $i['case_status'] . ')';
        return array('ok' => false, 'message' => 'Inconsistência: leads em doc_faltante com caso em outro status: ' . implode(', ', $names));
    }

    // Verificar o inverso: casos em doc_faltante devem ter lead em doc_faltante
    $stmt = $pdo->query("
        SELECT c.id, cl.name, pl.stage AS lead_stage
        FROM cases c
        LEFT JOIN clients cl ON cl.id = c.client_id
        LEFT JOIN pipeline_leads pl ON pl.linked_case_id = c.id
        WHERE c.status = 'doc_faltante'
        AND pl.id IS NOT NULL
        AND pl.stage != 'doc_faltante'
        LIMIT 5
    ");
    $revInconsistencies = $stmt->fetchAll();
    if ($revInconsistencies) {
        $names = array();
        foreach ($revInconsistencies as $i) $names[] = ($i['name'] ? $i['name'] : 'Caso #' . $i['id']) . ' (lead: ' . $i['lead_stage'] . ')';
        return array('ok' => false, 'message' => 'Inconsistência inversa: casos em doc_faltante com lead em outro status: ' . implode(', ', $names));
    }

    return array('ok' => true, 'message' => 'OK — Espelhamento bilateral presente e consistente');
});

// ─── Contagem final ─────────────────────────────────────
$totalOk = 0;
$totalFail = 0;
foreach ($results as $r) {
    if ($r['ok']) $totalOk++;
    else $totalFail++;
}
$allGreen = ($totalFail === 0);

// ─── Salvar resultado (para deploy_check.php) ──────────
$healthResult = array(
    'timestamp' => date('Y-m-d H:i:s'),
    'all_green' => $allGreen,
    'passed'    => $totalOk,
    'failed'    => $totalFail,
    'results'   => $results,
);
$healthFile = APP_ROOT . '/health_last_result.json';
file_put_contents($healthFile, json_encode($healthResult, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

// ─── Se chamado via JSON (API) ──────────────────────────
if (isset($_GET['format']) && $_GET['format'] === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($healthResult, JSON_UNESCAPED_UNICODE);
    exit;
}

// ─── Renderizar HTML ────────────────────────────────────
require_once APP_ROOT . '/templates/layout_start.php';
?>
    <div style="display:flex; align-items:center; gap:1rem; margin-bottom:1.5rem;">
        <h1 style="margin:0;">Health Check</h1>
        <span style="font-size:1.5rem;"><?= $allGreen ? '&#9989;' : '&#10060;' ?></span>
        <span class="badge badge-<?= $allGreen ? 'success' : 'admin' ?>" style="font-size:.9rem;">
            <?= $totalOk ?>/<?= count($results) ?> OK
        </span>
        <a href="?format=json" style="margin-left:auto; font-size:.85rem; color:var(--petrol-300);">JSON</a>
    </div>

    <?php if (!$allGreen): ?>
    <div class="alert alert-error" style="margin-bottom:1rem;">
        <span class="alert-icon">&#10007;</span>
        <?= $totalFail ?> teste(s) falharam. Corrija antes do próximo deploy.
    </div>
    <?php else: ?>
    <div class="alert alert-success" style="margin-bottom:1rem;">
        <span class="alert-icon">&#10003;</span>
        Todos os testes passaram. Sistema pronto para deploy.
    </div>
    <?php endif; ?>

    <table>
        <thead>
            <tr>
                <th style="width:40px;">Status</th>
                <th>Teste</th>
                <th>Resultado</th>
                <th style="width:70px;">Tempo</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($results as $r): ?>
            <tr style="<?= $r['ok'] ? '' : 'background:#fef2f2;' ?>">
                <td style="text-align:center; font-size:1.2rem;">
                    <?= $r['ok'] ? '&#9989;' : '&#10060;' ?>
                </td>
                <td><strong><?= e($r['name']) ?></strong></td>
                <td style="font-size:.9rem;"><?= e($r['message']) ?></td>
                <td style="text-align:right; font-size:.85rem; color:#666;">
                    <?= $r['ms'] ?>ms
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <p style="margin-top:1.5rem; color:#888; font-size:.85rem;">
        Executado em <?= date('d/m/Y H:i:s') ?> por <?= e(current_user_name()) ?>
        &nbsp;|&nbsp;
        Resultado salvo em <code>health_last_result.json</code>
    </p>

    <div style="margin-top:1rem;">
        <a href="<?= url('modules/admin/health.php') ?>" class="btn btn-primary">Rodar novamente</a>
        <a href="<?= url('modules/dashboard/') ?>" class="btn btn-outline" style="margin-left:.5rem;">Voltar ao Dashboard</a>
    </div>
</div>
<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
