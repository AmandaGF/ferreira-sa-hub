<?php
/**
 * Ferreira & Sá Conecta — Funções Utilitárias Gerais
 *
 * Helpers: escape, redirect, flash messages, CSRF, sanitização,
 * formatação, URL, criptografia, auditoria, paginação.
 */

// ─── Escape HTML ────────────────────────────────────────
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ─── Limpeza de texto jurídico com HTML cru ─────────────
/**
 * Converte texto que veio com HTML cru (tags + entidades nomeadas) em texto
 * plano legível. Usado em andamentos/publicações importados de sistemas de
 * tribunal que vêm com <section>, <td>, &iacute;, &ordm;, &emsp; etc.
 *
 * Idempotente e seguro pra texto puro: só age quando DETECTA HTML (tag ou
 * entidade nomeada). Texto digitado à mão pela equipe passa intacto.
 *
 * Retorna texto plano (com \n). O caller ainda deve escapar com e() e exibir
 * com white-space:pre-wrap (NÃO usar nl2br por cima).
 */
function limpar_html_juridico(?string $texto): string
{
    $t = (string)$texto;
    if ($t === '') return '';

    // Detecta markup: tag HTML OU entidade nomeada (&iacute; etc). Ignora
    // &amp;/&lt;/&gt;/&quot;/&#NN; sozinhos pra não mexer em texto comum.
    $temTag      = preg_match('/<\/?[a-z][a-z0-9]*\b[^>]*>/i', $t);
    $temEntidade = preg_match('/&(?!amp;|lt;|gt;|quot;|#)[a-zA-Z][a-zA-Z0-9]+;/', $t);
    if (!$temTag && !$temEntidade) return $t;

    // 1. Tags de quebra/bloco viram nova linha (preserva parágrafos)
    $t = preg_replace('/<\s*br\s*\/?>/i', "\n", $t);
    $t = preg_replace('/<\/(p|div|section|header|footer|article|tr|li|h[1-6]|blockquote)\s*>/i', "\n", $t);
    $t = preg_replace('/<\/(td|th)\s*>/i', "\t", $t);

    // 2. Remove todas as tags restantes
    $t = strip_tags($t);

    // 3. Decodifica entidades (&iacute;->í, &ordm;->º, &emsp;->espaço, etc)
    $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // 4. Normaliza espaços em branco
    $t = str_replace("\xc2\xa0", ' ', $t);      // &nbsp; -> espaço comum
    $t = preg_replace('/[ \t]*\t[ \t]*/', ' ', $t); // tabs (vindos de td) -> 1 espaço
    $t = preg_replace('/[ \t]{2,}/', ' ', $t);
    $t = preg_replace('/ *\n */', "\n", $t);
    $t = preg_replace('/\n{3,}/', "\n\n", $t);
    return trim($t);
}

// ─── Redirect ───────────────────────────────────────────
function redirect(string $url)
{
    header('Location: ' . $url);
    exit;
}

// ─── Flash Messages ─────────────────────────────────────
function flash_set(string $type, string $message): void
{
    $_SESSION['flash'][$type] = $message;
}

function flash_get(string $type): ?string
{
    $msg = $_SESSION['flash'][$type] ?? null;
    unset($_SESSION['flash'][$type]);
    return $msg;
}

function flash_html(): string
{
    $html = '';
    foreach (['success', 'error', 'warning', 'info'] as $type) {
        $msg = flash_get($type);
        if ($msg) {
            $icons = ['success' => '✓', 'error' => '✕', 'warning' => '⚠', 'info' => 'ℹ'];
            $icon = $icons[$type] ?? '';
            $html .= '<div class="alert alert-' . $type . '">'
                    . '<span class="alert-icon">' . $icon . '</span> '
                    . e($msg) . '</div>';
        }
    }
    return $html;
}

// ─── CSRF ───────────────────────────────────────────────
function generate_csrf_token(): string
{
    if (empty($_SESSION[CSRF_TOKEN_NAME])) {
        $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
    }
    return $_SESSION[CSRF_TOKEN_NAME];
}

function csrf_input(): string
{
    return '<input type="hidden" name="' . CSRF_TOKEN_NAME . '" value="' . generate_csrf_token() . '">';
}

function validate_csrf(): bool
{
    $token = $_POST[CSRF_TOKEN_NAME] ?? '';
    return hash_equals($_SESSION[CSRF_TOKEN_NAME] ?? '', $token);
}

