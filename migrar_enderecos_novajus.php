<?php
/**
 * Ferreira & Sá Conecta — Migração de Endereços do Novajus
 *
 * Script de migração única para importar endereços de clientes
 * exportados do sistema Novajus para a tabela clients do Conecta.
 *
 * Uso:
 *   ?key=fsa-hub-deploy-2026&mode=test   (simula, não altera banco)
 *   ?key=fsa-hub-deploy-2026&mode=run    (executa de fato)
 */

// ─── Autenticação por chave ────────────────────────────────
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') {
    http_response_code(403);
    exit('Chave invalida');
}

// ─── Core ──────────────────────────────────────────────────
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_utils.php';

header('Content-Type: text/plain; charset=utf-8');

$mode = isset($_GET['mode']) ? $_GET['mode'] : 'test';
if (!in_array($mode, array('test', 'run'), true)) {
    $mode = 'test';
}

echo "=== MIGRAÇÃO DE ENDEREÇOS NOVAJUS ===\n";
echo "Modo: " . strtoupper($mode) . "\n";
echo "Data: " . date('Y-m-d H:i:s') . "\n";
echo str_repeat('=', 50) . "\n\n";

// ─── Helpers ───────────────────────────────────────────────

function format_cep_migration($cep) {
    $cep = preg_replace('/\D/', '', $cep);
    if (strlen($cep) === 8) {
        return substr($cep, 0, 5) . '-' . substr($cep, 5, 3);
    }
    return $cep;
}

function build_street($street, $complement, $neighborhood) {
    $result = trim($street);
    if ($complement !== '' && $complement !== null) {
        $result .= ', ' . trim($complement);
    }
    if ($neighborhood !== '' && $neighborhood !== null) {
        $result .= ' - ' . trim($neighborhood);
    }
    return $result;
}

function find_client_migration($pdo, $name) {
    // Tentativa 1: match exato normalizado
    $stmt = $pdo->prepare("SELECT id, name, address_street FROM clients WHERE UPPER(TRIM(REPLACE(REPLACE(name, '  ', ' '), '  ', ' '))) = UPPER(TRIM(REPLACE(REPLACE(?, '  ', ' '), '  ', ' '))) LIMIT 1");
    $stmt->execute(array($name));
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($client) {
        return $client;
    }

    // Tentativa 2: fuzzy com primeiro + último nome
    $words = explode(' ', trim($name));
    if (count($words) >= 2) {
        $first = $words[0];
        $last = end($words);
        $stmt2 = $pdo->prepare("SELECT id, name, address_street FROM clients WHERE UPPER(name) LIKE ? AND UPPER(name) LIKE ? LIMIT 1");
        $stmt2->execute(array('%' . strtoupper($first) . '%', '%' . strtoupper($last) . '%'));
        $client = $stmt2->fetch(PDO::FETCH_ASSOC);
        if ($client) {
            return $client;
        }
    }

    return null;
}

// ─── Dados do Novajus (PDF exportado, 86 páginas) ─────────

