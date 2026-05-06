<?php
/**
 * Templates de renderização dos documentos do onboarding.
 *
 * Cada função render_* recebe:
 *   $colaborador  — array com dados do colaborador (colaboradores_onboarding)
 *   $dadosAdmin   — array decodificado de dados_admin_json
 *   $dadosColab   — array decodificado de dados_estagiario_json
 *   $assinaturas  — array com assinatura_estagiario_em, assinatura_estagiario_nome, etc
 *
 * Retorna HTML completo do documento (sem <html>/<body> — só conteúdo).
 *
 * Visual law inspirado nos PDFs originais do escritório:
 *   - Banner topo com título centralizado em caixa cobre/petrol
 *   - Subseções em barra cinza com border-left grosso
 *   - Cláusulas numeradas em quadrado preto com label branco
 *   - Texto justificado, parágrafos com margin
 */

// Helper interno: converte número para extenso simples (R$)
function _onb_extenso_real($valor) {
    if ($valor === null || $valor === '') return '';
    $v = (float)$valor;
    if ($v <= 0) return '';
    // Versão simplificada — só pega centenas
    $unidades = array('', 'um', 'dois', 'três', 'quatro', 'cinco', 'seis', 'sete', 'oito', 'nove', 'dez',
        'onze', 'doze', 'treze', 'quatorze', 'quinze', 'dezesseis', 'dezessete', 'dezoito', 'dezenove');
    $dezenas = array('', '', 'vinte', 'trinta', 'quarenta', 'cinquenta', 'sessenta', 'setenta', 'oitenta', 'noventa');
    $centenas = array('', 'cento', 'duzentos', 'trezentos', 'quatrocentos', 'quinhentos', 'seiscentos', 'setecentos', 'oitocentos', 'novecentos');

    $extensoTresDigitos = function($n) use ($unidades, $dezenas, $centenas) {
        $n = (int)$n;
        if ($n === 0) return '';
        if ($n === 100) return 'cem';
        $c = (int)($n / 100); $n %= 100;
        $resto = '';
        if ($n < 20) {
            $resto = $unidades[$n];
        } else {
            $d = (int)($n / 10); $u = $n % 10;
            $resto = $dezenas[$d] . ($u > 0 ? ' e ' . $unidades[$u] : '');
        }
        return trim(($c > 0 ? $centenas[$c] : '') . ($c > 0 && $resto ? ' e ' : '') . $resto);
    };

    $reais = (int)$v;
    $cents = (int)round(($v - $reais) * 100);
    $partes = array();

    if ($reais >= 1000) {
        $milhares = (int)($reais / 1000);
        $resto = $reais % 1000;
        $partes[] = $extensoTresDigitos($milhares) . ' mil';
        if ($resto > 0) $partes[] = $extensoTresDigitos($resto);
    } else {
        $partes[] = $extensoTresDigitos($reais);
    }

    $txt = implode(' e ', array_filter($partes)) . ' real' . ($reais > 1 ? 'es' : '');
    if ($cents > 0) {
        $txt .= ' e ' . $extensoTresDigitos($cents) . ' centavo' . ($cents > 1 ? 's' : '');
    }
    return $txt;
}

// Helper interno: formata data BR
function _onb_data_br($iso) {
    if (!$iso) return '';
    $dt = DateTime::createFromFormat('Y-m-d', $iso);
    return $dt ? $dt->format('d/m/Y') : $iso;
}
function _onb_data_br_extenso($iso) {
    if (!$iso) return '';
    $dt = DateTime::createFromFormat('Y-m-d', $iso);
    if (!$dt) return $iso;
    $meses = array('janeiro','fevereiro','março','abril','maio','junho','julho','agosto','setembro','outubro','novembro','dezembro');
    return $dt->format('d') . ' de ' . $meses[(int)$dt->format('n') - 1] . ' de ' . $dt->format('Y');
}

// Helper interno: rodapé padrão F&S
function _onb_footer_html() {
    return '<div class="doc-footer">'
         . '<div>📍 Rio de Janeiro / RJ &nbsp;&nbsp; Barra Mansa / RJ &nbsp;&nbsp; Volta Redonda / RJ &nbsp;&nbsp; Resende / RJ &nbsp;&nbsp; São Paulo / SP</div>'
         . '<div>(24) 9.9205-0096 / (11) 2110-5438</div>'
         . '<div>🌐 www.ferreiraesa.com.br &nbsp;&nbsp; ✉ contato@ferreiraesa.com.br</div>'
         . '</div>';
}

