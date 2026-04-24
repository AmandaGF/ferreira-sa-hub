<?php
/**
 * Renderizador Visual Law — converte petição com MARCADORES em HTML inline pronto.
 *
 * Entrada (emitida pela IA via system prompt):
 *   [BARRA_SECAO] TÍTULO
 *   [SUBTOPICO] Título do subtópico
 *   [CAIXA_ACAO] NOME DA AÇÃO
 *   [INDICACAO] GRATUIDADE DE JUSTIÇA
 *   [VERMELHO] dado faltante [/VERMELHO]
 *   [TABELA_DESPESAS]
 *   categoria | descrição | valor
 *   categoria | descrição | valor
 *   [/TABELA_DESPESAS]
 *   [PEDIDOS]
 *   a) texto do pedido
 *   b) texto do pedido com sub-itens
 *     I. sub-item
 *     II. sub-item
 *   [/PEDIDOS]
 *   [ASSINATURA]
 *   Texto livre fora dos marcadores = parágrafo normal (justify + indent 1.5cm)
 *
 * Saída: HTML com estilos inline (font Calibri, paleta Visual Law oficial),
 * pronto pra imprimir, exportar pra Word e renderizar no navegador.
 *
 * Vantagem do marcador sobre HTML direto da IA:
 *   - Formatação consistente (um único ponto de aplicação do CSS)
 *   - Impressão/Word/PDF nunca perdem estilo (não depende de CSS do browser)
 *   - Fácil de ajustar visual sem retreinar a IA — muda só o renderer
 */

function peticao_render(string $texto): string {
    // 1) Normalização inicial
    $texto = str_replace(array("\r\n", "\r"), "\n", $texto);
    $texto = trim($texto);

    // Remove code blocks acidentais (IA às vezes devolve ```markdown...```)
    $texto = preg_replace('/^```[a-z]*\s*/i', '', $texto);
    $texto = preg_replace('/\s*```\s*$/', '', $texto);

    // 2) Processar blocos estruturados PRIMEIRO (evita que virem parágrafos soltos)
    $texto = _peticao_render_blocos($texto);

    // 3) Processar marcadores de linha (barras, subtópicos, caixa ação, indicação, assinatura)
    $texto = _peticao_render_linhas($texto);

    // 4) Processar inline (vermelho)
    $texto = _peticao_render_inline($texto);

    // 5) Parágrafos livres — quebra em linhas e cada uma vira <p> justify + indent
    $texto = _peticao_render_paragrafos($texto);

    return $texto;
}

/**
 * Blocos fechados: [TABELA_DESPESAS]...[/TABELA_DESPESAS] e [PEDIDOS]...[/PEDIDOS]
 */
