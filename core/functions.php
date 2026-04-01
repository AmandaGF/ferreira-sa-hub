<?php
/**
 * Ferreira & Sá Conecta — Funções Utilitárias
 */

require_once __DIR__ . '/config.php';

// ─── Escape HTML ────────────────────────────────────────
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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
    $valid = hash_equals($_SESSION[CSRF_TOKEN_NAME] ?? '', $token);
    // Regenerar token para o próximo uso (não apagar, para não quebrar forms múltiplos)
    $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
    return $valid;
}

// ─── Sanitização ────────────────────────────────────────
function clean_str(?string $input, int $maxLength = 500): string
{
    if ($input === null) return '';
    $input = trim($input);
    $input = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $input);
    return mb_substr($input, 0, $maxLength, 'UTF-8');
}

// ─── Roles ──────────────────────────────────────────────
// Perfis: admin, gestao, comercial, cx, operacional, colaborador, estagiario
function role_label(string $role): string
{
    $labels = array('admin' => 'Administrador', 'gestao' => 'Gestão', 'comercial' => 'Comercial', 'cx' => 'CX', 'operacional' => 'Operacional', 'colaborador' => 'Colaborador', 'estagiario' => 'Estagiário');
    return isset($labels[$role]) ? $labels[$role] : 'Desconhecido';
}

function role_level(string $role): int
{
    // admin > gestao > comercial/cx/operacional > estagiario > colaborador
    $levels = array('admin' => 5, 'gestao' => 4, 'comercial' => 3, 'cx' => 3, 'operacional' => 3, 'estagiario' => 2, 'colaborador' => 1);
    return isset($levels[$role]) ? $levels[$role] : 0;
}

function role_badge(string $role): string
{
    $label = role_label($role);
    $badgeClass = $role;
    if (in_array($role, array('comercial', 'cx', 'operacional'))) $badgeClass = 'gestao';
    return '<span class="badge badge-' . e($badgeClass) . '">' . $label . '</span>';
}

// ═══════════════════════════════════════════════════════
// SISTEMA DE PERMISSÕES POR MÓDULO
// Defaults por role + overrides individuais por usuário
// ═══════════════════════════════════════════════════════

// Módulos e quais roles TÊM acesso por padrão
function _permission_defaults()
{
    return array(
        'dashboard'           => array('admin','gestao','comercial','cx','operacional','colaborador','estagiario'),
        'dashboard_comercial' => array('admin','gestao','comercial','cx'),
        'dashboard_operacional' => array('admin','gestao','operacional'),
        'portal'              => array('admin','gestao','comercial','cx','operacional','colaborador','estagiario'),
        'helpdesk'            => array('admin','gestao','comercial','cx','operacional','colaborador','estagiario'),
        'agenda'              => array('admin','gestao','comercial','cx','operacional','colaborador','estagiario'),
        'crm'                 => array('admin','gestao','comercial','cx'),
        'pipeline'            => array('admin','gestao','comercial','cx'),
        'pipeline_mover_comercial' => array('admin','gestao','comercial'),
        'pipeline_mover_cx'   => array('admin','gestao','cx'),
        'operacional'         => array('admin','gestao','operacional','comercial','cx'),
        'operacional_mover'   => array('admin','gestao','operacional'),
        'processos'           => array('admin','gestao','operacional','comercial','cx'),
        'prazos'              => array('admin','gestao','operacional'),
        'documentos'          => array('admin','gestao','operacional'),
        'peticoes'            => array('admin','gestao','operacional'),
        'formularios'         => array('admin','gestao'),
        'relatorios'          => array('admin','gestao'),
        'usuarios'            => array('admin'),
        'faturamento'         => array('admin'),
    );
}

/**
 * Verifica se o usuário atual pode acessar um módulo.
 * 1. Admin sempre pode tudo
 * 2. Verifica override individual (user_permissions)
 * 3. Fallback para default do role
 */
function can_access($module)
{
    static $overrides = null;

    $role = current_user_role();
    if (!$role) return false;
    if ($role === 'admin') return true;

    $userId = current_user_id();

    // Carregar overrides do banco (uma vez por request)
    if ($overrides === null) {
        $overrides = array();
        try {
            $rows = db()->prepare("SELECT module, allowed FROM user_permissions WHERE user_id = ?");
            $rows->execute(array($userId));
            foreach ($rows->fetchAll() as $r) {
                $overrides[$r['module']] = (int)$r['allowed'];
            }
        } catch (Exception $e) {
            // Tabela pode não existir ainda
        }
    }

    // Override individual tem prioridade
    if (isset($overrides[$module])) {
        return (bool)$overrides[$module];
    }

    // Default do role
    $defaults = _permission_defaults();
    if (isset($defaults[$module])) {
        return in_array($role, $defaults[$module], true);
    }

    return false;
}