// CSS inline padrão dos documentos do onboarding (visual law dos PDFs)
function onboarding_docs_css() {
    return '<style>
        .doc-page { font-family:"Open Sans",Arial,sans-serif; font-size:11pt; color:#1a1a1a; line-height:1.55; max-width:780px; margin:0 auto; padding:30px 40px; background:#fff; }
        .doc-logo { text-align:center; margin-bottom:1rem; }
        .doc-logo img { max-height:60px; }
        .doc-title-banner { background:#fff7ed; border-top:3px solid #d7ab90; border-bottom:3px solid #d7ab90; padding:18px 22px; text-align:center; margin:1rem 0 1.6rem; }
        .doc-title-banner h1 { font-size:13pt; letter-spacing:.18em; color:#052228; font-weight:700; margin:0; line-height:1.4; text-transform:uppercase; font-family:"Open Sans",sans-serif; }
        .doc-section-bar { background:#f3f4f6; border-left:5px solid #052228; padding:.55rem .9rem; margin:1.4rem 0 .8rem; font-weight:700; font-size:11pt; color:#052228; letter-spacing:.05em; text-transform:uppercase; }
        .doc-subsection-bar { background:#fafafa; border-left:4px solid #d7ab90; padding:.4rem .85rem; margin:1rem 0 .6rem; font-weight:700; font-size:10.5pt; color:#052228; letter-spacing:.04em; text-transform:uppercase; }
        .doc-clausula { display:flex; gap:.85rem; margin:.85rem 0; align-items:flex-start; }
        .doc-clausula-num { background:#052228; color:#fff; min-width:42px; height:42px; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:10pt; border-radius:4px; flex-shrink:0; }
        .doc-clausula-corpo { flex:1; text-align:justify; padding-top:.4rem; }
        .doc-clausula-corpo ul { margin:.45rem 0 0 1.2rem; padding:0; }
        .doc-clausula-corpo li { margin-bottom:.3rem; }
        .doc-clausula-titulo-h { font-weight:700; background:#f3f4f6; border-left:4px solid #d7ab90; padding:.45rem .85rem; margin:1.3rem 0 .6rem; font-size:10.5pt; color:#052228; }
        .doc-data-local { margin:2rem 0 .5rem; text-align:center; font-size:11pt; }
        .doc-assinatura { margin-top:2.5rem; text-align:center; }
        .doc-assinatura-linha { border-top:1px solid #1a1a1a; width:60%; margin:0 auto .35rem; padding-top:0; }
        .doc-assinatura-nome { font-weight:700; font-size:10.5pt; }
        .doc-assinatura-sub { font-size:9pt; color:#444; margin-top:2px; }
        .doc-assinatura-eletronica { background:#ecfdf5; border:1px dashed #10b981; padding:.5rem .8rem; border-radius:6px; font-size:9pt; color:#065f46; max-width:480px; margin:.35rem auto 0; text-align:left; }
        .doc-assinatura-eletronica strong { color:#047857; }
        .doc-footer { margin-top:2.5rem; padding-top:.8rem; border-top:1px solid #d7ab90; font-size:8.5pt; color:#6b7280; text-align:center; line-height:1.6; }
        .doc-page p { text-align:justify; margin:.5rem 0; }
        .doc-page strong { color:#052228; }
        @media print {
            body { margin:0; padding:0; background:#fff; }
            .doc-page { padding:20px 30px; max-width:none; }
            .no-print { display:none !important; }
        }
    </style>';
}

/**
 * Helper interno: monta o cabeçalho com logo + título centralizado em banner.
 */
function _onb_header($titulo, $subtitulo = '') {
    $logoUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
             . '://' . $_SERVER['HTTP_HOST'] . '/conecta/assets/img/logo.png';
    $html = '<div class="doc-logo"><img src="' . htmlspecialchars($logoUrl) . '" alt="Ferreira & Sá"></div>';
    $html .= '<div class="doc-title-banner"><h1>' . $titulo;
    if ($subtitulo) $html .= '<br><span style="font-size:.85em;letter-spacing:.12em;">' . $subtitulo . '</span>';
    $html .= '</h1></div>';
    return $html;
}

/**
 * Helper interno: renderiza assinatura eletrônica registrada.
 */
function _onb_assinatura_eletronica($nome, $assinaturaEm, $ip = '') {
    if (!$assinaturaEm) {
        return '<div class="doc-assinatura"><div class="doc-assinatura-linha"></div>'
             . '<div class="doc-assinatura-nome">' . htmlspecialchars($nome) . '</div>'
             . '</div>';
    }
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $assinaturaEm);
    $dataFmt = $dt ? $dt->format('d/m/Y \à\s H:i') : $assinaturaEm;
    return '<div class="doc-assinatura">'
         . '<div class="doc-assinatura-linha"></div>'
         . '<div class="doc-assinatura-nome">' . htmlspecialchars($nome) . '</div>'
         . '<div class="doc-assinatura-eletronica">'
         . '✓ <strong>Assinatura eletrônica registrada</strong> em ' . htmlspecialchars($dataFmt)
         . ($ip ? ' &middot; IP ' . htmlspecialchars($ip) : '')
         . '</div>'
         . '</div>';
}

// ═══════════════════════════════════════════════════════════
// 1) TERMO DE COMPROMISSO DE ESTÁGIO
// ═══════════════════════════════════════════════════════════
function render_termo_compromisso_estagio($colaborador, $dadosAdmin, $dadosColab, $assinaturas = array()) {
    $nome  = strtoupper($colaborador['nome_completo'] ?? '');
    $cpf   = $colaborador['cpf'] ?? '___.___.___-__';
    $email = $colaborador['email_institucional'] ?? '___';
    $dataNasc = _onb_data_br($colaborador['data_nascimento'] ?? '');

    $nacionalidade = $dadosColab['nacionalidade'] ?? '___';
    $estadoCivil   = $dadosColab['estado_civil'] ?? '___';
    $rg            = $dadosColab['rg'] ?? '___';
    $rgOrgao       = $dadosColab['rg_orgao_uf'] ?? '___';
    // Monta endereco a partir dos campos separados (ou usa o legado endereco_completo).
    if (!empty($dadosColab['endereco_logradouro']) || !empty($dadosColab['cep'])) {
        $partes = array();
        if (!empty($dadosColab['endereco_logradouro'])) {
            $rua = $dadosColab['endereco_logradouro'];
            if (!empty($dadosColab['endereco_numero'])) $rua .= ', n° ' . $dadosColab['endereco_numero'];
            $partes[] = $rua;
        }
        if (!empty($dadosColab['endereco_complemento'])) $partes[] = $dadosColab['endereco_complemento'];
        if (!empty($dadosColab['endereco_bairro'])) $partes[] = $dadosColab['endereco_bairro'];
        if (!empty($dadosColab['endereco_cidade']) && !empty($dadosColab['endereco_uf'])) {
            $partes[] = $dadosColab['endereco_cidade'] . '/' . $dadosColab['endereco_uf'];
        } elseif (!empty($dadosColab['endereco_cidade'])) {
            $partes[] = $dadosColab['endereco_cidade'];
        }
        if (!empty($dadosColab['cep'])) $partes[] = 'CEP ' . $dadosColab['cep'];
        $endereco = implode(', ', $partes);
    } else {
        $endereco = $dadosColab['endereco_completo'] ?? '___';
    }
    $telefone      = $dadosColab['telefone'] ?? '___';
    $instituicao   = $dadosColab['instituicao_ensino'] ?? '___';
    $semestre      = $dadosColab['semestre'] ?? '___';
    $ra            = $dadosColab['registro_academico'] ?? '___';
    $chavePix      = $dadosColab['chave_pix'] ?? '___';

    $modalidade    = $dadosAdmin['modalidade'] ?? '';
    $modalidadeRom = $modalidade === 'I' ? 'I' : ($modalidade === 'II' ? 'II' : '(I) ou (II)');
    $dataInicio    = _onb_data_br($dadosAdmin['data_inicio'] ?? '');
    $dataTermino   = _onb_data_br($dadosAdmin['data_termino'] ?? '');
    $valorBolsa    = $dadosAdmin['valor_bolsa'] ?? null;
    $valorBolsaFmt = $valorBolsa !== null ? 'R$ ' . number_format((float)$valorBolsa, 2, ',', '.') : 'R$ ___';
    $valorBolsaExt = _onb_extenso_real($valorBolsa);
    $valorTransp   = $dadosAdmin['valor_aux_transporte'] ?? null;
    $valorTranspFmt= $valorTransp !== null ? 'R$ ' . number_format((float)$valorTransp, 2, ',', '.') : 'R$ ___';
    $valorTranspExt= _onb_extenso_real($valorTransp);
    $numApolice    = $dadosAdmin['num_apolice'] ?? '___';
    $seguradora    = $dadosAdmin['seguradora'] ?? '___';

    // Jornada de estagio: carga horaria + dias + horarios — vem do cadastro do colaborador
    $cargaH = $colaborador['carga_horaria_estagio'] ?? '';  // ex: '6h'
    $cargaMap = array(
        '4h' => array('hSem' => '20 (vinte)',     'hDia' => '4 (quatro)'),
        '5h' => array('hSem' => '25 (vinte e cinco)', 'hDia' => '5 (cinco)'),
        '6h' => array('hSem' => '30 (trinta)',    'hDia' => '6 (seis)'),
        '7h' => array('hSem' => '35 (trinta e cinco)', 'hDia' => '7 (sete)'),
        '8h' => array('hSem' => '40 (quarenta)',  'hDia' => '8 (oito)'),
    );
    $cargaTxt = isset($cargaMap[$cargaH])
        ? $cargaMap[$cargaH]['hSem'] . ' horas semanais, distribuídas em ' . $cargaMap[$cargaH]['hDia'] . ' horas diárias'
        : '___ horas semanais, distribuídas em ___ horas diárias';
    $diasTrab = $colaborador['dias_trabalho'] ?? '';
    $diasTxt = $diasTrab !== '' ? mb_strtolower($diasTrab, 'UTF-8') : 'em dias a serem ajustados pelas partes';
    $horaIni = $colaborador['horario_inicio'] ? substr($colaborador['horario_inicio'], 0, 5) : '';
    $horaFim = $colaborador['horario_fim'] ? substr($colaborador['horario_fim'], 0, 5) : '';
    $horarioTxt = ($horaIni && $horaFim)
        ? 'no horário das <strong>' . htmlspecialchars($horaIni) . '</strong> às <strong>' . htmlspecialchars($horaFim) . '</strong>'
        : 'em horário a ser ajustado pelas partes';
    // Local de prestacao (sede / filial / VR / outro) — vem do cadastro
    $localPrest = trim((string)($colaborador['local_presencial'] ?? ''));
    $modalidadeTrab = trim((string)($colaborador['modalidade'] ?? ''));  // Presencial/Remoto/Hibrido
    $localTxt = '';
    if ($localPrest !== '') {
        $localTxt = 'O estágio será prestado, presencialmente, no escritório localizado em <strong>' . htmlspecialchars($localPrest) . '</strong>';
        if ($modalidadeTrab !== '' && stripos($modalidadeTrab, 'híb') !== false) {
            $localTxt .= ', em regime <strong>híbrido</strong>, com possibilidade de execução remota mediante prévio ajuste';
        } elseif ($modalidadeTrab !== '' && stripos($modalidadeTrab, 'remot') !== false) {
            $localTxt = 'O estágio será prestado em regime <strong>remoto</strong>, com sede de referência em <strong>' . htmlspecialchars($localPrest) . '</strong> para reuniões e atividades presenciais quando necessário';
        }
        $localTxt .= '.';
    } elseif ($modalidadeTrab !== '' && stripos($modalidadeTrab, 'remot') !== false) {
        $localTxt = 'O estágio será prestado em regime <strong>remoto</strong>.';
    }

    $estadoCivilLabels = array('solteira'=>'solteira(o)','casada'=>'casada(o)','divorciada'=>'divorciada(o)','viuva'=>'viúva(o)','uniao_estavel'=>'em união estável');
    $estadoCivilTxt = isset($estadoCivilLabels[$estadoCivil]) ? $estadoCivilLabels[$estadoCivil] : $estadoCivil;

    $h = '<div class="doc-page">';
    $h .= _onb_header('Termo de Compromisso de Estágio', 'Profissional de Advocacia');

    $h .= '<p>Pelo presente instrumento particular, as partes a seguir qualificadas celebram o presente <strong>TERMO DE COMPROMISSO DE ESTÁGIO PROFISSIONAL DE ADVOCACIA</strong>, regido pela Lei 8.906/94 (Estatuto da Advocacia e da OAB), pelo Provimento 144/2011 do Conselho Federal da OAB, pela Lei 11.788/2008 (Lei do Estágio), naquilo em que aplicável, e pelas cláusulas e condições a seguir estabelecidas.</p>';

    $h .= '<div class="doc-section-bar">Da Qualificação das Partes</div>';

    $h .= '<div class="doc-subsection-bar">CONCEDENTE</div>';
    $h .= '<p><strong>FERREIRA &amp; SÁ ADVOCACIA ESPECIALIZADA</strong>, sociedade individual de advocacia, inscrita no CNPJ sob o n. 51.294.223/0001-40, registrada na OAB-RJ sob o n. 005.987/2023, com sede na Rua Dr. Aldrovando de Oliveira, n. 140, Ano Bom, Barra Mansa/RJ, neste ato representada por seus sócios-administradores <strong>AMANDA GUEDES FERREIRA</strong>, advogada inscrita na OAB-RJ sob o n. 163.260, e <strong>LUIZ EDUARDO DE SÁ SILVA MARCELINO</strong>, advogado inscrito na OAB-RJ sob o n. 248.755, doravante denominada simplesmente <strong>CONCEDENTE</strong>.</p>';

    $h .= '<div class="doc-subsection-bar">ESTAGIÁRIO(A)</div>';
    $h .= '<p><strong>' . htmlspecialchars($nome) . '</strong>, ' . htmlspecialchars($nacionalidade) . ', ' . htmlspecialchars($estadoCivilTxt) . ', inscrito(a) no CPF sob o n. <strong>' . htmlspecialchars($cpf) . '</strong>, portador(a) do RG n. <strong>' . htmlspecialchars($rg) . ' — ' . htmlspecialchars($rgOrgao) . '</strong>, nascido(a) em <strong>' . htmlspecialchars($dataNasc) . '</strong>, residente e domiciliado(a) na ' . htmlspecialchars($endereco) . ', e-mail ' . htmlspecialchars($email) . ', telefone ' . htmlspecialchars($telefone) . ', regularmente matriculado(a) no curso de Direito da <strong>' . htmlspecialchars($instituicao) . '</strong>, cursando o <strong>' . htmlspecialchars($semestre) . 'º semestre</strong>, sob o registro acadêmico n. <strong>' . htmlspecialchars($ra) . '</strong>, doravante denominado(a) simplesmente <strong>ESTAGIÁRIO(A)</strong>.</p>';

    $h .= '<div class="doc-section-bar">Das Cláusulas e Condições</div>';

    // 1. OBJETO
    $h .= '<div class="doc-clausula-titulo-h">CLÁUSULA 1ª — DO OBJETO</div>';
    $h .= '<div class="doc-clausula"><div class="doc-clausula-num">1.1</div><div class="doc-clausula-corpo">O presente instrumento tem por objeto regular as condições de realização do <strong>estágio profissional de advocacia</strong> do(a) ESTAGIÁRIO(A) junto à CONCEDENTE, sob a supervisão direta de advogado(a) regularmente inscrito(a) na OAB-RJ.</div></div>';
    $h .= '<div class="doc-clausula"><div class="doc-clausula-num">1.2</div><div class="doc-clausula-corpo">O estágio será realizado, alternativamente, sob uma das seguintes modalidades, conforme o caso:<ul>'
        . '<li><strong>Estágio profissional de advocacia inscrito na OAB-RJ</strong>, nos moldes do art. 9º, §1º, II, da Lei 8.906/94 e do Provimento 144/2011 do CFOAB, hipótese em que o(a) ESTAGIÁRIO(A) compromete-se a requerer e manter ativa sua inscrição na seccional como estagiário, autorizando-o à prática dos atos previstos no art. 1º, §2º, do EAOAB e no art. 29 do Regulamento Geral, sempre em conjunto com advogado(a) supervisor(a); ou</li>'
        . '<li><strong>Estágio acadêmico curricular ou extracurricular</strong>, nos moldes da Lei 11.788/2008, hipótese em que o(a) ESTAGIÁRIO(A) atuará exclusivamente em atividades de aprendizagem prática, sem subscrição de petições nem prática isolada de atos privativos da advocacia, vedando-se a representação direta de clientes em juízo.</li>'
        . '</ul></div></div>';
    $h .= '<div class="doc-clausula"><div class="doc-clausula-num">1.3</div><div class="doc-clausula-corpo">A modalidade adotada pelas partes na presente contratação é a de número <strong>(' . htmlspecialchars($modalidadeRom) . ')</strong>, podendo ser alterada por aditivo escrito mediante consenso das partes.</div></div>';

    // 2. NATUREZA JURÍDICA
    $h .= '<div class="doc-clausula-titulo-h">CLÁUSULA 2ª — DA NATUREZA JURÍDICA E DA AUSÊNCIA DE VÍNCULO EMPREGATÍCIO</div>';
    $h .= '<div class="doc-clausula"><div class="doc-clausula-num">2.1</div><div class="doc-clausula-corpo">O presente estágio não cria vínculo empregatício de qualquer natureza entre as partes, nos termos do art. 3º da Lei 11.788/2008 e do art. 9º, §3º, da Lei 8.906/94, ficando a CONCEDENTE isenta do pagamento de quaisquer verbas trabalhistas ou previdenciárias.</div></div>';

    // 3. VIGÊNCIA
    $h .= '<div class="doc-clausula-titulo-h">CLÁUSULA 3ª — DA VIGÊNCIA</div>';
    $h .= '<div class="doc-clausula"><div class="doc-clausula-num">3.1</div><div class="doc-clausula-corpo">O presente termo terá vigência de 12 (doze) meses, com início em <strong>' . htmlspecialchars($dataInicio) . '</strong> e término em <strong>' . htmlspecialchars($dataTermino) . '</strong>, podendo ser prorrogado mediante aditivo escrito, observado o limite máximo de 2 (dois) anos previsto no art. 11 da Lei 11.788/2008, salvo na hipótese de pessoa com deficiência.</div></div>';
    $h .= '<div class="doc-clausula"><div class="doc-clausula-num">3.2</div><div class="doc-clausula-corpo">O presente instrumento poderá ser denunciado a qualquer tempo, unilateralmente, por qualquer das partes, mediante comunicação escrita com antecedência mínima de 30 (trinta) dias, sem ônus para a parte denunciante, ressalvada a hipótese de descumprimento contratual, que autoriza rescisão imediata.</div></div>';

    // 4. JORNADA
    $h .= '<div class="doc-clausula-titulo-h">CLÁUSULA 4ª — DA JORNADA E DO LOCAL DE ESTÁGIO</div>';
    $h .= '<div class="doc-clausula"><div class="doc-clausula-num">4.1</div><div class="doc-clausula-corpo">A jornada de estágio será de <strong>' . $cargaTxt . '</strong>, ' . htmlspecialchars($diasTxt) . ', ' . $horarioTxt . ', em compatibilidade com a grade acadêmica do(a) ESTAGIÁRIO(A), nos termos do art. 10, II, da Lei 11.788/2008.</div></div>';
    if ($localTxt !== '') {
        $h .= '<div class="doc-clausula"><div class="doc-clausula-num">4.2</div><div class="doc-clausula-corpo">' . $localTxt . '</div></div>';
        $h .= '<div class="doc-clausula"><div class="doc-clausula-num">4.3</div><div class="doc-clausula-corpo">Nos períodos de avaliações periódicas e finais, a jornada poderá ser reduzida, nos termos do art. 10, §2º, da Lei 11.788/2008, mediante apresentação de calendário acadêmico oficial pelo(a) ESTAGIÁRIO(A) com antecedência mínima de 15 (quinze) dias.</div></div>';
        $h .= '<div class="doc-clausula"><div class="doc-clausula-num">4.4</div><div class="doc-clausula-corpo">Eventual realização de atividades em horário diverso do contratado, inclusive em finais de semana ou feriados, dependerá de ajuste prévio e expresso entre as partes, sendo facultativa para o(a) ESTAGIÁRIO(A).</div></div>';
    } else {
        $h .= '<div class="doc-clausula"><div class="doc-clausula-num">4.2</div><div class="doc-clausula-corpo">Nos períodos de avaliações periódicas e finais, a jornada poderá ser reduzida, nos termos do art. 10, §2º, da Lei 11.788/2008, mediante apresentação de calendário acadêmico oficial pelo(a) ESTAGIÁRIO(A) com antecedência mínima de 15 (quinze) dias.</div></div>';
        $h .= '<div class="doc-clausula"><div class="doc-clausula-num">4.3</div><div class="doc-clausula-corpo">Eventual realização de atividades em horário diverso do contratado, inclusive em finais de semana ou feriados, dependerá de ajuste prévio e expresso entre as partes, sendo facultativa para o(a) ESTAGIÁRIO(A).</div></div>';
    }

    // 5. BOLSA
    $h .= '<div class="doc-clausula-titulo-h">CLÁUSULA 5ª — DA BOLSA-AUXÍLIO E DO AUXÍLIO-TRANSPORTE</div>';
    $h .= '<div class="doc-clausula"><div class="doc-clausula-num">5.1</div><div class="doc-clausula-corpo">A CONCEDENTE pagará ao(à) ESTAGIÁRIO(A) bolsa-auxílio mensal no valor de <strong>' . htmlspecialchars($valorBolsaFmt) . '</strong>' . ($valorBolsaExt ? ' (' . htmlspecialchars($valorBolsaExt) . ')' : '') . ', mediante transferência bancária via PIX para a chave <strong>' . htmlspecialchars($chavePix) . '</strong>, até o 5º (quinto) dia útil do mês subsequente ao da prestação das atividades.</div></div>';
    $h .= '<div class="doc-clausula"><div class="doc-clausula-num">5.2</div><div class="doc-clausula-corpo">Adicionalmente, a CONCEDENTE concederá auxílio-transporte no valor diário de <strong>' . htmlspecialchars($valorTranspFmt) . '</strong>' . ($valorTranspExt ? ' (' . htmlspecialchars($valorTranspExt) . ')' : '') . ', pago em conjunto com a bolsa-auxílio, observadas eventuais alterações no valor das passagens do transporte público local.</div></div>';

    // 6. RECESSO
    $h .= '<div class="doc-clausula-titulo-h">CLÁUSULA 6ª — DO RECESSO REMUNERADO</div>';
    $h .= '<div class="doc-clausula"><div class="doc-clausula-num">6.1</div><div class="doc-clausula-corpo">A cada 12 (doze) meses de estágio, será concedido ao(à) ESTAGIÁRIO(A) recesso remunerado de 30 (trinta) dias, a ser usufruído preferencialmente nos períodos de férias da faculdade, nos termos do art. 13 da Lei 11.788/2008. Em caso de estágio com duração inferior, o recesso será concedido proporcionalmente.</div></div>';

    // 7. SEGURO
    $h .= '<div class="doc-clausula-titulo-h">CLÁUSULA 7ª — DO SEGURO CONTRA ACIDENTES PESSOAIS</div>';
    $h .= '<div class="doc-clausula"><div class="doc-clausula-num">7.1</div><div class="doc-clausula-corpo">A CONCEDENTE contratará, em favor do(a) ESTAGIÁRIO(A), seguro contra acidentes pessoais, conforme exige o art. 9º, IV, da Lei 11.788/2008, mediante apólice n. <strong>' . htmlspecialchars($numApolice) . '</strong> da seguradora <strong>' . htmlspecialchars($seguradora) . '</strong>, cuja vigência coincidirá com a deste termo. O comprovante da apólice será entregue ao(à) ESTAGIÁRIO(A) em até 30 (trinta) dias.</div></div>';

    // 8. ATIVIDADES
    $h .= '<div class="doc-clausula-titulo-h">CLÁUSULA 8ª — DAS ATIVIDADES DE ESTÁGIO</div>';
    $h .= '<div class="doc-clausula"><div class="doc-clausula-num">8.1</div><div class="doc-clausula-corpo">O(a) ESTAGIÁRIO(A) auxiliará os advogados da CONCEDENTE no desempenho de atividades compatíveis com a formação jurídica, abrangendo, entre outras:<ul>'
        . '<li>pesquisa de legislação, doutrina e jurisprudência;</li>'
        . '<li>elaboração de minutas de petições simples e, sob supervisão, de petições complexas;</li>'
        . '<li>acompanhamento processual nos sistemas eletrônicos do Poder Judiciário (PJe, eSAJ, Projudi e outros);</li>'
        . '<li>redação de contratos, notificações, pareceres e estudos de caso;</li>'
        . '<li>organização e revisão documental de autos físicos e eletrônicos;</li>'
        . '<li>comparecimento a audiências, sessões e diligências externas, sempre acompanhando advogado(a) supervisor(a);</li>'
        . '<li>atendimento a clientes, sob supervisão, presencial, telefônico ou por meios eletrônicos;</li>'
        . '<li>atendimentos de leads com orientações e fechamentos de contratos;</li>'
        . '<li>participação em reuniões internas, treinamentos, palestras e atividades de capacitação.</li>'
        . '</ul></div></div>';
    $h .= '<div class="doc-clausula"><div class="doc-clausula-num">8.2</div><div class="doc-clausula-corpo">As atividades poderão ser ajustadas, ampliadas ou substituídas conforme a progressividade do aprendizado e a compatibilidade com o currículo escolar do(a) ESTAGIÁRIO(A), preservada a finalidade pedagógica do estágio.</div></div>';
    $h .= '<div class="doc-clausula"><div class="doc-clausula-num">8.3</div><div class="doc-clausula-corpo">Quando o estágio for realizado sob a modalidade prevista no item 1.2(I) — inscrição na OAB —, a prática de atos privativos de advocacia somente poderá ocorrer em conjunto com advogado(a) supervisor(a) regularmente inscrito(a), sendo vedado ao(à) ESTAGIÁRIO(A) a postulação isolada em juízo, salvo nas hipóteses excepcionais previstas no art. 29 do Regulamento Geral do EAOAB.</div></div>';

    // 9. OBRIGAÇÕES CONCEDENTE
    $h .= '<div class="doc-clausula-titulo-h">CLÁUSULA 9ª — DAS OBRIGAÇÕES DA CONCEDENTE</div>';
    $h .= '<div class="doc-clausula"><div class="doc-clausula-num">9.1</div><div class="doc-clausula-corpo">Compete à CONCEDENTE:<ul>'
        . '<li>proporcionar atividades de aprendizagem profissional compatíveis com o curso de Direito;</li>'
        . '<li>designar advogado(a) supervisor(a), regularmente inscrito(a) na OAB, para acompanhamento direto do(a) ESTAGIÁRIO(A);</li>'
        . '<li>efetuar o pagamento da bolsa-auxílio e do auxílio-transporte nos termos da Cláusula 5ª;</li>'
        . '<li>contratar e manter vigente o seguro contra acidentes pessoais previsto na Cláusula 7ª;</li>'
        . '<li>fornecer ao(à) ESTAGIÁRIO(A), ao término do estágio, certificado e termo de realização, com indicação resumida das atividades desenvolvidas e do período;</li>'
        . '<li>observar a jornada e o recesso remunerado, sem exigir atividades incompatíveis com a finalidade pedagógica;</li>'
        . '<li>preservar o sigilo dos dados pessoais do(a) ESTAGIÁRIO(A), nos termos da Lei 13.709/2018 (LGPD).</li>'
        . '</ul></div></div>';

    // 10. OBRIGAÇÕES ESTAGIÁRIO
    $h .= '<div class="doc-clausula-titulo-h">CLÁUSULA 10ª — DAS OBRIGAÇÕES DO(A) ESTAGIÁRIO(A)</div>';
    $h .= '<div class="doc-clausula"><div class="doc-clausula-num">10.1</div><div class="doc-clausula-corpo">Compete ao(à) ESTAGIÁRIO(A):<ul>'
        . '<li>cumprir, com diligência e zelo, as atividades programadas;</li>'
        . '<li>observar as normas internas da CONCEDENTE, em especial seu Manual de Conduta e Procedimentos Operacionais Padrão (POPs);</li>'
        . '<li><strong>guardar absoluto sigilo profissional</strong> quanto a fatos, dados e documentos relacionados aos clientes, advogados e à própria CONCEDENTE, nos termos do art. 34, VII, do EAOAB, do art. 25 do Código de Ética e Disciplina da OAB e da Lei 13.709/2018, sob pena de responsabilização civil, criminal e administrativa;</li>'
        . '<li>comparecer pontualmente ao escritório e justificar, com antecedência, eventuais ausências;</li>'
        . '<li>informar, com antecedência mínima de 15 (quinze) dias, sobre o calendário de provas;</li>'
        . '<li>manter atualizado o registro acadêmico junto à instituição de ensino, comunicando à CONCEDENTE qualquer trancamento, abandono ou conclusão do curso;</li>'
        . '<li>apresentar, semestralmente, declaração de matrícula e histórico escolar atualizado;</li>'
        . '<li>comunicar, de imediato, à CONCEDENTE qualquer fato relevante que possa comprometer o regular cumprimento do estágio.</li>'
        . '</ul></div></div>';

    // 11. CONFIDENCIALIDADE
    $h .= '<div class="doc-clausula-titulo-h">CLÁUSULA 11ª — DA CONFIDENCIALIDADE E DA PROPRIEDADE INTELECTUAL</div>';
    $h .= '<div class="doc-clausula"><div class="doc-clausula-num">11.1</div><div class="doc-clausula-corpo">Todo o material produzido pelo(a) ESTAGIÁRIO(A) no exercício de suas atividades — incluindo minutas, pesquisas, pareceres, modelos e quaisquer outras peças jurídicas — pertence exclusivamente à CONCEDENTE, que poderá utilizá-lo livremente, reservados os direitos morais de autoria.</div></div>';
    $h .= '<div class="doc-clausula"><div class="doc-clausula-num">11.2</div><div class="doc-clausula-corpo">O dever de sigilo persistirá indefinidamente após o encerramento do estágio, abrangendo informações de clientes, estratégias processuais, base de dados, modelos do escritório, dados financeiros e quaisquer outras informações sensíveis a que o(a) ESTAGIÁRIO(A) tenha tido acesso.</div></div>';
    $h .= '<div class="doc-clausula"><div class="doc-clausula-num">11.3</div><div class="doc-clausula-corpo">O(a) ESTAGIÁRIO(A) firmará, em ato apartado, <em>Termo de Confidencialidade, Sigilo Profissional e Tratamento de Dados Pessoais</em>, cujas disposições integram o presente instrumento.</div></div>';

    // 12. RESCISÃO
    $h .= '<div class="doc-clausula-titulo-h">CLÁUSULA 12ª — DA RESCISÃO</div>';
    $h .= '<div class="doc-clausula"><div class="doc-clausula-num">12.1</div><div class="doc-clausula-corpo">O presente termo será automaticamente rescindido, independentemente de aviso prévio, nas seguintes hipóteses:<ul>'
        . '<li>conclusão, trancamento ou abandono do curso de Direito pelo(a) ESTAGIÁRIO(A);</li>'
        . '<li>descumprimento de qualquer obrigação contratual pelas partes;</li>'
        . '<li>quebra de sigilo profissional ou violação de dever ético;</li>'
        . '<li>aprovação do(a) ESTAGIÁRIO(A) no Exame de Ordem e respectiva inscrição como advogado(a), salvo migração para contrato de associação ou empregatício;</li>'
        . '<li>expiração do prazo legal máximo de 2 (dois) anos previsto na Lei 11.788/2008.</li>'
        . '</ul></div></div>';

    // 13. FORO
    $h .= '<div class="doc-clausula-titulo-h">CLÁUSULA 13ª — DO FORO</div>';
    $h .= '<div class="doc-clausula"><div class="doc-clausula-num">13.1</div><div class="doc-clausula-corpo">Fica eleito o foro da Comarca de Barra Mansa/RJ para dirimir quaisquer controvérsias oriundas do presente termo, com renúncia a qualquer outro, por mais privilegiado que seja.</div></div>';

    $h .= '<p style="margin-top:1.5rem;">E, por estarem assim ajustadas, firmam as partes o presente instrumento em 2 (duas) vias de igual teor e forma, na presença das testemunhas abaixo identificadas, para que produza seus regulares efeitos jurídicos.</p>';

    $assinDt = !empty($assinaturas['estagiario_em']) ? _onb_data_br_extenso(date('Y-m-d', strtotime($assinaturas['estagiario_em']))) : null;
    $h .= '<div class="doc-data-local">Barra Mansa/RJ, ' . htmlspecialchars($assinDt ?: '_____ de _________________ de 20___') . '.</div>';

    // Assinatura CONCEDENTE
    $h .= '<div class="doc-assinatura">'
        . '<div class="doc-assinatura-linha"></div>'
        . '<div class="doc-assinatura-nome">CONCEDENTE</div>'
        . '<div class="doc-assinatura-sub">Ferreira &amp; Sá Advocacia Especializada</div>'
        . '<div class="doc-assinatura-sub">Dra. Amanda Guedes Ferreira (OAB/RJ 163.260) &middot; Dr. Luiz Eduardo de Sá Silva Marcelino (OAB/RJ 248.755)</div>'
        . '</div>';

    // Assinatura ESTAGIÁRIO(A)
    $h .= _onb_assinatura_eletronica(
        $colaborador['nome_completo'] . ' — ESTAGIÁRIO(A)',
        $assinaturas['estagiario_em'] ?? null,
        $assinaturas['estagiario_ip'] ?? ''
    );

    $h .= _onb_footer_html();
    $h .= '</div>';
    return $h;
}

// ═══════════════════════════════════════════════════════════
// 2) TERMO DE CONFIDENCIALIDADE, SIGILO E LGPD
// ═══════════════════════════════════════════════════════════
function render_termo_confidencialidade_lgpd($colaborador, $dadosAdmin, $dadosColab, $assinaturas = array()) {
    $nome = strtoupper($colaborador['nome_completo'] ?? '');

    $h = '<div class="doc-page">';
    $h .= _onb_header('Termo de Confidencialidade,', 'Sigilo Profissional e Tratamento de Dados Pessoais');

    $h .= '<p>Pelo presente instrumento particular, <strong>' . htmlspecialchars($nome) . '</strong>, já qualificado(a) no Termo de Compromisso de Estágio firmado nesta data com a <strong>Ferreira &amp; Sá Advocacia Especializada</strong> (CNPJ 51.294.223/0001-40), declara estar plenamente ciente das obrigações de sigilo profissional, confidencialidade e proteção de dados pessoais decorrentes de seu vínculo com a CONCEDENTE, comprometendo-se a observar as cláusulas a seguir estipuladas.</p>';

    $h .= '<div class="doc-section-bar">Cláusulas</div>';

    // 1. SIGILO PROFISSIONAL
    $h .= '<div class="doc-clausula-titulo-h">CLÁUSULA 1ª — DO SIGILO PROFISSIONAL</div>';
    $h .= '<div class="doc-clausula"><div class="doc-clausula-num">1.1</div><div class="doc-clausula-corpo">O(a) ESTAGIÁRIO(A) reconhece que o sigilo profissional constitui dever ético e legal inerente ao exercício da advocacia, nos termos do art. 7º, II, e do art. 34, VII, da Lei 8.906/94 (EAOAB), do art. 25 do Código de Ética e Disciplina da OAB e do art. 154 do Código Penal, aplicando-se a obrigação a estagiários, prepostos e a todos os auxiliares da advocacia.</div></div>';
    $h .= '<div class="doc-clausula"><div class="doc-clausula-num">1.2</div><div class="doc-clausula-corpo">Toda informação obtida em razão do estágio é, para fins do presente termo, considerada <strong>INFORMAÇÃO CONFIDENCIAL</strong>, abrangendo, sem caráter exaustivo:<ul>'
        . '<li>identidade dos clientes do escritório, ainda que tornada pública;</li>'
        . '<li>fatos, documentos, estratégias processuais e teses jurídicas relacionados aos casos sob patrocínio da CONCEDENTE;</li>'
        . '<li>modelos de petições, fluxos de trabalho, skills, prompts e bases de conhecimento internas do escritório;</li>'
        . '<li>dados financeiros, comerciais e contratuais da CONCEDENTE;</li>'
        . '<li>comunicações internas (e-mails, mensagens, reuniões) e externas (com clientes, contrapartes, autoridades, peritos);</li>'
        . '<li>qualquer outra informação assinalada como confidencial ou que, por sua natureza, deva ser tratada como tal.</li>'
        . '</ul></div></div>';

    // 2. VIGÊNCIA
    $h .= '<div class="doc-clausula-titulo-h">CLÁUSULA 2ª — DA VIGÊNCIA E PERSISTÊNCIA DA OBRIGAÇÃO</div>';
    $h .= '<div class="doc-clausula"><div class="doc-clausula-num">2.1</div><div class="doc-clausula-corpo">A obrigação de sigilo persiste por prazo indeterminado, mesmo após o término, a rescisão ou a denúncia do Termo de Compromisso de Estágio, vinculando o(a) ESTAGIÁRIO(A) durante toda a sua vida profissional, em conformidade com o caráter perpétuo do sigilo profissional na advocacia.</div></div>';

    // 3. VEDAÇÕES
    $h .= '<div class="doc-clausula-titulo-h">CLÁUSULA 3ª — DAS VEDAÇÕES</div>';
    $h .= '<div class="doc-clausula"><div class="doc-clausula-num">3.1</div><div class="doc-clausula-corpo">É expressamente vedado ao(à) ESTAGIÁRIO(A):<ul>'
        . '<li>divulgar, comentar ou compartilhar informações confidenciais com terceiros, ainda que sejam advogados não vinculados à CONCEDENTE, familiares ou conhecidos;</li>'
        . '<li>mencionar clientes, casos ou estratégias em redes sociais, blogs, podcasts, vídeos, palestras, trabalhos acadêmicos ou qualquer canal público;</li>'
        . '<li>fotografar, gravar, copiar, imprimir ou reproduzir, por qualquer meio, documentos, telas de sistemas, e-mails ou comunicações da CONCEDENTE, salvo necessidade estritamente operacional autorizada pela supervisão;</li>'
        . '<li>utilizar informações confidenciais em proveito próprio ou de terceiros, durante ou após o estágio;</li>'
        . '<li>extrair, copiar ou transferir bases de dados, listas de clientes, modelos de petição ou qualquer ativo de propriedade intelectual da CONCEDENTE para meios externos não autorizados;</li>'
        . '<li>utilizar inteligência artificial generativa pública (sem ambiente corporativo) para processar dados de clientes ou peças do escritório.</li>'
        . '</ul></div></div>';

    // 4. LGPD
    $h .= '<div class="doc-clausula-titulo-h">CLÁUSULA 4ª — DA LEI GERAL DE PROTEÇÃO DE DADOS (LGPD)</div>';
    $h .= '<div class="doc-clausula"><div class="doc-clausula-num">4.1</div><div class="doc-clausula-corpo">O(a) ESTAGIÁRIO(A) declara conhecer a Lei 13.709/2018 (LGPD) e compromete-se a tratar todos os dados pessoais de clientes, contrapartes, testemunhas e demais titulares com observância dos princípios da finalidade, adequação, necessidade, segurança e responsabilização.</div></div>';
    $h .= '<div class="doc-clausula"><div class="doc-clausula-num">4.2</div><div class="doc-clausula-corpo">O(a) ESTAGIÁRIO(A) atuará como <strong>agente de tratamento sob a direção da CONCEDENTE</strong> (controladora dos dados), devendo:<ul>'
        . '<li>tratar dados pessoais somente para a finalidade processual ou administrativa específica do caso;</li>'
        . '<li>observar especial cautela com dados pessoais sensíveis (saúde, sexualidade, raça, religião, biometria, dados de crianças e adolescentes);</li>'
        . '<li>não armazenar dados pessoais em dispositivos pessoais não autorizados;</li>'
        . '<li>reportar imediatamente à supervisão qualquer suspeita de incidente de segurança, vazamento ou acesso indevido, no prazo máximo de 24 (vinte e quatro) horas, para fins de cumprimento do art. 48 da LGPD.</li>'
        . '</ul></div></div>';

    // 5. DEVOLUÇÃO
    $h .= '<div class="doc-clausula-titulo-h">CLÁUSULA 5ª — DA DEVOLUÇÃO DE MATERIAL</div>';
    $h .= '<div class="doc-clausula"><div class="doc-clausula-num">5.1</div><div class="doc-clausula-corpo">No término do estágio, o(a) ESTAGIÁRIO(A) compromete-se a devolver, em até 5 (cinco) dias úteis, todo material físico ou digital de propriedade da CONCEDENTE, incluindo equipamentos, documentos, cópias, anotações e arquivos, bem como a apagar permanentemente qualquer cópia mantida em dispositivos pessoais, mediante declaração escrita de exclusão.</div></div>';

    // 6. PENALIDADES
    $h .= '<div class="doc-clausula-titulo-h">CLÁUSULA 6ª — DAS PENALIDADES</div>';
    $h .= '<div class="doc-clausula"><div class="doc-clausula-num">6.1</div><div class="doc-clausula-corpo">A violação de quaisquer das cláusulas deste termo sujeitará o(a) ESTAGIÁRIO(A) a:<ul>'
        . '<li><strong>responsabilização civil</strong> por todos os danos materiais, morais e à imagem causados à CONCEDENTE, aos clientes ou a terceiros, nos termos dos arts. 186, 187 e 927 do Código Civil;</li>'
        . '<li><strong>responsabilização criminal</strong> nos termos do art. 154 do Código Penal (violação de segredo profissional) e demais tipos penais aplicáveis;</li>'
        . '<li><strong>responsabilização administrativa</strong> perante a OAB, quando inscrito como estagiário, nos termos do EAOAB e do Código de Ética da OAB;</li>'
        . '<li><strong>rescisão imediata</strong> do Termo de Compromisso de Estágio por justa causa, sem prejuízo das demais penalidades.</li>'
        . '</ul></div></div>';

    // 7. FORO
    $h .= '<div class="doc-clausula-titulo-h">CLÁUSULA 7ª — DO FORO</div>';
    $h .= '<div class="doc-clausula"><div class="doc-clausula-num">7.1</div><div class="doc-clausula-corpo">Fica eleito o foro da Comarca de Barra Mansa/RJ para dirimir quaisquer controvérsias decorrentes deste termo, com renúncia a qualquer outro, por mais privilegiado que seja.</div></div>';

    $h .= '<p style="margin-top:1.5rem;">E, por estar de inteiro acordo, firma o presente.</p>';
    $assinDt = !empty($assinaturas['estagiario_em']) ? _onb_data_br_extenso(date('Y-m-d', strtotime($assinaturas['estagiario_em']))) : null;
    $h .= '<div class="doc-data-local">Barra Mansa/RJ, ' . htmlspecialchars($assinDt ?: '_____ de _________________ de 20___') . '.</div>';

    $h .= _onb_assinatura_eletronica(
        $colaborador['nome_completo'] . ' — ESTAGIÁRIO(A)',
        $assinaturas['estagiario_em'] ?? null,
        $assinaturas['estagiario_ip'] ?? ''
    );

    $h .= _onb_footer_html();
    $h .= '</div>';
    return $h;
}

// ═══════════════════════════════════════════════════════════
// 3) POP — placeholder até Amanda mandar PDF
// ═══════════════════════════════════════════════════════════
function render_pop_estagiario($colaborador, $dadosAdmin, $dadosColab, $assinaturas = array()) {
    $h = '<div class="doc-page">';
    $h .= _onb_header('POP — Estagiário', 'Procedimentos Operacionais Padrão');
    $h .= '<p style="text-align:center;color:#9a3412;background:#fef3c7;padding:1rem;border-radius:8px;border:1px solid #fbbf24;margin:2rem 0;">'
        . '⏳ Conteúdo do POP será disponibilizado em breve. Aguarde a Dra. Amanda enviar a versão final.'
        . '</p>';
    $h .= _onb_footer_html();
    $h .= '</div>';
    return $h;
}

// ═══════════════════════════════════════════════════════════
// 4) CHECKLIST ADMISSIONAL — placeholder
// ═══════════════════════════════════════════════════════════
function render_checklist_admissional_estagiario($colaborador, $dadosAdmin, $dadosColab, $assinaturas = array()) {
    $h = '<div class="doc-page">';
    $h .= _onb_header('Checklist Admissional', 'do(a) Estagiário(a)');
    $h .= '<p style="text-align:center;color:#9a3412;background:#fef3c7;padding:1rem;border-radius:8px;border:1px solid #fbbf24;margin:2rem 0;">'
        . '⏳ Este documento é preenchido pelo admin nos primeiros 5 dias úteis.<br>Quando todos os itens forem marcados, ele estará pronto para sua assinatura de ciência.'
        . '</p>';
    $h .= _onb_footer_html();
    $h .= '</div>';
    return $h;
}