function _peticao_render_blocos(string $texto): string {
    // TABELA_DESPESAS
    $texto = preg_replace_callback(
        '/\[TABELA_DESPESAS\](.*?)\[\/TABELA_DESPESAS\]/is',
        function ($m) {
            $linhas = array_values(array_filter(array_map('trim', explode("\n", $m[1])), 'strlen'));
            $html = '<table style="width:100%;border-collapse:collapse;font-family:Calibri,sans-serif;font-size:11pt;margin:12px 0;">'
                  . '<tr style="background:#052228;color:#FFFFFF;">'
                  . '<th style="padding:10px 12px;text-align:left;border:none;">CATEGORIA</th>'
                  . '<th style="padding:10px 12px;text-align:left;border:none;">DESCRIÇÃO</th>'
                  . '<th style="padding:10px 12px;text-align:right;border:none;">VALOR MENSAL</th>'
                  . '</tr>';
            $i = 0;
            $totalCalculado = 0.0;
            $temTotalExplicito = false;
            foreach ($linhas as $ln) {
                $cols = array_map('trim', explode('|', $ln));
                if (count($cols) < 3) continue;
                $cat = $cols[0];
                $desc = $cols[1];
                $valor = $cols[2];
                $bg = ($i++ % 2 === 0) ? '#FFFFFF' : '#F4F4F4';
                $isTotal = preg_match('/^TOTAL/i', $cat);
                if ($isTotal) $temTotalExplicito = true;
                if (!$isTotal) {
                    // Extrai valor numérico pra somar
                    $num = preg_replace('/[^\d,.]/', '', $valor);
                    $num = str_replace(array('.', ','), array('', '.'), $num);
                    if (is_numeric($num)) $totalCalculado += (float)$num;
                }
                $style = $isTotal
                    ? 'background:#052228;color:#FFFFFF;font-weight:700;'
                    : 'background:' . $bg . ';';
                $html .= '<tr style="' . $style . '">'
                      . '<td style="padding:8px 12px;border:none;">' . htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') . '</td>'
                      . '<td style="padding:8px 12px;border:none;">' . htmlspecialchars($desc, ENT_QUOTES, 'UTF-8') . '</td>'
                      . '<td style="padding:8px 12px;border:none;text-align:right;">' . htmlspecialchars($valor, ENT_QUOTES, 'UTF-8') . '</td>'
                      . '</tr>';
            }
            // Se IA não pôs TOTAL, adiciona automático
            if (!$temTotalExplicito && $totalCalculado > 0) {
                $html .= '<tr style="background:#052228;color:#FFFFFF;font-weight:700;">'
                      . '<td style="padding:10px 12px;border:none;">TOTAL</td>'
                      . '<td style="padding:10px 12px;border:none;"></td>'
                      . '<td style="padding:10px 12px;border:none;text-align:right;">R$ ' . number_format($totalCalculado, 2, ',', '.') . '</td>'
                      . '</tr>';
            }
            $html .= '</table>';
            return "\n\n" . $html . "\n\n";
        },
        $texto
    );

    // PEDIDOS — tabela formatada com coluna de letras fundo petrol
    $texto = preg_replace_callback(
        '/\[PEDIDOS\](.*?)\[\/PEDIDOS\]/is',
        function ($m) {
            $linhas = explode("\n", trim($m[1]));
            $html = '<table style="width:100%;border-collapse:collapse;margin:12px 0;">';
            $i = 0;
            $blocoAtual = '';
            $letraAtual = '';
            $subitensAtual = array();

            $flush = function () use (&$html, &$blocoAtual, &$letraAtual, &$subitensAtual, &$i) {
                if (!$letraAtual) return;
                $bg = ($i++ % 2 === 0) ? '#FFFFFF' : '#F4F4F4';
                $corpo = htmlspecialchars($blocoAtual, ENT_QUOTES, 'UTF-8');
                if (!empty($subitensAtual)) {
                    $corpo .= '<div style="margin-top:6px;padding-left:20px;">';
                    foreach ($subitensAtual as $sub) {
                        $corpo .= '<p style="margin:3px 0;font-family:Calibri,sans-serif;font-size:12pt;line-height:1.8;text-align:justify;">'
                               .  htmlspecialchars($sub, ENT_QUOTES, 'UTF-8')
                               .  '</p>';
                    }
                    $corpo .= '</div>';
                }
                $html .= '<tr>'
                       . '<td style="width:40px;background:#052228;color:#FFFFFF;font-weight:700;text-align:center;padding:10px 8px;vertical-align:top;border:none;font-family:Calibri,sans-serif;font-size:12pt;">' . htmlspecialchars($letraAtual, ENT_QUOTES, 'UTF-8') . ')</td>'
                       . '<td style="padding:10px 12px;font-family:Calibri,sans-serif;font-size:12pt;line-height:1.8;text-align:justify;border:none;background:' . $bg . ';">' . $corpo . '</td>'
                       . '</tr>';
                $blocoAtual = '';
                $letraAtual = '';
                $subitensAtual = array();
            };

            foreach ($linhas as $ln) {
                $ln = rtrim($ln);
                if ($ln === '') continue;
                // Nova letra: a), b), c)... (pode ter indentação antes)
                if (preg_match('/^\s*([a-z])\)\s*(.*)$/i', $ln, $mp)) {
                    $flush();
                    $letraAtual = strtolower($mp[1]);
                    $blocoAtual = $mp[2];
                }
                // Sub-item: I., II., III., IV... (com indentação)
                elseif (preg_match('/^\s+([IVXLCDM]+|\d+)\.\s*(.*)$/i', $ln, $ms)) {
                    $subitensAtual[] = $ms[1] . '. ' . $ms[2];
                }
                // Linha continua o bloco atual
                elseif ($letraAtual !== '') {
                    $blocoAtual .= ' ' . trim($ln);
                }
            }
            $flush();

            $html .= '</table>';
            return "\n\n" . $html . "\n\n";
        },
        $texto
    );

    return $texto;
}