/**
 * Retorna as permissões de um usuário específico (para UI admin)
 */
function get_user_permissions($userId, $userRole)
{
    $defaults = _permission_defaults();
    $result = array();

    // Carregar overrides
    $overrides = array();
    try {
        $rows = db()->prepare("SELECT module, allowed FROM user_permissions WHERE user_id = ?");
        $rows->execute(array($userId));
        foreach ($rows->fetchAll() as $r) $overrides[$r['module']] = (int)$r['allowed'];
    } catch (Exception $e) {}

    foreach ($defaults as $module => $roles) {
        $defaultAllowed = in_array($userRole, $roles, true);
        $hasOverride = isset($overrides[$module]);
        $effectiveAllowed = $hasOverride ? (bool)$overrides[$module] : $defaultAllowed;

        $result[$module] = array(
            'default' => $defaultAllowed,
            'override' => $hasOverride ? (int)$overrides[$module] : null,
            'effective' => $effectiveAllowed,
        );
    }
    return $result;
}

// Labels amigáveis dos módulos
function module_permission_labels()
{
    return array(
        'dashboard' => 'Dashboard (Geral)',
        'dashboard_comercial' => 'Dashboard Comercial',
        'dashboard_operacional' => 'Dashboard Operacional',
        'portal' => 'Portal de Links',
        'helpdesk' => 'Helpdesk',
        'agenda' => 'Agenda',
        'crm' => 'CRM (Clientes)',
        'pipeline' => 'Kanban Comercial',
        'pipeline_mover_comercial' => 'Mover Pipeline (Comercial)',
        'pipeline_mover_cx' => 'Mover Pipeline (CX)',
        'operacional' => 'Kanban Operacional',
        'operacional_mover' => 'Mover Operacional',
        'processos' => 'Processos',
        'prazos' => 'Prazos',
        'documentos' => 'Documentos',
        'peticoes' => 'Fábrica de Petições',
        'formularios' => 'Formulários',
        'relatorios' => 'Relatórios',
        'usuarios' => 'Gestão de Usuários',
        'faturamento' => 'Ver Faturamento (R$)',
    );
}

// ── Funções legadas (mantidas para compatibilidade) ──
function can_view_pipeline(): bool { return can_access('pipeline'); }
function can_move_pipeline_comercial(): bool { return can_access('pipeline_mover_comercial'); }
function can_move_pipeline_cx(): bool { return can_access('pipeline_mover_cx'); }
function can_view_operacional(): bool { return can_access('operacional'); }
function can_move_operacional(): bool { return can_access('operacional_mover'); }

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

// ─── Agenda de Contatos (anti-duplicação) ─────────────

/**
 * Busca contato existente ou cria um novo.
 * Busca por: telefone (prioridade) → email → nome exato.
 * Retorna o ID do contato.
 */
function find_or_create_client(array $data): int
{
    $pdo = db();
    $name = isset($data['name']) ? trim($data['name']) : '';
    $phone = isset($data['phone']) ? trim($data['phone']) : '';
    $email = isset($data['email']) ? trim($data['email']) : '';

    // 1. Buscar por telefone (mais confiável)
    if ($phone) {
        $phoneClean = preg_replace('/\D/', '', $phone);
        if (strlen($phoneClean) >= 8) {
            $stmt = $pdo->prepare("SELECT id FROM clients WHERE REPLACE(REPLACE(REPLACE(REPLACE(phone,' ',''),'-',''),'(',''),')','') LIKE ? LIMIT 1");
            $stmt->execute(array('%' . substr($phoneClean, -8) . '%'));
            $row = $stmt->fetch();
            if ($row) return (int)$row['id'];
        }
    }

    // 2. Buscar por email
    if ($email) {
        $stmt = $pdo->prepare("SELECT id FROM clients WHERE email = ? LIMIT 1");
        $stmt->execute(array($email));
        $row = $stmt->fetch();
        if ($row) return (int)$row['id'];
    }

    // 3. Buscar por nome exato
    if ($name) {
        $stmt = $pdo->prepare("SELECT id FROM clients WHERE name = ? LIMIT 1");
        $stmt->execute(array($name));
        $row = $stmt->fetch();
        if ($row) return (int)$row['id'];
    }

    // 4. Não encontrou — criar novo
    if (!$name) return 0;

    $pdo->prepare(
        "INSERT INTO clients (name, phone, email, cpf, source, client_status, created_at) VALUES (?,?,?,?,'outro','ativo',NOW())"
    )->execute(array($name, $phone ?: null, $email ?: null, isset($data['cpf']) ? $data['cpf'] : null));

    return (int)$pdo->lastInsertId();
}