// ─── Máscara CNJ (NNNNNNN-DD.AAAA.J.TR.OOOO) ──────────
function format_cnj($numero)
{
    if (!$numero) return '';
    $num = preg_replace('/\D/', '', $numero);
    // Se já tem formatação (contém - ou .), retorna como está
    if (preg_match('/\d{7}-\d{2}\.\d{4}\.\d\.\d{2}\.\d{4}/', $numero)) return $numero;
    // Se tem 20 dígitos, formatar
    if (strlen($num) === 20) {
        return substr($num,0,7) . '-' . substr($num,7,2) . '.' . substr($num,9,4) . '.' . substr($num,13,1) . '.' . substr($num,14,2) . '.' . substr($num,16,4);
    }
    // Retorna original se não tem 20 dígitos
    return $numero;
}

// ─── Sanitização ────────────────────────────────────────
function clean_str(?string $input, int $maxLength = 500): string
{
    if ($input === null) return '';
    $input = trim($input);
    $input = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $input);
    return mb_substr($input, 0, $maxLength, 'UTF-8');
}

// ─── Formatação ─────────────────────────────────────────
function brl(int $cents): string
{
    return 'R$ ' . number_format($cents / 100, 2, ',', '.');
}

function data_br(?string $date): string
{
    if (!$date) return '—';
    $dt = DateTime::createFromFormat('Y-m-d', $date)
       ?: DateTime::createFromFormat('Y-m-d H:i:s', $date);
    return $dt ? $dt->format('d/m/Y') : '—';
}

function data_hora_br(?string $datetime): string
{
    if (!$datetime) return '—';
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $datetime);
    return $dt ? $dt->format('d/m/Y H:i') : '—';
}

// ─── Protocolo ──────────────────────────────────────────
function generate_protocol(string $prefix = 'FSA'): string
{
    return $prefix . '-' . strtoupper(substr(bin2hex(random_bytes(5)), 0, 10));
}

// ─── Criptografia (credenciais do portal) ───────────────
function encrypt_value(string $value): string
{
    $iv = random_bytes(openssl_cipher_iv_length(ENCRYPT_METHOD));
    $encrypted = openssl_encrypt($value, ENCRYPT_METHOD, ENCRYPT_KEY, 0, $iv);
    return base64_encode($iv . '::' . $encrypted);
}

function decrypt_value(string $encoded): string
{
    $data = base64_decode($encoded);
    [$iv, $encrypted] = explode('::', $data, 2);
    return openssl_decrypt($encrypted, ENCRYPT_METHOD, ENCRYPT_KEY, 0, $iv);
}

// ─── URL helpers ────────────────────────────────────────
function url(string $path = ''): string
{
    return BASE_URL . '/' . ltrim($path, '/');
}

function module_url(string $module, string $page = 'index.php'): string
{
    return url('modules/' . $module . '/' . $page);
}

function is_current_module(string $module): bool
{
    return strpos($_SERVER['REQUEST_URI'] ?? '', '/modules/' . $module) !== false;
}

/**
 * Exibe botão "Voltar ao processo" se o usuário veio de um caso.
 * Usar no topo das páginas de destino (financeiro, helpdesk, agenda).
 */
function voltar_ao_processo_html(): string
{
    // Prioridade: param URL > sessão (se referer veio do processo)
    $fromCase = (int)($_GET['from_case'] ?? 0);
    if (!$fromCase) {
        $referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
        $veioDoProcesso = (strpos($referer, 'caso_ver') !== false || strpos($referer, 'from_case') !== false);
        if ($veioDoProcesso) $fromCase = (int)($_SESSION['origem_case_id'] ?? 0);
    }
    if (!$fromCase) return '';
    try {
        $stmt = db()->prepare("SELECT title, case_number FROM cases WHERE id = ?");
        $stmt->execute(array($fromCase));
        $c = $stmt->fetch();
        if (!$c) return '';
        $label = $c['title'] ?: ($c['case_number'] ?: 'Processo #' . $fromCase);
        return '<div style="margin-bottom:.75rem;">'
            . '<a href="' . module_url('operacional', 'caso_ver.php?id=' . $fromCase) . '" class="btn btn-outline btn-sm" style="font-size:.82rem;">'
            . '&larr; Voltar ao processo: <strong>' . e($label) . '</strong></a></div>';
    } catch (Exception $e) { return ''; }
}

// ─── Parsing de valor monetário ─────────────────────────
/**
 * Extrai valor numérico em centavos de texto livre.
 * Ex: "R$ 3.108" → 310800, "5000" → 500000, "500+30%" → 50000, "Risco" → null
 */
