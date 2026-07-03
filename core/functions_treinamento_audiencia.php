<?php
/**
 * Helpers do módulo de treinamento obrigatório de audiência remota
 * (feature Amanda 02/07/2026 — após ela levar esporro de juíza porque
 * cliente não conseguiu acessar audiência remota pela 2ª vez).
 *
 * Fluxo:
 *   1. Adv (ou automação) chama treinamento_audiencia_criar($pdo, $caseId, $agendaEventoId?, $userId)
 *      → gera token, insere row, retorna URL única
 *   2. URL única é mandada pro cliente via WhatsApp
 *   3. Cliente abre publico/treinamento_audiencia.php?t=TOKEN
 *   4. Lê cartilha + faz testes + preenche nome/CPF + aceita termo
 *   5. Backend grava aceite (hash dos checkboxes, IP, user-agent) e
 *      gera certificado
 *
 * Killswitch: configuracoes.treinamento_audiencia_ativo=0 impede envio
 * (mas link já emitido ainda funciona pra não deixar cliente na mão).
 */

/**
 * ═══════════════════════════════════════════════════════════════════
 * MINUTA DO TERMO — v1 (2026-07-02)
 *
 * ⚠️ AMANDA PRECISA REVISAR ANTES DE ATIVAR O KILLSWITCH.
 *
 * Texto redigido pra:
 *  - Confirmar que cliente foi orientado
 *  - Confirmar que testou infraestrutura
 *  - Assumir responsabilidade por falhas de conexão/equipamento/ambiente
 *    no dia da audiência
 *  - Não renuncia direitos processuais — só declara ciência das
 *    orientações e da responsabilidade pela adequação técnica
 * ═══════════════════════════════════════════════════════════════════
 */
if (!function_exists('treinamento_audiencia_termo_texto')) {
function treinamento_audiencia_termo_texto() {
    return array(
        'versao' => 'minuta-v1-2026-07-02',
        'titulo' => 'Termo de Ciência e Responsabilidade — Audiência por Videoconferência',
        'preambulo' => 'Pelo presente instrumento, o(a) participante identificado(a) ao final DECLARA, de livre e espontânea vontade, o seguinte:',
        'clausulas' => array(
            array(
                'num' => '1',
                'titulo' => 'Do recebimento das orientações',
                'texto' => 'Que recebeu, leu e compreendeu integralmente as orientações fornecidas pelo escritório FERREIRA & SÁ ADVOCACIA ESPECIALIZADA sobre como participar da sua audiência por videoconferência, incluindo o passo a passo de acesso à plataforma, as recomendações sobre internet, câmera, microfone, ambiente e documentação exigida.',
            ),
            array(
                'num' => '2',
                'titulo' => 'Da realização dos testes prévios',
                'texto' => 'Que testou previamente o funcionamento da sua câmera, do seu microfone e da sua conexão de internet, utilizando a sala de teste disponibilizada, e que verificou estar apto(a) a participar da audiência remota nas condições atuais do seu dispositivo e da sua rede.',
            ),
            array(
                'num' => '3',
                'titulo' => 'Da responsabilidade técnica',
                'texto' => 'Que assume EXCLUSIVA responsabilidade pela adequação e funcionamento, na data e horário designados da audiência, dos meios técnicos necessários à sua participação, incluindo, mas não se limitando a: (a) qualidade e estabilidade da conexão de internet; (b) funcionamento da câmera e do microfone do seu dispositivo; (c) energia elétrica e/ou carga suficiente da bateria; (d) ambiente adequado (silencioso, iluminado, sem interferências); (e) documento oficial com foto à disposição.',
            ),
            array(
                'num' => '4',
                'titulo' => 'Da ciência das consequências',
                'texto' => 'Que está ciente de que EVENTUAL IMPOSSIBILIDADE DE ACESSO À AUDIÊNCIA por falha técnica dos seus meios de conexão, do seu dispositivo, do seu ambiente ou por qualquer motivo alheio ao controle do escritório poderá acarretar consequências processuais, incluindo, entre outras: (a) o registro da sua ausência; (b) a adoção, pelo(a) magistrado(a), das providências cabíveis nos termos da legislação processual em vigor; (c) eventual prejuízo à defesa dos seus interesses no processo.',
            ),
            array(
                'num' => '5',
                'titulo' => 'Da isenção de responsabilidade do escritório',
                'texto' => 'Que reconhece que o escritório FERREIRA & SÁ ADVOCACIA ESPECIALIZADA cumpriu integralmente seu dever de orientação prévia, ao fornecer o material informativo e disponibilizar a sala de teste, NÃO SENDO RESPONSÁVEL por eventuais falhas técnicas ocorridas na data e horário da audiência que sejam decorrentes dos meios de acesso do(a) participante.',
            ),
            array(
                'num' => '6',
                'titulo' => 'Do compromisso de comunicação',
                'texto' => 'Que se compromete a comunicar o escritório, com a maior antecedência possível, sobre qualquer dificuldade técnica ou impossibilidade prevista de comparecimento à audiência remota, por meio dos canais oficiais de atendimento (WhatsApp, e-mail ou telefone).',
            ),
        ),
        'aceite_final' => 'DECLARO, ainda, que este termo foi por mim lido, compreendido e aceito eletronicamente, no ato do preenchimento do meu nome completo, CPF e marcação dos itens de confirmação, produzindo os mesmos efeitos jurídicos da assinatura física, nos termos da legislação aplicável.',
    );
}
}

