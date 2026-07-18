<?php
/**
 * Central VIP F&S — Funções utilitárias
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
    // Rótulos voltados ao CLIENTE. Traduzem os stages internos do Kanban
    // operacional (tabela cases) para linguagem amigável. NUNCA expor o nome
    // cru do stage — é jargão interno (ex.: "Para_execucao_ia", "Kanban_prev").
    $map = [
        'em_andamento'           => ['#059669', 'Em andamento'],
        'em_elaboracao'          => ['#0ea5e9', 'Em elaboração'],
        'para_execucao_ia'       => ['#0ea5e9', 'Em elaboração'],
        'aguardando_docs'        => ['#f59e0b', 'Aguardando documentos'],
        'aguardando_prazo'       => ['#d97706', 'Aguardando distribuição'],
        'distribuido'            => ['#6366f1', 'Distribuído'],
        'doc_faltante'           => ['#dc2626', 'Documento faltante'],
        'suspenso'               => ['#9ca3af', 'Suspenso'],
        'kanban_prev'            => ['#059669', 'Em andamento'],
        'parceria_previdenciario'=> ['#059669', 'Em andamento'],
        'para_arquivar'          => ['#059669', 'Em andamento'],
        'arquivado'              => ['#6b7280', 'Arquivado'],
        'cancelado'              => ['#6b7280', 'Cancelado'],
        'finalizado'             => ['#059669', 'Finalizado'],
    ];

    if (isset($map[$status])) {
        $cor   = $map[$status][0];
        $label = $map[$status][1];
    } else {
        // Fallback SEGURO: qualquer stage interno novo/desconhecido cai aqui.
        // Nunca imprimir o nome cru do stage — mostrar rótulo genérico neutro.
        $cor   = '#059669';
        $label = 'Em andamento';
    }

    return sprintf(
        '<span style="background:%s;color:#fff;padding:2px 8px;border-radius:9999px;font-size:0.75rem;font-weight:600;">%s</span>',
        $cor,
        sv_e($label)
    );
}

/**
 * Situação a AVISAR ao cliente sobre um processo, na Central VIP.
 *
 * Regra (Amanda 17/07): o cliente NÃO vê o status interno do Kanban. Só
 * recebe um aviso em DUAS situações específicas:
 *   - processo arquivado
 *   - renúncia/desistência registrada para o processo (tabela `renuncias`)
 *
 * Em qualquer outro caso retorna null (nada é exibido).
 *
 * @return array|null ['icon','label','texto','cor','bg','border'] ou null
 */
function sv_situacao_aviso_cliente(PDO $pdo, int $caseId, string $status) {
    // 1) Renúncia / desistência tem prioridade sobre o status.
    $tipo = null;
    try {
        $st = $pdo->prepare("SELECT tipo FROM renuncias WHERE case_id = ? ORDER BY created_at DESC LIMIT 1");
        $st->execute([$caseId]);
        $tipo = $st->fetchColumn();
    } catch (Exception $e) { /* tabela pode nao existir em ambiente antigo */ }

    if ($tipo === 'renuncia') {
        return [
            'icon' => '⚠️', 'label' => 'Patrocínio encerrado',
            'texto' => 'O escritório comunicou a renúncia ao patrocínio deste processo. Fale com a nossa equipe para mais informações.',
            'cor' => '#92400e', 'bg' => '#fffbeb', 'border' => '#fde68a',
        ];
    }
    if ($tipo === 'desistencia') {
        return [
            'icon' => '⚠️', 'label' => 'Processo encerrado',
            'texto' => 'Este processo foi encerrado por desistência. Fale com a nossa equipe para mais informações.',
            'cor' => '#92400e', 'bg' => '#fffbeb', 'border' => '#fde68a',
        ];
    }

    // 2) Processo arquivado.
    if ($status === 'arquivado') {
        return [
            'icon' => '📦', 'label' => 'Processo arquivado',
            'texto' => 'Este processo foi arquivado. Fale com a nossa equipe se precisar de alguma informação.',
            'cor' => '#4b5563', 'bg' => '#f9fafb', 'border' => '#e5e7eb',
        ];
    }

    return null;
}

/**
 * Cor consistente por processo (case_id) para tema dark da Central VIP.
 * Retorna ['bg' => transparente, 'text' => claro, 'border' => sólida].
 * Mesmo case_id sempre cai na mesma cor da paleta.
 */
function sv_cor_processo(int $caseId): array {
    $palette = [
        ['bg' => 'rgba(96,165,250,.15)',  'text' => '#93c5fd', 'border' => '#3b82f6'], // blue
        ['bg' => 'rgba(244,114,182,.15)', 'text' => '#f9a8d4', 'border' => '#ec4899'], // pink
        ['bg' => 'rgba(74,222,128,.15)',  'text' => '#86efac', 'border' => '#22c55e'], // green
        ['bg' => 'rgba(251,191,36,.15)',  'text' => '#fcd34d', 'border' => '#f59e0b'], // amber
        ['bg' => 'rgba(167,139,250,.15)', 'text' => '#c4b5fd', 'border' => '#8b5cf6'], // violet
        ['bg' => 'rgba(232,121,249,.15)', 'text' => '#f0abfc', 'border' => '#d946ef'], // fuchsia
        ['bg' => 'rgba(248,113,113,.15)', 'text' => '#fca5a5', 'border' => '#ef4444'], // red
        ['bg' => 'rgba(34,211,238,.15)',  'text' => '#67e8f9', 'border' => '#06b6d4'], // cyan
    ];
    return $palette[$caseId % count($palette)];
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
 * Monta URL relativa da Central VIP.
 */
function sv_url(string $path): string {
    return SALAVIP_BASE_URL . '/' . ltrim($path, '/');
}

/**
 * Redireciona para path da Central VIP.
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