function parse_valor_reais(?string $texto): ?int
{
    if ($texto === null || $texto === '') return null;
    // Remove "R$", espaços, e caracteres não numéricos exceto . , -
    $limpo = preg_replace('/[rR]\$\s*/', '', trim($texto));
    // Pega apenas a primeira sequência numérica (antes de +, /, etc.)
    if (!preg_match('/^[\s]*([\d.,]+)/', $limpo, $m)) return null;
    $num = $m[1];
    // Detecta formato BR: 3.108,00 ou 3.108 (ponto como milhar)
    if (preg_match('/^\d{1,3}(\.\d{3})+(,\d{1,2})?$/', $num)) {
        // Formato BR: remove pontos de milhar, troca vírgula por ponto
        $num = str_replace('.', '', $num);
        $num = str_replace(',', '.', $num);
    } elseif (strpos($num, ',') !== false) {
        // Vírgula como decimal: 3108,50
        $num = str_replace(',', '.', $num);
    }
    $valor = (float)$num;
    if ($valor <= 0) return null;
    return (int)round($valor * 100);
}

// ─── Sincronizar honorários → estimated_value_cents ─────
function sync_honorarios(PDO $pdo, int $leadId, ?string $valorTexto): void
{
    $cents = parse_valor_reais($valorTexto);
    $pdo->prepare("UPDATE pipeline_leads SET honorarios_cents = ?, estimated_value_cents = ? WHERE id = ?")
        ->execute(array($cents, $cents, $leadId));
}

function sync_estimated_value(PDO $pdo, int $leadId, ?string $valorAcao): void
{
    sync_honorarios($pdo, $leadId, $valorAcao);
}

// ─── Auditoria ──────────────────────────────────────────
function audit_log(string $action, ?string $entityType = null, ?int $entityId = null, ?string $details = null): void
{
    $userId = $_SESSION['user']['id'] ?? null;
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;

    db()->prepare(
        'INSERT INTO audit_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$userId, $action, $entityType, $entityId, $details, $ip]);
}

// ─── Paginação ──────────────────────────────────────────
function paginate(int $total, int $perPage = 20, int $currentPage = 1): array
{
    $totalPages = max(1, (int)ceil($total / $perPage));
    $currentPage = max(1, min($currentPage, $totalPages));
    $offset = ($currentPage - 1) * $perPage;

    return [
        'total'       => $total,
        'per_page'    => $perPage,
        'current'     => $currentPage,
        'total_pages' => $totalPages,
        'offset'      => $offset,
    ];
}

// ─── Sanitização de nome de arquivo pro PJe ─────────────
/**
 * Remove caracteres que o PJe (e outros tribunais) recusam em nomes de anexo.
 * Amanda 07/07/2026: PJe recusa upload de arquivo com travessão "—" no nome.
 *
 * Substitui:
 *   —  U+2014 EM DASH        → -
 *   –  U+2013 EN DASH        → -
 *   ‒  U+2012 FIGURE DASH    → -
 *   ―  U+2015 HORIZONTAL BAR → -
 *   −  U+2212 MINUS SIGN     → -
 *   " " U+00A0 NBSP          → espaço normal
 *   caracteres de controle   → removidos
 *
 * Preserva: acentos (á é ç ñ etc), espaços, hífen normal, ponto, underscore.
 *
 * Chame ANTES de mandar pro Drive/download/protocolar. Não substitui
 * sanitização mais agressiva (tipo baixar_docx que translitera tudo) —
 * cobre só o caso do PJe recusar caracteres exóticos.
 */
function sanitize_filename_pje($nome) {
    $nome = (string)$nome;
    if ($nome === '') return $nome;
    // Troca todos os "travessões unicode" por hífen ASCII
    $nome = strtr($nome, array(
        "\xE2\x80\x94" => '-',  // — em dash
        "\xE2\x80\x93" => '-',  // – en dash
        "\xE2\x80\x92" => '-',  // ‒ figure dash
        "\xE2\x80\x95" => '-',  // ― horizontal bar
        "\xE2\x88\x92" => '-',  // − minus sign
        "\xC2\xA0"     => ' ',  // NBSP -> espaço normal
    ));
    // Remove caracteres de controle (0x00-0x1F e 0x7F) que corrompem upload
    $nome = preg_replace('/[\x00-\x1F\x7F]/u', '', $nome);
    // Colapsa múltiplos espaços/hífens que a substituição possa ter gerado
    $nome = preg_replace('/\s+/', ' ', $nome);
    $nome = preg_replace('/-{2,}/', '-', $nome);
    return trim($nome);
}
