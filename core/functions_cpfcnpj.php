<?php
/**
 * Ferreira & Sá Conecta — Consulta CPF/CNPJ centralizada
 *
 * Ordem de busca CPF:
 *   1. Validação local dos dígitos verificadores (sem custo)
 *   2. Cache interno (cpf_cache, válido 30 dias)
 *   3. Base interna: clients + case_partes (sem custo)
 *   4. API externa cpfcnpj.com.br (pago, token em configuracoes)
 *
 * CNPJ sempre via ReceitaWS (gratuito)
 */

// ─── Validação local ───────────────────────────────────

function validar_digitos_cpf($cpf)
{
    $cpf = preg_replace('/\D/', '', $cpf);
    if (strlen($cpf) !== 11) return false;
    if (preg_match('/(\d)\1{10}/', $cpf)) return false;
    for ($t = 9; $t < 11; $t++) {
        for ($d = 0, $c = 0; $c < $t; $c++) {
            $d += $cpf[$c] * (($t + 1) - $c);
        }
        $d = ((10 * $d) % 11) % 10;
        if ($cpf[$c] != $d) return false;
    }
    return true;
}

function validar_digitos_cnpj($cnpj)
{
    $cnpj = preg_replace('/\D/', '', $cnpj);
    if (strlen($cnpj) !== 14) return false;
    if (preg_match('/(\d)\1{13}/', $cnpj)) return false;
    $pesos1 = array(5,4,3,2,9,8,7,6,5,4,3,2);
    $pesos2 = array(6,5,4,3,2,9,8,7,6,5,4,3,2);
    for ($i = 0, $soma = 0; $i < 12; $i++) $soma += $cnpj[$i] * $pesos1[$i];
    $resto = $soma % 11;
    if ($cnpj[12] != ($resto < 2 ? 0 : 11 - $resto)) return false;
    for ($i = 0, $soma = 0; $i < 13; $i++) $soma += $cnpj[$i] * $pesos2[$i];
    $resto = $soma % 11;
    if ($cnpj[13] != ($resto < 2 ? 0 : 11 - $resto)) return false;
    return true;
}

// ─── Busca CPF ─────────────────────────────────────────

function buscar_cpf($cpf)
{
    $cpf = preg_replace('/\D/', '', $cpf);

    // Camada 1: validar dígitos
    if (!validar_digitos_cpf($cpf)) {
        return array('erro' => 'CPF inválido');
    }

    $pdo = db();

    // Camada 2: base interna — clients (PRIORIDADE sobre cache externo) v2
    $cpfFmt = substr($cpf,0,3).'.'.substr($cpf,3,3).'.'.substr($cpf,6,3).'-'.substr($cpf,9,2);
    $stmt = $pdo->prepare(
        "SELECT id, name, cpf, rg, birth_date, profession, marital_status, gender,
                nacionalidade, email, phone, phone2, address_street, address_city,
                address_state, address_zip, pix_key, children_names
         FROM clients WHERE REPLACE(REPLACE(cpf,'.',''),'-','') = ? LIMIT 1"
    );
    $stmt->execute(array($cpf));
    $client = $stmt->fetch();
    if ($client && $client['name']) {
        return array('fonte' => 'portal', 'dados' => array(
            'nome'         => $client['name'],
            'cpf'          => $client['cpf'],
            'rg'           => $client['rg'],
            'nascimento'   => $client['birth_date'],
            'profissao'    => $client['profession'],
            'estado_civil' => $client['marital_status'],
            'genero'       => $client['gender'],
            'nacionalidade'=> $client['nacionalidade'],
            'email'        => $client['email'],
            'telefone'     => $client['phone'],
            'telefone2'    => $client['phone2'],
            'endereco'     => $client['address_street'],
            'cidade'       => $client['address_city'],
            'uf'           => $client['address_state'],
            'cep'          => $client['address_zip'],
            'pix'          => $client['pix_key'],
            'filhos'       => $client['children_names'],
            'client_id'    => (int)$client['id'],
        ));
    }

    // Camada 3b: base interna — case_partes
    $stmt2 = $pdo->prepare(
        "SELECT nome, cpf, rg, nascimento, profissao, estado_civil, email, telefone,
                endereco, cidade, uf, cep, client_id
         FROM case_partes WHERE cpf = ? OR cpf = ? LIMIT 1"
    );
    $stmt2->execute(array($cpf, $cpfFmt));
    $parte = $stmt2->fetch();
    if ($parte && $parte['nome']) {
        return array('fonte' => 'partes', 'dados' => array(
            'nome'         => $parte['nome'],
            'cpf'          => $parte['cpf'],
            'rg'           => $parte['rg'],
            'nascimento'   => $parte['nascimento'],
            'profissao'    => $parte['profissao'],
            'estado_civil' => $parte['estado_civil'],
            'email'        => $parte['email'],
            'telefone'     => $parte['telefone'],
            'endereco'     => $parte['endereco'],
            'cidade'       => $parte['cidade'],
            'uf'           => $parte['uf'],
            'cep'          => $parte['cep'],
            'client_id'    => $parte['client_id'] ? (int)$parte['client_id'] : null,
        ));
    }

    // Camada 4: cache (30 dias) — só consulta se não achou na base interna
    try {
        $stmt = $pdo->prepare("SELECT dados FROM cpf_cache WHERE cpf = ? AND consultado_em > DATE_SUB(NOW(), INTERVAL 30 DAY)");
        $stmt->execute(array($cpf));
        $cached = $stmt->fetchColumn();
        if ($cached) {
            $dados = json_decode($cached, true);
            if ($dados) {
                return array('fonte' => 'cache', 'dados' => $dados);
            }
        }
    } catch (Exception $e) {}

    // Camada 5: API externa cpfcnpj.com.br
    $token = _cpfcnpj_token();
    if ($token) {
        $pacote = _cpfcnpj_pacote();
        $ch = curl_init("https://api.cpfcnpj.com.br/{$token}/{$pacote}/{$cpf}");
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'FES-Hub/1.0',
        ));
        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && $resp) {
            $extData = json_decode($resp, true);
            if (isset($extData['nome']) && $extData['nome'] && !isset($extData['erro'])) {
                $nascimento = isset($extData['nascimento']) ? $extData['nascimento'] : null;
                // Converter dd/mm/yyyy → yyyy-mm-dd
                if ($nascimento && preg_match('#(\d{2})/(\d{2})/(\d{4})#', $nascimento, $nm)) {
                    $nascimento = $nm[3] . '-' . $nm[2] . '-' . $nm[1];
                }
                $dados = array(
                    'nome'       => $extData['nome'],
                    'nascimento' => $nascimento,
                    'mae'        => isset($extData['mae']) ? $extData['mae'] : null,
                    'situacao'   => isset($extData['situacao']) ? $extData['situacao'] : null,
                    'genero'     => isset($extData['genero']) ? $extData['genero'] : null,
                );

                // Salvar no cache
                _cpf_cache_salvar($cpf, $dados);

                return array('fonte' => 'receita', 'dados' => $dados);
            }
        }
    }

    return array('erro' => 'Dados não encontrados');
}

