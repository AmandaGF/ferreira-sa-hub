<?php
/**
 * Ferreira & Sá Hub — Campos coletados pelos Fluxos (CRUD de zapi_campo)
 *
 * Cada campo é uma chave canônica usada por blocos `capturar` e `condicional`.
 * O valor por conversa fica em zapi_conversa_valor (UNIQUE conversa+campo).
 * Quem alimenta a Fábrica de Petições lê os campos por chave.
 *
 * Acesso: gestão+.
 */

require_once __DIR__ . '/../../core/middleware.php';
require_once __DIR__ . '/../../core/functions_fluxos.php';
require_login();
require_min_role('gestao');

$pageTitle = 'Campos dos Fluxos';
$pdo = db();

// ── POST handlers ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'criar') {
        $chave = trim($_POST['chave'] ?? '');
        $nome = trim($_POST['nome'] ?? '');
        $tipo = trim($_POST['tipo'] ?? 'texto');
        $descricao = trim($_POST['descricao'] ?? '');
        // Normaliza chave: minúscula, troca espaço/traço por _, remove acentos
        $chaveNorm = mb_strtolower($chave, 'UTF-8');
        $chaveNorm = strtr($chaveNorm, array('á'=>'a','à'=>'a','â'=>'a','ã'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ç'=>'c'));
        $chaveNorm = preg_replace('/[^a-z0-9_]+/', '_', $chaveNorm);
        $chaveNorm = trim($chaveNorm, '_');
        if ($chaveNorm === '' || $nome === '') {
            flash_set('error', 'Chave e nome são obrigatórios.');
        } else {
            // Existe?
            $st = $pdo->prepare("SELECT id FROM zapi_campo WHERE chave = ?");
            $st->execute(array($chaveNorm));
            if ($st->fetchColumn()) {
                flash_set('error', "Chave '$chaveNorm' já existe.");
            } else {
                $pdo->prepare("INSERT INTO zapi_campo (chave, nome, tipo, descricao) VALUES (?, ?, ?, ?)")
                    ->execute(array($chaveNorm, $nome, $tipo ?: 'texto', $descricao ?: null));
                $newId = (int)$pdo->lastInsertId();
                audit_log('zapi_campo_criar', 'zapi_campo', $newId, $chaveNorm);
                flash_set('success', "Campo '$chaveNorm' criado (id=$newId).");
            }
        }
        redirect(module_url('whatsapp', 'campos.php'));
    }

    if ($action === 'editar') {
        $id = (int)($_POST['id'] ?? 0);
        $nome = trim($_POST['nome'] ?? '');
        $tipo = trim($_POST['tipo'] ?? 'texto');
        $descricao = trim($_POST['descricao'] ?? '');
        if ($id > 0 && $nome !== '') {
            $pdo->prepare("UPDATE zapi_campo SET nome=?, tipo=?, descricao=? WHERE id=?")
                ->execute(array($nome, $tipo, $descricao ?: null, $id));
            audit_log('zapi_campo_editar', 'zapi_campo', $id);
            flash_set('success', 'Campo atualizado.');
        }
        redirect(module_url('whatsapp', 'campos.php'));
    }

    if ($action === 'excluir') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $st = $pdo->prepare("SELECT chave FROM zapi_campo WHERE id = ?");
            $st->execute(array($id));
            $chave = (string)$st->fetchColumn();
            // ON DELETE CASCADE remove zapi_conversa_valor
            $pdo->prepare("DELETE FROM zapi_campo WHERE id = ?")->execute(array($id));
            audit_log('zapi_campo_excluir', 'zapi_campo', $id, $chave);
            flash_set('success', "Campo '$chave' excluído (cascade: valores em zapi_conversa_valor).");
        }
        redirect(module_url('whatsapp', 'campos.php'));
    }
}

// ── Lista com contagem de uso ───────────────────────────
$campos = $pdo->query(
    "SELECT c.*,
            (SELECT COUNT(*) FROM zapi_conversa_valor WHERE campo_id = c.id) AS qtd_valores
       FROM zapi_campo c
     ORDER BY c.chave ASC"
)->fetchAll();