// ─── Notificações ──────────────────────────────────────

/**
 * Enviar notificação para um usuário
 */
function notify(int $userId, string $title, string $message = '', string $type = 'info', string $link = '', string $icon = ''): void
{
    try {
        db()->prepare(
            'INSERT INTO notifications (user_id, type, title, message, link, icon) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute(array($userId, $type, $title, $message ? $message : null, $link ? $link : null, $icon ? $icon : null));
    } catch (Exception $e) {
        // Silenciar se tabela não existir ainda
    }
}

/**
 * Enviar notificação para todos os admins
 */
function notify_admins(string $title, string $message = '', string $type = 'info', string $link = '', string $icon = ''): void
{
    try {
        $admins = db()->query("SELECT id FROM users WHERE role = 'admin' AND is_active = 1")->fetchAll();
        foreach ($admins as $a) {
            notify((int)$a['id'], $title, $message, $type, $link, $icon);
        }
    } catch (Exception $e) {}
}

/**
 * Enviar notificação para todos os gestores (admin + gestao)
 */
function notify_gestao(string $title, string $message = '', string $type = 'info', string $link = '', string $icon = ''): void
{
    try {
        $users = db()->query("SELECT id FROM users WHERE role IN ('admin','gestao') AND is_active = 1")->fetchAll();
        foreach ($users as $u) {
            notify((int)$u['id'], $title, $message, $type, $link, $icon);
        }
    } catch (Exception $e) {}
}

/**
 * Contar notificações não lidas do usuário logado
 */
function count_unread_notifications(): int
{
    $userId = $_SESSION['user']['id'] ?? 0;
    if (!$userId) return 0;
    try {
        $stmt = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
        $stmt->execute(array($userId));
        return (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * Buscar últimas notificações do usuário logado
 */
function get_notifications(int $limit = 10): array
{
    $userId = $_SESSION['user']['id'] ?? 0;
    if (!$userId) return array();
    try {
        $stmt = db()->prepare(
            'SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ' . $limit
        );
        $stmt->execute(array($userId));
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return array();
    }
}

// ─── Notificações ao Cliente ──────────────────────────

/**
 * Gera notificação para o cliente (WhatsApp + email).
 * Salva no log e notifica o responsável para envio manual via wa.me link.
 *
 * @param string $tipo boas_vindas|docs_recebidos|processo_distribuido|doc_faltante
 * @param int    $clientId
 * @param array  $vars Variáveis para substituir: [Nome], [numero_processo], etc.
 * @param int|null $caseId
 * @param int|null $leadId
 */
function notificar_cliente(string $tipo, int $clientId, array $vars = array(), $caseId = null, $leadId = null): void
{
    try {
        $pdo = db();

        // Buscar configuração do template
        $stmt = $pdo->prepare("SELECT * FROM notificacao_config WHERE tipo = ? AND ativo = 1");
        $stmt->execute(array($tipo));
        $config = $stmt->fetch();
        if (!$config) return; // Desativado ou inexistente

        // Buscar dados do cliente
        $stmt = $pdo->prepare("SELECT name, phone, email FROM clients WHERE id = ?");
        $stmt->execute(array($clientId));
        $client = $stmt->fetch();
        if (!$client) return;

        // Preparar variáveis de substituição
        $vars['[Nome]'] = $client['name'];
        if (!isset($vars['[link_drive]'])) {
            if ($caseId) {
                $cStmt = $pdo->prepare("SELECT drive_folder_url FROM cases WHERE id = ?");
                $cStmt->execute(array($caseId));
                $cRow = $cStmt->fetch();
                $vars['[link_drive]'] = ($cRow && $cRow['drive_folder_url']) ? $cRow['drive_folder_url'] : '';
            } else {
                $vars['[link_drive]'] = '';
            }
        }

        // Substituir variáveis na mensagem
        $mensagem = $config['mensagem_whatsapp'];
        foreach ($vars as $key => $val) {
            $mensagem = str_replace($key, $val, $mensagem);
        }

        // Salvar notificação WhatsApp
        if ($client['phone']) {
            $pdo->prepare(
                "INSERT INTO notificacoes_cliente (client_id, case_id, lead_id, tipo, canal, destinatario, mensagem) VALUES (?,?,?,?,?,?,?)"
            )->execute(array($clientId, $caseId, $leadId, $tipo, 'whatsapp', $client['phone'], $mensagem));
        }

        // Salvar notificação email (se tiver email e template de email)
        if ($client['email']) {
            $msgEmail = $config['mensagem_email'] ? $config['mensagem_email'] : $mensagem;
            foreach ($vars as $key => $val) {
                $msgEmail = str_replace($key, $val, $msgEmail);
            }
            $pdo->prepare(
                "INSERT INTO notificacoes_cliente (client_id, case_id, lead_id, tipo, canal, destinatario, mensagem) VALUES (?,?,?,?,?,?,?)"
            )->execute(array($clientId, $caseId, $leadId, $tipo, 'email', $client['email'], $msgEmail));
        }

        // Gerar link WhatsApp para envio
        $phone = preg_replace('/\D/', '', $client['phone']);
        if (strlen($phone) <= 11) $phone = '55' . $phone;
        $waLink = 'https://wa.me/' . $phone . '?text=' . rawurlencode($mensagem);

        // Notificar responsável para envio manual
        $tituloNotif = $config['titulo'] . ' — ' . $client['name'];
        // Buscar responsável do lead ou caso
        $responsavelId = null;
        if ($leadId) {
            $r = $pdo->prepare("SELECT assigned_to FROM pipeline_leads WHERE id = ?");
            $r->execute(array($leadId));
            $rr = $r->fetch();
            if ($rr) $responsavelId = (int)$rr['assigned_to'];
        }
        if (!$responsavelId && $caseId) {
            $r = $pdo->prepare("SELECT responsible_user_id FROM cases WHERE id = ?");
            $r->execute(array($caseId));
            $rr = $r->fetch();
            if ($rr) $responsavelId = (int)$rr['responsible_user_id'];
        }

        // Notificar responsável + gestão
        $msgNotif = 'Enviar para ' . $client['name'] . ': ' . mb_substr($mensagem, 0, 100) . '...';
        if ($responsavelId) {
            notify($responsavelId, $tituloNotif, $msgNotif, 'pendencia', $waLink, '💬');
        }
        notify_gestao($tituloNotif, $msgNotif, 'info', $waLink, '💬');

    } catch (Exception $e) {
        // Silenciar erros para não quebrar fluxo principal
    }
}

// ─── Checklist automático por tipo de ação ─────────────

/**
 * Retorna o checklist padrão de documentos para cada tipo de ação
 */
function get_checklist_template(string $caseType): array
{
    $caseType = mb_strtolower(trim($caseType));

    // Documentos básicos (todos os tipos)
    $basicos = array(
        'Documento de identidade (RG/CNH)',
        'CPF',
        'Comprovante de residência atualizado',
    );

    $templates = array(
        'alimentos' => array(
            'Certidão de nascimento do(s) menor(es)',
            'Comprovante de renda do alimentante',
            'Comprovante de renda do alimentado (se houver)',
            'Comprovante de despesas do(s) menor(es)',
            'Declaração de IR (se houver)',
            'Certidão de casamento ou união estável (se aplicável)',
            'Fotos/prints de comprovação de paternidade (se necessário)',
        ),
        'pensao' => array(
            'Certidão de nascimento do(s) menor(es)',
            'Comprovante de renda do alimentante',
            'Comprovante de renda do alimentado (se houver)',
            'Comprovante de despesas do(s) menor(es)',
            'Declaração de IR (se houver)',
            'Certidão de casamento ou união estável (se aplicável)',
        ),
        'divorcio' => array(
            'Certidão de casamento atualizada',
            'Pacto antenupcial (se houver)',
            'Certidão de nascimento dos filhos (se houver)',
            'Relação de bens a partilhar',
            'Escritura/matrícula de imóveis',
            'Documentos de veículos (CRLV)',
            'Extratos bancários e investimentos',
            'Declaração de IR dos cônjuges',
            'Acordo sobre guarda/convivência (se consensual)',
        ),
        'guarda' => array(
            'Certidão de nascimento do(s) menor(es)',
            'Comprovante de matrícula escolar',
            'Laudo médico/psicológico (se houver)',
            'Relatório escolar (se relevante)',
            'Comprovante de residência',
            'Fotos e provas do convívio familiar',
        ),
        'convivencia' => array(
            'Certidão de nascimento do(s) menor(es)',
            'Comprovante de matrícula escolar',
            'Laudo médico/psicológico (se houver)',
            'Comprovante de residência de ambos os genitores',
        ),
        'inventario' => array(
            'Certidão de óbito',
            'Certidão de casamento do(a) falecido(a)',
            'Certidão de nascimento dos herdeiros',
            'Testamento (se houver)',
            'Matrícula atualizada dos imóveis',
            'CRLV dos veículos',
            'Extratos bancários na data do óbito',
            'Declaração de IR do(a) falecido(a)',
            'Certidão negativa de débitos (CND)',
            'Guia ITD/ITCMD',
            'Procuração dos herdeiros',
        ),
        'familia' => array(
            'Certidão de nascimento/casamento',
            'Comprovante de renda',
            'Documentos pessoais de todos os envolvidos',
            'Provas documentais pertinentes',
        ),
        'consumidor' => array(
            'Nota fiscal / comprovante de compra',
            'Contrato de prestação de serviço',
            'Prints de conversas (WhatsApp, e-mail)',
            'Fotos do produto/serviço defeituoso',
            'Protocolo de reclamação (SAC/Procon)',
            'Comprovante de pagamento',
        ),
        'indenizacao' => array(
            'Boletim de ocorrência (se aplicável)',
            'Laudos médicos / exames',
            'Fotos e provas do dano',
            'Comprovantes de despesas decorrentes',
            'Prints de conversas relevantes',
            'Testemunhas (nomes e contatos)',
        ),
        'trabalhista' => array(
            'CTPS (Carteira de Trabalho)',
            'Contrato de trabalho',
            'Últimos 3 holerites/contracheques',
            'Termo de rescisão (TRCT)',
            'Guias do FGTS',
            'Extrato do FGTS',
            'Aviso prévio',
            'Comprovante de horas extras (se houver)',
            'Atestados médicos (se houver)',
        ),
        'fraude bancaria' => array(
            'Boletim de ocorrência',
            'Extratos bancários com transações fraudulentas',
            'Prints das transações não reconhecidas',
            'Protocolo de contestação no banco',
            'Resposta do banco à contestação',
            'Comprovante de abertura de conta',
        ),
        'imobiliario' => array(
            'Matrícula atualizada do imóvel',
            'Contrato de compra e venda',
            'Escritura pública',
            'IPTU',
            'Certidão negativa de ônus reais',
            'Planta do imóvel / habite-se',
            'Contrato de locação (se aplicável)',
        ),
        'usucapiao' => array(
            'Matrícula do imóvel (ou certidão negativa)',
            'Comprovantes de posse (contas, IPTU, correspondências)',
            'Planta e memorial descritivo',
            'ART do engenheiro/arquiteto',
            'Declaração de confrontantes',
            'Fotos do imóvel',
            'Certidão do registro de imóveis',
        ),
    );

    // Encontrar template que corresponde
    $checklist = $basicos;
    foreach ($templates as $key => $items) {
        if (strpos($caseType, $key) !== false) {
            $checklist = array_merge($basicos, $items);
            break;
        }
    }

    // Se não encontrou nenhum template específico, adicionar itens genéricos
    if (count($checklist) <= count($basicos)) {
        $checklist[] = 'Procuração';
        $checklist[] = 'Documentos comprobatórios do caso';
        $checklist[] = 'Contrato de honorários assinado';
    }

    // Sempre adicionar no final
    $checklist[] = 'Procuração assinada';
    $checklist[] = 'Contrato de honorários assinado';

    return array_unique($checklist);
}

/**
 * Gerar checklist de tarefas para um caso recém-criado
 */
function generate_case_checklist(int $caseId, string $caseType): int
{
    $items = get_checklist_template($caseType);
    $pdo = db();
    $count = 0;

    $stmt = $pdo->prepare(
        'INSERT INTO case_tasks (case_id, title, status, sort_order, created_at) VALUES (?, ?, ?, ?, NOW())'
    );

    foreach ($items as $order => $title) {
        try {
            $stmt->execute(array($caseId, $title, 'pendente', $order));
            $count++;
        } catch (Exception $e) {}
    }

    return $count;
}
