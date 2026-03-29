<?php
/**
 * Extrator de texto de PDF simples (sem dependências externas)
 * Funciona em PHP 7.4+ sem Composer
 * Suporta PDFs com texto (não funciona com PDFs escaneados/imagem)
 */

function pdf_extract_text($filePath)
{
    $content = file_get_contents($filePath);
    if (!$content) return '';

    $text = '';

    // Método 1: Extrair streams de texto decodificados
    // Procurar por blocos BT...ET (Begin Text / End Text)
    if (preg_match_all('/stream\s*\n(.*?)\nendstream/s', $content, $streams)) {
        foreach ($streams[1] as $stream) {
            $decoded = _pdf_decode_stream($stream, $content);
            if ($decoded) {
                $extracted = _pdf_extract_text_from_stream($decoded);
                if ($extracted) {
                    $text .= $extracted . "\n";
                }
            }
        }
    }

    // Método 2: Procurar texto direto entre parênteses em blocos BT/ET
    if (preg_match_all('/BT\s*(.*?)\s*ET/s', $content, $btBlocks)) {
        foreach ($btBlocks[1] as $block) {
            // Extrair texto entre parênteses
            if (preg_match_all('/\((.*?)\)/s', $block, $texts)) {
                foreach ($texts[1] as $t) {
                    $decoded = _pdf_decode_string($t);
                    if (mb_strlen(trim($decoded)) > 0) {
                        $text .= $decoded . ' ';
                    }
                }
            }
            // Extrair texto hex <...>
            if (preg_match_all('/<([0-9A-Fa-f]+)>/s', $block, $hexTexts)) {
                foreach ($hexTexts[1] as $hex) {
                    $decoded = _pdf_hex_to_text($hex);
                    if (mb_strlen(trim($decoded)) > 1) {
                        $text .= $decoded . ' ';
                    }
                }
            }
            $text .= "\n";
        }
    }

    // Método 3: Fallback - buscar qualquer texto legível
    if (mb_strlen(trim($text)) < 50) {
        // Tentar extrair texto legível diretamente
        if (preg_match_all('/\(([^\)]{2,})\)/', $content, $directTexts)) {
            foreach ($directTexts[1] as $t) {
                $decoded = _pdf_decode_string($t);
                $clean = trim($decoded);
                // Filtrar lixo: só manter se parecer texto legível
                if (mb_strlen($clean) > 2 && preg_match('/[a-zA-ZÀ-ÿ]{2,}/', $clean)) {
                    $text .= $clean . "\n";
                }
            }
        }
    }

    // Limpar
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text);
    $text = preg_replace('/\n{3,}/', "\n\n", $text);

    return trim($text);
}

function _pdf_decode_stream($stream, $fullContent)
{
    // Tentar FlateDecode (mais comum)
    $decoded = @gzuncompress($stream);
    if ($decoded) return $decoded;

    $decoded = @gzinflate($stream);
    if ($decoded) return $decoded;

    // Tentar sem header
    $decoded = @gzinflate(substr($stream, 2));
    if ($decoded) return $decoded;

    // Retornar raw se parece texto
    if (preg_match('/[a-zA-ZÀ-ÿ]{3,}/', $stream)) {
        return $stream;
    }

    return null;
}

function _pdf_extract_text_from_stream($stream)
{
    $text = '';

    // Extrair de blocos BT/ET
    if (preg_match_all('/BT\s*(.*?)\s*ET/s', $stream, $blocks)) {
        foreach ($blocks[1] as $block) {
            if (preg_match_all('/\((.*?)\)/s', $block, $texts)) {
                foreach ($texts[1] as $t) {
                    $text .= _pdf_decode_string($t) . ' ';
                }
            }
            if (preg_match_all('/\[(.*?)\]\s*TJ/s', $block, $tjBlocks)) {
                foreach ($tjBlocks[1] as $tj) {
                    if (preg_match_all('/\((.*?)\)/', $tj, $tjTexts)) {
                        foreach ($tjTexts[1] as $t) {
                            $text .= _pdf_decode_string($t);
                        }
                    }
                }
                $text .= ' ';
            }
            $text .= "\n";
        }
    }

    return $text;
}

function _pdf_decode_string($str)
{
    // Decodificar escapes do PDF
    $str = str_replace(array('\\n', '\\r', '\\t', '\\(', '\\)', '\\\\'),
                       array("\n", "\r", "\t", '(', ')', '\\'), $str);

    // Converter octal escapes (\NNN)
    $str = preg_replace_callback('/\\\\(\d{1,3})/', function($m) {
        return chr(octdec($m[1]));
    }, $str);

    return $str;
}

function _pdf_hex_to_text($hex)
{
    $text = '';
    for ($i = 0; $i < strlen($hex) - 1; $i += 4) {
        $char = hexdec(substr($hex, $i, 4));
        if ($char > 31 && $char < 127) {
            $text .= chr($char);
        } elseif ($char > 127) {
            $text .= mb_chr($char, 'UTF-8');
        }
    }
    if (mb_strlen($text) < 2) {
        // Tentar 2 bytes por caractere
        $text = '';
        for ($i = 0; $i < strlen($hex) - 1; $i += 2) {
            $char = hexdec(substr($hex, $i, 2));
            if ($char > 31) {
                $text .= chr($char);
            }
        }
    }
    return $text;
}

