<?php
/**
 * Ferreira & Sá Hub — Integração ZapSign
 * Cria documento no ZapSign e retorna link de assinatura
 */

require_once __DIR__ . '/../../core/middleware.php';
require_min_role('gestao');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect(module_url('documentos')); }
if (!validate_csrf()) { flash_set('error', 'Token inválido.'); redirect(module_url('documentos')); }

$pdo = db();

$ZAPSIGN_TOKEN = '84835763-4090-41d4-8c59-570071f913e32e66a365-39f0-48be-be4a-e6095317aba2';

$tipo = $_POST['tipo'] ?? '';
$clientId = (int)($_POST['client_id'] ?? 0);
$htmlContent = $_POST['html_content'] ?? '';

// Buscar cliente
$stmt = $pdo->prepare('SELECT * FROM clients WHERE id = ?');
$stmt->execute(array($clientId));
$client = $stmt->fetch();

if (!$client) { flash_set('error', 'Cliente não encontrado.'); redirect(module_url('documentos')); }

$typeLabels = array(
    'procuracao' => 'Procuração',
    'contrato' => 'Contrato de Honorários',
    'hipossuficiencia' => 'Declaração de Hipossuficiência',
    'isencao_ir' => 'Declaração de Isenção de IR',
);

$docName = ($typeLabels[$tipo] ?? 'Documento') . ' — ' . $client['name'];

// ═══════════════════════════════════════════════════════
// 1. Criar documento no ZapSign via API
// ═══════════════════════════════════════════════════════

$signerName = $client['name'];
$signerEmail = $client['email'] ?: '';
$signerPhone = $client['phone'] ? preg_replace('/\D/', '', $client['phone']) : '';
if ($signerPhone && strlen($signerPhone) <= 11) {
    $signerPhone = '55' . $signerPhone;
}

$apiData = array(
    'name' => $docName,
    'lang' => 'pt-br',
    'signers' => array(
        array(
            'name' => $signerName,
            'email' => $signerEmail,
            'phone_country' => '55',
            'phone_number' => $signerPhone,
            'auth_mode' => 'assinaturaTela',
            'send_automatic_email' => false,
            'send_automatic_whatsapp' => false,
        ),
    ),
    'created_by' => 'Ferreira & Sá Hub',
);

// Se tiver HTML, usar endpoint de criação via HTML
// Se não, usar criação simples e enviar PDF depois
if ($htmlContent) {
    $apiData['url_pdf'] = '';
    $apiData['brand_primary_color'] = '#052228';
}

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'https://api.zapsign.com.br/api/v1/docs/');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_HTTPHEADER, array(
    'Content-Type: application/json',
    'Authorization: Bearer ' . $ZAPSIGN_TOKEN,
));
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($apiData));

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    flash_set('error', 'Erro de conexão com ZapSign: ' . $curlError);
    redirect(module_url('documentos'));
}

$result = json_decode($response, true);

if ($httpCode >= 200 && $httpCode < 300 && $result) {
    // Sucesso!
    $docToken = isset($result['token']) ? $result['token'] : '';
    $signerToken = '';
    $signLink = '';

    if (isset($result['signers']) && !empty($result['signers'])) {
        $signer = $result['signers'][0];
        $signerToken = isset($signer['token']) ? $signer['token'] : '';
        $signLink = isset($signer['sign_url']) ? $signer['sign_url'] : '';
    }

    // Se não tiver sign_url direto, construir
    if (!$signLink && $signerToken) {
        $signLink = 'https://app.zapsign.com.br/verificar/' . $signerToken;
    }

    audit_log('zapsign_created', 'client', $clientId, 'doc: ' . $docName . ' token: ' . $docToken);

    // Mostrar resultado
    $pageTitle = 'ZapSign — Link de Assinatura';
    require_once APP_ROOT . '/templates/layout_start.php';
    ?>

    <div style="max-width:600px;">
        <a href="<?= module_url('documentos') ?>" class="btn btn-outline btn-sm mb-2">← Voltar</a>

        <div class="card" style="border-color:var(--success);border-width:2px;">
            <div class="card-header" style="background:var(--success-bg);">
                <h3 style="color:var(--success);">✅ Documento enviado ao ZapSign!</h3>
            </div>
            <div class="card-body">
                <div style="margin-bottom:1rem;">
                    <label style="font-size:.7rem;text-transform:uppercase;color:var(--text-muted);font-weight:700;">Documento</label>
                    <p style="font-size:.9rem;font-weight:700;"><?= e($docName) ?></p>
                </div>

                <div style="margin-bottom:1rem;">
                    <label style="font-size:.7rem;text-transform:uppercase;color:var(--text-muted);font-weight:700;">Assinante</label>
                    <p style="font-size:.9rem;"><?= e($signerName) ?></p>
                </div>

                <?php if ($signLink): ?>
                <div style="margin-bottom:1.5rem;">
                    <label style="font-size:.7rem;text-transform:uppercase;color:var(--text-muted);font-weight:700;">Link de assinatura</label>
                    <div style="background:var(--bg);padding:.75rem;border-radius:var(--radius);margin-top:.35rem;word-break:break-all;">
                        <code id="signLink" style="font-size:.82rem;"><?= e($signLink) ?></code>
                    </div>
                </div>

                <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
                    <button onclick="copySignLink()" class="btn btn-primary">📋 Copiar link</button>
                    <?php if ($client['phone']): ?>
                        <?php
                        $whatsMsg = urlencode("Olá, " . $client['name'] . "! Segue o link para assinatura do documento:\n\n" . $signLink . "\n\nFerreira & Sá Advocacia");
                        $whatsNum = preg_replace('/\D/', '', $client['phone']);
                        ?>
                        <a href="https://wa.me/55<?= $whatsNum ?>?text=<?= $whatsMsg ?>" target="_blank" class="btn btn-success">💬 Enviar por WhatsApp</a>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                    <div class="alert alert-info">
                        <span class="alert-icon">ℹ</span>
                        Documento criado no ZapSign. Acesse o painel do ZapSign para obter o link de assinatura.
                    </div>
                <?php endif; ?>

                <?php if ($docToken): ?>
                <p class="text-sm text-muted" style="margin-top:1rem;">Token do documento: <?= e($docToken) ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
    function copySignLink() {
        var text = document.getElementById('signLink').textContent;
        if (navigator.clipboard) { navigator.clipboard.writeText(text); }
        else {
            var ta = document.createElement('textarea');
            ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
            document.body.appendChild(ta); ta.select();
            document.execCommand('copy'); document.body.removeChild(ta);
        }
        alert('Link copiado!');
    }
    </script>

    <?php
    require_once APP_ROOT . '/templates/layout_end.php';
    exit;

} else {
    // Erro
    $errorMsg = 'Erro ao criar documento no ZapSign (HTTP ' . $httpCode . ')';
    if (isset($result['detail'])) $errorMsg .= ': ' . $result['detail'];
    elseif (isset($result['error'])) $errorMsg .= ': ' . $result['error'];
    elseif ($response) $errorMsg .= ': ' . substr($response, 0, 300);

    flash_set('error', $errorMsg);
    redirect(module_url('documentos', 'gerar.php?tipo=' . urlencode($tipo) . '&client_id=' . $clientId));
}
