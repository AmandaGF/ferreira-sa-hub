<?php
/**
 * Auditoria completa da CONFIGURAÇÃO do WhatsApp no Hub.
 * Diferente do diag_wa.php (que olha dados), esse foca em config: tokens,
 * webhooks, templates, etiquetas, crons, integrações.
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
require_role('admin');
require_once __DIR__ . '/../../core/functions_zapi.php';

$pageTitle = 'Auditoria Config WhatsApp';
include __DIR__ . '/../../templates/layout_start.php';

$pdo = db();
$cfg = zapi_get_config();
$alertas = array();
$ok = array();

echo '<style>
.diag-sec{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:1rem;margin:.8rem 0;}
.diag-sec h2{margin-top:0;color:#052228;font-size:.95rem;border-bottom:2px solid #B87333;padding-bottom:6px;}
.diag-sec table{width:100%;border-collapse:collapse;font-size:12px;}
.diag-sec td,.diag-sec th{padding:6px 8px;border-bottom:1px solid #f1f5f9;text-align:left;vertical-align:top;}
.diag-sec th{background:#052228;color:#fff;}
.bx{padding:.5rem .8rem;border-radius:6px;margin:.3rem 0;font-size:.82rem;}
.bx-ok{background:#d1fae5;color:#065f46;}
.bx-no{background:#fee2e2;color:#991b1b;}
.bx-warn{background:#fef3c7;color:#92400e;}
code{font-size:11px;color:#475569;}
</style>';

echo '<h1 style="color:#052228">🔍 Auditoria de configuração do WhatsApp</h1>';
echo '<p style="color:#6b7280;font-size:.85rem">Verifica config viva (não dados): instâncias, tokens, webhooks, templates, crons, integrações. ' . date('d/m/Y H:i') . '</p>';

// ─── 1. INSTÂNCIAS ───────────────────────────────────────────────
echo '<div class="diag-sec"><h2>1. Instâncias Z-API</h2>';
$instancias = $pdo->query("SELECT * FROM zapi_instancias ORDER BY ddd")->fetchAll();
if (empty($instancias)) {
    echo '<div class="bx bx-no">✕ Nenhuma instância cadastrada em zapi_instancias!</div>';
    $alertas[] = 'Sem instâncias';
} else {
    echo '<table><tr><th>DDD</th><th>Instance ID</th><th>Token</th><th>Conectado?</th><th>Status ao vivo</th><th>Webhook esperado</th></tr>';
    foreach ($instancias as $i) {
        $temId = !empty($i['instancia_id']);
        $temToken = !empty($i['token']);
        $statusVivo = '?';
        if ($temId && $temToken) {
            $url = rtrim($cfg['base_url'], '/') . '/' . $i['instancia_id'] . '/token/' . $i['token'] . '/status';
            $headers = array('Content-Type: application/json');
            if (!empty($cfg['client_token'])) $headers[] = 'Client-Token: ' . $cfg['client_token'];
            $ch = curl_init($url);
            curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>10, CURLOPT_HTTPHEADER=>$headers, CURLOPT_SSL_VERIFYPEER=>false));
            $r = curl_exec($ch);
            $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $j = json_decode($r, true);
            if ($http === 200 && is_array($j)) {
                $statusVivo = !empty($j['connected']) ? '<span style="color:#065f46;font-weight:700">✓ conectada</span>' : '<span style="color:#991b1b;font-weight:700">✕ DESCONECTADA</span>';
                if (empty($j['connected'])) $alertas[] = "Instância DDD {$i['ddd']} desconectada";
            } else {
                $statusVivo = '<span style="color:#991b1b">HTTP ' . $http . '</span>';
                $alertas[] = "Instância DDD {$i['ddd']} HTTP " . $http;
            }
        }
        $whEsperado = url('api/zapi_webhook.php?numero=' . $i['ddd']);
        echo '<tr><td><strong>' . htmlspecialchars($i['ddd']) . '</strong></td>';
        echo '<td>' . ($temId ? '<code>' . htmlspecialchars($i['instancia_id']) . '</code>' : '<span style="color:#991b1b">VAZIO</span>') . '</td>';
        echo '<td>' . ($temToken ? '<code>' . substr($i['token'], 0, 8) . '...</code>' : '<span style="color:#991b1b">VAZIO</span>') . '</td>';
        echo '<td>' . ($i['ativo'] ? 'sim' : '<span style="color:#991b1b">não</span>') . '</td>';
        echo '<td>' . $statusVivo . '</td>';
        echo '<td><code>' . htmlspecialchars($whEsperado) . '</code></td></tr>';
    }
    echo '</table>';
    echo '<p style="font-size:.75rem;color:#6b7280;margin-top:.5rem">⚠️ Cadastre os 3 webhook URLs (received-callback, sent-callback, status-callback) na Z-API apontando pra <code>' . htmlspecialchars($whEsperado ?? '...') . '</code> (apenas substitua o numero=21 ou 24).</p>';
}
echo '</div>';

// ─── 2. CONFIGURAÇÕES (configuracoes) ────────────────────────────
echo '<div class="diag-sec"><h2>2. Configurações operacionais (configuracoes)</h2>';
$chavesEsperadas = array(
    'zapi_base_url' => 'URL base da Z-API',
    'zapi_client_token' => 'Client-Token (header)',
    'brevo_api_key' => 'Brevo (e-mail)',
    'groq_api_key' => 'Groq (transcrição áudio)',
    'groq_transcribe_on' => 'Transcrição automática on/off',
    'zapi_auto_aniversario' => 'Cron aniversário ativo',
    'zapi_auto_aniversario_canal' => 'Canal do cron aniversário',
    'zapi_auto_aniversario_tpl' => 'Template do cron aniversário',
);
$cfgs = array();
foreach ($pdo->query("SELECT chave, valor FROM configuracoes")->fetchAll() as $r) {
    $cfgs[$r['chave']] = $r['valor'];
}
echo '<table><tr><th>Chave</th><th>Descrição</th><th>Status</th></tr>';
foreach ($chavesEsperadas as $k => $desc) {
    $val = $cfgs[$k] ?? null;
    $cor = $val ? '#065f46' : '#991b1b';
    $status = $val ? ('✓ ' . (in_array($k, array('groq_api_key','brevo_api_key','zapi_client_token'), true) ? '(set, ' . strlen($val) . ' chars)' : htmlspecialchars(mb_substr($val, 0, 60)))) : 'AUSENTE';
    echo '<tr><td><code>' . htmlspecialchars($k) . '</code></td><td>' . htmlspecialchars($desc) . '</td><td style="color:' . $cor . '">' . $status . '</td></tr>';
    if (!$val && in_array($k, array('zapi_client_token','brevo_api_key','groq_api_key'))) $alertas[] = "Chave '{$k}' não configurada";
}
echo '</table></div>';

// ─── 3. TEMPLATES ────────────────────────────────────────────────
echo '<div class="diag-sec"><h2>3. Templates de mensagem</h2>';
try {
    $tpls = $pdo->query("SELECT id, nome, categoria, conteudo, ativo FROM zapi_templates ORDER BY categoria, nome")->fetchAll();
    if (empty($tpls)) {
        echo '<div class="bx bx-warn">⚠️ Nenhum template cadastrado.</div>';
    } else {
        $totalAtivos = 0; $totalInativos = 0;
        $variaveisRaras = array();
        foreach ($tpls as $t) {
            if ($t['ativo']) $totalAtivos++; else $totalInativos++;
            // Detecta variáveis tipo [nome], [link_meet], etc
            if (preg_match_all('/\[([a-z_]+)\]/i', $t['conteudo'] ?? '', $matches)) {
                foreach ($matches[1] as $v) {
                    if (!isset($variaveisRaras[$v])) $variaveisRaras[$v] = 0;
                    $variaveisRaras[$v]++;
                }
            }
        }
        echo '<div class="bx bx-ok">' . count($tpls) . ' templates total · ' . $totalAtivos . ' ativos · ' . $totalInativos . ' inativos</div>';

        // Categorias e contagem
        $porCat = array();
        foreach ($tpls as $t) { $porCat[$t['categoria']] = ($porCat[$t['categoria']] ?? 0) + 1; }
        echo '<p style="font-size:.8rem"><strong>Por categoria:</strong> ';
        foreach ($porCat as $c => $n) echo '<code>' . htmlspecialchars($c ?: '(sem categoria)') . '</code>=' . $n . ' &nbsp; ';
        echo '</p>';

        echo '<p style="font-size:.8rem"><strong>Variáveis usadas:</strong> ';
        foreach ($variaveisRaras as $v => $n) echo '<code>[' . $v . ']</code> (' . $n . 'x) &nbsp; ';
        echo '</p>';

        // Templates inativos (suspeito — alguém deveria desabilitar?)
        if ($totalInativos > 0) {
            echo '<details><summary style="cursor:pointer;color:#b45309">⚠️ ' . $totalInativos . ' templates inativos (clicar)</summary><table><tr><th>ID</th><th>Nome</th><th>Categoria</th></tr>';
            foreach ($tpls as $t) if (!$t['ativo']) echo '<tr><td>' . $t['id'] . '</td><td>' . htmlspecialchars($t['nome']) . '</td><td>' . htmlspecialchars($t['categoria']) . '</td></tr>';
            echo '</table></details>';
        }
    }
} catch (Exception $e) { echo '<div class="bx bx-no">Erro: ' . htmlspecialchars($e->getMessage()) . '</div>'; }
echo '</div>';

// ─── 4. ETIQUETAS ────────────────────────────────────────────────
echo '<div class="diag-sec"><h2>4. Etiquetas (zapi_etiquetas)</h2>';
try {
    $etqs = $pdo->query("SELECT id, nome, cor, ativo FROM zapi_etiquetas ORDER BY nome")->fetchAll();
    echo '<table><tr><th>ID</th><th>Nome</th><th>Cor</th><th>Ativo</th><th>Uso</th></tr>';
    foreach ($etqs as $e) {
        $uso = (int)$pdo->query("SELECT COUNT(*) FROM zapi_conversa_etiquetas WHERE etiqueta_id = " . (int)$e['id'])->fetchColumn();
        echo '<tr><td>' . $e['id'] . '</td><td>' . htmlspecialchars($e['nome']) . '</td><td><span style="display:inline-block;width:14px;height:14px;background:' . htmlspecialchars($e['cor']) . ';border-radius:2px;vertical-align:middle"></span> <code>' . htmlspecialchars($e['cor']) . '</code></td><td>' . ($e['ativo'] ? '✓' : '✕') . '</td><td>' . $uso . ' conv</td></tr>';
    }
    echo '</table>';
    // Verifica se "AT DESBLOQUEADO" existe
    $temAt = false;
    foreach ($etqs as $e) if (stripos($e['nome'], 'AT DESBLOQUEADO') !== false || stripos($e['nome'], 'desbloq') !== false) $temAt = true;
    if (!$temAt) echo '<div class="bx bx-warn">⚠️ Etiqueta "AT DESBLOQUEADO" parece não existir — cron de etiquetas não vai funcionar.</div>';
} catch (Exception $e) { echo '<div class="bx bx-no">Erro: ' . htmlspecialchars($e->getMessage()) . '</div>'; }
echo '</div>';

// ─── 5. ARQUIVOS órfãos / problemas ──────────────────────────────
echo '<div class="diag-sec"><h2>5. Arquivos em /files/whatsapp/</h2>';
$dirWa = APP_ROOT . '/files/whatsapp';
if (!is_dir($dirWa)) {
    echo '<div class="bx bx-no">✕ Pasta /files/whatsapp/ não existe!</div>';
} else {
    $totalWebm = 0; $totalOgg = 0; $totalOutros = 0; $totalSize = 0;
    foreach (glob($dirWa . '/wa_audio_*') as $f) {
        $totalSize += filesize($f);
        if (substr($f, -5) === '.webm') $totalWebm++;
        elseif (substr($f, -4) === '.ogg') $totalOgg++;
        else $totalOutros++;
    }
    echo '<div class="bx bx-ok">' . ($totalWebm + $totalOgg + $totalOutros) . ' arquivos de áudio · ' . $totalWebm . ' .webm · ' . $totalOgg . ' .ogg · ' . round($totalSize/1024/1024, 1) . ' MB total</div>';
    if ($totalWebm > 50) echo '<div class="bx bx-warn">⚠️ ' . $totalWebm . ' áudios .webm pendurados (legacy — replay pode falhar pra cliente). Limpeza de housekeeping seria benéfica.</div>';

    // Verificar .htaccess
    $ht = $dirWa . '/.htaccess';
    if (file_exists($ht)) {
        echo '<details><summary style="cursor:pointer">.htaccess (exists, ' . filesize($ht) . ' bytes)</summary><pre style="font-size:11px;background:#f8fafc;padding:8px;">' . htmlspecialchars(file_get_contents($ht)) . '</pre></details>';
    } else {
        echo '<div class="bx bx-no">✕ /files/whatsapp/.htaccess NÃO existe — Content-Type de .webm/.ogg pode estar errado!</div>';
        $alertas[] = '.htaccess de /files/whatsapp/ ausente';
    }
}
echo '</div>';

// ─── 6. CRONS ────────────────────────────────────────────────────
echo '<div class="diag-sec"><h2>6. Crons WA (verificação rápida)</h2>';
$crons = array(
    'wa_saude_check' => 'Saúde WhatsApp horário',
    'wa_resumo_semanal' => 'Resumo semanal Brevo',
    'wa_lid_refresh' => 'Refresh mensal de @lid',
    'zapi_aniversarios' => 'Aniversariantes',
    'zapi_health_check' => 'Health-check da Z-API',
);
echo '<table><tr><th>Script</th><th>Descrição</th><th>Existe?</th><th>Última execução (audit_log)</th></tr>';
foreach ($crons as $script => $desc) {
    $path = APP_ROOT . '/cron/' . $script . '.php';
    $existe = file_exists($path);
    $ult = '?';
    try {
        $st = $pdo->prepare("SELECT created_at FROM audit_log WHERE action LIKE ? ORDER BY id DESC LIMIT 1");
        $st->execute(array('%' . $script . '%'));
        $ult = $st->fetchColumn() ?: '<span style="color:#991b1b">nunca</span>';
    } catch (Exception $e) {}
    echo '<tr><td><code>' . htmlspecialchars($script) . '</code></td><td>' . htmlspecialchars($desc) . '</td><td>' . ($existe ? '✓' : '<span style="color:#991b1b">✕</span>') . '</td><td>' . $ult . '</td></tr>';
}
echo '</table>';
echo '<p style="font-size:.75rem;color:#6b7280">⚠️ A coluna "Última execução" mostra quando o audit_log foi gravado. Se mostra "nunca", o cron pode não estar cadastrado no cPanel.</p>';
echo '</div>';

// ─── 7. ESTATÍSTICAS DE FALHAS RECENTES ──────────────────────────
echo '<div class="diag-sec"><h2>7. Sintomas operacionais (últimos 7 dias)</h2>';
$st = $pdo->query("SELECT COUNT(*) FROM zapi_mensagens WHERE direcao='enviada' AND (zapi_message_id IS NULL OR zapi_message_id='') AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
$semId = (int)$st->fetchColumn();
$st = $pdo->query("SELECT COUNT(*) FROM zapi_mensagens WHERE direcao='enviada' AND status IN ('erro','falhou') AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
$comErro = (int)$st->fetchColumn();
$st = $pdo->query("SELECT COUNT(*) FROM zapi_conversas WHERE COALESCE(eh_grupo,0)=0 AND (LENGTH(REGEXP_REPLACE(telefone,'[^0-9]','')) > 14 OR telefone LIKE '%@lid%')");
$lidBruto = (int)$st->fetchColumn();
$st = $pdo->query("SELECT COUNT(*) FROM zapi_conversas WHERE precisa_revisao = 1");
$precisaRev = (int)$st->fetchColumn();

echo '<table><tr><th>Sintoma</th><th>Qtd</th><th>O que indica</th></tr>';
echo '<tr><td>Msgs enviadas SEM message_id (7d)</td><td><strong>' . $semId . '</strong></td><td>Falha silenciosa de envio (Z-API não confirmou)</td></tr>';
echo '<tr><td>Msgs com status erro/falhou (7d)</td><td><strong>' . $comErro . '</strong></td><td>Erros explícitos da Z-API</td></tr>';
echo '<tr><td>Conv com telefone @lid bruto</td><td><strong>' . $lidBruto . '</strong></td><td>Conv legacy com phone esquisito (auto-upgrade vai resolver)</td></tr>';
echo '<tr><td>Conv com flag precisa_revisao</td><td><strong>' . $precisaRev . '</strong></td><td>Cadastro precisa atenção manual</td></tr>';
echo '</table></div>';

// ─── 8. ALERTAS CONSOLIDADOS ─────────────────────────────────────
echo '<div class="diag-sec" style="border:2px solid ' . (empty($alertas) ? '#10b981' : '#f59e0b') . '">';
echo '<h2>📊 Alertas consolidados</h2>';
if (empty($alertas)) {
    echo '<div class="bx bx-ok"><strong>✓ Tudo OK</strong> — nenhuma anomalia crítica detectada na configuração.</div>';
} else {
    echo '<div class="bx bx-warn"><strong>' . count($alertas) . ' anomalia(s) detectada(s):</strong></div>';
    echo '<ul>';
    foreach ($alertas as $a) echo '<li>' . htmlspecialchars($a) . '</li>';
    echo '</ul>';
}
echo '</div>';

include __DIR__ . '/../../templates/layout_end.php';