$addresses = array(
    array('name' => 'ADEMILSON JOSE DA SILVA', 'street' => 'Rua dos Prazeres, 02', 'complement' => '', 'neighborhood' => 'Nova Angra (Cunhambebe)', 'city' => 'Angra dos Reis', 'state' => 'RJ', 'zip' => '23933-120'),
    array('name' => 'ADEVANIS IRENE DEPTUSKI SALLES', 'street' => 'Rua Cento e Onze, 273', 'complement' => 'Nova rosa da penha 2', 'neighborhood' => 'Nova Rosa da Penha', 'city' => 'Cariacica', 'state' => 'ES', 'zip' => '29157-280'),
    array('name' => 'ADILY SABRINE ALVES PEDROSO', 'street' => 'Rua Padre Pedro Guerra, 262', 'complement' => '', 'neighborhood' => 'Rio Pequeno', 'city' => 'São José dos Pinhais', 'state' => 'PR', 'zip' => '83070-300'),
    array('name' => 'ADRIANA BARROS DE ARAUJO', 'street' => 'Estrada de Jacarepaguá, 3671', 'complement' => 'Bl 1 Ap 501', 'neighborhood' => 'Jacarepaguá', 'city' => 'Rio de Janeiro', 'state' => 'RJ', 'zip' => '22753-212'),
    array('name' => 'ADRIANE MENDONÇA TRINDADE', 'street' => 'Rua da Torre, 40', 'complement' => 'CASA/CAIXA', 'neighborhood' => 'Novo Horizonte', 'city' => 'Tomé-Açu', 'state' => 'PA', 'zip' => '68680-000'),
    array('name' => 'Adriano Bernardes Ferreira', 'street' => 'Avenida Chet Miller, 906', 'complement' => '', 'neighborhood' => 'Ponte Alta', 'city' => 'Betim', 'state' => 'MG', 'zip' => '32605-748'),
    array('name' => 'Adriano da Silva Gomes', 'street' => 'Papa Paulo VI, 31', 'complement' => 'Ap 401', 'neighborhood' => 'Jardim Amália', 'city' => 'Volta Redonda', 'state' => 'RJ', 'zip' => '27251-340'),
    array('name' => 'ADRIANO LUIS DE OLIVEIRA', 'street' => 'Rua Joaquim Alves Tavares, 46', 'complement' => '', 'neighborhood' => 'São João', 'city' => 'Conselheiro Lafaiete', 'state' => 'MG', 'zip' => '36404-148'),
    array('name' => 'ADRIANO MATHEUS LEONCIO DE JESUS', 'street' => 'Rua da Assembléia, 109', 'complement' => '', 'neighborhood' => 'Jardim Esperança', 'city' => 'Cabo Frio', 'state' => 'RJ', 'zip' => '28920-245'),
    array('name' => 'Adriano Silva de Souza', 'street' => 'Rua Lanterneiras, 292', 'complement' => '', 'neighborhood' => 'Vila Iguaçuana', 'city' => 'Nova Iguaçu', 'state' => 'RJ', 'zip' => '26051-010'),
    array('name' => 'ADRIELE MOREIRA SÃO MARTINHO', 'street' => 'Rua Virgem Peregrina, 114', 'complement' => '', 'neighborhood' => 'Piedade', 'city' => 'Rio de Janeiro', 'state' => 'RJ', 'zip' => '21381-070'),
    array('name' => 'AGATHA DA SILVA QUEIROZ', 'street' => 'Rua Expedicionário Jalber Coelho da Silva, 375', 'complement' => '', 'neighborhood' => 'Morro do Gama', 'city' => 'Barra do Piraí', 'state' => 'RJ', 'zip' => '27150-510'),
    array('name' => 'AGHATA STEFANY NASCIMENTO DA SILVA', 'street' => 'Rua Otaviano Franca, 27', 'complement' => '', 'neighborhood' => 'Vila Silvânia', 'city' => 'Carapicuíba', 'state' => 'SP', 'zip' => '06317-120'),
    array('name' => 'AILA DOS SANTOS GAIA', 'street' => 'Rua Belkiss, 101', 'complement' => 'Lote 101 Quadra 35', 'neighborhood' => 'Coelho da Rocha', 'city' => 'São João de Meriti', 'state' => 'RJ', 'zip' => '25550-590'),
    array('name' => 'AILANDA ALINE LESSA FARIA', 'street' => 'Hugo Tavares, 64', 'complement' => '', 'neighborhood' => 'Goitacazes', 'city' => 'Campos dos Goytacazes', 'state' => 'RJ', 'zip' => '28110-000'),
    array('name' => 'ALAN CRISTINO FRANCINI', 'street' => 'Rua Capitão Menezes, 409', 'complement' => '', 'neighborhood' => 'Praça Seca', 'city' => 'Rio de Janeiro', 'state' => 'RJ', 'zip' => '21320-040'),
    array('name' => 'ALANE SANTOS OLIVEIRA', 'street' => 'Rua das Palmeiras, 13', 'complement' => '', 'neighborhood' => 'Mar do Norte', 'city' => 'Rio das Ostras', 'state' => 'RJ', 'zip' => '28898-008'),
    array('name' => 'Aldimeira Maria Silva Dantas Moura', 'street' => 'Avenida Francisco Mastropietro, 3275', 'complement' => '', 'neighborhood' => 'Jardim Popular', 'city' => 'Matão', 'state' => 'SP', 'zip' => '15997-150'),
    array('name' => 'ALDO DE SOUZA LOURENÇO', 'street' => 'Rua General Silva Bittencourt, 43', 'complement' => '', 'neighborhood' => 'Independência (Agulhas Negras)', 'city' => 'Resende', 'state' => 'RJ', 'zip' => '27533-340'),
    array('name' => 'Alessandra de Farias Candido', 'street' => 'Rua Rubênia, 977', 'complement' => '', 'neighborhood' => 'Bom Pastor', 'city' => 'Belford Roxo', 'state' => 'RJ', 'zip' => '26110-280'),
    array('name' => 'ALESSANDRA RIBEIRO DA SILVA', 'street' => 'Área Rural', 'complement' => '', 'neighborhood' => 'Área Rural de Manacapuru', 'city' => 'Manacapuru', 'state' => 'AM', 'zip' => '69409-899'),
    array('name' => 'ALESSANDRA SOUSA MELLO SILVA', 'street' => 'Benedito Rufino Xavier, 360', 'complement' => '', 'neighborhood' => 'São Judas Tadeu', 'city' => 'Borda da Mata', 'state' => 'MG', 'zip' => '37564-000'),
    array('name' => 'ALEX SANDER LIMA REGUS', 'street' => 'Rua Lino Estácio dos Santos, 2370', 'complement' => '', 'neighborhood' => 'Oriço', 'city' => 'Gravataí', 'state' => 'RS', 'zip' => '94010-400'),
    array('name' => 'ALEX VEIGA FERNANDES', 'street' => 'Área Rural, s/n', 'complement' => '', 'neighborhood' => 'Área Rural de Nova Friburgo', 'city' => 'Nova Friburgo', 'state' => 'RJ', 'zip' => '28636-899'),
    array('name' => 'ALEXANDRA MARIA DOS SANTOS', 'street' => 'Rua Elmo da Costa Caçador, 174', 'complement' => '', 'neighborhood' => 'Bela Vista', 'city' => 'São João Nepomuceno', 'state' => 'MG', 'zip' => '36684-406'),
    array('name' => 'ALEXANDRE LUIZ DA SILVA GARCIA', 'street' => 'Rua 13, 79', 'complement' => '', 'neighborhood' => 'Varjão', 'city' => 'Piraí', 'state' => 'RJ', 'zip' => '27175-000'),
    array('name' => 'ALEXANDRO DA SILVA MARTINS', 'street' => 'Rua Perimetral Norte, 463', 'complement' => '', 'neighborhood' => 'Cidade Alegria', 'city' => 'Resende', 'state' => 'RJ', 'zip' => '27525-031'),
    array('name' => 'ALEXSANDRO DA SILVA SANTANA', 'street' => 'Rua João Ventura, 491', 'complement' => '', 'neighborhood' => 'Segredo', 'city' => 'Guapimirim', 'state' => 'RJ', 'zip' => '25946-700'),
    array('name' => 'Alice Carvalho', 'street' => 'Rua B, 187', 'complement' => '', 'neighborhood' => 'Santo Amaro', 'city' => 'Resende', 'state' => 'RJ', 'zip' => '27513-060'),
    array('name' => 'Alícia de Carvalho Wogel', 'street' => 'Rua da Figueira, 273', 'complement' => '', 'neighborhood' => 'Werneck', 'city' => 'Paraíba do Sul', 'state' => 'RJ', 'zip' => '25850-000'),
    array('name' => 'ALINE APARECIDA DA SILVA', 'street' => 'Avenida Professora Carmem Carneiro, 48', 'complement' => 'Rua 3 - z1 estrada do brejo grande', 'neighborhood' => 'Parque Aeroporto', 'city' => 'Campos dos Goytacazes', 'state' => 'RJ', 'zip' => '28090-115'),
    array('name' => 'ALINE APARECIDA DE SOUZA VIEIRA', 'street' => 'Rua Estrada Miguel Pereira, 94', 'complement' => 'casa 2', 'neighborhood' => 'Massambará', 'city' => 'Vassouras', 'state' => 'RJ', 'zip' => '27700-000'),
    array('name' => 'ALINE APARECIDA RODRIGUES DA SILVA', 'street' => 'Rua Ari Jorge Fonseca Ramos, 200', 'complement' => '', 'neighborhood' => 'Parque Independência', 'city' => 'Barra Mansa', 'state' => 'RJ', 'zip' => '27325-110'),
    array('name' => 'ALINE FERNANDES CARVALHO', 'street' => 'Rua Furquim Mendes, 370', 'complement' => '', 'neighborhood' => 'Vigário Geral', 'city' => 'Rio de Janeiro', 'state' => 'RJ', 'zip' => '21241-340'),
    array('name' => 'ALINE FERNANDES DE CARVALHO', 'street' => 'Rua Campestre', 'complement' => '', 'neighborhood' => 'Campo do Coelho', 'city' => 'Nova Friburgo', 'state' => 'RJ', 'zip' => '28630-540'),
    array('name' => 'ALINE MENEZES CARREIRA', 'street' => 'Rua Maranata, 14', 'complement' => '', 'neighborhood' => 'Nova Angra (Cunhambebe)', 'city' => 'Angra dos Reis', 'state' => 'RJ', 'zip' => '23933-155'),
    array('name' => 'ALINE MORAIS DOS SANTOS', 'street' => 'Rua Todos os Santos, 336', 'complement' => '', 'neighborhood' => 'Vila São Paulo', 'city' => 'Mogi das Cruzes', 'state' => 'SP', 'zip' => '08840-090'),
    array('name' => 'Alisson Alencar David', 'street' => 'Praça General Tiburcio, 83', 'complement' => '', 'neighborhood' => 'Urca', 'city' => 'Rio de Janeiro', 'state' => 'RJ', 'zip' => '22290-270'),
    array('name' => 'Allan do Carmo Silva', 'street' => 'Rua Dona Moura, 128', 'complement' => '', 'neighborhood' => 'Adrianópolis', 'city' => 'Nova Iguaçu', 'state' => 'RJ', 'zip' => '26053-710'),
    array('name' => 'ALLAN ROSA LOUZADA COSTA', 'street' => 'Rua Ceará, 157', 'complement' => '', 'neighborhood' => 'Morada do Contorno', 'city' => 'Resende', 'state' => 'RJ', 'zip' => '27525-676'),
    array('name' => 'ALYSSON FREITAS DE AMURIM', 'street' => 'Sitio boa sorte, s/n', 'complement' => '', 'neighborhood' => 'Vila Santo Antônio', 'city' => 'Acopiara', 'state' => 'CE', 'zip' => '63560-000'),
    array('name' => 'AMABELI FERNANDA CORDEIRO', 'street' => 'Rua Magnólia, 134', 'complement' => '', 'neighborhood' => 'Vila Matilde', 'city' => 'Nova Iguaçu', 'state' => 'RJ', 'zip' => '26053-460'),
    array('name' => 'AMANDA CRISTINA DE MIRANDA ROCHA', 'street' => 'Rua Cândido Lima, 423', 'complement' => '', 'neighborhood' => 'Austin', 'city' => 'Nova Iguaçu', 'state' => 'RJ', 'zip' => '26087-130'),
    array('name' => 'Amanda de Castro', 'street' => 'Rua Nossa Senhora das Graças, 39', 'complement' => '', 'neighborhood' => 'Charitas', 'city' => 'Niterói', 'state' => 'RJ', 'zip' => '24370-630'),
    array('name' => 'AMANDA FERREIRA DE LIMA', 'street' => 'Rua Fruhbeck, 55', 'complement' => '', 'neighborhood' => 'Coelho Neto', 'city' => 'Rio de Janeiro', 'state' => 'RJ', 'zip' => '21530-300'),
    array('name' => 'Amanda Ferreira Fernandes', 'street' => 'Estrada nova dores de Macabu', 'complement' => '', 'neighborhood' => 'Dores de Macabu', 'city' => 'Campos dos Goytacazes', 'state' => 'RJ', 'zip' => '28115-000'),
    array('name' => 'AMANDA GOMES DE MELLO', 'street' => 'Rua Agai, 27', 'complement' => 'Nogueira - Bosque de Palmares', 'neighborhood' => 'Paciência', 'city' => 'Rio de Janeiro', 'state' => 'RJ', 'zip' => '23065-620'),
    array('name' => 'AMANDA GOMES DE OLIVEIRA', 'street' => 'Rua Sodré, 05', 'complement' => '', 'neighborhood' => 'Alto da Boa Vista', 'city' => 'Rio de Janeiro', 'state' => 'RJ', 'zip' => '20535-380'),
    array('name' => 'Amanda Guedes Ferreira', 'street' => 'Rua Jorge Gonçalves Pereira, 350', 'complement' => '', 'neighborhood' => 'Morada da Colina', 'city' => 'Volta Redonda', 'state' => 'RJ', 'zip' => '27250-515'),
    array('name' => 'Amanda Lima dos Santos', 'street' => 'Avenida Almirante Jaceguai', 'complement' => 'Lote 29, quadra 55', 'neighborhood' => 'Vila Rosário', 'city' => 'Duque de Caxias', 'state' => 'RJ', 'zip' => '25040-330'),
    array('name' => 'ANA CARLA DOS SANTOS HILÁRIO', 'street' => 'Rua Cassiano Antônio, 255', 'complement' => '', 'neighborhood' => 'Cantagalo', 'city' => 'Três Rios', 'state' => 'RJ', 'zip' => '25806-130'),
    array('name' => 'ANA CAROLINA BRAGANÇA PINTO', 'street' => 'Br101 kl 257, s/n', 'complement' => '', 'neighborhood' => 'Mangueira', 'city' => 'Rio Bonito', 'state' => 'RJ', 'zip' => '28800-000'),
    array('name' => 'ANA CAROLINA DA SILVA', 'street' => 'Rua Dezessete, 0', 'complement' => 'Fazenda', 'neighborhood' => 'Morada da Barra', 'city' => 'Resende', 'state' => 'RJ', 'zip' => '27540-532'),
    array('name' => 'Ana Carolina Pereira Laranjeira', 'street' => 'Rua do Amor, 183', 'complement' => '', 'neighborhood' => 'Mirante de Serra', 'city' => 'Resende', 'state' => 'RJ', 'zip' => '27521-595'),
    array('name' => 'ANA CAROLINA VARGAS DO NASCIMENTO', 'street' => 'Rua Tarento, 209', 'complement' => '', 'neighborhood' => 'Parque Veneza (Vila Inhomirim)', 'city' => 'Magé', 'state' => 'RJ', 'zip' => '25930-795'),
    array('name' => 'Ana Caroline Carvalho Neris Lourenço', 'street' => 'Rua General Silva Bittencourt, 43', 'complement' => '', 'neighborhood' => 'Independência (Agulhas Negras)', 'city' => 'Resende', 'state' => 'RJ', 'zip' => '27533-340'),
    array('name' => 'ANA CAROLINE DIAS PEREIRA', 'street' => 'Rua Martins Teixeira, 20', 'complement' => 'Lote 20, Quadra 53', 'neighborhood' => 'Bom Pastor', 'city' => 'Belford Roxo', 'state' => 'RJ', 'zip' => '26110-360'),
    array('name' => 'Ana Clara Garcia', 'street' => 'Av. Hr Pritchard, 625', 'complement' => 'Casa', 'neighborhood' => 'Vila Marina Bulhões', 'city' => 'Porto Real', 'state' => 'RJ', 'zip' => '27570-000'),
    array('name' => 'ANA CLAUDIA DOS SANTOS', 'street' => 'Rua Javata, 07', 'complement' => '', 'neighborhood' => 'Costa Barros', 'city' => 'Rio de Janeiro', 'state' => 'RJ', 'zip' => '21650-190'),
    array('name' => 'ANA CLAUDIA GUIMARÃES DA SILVA', 'street' => 'Rua dos Eucaliptos, 332', 'complement' => 'Sobrado', 'neighborhood' => 'Cidade Alegria', 'city' => 'Resende', 'state' => 'RJ', 'zip' => '27500-001'),
    array('name' => 'ANA KAROLINY CALAZANS DE ANDRADE', 'street' => 'Rua Saul Neto, S/N', 'complement' => '', 'neighborhood' => 'Alcântara', 'city' => 'São Gonçalo', 'state' => 'RJ', 'zip' => '24711-130'),
    array('name' => 'Ana Leticia Lamas de Souza', 'street' => 'Rua José Espindola de Mendonça, 460', 'complement' => '', 'neighborhood' => 'Barão de Angra', 'city' => 'Paraíba do Sul', 'state' => 'RJ', 'zip' => '25850-000'),
    array('name' => 'Ana Maria Cipriano de Jesus Souza', 'street' => 'Av. dos Ypes, 733', 'complement' => 'Casa', 'neighborhood' => 'Vila Pinheiro', 'city' => 'Itatiaia', 'state' => 'RJ', 'zip' => '27580-000'),
    array('name' => 'Ana Maria Silveira da Silva', 'street' => 'Rua Antenor Barbosa Rego, 215', 'complement' => '', 'neighborhood' => 'Oficinas Velhas', 'city' => 'Barra do Piraí', 'state' => 'RJ', 'zip' => '27110-250'),
    array('name' => 'ANA PAULA ALMEIDA DINIZ', 'street' => 'Rua João Ferreira da Paz, 191', 'complement' => '', 'neighborhood' => 'Jardim D\'Oeste', 'city' => 'Resende', 'state' => 'RJ', 'zip' => '27525-640'),
    array('name' => 'Ana Paula Bernardo dos Reis', 'street' => 'Rua Carlos Herculano Couto, 150', 'complement' => '', 'neighborhood' => 'Francisco Bernardino', 'city' => 'Juiz de Fora', 'state' => 'MG', 'zip' => '36081-680'),
    array('name' => 'ANALICE ELIAS DA SILVA', 'street' => 'Rua Dona Marlene, 60', 'complement' => 'Casa 02', 'neighborhood' => 'Shangri-lá', 'city' => 'Belford Roxo', 'state' => 'RJ', 'zip' => '26150-150'),

    // ... dados continuam - ver PDF completo para todos os registros
    // Total estimado: ~860 registros das 86 páginas do PDF Novajus
    // Para adicionar mais registros, copie o formato acima.
);

