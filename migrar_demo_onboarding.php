<?php
/**
 * Migrar: cria um cadastro DEMO no Onboarding pra Amanda visualizar
 * a página da estagiária sem precisar criar manualmente.
 *
 * Acesso: ?key=fsa-hub-deploy-2026
 * Retorna: link da página demo pronta pra abrir.
 */
$expectedKey = 'fsa-hub-deploy-2026';
if (($_GET['key'] ?? '') !== $expectedKey) {
    http_response_code(403);
    exit('Acesso negado.');
}

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';

header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

// Self-heal das tabelas (caso ainda nao tenha rodado o admin)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS colaboradores_onboarding (
        id INT AUTO_INCREMENT PRIMARY KEY,
        token VARCHAR(48) NOT NULL UNIQUE,
        nome_completo VARCHAR(150) NOT NULL,
        data_nascimento DATE NOT NULL,
        cpf VARCHAR(14) NULL,
        email_institucional VARCHAR(150) NULL,
        senha_inicial VARCHAR(80) NULL,
        kit_descricao TEXT NULL,
        modalidade VARCHAR(20) NULL,
        dias_trabalho VARCHAR(150) NULL,
        horario_inicio TIME NULL,
        horario_fim TIME NULL,
        setor VARCHAR(150) NULL,
        cargo VARCHAR(150) NULL,
        perfil_cargo VARCHAR(40) NULL,
        tipo_remuneracao VARCHAR(30) NULL,
        valor_remuneracao DECIMAL(10,2) NULL,
        data_pagamento VARCHAR(50) NULL,
        beneficios TEXT NULL,
        mensagem_pessoal TEXT NULL,
        link_contrato_url VARCHAR(500) NULL,
        aceite_em DATETIME NULL,
        aceite_ip VARCHAR(45) NULL,
        ultimo_acesso_em DATETIME NULL,
        status ENUM('pendente','ativo','aceito','arquivado') NOT NULL DEFAULT 'pendente',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        created_by INT NULL,
        INDEX idx_status (status), INDEX idx_token (token)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE colaboradores_onboarding ADD COLUMN cpf VARCHAR(14) NULL"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE colaboradores_onboarding ADD COLUMN perfil_cargo VARCHAR(40) NULL"); } catch (Exception $e) {}
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS colaboradores_documentos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        colaborador_id INT NOT NULL,
        tipo VARCHAR(60) NOT NULL,
        status VARCHAR(40) NOT NULL DEFAULT 'pendente',
        dados_admin_json LONGTEXT NULL,
        dados_estagiario_json LONGTEXT NULL,
        assinatura_estagiario_em DATETIME NULL,
        assinatura_estagiario_ip VARCHAR(45) NULL,
        assinatura_estagiario_nome VARCHAR(200) NULL,
        assinatura_admin_em DATETIME NULL,
        assinatura_admin_user_id INT NULL,
        pdf_drive_file_id VARCHAR(120) NULL,
        pdf_drive_url VARCHAR(500) NULL,
        pdf_html_snapshot LONGTEXT NULL,
        criado_por INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_col (colaborador_id), INDEX idx_tipo (tipo), INDEX idx_status (status),
        UNIQUE KEY uniq_colab_tipo (colaborador_id, tipo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

// Remove demo anterior (pra garantir token novo limpo) — identifica pelo nome fixo
try {
    $stmtOld = $pdo->prepare("SELECT id FROM colaboradores_onboarding WHERE nome_completo = ? LIMIT 1");
    $stmtOld->execute(array('Maria Estagiária Demo'));
    $oldId = (int)$stmtOld->fetchColumn();
    if ($oldId) {
        $pdo->prepare("DELETE FROM colaboradores_documentos WHERE colaborador_id = ?")->execute(array($oldId));
        $pdo->prepare("DELETE FROM colaboradores_onboarding WHERE id = ?")->execute(array($oldId));
        echo "[INFO] cadastro demo anterior removido (id=$oldId)\n";
    }
} catch (Exception $e) {
    echo "[WARN] erro removendo antigo: " . $e->getMessage() . "\n";
}

// Cria novo cadastro demo
$token = bin2hex(random_bytes(16));
$cpf = '123.456.789-00';
$senha = '123456789@';

try {
    $pdo->prepare("INSERT INTO colaboradores_onboarding
        (token, nome_completo, data_nascimento, genero, cpf, email_institucional, senha_inicial,
         kit_descricao, modalidade, local_presencial, dias_trabalho, horario_inicio, horario_fim,
         setor, cargo, perfil_cargo, tipo_remuneracao, valor_remuneracao,
         data_pagamento, beneficios, mensagem_pessoal, status)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute(array(
            $token,
            'Maria Estagiária Demo',
            '2002-05-15',
            'F',
            $cpf,
            'maria.demo@ferreiraesa.com.br',
            $senha,
            "Caneca personalizada F&S\nCaderno de anotações\nCaneta\nCamiseta tamanho M\nSerá entregue em até 7 dias úteis",
            'Híbrido',
            'Barra Mansa/RJ — Rua Dr. Aldrovando de Oliveira, 140 — Ano Bom',
            'Segunda a sexta',
            '13:00:00',
            '19:00:00',
            'Família e Sucessões',
            'Estagiária de Direito',
            'estagiario',
            'Bolsa de estágio',
            1500.00,
            'Todo dia 5 do mês seguinte ao trabalhado',
            "Vale-transporte\nLanche no escritório\nDay-off no aniversário\nPlano de saúde após período de experiência",
            "Maria, é uma alegria ter você na nossa equipe! 💜\n\nPreparamos essa página com muito carinho para você se sentir parte do escritório desde o primeiro dia. Qualquer dúvida, fale com a gente no WhatsApp.\n\nDra. Amanda Ferreira e Dr. Luiz Eduardo de Sá",
            'pendente'
        ));
    $colabId = (int)$pdo->lastInsertId();
    echo "[OK] cadastro demo criado (id=$colabId)\n";
} catch (Exception $e) {
    echo "[ERRO] criando cadastro: " . $e->getMessage() . "\n";
    exit;
}

// Vincula os 4 documentos do estagiario
$docsTipos = array('compromisso_estagio', 'confidencialidade_lgpd', 'pop_estagiario', 'checklist_admissional_estagiario');
foreach ($docsTipos as $tipo) {
    try {
        $pdo->prepare("INSERT IGNORE INTO colaboradores_documentos
            (colaborador_id, tipo, status) VALUES (?, ?, 'pendente')")
            ->execute(array($colabId, $tipo));
        echo "[OK] doc vinculado: $tipo\n";
    } catch (Exception $e) {
        echo "[ERRO] vinculando $tipo: " . $e->getMessage() . "\n";
    }
}

// Monta link
$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
$basePath = rtrim(dirname($_SERVER['PHP_SELF']), '/');
$linkDemo = $baseUrl . $basePath . '/publico/onboarding/?token=' . $token;

echo "\n";
echo "===============================================================\n";
echo "  CADASTRO DEMO PRONTO!\n";
echo "===============================================================\n";
echo "\n";
echo "🔗 LINK DA DEMO:\n";
echo "   $linkDemo\n";
echo "\n";
echo "🔐 PARA ENTRAR:\n";
echo "   Nome completo:        Maria Estagiária Demo\n";
echo "   Data de nascimento:   15/05/2002\n";
echo "\n";
echo "📋 DOCUMENTOS VINCULADOS (todos pendentes):\n";
foreach ($docsTipos as $tipo) echo "   - $tipo\n";
echo "\n";
echo "Bom teste! 💜\n";