// ─── Busca CNPJ ────────────────────────────────────────

function buscar_cnpj($cnpj)
{
    $cnpj = preg_replace('/\D/', '', $cnpj);

    if (!validar_digitos_cnpj($cnpj)) {
        return array('erro' => 'CNPJ inválido');
    }

    // ReceitaWS — gratuito
    $ch = curl_init("https://www.receitaws.com.br/v1/cnpj/{$cnpj}");
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => array('Accept: application/json'),
    ));
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && $resp) {
        $dados = json_decode($resp, true);
        if ($dados && (!isset($dados['status']) || $dados['status'] !== 'ERROR')) {
            return array('fonte' => 'receita', 'dados' => array(
                'razao_social'  => isset($dados['nome']) ? $dados['nome'] : '',
                'nome_fantasia' => isset($dados['fantasia']) ? $dados['fantasia'] : '',
                'situacao'      => isset($dados['situacao']) ? $dados['situacao'] : '',
                'logradouro'    => isset($dados['logradouro']) ? $dados['logradouro'] : '',
                'numero'        => isset($dados['numero']) ? $dados['numero'] : '',
                'bairro'        => isset($dados['bairro']) ? $dados['bairro'] : '',
                'municipio'     => isset($dados['municipio']) ? $dados['municipio'] : '',
                'uf'            => isset($dados['uf']) ? $dados['uf'] : '',
                'cep'           => isset($dados['cep']) ? $dados['cep'] : '',
                'email'         => isset($dados['email']) ? $dados['email'] : '',
                'telefone'      => isset($dados['telefone']) ? $dados['telefone'] : '',
                'representante' => (isset($dados['qsa']) && !empty($dados['qsa'])) ? $dados['qsa'][0]['nome'] : '',
            ));
        }
    }

    return array('erro' => 'CNPJ não encontrado');
}

// ─── Helpers internos ──────────────────────────────────

function _cpfcnpj_token()
{
    static $token = null;
    if ($token !== null) return $token;
    try {
        $stmt = db()->prepare("SELECT valor FROM configuracoes WHERE chave = 'cpfcnpj_api_token'");
        $stmt->execute();
        $row = $stmt->fetch();
        $token = $row ? $row['valor'] : '9320d4099cf4099528cce511241c48a0';
    } catch (Exception $e) {
        $token = '9320d4099cf4099528cce511241c48a0';
    }
    return $token;
}

function _cpfcnpj_pacote()
{
    static $pacote = null;
    if ($pacote !== null) return $pacote;
    try {
        $stmt = db()->prepare("SELECT valor FROM configuracoes WHERE chave = 'cpfcnpj_pacote'");
        $stmt->execute();
        $row = $stmt->fetch();
        $pacote = $row ? $row['valor'] : '1';
    } catch (Exception $e) {
        $pacote = '1';
    }
    return $pacote;
}

function _cpf_cache_salvar($cpf, $dados)
{
    try {
        db()->prepare(
            "INSERT INTO cpf_cache (cpf, dados, consultado_em) VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE dados = VALUES(dados), consultado_em = NOW()"
        )->execute(array($cpf, json_encode($dados, JSON_UNESCAPED_UNICODE)));
    } catch (Exception $e) { /* tabela pode não existir */ }
}

// ─── Busca unificada (CPF ou CNPJ) ────────────────────

function buscar_documento($doc)
{
    $doc = preg_replace('/\D/', '', $doc);
    if (strlen($doc) === 11) return buscar_cpf($doc);
    if (strlen($doc) === 14) return buscar_cnpj($doc);
    return array('erro' => 'Documento deve ter 11 (CPF) ou 14 (CNPJ) dígitos');
}