/**
 * Marcadores de linha — processados ANTES dos parágrafos livres.
 */
function _peticao_render_linhas(string $texto): string {
    $linhas = explode("\n", $texto);
    $out = array();

    foreach ($linhas as $ln) {
        $lnTrim = trim($ln);
        if ($lnTrim === '') { $out[] = ''; continue; }

        // [BARRA_SECAO] TÍTULO — seção principal (texto à direita + bloco petrol na margem)
        if (preg_match('/^\[BARRA_SECAO\]\s*(.+)$/i', $lnTrim, $m)) {
            $titulo = trim($m[1]);
            $out[] = '<table style="width:100%;border-collapse:collapse;margin:32px 0 16px 0;"><tr>'
                   . '<td style="border:none;"></td>'
                   . '<td style="text-align:right;padding:8px 16px 8px 0;border:none;font-family:Calibri,sans-serif;font-size:12pt;font-weight:700;color:#052228;letter-spacing:1px;text-transform:uppercase;">' . htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8') . '</td>'
                   . '<td style="width:10px;background:#052228;border:none;"></td>'
                   . '</tr></table>';
            continue;
        }

        // [SUBTOPICO] Título — barra cobre à esquerda
        if (preg_match('/^\[SUBTOPICO\]\s*(.+)$/i', $lnTrim, $m)) {
            $titulo = trim($m[1]);
            $out[] = '<table style="width:100%;border-collapse:collapse;margin:20px 0 8px 0;"><tr>'
                   . '<td style="width:4px;background:#B87333;border:none;"></td>'
                   . '<td style="padding:8px 12px;border:none;"><span style="font-family:Calibri,sans-serif;font-size:12pt;font-weight:700;color:#052228;text-transform:uppercase;">' . htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8') . '</span></td>'
                   . '</tr></table>';
            continue;
        }

        // [SUBSUBTOPICO] I. Algo — subtópico menor (negrito + sublinhado)
        if (preg_match('/^\[SUBSUBTOPICO\]\s*(.+)$/i', $lnTrim, $m)) {
            $out[] = '<p style="font-family:Calibri,sans-serif;font-size:12pt;font-weight:700;text-decoration:underline;color:#052228;margin:16px 0 6px 0;">' . htmlspecialchars(trim($m[1]), ENT_QUOTES, 'UTF-8') . '</p>';
            continue;
        }

        // [CAIXA_ACAO] NOME DA AÇÃO — caixa visual law (faixa cobre + fundo petrol)
        if (preg_match('/^\[CAIXA_ACAO\]\s*(.+)$/i', $lnTrim, $m)) {
            $nome = trim($m[1]);
            $out[] = '<table style="width:100%;border-collapse:collapse;margin:24px 0;"><tr>'
                   . '<td style="width:8px;background:#B87333;border:none;"></td>'
                   . '<td style="background:#052228;padding:14px 24px;text-align:center;border:none;"><span style="color:#FFFFFF;font-family:Calibri,sans-serif;font-size:13pt;font-weight:700;text-transform:uppercase;letter-spacing:4px;">' . htmlspecialchars($nome, ENT_QUOTES, 'UTF-8') . '</span></td>'
                   . '</tr></table>';
            continue;
        }

        // [INDICACAO] GRATUIDADE DE JUSTIÇA — bloco alinhado à direita, negrito
        if (preg_match('/^\[INDICACAO\]\s*(.+)$/i', $lnTrim, $m)) {
            $out[] = '<p style="text-align:right;font-weight:700;font-size:12pt;font-family:Calibri,sans-serif;color:#1A1A1A;margin:4px 0;">' . htmlspecialchars(trim($m[1]), ENT_QUOTES, 'UTF-8') . '</p>';
            continue;
        }

        // [ENDERECAMENTO] AO JUÍZO... — título de endereçamento (caixa alta negrito esquerda)
        if (preg_match('/^\[ENDERECAMENTO\]\s*(.+)$/i', $lnTrim, $m)) {
            $out[] = '<p style="font-family:Calibri,sans-serif;font-size:12pt;font-weight:700;color:#1A1A1A;text-transform:uppercase;margin:10px 0;line-height:1.6;">' . htmlspecialchars(trim($m[1]), ENT_QUOTES, 'UTF-8') . '</p>';
            continue;
        }

        // [CONTRA] — palavra "contra" centralizada, negrito
        if (preg_match('/^\[CONTRA\]\s*$/i', $lnTrim)) {
            $out[] = '<p style="text-align:center;font-family:Calibri,sans-serif;font-size:12pt;font-weight:700;color:#1A1A1A;margin:18px 0;">contra</p>';
            continue;
        }

        // [ASSINATURA] — assinatura dupla padrão Amanda + Luiz Eduardo
        if (preg_match('/^\[ASSINATURA\]\s*$/i', $lnTrim)) {
            $out[] = '<p style="text-align:center;font-family:Calibri,sans-serif;font-size:12pt;margin:40px 0 8px 0;">Nestes termos, pede deferimento.</p>'
                   . '<p style="text-align:center;font-family:Calibri,sans-serif;font-size:12pt;">Barra Mansa, data do sistema.</p>'
                   . '<table style="width:100%;border-collapse:collapse;margin:40px 0 0 0;"><tr>'
                   . '<td style="width:50%;text-align:center;border:none;padding:0 20px;">'
                   .   '<p style="font-family:Calibri,sans-serif;font-size:12pt;font-weight:700;color:#052228;margin:0;">AMANDA GUEDES FERREIRA</p>'
                   .   '<p style="font-family:Calibri,sans-serif;font-size:11pt;color:#173D46;margin:2px 0 0 0;">OAB-RJ 163.260</p>'
                   . '</td>'
                   . '<td style="width:50%;text-align:center;border:none;padding:0 20px;">'
                   .   '<p style="font-family:Calibri,sans-serif;font-size:12pt;font-weight:700;color:#052228;margin:0;">LUIZ EDUARDO DE SÁ SILVA MARCELINO</p>'
                   .   '<p style="font-family:Calibri,sans-serif;font-size:11pt;color:#173D46;margin:2px 0 0 0;">OAB-RJ 248.755</p>'
                   . '</td>'
                   . '</tr></table>';
            continue;
        }

        // Linha normal — devolve pra fase de parágrafo
        $out[] = $ln;
    }

    return implode("\n", $out);
}