// ─── Processamento ─────────────────────────────────────────

$pdo = db();

$totalProcessed = 0;
$totalUpdated = 0;
$totalSkipped = 0;
$totalNotFound = 0;
$namesNotFound = array();
$namesSkipped = array();

// Em modo teste, processar apenas os primeiros 10
$dataToProcess = ($mode === 'test') ? array_slice($addresses, 0, 10) : $addresses;

echo "Registros a processar: " . count($dataToProcess) . " de " . count($addresses) . " total\n\n";

try {
    $pdo->beginTransaction();

    foreach ($dataToProcess as $i => $entry) {
        $totalProcessed++;
        $num = $i + 1;
        $name = trim($entry['name']);

        echo "[{$num}] {$name}... ";

        // Buscar cliente
        $client = find_client_migration($pdo, $name);

        if ($client === null) {
            $totalNotFound++;
            $namesNotFound[] = $name;
            echo "NAO ENCONTRADO\n";
            continue;
        }

        // Verificar se já tem endereço
        $currentStreet = trim($client['address_street'] ? $client['address_street'] : '');
        if ($currentStreet !== '') {
            $totalSkipped++;
            $namesSkipped[] = $name . ' (ID ' . $client['id'] . ' - já tem: ' . $currentStreet . ')';
            echo "JA TEM ENDERECO (ID {$client['id']}) - PULADO\n";
            continue;
        }

        // Montar endereço
        $addressStreet = build_street($entry['street'], $entry['complement'], $entry['neighborhood']);
        $addressCity = trim($entry['city']);
        $addressState = trim($entry['state']);
        $addressZip = format_cep_migration($entry['zip']);

        if ($mode === 'run') {
            // UPDATE apenas campos vazios/nulos
            $stmtUpdate = $pdo->prepare(
                "UPDATE clients SET
                    address_street = CASE WHEN (address_street IS NULL OR address_street = '') THEN ? ELSE address_street END,
                    address_city   = CASE WHEN (address_city IS NULL OR address_city = '')     THEN ? ELSE address_city END,
                    address_state  = CASE WHEN (address_state IS NULL OR address_state = '')   THEN ? ELSE address_state END,
                    address_zip    = CASE WHEN (address_zip IS NULL OR address_zip = '')       THEN ? ELSE address_zip END
                WHERE id = ?"
            );
            $stmtUpdate->execute(array(
                $addressStreet,
                $addressCity,
                $addressState,
                $addressZip,
                $client['id']
            ));

            // Auditoria
            audit_log('address_imported', 'client', (int)$client['id'], 'Novajus import');

            echo "ATUALIZADO (ID {$client['id']}) -> {$addressStreet}, {$addressCity}/{$addressState} {$addressZip}\n";
        } else {
            echo "SIMULADO (ID {$client['id']}) -> {$addressStreet}, {$addressCity}/{$addressState} {$addressZip}\n";
        }

        $totalUpdated++;
    }

    if ($mode === 'run') {
        $pdo->commit();
        echo "\n>>> COMMIT realizado com sucesso.\n";
    } else {
        $pdo->rollBack();
        echo "\n>>> ROLLBACK (modo teste, nenhuma alteracao foi salva).\n";
    }

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "\n!!! ERRO: " . $e->getMessage() . "\n";
    echo ">>> ROLLBACK realizado. Nenhuma alteracao foi salva.\n";
    exit(1);
}

// ─── Resumo ────────────────────────────────────────────────

echo "\n" . str_repeat('=', 50) . "\n";
echo "RESUMO DA MIGRAÇÃO\n";
echo str_repeat('=', 50) . "\n";
echo "Modo:                     " . strtoupper($mode) . "\n";
echo "Total processados:        {$totalProcessed}\n";
echo "Encontrados e atualizados: {$totalUpdated}\n";
echo "Já tinham endereço (pulados): {$totalSkipped}\n";
echo "Não encontrados:          {$totalNotFound}\n";
echo str_repeat('-', 50) . "\n";

if (count($namesNotFound) > 0) {
    echo "\nNOMES NAO ENCONTRADOS:\n";
    foreach ($namesNotFound as $n) {
        echo "  - {$n}\n";
    }
}

if (count($namesSkipped) > 0) {
    echo "\nPULADOS (JA TINHAM ENDERECO):\n";
    foreach ($namesSkipped as $s) {
        echo "  - {$s}\n";
    }
}

echo "\n--- Fim da migração ---\n";
