<?php
/**
 * Sala VIP F&S — Funções utilitárias
 */

/**
 * Escape HTML seguro.
 */
function sv_e(?string $str): string {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Formata CPF como 000.000.000-00.
 */
function sv_formatar_cpf(string $cpf): string {
    $cpf = preg_replace('/\D/', '', $cpf);
    if (strlen($cpf) !== 11) {
        return $cpf;
    }
    return substr($cpf, 0, 3) . '.' . substr($cpf, 3, 3) . '.' . substr($cpf, 6, 3) . '-' . substr($cpf, 9, 2);
}

/**
 * Formata data para dd/mm/aaaa.
 */
function sv_formatar_data(?string $data): string {
    if (empty($data)) {
        return '';
    }
    return date('d/m/Y', strtotime($data));
}

/**
 * Formata data/hora para dd/mm/aaaa HH:ii.
 */
function sv_formatar_data_hora(?string $datetime): string {
    if (empty($datetime)) {
        return '';
    }
    return date('d/m/Y H:i', strtotime($datetime));
}

/**
 * Formata valor em centavos para moeda brasileira.
 */
function sv_formatar_moeda(int $valor): string {
    return 'R$ ' . number_format($valor / 100, 2, ',', '.');
}

/**
 * Badge HTML para status de processo.
 */
function sv_badge_status_processo(string $status): string {
    $map = [
        'em_andamento'      => ['#059669', 'Em andamento'],
        'em_elaboracao'      => ['#0ea5e9', 'Em elaboração'],
        'aguardando_docs'    => ['#f59e0b', 'Aguardando documentos'],
        'distribuido'        => ['#6366f1', 'Distribuído'],
        'doc_faltante'       => ['#dc2626', 'Documento faltante'],
        'suspenso'           => ['#9ca3af', 'Suspenso'],
        'arquivado'          => ['#6b7280', 'Arquivado'],
        'cancelado'          => ['#6b7280', 'Cancelado'],
        'aguardando_prazo'   => ['#d97706', 'Aguardando prazo'],
        'finalizado'         => ['#059669', 'Finalizado'],
    ];

    if (isset($map[$status])) {
        $cor   = $map[$status][0];
        $label = $map[$status][1];
    } else {
        $cor   = '#888';
        $label = ucfirst($status);
    }

    return sprintf(
        '<span style="background:%s;color:#fff;padding:2px 8px;border-radius:9999px;font-size:0.75rem;font-weight:600;">%s</span>',
        $cor,
        sv_e($label)
    );
}

/**
 * Badge HTML para status de parcela financeira.
 */
function sv_badge_status_parcela(string $status): string {
    $map = [
        'PENDING'   => ['#f59e0b', 'Pendente'],
        'RECEIVED'  => ['#059669', 'Pago'],
        'OVERDUE'   => ['#dc2626', 'Vencido'],
        'CONFIRMED' => ['#059669', 'Confirmado'],
        'REFUNDED'  => ['#9ca3af', 'Estornado'],
    ];

    if (isset($map[$status])) {
        $cor   = $map[$status][0];
        $label = $map[$status][1];
    } else {
        $cor   = '#888';
        $label = $status;
    }

    return sprintf(
        '<span style="background:%s;color:#fff;padding:2px 8px;border-radius:9999px;font-size:0.75rem;font-weight:600;">%s</span>',
        $cor,
        sv_e($label)
    );
}

/**
 * Nome legível para tipo de evento.
 */
function sv_nome_tipo_evento(string $tipo): string {
    $map = [
        'audiencia'       => 'Audiência',
        'reuniao_cliente' => 'Reunião',
        'prazo'           => 'Prazo',
        'onboarding'      => 'Onboarding',
        'mediacao_cejusc' => 'Mediação/CEJUSC',
        'balcao_virtual'  => 'Balcão Virtual',
        'ligacao'         => 'Ligação',
    ];

    return $map[$tipo] ?? ucfirst($tipo);
}

/**
 * Traduz termos jurídicos em linguagem acessível.
 */
function sv_traduzir_andamento(string $descricao): string {
    $de = [
        'Despacho saneador',
        'Conclusos para despacho',
        'Conclusos para decisão',
        'Conclusos para sentença',
        'Juntada de',
        'Citação',
        'Intimação',
        'Certidão',
        'Ata de Audiência',
        'Distribuição',
    ];

    $para = [
        'O juiz organizou os próximos passos do processo',
        'Processo encaminhado ao juiz para análise',
        'Processo aguardando decisão do juiz',
        'Processo aguardando sentença do juiz',
        'Documento adicionado ao processo:',
        'A outra parte foi notificada oficialmente',
        'Comunicação oficial do tribunal',
        'Documento oficial emitido',
        'Registro da audiência realizada',
        'Processo distribuído ao juiz',
    ];

    return str_ireplace($de, $para, $descricao);
}

/**
 * Envia e-mail HTML.
 */
function sv_enviar_email(string $para, string $assunto, string $corpo_html): bool {
    $headers  = "From: Ferreira & Sá Advocacia <noreply@ferreiraesa.com.br>\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "MIME-Version: 1.0\r\n";

    return mail($para, $assunto, $corpo_html, $headers);
}

/**
 * Monta URL relativa da Sala VIP.
 */
function sv_url(string $path): string {
    return SALAVIP_BASE_URL . '/' . ltrim($path, '/');
}

/**
 * Redireciona para path da Sala VIP.
 */
function sv_redirect(string $path): void {
    header('Location: ' . sv_url($path));
    exit;
}

/**
 * Define mensagem flash na sessão.
 */
function sv_flash(string $type, string $msg): void {
    $_SESSION['salavip_flash'] = ['type' => $type, 'msg' => $msg];
}

/**
 * Lê e remove mensagem flash da sessão.
 */
function sv_flash_get(): ?array {
    if (isset($_SESSION['salavip_flash'])) {
        $flash = $_SESSION['salavip_flash'];
        unset($_SESSION['salavip_flash']);
        return $flash;
    }
    return null;
}
