<?php
/**
 * Ferreira & Sá Hub — Editor de um Fluxo do WhatsApp
 *
 * CRUD inline de cabeçalho + blocos + arestas. Lista as últimas execuções.
 * Acesso: gestão+.
 */

require_once __DIR__ . '/../../core/middleware.php';
require_once __DIR__ . '/../../core/functions_fluxos.php';
require_login();
require_min_role('gestao');

$pdo = db();
$fluxoId = (int)($_GET['id'] ?? 0);
if ($fluxoId <= 0) { flash_set('error', 'ID inválido.'); redirect(module_url('whatsapp', 'fluxos.php')); }

// ── POST handlers ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'salvar_cabecalho') {
        $nome = trim($_POST['nome'] ?? '');
        $descricao = trim($_POST['descricao'] ?? '');
        $canal = trim($_POST['canal'] ?? '');
        $gatilho = trim($_POST['gatilho_tipo'] ?? 'manual');
        $gatilhoCfg = trim($_POST['gatilho_config'] ?? '');
        $blocoInicial = (int)($_POST['bloco_inicial_id'] ?? 0);
        if ($nome === '') { flash_set('error', 'Nome obrigatório.'); }
        else {
            $pdo->prepare(
                "UPDATE zapi_fluxo SET nome=?, descricao=?, canal=?, gatilho_tipo=?, gatilho_config=?, bloco_inicial_id=? WHERE id=?"
            )->execute(array(
                $nome, $descricao ?: null, $canal ?: null, $gatilho,
                $gatilhoCfg ?: null, $blocoInicial ?: null, $fluxoId
            ));
            audit_log('zapi_fluxo_editar', 'zapi_fluxo', $fluxoId);
            flash_set('success', 'Cabeçalho salvo.');
        }
        redirect(module_url('whatsapp', 'fluxo_ver.php?id=' . $fluxoId));
    }

    if ($action === 'add_bloco') {
        $tipo = trim($_POST['tipo'] ?? '');
        $cfg = trim($_POST['config_json'] ?? '{}');
        $posX = (int)($_POST['pos_x'] ?? 0);
        // valida JSON
        $parsed = json_decode($cfg, true);
        if (!in_array($tipo, array('mensagem','esperar','capturar','condicional','transferir_humano','anotar','fim'), true)) {
            flash_set('error', "Tipo '$tipo' não suportado nesta versão.");
        } elseif ($cfg !== '' && $parsed === null && $cfg !== 'null') {
            flash_set('error', 'config_json inválido — não é JSON.');
        } else {
            $pdo->prepare(
                "INSERT INTO zapi_fluxo_bloco (fluxo_id, tipo, config_json, pos_x, pos_y, criado_em) VALUES (?,?,?,?,0,NOW())"
            )->execute(array($fluxoId, $tipo, $cfg ?: '{}', $posX));
            $blocoId = (int)$pdo->lastInsertId();
            audit_log('zapi_fluxo_bloco_add', 'zapi_fluxo_bloco', $blocoId, "tipo=$tipo fluxo=$fluxoId");
            flash_set('success', "Bloco #$blocoId criado ($tipo).");
        }
        redirect(module_url('whatsapp', 'fluxo_ver.php?id=' . $fluxoId));
    }

    if ($action === 'editar_bloco') {
        $blocoId = (int)($_POST['bloco_id'] ?? 0);
        $tipo = trim($_POST['tipo'] ?? '');
        $cfg = trim($_POST['config_json'] ?? '{}');
        $posX = (int)($_POST['pos_x'] ?? 0);
        $parsed = json_decode($cfg, true);
        if ($blocoId <= 0) { flash_set('error', 'ID do bloco inválido.'); }
        elseif (!in_array($tipo, array('mensagem','esperar','capturar','condicional','transferir_humano','anotar','fim'), true)) {
            flash_set('error', "Tipo '$tipo' não suportado.");
        } elseif ($cfg !== '' && $parsed === null && $cfg !== 'null') {
            flash_set('error', 'config_json inválido.');
        } else {
            $pdo->prepare("UPDATE zapi_fluxo_bloco SET tipo=?, config_json=?, pos_x=? WHERE id=? AND fluxo_id=?")
                ->execute(array($tipo, $cfg, $posX, $blocoId, $fluxoId));
            audit_log('zapi_fluxo_bloco_edit', 'zapi_fluxo_bloco', $blocoId);
            flash_set('success', "Bloco #$blocoId salvo.");
        }
        redirect(module_url('whatsapp', 'fluxo_ver.php?id=' . $fluxoId));
    }

    if ($action === 'excluir_bloco') {
        $blocoId = (int)($_POST['bloco_id'] ?? 0);
        if ($blocoId > 0) {
            $pdo->prepare("DELETE FROM zapi_fluxo_bloco WHERE id=? AND fluxo_id=?")->execute(array($blocoId, $fluxoId));
            audit_log('zapi_fluxo_bloco_del', 'zapi_fluxo_bloco', $blocoId);
            flash_set('success', "Bloco #$blocoId removido (arestas que apontavam pra ele também).");
        }
        redirect(module_url('whatsapp', 'fluxo_ver.php?id=' . $fluxoId));
    }

    if ($action === 'add_aresta') {
        $origem = (int)($_POST['origem_bloco_id'] ?? 0);
        $destino = (int)($_POST['destino_bloco_id'] ?? 0);
        $saida = trim($_POST['saida'] ?? 'default') ?: 'default';
        if ($origem <= 0 || $destino <= 0) { flash_set('error', 'Origem e destino obrigatórios.'); }
        elseif ($origem === $destino) { flash_set('error', 'Origem não pode ser igual ao destino.'); }
        else {
            // Evita duplicar mesma (origem, saida)
            $st = $pdo->prepare("SELECT id FROM zapi_fluxo_aresta WHERE fluxo_id=? AND origem_bloco_id=? AND saida=? LIMIT 1");
            $st->execute(array($fluxoId, $origem, $saida));
            if ($st->fetchColumn()) {
                flash_set('error', "Já existe aresta de #$origem com saída '$saida'. Remova ou edite a existente.");
            } else {
                $pdo->prepare(
                    "INSERT INTO zapi_fluxo_aresta (fluxo_id, origem_bloco_id, destino_bloco_id, saida) VALUES (?,?,?,?)"
                )->execute(array($fluxoId, $origem, $destino, $saida));
                $arId = (int)$pdo->lastInsertId();
                audit_log('zapi_fluxo_aresta_add', 'zapi_fluxo_aresta', $arId, "fluxo=$fluxoId");
                flash_set('success', "Aresta $origem -[$saida]-> $destino criada.");
            }
        }
        redirect(module_url('whatsapp', 'fluxo_ver.php?id=' . $fluxoId));
    }

    if ($action === 'excluir_aresta') {
        $arId = (int)($_POST['aresta_id'] ?? 0);
        if ($arId > 0) {
            $pdo->prepare("DELETE FROM zapi_fluxo_aresta WHERE id=? AND fluxo_id=?")->execute(array($arId, $fluxoId));
            audit_log('zapi_fluxo_aresta_del', 'zapi_fluxo_aresta', $arId);
            flash_set('success', "Aresta removida.");
        }
        redirect(module_url('whatsapp', 'fluxo_ver.php?id=' . $fluxoId));
    }

    if ($action === 'disparar_manual') {
        $convId = (int)($_POST['conv_id'] ?? 0);
        if ($convId <= 0) {
            flash_set('error', 'conv_id obrigatório.');
        } else {
            $st = $pdo->prepare("SELECT id, telefone, nome_contato FROM zapi_conversas WHERE id = ?");
            $st->execute(array($convId));
            $convAlvo = $st->fetch();
            if (!$convAlvo) {
                flash_set('error', "Conversa #$convId não existe.");
            } else {
                try {
                    $execId = fluxo_iniciar($fluxoId, $convId, current_user_id());
                    if (!$execId) {
                        flash_set('error', 'Não foi possível iniciar. Fluxo está ativo? Tem bloco inicial?');
                    } else {
                        $res = fluxo_avancar((int)$execId, null);
                        audit_log('zapi_fluxo_disparo_manual', 'zapi_fluxo_execucao', $execId, "fluxo=$fluxoId conv=$convId");
                        flash_set('success', "Disparado em #$convId ({$convAlvo['nome_contato']}). Execução #$execId — estado: " . ($res['estado'] ?? '?'));
                    }
                } catch (Exception $e) {
                    flash_set('error', 'Erro: ' . $e->getMessage());
                }
            }
        }
        redirect(module_url('whatsapp', 'fluxo_ver.php?id=' . $fluxoId));
    }
}