/**
 * Cria um novo registro de treinamento pro case/audiência e retorna
 * a URL única pra ser enviada ao cliente.
 *
 * @param PDO $pdo
 * @param int $caseId
 * @param int|null $agendaEventoId  Se vinculado a evento específico da agenda
 * @param int $criadoPor  user_id que criou (admin/adv)
 * @return array{token: string, url: string, id: int}
 */
if (!function_exists('treinamento_audiencia_criar')) {
function treinamento_audiencia_criar(PDO $pdo, $caseId, $agendaEventoId, $criadoPor) {
    $caseId = (int)$caseId;
    $agendaEventoId = $agendaEventoId ? (int)$agendaEventoId : null;
    $criadoPor = (int)$criadoPor;

    // Busca client_id + dados da audiência (se vinculada) pra denormalizar
    $clientId = null;
    $audienciaDataHora = null;
    $audienciaTitulo = null;
    try {
        $st = $pdo->prepare("SELECT client_id FROM cases WHERE id = ?");
        $st->execute(array($caseId));
        $clientId = (int)$st->fetchColumn() ?: null;
    } catch (Exception $e) {}
    if ($agendaEventoId) {
        try {
            $st = $pdo->prepare("SELECT titulo, data_inicio FROM agenda_eventos WHERE id = ?");
            $st->execute(array($agendaEventoId));
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $audienciaTitulo = (string)$row['titulo'];
                $audienciaDataHora = (string)$row['data_inicio'];
            }
        } catch (Exception $e) {}
    }

    // Gera token único de 48 chars hex — colisão praticamente impossível
    $token = bin2hex(random_bytes(24));

    $stmt = $pdo->prepare(
        "INSERT INTO treinamento_audiencia_aceites
         (token, case_id, client_id, agenda_evento_id, audiencia_data_hora,
          audiencia_titulo, criado_por, aceite_termo_versao)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $termo = treinamento_audiencia_termo_texto();
    $stmt->execute(array(
        $token, $caseId, $clientId, $agendaEventoId, $audienciaDataHora,
        $audienciaTitulo, $criadoPor, $termo['versao']
    ));
    $id = (int)$pdo->lastInsertId();

    // URL absoluta usa base do servidor
    $host = isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST']
        ? $_SERVER['HTTP_HOST']
        : 'ferreiraesa.com.br';
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'https';
    $url = $scheme . '://' . $host . '/conecta/publico/treinamento_audiencia.php?t=' . $token;

    return array('id' => $id, 'token' => $token, 'url' => $url);
}
}

/**
 * Valida token e retorna o registro (ou null se inválido/expirado).
 */
