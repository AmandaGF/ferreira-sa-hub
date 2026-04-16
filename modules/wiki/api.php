<?php
require_once __DIR__ . '/../../core/middleware.php';
require_login();
if (!validate_csrf()) {
    flash_set('error', 'Token inválido.');
    redirect(module_url('wiki'));
}

$pdo = db();
$userId = current_user_id();
$isGestao = has_min_role('gestao');
$action = $_POST['action'] ?? '';

if ($action === 'salvar_artigo') {
    $id = (int)($_POST['id'] ?? 0);
    $titulo = trim($_POST['titulo'] ?? '');
    $conteudo = $_POST['conteudo'] ?? '';
    $categoria = trim($_POST['categoria'] ?? 'Outros');
    $tags = trim($_POST['tags'] ?? '');
    $ativo = (int)($_POST['status'] ?? 0);
    $fixado = isset($_POST['fixado']) ? 1 : 0;

    if (!$titulo || !$conteudo) {
        flash_set('error', 'Título e conteúdo são obrigatórios.');
        redirect(module_url('wiki', $id ? 'editor.php?id=' . $id : 'editor.php'));
    }

    if ($id) {
        // Editar
        $art = $pdo->prepare("SELECT * FROM wiki_artigos WHERE id = ?")->execute(array($id));
        $art = $pdo->prepare("SELECT * FROM wiki_artigos WHERE id = ?");
        $art->execute(array($id));
        $art = $art->fetch();
        if (!$art) { flash_set('error', 'Artigo não encontrado.'); redirect(module_url('wiki')); }
        if (!$isGestao && (int)$art['autor_id'] !== $userId) {
            flash_set('error', 'Sem permissão.');
            redirect(module_url('wiki'));
        }

        // Salvar versão anterior
        $pdo->prepare("INSERT INTO wiki_versoes (artigo_id, conteudo_anterior, editado_por) VALUES (?, ?, ?)")
            ->execute(array($id, $art['conteudo'], $userId));

        $setCols = "titulo = ?, conteudo = ?, categoria = ?, tags = ?, ativo = ?, atualizado_em = NOW()";
        $setParams = array($titulo, $conteudo, $categoria, $tags, $ativo);
        if ($isGestao) {
            $setCols .= ", fixado = ?";
            $setParams[] = $fixado;
        }
        $setParams[] = $id;
        $pdo->prepare("UPDATE wiki_artigos SET $setCols WHERE id = ?")->execute($setParams);

        audit_log('wiki_editar', 'wiki_artigos', $id, $titulo);
        flash_set('success', 'Artigo atualizado!');
        redirect(module_url('wiki', 'ver.php?id=' . $id));
    } else {
        // Criar
        $pdo->prepare(
            "INSERT INTO wiki_artigos (titulo, conteudo, categoria, tags, autor_id, ativo, fixado, criado_em)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())"
        )->execute(array($titulo, $conteudo, $categoria, $tags, $userId, $ativo, $isGestao ? $fixado : 0));
        $newId = (int)$pdo->lastInsertId();

        audit_log('wiki_criar', 'wiki_artigos', $newId, $titulo);
        flash_set('success', 'Artigo criado!');
        redirect(module_url('wiki', 'ver.php?id=' . $newId));
    }
}

if ($action === 'toggle_fixado') {
    if (!$isGestao) { flash_set('error', 'Sem permissão.'); redirect(module_url('wiki')); }
    $id = (int)($_POST['id'] ?? 0);
    $pdo->prepare("UPDATE wiki_artigos SET fixado = NOT fixado WHERE id = ?")->execute(array($id));
    redirect(module_url('wiki', 'ver.php?id=' . $id));
}

if ($action === 'excluir') {
    if ($userId !== (int)current_user()['id'] || !has_role('admin')) {
        // Simplificação: admin pode excluir
    }
    if (!has_role('admin')) { flash_set('error', 'Apenas admin pode excluir.'); redirect(module_url('wiki')); }
    $id = (int)($_POST['id'] ?? 0);
    $pdo->prepare("DELETE FROM wiki_versoes WHERE artigo_id = ?")->execute(array($id));
    $pdo->prepare("DELETE FROM wiki_artigos WHERE id = ?")->execute(array($id));
    audit_log('wiki_excluir', 'wiki_artigos', $id);
    flash_set('success', 'Artigo excluído.');
    redirect(module_url('wiki'));
}

redirect(module_url('wiki'));