/**
 * Inline — tag [VERMELHO]...[/VERMELHO] vira span vermelho.
 */
function _peticao_render_inline(string $texto): string {
    $texto = preg_replace_callback(
        '/\[VERMELHO\](.*?)\[\/VERMELHO\]/is',
        function ($m) {
            return '<span style="color:#CC0000;font-weight:700;">' . htmlspecialchars(trim($m[1]), ENT_QUOTES, 'UTF-8') . '</span>';
        },
        $texto
    );
    return $texto;
}

/**
 * Texto livre fora dos marcadores vira <p> justify + indent.
 * Linhas que já começam com < (tag) são preservadas direto (vêm dos blocos/linhas processados antes).
 */
function _peticao_render_paragrafos(string $texto): string {
    $blocos = preg_split('/\n\s*\n/', $texto);
    $out = array();
    foreach ($blocos as $bloco) {
        $bloco = trim($bloco);
        if ($bloco === '') continue;
        // Se bloco já começa com tag HTML (table, p, div, span), mantém intacto
        if (preg_match('/^\s*<(table|p|div|span|ul|ol|h[1-6])/i', $bloco)) {
            $out[] = $bloco;
        } else {
            // Escapa e aplica estilo de parágrafo
            $safe = htmlspecialchars($bloco, ENT_QUOTES, 'UTF-8');
            // Preserva negrito **texto**
            $safe = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $safe);
            // Preserva itálico *texto*
            $safe = preg_replace('/(?<!\*)\*([^*]+)\*(?!\*)/', '<em>$1</em>', $safe);
            $out[] = '<p style="text-align:justify;text-indent:1.5cm;font-family:Calibri,sans-serif;font-size:12pt;line-height:1.8;color:#1A1A1A;margin:10px 0;">' . $safe . '</p>';
        }
    }
    return implode("\n\n", $out);
}
