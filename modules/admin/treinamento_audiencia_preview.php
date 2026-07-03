<?php
/**
 * Página admin de preview do treinamento obrigatório.
 *
 * Fluxo:
 *   1. Amanda escolhe um case (busca por título/número)
 *   2. (Opcional) escolhe um evento de agenda desse case
 *   3. Sistema gera um link e mostra o botão pra abrir
 *
 * Não envia mensagem, não avisa cliente — só cria o link e leva pra tela
 * pública. Serve pra Amanda revisar o texto do termo e a UI antes de
 * ativar o killswitch. Também serve pra criar treinamentos manuais
 * enquanto Onda 3 (envio automático) ainda não estiver pronta.
 */
require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/database.php';
require_once __DIR__ . '/../../core/auth.php';
require_once __DIR__ . '/../../core/middleware.php';
require_once __DIR__ . '/../../core/functions_utils.php';
require_once __DIR__ . '/../../core/functions_treinamento_audiencia.php';

require_login();
require_min_role('admin');

$pdo = db();
$erro = '';
$linkGerado = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf($_POST['csrf_token'] ?? '')) {
        $erro = 'CSRF inválido — recarregue a página.';
    } else {
        $acao = $_POST['acao'] ?? 'criar';
        if ($acao === 'apagar') {
            $trId = (int)($_POST['tr_id'] ?? 0);
            if ($trId > 0) {
                try {
                    // TODO Onda 2: remover certificado do Drive se certificado_url estiver preenchido.
                    $del = $pdo->prepare("DELETE FROM treinamento_audiencia_aceites WHERE id = ?");
                    $del->execute(array($trId));
                    if (function_exists('audit_log')) {
                        audit_log('treinamento_audiencia_apagado', 'treinamento_audiencia_aceites', $trId, 'Excluído via admin/preview');
                    }
                    $mensagemOkAcao = 'Treinamento #' . $trId . ' apagado.';
                } catch (Exception $e) {
                    $erro = 'Erro ao apagar: ' . $e->getMessage();
                }
            }
        } else {
            $caseId = (int)($_POST['case_id'] ?? 0);
            $agEv   = (int)($_POST['agenda_evento_id'] ?? 0) ?: null;
            if (!$caseId) {
                $erro = 'Escolha um caso.';
            } else {
                try {
                    $r = treinamento_audiencia_criar($pdo, $caseId, $agEv, current_user_id());
                    $linkGerado = $r['url'];
                } catch (Exception $e) {
                    $erro = 'Erro: ' . $e->getMessage();
                }
            }
        }
    }
}

// Cases recentes (para dropdown, últimos 200)
$cases = $pdo->query(
    "SELECT cs.id, cs.title, cs.case_number, c.name AS cliente_nome
     FROM cases cs
     LEFT JOIN clients c ON c.id = cs.client_id
     WHERE cs.status NOT IN ('arquivado')
     ORDER BY cs.updated_at DESC
     LIMIT 200"
)->fetchAll(PDO::FETCH_ASSOC);

// Últimos treinamentos gerados (útil pra Amanda ver os que já foram testados)
$recentes = $pdo->query(
    "SELECT ta.id, ta.token, ta.criado_em, ta.aceite_em, ta.aceite_nome,
            cs.title AS case_title, c.name AS client_name
     FROM treinamento_audiencia_aceites ta
     LEFT JOIN cases cs ON cs.id = ta.case_id
     LEFT JOIN clients c ON c.id = ta.client_id
     ORDER BY ta.id DESC
     LIMIT 20"
)->fetchAll(PDO::FETCH_ASSOC);

// Status do killswitch
$killswitch = $pdo->query("SELECT valor FROM configuracoes WHERE chave='treinamento_audiencia_ativo'")->fetchColumn();
$ativo = ($killswitch === '1');