// ── Carrega tudo ────────────────────────────────────────
$grafo = fluxo_carregar($fluxoId);
if (!$grafo) { flash_set('error', "Fluxo #$fluxoId não encontrado."); redirect(module_url('whatsapp', 'fluxos.php')); }
$fluxo = $grafo['fluxo'];
$blocos = $grafo['blocos'];
$pageTitle = 'Fluxo: ' . $fluxo['nome'];

// Arestas indexadas linearmente pra renderização
$st = $pdo->prepare("SELECT * FROM zapi_fluxo_aresta WHERE fluxo_id = ? ORDER BY origem_bloco_id, saida");
$st->execute(array($fluxoId));
$arestas = $st->fetchAll();

// Validação do grafo
$problemas = fluxo_validar_grafo($fluxoId);
$temCritico = false;
foreach ($problemas as $p) { if ($p['nivel'] === 'critico') { $temCritico = true; break; } }

// Últimas execuções (read-only)
$st = $pdo->prepare(
    "SELECT e.*, c.telefone, c.nome_contato
       FROM zapi_fluxo_execucao e
       LEFT JOIN zapi_conversas c ON c.id = e.conversa_id
      WHERE e.fluxo_id = ?
      ORDER BY e.id DESC LIMIT 25"
);
$st->execute(array($fluxoId));
$execucoes = $st->fetchAll();

