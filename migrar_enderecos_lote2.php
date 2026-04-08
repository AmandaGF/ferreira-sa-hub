<?php
/**
 * Ferreira & Sá Conecta — Migração de Endereços do Novajus (Lote 2)
 *
 * Script de migração para importar endereços de clientes
 * exportados do sistema Novajus para a tabela clients do Conecta.
 * Lote 2: registros das páginas 2-86 do PDF (após os 67 primeiros).
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

echo "=== MIGRAÇÃO DE ENDEREÇOS NOVAJUS (LOTE 2) ===\n";
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

// ─── Dados do Novajus — Lote 2 (páginas 2-86, ~120 registros) ───

$addresses = array(
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
    array('name' => 'ALESSANDRA RIBEIRO DA SILVA', 'street' => 'Área Rural', 'complement' => 'Canabuoca 1', 'neighborhood' => 'Área Rural de Manacapuru', 'city' => 'Manacapuru', 'state' => 'AM', 'zip' => '69409-899'),
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
    array('name' => 'Anderson de Oliveira Silva', 'street' => 'Rua Padre Josino, 19 F', 'complement' => 'Casa', 'neighborhood' => 'Jardim Esperança', 'city' => 'Resende', 'state' => 'RJ', 'zip' => '27540-662'),
    array('name' => 'Anderson Silva de Souza Candido', 'street' => 'Rua das Magnólias - de 379/380 a 802/803, 646', 'complement' => 'casa 2', 'neighborhood' => 'Cidade Alegria', 'city' => 'Resende', 'state' => 'RJ', 'zip' => '27525-121'),
    array('name' => 'ANDRE CARIUS DA SILVA', 'street' => 'Rua do Bingue, 435', 'complement' => '', 'neighborhood' => 'Morro da Vaca', 'city' => 'Vassouras', 'state' => 'RJ', 'zip' => '27700-000'),
    array('name' => 'ANDRÉ CARLOS DE ANDRADE JUNIOR', 'street' => 'Rua Osório Gomes de Brito, 1009', 'complement' => 'Vila Nova', 'neighborhood' => 'Água Comprida', 'city' => 'Barra Mansa', 'state' => 'RJ', 'zip' => '27321-580'),
    array('name' => 'ANDRE VICTOR DE FREITAS', 'street' => 'Rua Antônio Lago, 826', 'complement' => '', 'neighborhood' => 'Campo Grande', 'city' => 'Rio de Janeiro', 'state' => 'RJ', 'zip' => '23070-550'),
    array('name' => 'ANDREIA VAINER CARDOSO PEREIRA', 'street' => 'Estrada Eliseu de Alvarenga, 1683', 'complement' => 'Casa 11', 'neighborhood' => 'Centro', 'city' => 'Nilópolis', 'state' => 'RJ', 'zip' => '26525-101'),
    array('name' => 'Andreina Mendes Finamor', 'street' => 'Avenida Duque de Caxias, 1887', 'complement' => '', 'neighborhood' => 'Deodoro', 'city' => 'Rio de Janeiro', 'state' => 'RJ', 'zip' => '21615-220'),
    array('name' => 'Andressia Cristina Fonseca Domingues', 'street' => 'Avenida Francisco Fortes Filho, 1509', 'complement' => 'Bloco', 'neighborhood' => 'Mirante de Serra', 'city' => 'Resende', 'state' => 'RJ', 'zip' => '27521-620'),
    array('name' => 'ANDREZA FARIA LEITE', 'street' => 'Avenida Luiz Corrêa da Silva, 207', 'complement' => '', 'neighborhood' => 'Boaçu', 'city' => 'São Gonçalo', 'state' => 'RJ', 'zip' => '24467-000'),
    array('name' => 'ANDREZZA ALVES DA SILVA DE OLIVEIRA', 'street' => 'Rua Vinte e Um, 18', 'complement' => '', 'neighborhood' => 'Unamar (Tamoios)', 'city' => 'Cabo Frio', 'state' => 'RJ', 'zip' => '28928-524'),
    array('name' => 'ANDRIELLE GUEDES DOS SANTOS', 'street' => 'Rua Da Felicidade, 6', 'complement' => 'Casa', 'neighborhood' => 'João Fernandes', 'city' => 'Armação dos Búzios', 'state' => 'RJ', 'zip' => '28950-001'),
    array('name' => 'ANDRIW PEIXOTO PEREIRA', 'street' => 'Rua Engenheiro Jacinto Lameira Filho, 199', 'complement' => '', 'neighborhood' => 'Barbosa Lima', 'city' => 'Resende', 'state' => 'RJ', 'zip' => '27511-630'),
    array('name' => 'Ane Caroline Prates da Silva', 'street' => 'Rua Cônego Maris, S/N', 'complement' => '', 'neighborhood' => 'Pavuna', 'city' => 'Rio de Janeiro', 'state' => 'RJ', 'zip' => '21535-040'),
    array('name' => 'ANESIO FRANCISCO DA SILVA', 'street' => 'Rua 13', 'complement' => '', 'neighborhood' => 'Jardim das Ácacias', 'city' => 'Porto Real', 'state' => 'RJ', 'zip' => '27570-000'),
    array('name' => 'Angela de Oliveira Louzada Sobral', 'street' => 'Rua Guarani, 59', 'complement' => 'Penedo', 'neighborhood' => 'Centro', 'city' => 'Itatiaia', 'state' => 'RJ', 'zip' => '27598-000'),
    array('name' => 'ANGELA RODRIGUES DE OLIVEIRA', 'street' => 'Rua Professor Hélio Viana, 240', 'complement' => 'Casa 3', 'neighborhood' => 'Realengo', 'city' => 'Rio de Janeiro', 'state' => 'RJ', 'zip' => '21775-190'),
    array('name' => 'ANGELICA ALVES GONÇALVES', 'street' => 'Travessa Assembléia, 14', 'complement' => 'Casa 8', 'neighborhood' => 'Portuguesa', 'city' => 'Rio de Janeiro', 'state' => 'RJ', 'zip' => '21931-610'),
    array('name' => 'ANGELICA DA SILVA ACIOLY', 'street' => 'Rua Fernando Tedesco, 496', 'complement' => '', 'neighborhood' => 'São Lucas', 'city' => 'Volta Redonda', 'state' => 'RJ', 'zip' => '27265-280'),
    array('name' => 'ANIK DANIELE MENEZES RIBEIRO', 'street' => 'Rua Lady Esteves da Conceição, 06', 'complement' => '', 'neighborhood' => 'Morro de São Jorge', 'city' => 'Macaé', 'state' => 'RJ', 'zip' => '27933-420'),
    array('name' => 'Anthony da Costa Oliveira', 'street' => 'Rua Romualdo Santos, s/n', 'complement' => '', 'neighborhood' => 'Vila Guimarães', 'city' => 'Nova Iguaçu', 'state' => 'RJ', 'zip' => '26088-270'),
    array('name' => 'ANTONIA CLAUDIA NUNES VIEIRA', 'street' => 'Rua Honduras, 406', 'complement' => '', 'neighborhood' => 'Parque Residencial Belinha Ometto', 'city' => 'Limeira', 'state' => 'SP', 'zip' => '13483-506'),
    array('name' => 'ANTONIA ESTEVAM GONÇALVES', 'street' => 'Rua Monsenhor Rios, 81', 'complement' => 'Apto 102', 'neighborhood' => 'Melo Afonso', 'city' => 'Vassouras', 'state' => 'RJ', 'zip' => '27700-000'),
    array('name' => 'Antoninha Neres', 'street' => 'Rua Suyas, 435', 'complement' => '', 'neighborhood' => 'Santa Cruz', 'city' => 'Cascavel', 'state' => 'PR', 'zip' => '85806-120'),
    array('name' => 'ANY CAROLINE DO NASCIMENTO', 'street' => 'Rua Djalma Dutra, 355', 'complement' => '', 'neighborhood' => 'Pilares', 'city' => 'Rio de Janeiro', 'state' => 'RJ', 'zip' => '20755-000'),
    array('name' => 'APOLIANA LIMA DE OLIVEIRA', 'street' => 'Santos Dumont, 83', 'complement' => '', 'neighborhood' => 'Pindorama', 'city' => 'Iuiú', 'state' => 'BA', 'zip' => '46439-500'),
    array('name' => 'Ariana de Jesus Lima Novais', 'street' => 'Rua Paraná, s/n', 'complement' => '', 'neighborhood' => 'Morada do Contorno', 'city' => 'Resende', 'state' => 'RJ', 'zip' => '27525-664'),
    array('name' => 'ARNALDO LUIS CORREA DA SILVA', 'street' => 'Rua José Maria de Amorim, s/n', 'complement' => '', 'neighborhood' => 'Jardim D\'Oeste', 'city' => 'Resende', 'state' => 'RJ', 'zip' => '27525-642'),
    array('name' => 'BEATRIZ DOS SANTOS OLIVEIRA', 'street' => 'Travessa Ururay, 318', 'complement' => '', 'neighborhood' => 'Infraero I', 'city' => 'Macapá', 'state' => 'AP', 'zip' => '68908-872'),
    array('name' => 'BEATRIZ ELIAS DE PEÑARANDA', 'street' => 'Avenida Rita Maria Ferreira da Rocha, 405', 'complement' => '', 'neighborhood' => 'Comercial', 'city' => 'Resende', 'state' => 'RJ', 'zip' => '27510-060'),
    array('name' => 'Beatriz Pedroza Lauro De Oliveira', 'street' => 'Rua Barata Ribeiro, 222', 'complement' => '612', 'neighborhood' => 'Copacabana', 'city' => 'Rio de Janeiro', 'state' => 'RJ', 'zip' => '22040-002'),
    array('name' => 'BEBIANA DE OLIVEIRA REIS', 'street' => 'Avenida Francisco Fortes Filho, 1509', 'complement' => '', 'neighborhood' => 'Mirante de Serra', 'city' => 'Resende', 'state' => 'RJ', 'zip' => '27521-620'),
    array('name' => 'Berenice Wanderley Soares', 'street' => 'Rua Santa Verônica, 88', 'complement' => '', 'neighborhood' => 'Brooklin Paulista', 'city' => 'São Paulo', 'state' => 'SP', 'zip' => '04557-040'),
    array('name' => 'Bianca Aparecida da Costa Mattos', 'street' => 'Rua Doutor João Cabral Flexa, 150', 'complement' => '', 'neighborhood' => 'Cabral', 'city' => 'Resende', 'state' => 'RJ', 'zip' => '27535-010'),
    array('name' => 'Bianca Nascimento Conceição', 'street' => 'Caminho das Mulheres, 145', 'complement' => '', 'neighborhood' => 'Nova Aurora', 'city' => 'Belford Roxo', 'state' => 'RJ', 'zip' => '26155-130'),
    array('name' => 'BIANCA YASMIM LEAL FERREIRA', 'street' => 'Rua dos Motoqueiros, 68', 'complement' => 'Pero', 'neighborhood' => 'Cajueiro', 'city' => 'Cabo Frio', 'state' => 'RJ', 'zip' => '28924-209'),
    array('name' => 'Brás Ramos Frias', 'street' => 'Rua Major Antônio Carvalho, 120', 'complement' => '', 'neighborhood' => 'Jardim Polastri', 'city' => 'Quatis', 'state' => 'RJ', 'zip' => '27570-000'),
    array('name' => 'BRENDA LUCIO PESSANHA PIO', 'street' => 'Rua José Maravilha, 02', 'complement' => '', 'neighborhood' => 'Virgem Santa', 'city' => 'Macaé', 'state' => 'RJ', 'zip' => '27948-055'),
    array('name' => 'Breno da Silva Amaral', 'street' => 'Rua Ângela, 135', 'complement' => '', 'neighborhood' => 'Vila Moderna', 'city' => 'Resende', 'state' => 'RJ', 'zip' => '27514-020'),
    array('name' => 'BRUNA APARECIDA ARRUDA DO NASCIMENTO', 'street' => 'Rua Camaipi, 35', 'complement' => 'Rua onze', 'neighborhood' => 'Campo Grande', 'city' => 'Rio de Janeiro', 'state' => 'RJ', 'zip' => '23052-320'),
    array('name' => 'Bruna Correa Serapiao', 'street' => 'Rua Antônio da Silva Lemos, 569', 'complement' => 'Casa ao lado', 'neighborhood' => 'Jardim Tropical', 'city' => 'Alfenas', 'state' => 'MG', 'zip' => '37133-580'),
    array('name' => 'BRUNA COSTA DE ASEVEDO', 'street' => 'Rua Jundiai, 07', 'complement' => 'Lote 07, quadra 71', 'neighborhood' => 'Jardim Gramacho', 'city' => 'Duque de Caxias', 'state' => 'RJ', 'zip' => '25051-070'),
    array('name' => 'Bruna Cristina Rodrigues dos Santos', 'street' => 'Rua Jardim Botânico, 15', 'complement' => '', 'neighborhood' => 'Mirante da Serra', 'city' => 'Resende', 'state' => 'RJ', 'zip' => ''),
    array('name' => 'BRUNA DOS SANTOS FAUSTINO', 'street' => 'Rua Carlos Chagas, 128', 'complement' => 'Apto 14', 'neighborhood' => 'Jardim Stella', 'city' => 'Santo André', 'state' => 'SP', 'zip' => '09185-650'),
    array('name' => 'BRUNA DOS SANTOS VENANCIO', 'street' => 'Rua Balmant, 196', 'complement' => '', 'neighborhood' => 'Parque Residencial Solares', 'city' => 'Nova Friburgo', 'state' => 'RJ', 'zip' => '28630-370'),
    array('name' => 'Bruna Roberta de Freitas Medeiros', 'street' => 'Avenida Santa Luzia, 67', 'complement' => 'Casa 07 Fundos', 'neighborhood' => 'Senador Camará', 'city' => 'Rio de Janeiro', 'state' => 'RJ', 'zip' => '21843-100'),
    array('name' => 'BRUNA SENA OLIVEIRA', 'street' => 'Rua Paraiso, 88', 'complement' => '', 'neighborhood' => 'Caiçara', 'city' => 'Arraial do Cabo', 'state' => 'RJ', 'zip' => '28930-000'),
    array('name' => 'BRUNA TEIXEIRA DO NASCIMENTO PINTO', 'street' => 'Rua Cinerária, 101', 'complement' => 'fundos', 'neighborhood' => 'Residencial Praia Âncora', 'city' => 'Rio das Ostras', 'state' => 'RJ', 'zip' => '28899-386'),
    array('name' => 'Bruno da Silva Aragão', 'street' => 'Rua Itaparica', 'complement' => '', 'neighborhood' => 'Morro da Conquista', 'city' => 'Volta Redonda', 'state' => 'RJ', 'zip' => '27210-060'),
    array('name' => 'BRUNO DE CARVALHO FERNANDES', 'street' => 'Rua das Casuarinas, 179', 'complement' => '', 'neighborhood' => 'Nova Aroeiras', 'city' => 'Macaé', 'state' => 'RJ', 'zip' => '27946-020'),
    array('name' => 'BRUNO VINICIUS PINHEIRO DE FREITAS', 'street' => 'Rua Francisco Mendes, 76', 'complement' => '', 'neighborhood' => 'Deodoro', 'city' => 'Rio de Janeiro', 'state' => 'RJ', 'zip' => '21675-260'),
    array('name' => 'CAILANE SANTOS OLIVEIRA', 'street' => 'Rua das Palmeiras, 13', 'complement' => '', 'neighborhood' => 'Mar do Norte', 'city' => 'Rio das Ostras', 'state' => 'RJ', 'zip' => '28898-008'),
    array('name' => 'Camila Figueira Barros', 'street' => 'Rua Senador Alfredo Ellis, 315', 'complement' => 'Apto 306', 'neighborhood' => 'Jardim Amália', 'city' => 'Volta Redonda', 'state' => 'RJ', 'zip' => '27251-400'),
    array('name' => 'CAMILLE VITÓRIA DE ARAÚJO MACHADO DA SILVA', 'street' => 'Rua Joaquim Borges Pereira, 20', 'complement' => '', 'neighborhood' => 'Piteiras', 'city' => 'Barra Mansa', 'state' => 'RJ', 'zip' => '27331-400'),
    array('name' => 'Carina', 'street' => 'Avenida Marechal Paulo Torres, 830', 'complement' => '', 'neighborhood' => 'Madruga', 'city' => 'Vassouras', 'state' => 'RJ', 'zip' => '27700-000'),
    array('name' => 'Carina Corrêa e Castro Vaillant Amorim', 'street' => 'Avenida Marechal Paulo Torres, 830', 'complement' => '', 'neighborhood' => 'Madruga', 'city' => 'Vassouras', 'state' => 'RJ', 'zip' => '27700-000'),
    array('name' => 'Carina Marcelino Correa', 'street' => 'Rua Paulo Pereira Dias, 324', 'complement' => '', 'neighborhood' => 'Xerém', 'city' => 'Duque de Caxias', 'state' => 'RJ', 'zip' => '25250-624'),
    array('name' => 'Carla Beatriz de Mattos Mendes', 'street' => 'Estrada dos Três Rios, 1245', 'complement' => '', 'neighborhood' => 'Freguesia (Jacarepaguá)', 'city' => 'Rio de Janeiro', 'state' => 'RJ', 'zip' => '22745-004'),
    array('name' => 'CARLA DA SILVA DE OLIVEIRA', 'street' => 'Estrada dos Vieiras, 92', 'complement' => '', 'neighborhood' => 'Paciência', 'city' => 'Rio de Janeiro', 'state' => 'RJ', 'zip' => '23587-610'),
    array('name' => 'Carlos Alberto Camilo Junior', 'street' => 'Rua Feliciana Bernardes, 220', 'complement' => '', 'neighborhood' => 'Jardim Paineiras', 'city' => 'Itatiaia', 'state' => 'RJ', 'zip' => '27580-530'),
    array('name' => 'Carlos Antônio Pereira de Oliveira', 'street' => 'Rua da Guarda, 17A', 'complement' => '', 'neighborhood' => 'Sepetiba', 'city' => 'Rio de Janeiro', 'state' => 'RJ', 'zip' => '23545-290'),
    array('name' => 'Carlos Augusto Ferreira da Fonseca', 'street' => 'Avenida Governador Portela, 1150', 'complement' => '', 'neighborhood' => 'Vila Julieta', 'city' => 'Resende', 'state' => 'RJ', 'zip' => '27521-102'),
    array('name' => 'Carlos Augusto Ferreira Monteiro', 'street' => 'Rua Uruguai, 89', 'complement' => '', 'neighborhood' => 'Parque Morone', 'city' => 'Paraíba do Sul', 'state' => 'RJ', 'zip' => '25850-000'),
    array('name' => 'Carlos Eduardo de Souza', 'street' => 'Rua B, 197', 'complement' => '', 'neighborhood' => 'Santo Amaro', 'city' => 'Resende', 'state' => 'RJ', 'zip' => ''),
    array('name' => 'Carlos Giovani De Azevedo Cruz', 'street' => 'Rua Macaé, 133', 'complement' => '', 'neighborhood' => 'Siderlândia', 'city' => 'Volta Redonda', 'state' => 'RJ', 'zip' => '27273-170'),
    array('name' => 'CARLOS HENRIQUE RAMOS SIQUEIRA', 'street' => 'Rua João Paulo, 10', 'complement' => '', 'neighborhood' => 'Ponto Chic', 'city' => 'Nova Iguaçu', 'state' => 'RJ', 'zip' => '26033-157'),
    array('name' => 'Carlos Henrique Rodrigues Silveira', 'street' => 'BR 116, 32', 'complement' => 'Rua ingraterra', 'neighborhood' => '', 'city' => 'Frei Inocêncio', 'state' => 'MG', 'zip' => '35112-000'),
    array('name' => 'Carolaine de Souza Barros', 'street' => 'Rua Manoel Costa, 9', 'complement' => '', 'neighborhood' => 'Fazendinha', 'city' => 'Araruama', 'state' => 'RJ', 'zip' => '28984-146'),
    array('name' => 'Carolina Ferreira Guimarães Cerqueira', 'street' => 'Avenida Francisco Fortes Filho, 1535', 'complement' => 'Bloco 12b, apto 102', 'neighborhood' => 'Mirante de Serra', 'city' => 'Resende', 'state' => 'RJ', 'zip' => '27521-620'),
    array('name' => 'CAROLINE DA SILVA DAVID', 'street' => 'Rua Olímpia, 811', 'complement' => '', 'neighborhood' => 'Tijuca', 'city' => 'Rio de Janeiro', 'state' => 'RJ', 'zip' => '20521-120'),
    array('name' => 'CASSIANE DIAS DE SOUZA', 'street' => 'Rua Sales Teixeira, 51', 'complement' => '', 'neighborhood' => 'São Francisco de Assis', 'city' => 'Belford Roxo', 'state' => 'RJ', 'zip' => '26125-290'),
    array('name' => 'CASSIANE FARIA CONCEIÇÃO', 'street' => 'Rua Dourados, 205', 'complement' => 'Vale do Ipê', 'neighborhood' => 'Parque São Lucas', 'city' => 'Belford Roxo', 'state' => 'RJ', 'zip' => '26182-300'),
    array('name' => 'CAUÃ PIRES EDUARDO', 'street' => 'Rua Coronel Rocha Santos, 247', 'complement' => '', 'neighborhood' => 'Jardim Brasília', 'city' => 'Resende', 'state' => 'RJ', 'zip' => '27515-000'),
    array('name' => 'Celeste das Graças Theodoro', 'street' => 'Avenida B, 522', 'complement' => '', 'neighborhood' => 'Freitas Soares', 'city' => 'Porto Real', 'state' => 'RJ', 'zip' => '27570-000'),
    array('name' => 'CÉLIA FERREIRA DA SILVA', 'street' => 'Rua Avelino Batista Soares, 477', 'complement' => 'casa 3', 'neighborhood' => 'Loteamento Bondarovshy', 'city' => 'Quatis', 'state' => 'RJ', 'zip' => '27410-180'),
    array('name' => 'Cezar Augusto Pereira de Carvalho', 'street' => 'Rua José Medeiros, 27', 'complement' => '', 'neighborhood' => 'Mirante das Agulhas', 'city' => 'Resende', 'state' => 'RJ', 'zip' => '27524-580'),
    array('name' => 'CHUARA RIBEIRO STEIN KULZER', 'street' => 'Rua prefeito José Basílio de alvarenga, 338', 'complement' => '', 'neighborhood' => 'Jardim Monte Serrat', 'city' => 'Santa Isabel', 'state' => 'SP', 'zip' => '07500-000'),
    array('name' => 'CINARA BRAZILINO DE BRITO', 'street' => 'Travessa Maria Emília Andrade, 16', 'complement' => '', 'neighborhood' => 'Beco do Sarole', 'city' => 'Piraí', 'state' => 'RJ', 'zip' => '27175-000'),
    array('name' => 'CINEONE DA CONCEIÇÃO LIMA', 'street' => 'Rua Quarenta e Nove, 67', 'complement' => '', 'neighborhood' => 'Morada da Barra', 'city' => 'Resende', 'state' => 'RJ', 'zip' => '27540-330'),
    array('name' => 'CINTHIA MARA DA SILVA PINHEIRO GAMA', 'street' => 'Rua Geraldo José de Freitas, 435', 'complement' => '', 'neighborhood' => 'Boa Vista 1', 'city' => 'Barra Mansa', 'state' => 'RJ', 'zip' => '27332-180'),
    array('name' => 'Cláudia da Silva', 'street' => 'Rua Santa Ângela, 375', 'complement' => '', 'neighborhood' => 'Parque Ipiranga', 'city' => 'Resende', 'state' => 'RJ', 'zip' => '27516-120'),

    // ─── FIM DO LOTE 2 (páginas 2-15, ~120 registros) ───────
    // Registros das páginas 16-86 (~700+ registros) serão adicionados
    // em lotes subsequentes (lote3, lote4, etc.) ou neste mesmo arquivo.
    // O script é seguro para re-execução: só atualiza campos vazios.
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
            audit_log('address_imported', 'client', (int)$client['id'], 'Novajus import lote2');

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
echo "RESUMO DA MIGRAÇÃO (LOTE 2)\n";
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

echo "\n--- Fim da migração (Lote 2) ---\n";