$_page_title = 'Treinamento Audiência — Preview / Testes';
require_once __DIR__ . '/../../templates/layout_start.php';
?>
<div style="max-width: 940px; margin: 0 auto; padding: 20px;">
    <h1 style="color: var(--petrol-900); margin: 0 0 6px;">🎓 Treinamento Obrigatório de Audiência Remota</h1>
    <p style="color: var(--text-muted); margin: 0 0 20px;">Preview + criação manual de links de treinamento. Use pra revisar o termo antes de ativar o envio automático.</p>

    <div style="background: <?= $ativo ? '#ecfdf5' : '#fef3c7' ?>; border: 1.5px solid <?= $ativo ? '#10b981' : '#f59e0b' ?>; border-radius: 12px; padding: 14px 18px; margin-bottom: 20px;">
        <strong style="color: <?= $ativo ? '#10b981' : '#d97706' ?>;"><?= $ativo ? '🟢 Killswitch LIGADO' : '🟡 Killswitch DESLIGADO (default)' ?></strong>
        <p style="margin: 6px 0 0; font-size: 14px;">
            <?php if ($ativo): ?>
                Envio automático + botões em produção estão ATIVOS. Pra desligar rápido: UPDATE configuracoes SET valor='0' WHERE chave='treinamento_audiencia_ativo'.
            <?php else: ?>
                A feature está desligada em produção. Só links criados por essa tela funcionam. Depois de revisar o termo, ative com:
                <code style="background:#fff; padding: 2px 6px; border-radius:4px;">UPDATE configuracoes SET valor='1' WHERE chave='treinamento_audiencia_ativo';</code>
            <?php endif; ?>
        </p>
    </div>

    <?php if ($erro): ?>
        <div style="background:#fde8e8; border:1px solid #fbcaca; color:#c8544a; padding: 12px 16px; border-radius: 10px; margin-bottom: 18px;"><?= e($erro) ?></div>
    <?php endif; ?>

    <?php if (!empty($mensagemOkAcao)): ?>
        <div style="background:#ecfdf5; border:1px solid #bbf7d0; color:#059669; padding: 12px 16px; border-radius: 10px; margin-bottom: 18px;">✅ <?= e($mensagemOkAcao) ?></div>
    <?php endif; ?>

    <?php if ($linkGerado): ?>
        <div style="background:#ecfdf5; border: 1.5px solid #10b981; border-radius: 14px; padding: 18px 20px; margin-bottom: 20px;">
            <h3 style="margin: 0 0 8px; color: #059669;">✅ Link gerado!</h3>
            <p style="margin: 0 0 12px;">Copie o link abaixo ou clique no botão pra abrir agora:</p>
            <input type="text" value="<?= e($linkGerado) ?>" readonly onclick="this.select()" style="width: 100%; padding: 10px 12px; border: 1.5px solid #10b981; border-radius: 8px; font-family: ui-monospace, monospace; font-size: 13px; background:#fff;">
            <div style="display:flex; gap: 8px; margin-top: 12px; flex-wrap: wrap;">
                <a href="<?= e($linkGerado) ?>" target="_blank" class="btn btn-primary">🔗 Abrir treinamento</a>
                <button onclick="navigator.clipboard.writeText('<?= e($linkGerado) ?>').then(()=>alert('Copiado!'))" class="btn btn-outline">📋 Copiar link</button>
            </div>
        </div>
    <?php endif; ?>

    <!-- Form de criação -->
    <div style="background: #fff; border: 1px solid var(--border); border-radius: 14px; padding: 22px; margin-bottom: 20px; box-shadow: var(--shadow);">
        <h2 style="margin: 0 0 12px; font-size: 18px;">Criar novo link de treinamento</h2>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= e(generate_csrf_token()) ?>">

            <div style="margin-bottom: 14px;">
                <label style="display:block; font-weight: 700; font-size: 13px; margin-bottom: 4px;">Caso / processo</label>
                <select name="case_id" required style="width: 100%; padding: 10px 12px; border: 1.5px solid var(--border); border-radius: 8px; font-size: 14px;">
                    <option value="">— escolha —</option>
                    <?php foreach ($cases as $cs): ?>
                        <option value="<?= (int)$cs['id'] ?>">
                            #<?= (int)$cs['id'] ?> — <?= e($cs['title']) ?><?= $cs['case_number'] ? ' (' . e($cs['case_number']) . ')' : '' ?><?= $cs['cliente_nome'] ? ' — ' . e($cs['cliente_nome']) : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p style="font-size: 12px; color: var(--text-muted); margin: 4px 0 0;">Últimos 200 casos ativos. Se não achar, use a URL direta: <code>?case_id=X</code></p>
            </div>

            <div style="margin-bottom: 14px;">
                <label style="display:block; font-weight: 700; font-size: 13px; margin-bottom: 4px;">Vincular a evento de agenda (opcional)</label>
                <input type="number" name="agenda_evento_id" placeholder="Deixe vazio pra criar sem vincular a evento específico" style="width: 100%; padding: 10px 12px; border: 1.5px solid var(--border); border-radius: 8px; font-size: 14px;">
                <p style="font-size: 12px; color: var(--text-muted); margin: 4px 0 0;">Se informado, o link mostra data/hora/título da audiência no topo.</p>
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%; padding: 14px; font-size: 15px;">
                🎓 Criar link de treinamento
            </button>
        </form>
    </div>

    <!-- Recentes -->
    <?php if ($recentes): ?>
        <div style="background: #fff; border: 1px solid var(--border); border-radius: 14px; padding: 22px; box-shadow: var(--shadow);">
            <h2 style="margin: 0 0 12px; font-size: 18px;">Últimos treinamentos gerados</h2>
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse; font-size: 13.5px;">
                    <thead>
                        <tr style="background: #FBF6F1;">
                            <th style="padding: 10px 8px; text-align: left; border-bottom: 1.5px solid var(--border);">#</th>
                            <th style="padding: 10px 8px; text-align: left; border-bottom: 1.5px solid var(--border);">Case</th>
                            <th style="padding: 10px 8px; text-align: left; border-bottom: 1.5px solid var(--border);">Cliente</th>
                            <th style="padding: 10px 8px; text-align: left; border-bottom: 1.5px solid var(--border);">Criado em</th>
                            <th style="padding: 10px 8px; text-align: left; border-bottom: 1.5px solid var(--border);">Status</th>
                            <th style="padding: 10px 8px; text-align: left; border-bottom: 1.5px solid var(--border);">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentes as $r): ?>
                            <tr>
                                <td style="padding: 8px; border-bottom: 1px solid #f4f4f4;"><?= (int)$r['id'] ?></td>
                                <td style="padding: 8px; border-bottom: 1px solid #f4f4f4;"><?= e($r['case_title'] ?: '—') ?></td>
                                <td style="padding: 8px; border-bottom: 1px solid #f4f4f4;"><?= e($r['aceite_nome'] ?: $r['client_name'] ?: '—') ?></td>
                                <td style="padding: 8px; border-bottom: 1px solid #f4f4f4;"><?= e(date('d/m/Y H:i', strtotime($r['criado_em']))) ?></td>
                                <td style="padding: 8px; border-bottom: 1px solid #f4f4f4;">
                                    <?php if ($r['aceite_em']): ?>
                                        <span style="color: #059669; font-weight: 600;">✓ Assinado</span>
                                        <div style="font-size: 11px; color: var(--text-muted);"><?= e(date('d/m/Y H:i', strtotime($r['aceite_em']))) ?></div>
                                    <?php else: ?>
                                        <span style="color: #d97706;">⏳ Aguardando</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 8px; border-bottom: 1px solid #f4f4f4; white-space: nowrap;">
                                    <a href="<?= url('publico/treinamento_audiencia.php?t=' . urlencode($r['token'])) ?>" target="_blank" style="font-size: 12px; margin-right: 10px;">Abrir</a>
                                    <?php
                                        $assinado = !empty($r['aceite_em']);
                                        $confirmMsg = $assinado
                                            ? '⚠️ ATENÇÃO: este certificado JÁ FOI ASSINADO em ' . date('d/m/Y H:i', strtotime($r['aceite_em'])) . '.\\n\\nApagar removerá a prova documental do aceite eletrônico do cliente. Só faça isso se tem certeza (ex: cliente pediu, foi teste, etc).\\n\\nConfirma?'
                                            : 'Apagar o link do treinamento #' . (int)$r['id'] . '? O cliente que recebeu o link vai receber erro ao tentar acessar.';
                                    ?>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('<?= e($confirmMsg) ?>');">
                                        <input type="hidden" name="csrf_token" value="<?= e(generate_csrf_token()) ?>">
                                        <input type="hidden" name="acao" value="apagar">
                                        <input type="hidden" name="tr_id" value="<?= (int)$r['id'] ?>">
                                        <button type="submit" style="border:0; background:none; color:#c8544a; font-size: 12px; cursor:pointer; padding:0; text-decoration:underline;" title="<?= $assinado ? 'Apaga certificado assinado (prova documental)' : 'Apaga link pendente' ?>">
                                            🗑️ Apagar
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