if (!function_exists('treinamento_audiencia_buscar_por_token')) {
function treinamento_audiencia_buscar_por_token(PDO $pdo, $token) {
    if (!$token || !preg_match('/^[a-f0-9]{32,64}$/', $token)) return null;
    try {
        $st = $pdo->prepare(
            "SELECT ta.*, c.name AS client_name, c.cpf AS client_cpf,
                    cs.title AS case_title, cs.case_number,
                    u.name AS criado_por_name
             FROM treinamento_audiencia_aceites ta
             LEFT JOIN clients c ON c.id = ta.client_id
             LEFT JOIN cases cs ON cs.id = ta.case_id
             LEFT JOIN users u ON u.id = ta.criado_por
             WHERE ta.token = ?
             LIMIT 1"
        );
        $st->execute(array($token));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Exception $e) { return null; }
}
}

/**
 * Registra o aceite do cliente. Idempotente: se já assinou, retorna
 * o registro existente sem sobrescrever (preserva prova documental
 * do primeiro aceite).
 *
 * @return array{ok: bool, motivo?: string, ja_assinado?: bool}
 */
if (!function_exists('treinamento_audiencia_registrar_aceite')) {
function treinamento_audiencia_registrar_aceite(PDO $pdo, $registroId, $dados) {
    $registroId = (int)$registroId;
    if (!$registroId) return array('ok' => false, 'motivo' => 'id_invalido');

    // Já assinou? Não sobrescreve.
    try {
        $st = $pdo->prepare("SELECT aceite_em FROM treinamento_audiencia_aceites WHERE id = ?");
        $st->execute(array($registroId));
        $atual = $st->fetchColumn();
        if ($atual) return array('ok' => true, 'ja_assinado' => true);
    } catch (Exception $e) {
        return array('ok' => false, 'motivo' => 'erro_busca: ' . $e->getMessage());
    }

    // Sanitiza + valida
    $nome = trim((string)($dados['nome'] ?? ''));
    $cpf = preg_replace('/\D/', '', (string)($dados['cpf'] ?? ''));
    $checks = isset($dados['checks']) && is_array($dados['checks']) ? $dados['checks'] : array();
    if (mb_strlen($nome) < 5) return array('ok' => false, 'motivo' => 'nome_curto');
    if (strlen($cpf) !== 11) return array('ok' => false, 'motivo' => 'cpf_invalido');

    // Todos os checkboxes obrigatórios marcados?
    $obrigatorios = array('leu_cartilha', 'testou_camera', 'testou_mic', 'testou_internet', 'fez_simulacao', 'aceita_termo');
    foreach ($obrigatorios as $k) {
        if (empty($checks[$k])) return array('ok' => false, 'motivo' => 'checkbox_faltando:' . $k);
    }

    $checksJson = json_encode($checks, JSON_UNESCAPED_UNICODE);
    $checksHash = hash('sha256', $checksJson . '|' . $nome . '|' . $cpf);
    $ip = isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0] : ($_SERVER['REMOTE_ADDR'] ?? '');
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 500) : '';

    try {
        $up = $pdo->prepare(
            "UPDATE treinamento_audiencia_aceites SET
                aceite_em = NOW(),
                aceite_nome = ?,
                aceite_cpf = ?,
                aceite_ip = ?,
                aceite_user_agent = ?,
                aceite_checks_json = ?,
                aceite_checks_hash = ?
             WHERE id = ? AND aceite_em IS NULL"
        );
        $up->execute(array($nome, $cpf, $ip, $ua, $checksJson, $checksHash, $registroId));
        if ($up->rowCount() < 1) {
            // Race condition — alguém assinou entre o check e o UPDATE
            return array('ok' => true, 'ja_assinado' => true);
        }
    } catch (Exception $e) {
        return array('ok' => false, 'motivo' => 'erro_gravar: ' . $e->getMessage());
    }

    return array('ok' => true);
}
}

/**
 * Formata CPF pra exibição LGPD-friendly (070.***.**6-78).
 */
if (!function_exists('treinamento_audiencia_cpf_mascarado')) {
function treinamento_audiencia_cpf_mascarado($cpf) {
    $c = preg_replace('/\D/', '', (string)$cpf);
    if (strlen($c) !== 11) return $cpf;
    return substr($c, 0, 3) . '.***.**' . substr($c, 8, 1) . '-' . substr($c, 9, 2);
}
}
