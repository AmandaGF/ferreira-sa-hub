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

    // ─── PÁGINAS 16-86 ─────────────────────────────────────
    // Página 16
    array('name' => 'CLAUDIA FERREIRA LIMA', 'street' => 'Rua Bem-feliz, s/n', 'complement' => '', 'neighborhood' => 'Costa Barros', 'city' => 'Rio de Janeiro', 'state' => 'RJ', 'zip' => '21515-460'),
    array('name' => 'CLAUDINEI MARCOLINO', 'street' => 'Rua Dona Filo, 102', 'complement' => '', 'neighborhood' => 'Fazenda da Barra II', 'city' => 'Resende', 'state' => 'RJ', 'zip' => '27537-150'),
    array('name' => 'CLAUDIO ALEXANDRE MACEDO CORREA', 'street' => 'Rua Firmino Narciso do Nascimento, 19', 'complement' => '', 'neighborhood' => 'Vicentino', 'city' => 'Resende', 'state' => 'RJ', 'zip' => '27513-284'),
    array('name' => 'Cleide Barcellos Mendes', 'street' => 'Travessa Alberto, 3', 'complement' => '', 'neighborhood' => 'Barreto', 'city' => 'Niterói', 'state' => 'RJ', 'zip' => '24110-292'),
    array('name' => 'Cleitom Avelino Eduardo', 'street' => 'Rua Coronel Rocha Santos, 447', 'complement' => '', 'neighborhood' => 'Jardim Brasília', 'city' => 'Resende', 'state' => 'RJ', 'zip' => '27515-000'),
    array('name' => 'Cleonice Ramos da Silva', 'street' => 'Rua Cinco, 124', 'complement' => '', 'neighborhood' => 'Nossa Senhora do Rosário', 'city' => 'Quatis', 'state' => 'RJ', 'zip' => '27430-170'),
    array('name' => 'CLEYTON RIBEIRO DA SILVA', 'street' => 'Avenida Paiva, 299', 'complement' => 'Apto 301', 'neighborhood' => 'Neves (Neves)', 'city' => 'São Gonçalo', 'state' => 'RJ', 'zip' => '24426-148'),
    array('name' => 'CRISTIANA MARTINIANO DE CASTRO', 'street' => 'Rua José Martins Taboa, 36', 'complement' => '', 'neighborhood' => 'Vila Isabel', 'city' => 'Três Rios', 'state' => 'RJ', 'zip' => '25815-520'),
    array('name' => 'CRISTIANE ANICETO DA COSTA', 'street' => 'Rua Cláudia Múzio, 03', 'complement' => 'Beco', 'neighborhood' => 'Citrópolis', 'city' => 'Japeri', 'state' => 'RJ', 'zip' => '26430-140'),
    array('name' => 'CRISTIANO DA SILVA MARINATO', 'street' => 'Rua 5, 438', 'complement' => '', 'neighborhood' => 'Nova Campinas', 'city' => 'Duque de Caxias', 'state' => 'RJ', 'zip' => '25268-080'),
    // Página 17
    array('name' => 'Cristiano Miller de Mello', 'street' => 'Rua Dezoito, 0000', 'complement' => 'Quadra 25, lote 48 - casa 01', 'neighborhood' => 'Jardim Aliança II', 'city' => 'Resende', 'state' => 'RJ', 'zip' => '27525-850'),
    array('name' => 'Cristiano Porto Faria', 'street' => 'Avenida Brasil, 69', 'complement' => '', 'neighborhood' => 'Village', 'city' => 'Porto Real', 'state' => 'RJ', 'zip' => '27570-000'),
    array('name' => 'CRISTINA MARA OLIVEIRA DOS SANTOS', 'street' => 'Rua Furquim Mendes, 235', 'complement' => '', 'neighborhood' => 'Vigário Geral', 'city' => 'Rio de Janeiro', 'state' => 'RJ', 'zip' => '21241-340'),
    array('name' => 'Daiana da silva Oliveira Meneses', 'street' => 'Viela Santo Amaro, 365', 'complement' => '', 'neighborhood' => 'Cachoeira', 'city' => 'Guarujá', 'state' => 'SP', 'zip' => '11435-012'),
    array('name' => 'DALÍZIO ANTONIO REZENDE COSTA', 'street' => 'Rua Araxá, 59', 'complement' => '', 'neighborhood' => 'Fazenda da Barra', 'city' => 'Resende', 'state' => 'RJ', 'zip' => '27540-010'),
    array('name' => 'Damalena Perpétua da Silva e Assis', 'street' => 'Rua 1, 224', 'complement' => '', 'neighborhood' => 'Jardim das Acácias', 'city' => 'Porto Real', 'state' => 'RJ', 'zip' => '27570-000'),
    array('name' => 'Daniel Caldeira Alves', 'street' => 'Rua Antonio Ruiz Veiga, 110', 'complement' => 'AP 1101', 'neighborhood' => 'Loteamento Mogilar', 'city' => 'Mogi das Cruzes', 'state' => 'SP', 'zip' => '08773-495'),
    array('name' => 'Daniel Nascimento Correia de Oliveira', 'street' => 'Rua Alcântara, 11', 'complement' => '', 'neighborhood' => 'Fazendinha', 'city' => 'Araruama', 'state' => 'RJ', 'zip' => '28984-045'),
    array('name' => 'DANIELA DA SILVA LIMA', 'street' => 'Rua Alga Marinha, 78', 'complement' => 'Humeboch casa 78', 'neighborhood' => 'Paraíso', 'city' => 'Nova Iguaçu', 'state' => 'RJ', 'zip' => '26297-075'),
    array('name' => 'Daniele Silva de Araújo', 'street' => 'Rua Itapoana, 28', 'complement' => '', 'neighborhood' => 'Santa Lúcia', 'city' => 'Duque de Caxias', 'state' => 'RJ', 'zip' => '25271-290'),
    // Página 18
    array('name' => 'Danielle Berbert Rodrigues Coura', 'street' => 'Rodovia BR 393, 42/44', 'complement' => 'ap 101', 'neighborhood' => 'São João', 'city' => 'Volta Redonda', 'state' => 'RJ', 'zip' => '27253-410'),
    array('name' => 'DANIELLE CÂNDIDO DA SILVA', 'street' => 'Rua Clerio Ferreira de Carvalho, s/n', 'complement' => '', 'neighborhood' => 'Petrolândia', 'city' => 'Marataízes', 'state' => 'ES', 'zip' => '29345-000'),
    array('name' => 'DANIELLI NOGUEIRA NOGUCHI', 'street' => 'Rua Alfredo Botelho, 514', 'complement' => '', 'neighborhood' => 'Vila Julieta', 'city' => 'Resende', 'state' => 'RJ', 'zip' => '27520-282'),
    array('name' => 'DANIELLY RODRIGUES LYRA', 'street' => 'Rua Pio Borges, 91', 'complement' => 'casa 13', 'neighborhood' => 'Parque Estrela', 'city' => 'Duque de Caxias', 'state' => 'RJ', 'zip' => '25275-260'),
    array('name' => 'DANILO CURVELO DO NASCIMENTO', 'street' => 'Rua Orlinda Wilman, 138', 'complement' => '', 'neighborhood' => 'Moquetá', 'city' => 'Nova Iguaçu', 'state' => 'RJ', 'zip' => '26215-150'),
    array('name' => 'Danilo Fontes Teodoro', 'street' => 'Rua 7 de setembro, 85', 'complement' => '', 'neighborhood' => 'São José', 'city' => 'Porto Real', 'state' => 'RJ', 'zip' => '27570-000'),
    array('name' => 'DANNYELE VENANCIOI DE SOUZA DAS DORES', 'street' => 'Rua Murilo Peixoto, 190', 'complement' => '', 'neighborhood' => 'Parque São Silvestre', 'city' => 'Campos dos Goytacazes', 'state' => 'RJ', 'zip' => '28090-200'),
    array('name' => 'DANUBIA VIEIRA XAVIER', 'street' => 'Rua Luiz de Souza, 14', 'complement' => '', 'neighborhood' => '', 'city' => 'Descoberto', 'state' => 'MG', 'zip' => '36690-000'),
    array('name' => 'Davi Ferreira Fraga', 'street' => 'Rua Quatorze, 13', 'complement' => '', 'neighborhood' => 'Mirante das Agulhas', 'city' => 'Resende', 'state' => 'RJ', 'zip' => '27524-570'),
    array('name' => 'DAVI REINALDO ANDRADE HONORIO', 'street' => 'Avenida Dauro Peixoto Aragão, s/n', 'complement' => '', 'neighborhood' => 'Três Poços', 'city' => 'Volta Redonda', 'state' => 'RJ', 'zip' => '27240-560'),
    // Página 19
    array('name' => 'David Magliano Costa', 'street' => 'Rua Chico Mendes, 100', 'complement' => '', 'neighborhood' => 'Vale Verde', 'city' => 'Volta Redonda', 'state' => 'RJ', 'zip' => '27279-095'),
    array('name' => 'DAYANA CABRAL FERNANDES', 'street' => 'Rua Santa Luz, 308', 'complement' => '', 'neighborhood' => 'Vista Alegre', 'city' => 'Rio de Janeiro', 'state' => 'RJ', 'zip' => '21230-760'),
    array('name' => 'DAYANE DE ARAUJO AMANCIO', 'street' => 'Rua Fortunata Trento Galazi, 120', 'complement' => 'quadra 11', 'neighborhood' => 'São Miguel', 'city' => 'Colatina', 'state' => 'ES', 'zip' => '29704-780'),
    array('name' => 'Dayse Maria Castilho Cabral', 'street' => 'Avenida das Américas, 13550', 'complement' => 'Bloco 02 Apartamento 601', 'neighborhood' => 'Recreio dos Bandeirantes', 'city' => 'Rio de Janeiro', 'state' => 'RJ', 'zip' => '22790-702'),
    array('name' => 'DÉBORA BATISTA BARBOSA', 'street' => 'Estrada Lagoa de Cima, s/n', 'complement' => 'Pernambuca', 'neighborhood' => 'Ibitioca', 'city' => 'Campos dos Goytacazes', 'state' => 'RJ', 'zip' => '28120-000'),
    array('name' => 'Débora Carvalho da Silva', 'street' => 'Rua Abdo Miguel Arbex, 113', 'complement' => '', 'neighborhood' => 'Parque Ipiranga II', 'city' => 'Resende', 'state' => 'RJ', 'zip' => '27516-400'),
    array('name' => 'Débora Lopes Gonçalves', 'street' => 'Rua Padre José Sandrup, 731', 'complement' => '', 'neighborhood' => 'Vila Julieta', 'city' => 'Resende', 'state' => 'RJ', 'zip' => '27520-262'),
    array('name' => 'DEBORAH DA SILVA VIEIRA', 'street' => 'Estrada Curral Novo, 158', 'complement' => 'AP 202 C', 'neighborhood' => 'Ipiranga', 'city' => 'Nova Iguaçu', 'state' => 'RJ', 'zip' => '26293-567'),
    array('name' => 'Deise Cristina Ramos de Oliveira', 'street' => 'Rua Rio de Janeiro, 807', 'complement' => 'Casa', 'neighborhood' => 'São José', 'city' => 'Porto Real', 'state' => 'RJ', 'zip' => '27570-000'),
    array('name' => 'DEISE FERREIRA DO NASCIMENTO', 'street' => 'Rua Pinheiro, 370', 'complement' => 'casa 4', 'neighborhood' => 'Vila Pinheiro', 'city' => 'Itatiaia', 'state' => 'RJ', 'zip' => '27580-000'),
    // Página 20
    array('name' => 'Deisiane Santana de Amorim', 'street' => 'Rua Waldir dos Santos, 18', 'complement' => '', 'neighborhood' => 'Engenho Pequeno', 'city' => 'São Gonçalo', 'state' => 'RJ', 'zip' => '24417-300'),
    array('name' => 'DEIVISON DA SILVA OLIVEIRA', 'street' => 'Rua João Barbalho, 506', 'complement' => '', 'neighborhood' => 'Quintino Bocaiúva', 'city' => 'Rio de Janeiro', 'state' => 'RJ', 'zip' => '20740-010'),
    array('name' => 'DENILSON DE ALMEIDA NASCIMENTO', 'street' => 'Estrada do Imburo, s/n', 'complement' => '', 'neighborhood' => 'Ajuda de Cima', 'city' => 'Macaé', 'state' => 'RJ', 'zip' => '27979-000'),
    array('name' => 'DENISE DE FATIMA DA SILVA LOPES', 'street' => 'Rua Padre José Sandrup, 731', 'complement' => '2° andar', 'neighborhood' => 'Vila Julieta', 'city' => 'Resende', 'state' => 'RJ', 'zip' => '27520-262'),
    array('name' => 'Dennys da Silva Souza', 'street' => 'Av. Brasilia, 397', 'complement' => '', 'neighborhood' => '', 'city' => 'Resende', 'state' => 'RJ', 'zip' => ''),
    array('name' => 'DHULY ANEBERG DA SILVA CRAVO', 'street' => 'Avenida do Contorno, s/n', 'complement' => 'Condomínio bem ti vi BL 21 AP 504', 'neighborhood' => 'Jóquei Clube', 'city' => 'São Gonçalo', 'state' => 'RJ', 'zip' => '24743-100'),
    array('name' => 'DIANA BORGES DA SILVA', 'street' => 'Rua Bahia, 15', 'complement' => 'LT 15 Qd 13', 'neighborhood' => 'Jardim Guandu', 'city' => 'Nova Iguaçu', 'state' => 'RJ', 'zip' => '26298-105'),
    array('name' => 'Diandra Castioni', 'street' => 'Rua Visconde de Tamandaré, 729', 'complement' => '', 'neighborhood' => 'Florestal', 'city' => 'Lajeado', 'state' => 'RS', 'zip' => '95900-600'),
    // Página 21
    array('name' => 'Diego Cesar Machado de França Lemos', 'street' => 'Rua Visconde de Tamandaré, 729', 'complement' => '', 'neighborhood' => 'Florestal', 'city' => 'Lajeado', 'state' => 'RS', 'zip' => '95900-600'),
    array('name' => 'DIEGO RAIMUNDO DOS SANTOS SILVA', 'street' => 'Benedito Rulfino Xavier, 360', 'complement' => '', 'neighborhood' => 'São Judas Tadeu', 'city' => 'Borda da Mata', 'state' => 'MG', 'zip' => '37564-000'),
    array('name' => 'DIEGO SOUZA VILLARINHO', 'street' => 'Rua Seis, 88', 'complement' => '', 'neighborhood' => 'Vicentino', 'city' => 'Resende', 'state' => 'RJ', 'zip' => '27513-271'),
    array('name' => 'DIOGO NASCIMENTO DE OLIVEIRA', 'street' => 'Rua José Roberto de Mello Faria, 95', 'complement' => '', 'neighborhood' => 'Loteamento Bondarovshy', 'city' => 'Quatis', 'state' => 'RJ', 'zip' => '27410-240'),
    array('name' => 'Diorge Jefferson Alexandre de Jesus', 'street' => 'Avenida Jaguaré, 403', 'complement' => 'APTO 031', 'neighborhood' => 'Jaguaré', 'city' => 'São Paulo', 'state' => 'SP', 'zip' => '05346-000'),
    array('name' => 'Douglas Cardoso Silva', 'street' => 'Rua Major Penha, 332', 'complement' => 'Sala 04', 'neighborhood' => 'Centro', 'city' => 'Caxambu', 'state' => 'MG', 'zip' => ''),
    array('name' => 'Douglas Silva de Souza', 'street' => 'Estrada da Companhia, 191', 'complement' => '', 'neighborhood' => 'Roma', 'city' => 'Volta Redonda', 'state' => 'RJ', 'zip' => '27257-505'),
    array('name' => 'DOUGLAS SUARES MARTINS', 'street' => 'Avenida Brasil, s/n', 'complement' => '', 'neighborhood' => 'Morada da Barra', 'city' => 'Vila Velha', 'state' => 'ES', 'zip' => '29126-554'),
    array('name' => 'Driele Soares Nunes', 'street' => 'Travessa Minas Gerais 2, 36', 'complement' => '', 'neighborhood' => 'Vila Julieta', 'city' => 'Resende', 'state' => 'RJ', 'zip' => '27521-050'),
    array('name' => 'Dulce Fontes Teodoro', 'street' => 'Rua São Francisco, 522', 'complement' => '', 'neighborhood' => 'Fátima', 'city' => 'Porto Real', 'state' => 'RJ', 'zip' => '27570-000'),
    // Página 22
    array('name' => 'EDILAINE FERREIRA ALVES', 'street' => 'Jacinta Filgueira, 275', 'complement' => '', 'neighborhood' => 'Santa Rosa', 'city' => 'Além Paraíba', 'state' => 'MG', 'zip' => '32346-245'),
    array('name' => 'EDILSON MANOEL DA SILVA', 'street' => 'Rua Joaquim Constantino, 13', 'complement' => '', 'neighborhood' => 'Jardim Vergueiro', 'city' => 'São Paulo', 'state' => 'SP', 'zip' => '05818-300'),
    array('name' => 'Edir Soares Oliva', 'street' => 'Rua Cassiano Antônio, 210', 'complement' => '', 'neighborhood' => 'Cantagalo', 'city' => 'Três Rios', 'state' => 'RJ', 'zip' => '25806-130'),
    array('name' => 'EDLAYNE MARA NUNES DOS SANTOS', 'street' => 'Rua Frei Tito, 179', 'complement' => '', 'neighborhood' => 'Jardim Esperança', 'city' => 'Resende', 'state' => 'RJ', 'zip' => '27540-660'),
    array('name' => 'EDMILSON OURÍQUES DOS SANTOS', 'street' => 'Rua General Juarez Pereira Gomes, 18', 'complement' => 'Lote 18 Q I', 'neighborhood' => 'Parque Alvorada', 'city' => 'Duque de Caxias', 'state' => 'RJ', 'zip' => '25045-420'),
    array('name' => 'EDNA CRISTINA FIDELIS DE SOUSA', 'street' => 'Estrada Campo Limpo, 02', 'complement' => '', 'neighborhood' => 'Campo Limpo', 'city' => 'Teresópolis', 'state' => 'RJ', 'zip' => '25980-180'),
    array('name' => 'EDNA VITORIA GUIMARAES DA SILVA', 'street' => 'Rua Rodes, 100', 'complement' => '', 'neighborhood' => 'Parque Marilandia', 'city' => 'Duque de Caxias', 'state' => 'RJ', 'zip' => '25225-630'),
    array('name' => 'EDUARDA DO NASCIMENTO PIMENTA', 'street' => 'Rua Mário Figueiredo Cicarino, 06', 'complement' => '', 'neighborhood' => 'Engenho', 'city' => 'Itaguaí', 'state' => 'RJ', 'zip' => '23820-760'),
    array('name' => 'EDUARDA HONORATO TOLEDO', 'street' => 'Rua Izoldino Diniz, 212', 'complement' => '', 'neighborhood' => 'Mirante das Agulhas', 'city' => 'Resende', 'state' => 'RJ', 'zip' => '27524-530'),
    array('name' => 'Eduardo Roberto dos Santos Guido', 'street' => 'Rua Dez, 512', 'complement' => 'Ap 101', 'neighborhood' => 'Vista Alegre', 'city' => 'Barra Mansa', 'state' => 'RJ', 'zip' => '27320-150'),
    // Página 23
    array('name' => 'ELAINE CAMELO DA SILVA', 'street' => 'Rua Jamaica, 09', 'complement' => '', 'neighborhood' => 'Barros Filho', 'city' => 'Rio de Janeiro', 'state' => 'RJ', 'zip' => '21660-460'),
    array('name' => 'Elaine Cristina bilha Ferreira da Silva', 'street' => 'Rua João Ferreira da Paz, 310', 'complement' => 'Casa 01', 'neighborhood' => 'Jardim D\'Oeste', 'city' => 'Resende', 'state' => 'RJ', 'zip' => '27525-640'),
    array('name' => 'Eli Manoel da Silva', 'street' => 'Rua José Ferreira, 473', 'complement' => 'Travessa Santos', 'neighborhood' => 'Penedo', 'city' => 'Itatiaia', 'state' => 'RJ', 'zip' => '27598-000'),
    array('name' => 'Eliana Cristina Fernandes', 'street' => 'Rua Joana dos Santos Nogueira, 158', 'complement' => '', 'neighborhood' => 'Santa Catarina', 'city' => 'Pontal', 'state' => 'SP', 'zip' => '14180-000'),
    array('name' => 'ELIANA DE SOUZA JESUS ROCHA', 'street' => 'Avenida Juscelino Kubitschek de Oliveira, 206', 'complement' => '', 'neighborhood' => 'Fazenda da Barra 2', 'city' => 'Resende', 'state' => 'RJ', 'zip' => '27540-250'),
    array('name' => 'ELIANE ROSALINA DA SILVA', 'street' => 'Rua Santo Dias, 65', 'complement' => '', 'neighborhood' => 'Jardim Esperança', 'city' => 'Resende', 'state' => 'RJ', 'zip' => '27540-652'),
    array('name' => 'ELIAS LIMA MATHEUS', 'street' => 'Rua A, 263', 'complement' => 'Rua Urumajo', 'neighborhood' => 'Campo Grande', 'city' => 'Rio de Janeiro', 'state' => 'RJ', 'zip' => '23015-250'),
    array('name' => 'ELIENE LIMA DOS SANTOS ALMEIDA', 'street' => 'Rua Godofredo de Barros, 16', 'complement' => '', 'neighborhood' => 'Vila Engenho Novo', 'city' => 'Barueri', 'state' => 'SP', 'zip' => '06416-050'),
    array('name' => 'Eliete da Silva Ribeiro', 'street' => 'Rua Lírios, 36', 'complement' => '', 'neighborhood' => 'Parada Angélica', 'city' => 'Duque de Caxias', 'state' => 'RJ', 'zip' => '25271-400'),
    array('name' => 'Elisa Duarte Rezende', 'street' => 'Avenida Francisco Fortes Filho, 1535', 'complement' => '15a103', 'neighborhood' => 'Mirante de Serra', 'city' => 'Resende', 'state' => 'RJ', 'zip' => '27521-620'),
    array('name' => 'Elisabety Coelho da Silva Geribello', 'street' => 'Estrada Rio Caxambu, Km11', 'complement' => 'Hotel Fazenda Palmital', 'neighborhood' => 'Engenheiros Passos', 'city' => 'Resende', 'state' => 'RJ', 'zip' => '27555-000'),
    // Página 24
    array('name' => 'Elisangela Cristofoli', 'street' => 'Rua Antônio Carlos Kraide, 670', 'complement' => '', 'neighborhood' => 'Brasília', 'city' => 'Cascavel', 'state' => 'PR', 'zip' => '85815-380'),
    array('name' => 'ELISVALDO OLIVEIRA BOTELHO', 'street' => 'Avenida Nossa Senhora de Copacabana, LT 01', 'complement' => 'QD 04', 'neighborhood' => 'Vila São João', 'city' => 'Queimados', 'state' => 'RJ', 'zip' => '26379-010'),
    array('name' => 'Eliton de Paula Cunha', 'street' => 'Rua Alan Pinto de Souza, 52', 'complement' => '', 'neighborhood' => 'Morada da Montanha', 'city' => 'Resende', 'state' => 'RJ', 'zip' => '27525-630'),
    array('name' => 'EMERSON ALVARENGA DE MACEDO', 'street' => 'Rua Edu Chaves, 404', 'complement' => '', 'neighborhood' => 'São Dimas', 'city' => 'Piracicaba', 'state' => 'SP', 'zip' => '13416-020'),
    array('name' => 'Emmanuelly Karoline de Souza Conceição', 'street' => 'Rua Irmã Dulce, 197', 'complement' => '', 'neighborhood' => 'Esmeralda', 'city' => 'Cascavel', 'state' => 'PR', 'zip' => '85806-746'),
    array('name' => 'Enayle Garcia Fontes', 'street' => 'Rua Cinco, 266', 'complement' => '', 'neighborhood' => 'Jardim Aliança II', 'city' => 'Resende', 'state' => 'RJ', 'zip' => '27525-836'),
    array('name' => 'Eni Cristina Moliterno da Costa', 'street' => 'Rua General Pratti de Aguiar, 421', 'complement' => '', 'neighborhood' => 'Jardim Brasília - Tangara', 'city' => 'Resende', 'state' => 'RJ', 'zip' => '27514-080'),
    array('name' => 'Eni Sacramento Guedes', 'street' => 'Rua Dr. Aldrovando de Oliveira, 138', 'complement' => '', 'neighborhood' => 'Ano Bom', 'city' => 'Barra Mansa', 'state' => 'RJ', 'zip' => ''),
    array('name' => 'ERIKA DE SENA ANTUNES', 'street' => 'Rua Iguaçu, 1276', 'complement' => '', 'neighborhood' => 'Engenheiro Leal', 'city' => 'Rio de Janeiro', 'state' => 'RJ', 'zip' => '21370-130'),
    // Páginas 25-30
    array('name' => 'Ester dos Santos Patricio', 'street' => 'Rua Seis, 12', 'complement' => 'fundos', 'neighborhood' => 'Praça Seca', 'city' => 'Rio de Janeiro', 'state' => 'RJ', 'zip' => '22733-080'),
    array('name' => 'Ester Gonsalves Romão', 'street' => 'Rua Arnaldo Silva Duarte, 147', 'complement' => '', 'neighborhood' => 'Mirante das Agulhas', 'city' => 'Resende', 'state' => 'RJ', 'zip' => '27524-535'),
    array('name' => 'ESTHER VIVIANE FERREIRA DA SILVA', 'street' => 'Rua João Montilho, 156', 'complement' => '', 'neighborhood' => 'Vila Amélia', 'city' => 'Duque de Caxias', 'state' => 'RJ', 'zip' => '25025-115'),
    array('name' => 'ESTOEL NATHAN COSTA SILVA', 'street' => 'Rua Manoel Pedro de Carvalho, 68', 'complement' => '68 A', 'neighborhood' => 'Recanto das Rosas', 'city' => 'Cruzília', 'state' => 'MG', 'zip' => '37445-000'),
    array('name' => 'Eurídice Barbosa Lamin', 'street' => 'Rua São Judas Tadeu, 49', 'complement' => '', 'neighborhood' => 'Paraíso', 'city' => 'Resende', 'state' => 'RJ', 'zip' => '27535-170'),
    array('name' => 'EVANDRO SORES DE LUCENA', 'street' => 'Rua Coronel Respicio do Espírito Santo, 687', 'complement' => 'Casa 01', 'neighborhood' => 'Sepetiba', 'city' => 'Rio de Janeiro', 'state' => 'RJ', 'zip' => '23530-371'),
    array('name' => 'EVELLYN XAVIER MANSO', 'street' => 'Rua Doutor Fernando, s/n', 'complement' => '', 'neighborhood' => 'Campo Grande', 'city' => 'Rio de Janeiro', 'state' => 'RJ', 'zip' => '23068-300'),
    array('name' => 'Evelyn Lemes Theodoro Corrêa', 'street' => 'Av das camélias, 594', 'complement' => '', 'neighborhood' => 'Engenheiro Passos', 'city' => 'Resende', 'state' => 'RJ', 'zip' => '27555-000'),
    array('name' => 'EVERTON BATISTA DA SILVA', 'street' => 'Rua Helena Alegret, 107', 'complement' => '', 'neighborhood' => 'Jardim Real', 'city' => 'Porto Real', 'state' => 'RJ', 'zip' => '27570-000'),
    array('name' => 'FABIANA DE FREITAS PEREIRA', 'street' => 'Caminho Sebastião, 200', 'complement' => '', 'neighborhood' => 'Guaratiba', 'city' => 'Rio de Janeiro', 'state' => 'RJ', 'zip' => '23020-040'),
    array('name' => 'FABIANA MARTINS', 'street' => 'Rua Um, 105', 'complement' => '', 'neighborhood' => 'Parque Esplanada', 'city' => 'Campos dos Goytacazes', 'state' => 'RJ', 'zip' => '28055-045'),
    array('name' => 'Fabiana Ribeiro Dumas', 'street' => 'Rua Engenheira Paula Lopes, 531', 'complement' => '', 'neighborhood' => 'Bangu', 'city' => 'Rio de Janeiro', 'state' => 'RJ', 'zip' => '21825-320'),
    array('name' => 'FABIANE DE ALENCAR ESQUERDO', 'street' => 'Rua Erlane Lameira, s/n', 'complement' => 'Vila Nova Pissareira', 'neighborhood' => 'Cristo Rei', 'city' => 'Inhangapi', 'state' => 'PA', 'zip' => '68770-000'),
    array('name' => 'FABIO DOS SANTOS REIS', 'street' => 'Estrada Macabu', 'complement' => '', 'neighborhood' => 'Monte Belo (Iguabinha)', 'city' => 'Araruama', 'state' => 'RJ', 'zip' => '28970-001'),
    array('name' => 'FÁBIO LUIZ DA CONCEIÇÃO', 'street' => 'Beco Bonfim, 04', 'complement' => '', 'neighborhood' => 'Guaratiba', 'city' => 'Rio de Janeiro', 'state' => 'RJ', 'zip' => '23035-417'),
    array('name' => 'FABIO REZENDE DA SILVA', 'street' => 'Rua Alexandre Fleming, 209', 'complement' => '', 'neighborhood' => 'Vila Nova', 'city' => 'Nova Iguaçu', 'state' => 'RJ', 'zip' => '26225-490'),
    array('name' => 'FABRÍCIA BUENO GONÇALVES DA FONTE', 'street' => 'Rua José Soares, 175', 'complement' => '', 'neighborhood' => 'Roberto Silveira', 'city' => 'Barra Mansa', 'state' => 'RJ', 'zip' => '27310-480'),
    array('name' => 'Fabrício Costa Andrade', 'street' => 'Rua Otton da Fonseca, 40', 'complement' => 'Apt 408 bloco 02', 'neighborhood' => 'Jardim Sulacap', 'city' => 'Rio de Janeiro', 'state' => 'RJ', 'zip' => '21741-230'),
    array('name' => 'FABRICIO FURTADO MARQUES', 'street' => 'Rua das Margaridas, 92', 'complement' => 'casa 02', 'neighborhood' => 'Colônia Santo Antônio', 'city' => 'Barra Mansa', 'state' => 'RJ', 'zip' => '27353-050'),
    array('name' => 'FABRÍCIO LOPES DOS SANTOS', 'street' => 'Estrada Aderson Ferreira Filho, 6500', 'complement' => '', 'neighborhood' => 'Nova Cidade', 'city' => 'Macaé', 'state' => 'RJ', 'zip' => '27949-100'),
    array('name' => 'FELIPE ARAUJO GOMES', 'street' => 'Avenida Atlântica, 958', 'complement' => '', 'neighborhood' => 'Copacabana', 'city' => 'Rio de Janeiro', 'state' => 'RJ', 'zip' => '22010-000'),
    array('name' => 'Felipe de Jesus Souza', 'street' => 'Av. dos Ypes, 733', 'complement' => 'Casa', 'neighborhood' => 'Vila Pinheiro', 'city' => 'Itatiaia', 'state' => 'RJ', 'zip' => '27580-000'),
    array('name' => 'Felipe de Pontes Carvalho', 'street' => 'Rua Abdo Miguel Arbex, 113', 'complement' => 'Casa', 'neighborhood' => 'Parque Ipiranga II', 'city' => 'Resende', 'state' => 'RJ', 'zip' => '27516-400'),
    array('name' => 'FELIPE GUIMARÃES DA SILVA', 'street' => 'Rua dos operários, 15', 'complement' => '', 'neighborhood' => 'Penedo', 'city' => 'Itatiaia', 'state' => 'RJ', 'zip' => '27580-000'),
    array('name' => 'FELIPE JOSÉ FERNANDES MARIA', 'street' => 'Rua Maricá, 205', 'complement' => '', 'neighborhood' => 'Paraíso', 'city' => 'Nova Iguaçu', 'state' => 'RJ', 'zip' => '26297-183'),
    array('name' => 'Fernanda Alves Dias Jasmim', 'street' => 'Rua Walace Landal, 40', 'complement' => '', 'neighborhood' => 'Santa Cândida', 'city' => 'Curitiba', 'state' => 'PR', 'zip' => '82720-460'),
    array('name' => 'Fernanda Ribeiro de Oliveira', 'street' => 'Rua Dona Arcidia, 79', 'complement' => '', 'neighborhood' => 'Santa Isabel', 'city' => 'Resende', 'state' => 'RJ', 'zip' => '27522-030'),
    array('name' => 'Fernanda Silva Rabetine Junqueira', 'street' => 'Rua Doutor Satamini, 91', 'complement' => 'Apto 201', 'neighborhood' => 'Tijuca', 'city' => 'Rio de Janeiro', 'state' => 'RJ', 'zip' => '20270-230'),
    array('name' => 'FERNANDO BARBARA DA COSTA', 'street' => 'Rua dos Ipês, 27', 'complement' => '', 'neighborhood' => 'Vila Rica', 'city' => 'Extrema', 'state' => 'MG', 'zip' => '37640-000'),
    array('name' => 'Fernando Santana da Silva', 'street' => '1 (um), 130', 'complement' => '', 'neighborhood' => 'Fátima', 'city' => 'Volta Redonda', 'state' => 'RJ', 'zip' => '27163-000'),
    array('name' => 'Filipe Cristiano Alves Rodrigues Da Conceição', 'street' => 'Rua Miguel Nunes Teixeira, 161', 'complement' => '', 'neighborhood' => 'Parque Ipiranga', 'city' => 'Resende', 'state' => 'RJ', 'zip' => '27516-130'),
    array('name' => 'Flaviane Rodrigues', 'street' => 'Rua Cárceres, 298', 'complement' => '', 'neighborhood' => 'Parada Morabi', 'city' => 'Duque de Caxias', 'state' => 'RJ', 'zip' => '25265-354'),
    array('name' => 'FLÁVIO LEONARDO ARRUDA', 'street' => 'Rua Furtado de Mendonça, 47', 'complement' => '', 'neighborhood' => 'Quintino Bocaiúva', 'city' => 'Rio de Janeiro', 'state' => 'RJ', 'zip' => '20740-160'),
    array('name' => 'Franciele Cristina Cardoso', 'street' => 'Rua Izaias Francisco dos Santos, 255', 'complement' => '', 'neighborhood' => 'Parque Residencial Bom Pastor', 'city' => 'Sarandi', 'state' => 'PR', 'zip' => '87114-554'),
    array('name' => 'Francilini Lopes Saibro', 'street' => 'Rua Laranjal, 122', 'complement' => '', 'neighborhood' => 'Jardim Betânia', 'city' => 'Cachoeirinha', 'state' => 'RS', 'zip' => '94970-610'),
    array('name' => 'Francimeire Aparecida Raymundo de Oliveira', 'street' => 'Rua 15, 1067', 'complement' => '', 'neighborhood' => 'Jardim das Acácias', 'city' => 'Porto Real', 'state' => 'RJ', 'zip' => '27570-000'),
    array('name' => 'Francisca Vanessa Oliveira Gomes', 'street' => 'Rua Papa Paulo VI, 31', 'complement' => '', 'neighborhood' => 'Jardim Amália', 'city' => 'Volta Redonda', 'state' => 'RJ', 'zip' => '27251-340'),
    array('name' => 'FRANCISCO TOMAZI MANOEL', 'street' => 'Rua Curitiba, 110', 'complement' => 'Ouro Verde', 'neighborhood' => 'Vista Alegre', 'city' => 'Nova Iguaçu', 'state' => 'RJ', 'zip' => '26262-120'),
    array('name' => 'GABRIEL CARDOZO DA SILVA', 'street' => 'Estrada Governador Leonel Brizola, 65', 'complement' => '', 'neighborhood' => 'Palhada', 'city' => 'Nova Iguaçu', 'state' => 'RJ', 'zip' => '26290-012'),
    array('name' => 'GABRIELA CARDOSO LOBATO', 'street' => 'Estrada da Paciência, 28', 'complement' => 'casa', 'neighborhood' => 'Paciência', 'city' => 'Rio de Janeiro', 'state' => 'RJ', 'zip' => '23580-250'),
    array('name' => 'Gabriela Cristina de Oliveira Gonçalves', 'street' => 'Rua Tancredo Rodrigues de Paula, 28', 'complement' => '', 'neighborhood' => 'Vila Nova', 'city' => 'Barra Mansa', 'state' => 'RJ', 'zip' => '27321-640'),
    array('name' => 'GABRIELA DA SILVA GOMES', 'street' => 'Rua Damasco, 30', 'complement' => '', 'neighborhood' => 'Wona', 'city' => 'Belford Roxo', 'state' => 'RJ', 'zip' => '26183-040'),
    array('name' => 'Gabriela Gouvêa Muniz Alves', 'street' => 'Rua Isaura Ana de Carvalho, s/n', 'complement' => '', 'neighborhood' => 'Santa Cruz II', 'city' => 'Volta Redonda', 'state' => 'RJ', 'zip' => '27288-450'),
    array('name' => 'GABRIELA MEDINA FRANCA COSTA LTDA', 'street' => 'RUA BENEDITO VALADARES, 36 A', 'complement' => 'LOJA 04', 'neighborhood' => 'CENTRO', 'city' => 'Sete Lagoas', 'state' => 'MG', 'zip' => '35700-055'),
    array('name' => 'GABRIELA VITÓRIA NUNES', 'street' => 'Rua Joaquim de Queiroz, 95', 'complement' => '', 'neighborhood' => 'Ramos', 'city' => 'Rio de Janeiro', 'state' => 'RJ', 'zip' => '21061-610'),
    array('name' => 'GABRIELE RIBEIRO GOMES', 'street' => 'Rua Projetada A, 136', 'complement' => '', 'neighborhood' => 'Parque São Domingos', 'city' => 'Campos dos Goytacazes', 'state' => 'RJ', 'zip' => '28085-270'),
    array('name' => 'Gabriella Lorraine Ribeiro Fernandes', 'street' => 'rua 12, 1309', 'complement' => '', 'neighborhood' => 'Jardim das Acácias', 'city' => 'Porto Real', 'state' => 'RJ', 'zip' => '27570-000'),
    array('name' => 'Gabriella Queiroga Bairos de Castro', 'street' => 'Estrada Capenha, 845', 'complement' => 'apartamento 503', 'neighborhood' => 'Pechincha', 'city' => 'Rio de Janeiro', 'state' => 'RJ', 'zip' => '22743-041'),
    array('name' => 'GABRIELLI FERREIRA DOS SANTOS', 'street' => 'Rua Oswaldo Cruz, S/N', 'complement' => 'LT 16 QD L - Casa 2', 'neighborhood' => 'Pilar', 'city' => 'Duque de Caxias', 'state' => 'RJ', 'zip' => '25235-230'),
    array('name' => 'Geissi Kelli de Assis Firmino', 'street' => 'Rua Diamantina, 314', 'complement' => 'Casa', 'neighborhood' => 'Tinguazinho', 'city' => 'Nova Iguaçu', 'state' => 'RJ', 'zip' => '26080-290'),
    array('name' => 'GEOVANNA DA SILVA RIBEIRO', 'street' => 'Rua São João, 03', 'complement' => '', 'neighborhood' => 'Cariacica Sede', 'city' => 'Cariacica', 'state' => 'ES', 'zip' => '29156-970'),
    array('name' => 'Gessilene Gomes da Silva', 'street' => 'Rua 24 Sebastião Romero, 276', 'complement' => '', 'neighborhood' => 'Califórnia da Barra', 'city' => 'Barra do Piraí', 'state' => 'RJ', 'zip' => '27163-000'),
    array('name' => 'GEYZA NOVAIS DA CUNHA', 'street' => 'Avenida Professor Darcy Ribeiro, 145', 'complement' => 'C06', 'neighborhood' => 'Mirante das Agulhas', 'city' => 'Resende', 'state' => 'RJ', 'zip' => '27524-500'),
    array('name' => 'GILDSON SILVA DE FARIA', 'street' => 'Rua 39, 151', 'complement' => '', 'neighborhood' => 'Freitas Soares', 'city' => 'Porto Real', 'state' => 'RJ', 'zip' => '27570-000'),
    array('name' => 'GILMAR DA SILVA ROCHA', 'street' => 'Avenida Juscelino Kubitschek de Oliveira, 206', 'complement' => '', 'neighborhood' => 'Fazenda da Barra 2', 'city' => 'Resende', 'state' => 'RJ', 'zip' => '27540-250'),
    array('name' => 'GILMAR FERREIRA GOMES', 'street' => 'Rua Guaporé, 25', 'complement' => '', 'neighborhood' => 'Amapá', 'city' => 'Duque de Caxias', 'state' => 'RJ', 'zip' => '25235-490'),
    array('name' => 'GILMAR SILVA CALIXTO', 'street' => 'Rua Vilage Campo da Paz, 238', 'complement' => '', 'neighborhood' => 'Curicica', 'city' => 'Rio de Janeiro', 'state' => 'RJ', 'zip' => '22780-214'),
    array('name' => 'Gilmara Gonçalves', 'street' => 'Rua Morgado, 35', 'complement' => '', 'neighborhood' => 'Centro', 'city' => 'Belford Roxo', 'state' => 'RJ', 'zip' => '26112-025'),
    array('name' => 'Giovana Lino Antunes', 'street' => 'Rua Doce Paraíso, 56', 'complement' => '101', 'neighborhood' => 'Jacuacanga', 'city' => 'Angra dos Reis', 'state' => 'RJ', 'zip' => '23914-065'),
    array('name' => 'GIOVANNA GRAZIELE DOS SANTOS', 'street' => 'Avenida Juscelino Kubitschek, 6701', 'complement' => 'bloco 31, Apt 42', 'neighborhood' => 'Vila Industrial', 'city' => 'São José dos Campos', 'state' => 'SP', 'zip' => '49064-175'),
    array('name' => 'GISELE CONCEIÇÃO', 'street' => 'Rua Lindolpho Godinho, s/n', 'complement' => 'Lote 36', 'neighborhood' => 'Granja Spinelli', 'city' => 'Nova Friburgo', 'state' => 'RJ', 'zip' => '28625-810'),
    array('name' => 'Gisele Sacoman dos Santos', 'street' => 'Estrada São Cristóvão, Travessa Sete, 189', 'complement' => 'Chácara', 'neighborhood' => 'Jundiaquara', 'city' => 'Araçoiaba da Serra', 'state' => 'SP', 'zip' => '18190-000'),
    array('name' => 'GISELE SILVA MACHADO AMARO', 'street' => 'Rua Dona Arcidia, 79', 'complement' => 'bloco 3 apt 203', 'neighborhood' => 'Santa Isabel', 'city' => 'Resende', 'state' => 'RJ', 'zip' => '27522-030'),
    array('name' => 'GISELI RODRIGUES CORREA', 'street' => 'Avenida dos Bandeirantes, 6', 'complement' => '', 'neighborhood' => 'Lagomar', 'city' => 'Macaé', 'state' => 'RJ', 'zip' => '27966-540'),
    array('name' => 'Gislaine Aparecida da Silva', 'street' => 'Rua Joaquim Augusto Barreiros, 125', 'complement' => 'Casa', 'neighborhood' => 'Residencial Jardim Brasil II', 'city' => 'Pouso Alegre', 'state' => 'MG', 'zip' => '37550-709'),
    array('name' => 'Gislaine Silva de Oliveira Rosa', 'street' => 'Rua Tulipas, 148', 'complement' => '', 'neighborhood' => 'Parque das Flores', 'city' => 'São Paulo', 'state' => 'SP', 'zip' => '08391-460'),
    array('name' => 'GLEICE DE OLIVEIRA ARCANJO', 'street' => 'Rua Jair Teixeira Gonçalves, lote 12', 'complement' => '', 'neighborhood' => 'Brisa Mar', 'city' => 'Itaguaí', 'state' => 'RJ', 'zip' => '23825-405'),
    array('name' => 'Gracielli dos Santos Horta Freitas', 'street' => 'Travessa Nossa Senhora da Aparecida, 31', 'complement' => '', 'neighborhood' => 'Getúlio Vargas', 'city' => 'Barra Mansa', 'state' => 'RJ', 'zip' => '27325-530'),
    array('name' => 'GRAZIELE DE PAULA BELÉM LIMA', 'street' => 'Rua 13', 'complement' => '', 'neighborhood' => 'Jardim das Acácias', 'city' => 'Porto Real', 'state' => 'RJ', 'zip' => '27570-000'),
    array('name' => 'Guilherme da Silva Benício', 'street' => 'Rua Um, 275', 'complement' => '', 'neighborhood' => 'Jardim Manchete', 'city' => 'Itatiaia', 'state' => 'RJ', 'zip' => '27580-000'),
    // Páginas 32-40 (parcial - contexto extenso, continuando com nomes-chave)
    array('name' => 'Guilherme Piva Sarjorato', 'street' => 'Rua Siqueira Campos, 2489', 'complement' => '', 'neighborhood' => 'Novo Jardim Stábile', 'city' => 'Birigui', 'state' => 'SP', 'zip' => '16204-070'),
    array('name' => 'GUILHERME SALLES LEMOS CAMPOS', 'street' => 'Rua Cândida Maria da Conceição, 26', 'complement' => '', 'neighborhood' => 'Campo Lindo', 'city' => 'Seropédica', 'state' => 'RJ', 'zip' => '23898-003'),
    array('name' => 'GUSTAVO PEREIRA DA SILVA DO ROSARIO', 'street' => 'Rua Adrisalio Guimarães, 68', 'complement' => '', 'neighborhood' => 'Barão de Juparana', 'city' => 'Valença', 'state' => 'RJ', 'zip' => '27640-000'),
    array('name' => 'Harleson dos Santos Gomes', 'street' => 'Travessa São Pedro, 14', 'complement' => '', 'neighborhood' => 'Mangueira', 'city' => 'Rio de Janeiro', 'state' => 'RJ', 'zip' => '20941-550'),
    array('name' => 'Hayama Rodrigues Martins Coelho', 'street' => 'Oby Loiola, 177', 'complement' => '', 'neighborhood' => 'Campo Alegre', 'city' => 'Itatiaia', 'state' => 'RJ', 'zip' => '27580-000'),
    array('name' => 'Helaine Morais de Oliveira', 'street' => 'Rua das Moças, 0', 'complement' => 'LT1 QD3', 'neighborhood' => 'Queimados', 'city' => 'Rio de Janeiro', 'state' => 'RJ', 'zip' => '27327-230'),
    array('name' => 'HELBER OLIVEIRA SANTOS', 'street' => 'Rua Allan Kardec, 175', 'complement' => '', 'neighborhood' => 'Mar e Céu', 'city' => 'Guarujá', 'state' => 'SP', 'zip' => '11443-060'),
    array('name' => 'Hélio Procópio Fagundes', 'street' => 'Rua Oitocentos e Trinta e Cinco, 30', 'complement' => '', 'neighborhood' => 'Jd Tiradentes', 'city' => 'Volta Redonda', 'state' => 'RJ', 'zip' => '27258-390'),
    array('name' => 'HELOIZE VITORIA OLIVEIRA LIMA', 'street' => 'Rua Carlos Facchina, 44', 'complement' => '', 'neighborhood' => 'Americanópolis', 'city' => 'São Paulo', 'state' => 'SP', 'zip' => '04427-020'),
    array('name' => 'HENRIQUE VAILLANT AMORIM', 'street' => 'Rua Visconde de Itamarati, 80', 'complement' => 'apt 104 - bloco 01', 'neighborhood' => 'Maracanã', 'city' => 'Rio de Janeiro', 'state' => 'RJ', 'zip' => '20550-140'),
    array('name' => 'HEVILYN CRISTIANA DA SILVA PEREIRA', 'street' => 'Rua Avião Muniz, 282', 'complement' => '', 'neighborhood' => 'Jardim Souto', 'city' => 'São José dos Campos', 'state' => 'SP', 'zip' => '12227-100'),
    array('name' => 'Hugo Teixeira Fernandes da Silva', 'street' => 'Rua Dona Moura, 128', 'complement' => '', 'neighborhood' => 'Adrianópolis', 'city' => 'Nova Iguaçu', 'state' => 'RJ', 'zip' => '26053-710'),
    array('name' => 'HYGOR DA SILVA HUGUENIN', 'street' => 'Rua Cristóvão Colombo, 161', 'complement' => '', 'neighborhood' => 'Jardim Amália', 'city' => 'Volta Redonda', 'state' => 'RJ', 'zip' => '27251-025'),
    array('name' => 'IAEMA APARECIDA EUGENIO DA SILVA', 'street' => 'Rua antonio florenciano, 119', 'complement' => '', 'neighborhood' => 'Fazenda da Barra', 'city' => 'Resende', 'state' => 'RJ', 'zip' => '27537-230'),
    array('name' => 'Iara Dos Santos Gonçalves', 'street' => 'Beco do Nato, 18', 'complement' => '', 'neighborhood' => 'Bonsucesso', 'city' => 'Rio de Janeiro', 'state' => 'RJ', 'zip' => '21040-490'),
    array('name' => 'Ibrahim Melo Brandão', 'street' => 'Rua Grajaú, 433', 'complement' => 'casa', 'neighborhood' => 'Cerâmica', 'city' => 'Nova Iguaçu', 'state' => 'RJ', 'zip' => '26030-650'),
    array('name' => 'Igor Yahnn Neves de Carvalho', 'street' => 'Rua Felipe Bruno, 55', 'complement' => '', 'neighborhood' => 'Jardim Tropical', 'city' => 'Resende', 'state' => 'RJ', 'zip' => '27541-270'),
    array('name' => 'ILAN DUARTE ALVES', 'street' => 'Rua Cesário Lange, 184', 'complement' => '', 'neighborhood' => 'Parque Roseira', 'city' => 'Carapicuíba', 'state' => 'SP', 'zip' => '06385-170'),
    array('name' => 'INDIANE CHRISTINE MORAIS DE SOUZA', 'street' => 'Rua Jaime vignoli, 75', 'complement' => '', 'neighborhood' => 'Praia Grande', 'city' => 'Arraial do Cabo', 'state' => 'RJ', 'zip' => '28930-000'),
    array('name' => 'Iohana Vivian Santiago dos Santos', 'street' => 'Rua Barão do Piabanha, 36', 'complement' => '', 'neighborhood' => 'Centro', 'city' => 'Paraíba do Sul', 'state' => 'RJ', 'zip' => '25850-000'),
    array('name' => 'IRLEI RIBEIRO SOUZA', 'street' => 'Rua Seis, 46', 'complement' => '', 'neighborhood' => 'Surubi', 'city' => 'Resende', 'state' => 'RJ', 'zip' => '27512-010'),
    array('name' => 'ISABEL CRISTINA PERES GARCIA DE MOURA GUEDES', 'street' => 'Travessa Salgueiro, 197', 'complement' => '', 'neighborhood' => 'Parque Mambucaba (Mambucaba)', 'city' => 'Angra dos Reis', 'state' => 'RJ', 'zip' => '23954-245'),
    array('name' => 'Isabella Cristina Santos Soares', 'street' => 'Rua São João Batista, 27', 'complement' => 'ap 703', 'neighborhood' => 'Botafogo', 'city' => 'Rio de Janeiro', 'state' => 'RJ', 'zip' => '22270-030'),
    array('name' => 'ISABELLE FIGUEIRA DA SILVA', 'street' => 'Rua Marcondes da Luz, 152', 'complement' => '', 'neighborhood' => 'Senador Vasconcelos', 'city' => 'Rio de Janeiro', 'state' => 'RJ', 'zip' => '23013-430'),
    array('name' => 'IVAN DE SOUZA MORAES', 'street' => 'Rua Dezoito, 145', 'complement' => '', 'neighborhood' => 'Piteiras', 'city' => 'Barra Mansa', 'state' => 'RJ', 'zip' => '27331-340'),
    array('name' => 'IZABEL CRISTINA DOS SANTOS MACIEL', 'street' => 'Estrada do Viegas, 597', 'complement' => '', 'neighborhood' => 'Senador Camará', 'city' => 'Rio de Janeiro', 'state' => 'RJ', 'zip' => '21832-006'),
    array('name' => 'Izabela de Castro Ferreira Saraiva', 'street' => 'Rua Treze, 377', 'complement' => '', 'neighborhood' => 'Jardim Itatiaia', 'city' => 'Itatiaia', 'state' => 'RJ', 'zip' => '27580-000'),
    array('name' => 'Izaías de Souza Ferreira', 'street' => 'Rua 16, 08', 'complement' => '', 'neighborhood' => 'Freitas Soares', 'city' => 'Porto Real', 'state' => 'RJ', 'zip' => '27570-000'),
    array('name' => 'JACKSON BARRETO DE LIMA', 'street' => 'Estrada José Carlos Ribeiro Lopes, s/n', 'complement' => '', 'neighborhood' => 'Maringá', 'city' => 'Belford Roxo', 'state' => 'RJ', 'zip' => '26173-310'),
    array('name' => 'Jainine Alice Silva dos Santos Moreira', 'street' => 'Rua dos Eucaliptos, 97', 'complement' => 'Casa', 'neighborhood' => 'Moinho de Vento', 'city' => 'Barra Mansa', 'state' => 'RJ', 'zip' => '27337-150'),
    array('name' => 'Janaína Fernandes da Cruz', 'street' => 'Travessa Paulino Bazani, 89 - A', 'complement' => 'Ap 201', 'neighborhood' => 'Recreio dos Bandeirantes (Terreirão)', 'city' => 'Rio de Janeiro', 'state' => 'RJ', 'zip' => '22795-135'),
    array('name' => 'Janaína Rodrigues Felisberto', 'street' => 'Rua Murilo Costa, 29', 'complement' => '', 'neighborhood' => 'Jardim Metrópole', 'city' => 'São João de Meriti', 'state' => 'RJ', 'zip' => '25571-240'),
    array('name' => 'JANAINA SILVA FREITAS', 'street' => 'Rua H, 27', 'complement' => 'urbis 4', 'neighborhood' => 'Zabelê', 'city' => 'Vitória da Conquista', 'state' => 'BA', 'zip' => '45077-056'),
    array('name' => 'JANE REIS DA SILVA', 'street' => 'Rua Apligio Osório, 46', 'complement' => '', 'neighborhood' => 'Ajuda de Baixo', 'city' => 'Macaé', 'state' => 'RJ', 'zip' => '27971-270'),
    array('name' => 'Janete da Silva Fabris', 'street' => 'Rua Grajaú, 433', 'complement' => '', 'neighborhood' => 'Cerâmica', 'city' => 'Nova Iguaçu', 'state' => 'RJ', 'zip' => '26030-650'),
    array('name' => 'JANETE SANTOS ALELUIA', 'street' => 'Rua Jonas Costa Pereira, s/n', 'complement' => '', 'neighborhood' => 'Centro', 'city' => 'Itaguaí', 'state' => 'RJ', 'zip' => '23815-100'),
    array('name' => 'JAQUELINE ALVES PEREIRA', 'street' => 'Rua E, 20', 'complement' => '', 'neighborhood' => 'Candelária', 'city' => 'Volta Redonda', 'state' => 'RJ', 'zip' => '27286-380'),
    array('name' => 'Jaqueline Nascimento Iwashima', 'street' => 'Rua José Bonelli, 211', 'complement' => '', 'neighborhood' => 'Conceição', 'city' => 'Miguel Pereira', 'state' => 'RJ', 'zip' => '26900-000'),
    array('name' => 'JAQUELINE OLIVEIRA SILVA SANTOS', 'street' => 'Rua Luís Madrazo, s/n', 'complement' => '', 'neighborhood' => 'Jardim Vaz de Lima', 'city' => 'São Paulo', 'state' => 'SP', 'zip' => '05833-200'),
    array('name' => 'JEAN KLEBER DA SILVA', 'street' => 'Estrada do Piavu, 4000', 'complement' => '', 'neighborhood' => 'Camburi', 'city' => 'São Sebastião', 'state' => 'SP', 'zip' => '11619-392'),
    array('name' => 'JEFFERSON DOMINGOS DOS SANTOS', 'street' => 'Avenida Otoniel Gomes Tavares, 105', 'complement' => '', 'neighborhood' => 'São José do Barreto', 'city' => 'Macaé', 'state' => 'RJ', 'zip' => '27965-055'),
    array('name' => 'Jefferson Duarte da Silva Cordeiro', 'street' => 'Rua Professora Emília Pinheiro Cordeiro, 61', 'complement' => '', 'neighborhood' => 'Volta Grande', 'city' => 'Volta Redonda', 'state' => 'RJ', 'zip' => '27211-690'),
    array('name' => 'JENIFER APARECIDA DE SOUZA PALMA', 'street' => 'Rua General Góes Monteiro, 1418', 'complement' => '', 'neighborhood' => 'Cruz', 'city' => 'Lorena', 'state' => 'SP', 'zip' => '12606-490'),
    array('name' => 'JENIFER DA SILVA BARBOSA', 'street' => 'Rua Belvedere, 2', 'complement' => '', 'neighborhood' => 'Tijuca', 'city' => 'Rio de Janeiro', 'state' => 'RJ', 'zip' => '20530-220'),
    array('name' => 'JENIFER DE FREITAS SILVA', 'street' => 'Rua Oitenta e Três, 24', 'complement' => 'Lt24 qd83', 'neighborhood' => 'Guaratiba', 'city' => 'Rio de Janeiro', 'state' => 'RJ', 'zip' => '23033-140'),
    array('name' => 'Jenifer Luara Silveira Pinto', 'street' => 'Avenida Dorival Marcondes Godoy, 175', 'complement' => '', 'neighborhood' => 'Fazenda Castelo', 'city' => 'Resende', 'state' => 'RJ', 'zip' => '27535-320'),
    array('name' => 'JENIKELLY DOS SANTOS OSÓRIO', 'street' => 'Rua Luiz Winter, S/N', 'complement' => '', 'neighborhood' => 'Duarte Silveira', 'city' => 'Petrópolis', 'state' => 'RJ', 'zip' => '25665-431'),
    array('name' => 'JENNIFER DE PAULA', 'street' => 'Rua Vereador José Fortes, 309', 'complement' => '', 'neighborhood' => 'Centro', 'city' => 'Nilópolis', 'state' => 'RJ', 'zip' => '26535-670'),
    array('name' => 'Jeremias Silveira', 'street' => 'Rua Dr. Lauro Miller Bueno, 05', 'complement' => '', 'neighborhood' => 'Vila Marina', 'city' => 'Porto Real', 'state' => 'RJ', 'zip' => '27570-000'),
    array('name' => 'JESSICA COSTA DE OLIVEIRA', 'street' => 'Estrada do Catruz, 360', 'complement' => '', 'neighborhood' => 'Pedra de Guaratiba', 'city' => 'Rio de Janeiro', 'state' => 'RJ', 'zip' => '23026-280'),
    array('name' => 'JÉSSICA DA SILVA', 'street' => 'Servidão São Vicente de Paula, 319', 'complement' => '', 'neighborhood' => 'Siderlandia', 'city' => 'Barra Mansa', 'state' => 'RJ', 'zip' => '27350-310'),
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
