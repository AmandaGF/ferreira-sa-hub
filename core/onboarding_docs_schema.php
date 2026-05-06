<?php
/**
 * Ferreira & Sá — Schema de documentos do Onboarding de colaboradores.
 *
 * Cada documento tem:
 *  - label, icon, descricao curta
 *  - perfis: array de perfis de cargo aos quais o documento se aplica
 *           (estagiario, advogado_associado, clt, sociedade, outro)
 *  - fluxo: como o documento e' preenchido/assinado:
 *      'estagiario_preenche_e_assina' — admin preenche campos proprios + colaborador
 *                                       preenche os pessoais + assina
 *      'so_assina'                    — colaborador so le e assina (sem campos extras)
 *      'admin_marca_e_ambos_assinam'  — admin marca itens (ex: checklist) e os 2 assinam
 *      'admin_preenche_e_ambos_assinam' — admin preenche tudo, ambos assinam
 *  - campos_admin: dados que o admin preenche (modalidade, datas, valores, etc)
 *  - campos_colaborador: dados que o colaborador preenche (RG, endereco, etc)
 *  - assinaturas: lista de quem precisa assinar (estagiario / colaborador / escritorio_amanda
 *                / escritorio_luiz / escritorio_qualquer_socio)
 *  - render_function: nome da funcao PHP que renderiza o HTML do documento
 *  - pasta_drive: padrao da pasta no Drive (suporta {nome_colaborador})
 *  - nome_arquivo: nome final do PDF
 *
 * Os render_function ainda nao foram implementados — serao adicionados conforme
 * a Amanda for confirmando o conteudo final de cada documento.
 */