require_once APP_ROOT . '/templates/layout_start.php';
?>
<style>
.cp-card { background:var(--bg-card); border:1px solid var(--border); border-radius:12px; padding:1.15rem; margin-bottom:1rem; }
.cp-tbl { width:100%; border-collapse:collapse; font-size:.83rem; }
.cp-tbl th { background:var(--petrol-900); color:#fff; padding:.5rem .75rem; text-align:left; font-size:.65rem; text-transform:uppercase; letter-spacing:.5px; }
.cp-tbl td { padding:.5rem .75rem; border-bottom:1px solid #f1f5f9; vertical-align:top; }
.cp-form input, .cp-form select, .cp-form textarea {
    padding:.45rem .65rem; border:1.5px solid var(--border); border-radius:6px; font-size:.83rem; font-family:inherit;
}
.cp-tipo { display:inline-block; padding:1px 6px; border-radius:10px; font-size:.65rem; font-weight:700; background:#dbeafe; color:#1e40af; }
</style>

<a href="<?= module_url('whatsapp', 'fluxos.php') ?>" class="btn btn-outline btn-sm mb-2">&larr; Voltar aos Fluxos</a>

<div style="display:flex;align-items:center;gap:.5rem;margin-bottom:1rem;">
    <h1 style="margin:0;">📦 Campos dos Fluxos</h1>
    <span style="font-size:.7rem;color:#6b7280;font-style:italic;">zapi_campo · intake estruturado</span>
</div>

<div class="cp-card" style="background:#eff6ff;border-color:#bfdbfe;">
    <p style="margin:0;font-size:.8rem;color:#1e3a8a;">
        <strong>O que é:</strong> cada campo aqui é uma <em>chave</em> que blocos <code>capturar</code> usam pra
        guardar a resposta do cliente, e que blocos <code>condicional</code> avaliam pra decidir saída.
        Valores ficam em <code>zapi_conversa_valor</code> (1 por par conversa+campo). Você pode usar
        <code>{{campo:chave}}</code> dentro de textos de <code>mensagem</code> pra ler o valor de volta.
    </p>
</div>

<!-- Criar -->
<div class="cp-card">
    <h3 style="margin:0 0 .75rem;font-size:.95rem;">➕ Novo campo</h3>
    <form method="POST" class="cp-form" style="display:grid;grid-template-columns:1fr 1.5fr 1fr auto;gap:.5rem;align-items:end;">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="criar">
        <label style="display:flex;flex-direction:column;gap:.2rem;font-size:.7rem;font-weight:700;color:#374151;">
            Chave <span style="color:#dc2626;">*</span>
            <input type="text" name="chave" maxlength="60" required placeholder="ex: telefone_alternativo">
            <small style="font-weight:400;color:#6b7280;">Vira lowercase, _ no lugar de espaço</small>
        </label>
        <label style="display:flex;flex-direction:column;gap:.2rem;font-size:.7rem;font-weight:700;color:#374151;">
            Nome legível <span style="color:#dc2626;">*</span>
            <input type="text" name="nome" maxlength="120" required placeholder="ex: Telefone alternativo">
        </label>
        <label style="display:flex;flex-direction:column;gap:.2rem;font-size:.7rem;font-weight:700;color:#374151;">
            Tipo
            <select name="tipo">
                <option value="texto">texto</option>
                <option value="numero">número</option>
                <option value="data">data</option>
                <option value="email">email</option>
                <option value="telefone">telefone</option>
                <option value="opcao">opção</option>
            </select>
        </label>
        <button type="submit" class="btn btn-primary">Criar</button>
    </form>
    <label style="display:flex;flex-direction:column;gap:.2rem;font-size:.7rem;font-weight:700;color:#374151;margin-top:.5rem;">
        Descrição (opcional)
        <input type="text" name="descricao" form="" placeholder="Ex: usado quando o cliente não atende no número principal" style="padding:.45rem .65rem;border:1.5px solid var(--border);border-radius:6px;font-size:.83rem;">
    </label>
    <p style="font-size:.7rem;color:#6b7280;margin:.4rem 0 0;">
        Tipo é só metadado (não força validação) — o executor sempre trata o valor como string.
        Usa pra documentar e pra Fábrica de Petições renderizar adequadamente no futuro.
    </p>
</div>

<!-- Lista -->
<div class="cp-card" style="padding:0;overflow:hidden;">
    <div style="padding:.85rem 1rem;background:#fafbfc;border-bottom:1px solid var(--border);font-size:.8rem;color:var(--text-muted);">
        <strong style="color:var(--petrol-900);"><?= count($campos) ?> campo(s)</strong>
    </div>
    <?php if (empty($campos)): ?>
        <p style="padding:2rem;text-align:center;color:var(--text-muted);font-size:.9rem;">
            Nenhum campo cadastrado. Use o form acima pra criar o primeiro.
        </p>
    <?php else: ?>
    <table class="cp-tbl">
        <thead>
            <tr>
                <th style="width:40px;">ID</th>
                <th>Chave</th>
                <th>Nome</th>
                <th style="width:80px;">Tipo</th>
                <th>Descrição</th>
                <th style="width:90px;text-align:center;">Valores<br><span style="font-weight:400;font-size:.6rem;">(em uso)</span></th>
                <th style="width:80px;">Ações</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($campos as $c): ?>
            <tr>
                <td style="font-family:monospace;color:#94a3b8;">#<?= (int)$c['id'] ?></td>
                <td><code style="background:#f1f5f9;padding:1px 6px;border-radius:4px;font-size:.78rem;color:#0f766e;"><?= htmlspecialchars($c['chave']) ?></code></td>
                <td><?= htmlspecialchars($c['nome']) ?></td>
                <td><span class="cp-tipo"><?= htmlspecialchars($c['tipo']) ?></span></td>
                <td style="color:#475569;font-size:.78rem;"><?= htmlspecialchars($c['descricao'] ?? '—') ?></td>
                <td style="text-align:center;font-weight:700;color:<?= $c['qtd_valores'] > 0 ? '#0d9488' : '#94a3b8' ?>;"><?= (int)$c['qtd_valores'] ?></td>
                <td>
                    <details>
                        <summary style="cursor:pointer;font-size:.75rem;color:#0d9488;">✏️</summary>
                        <form method="POST" class="cp-form" style="margin-top:.4rem;padding:.4rem;background:#fafbfc;border-radius:6px;">
                            <?= csrf_input() ?>
                            <input type="hidden" name="action" value="editar">
                            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                            <input type="text" name="nome" value="<?= htmlspecialchars($c['nome']) ?>" required style="width:100%;margin-bottom:.3rem;">
                            <select name="tipo" style="width:100%;margin-bottom:.3rem;">
                                <?php foreach (array('texto','numero','data','email','telefone','opcao') as $t): ?>
                                    <option value="<?= $t ?>" <?= $c['tipo'] === $t ? 'selected' : '' ?>><?= $t ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="text" name="descricao" value="<?= htmlspecialchars($c['descricao'] ?? '') ?>" placeholder="Descrição" style="width:100%;margin-bottom:.3rem;">
                            <button type="submit" class="btn btn-primary btn-sm">💾 Salvar</button>
                        </form>
                    </details>
                    <form method="POST" style="display:inline;margin-left:.3rem;" onsubmit="return confirm('Excluir campo <?= htmlspecialchars(addslashes($c['chave'])) ?>?\n\nCascade: <?= (int)$c['qtd_valores'] ?> valor(es) em zapi_conversa_valor TAMBÉM serão removidos.');">
                        <?= csrf_input() ?>
                        <input type="hidden" name="action" value="excluir">
                        <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                        <button type="submit" class="btn btn-outline btn-sm" style="color:#dc2626;">🗑</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
