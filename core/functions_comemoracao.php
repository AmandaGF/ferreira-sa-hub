<?php
/**
 * Ferreira & Sá Hub — Comemoração de Contrato Assinado
 *
 * Quando um lead vai pra stage 'contrato_assinado', manda mensagem
 * automática no grupo WhatsApp do escritório anunciando a vitória.
 *
 * Killswitch via configuracoes.comemoracao_contrato_ativada.
 * Tudo configurável em /modules/admin/comemorar_contrato.php.
 *
 * Amanda 15/06/2026.
 */

if (!function_exists('comemoracao_get_config')) {

function comemoracao_get_config() {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = array(
        'ativada' => '0',
        'canal' => '21',
        'grupo_id' => '',
        'template' => "🎉🔔 *CONTRATO FECHADO!* 🔔🎉\n\nParabéns ao time! ✨\n\n👤 Cliente: *[cliente]*\n💼 Caso: [tipo_caso]\n💰 Valor: R\$ [valor]\n🎯 Vendedor(a): *[comercial]*\n\n_Mais um a equipe Ferreira & Sá Advocacia conquistou!_ 💪",
    );
    try {
        $st = db()->prepare("SELECT chave, valor FROM configuracoes WHERE chave IN
            ('comemoracao_contrato_ativada','comemoracao_contrato_canal','comemoracao_contrato_grupo_id','comemoracao_contrato_template')");
        $st->execute();
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $k = str_replace('comemoracao_contrato_', '', $r['chave']);
            if (isset($cache[$k])) $cache[$k] = (string)$r['valor'];
        }
    } catch (Throwable $e) {}
    return $cache;
}

/**
 * Dispara mensagem de comemoração no grupo WhatsApp.
 * @param array $lead array com chaves: name, case_type, estimated_value_cents, honorarios_cents, assigned_to
 * @return array ['ok'=>bool, 'erro'=>?, 'mensagem'=>?]
 */
function comemorar_contrato_assinado($lead) {
    $cfg = comemoracao_get_config();
    if ($cfg['ativada'] !== '1') return array('ok' => false, 'erro' => 'Feature desativada');
    if (!$cfg['grupo_id']) return array('ok' => false, 'erro' => 'Grupo do WhatsApp não configurado');
    if (!in_array($cfg['canal'], array('21','24'), true)) return array('ok' => false, 'erro' => 'Canal inválido');

    // Resolver nome do comercial
    $comercialNome = 'equipe';
    if (!empty($lead['assigned_to'])) {
        try {
            $st = db()->prepare("SELECT name FROM users WHERE id = ?");
            $st->execute(array((int)$lead['assigned_to']));
            $n = (string)$st->fetchColumn();
            if ($n) {
                // Primeiro nome só, mais informal
                $parts = preg_split('/\s+/', $n);
                $comercialNome = $parts[0] ?: $n;
            }
        } catch (Throwable $e) {}
    }

    // Valor em formato BR
    $valorCents = isset($lead['estimated_value_cents']) ? (int)$lead['estimated_value_cents'] : 0;
    if (!$valorCents && !empty($lead['honorarios_cents'])) $valorCents = (int)$lead['honorarios_cents'];
    $valorFmt = $valorCents > 0 ? number_format($valorCents / 100, 2, ',', '.') : 'a combinar';

    $tipoCaso = !empty($lead['case_type']) ? $lead['case_type'] : 'não informado';

    $msg = strtr($cfg['template'], array(
        '[cliente]'   => $lead['name'] ?? 'Cliente',
        '[comercial]' => $comercialNome,
        '[valor]'     => $valorFmt,
        '[tipo_caso]' => $tipoCaso,
        '[hoje]'      => date('d/m/Y'),
    ));

    require_once __DIR__ . '/functions_zapi.php';
    $r = zapi_send_text($cfg['canal'], $cfg['grupo_id'], $msg);

    // Log na configuracoes pra debug (ultimas 5 tentativas)
    try {
        $hist = json_decode((string)(db()->query("SELECT valor FROM configuracoes WHERE chave='comemoracao_contrato_log'")->fetchColumn()), true) ?: array();
        array_unshift($hist, array(
            'em'       => date('Y-m-d H:i:s'),
            'cliente'  => $lead['name'] ?? '',
            'ok'       => !empty($r['ok']),
            'erro'     => $r['ok'] ? null : ($r['erro'] ?? ''),
        ));
        $hist = array_slice($hist, 0, 10);
        db()->prepare("INSERT INTO configuracoes (chave, valor) VALUES ('comemoracao_contrato_log', ?)
                       ON DUPLICATE KEY UPDATE valor = VALUES(valor)")
            ->execute(array(json_encode($hist, JSON_UNESCAPED_UNICODE)));
    } catch (Throwable $e) {}

    return array(
        'ok'        => !empty($r['ok']),
        'erro'      => $r['ok'] ? null : ($r['erro'] ?? 'erro desconhecido'),
        'mensagem'  => $msg,
    );
}

} // if function_exists
