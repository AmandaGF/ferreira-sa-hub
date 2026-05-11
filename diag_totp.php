<?php
/**
 * Diagnóstico TOTP — ajuda a achar por que o código gerado pelo Hub não bate
 * com o que o sistema externo espera. Mostra:
 *   - Hora exata do servidor (local + UTC)
 *   - Códigos gerados pra cada chave cadastrada em sistemas_2fa
 *   - Códigos pros 3 steps adjacentes (-30s, agora, +30s) — pra ver se é drift
 *   - Vetor de teste RFC 6238 (chave 'JBSWY3DPEHPK3PXP') — se esse bater com o
 *     que aparece no app do celular pro mesmo secret, o problema é só drift
 *     de chave digitada errada
 *
 * Uso: https://ferreiraesa.com.br/conecta/diag_totp.php
 * Requer login + role admin (sem ?key= por ser sensível).
 */

require_once __DIR__ . '/core/middleware.php';
require_login();
require_role('admin');
require_once __DIR__ . '/core/functions_totp.php';

header('Content-Type: text/html; charset=utf-8');
$pdo = db();
totp_ensure_schema($pdo);

$agora = time();
$step = floor($agora / 30);
$segRest = totp_segundos_restantes();

echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Diag TOTP</title>';
echo '<style>body{font-family:monospace;padding:1.5rem;background:#0a0a0a;color:#e5e7eb;line-height:1.6;max-width:900px;margin:0 auto;} h1{color:#fbbf24;} h2{color:#60a5fa;border-bottom:1px solid #374151;padding-bottom:.3rem;margin-top:1.5rem;} .ok{color:#10b981;} .warn{color:#fbbf24;} .err{color:#ef4444;} .code{background:#1f2937;padding:.4rem .8rem;border-radius:6px;display:inline-block;font-size:1.3rem;font-weight:700;letter-spacing:.2rem;color:#fbbf24;} table{border-collapse:collapse;margin-top:.5rem;} td,th{padding:.3rem .8rem;border-bottom:1px solid #374151;text-align:left;}</style></head><body>';

echo '<h1>🔐 Diagnóstico TOTP</h1>';

echo '<h2>1. Hora do servidor</h2>';
echo '<p>Local: <strong>' . date('Y-m-d H:i:s') . '</strong> (' . date_default_timezone_get() . ')<br>';
echo 'UTC:   <strong>' . gmdate('Y-m-d H:i:s') . '</strong><br>';
echo 'Timestamp UNIX: <strong>' . $agora . '</strong><br>';
echo 'Step atual (30s): <strong>' . $step . '</strong> (' . $segRest . 's restantes pro próximo)<br>';
echo '<small class="warn">⚠ Compare a hora UTC acima com a hora real (ex.: time.is). Se houver diferença >5s, o servidor está com clock drift — todos os códigos vão ser rejeitados.</small></p>';

echo '<h2>2. Teste com chave conhecida (RFC 6238)</h2>';
$chaveTesteFixa = 'JBSWY3DPEHPK3PXP'; // exemplo público
$codigoFixo = totp_gerar($chaveTesteFixa);
echo '<p>Chave: <code>' . $chaveTesteFixa . '</code><br>';
echo 'Código agora: <span class="code">' . $codigoFixo . '</span></p>';
echo '<p><small>Cole essa chave no Google Authenticator (manual entry) e veja se o código mostrado no app bate com o acima.<br>';
echo '✓ Bate → o algoritmo está OK, problema deve ser chave digitada errada no sistema 2FA.<br>';
echo '✗ Não bate → drift de clock do servidor (mais de 30s de diferença). Avisar TurboCloud / NTP.</small></p>';

echo '<h2>3. Códigos dos sistemas cadastrados</h2>';
$sistemas = $pdo->query("SELECT id, nome, chave_encrypted FROM sistemas_2fa ORDER BY id")->fetchAll();
if (!$sistemas) {
    echo '<p class="warn">Nenhum sistema cadastrado ainda.</p>';
} else {
    echo '<table><tr><th>Sistema</th><th>Código −30s</th><th>Código AGORA</th><th>Código +30s</th><th>Primeiros 4 chars da chave</th></tr>';
    foreach ($sistemas as $s) {
        $chave = totp_decrypt($s['chave_encrypted']);
        if (!$chave) {
            echo '<tr><td>' . htmlspecialchars($s['nome']) . '</td><td colspan="4" class="err">Falha ao decifrar (TOTP_ENCRYPTION_KEY mudou?)</td></tr>';
            continue;
        }
        $codAnt = totp_gerar($chave, $agora - 30);
        $codAgora = totp_gerar($chave, $agora);
        $codProx = totp_gerar($chave, $agora + 30);
        $primeiros = substr($chave, 0, 4) . '... (' . strlen($chave) . ' chars total)';
        echo '<tr>';
        echo '<td>' . htmlspecialchars($s['nome']) . '</td>';
        echo '<td>' . $codAnt . '</td>';
        echo '<td class="ok"><strong>' . $codAgora . '</strong></td>';
        echo '<td>' . $codProx . '</td>';
        echo '<td>' . $primeiros . '</td>';
        echo '</tr>';
    }
    echo '</table>';
    echo '<p><small>Se o sistema externo rejeitou um código que aparece nessa tabela (especialmente o "AGORA"), pode ser:<br>';
    echo '1. <strong>Drift de clock</strong> — sistema externo aceita ±30s, o "−30s" ou "+30s" pode bater<br>';
    echo '2. <strong>Chave copiada errada</strong> — confira os primeiros 4 chars com a chave original<br>';
    echo '3. <strong>Configuração não-padrão</strong> do sistema (raríssimo — SHA256 em vez de SHA1, 8 dígitos em vez de 6, period 60s em vez de 30s)</small></p>';
}

echo '<h2>4. Próximos passos sugeridos</h2>';
echo '<ol>';
echo '<li>Abre o app autenticador no celular e adiciona a chave de teste do item 2 (<code>' . $chaveTesteFixa . '</code>) MANUALMENTE</li>';
echo '<li>Compara o código do app com <span class="code">' . $codigoFixo . '</span> nesse mesmo segundo</li>';
echo '<li>Se bater → bug não é clock, refaz a chave do eproc no item 3 (apaga e cola de novo)</li>';
echo '<li>Se NÃO bater → clock drift do servidor, abrir chamado na TurboCloud pra forçar ntpdate</li>';
echo '</ol>';

echo '</body></html>';