$ONBOARDING_DOC_SCHEMAS = array(

    // ─────────────────────────────────────────────────────────
    // DOCUMENTOS DO ESTAGIÁRIO
    // ─────────────────────────────────────────────────────────

    'compromisso_estagio' => array(
        'label' => 'Termo de Compromisso de Estágio',
        'icon' => '📋',
        'descricao' => 'Contrato de estágio profissional de advocacia (Lei 8.906/94 + Lei 11.788/2008).',
        'perfis' => array('estagiario'),
        'fluxo' => 'estagiario_preenche_e_assina',
        'campos_admin' => array(
            'modalidade' => array(
                'label' => 'Modalidade do estágio',
                'tipo' => 'select',
                'opcoes' => array(
                    'I'  => 'Modalidade I — Inscrição na OAB-RJ (Provimento 144/2011)',
                    'II' => 'Modalidade II — Estágio acadêmico (Lei 11.788/2008)',
                ),
                'obrigatorio' => true,
            ),
            'data_inicio' => array('label' => 'Data de início', 'tipo' => 'date', 'obrigatorio' => true),
            'data_termino' => array('label' => 'Data de término', 'tipo' => 'date', 'obrigatorio' => true),
            'valor_bolsa' => array('label' => 'Bolsa-auxílio mensal (R$)', 'tipo' => 'money', 'obrigatorio' => true),
            'valor_aux_transporte' => array('label' => 'Auxílio-transporte diário (R$)', 'tipo' => 'money', 'obrigatorio' => true),
            'num_apolice' => array('label' => 'Número da apólice de seguro', 'tipo' => 'text', 'obrigatorio' => false),
            'seguradora' => array('label' => 'Seguradora', 'tipo' => 'text', 'obrigatorio' => false),
        ),
        'campos_colaborador' => array(
            'nacionalidade' => array('label' => 'Nacionalidade', 'tipo' => 'text', 'obrigatorio' => true, 'default' => 'brasileira'),
            'estado_civil' => array(
                'label' => 'Estado civil',
                'tipo' => 'select',
                'opcoes' => array(
                    'solteira'      => 'Solteira(o)',
                    'casada'        => 'Casada(o)',
                    'divorciada'    => 'Divorciada(o)',
                    'viuva'         => 'Viúva(o)',
                    'uniao_estavel' => 'União estável',
                ),
                'obrigatorio' => true,
            ),
            'rg' => array('label' => 'RG (número)', 'tipo' => 'text', 'obrigatorio' => true, 'placeholder' => 'Ex: 12.345.678-9'),
            'rg_orgao_uf' => array('label' => 'Órgão emissor / UF', 'tipo' => 'text', 'obrigatorio' => true, 'placeholder' => 'Ex: SSP/RJ'),
            'cep' => array('label' => 'CEP', 'tipo' => 'cep', 'obrigatorio' => true, 'placeholder' => '00000-000', 'grupo' => 'endereco'),
            'endereco_logradouro' => array('label' => 'Rua / Avenida', 'tipo' => 'text', 'obrigatorio' => true, 'grupo' => 'endereco', 'auto_viacep' => 'logradouro'),
            'endereco_numero' => array('label' => 'Número', 'tipo' => 'text', 'obrigatorio' => true, 'grupo' => 'endereco'),
            'endereco_bairro' => array('label' => 'Bairro', 'tipo' => 'text', 'obrigatorio' => true, 'grupo' => 'endereco', 'auto_viacep' => 'bairro'),
            'endereco_complemento' => array('label' => 'Complemento (opcional)', 'tipo' => 'text', 'obrigatorio' => false, 'grupo' => 'endereco'),
            'endereco_cidade' => array('label' => 'Cidade', 'tipo' => 'text', 'obrigatorio' => true, 'grupo' => 'endereco', 'auto_viacep' => 'localidade'),
            'endereco_uf' => array(
                'label' => 'UF', 'tipo' => 'select', 'obrigatorio' => true, 'grupo' => 'endereco', 'auto_viacep' => 'uf',
                'opcoes' => array('AC'=>'AC','AL'=>'AL','AM'=>'AM','AP'=>'AP','BA'=>'BA','CE'=>'CE','DF'=>'DF','ES'=>'ES','GO'=>'GO','MA'=>'MA','MG'=>'MG','MS'=>'MS','MT'=>'MT','PA'=>'PA','PB'=>'PB','PE'=>'PE','PI'=>'PI','PR'=>'PR','RJ'=>'RJ','RN'=>'RN','RO'=>'RO','RR'=>'RR','RS'=>'RS','SC'=>'SC','SE'=>'SE','SP'=>'SP','TO'=>'TO'),
            ),
            'telefone' => array('label' => 'Telefone (com DDD)', 'tipo' => 'tel', 'obrigatorio' => true, 'placeholder' => '(00) 00000-0000'),
            'instituicao_ensino' => array('label' => 'Nome da instituição de ensino superior', 'tipo' => 'text', 'obrigatorio' => true),
            'semestre' => array('label' => 'Semestre que está cursando', 'tipo' => 'number', 'obrigatorio' => true, 'min' => 1, 'max' => 14),
            'registro_academico' => array('label' => 'Matrícula na faculdade (RA / RGM / DRE)', 'tipo' => 'text', 'obrigatorio' => true, 'placeholder' => 'Número de matrícula'),
            'chave_pix' => array('label' => 'Chave PIX (para receber a bolsa-auxílio)', 'tipo' => 'text', 'obrigatorio' => true),
        ),
        'assinaturas' => array('estagiario', 'escritorio'),
        'render_function' => 'render_termo_compromisso_estagio', // a implementar
        'pasta_drive' => '/Onboarding/{nome_colaborador}/',
        'nome_arquivo' => 'Termo_de_Compromisso_de_Estagio.pdf',
    ),

    'confidencialidade_lgpd' => array(
        'label' => 'Termo de Confidencialidade, Sigilo e LGPD',
        'icon' => '🔒',
        'descricao' => 'Compromisso de sigilo profissional + tratamento de dados pessoais.',
        'perfis' => array('estagiario', 'advogado_associado', 'clt', 'outro'),
        'fluxo' => 'so_assina',
        'campos_admin' => array(),
        'campos_colaborador' => array(),
        'assinaturas' => array('colaborador'),
        'render_function' => 'render_termo_confidencialidade_lgpd', // a implementar
        'pasta_drive' => '/Onboarding/{nome_colaborador}/',
        'nome_arquivo' => 'Termo_de_Confidencialidade_LGPD.pdf',
    ),

    'pop_estagiario' => array(
        'label' => 'POP — Estagiário',
        'icon' => '📘',
        'descricao' => 'Procedimentos Operacionais Padrão para a função de estagiário.',
        'perfis' => array('estagiario'),
        'fluxo' => 'so_assina', // pendente confirmação — Amanda vai mandar o PDF
        'campos_admin' => array(),
        'campos_colaborador' => array(),
        'assinaturas' => array('estagiario'),
        'render_function' => 'render_pop_estagiario', // a implementar (aguardando PDF)
        'pasta_drive' => '/Onboarding/{nome_colaborador}/',
        'nome_arquivo' => 'POP_Estagiario.pdf',
    ),

    'checklist_admissional_estagiario' => array(
        'label' => 'Checklist Admissional',
        'icon' => '✅',
        'descricao' => 'Lista de 29 itens preenchida pelo admin nos 5 primeiros dias úteis.',
        'perfis' => array('estagiario'),
        'fluxo' => 'admin_marca_e_ambos_assinam',
        // O admin marca 29 checkboxes ao longo do tempo. Estrutura abaixo descreve os blocos.
        'campos_admin' => array(
            'checklist_items' => array(
                'label' => 'Itens do checklist (29 itens em 5 blocos)',
                'tipo' => 'checklist',
                'blocos' => array(
                    'pessoal_academica' => array(
                        'label' => 'Bloco 1 — Documentação pessoal e acadêmica',
                        'itens' => array(
                            1 => 'Cópia do RG e CPF do(a) estagiário(a)',
                            2 => 'Comprovante de residência atualizado (até 90 dias)',
                            3 => 'Comprovante de matrícula e histórico escolar atualizados',
                            4 => 'Declaração da instituição de ensino com período/semestre cursado',
                            5 => 'Cópia da carteira de estagiário OAB-RJ (modalidade I) ou termo de opção pela modalidade acadêmica (modalidade II)',
                            6 => 'Dados bancários para pagamento da bolsa-auxílio (chave PIX)',
                        ),
                    ),
                    'contratual' => array(
                        'label' => 'Bloco 2 — Documentação contratual',
                        'itens' => array(
                            8  => 'Termo de Compromisso de Estágio assinado por ambas as partes',
                            9  => 'Termo de Confidencialidade, Sigilo Profissional e LGPD assinado',
                            10 => 'Ciência formal do POP — Estagiário',
                            11 => 'Apólice do seguro contra acidentes pessoais — entrega de cópia ao(à) estagiário(a)',
                            12 => 'Convênio com a instituição de ensino, quando exigido pela IES',
                        ),
                    ),
                    'acessos' => array(
                        'label' => 'Bloco 3 — Acessos a sistemas e recursos',
                        'itens' => array(
                            13 => 'Criação de e-mail institucional (@ferreiraesa.com.br) com configuração inicial',
                            14 => 'Acesso ao F&S Hub (Conecta) com perfil compatível com as atribuições de estagiário',
                            15 => 'Acesso ao F&S Hub (Conecta) — credenciais e treinamento básico',
                            16 => 'Cadastro nos canais internos (WhatsApp Business, grupos institucionais)',
                            17 => 'Configuração de PJe TJRJ, eSAJ, Projudi e PJe-JFRJ no equipamento de trabalho (se aplicável)',
                            18 => 'Instalação de assinatura digital (certificado A1/A3) — quando aplicável à modalidade',
                            19 => 'Configuração do Microsoft Office com identidade visual padrão F&S',
                            20 => 'Entrega de celular/kit, na modalidade de comodato — quando aplicável',
                        ),
                    ),
                    'integracao' => array(
                        'label' => 'Bloco 4 — Integração e treinamento inicial',
                        'itens' => array(
                            21 => 'Apresentação à equipe e tour pelas instalações do escritório (se aplicável)',
                            22 => 'Treinamento operacional do F&S Hub (lançamento de andamentos, prazos, contatos)',
                            23 => 'Treinamento operacional do F&S Hub (Conecta) — Marina, Claudin e demais skills',
                            24 => 'Apresentação dos modelos de petições e da identidade visual F&S (visual law)',
                            25 => 'Apresentação das principais carteiras e clientes em atividade',
                            26 => 'Treinamento sobre comunicação com cliente (frases institucionais, tom F&S)',
                            27 => 'Briefing sobre LGPD, sigilo profissional e protocolos de incidente',
                            28 => 'Definição de supervisor(a) imediato(a) e fluxo de revisão de minutas',
                            29 => 'Agendamento da primeira reunião de feedback (D+30)',
                        ),
                    ),
                ),
            ),
        ),
        'campos_colaborador' => array(),
        'assinaturas' => array('estagiario', 'escritorio_amanda'),
        'render_function' => 'render_checklist_admissional_estagiario', // a implementar
        'pasta_drive' => '/Onboarding/{nome_colaborador}/',
        'nome_arquivo' => 'Checklist_Admissional.pdf',
    ),

    // ─────────────────────────────────────────────────────────
    // DOCUMENTOS DO ADVOGADO ASSOCIADO — placeholders
    // (aguardando PDFs da Amanda)
    // ─────────────────────────────────────────────────────────

    // 'contrato_associacao'      => array( ... ),
    // 'pop_advogado_associado'   => array( ... ),
    // 'checklist_admissional_adv' => array( ... ),

);

