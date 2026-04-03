<?php
/**
 * Ferreira & Sá Conecta — Regras de Negócio dos Casos
 *
 * Criação de clientes, checklist automático por tipo de ação,
 * geração de tarefas para processos jurídicos.
 */

// ─── Partes do Processo ────────────────────────────────

/**
 * Busca todas as partes de um caso, organizadas por papel.
 * Retorna array com chaves: autores, reus, representantes, terceiros, todas
 */
function buscar_partes_caso(int $caseId): array
{
    $result = array('autores' => array(), 'reus' => array(), 'representantes' => array(), 'terceiros' => array(), 'todas' => array());
    try {
        $stmt = db()->prepare(
            "SELECT p.*, rep.nome as representa_nome
             FROM case_partes p
             LEFT JOIN case_partes rep ON rep.id = p.representa_parte_id
             WHERE p.case_id = ?
             ORDER BY FIELD(p.papel,'autor','reu','representante_legal','terceiro_interessado','litisconsorte_ativo','litisconsorte_passivo'), p.id"
        );
        $stmt->execute(array($caseId));
        $all = $stmt->fetchAll();
        $result['todas'] = $all;
        foreach ($all as $p) {
            if ($p['papel'] === 'autor' || $p['papel'] === 'litisconsorte_ativo') $result['autores'][] = $p;
            elseif ($p['papel'] === 'reu' || $p['papel'] === 'litisconsorte_passivo') $result['reus'][] = $p;
            elseif ($p['papel'] === 'representante_legal') $result['representantes'][] = $p;
            else $result['terceiros'][] = $p;
        }
    } catch (Exception $e) {}
    return $result;
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

    // 4. Não encontrou — criar novo (com proteção contra duplicatas)
    if (!$name) return 0;

    try {
        $pdo->prepare(
            "INSERT INTO clients (name, phone, email, cpf, source, client_status, created_at) VALUES (?,?,?,?,'outro','ativo',NOW())"
        )->execute(array($name, $phone ?: null, $email ?: null, isset($data['cpf']) ? $data['cpf'] : null));
        return (int)$pdo->lastInsertId();
    } catch (Exception $e) {
        // Se deu erro de duplicata (race condition), buscar o que já existe
        if ($phone) {
            $phoneClean = preg_replace('/\D/', '', $phone);
            $stmt = $pdo->prepare("SELECT id FROM clients WHERE REPLACE(REPLACE(phone,' ',''),'-','') LIKE ? LIMIT 1");
            $stmt->execute(array('%' . substr($phoneClean, -8) . '%'));
            $row = $stmt->fetch();
            if ($row) return (int)$row['id'];
        }
        if ($name) {
            $stmt = $pdo->prepare("SELECT id FROM clients WHERE name = ? LIMIT 1");
            $stmt->execute(array($name));
            $row = $stmt->fetch();
            if ($row) return (int)$row['id'];
        }
        return 0;
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
