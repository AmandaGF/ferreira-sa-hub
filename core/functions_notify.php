<?php
/**
 * Ferreira & Sá Conecta — Sistema de Notificações
 *
 * Notificações internas (entre colaboradores) e
 * notificações para clientes (WhatsApp + email).
 */

// ─── Notificações Internas ──────────────────────────────

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
        $primeiroNome = explode(' ', trim($client['name']))[0];
        $vars['[Nome]'] = $primeiroNome;
        $vars['[NomeCompleto]'] = $client['name'];
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
            notify($responsavelId, $tituloNotif, $msgNotif, 'pendencia', $waLink, '');
        }
        notify_gestao($tituloNotif, $msgNotif, 'info', $waLink, '');

    } catch (Exception $e) {
        // Silenciar erros para não quebrar fluxo principal
    }
}