/**
 * Retorna os documentos disponíveis para um determinado perfil de cargo.
 *
 * @param string $perfil 'estagiario' | 'advogado_associado' | 'clt' | 'sociedade' | 'outro'
 * @return array<string, array>
 */
function onboarding_docs_por_perfil($perfil) {
    global $ONBOARDING_DOC_SCHEMAS;
    if (!$perfil) return array();
    $out = array();
    foreach ($ONBOARDING_DOC_SCHEMAS as $tipo => $schema) {
        if (in_array($perfil, $schema['perfis'], true)) {
            $out[$tipo] = $schema;
        }
    }
    return $out;
}

/**
 * Retorna o schema de um tipo específico, ou null se não existe.
 */
function onboarding_doc_schema($tipo) {
    global $ONBOARDING_DOC_SCHEMAS;
    return isset($ONBOARDING_DOC_SCHEMAS[$tipo]) ? $ONBOARDING_DOC_SCHEMAS[$tipo] : null;
}

/**
 * Lista os perfis de cargo disponíveis (label amigável).
 */
function onboarding_perfis_cargo() {
    return array(
        'estagiario'         => 'Estagiária(o)',
        'advogado_associado' => 'Advogada(o) Associada(o)',
        'clt'                => 'CLT',
        'sociedade'          => 'Sociedade',
        'outro'              => 'Outro',
    );
}
