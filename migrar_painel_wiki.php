<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== MIGRAÇÃO: Painel do Dia + Wiki ===\n\n";

// 1. eventos_dia
echo "--- eventos_dia ---\n";
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS eventos_dia (
        id INT AUTO_INCREMENT PRIMARY KEY,
        usuario_id INT NOT NULL,
        tipo ENUM('audiencia','prazo','tarefa','lembrete') NOT NULL DEFAULT 'lembrete',
        titulo VARCHAR(200) NOT NULL,
        data_evento DATE NOT NULL,
        hora_inicio TIME DEFAULT NULL,
        hora_fim TIME DEFAULT NULL,
        processo_ref VARCHAR(50) DEFAULT NULL,
        link_externo VARCHAR(500) DEFAULT NULL,
        prioridade ENUM('normal','urgente','fatal') NOT NULL DEFAULT 'normal',
        concluido TINYINT(1) NOT NULL DEFAULT 0,
        criado_por INT NOT NULL,
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_usuario_data (usuario_id, data_evento)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] Tabela eventos_dia\n";
} catch (Exception $e) { echo "[INFO] " . $e->getMessage() . "\n"; }

// 2. wiki_artigos
echo "\n--- wiki_artigos ---\n";
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS wiki_artigos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        titulo VARCHAR(200) NOT NULL,
        conteudo LONGTEXT NOT NULL,
        categoria VARCHAR(50) NOT NULL,
        tags VARCHAR(300) DEFAULT NULL,
        autor_id INT NOT NULL,
        visualizacoes INT NOT NULL DEFAULT 0,
        fixado TINYINT(1) NOT NULL DEFAULT 0,
        ativo TINYINT(1) NOT NULL DEFAULT 0,
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        atualizado_em DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        FULLTEXT INDEX idx_busca (titulo, conteudo, tags)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] Tabela wiki_artigos\n";
} catch (Exception $e) { echo "[INFO] " . $e->getMessage() . "\n"; }

// 3. wiki_versoes
echo "\n--- wiki_versoes ---\n";
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS wiki_versoes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        artigo_id INT NOT NULL,
        conteudo_anterior LONGTEXT NOT NULL,
        editado_por INT NOT NULL,
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_artigo (artigo_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "[OK] Tabela wiki_versoes\n";
} catch (Exception $e) { echo "[INFO] " . $e->getMessage() . "\n"; }

// 4. Artigos iniciais
echo "\n--- Artigos iniciais ---\n";
$chk = (int)$pdo->query("SELECT COUNT(*) FROM wiki_artigos")->fetchColumn();
if ($chk === 0) {
    $adminId = (int)$pdo->query("SELECT id FROM users WHERE role='admin' ORDER BY id LIMIT 1")->fetchColumn() ?: 1;
    $arts = array(
        array('Como cadastrar um novo cliente', 'Processos Internos', "## Passo a passo\n\n1. Acesse **CRM > Novo Cliente**\n2. Preencha nome, CPF, telefone e e-mail\n3. O sistema busca automaticamente dados por CPF\n4. Salve e o cliente aparecerá na lista\n\n### Dica\nUse a busca por CPF para não duplicar cadastros.", 'cliente,cadastro,onboarding', 1, 1),
        array('Fluxo do Kanban Comercial', 'Processos Internos', "## Etapas do Pipeline\n\n1. **Cadastro Preenchido** — Lead entra pelo formulário\n2. **Elaboração Docs** — Equipe prepara documentação\n3. **Link Enviados** — Documentos enviados ao cliente\n4. **Contrato Assinado** — Cliente assinou\n5. **Pasta Apta** — Documentação completa\n6. **Finalizado** — Caso operacional criado\n\n### Regra\nAo mover para Pasta Apta, o caso é criado automaticamente no Operacional.", 'kanban,comercial,fluxo', 1, 1),
        array('Regras de Procuração — quem assina o quê', 'Jurídico', "## Regra Geral\n\n- **Alimentos / Execução / Revisional**: procuração no nome da criança (representada pelo genitor)\n- **Guarda / Convivência / Divórcio**: procuração no nome do pai ou mãe contratante\n- **Inventário**: procuração de todos os herdeiros\n\n### Atenção\nNunca usar o termo \"menor\" — usar criança (até 12 anos) ou adolescente (12-18 anos).", 'procuracao,familia,regras', 1, 1),
        array('Como gerar uma petição pela Fábrica', 'Jurídico', "## Acessando\n\n1. Vá em **Fáb. Petições** na sidebar\n2. Escolha o tipo de ação e a peça\n3. Selecione o processo vinculado\n4. Preencha os campos obrigatórios\n5. Clique em **Gerar com IA**\n\n### Download\nApós gerar, clique em **Download Word** para salvar o documento com timbrado.", 'peticao,fabrica,documentos', 1, 0),
        array('Glossário Jurídico Essencial', 'Jurídico', "## Termos Frequentes\n\n- **Autor**: quem propõe a ação\n- **Réu**: contra quem a ação é proposta\n- **Citação**: ato pelo qual se chama o réu ao processo\n- **Intimação**: comunicação de ato processual\n- **Despacho**: decisão administrativa do juiz\n- **Sentença**: decisão que resolve o mérito\n- **Acórdão**: decisão de tribunal (colegiada)\n- **Tutela de urgência**: medida liminar para proteger direito\n- **Execução**: fase de cumprimento da decisão", 'glossario,termos,juridico', 1, 0),
    );
    $stmt = $pdo->prepare("INSERT INTO wiki_artigos (titulo, categoria, conteudo, tags, autor_id, ativo, fixado) VALUES (?,?,?,?,?,?,?)");
    foreach ($arts as $a) {
        $stmt->execute(array($a[0], $a[1], $a[2], $a[3], $adminId, $a[4], $a[5]));
        echo "[OK] Artigo: {$a[0]}\n";
    }
} else {
    echo "[JÁ EXISTE] $chk artigos\n";
}

echo "\n=== MIGRAÇÃO CONCLUÍDA ===\n";