/**
 * Extrair contatos estruturados do texto do PDF
 * Detecta CPF, telefone, e-mail e tenta associar a nomes
 */
function pdf_extract_contacts($text)
{
    $contacts = array();
    $lines = preg_split('/\n+/', $text);

    // Padrões de detecção
    $cpfPattern = '/(\d{3}[.\s]?\d{3}[.\s]?\d{3}[-.\s]?\d{2})/';
    $phonePattern = '/\(?\d{2}\)?\s*\d{4,5}[-.\s]?\d{4}/';
    $emailPattern = '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/';

    // Primeiro: tentar detectar formato tabular (LegalOne geralmente exporta tabelas)
    $currentContact = array('name' => '', 'cpf' => '', 'phone' => '', 'email' => '');
    $lastNameLine = '';

    foreach ($lines as $line) {
        $line = trim($line);
        if (mb_strlen($line) < 2) continue;

        // Detectar CPF
        if (preg_match($cpfPattern, $line, $m)) {
            $cpf = preg_replace('/[^0-9]/', '', $m[1]);
            if (strlen($cpf) === 11) {
                // Se já tem um contato em construção com nome, salvar
                if ($currentContact['name'] && $currentContact['cpf']) {
                    $contacts[] = $currentContact;
                    $currentContact = array('name' => '', 'cpf' => '', 'phone' => '', 'email' => '');
                }
                $currentContact['cpf'] = $cpf;

                // Nome pode estar na mesma linha antes do CPF
                $beforeCpf = trim(preg_replace($cpfPattern, '', $line));
                // Ou pode ser um nome (sem números, com pelo menos 2 palavras)
                if (preg_match('/^[A-ZÀ-Ÿa-zà-ÿ\s]{5,}$/', $beforeCpf) && str_word_count($beforeCpf) >= 2) {
                    $currentContact['name'] = mb_convert_case(trim($beforeCpf), MB_CASE_TITLE, 'UTF-8');
                } elseif ($lastNameLine) {
                    $currentContact['name'] = $lastNameLine;
                }
            }
        }

        // Detectar telefone
        if (preg_match($phonePattern, $line, $m)) {
            $phone = preg_replace('/[^0-9]/', '', $m[0]);
            if (strlen($phone) >= 10 && strlen($phone) <= 11) {
                if (!$currentContact['phone']) {
                    $currentContact['phone'] = $m[0];
                }
            }
        }

        // Detectar e-mail
        if (preg_match($emailPattern, $line, $m)) {
            if (!$currentContact['email']) {
                $currentContact['email'] = strtolower($m[0]);
            }
        }

        // Detectar possível nome (linha com apenas texto, sem números, 2+ palavras)
        $cleanLine = preg_replace('/[^A-ZÀ-Ÿa-zà-ÿ\s]/', '', $line);
        if (mb_strlen($cleanLine) > 5 && str_word_count($cleanLine) >= 2 && !preg_match('/\d/', $line)
            && !preg_match('/^(nome|cpf|telefone|email|endere|data|cliente|observ|status|rua|bairro|cidade)/i', $cleanLine)) {
            $lastNameLine = mb_convert_case(trim($cleanLine), MB_CASE_TITLE, 'UTF-8');
            if (!$currentContact['name'] && !$currentContact['cpf']) {
                $currentContact['name'] = $lastNameLine;
            }
        }
    }

    // Salvar último contato
    if ($currentContact['name'] || $currentContact['cpf']) {
        $contacts[] = $currentContact;
    }

    // Limpar contatos sem nome
    $result = array();
    foreach ($contacts as $c) {
        if (empty($c['name']) && !empty($c['cpf'])) {
            $c['name'] = 'CPF ' . substr($c['cpf'], 0, 3) . '.***.***-' . substr($c['cpf'], -2);
        }
        if (!empty($c['name'])) {
            // Formatar CPF
            if (!empty($c['cpf']) && strlen($c['cpf']) === 11) {
                $c['cpf'] = substr($c['cpf'], 0, 3) . '.' . substr($c['cpf'], 3, 3) . '.' . substr($c['cpf'], 6, 3) . '-' . substr($c['cpf'], 9, 2);
            }
            // Formatar telefone
            if (!empty($c['phone'])) {
                $phone = preg_replace('/[^0-9]/', '', $c['phone']);
                if (strlen($phone) === 11) {
                    $c['phone'] = '(' . substr($phone, 0, 2) . ') ' . substr($phone, 2, 5) . '-' . substr($phone, 7);
                } elseif (strlen($phone) === 10) {
                    $c['phone'] = '(' . substr($phone, 0, 2) . ') ' . substr($phone, 2, 4) . '-' . substr($phone, 6);
                }
            }
            $result[] = $c;
        }
    }

    return $result;
}