require_once APP_ROOT . '/templates/layout_start.php';
?>
<style>
.fx-card { background:var(--bg-card); border:1px solid var(--border); border-radius:12px; padding:1.15rem; margin-bottom:1rem; }
.fx-card h3 { margin:0 0 .75rem; font-size:.95rem; }
.fx-grid { display:grid; grid-template-columns:1fr 1fr; gap:.5rem; }
.fx-form label { display:flex; flex-direction:column; gap:.2rem; font-size:.7rem; font-weight:600; color:#374151; }
.fx-form input, .fx-form select, .fx-form textarea {
    padding:.45rem .65rem; border:1.5px solid var(--border); border-radius:6px; font-size:.83rem; font-family:inherit;
}
.fx-form textarea { font-family:'Consolas','Courier New',monospace; font-size:.8rem; min-height:60px; }
.fx-tbl { width:100%; border-collapse:collapse; font-size:.83rem; }
.fx-tbl th { background:#f3f4f6; padding:.45rem .65rem; text-align:left; font-size:.65rem; text-transform:uppercase; color:#475569; }
.fx-tbl td { padding:.55rem .65rem; border-bottom:1px solid #f1f5f9; vertical-align:top; }
.fx-tipo { display:inline-block; padding:1px 6px; border-radius:10px; font-size:.65rem; font-weight:700; }
.fx-tipo.mensagem { background:#dbeafe; color:#1e40af; }
.fx-tipo.esperar { background:#fef3c7; color:#92400e; }
.fx-tipo.capturar { background:#dcfce7; color:#166534; }
.fx-tipo.condicional { background:#e9d5ff; color:#7c3aed; }
.fx-tipo.transferir_humano { background:#fee2e2; color:#991b1b; }
.fx-tipo.anotar { background:#cffafe; color:#155e75; }
.fx-tipo.fim { background:#f3f4f6; color:#6b7280; }
.fx-cfg { font-family:'Consolas',monospace; font-size:.72rem; color:#475569; background:#fafbfc; padding:.3rem .5rem; border-radius:4px; max-width:380px; overflow-x:auto; white-space:nowrap; }
</style>

<a href="<?= module_url('whatsapp', 'fluxos.php') ?>" class="btn btn-outline btn-sm mb-2">&larr; Voltar à lista</a>

<div style="display:flex;align-items:center;gap:.5rem;margin-bottom:1rem;flex-wrap:wrap;">
    <h1 style="margin:0;">🌊 <?= htmlspecialchars($fluxo['nome']) ?></h1>
    <span class="fxl-badge <?= $fluxo['ativo'] ? 'on' : 'off' ?>" style="<?= $fluxo['ativo'] ? 'background:#dcfce7;color:#166534' : 'background:#f3f4f6;color:#6b7280' ?>;padding:2px 8px;border-radius:10px;font-size:.65rem;font-weight:700;">
        <?= $fluxo['ativo'] ? 'ATIVO' : 'INATIVO' ?>
    </span>
    <span style="font-size:.7rem;color:#94a3b8;">id=<?= (int)$fluxo['id'] ?> · execuções totais: <?= (int)$fluxo['execucoes'] ?></span>
    <?php if ($fluxo['ativo'] && count($blocos) > 0): ?>
        <button type="button" onclick="document.getElementById('modalDisparar').style.display='flex'"
            style="margin-left:auto;background:#0d9488;color:#fff;border:none;padding:6px 14px;border-radius:6px;font-size:.78rem;font-weight:700;cursor:pointer;">
            ▶ Disparar manualmente
        </button>
    <?php endif; ?>
</div>

<!-- Modal: Disparar manualmente -->
<div id="modalDisparar" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:12px;padding:1.5rem;max-width:480px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.3);">
        <h3 style="margin:0 0 .75rem;font-size:1rem;color:#052228;">▶ Disparar fluxo manualmente</h3>
        <p style="margin:0 0 .75rem;font-size:.78rem;color:#475569;">
            Esse disparo é <strong>real</strong>: vai enviar mensagens via WhatsApp pra conversa escolhida.
            Use sua própria conversa pra testar, ou uma conversa interna do escritório.
        </p>
        <form method="POST">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="disparar_manual">
            <label style="display:flex;flex-direction:column;gap:.2rem;font-size:.75rem;font-weight:700;color:#374151;margin-bottom:.75rem;">
                ID da conversa (zapi_conversas.id) <span style="color:#dc2626;">*</span>
                <input type="number" name="conv_id" required min="1" placeholder="Ex: 1" style="padding:.5rem;border:1.5px solid var(--border);border-radius:6px;font-size:.85rem;">
                <span style="font-size:.7rem;font-weight:400;color:#6b7280;margin-top:.2rem;">
                    Você pode achar o ID na URL do chat (após /id= ou no DB).
                </span>
            </label>
            <div style="background:#fef3c7;border-left:3px solid #f59e0b;padding:.5rem .75rem;border-radius:6px;font-size:.72rem;color:#78350f;margin-bottom:.75rem;">
                ⚠ Confirme que: (1) o killswitch está LIGADO se quiser que o cliente possa AVANÇAR o fluxo respondendo;
                (2) você não vai incomodar um cliente real sem necessidade.
            </div>
            <div style="display:flex;gap:.5rem;justify-content:flex-end;">
                <button type="button" onclick="document.getElementById('modalDisparar').style.display='none'" class="btn btn-outline btn-sm">Cancelar</button>
                <button type="submit" class="btn btn-primary btn-sm" style="background:#0d9488;">▶ Disparar agora</button>
            </div>
        </form>
    </div>
</div>

<!-- ── Validação ── -->
<?php if (!empty($problemas)):
    $crits = array_filter($problemas, function($p){return $p['nivel']==='critico';});
    $avisos = array_filter($problemas, function($p){return $p['nivel']==='aviso';});
    $infos = array_filter($problemas, function($p){return $p['nivel']==='info';});
    $bgPrincipal = !empty($crits) ? '#fef2f2' : (!empty($avisos) ? '#fffbeb' : '#f0fdf4');
    $borderPrincipal = !empty($crits) ? '#dc2626' : (!empty($avisos) ? '#f59e0b' : '#16a34a');
    $titulo = !empty($crits) ? '🚫 Problemas críticos — fluxo não pode ser ativado'
            : (!empty($avisos) ? '⚠️ Avisos — vale revisar'
            : '✓ Verificação OK');
?>
<div class="fx-card" style="background:<?= $bgPrincipal ?>;border-color:<?= $borderPrincipal ?>;border-left:4px solid <?= $borderPrincipal ?>;">
    <h3 style="margin:0 0 .5rem;color:<?= $borderPrincipal ?>;">🔎 <?= $titulo ?> (<?= count($problemas) ?>)</h3>
    <ul style="margin:.25rem 0 0;padding-left:1.25rem;font-size:.82rem;color:#1f2937;">
        <?php foreach ($problemas as $p):
            $cor = $p['nivel']==='critico' ? '#991b1b' : ($p['nivel']==='aviso' ? '#92400e' : '#15803d');
            $emoji = $p['nivel']==='critico' ? '🚫' : ($p['nivel']==='aviso' ? '⚠️' : 'ℹ️');
        ?>
            <li style="margin:.15rem 0;color:<?= $cor ?>;"><?= $emoji ?> <?= htmlspecialchars($p['msg']) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<!-- ── Cabeçalho ── -->
<div class="fx-card">
    <h3>📋 Cabeçalho</h3>
    <form method="POST" class="fx-form">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="salvar_cabecalho">
        <div class="fx-grid">
            <label>Nome <span style="color:#dc2626;">*</span>
                <input type="text" name="nome" value="<?= htmlspecialchars($fluxo['nome']) ?>" required>
            </label>
            <label>Bloco inicial
                <select name="bloco_inicial_id">
                    <option value="0">— (auto: primeiro bloco) —</option>
                    <?php foreach ($blocos as $b): ?>
                        <option value="<?= (int)$b['id'] ?>" <?= (int)$fluxo['bloco_inicial_id'] === (int)$b['id'] ? 'selected' : '' ?>>
                            #<?= (int)$b['id'] ?> · <?= htmlspecialchars($b['tipo']) ?> (pos=<?= (int)$b['pos_x'] ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <label style="margin-top:.5rem;">Descrição
            <textarea name="descricao" rows="2" style="min-height:50px;font-family:inherit;"><?= htmlspecialchars($fluxo['descricao'] ?? '') ?></textarea>
        </label>
        <div class="fx-grid" style="margin-top:.5rem;">
            <label>Canal
                <select name="canal">
                    <option value="" <?= empty($fluxo['canal']) ? 'selected' : '' ?>>Qualquer</option>
                    <option value="21" <?= $fluxo['canal'] === '21' ? 'selected' : '' ?>>DDD 21 (Comercial)</option>
                    <option value="24" <?= $fluxo['canal'] === '24' ? 'selected' : '' ?>>DDD 24 (CX)</option>
                </select>
            </label>
            <label>Gatilho
                <select name="gatilho_tipo">
                    <?php foreach (array('manual','primeira_msg','palavra_chave') as $g): ?>
                        <option value="<?= $g ?>" <?= $fluxo['gatilho_tipo'] === $g ? 'selected' : '' ?>><?= $g ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
        <label style="margin-top:.5rem;">Gatilho config (JSON, opcional)
            <textarea name="gatilho_config" placeholder='{"palavras":["menu","ajuda"]}'><?= htmlspecialchars($fluxo['gatilho_config'] ?? '') ?></textarea>
        </label>
        <div style="margin-top:.75rem;">
            <button type="submit" class="btn btn-primary btn-sm">💾 Salvar cabeçalho</button>
        </div>
    </form>
</div>

<!-- ── Blocos ── -->
<div class="fx-card">
    <h3>🧱 Blocos (<?= count($blocos) ?>)</h3>

    <!-- Lista de blocos -->
    <?php if (empty($blocos)): ?>
        <p style="color:#6b7280;font-style:italic;font-size:.85rem;">Nenhum bloco ainda. Use o form abaixo pra adicionar o primeiro.</p>
    <?php else: ?>
    <table class="fx-tbl">
        <thead>
            <tr>
                <th>ID</th>
                <th>Tipo</th>
                <th>Config JSON</th>
                <th style="width:60px;">Pos</th>
                <th style="width:180px;">Editar / Remover</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($blocos as $b):
            $cfgShort = trim((string)$b['config_json']);
            if (mb_strlen($cfgShort) > 90) $cfgShort = mb_substr($cfgShort, 0, 90) . '…';
        ?>
            <tr>
                <td style="font-family:monospace;color:#94a3b8;">#<?= (int)$b['id'] ?></td>
                <td><span class="fx-tipo <?= htmlspecialchars($b['tipo']) ?>"><?= htmlspecialchars($b['tipo']) ?></span></td>
                <td><div class="fx-cfg"><?= htmlspecialchars($cfgShort ?: '{}') ?></div></td>
                <td style="text-align:center;color:#6b7280;"><?= (int)$b['pos_x'] ?></td>
                <td>
                    <details>
                        <summary style="cursor:pointer;font-size:.78rem;color:#0d9488;">✏️ Editar</summary>
                        <form method="POST" class="fx-form" style="margin-top:.4rem;padding:.4rem;background:#fafbfc;border-radius:6px;">
                            <?= csrf_input() ?>
                            <input type="hidden" name="action" value="editar_bloco">
                            <input type="hidden" name="bloco_id" value="<?= (int)$b['id'] ?>">
                            <div class="fx-grid">
                                <label>Tipo
                                    <select name="tipo">
                                        <?php foreach (array('mensagem','esperar','capturar','condicional','transferir_humano','anotar','fim') as $t): ?>
                                            <option value="<?= $t ?>" <?= $b['tipo'] === $t ? 'selected' : '' ?>><?= $t ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label>Pos X
                                    <input type="number" name="pos_x" value="<?= (int)$b['pos_x'] ?>">
                                </label>
                            </div>
                            <label style="margin-top:.4rem;">config_json
                                <textarea name="config_json" rows="3"><?= htmlspecialchars($b['config_json'] ?? '{}') ?></textarea>
                            </label>
                            <button type="submit" class="btn btn-primary btn-sm" style="margin-top:.4rem;">💾 Salvar</button>
                        </form>
                    </details>
                    <form method="POST" style="display:inline;margin-left:.3rem;" onsubmit="return confirm('Excluir bloco #<?= (int)$b['id'] ?>?\n\nArestas que entram ou saem dele serão removidas em cascade.');">
                        <?= csrf_input() ?>
                        <input type="hidden" name="action" value="excluir_bloco">
                        <input type="hidden" name="bloco_id" value="<?= (int)$b['id'] ?>">
                        <button type="submit" class="btn btn-outline btn-sm" style="color:#dc2626;">🗑</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <!-- Form de adicionar bloco -->
    <details style="margin-top:1rem;">
        <summary style="cursor:pointer;font-weight:700;color:#0d9488;">➕ Adicionar bloco</summary>
        <form method="POST" class="fx-form" style="margin-top:.6rem;padding:.75rem;background:#fafbfc;border-radius:6px;">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="add_bloco">
            <div class="fx-grid">
                <label>Tipo <span style="color:#dc2626;">*</span>
                    <select name="tipo" required>
                        <option value="mensagem">mensagem · envia texto</option>
                        <option value="esperar">esperar · pausa até resposta/timeout</option>
                        <option value="capturar">capturar · grava resposta em campo</option>
                        <option value="condicional">condicional · saídas 'sim'/'nao'</option>
                        <option value="transferir_humano">transferir_humano · passa pra atendente humano (encerra fluxo)</option>
                        <option value="anotar">anotar · grava texto em conversa/cliente/caso</option>
                        <option value="fim">fim · encerra execução</option>
                    </select>
                </label>
                <label>Pos X (ordem visual)
                    <input type="number" name="pos_x" value="<?= count($blocos) + 1 ?>">
                </label>
            </div>
            <label style="margin-top:.4rem;">config_json
                <textarea name="config_json" rows="3" placeholder='mensagem:    {"texto": "Oi {{nome}}!"}
esperar:     {"timeout_min": 60}
capturar:    {"campo": "telefone_alt", "trim": true}
condicional: {"campo": "estado_civil", "operador": "igual", "valor": "casado"}
transferir:  {"mensagem": "Vou te transferir agora 🙌"}
anotar:      {"destino": "conversa", "texto": "Cliente disse: {{campo:resposta_demo}}"}'>{}</textarea>
            </label>
            <button type="submit" class="btn btn-primary btn-sm" style="margin-top:.4rem;">➕ Adicionar bloco</button>
        </form>
    </details>
</div>

<!-- ── Arestas ── -->
<div class="fx-card">
    <h3>🔗 Arestas (<?= count($arestas) ?>)</h3>
    <?php if (empty($arestas)): ?>
        <p style="color:#6b7280;font-style:italic;font-size:.85rem;">Nenhuma aresta. Sem arestas o fluxo termina no primeiro bloco. Use 'default' como saída para a maioria; 'sim'/'nao' são as saídas do bloco condicional.</p>
    <?php else: ?>
    <table class="fx-tbl">
        <thead>
            <tr>
                <th>ID</th>
                <th>Origem</th>
                <th>Saída</th>
                <th>Destino</th>
                <th style="width:60px;">Ação</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($arestas as $a):
            $blO = $blocos[(int)$a['origem_bloco_id']] ?? null;
            $blD = $blocos[(int)$a['destino_bloco_id']] ?? null;
        ?>
            <tr>
                <td style="font-family:monospace;color:#94a3b8;">#<?= (int)$a['id'] ?></td>
                <td>#<?= (int)$a['origem_bloco_id'] ?> <?= $blO ? '<span class="fx-tipo ' . htmlspecialchars($blO['tipo']) . '">' . htmlspecialchars($blO['tipo']) . '</span>' : '<span style="color:#dc2626;">[bloco removido]</span>' ?></td>
                <td><code style="background:#f1f5f9;padding:1px 6px;border-radius:4px;font-size:.7rem;"><?= htmlspecialchars($a['saida']) ?></code></td>
                <td>#<?= (int)$a['destino_bloco_id'] ?> <?= $blD ? '<span class="fx-tipo ' . htmlspecialchars($blD['tipo']) . '">' . htmlspecialchars($blD['tipo']) . '</span>' : '<span style="color:#dc2626;">[bloco removido]</span>' ?></td>
                <td>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('Remover essa aresta?');">
                        <?= csrf_input() ?>
                        <input type="hidden" name="action" value="excluir_aresta">
                        <input type="hidden" name="aresta_id" value="<?= (int)$a['id'] ?>">
                        <button type="submit" class="btn btn-outline btn-sm" style="color:#dc2626;">🗑</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <!-- Form add aresta -->
    <?php if (count($blocos) >= 2): ?>
    <details style="margin-top:1rem;">
        <summary style="cursor:pointer;font-weight:700;color:#0d9488;">➕ Adicionar aresta</summary>
        <form method="POST" class="fx-form" style="margin-top:.6rem;padding:.75rem;background:#fafbfc;border-radius:6px;">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="add_aresta">
            <div style="display:grid;grid-template-columns:1fr 120px 1fr auto;gap:.5rem;align-items:end;">
                <label>Origem
                    <select name="origem_bloco_id" required>
                        <option value="">—</option>
                        <?php foreach ($blocos as $b): ?>
                            <option value="<?= (int)$b['id'] ?>">#<?= (int)$b['id'] ?> · <?= htmlspecialchars($b['tipo']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Saída
                    <input type="text" name="saida" value="default" placeholder="default | sim | nao | ...">
                </label>
                <label>Destino
                    <select name="destino_bloco_id" required>
                        <option value="">—</option>
                        <?php foreach ($blocos as $b): ?>
                            <option value="<?= (int)$b['id'] ?>">#<?= (int)$b['id'] ?> · <?= htmlspecialchars($b['tipo']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit" class="btn btn-primary btn-sm">➕</button>
            </div>
        </form>
    </details>
    <?php endif; ?>
</div>

<!-- ── Execuções ── -->
<div class="fx-card">
    <h3>▶️ Últimas execuções (<?= count($execucoes) ?>)</h3>
    <?php if (empty($execucoes)): ?>
        <p style="color:#6b7280;font-style:italic;font-size:.85rem;">Nenhuma execução ainda.</p>
    <?php else: ?>
    <table class="fx-tbl">
        <thead>
            <tr>
                <th>ID</th>
                <th>Conversa</th>
                <th>Bloco atual</th>
                <th>Estado</th>
                <th>Aguardando até</th>
                <th>Iniciada em</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($execucoes as $e): ?>
            <tr>
                <td style="font-family:monospace;color:#94a3b8;">
                    <a href="<?= module_url('whatsapp', 'fluxo_execucao_ver.php?id=' . (int)$e['id']) ?>" style="color:#0d9488;text-decoration:none;font-weight:700;">#<?= (int)$e['id'] ?></a>
                </td>
                <td>
                    <?= htmlspecialchars($e['nome_contato'] ?: '(sem nome)') ?>
                    <div style="font-size:.7rem;color:#94a3b8;">conv#<?= (int)$e['conversa_id'] ?> · tel <?= htmlspecialchars($e['telefone'] ?? '') ?></div>
                </td>
                <td>#<?= (int)($e['bloco_atual_id'] ?? 0) ?></td>
                <td><span class="fx-tipo <?= $e['estado'] === 'concluido' ? 'fim' : ($e['estado'] === 'erro' ? 'fim' : 'capturar') ?>" style="<?= $e['estado'] === 'erro' ? 'background:#fee2e2;color:#991b1b' : '' ?>"><?= htmlspecialchars($e['estado']) ?></span></td>
                <td style="font-size:.75rem;color:#475569;"><?= $e['aguardando_ate'] ? date('d/m H:i', strtotime($e['aguardando_ate'])) : '—' ?></td>
                <td style="font-size:.75rem;color:#475569;"><?= date('d/m/Y H:i', strtotime($e['iniciado_em'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
