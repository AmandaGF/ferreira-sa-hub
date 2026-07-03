<?php
/**
 * Central VIP F&S — Detalhe do Processo
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
$pdo = sv_db();
$user = salavip_current_user();
$clienteId = salavip_current_cliente_id();

// --- Validar processo ---
$caseId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$stmtCase = $pdo->prepare("SELECT * FROM cases WHERE id = ? AND client_id = ? AND salavip_ativo = 1");
$stmtCase->execute([$caseId, $clienteId]);
$caso = $stmtCase->fetch();

if (!$caso) {
    sv_flash('error', 'Processo não encontrado.');
    sv_redirect('pages/meus_processos.php');
}

// --- Partes ---
$partes = [];
try {
    $stmtPartes = $pdo->prepare("SELECT * FROM case_partes WHERE case_id = ?");
    $stmtPartes->execute([$caseId]);
    $partes = $stmtPartes->fetchAll();
    // Fallback: se réu sem nome, usar campo legado
    foreach ($partes as &$_p) {
        if (empty($_p['nome']) && $_p['papel'] === 'reu' && !empty($caso['parte_re_nome'])) {
            $_p['nome'] = $caso['parte_re_nome'];
        }
    }
    unset($_p);
    // Se não tem partes na tabela, montar a partir dos campos legados
    if (empty($partes)) {
        if (!empty($caso['parte_re_nome'])) {
            $partes[] = ['papel' => 'reu', 'nome' => $caso['parte_re_nome']];
        }
    }
} catch (Exception $e) {}

// --- Andamentos com paginação (15 por página) ---
$porPagina = 15;
$paginaAtual = isset($_GET['pg']) ? max(1, (int)$_GET['pg']) : 1;
$offset = ($paginaAtual - 1) * $porPagina;

$stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM case_andamentos WHERE case_id = ? AND visivel_cliente = 1");
$stmtTotal->execute([$caseId]);
$totalAndamentos = (int)$stmtTotal->fetchColumn();
$totalPaginas = max(1, (int)ceil($totalAndamentos / $porPagina));
if ($paginaAtual > $totalPaginas) $paginaAtual = $totalPaginas;

$stmtAnd = $pdo->prepare(
    "SELECT * FROM case_andamentos WHERE case_id = ? AND visivel_cliente = 1 ORDER BY data_andamento DESC, created_at DESC LIMIT " . (int)$porPagina . " OFFSET " . (int)$offset
);
$stmtAnd->execute([$caseId]);
$andamentos = $stmtAnd->fetchAll();

// --- Documentos pendentes ---
$stmtDocs = $pdo->prepare("SELECT * FROM documentos_pendentes WHERE case_id = ? AND visivel_cliente = 1 ORDER BY solicitado_em DESC");
$stmtDocs->execute([$caseId]);
$documentos = $stmtDocs->fetchAll();

// --- Próximos compromissos ---
$stmtEv = $pdo->prepare("SELECT * FROM agenda_eventos WHERE case_id = ? AND visivel_cliente = 1 AND data_inicio >= CURDATE() AND status NOT IN ('cancelado','remarcado','realizado') ORDER BY data_inicio ASC");
$stmtEv->execute([$caseId]);
$eventos = $stmtEv->fetchAll();

// --- Treinamentos de audiência (feature Amanda 02/07) ---
// Cliente da VIP vê APENAS certificados que ele mesmo assinou (já concluídos).
// Links pendentes ele acessa direto pelo WhatsApp — não faz sentido listar aqui.
$treinamentosCli = array();
try {
    $stTr = $pdo->prepare(
        "SELECT id, token, aceite_em, aceite_nome, certificado_url, audiencia_titulo, audiencia_data_hora
         FROM treinamento_audiencia_aceites
         WHERE case_id = ? AND aceite_em IS NOT NULL
         ORDER BY aceite_em DESC"
    );
    $stTr->execute([$caseId]);
    $treinamentosCli = $stTr->fetchAll(PDO::FETCH_ASSOC) ?: array();
} catch (Exception $e) { /* tabela pode nao existir */ }

// Mapeamento de tipo para label e cor
$tipoAndLabels = [
    'movimentacao' => 'Movimentação', 'despacho' => 'Despacho', 'decisao' => 'Decisão',
    'sentenca' => 'Sentença', 'intimacao' => 'Intimação', 'citacao' => 'Citação',
    'audiencia' => 'Audiência', 'peticao' => 'Petição', 'certidao' => 'Certidão',
    'observacao' => 'Andamento', 'chamado' => 'Atendimento', 'publicacao' => 'Publicação',
];
$tipoAndCores = [
    'movimentacao' => '#6366f1', 'despacho' => '#0ea5e9', 'decisao' => '#dc2626',
    'sentenca' => '#7c3aed', 'intimacao' => '#d97706', 'citacao' => '#059669',
    'audiencia' => '#e67e22', 'peticao' => '#B87333', 'certidao' => '#0891b2',
    'observacao' => '#64748b', 'chamado' => '#f59e0b', 'publicacao' => '#dc2626',
];
$tipoAndIcons = [
    'movimentacao' => '📋', 'despacho' => '📜', 'decisao' => '⚖️',
    'sentenca' => '🏛️', 'intimacao' => '📨', 'citacao' => '📬',
    'audiencia' => '🗓️', 'peticao' => '📝', 'certidao' => '📄',
    'observacao' => '💬', 'chamado' => '🎫', 'publicacao' => '📰',
];

$pageTitle = $caso['title'];
require_once __DIR__ . '/../includes/header.php';
?>

<a href="<?= sv_url('pages/meus_processos.php') ?>" style="display:inline-flex;align-items:center;gap:6px;color:var(--sv-accent);font-size:.85rem;font-weight:600;text-decoration:none;margin-bottom:1.25rem;">
    ← Voltar para Meus Processos
</a>

<!-- Dados do Processo -->
<div class="sv-card" style="margin-bottom:1.5rem;">
    <div style="display:flex;align-items:flex-start;gap:1rem;flex-wrap:wrap;">
        <div style="flex:1;min-width:250px;">
            <h3 style="margin:0 0 .75rem;font-size:1.2rem;"><?= sv_e($caso['title']) ?></h3>

            <?php if (!empty($caso['case_number'])): ?>
            <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.5rem;">
                <span style="color:var(--sv-text-muted);font-size:.82rem;">Nº do Processo</span>
                <code style="background:var(--sv-accent-bg);color:var(--sv-accent);padding:3px 10px;border-radius:6px;font-size:.88rem;font-weight:600;letter-spacing:.5px;"><?= sv_e($caso['case_number']) ?></code>
            </div>
            <?php endif; ?>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:.6rem;margin-top:.75rem;">
                <?php if (!empty($caso['case_type'])): ?>
                <div>
                    <div style="color:var(--sv-text-muted);font-size:.72rem;text-transform:uppercase;letter-spacing:.5px;margin-bottom:.2rem;">Tipo</div>
                    <span style="background:var(--sv-accent-bg);color:var(--sv-accent);padding:3px 10px;border-radius:6px;font-size:.8rem;font-weight:600;"><?= sv_e(ucfirst($caso['case_type'])) ?></span>
                </div>
                <?php endif; ?>

                <div>
                    <div style="color:var(--sv-text-muted);font-size:.72rem;text-transform:uppercase;letter-spacing:.5px;margin-bottom:.2rem;">Status</div>
                    <?= sv_badge_status_processo($caso['status'] ?? '') ?>
                </div>

                <?php if (!empty($caso['court'])): ?>
                <div>
                    <div style="color:var(--sv-text-muted);font-size:.72rem;text-transform:uppercase;letter-spacing:.5px;margin-bottom:.2rem;">Vara</div>
                    <span style="color:var(--sv-text);font-size:.88rem;"><?= sv_e($caso['court']) ?></span>
                </div>
                <?php endif; ?>

                <?php if (!empty($caso['comarca'])): ?>
                <div>
                    <div style="color:var(--sv-text-muted);font-size:.72rem;text-transform:uppercase;letter-spacing:.5px;margin-bottom:.2rem;">Comarca</div>
                    <span style="color:var(--sv-text);font-size:.88rem;"><?= sv_e($caso['comarca']) ?></span>
                </div>
                <?php endif; ?>

                <?php if (!empty($caso['opened_at'])): ?>
                <div>
                    <div style="color:var(--sv-text-muted);font-size:.72rem;text-transform:uppercase;letter-spacing:.5px;margin-bottom:.2rem;">Distribuição</div>
                    <span style="color:var(--sv-text);font-size:.88rem;"><?= sv_formatar_data($caso['opened_at']) ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if (!empty($partes)): ?>
    <div style="margin-top:1.25rem;padding-top:1rem;border-top:1px solid var(--sv-border);">
        <div style="color:var(--sv-text-muted);font-size:.72rem;text-transform:uppercase;letter-spacing:.5px;margin-bottom:.5rem;">Partes do Processo</div>
        <div style="display:flex;flex-wrap:wrap;gap:.5rem;">
            <?php foreach ($partes as $parte):
                $papelLabel = ucfirst($parte['papel'] ?? 'Parte');
                if ($papelLabel === 'Autor') $papelLabel = '👤 Autor';
                elseif ($papelLabel === 'Reu') $papelLabel = '👥 Réu';
                elseif ($papelLabel === 'Representante_legal') $papelLabel = '⚖️ Rep. Legal';
            ?>
            <div style="background:var(--sv-accent-bg);border:1px solid var(--sv-border);border-radius:8px;padding:6px 12px;font-size:.82rem;">
                <strong style="color:var(--sv-accent);"><?= sv_e($papelLabel) ?>:</strong>
                <span style="color:var(--sv-text);"><?= sv_e($parte['nome'] ?? '') ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Timeline de Andamentos -->
<div class="sv-card" style="margin-bottom:1.5rem;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
        <h3 style="margin:0;">Andamentos do Processo</h3>
        <span style="color:var(--sv-text-muted);font-size:.78rem;"><?= $totalAndamentos ?> registro<?= $totalAndamentos !== 1 ? 's' : '' ?></span>
    </div>

    <?php if (empty($andamentos)): ?>
        <p class="sv-empty">Nenhum andamento disponível.</p>
    <?php else: ?>
        <div style="position:relative;padding-left:24px;">
            <!-- Linha vertical da timeline -->
            <div style="position:absolute;left:8px;top:8px;bottom:8px;width:2px;background:var(--sv-border);"></div>

            <?php
            // Checa se a feature de IA esta ligada — se sim, mostra botao "?"
            // ao lado de cada andamento; se nao, esconde o botao (silenciosamente).
            $_iaTraducaoOn = false;
            try {
                $stCfg = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'ia_feature_traducao_leiga_enabled'");
                $stCfg->execute();
                $_iaTraducaoOn = ((string)$stCfg->fetchColumn() === '1');
            } catch (Exception $e) {}
            ?>
            <?php foreach ($andamentos as $i => $and):
                $tipo = $and['tipo'] ?? 'observacao';
                $cor = $tipoAndCores[$tipo] ?? '#64748b';
                $icon = $tipoAndIcons[$tipo] ?? '📋';
                $label = $tipoAndLabels[$tipo] ?? ucfirst($tipo);
                $descOriginal = $and['descricao'] ?? '';
                $descTraduzidaSimples = sv_traduzir_andamento($descOriginal); // fallback str_replace
                $traducaoIaCache = (string)($and['traducao_leiga'] ?? '');
                $andId = (int)($and['id'] ?? 0);
            ?>
            <div style="position:relative;margin-bottom:1rem;padding-bottom:1rem;<?= $i < count($andamentos)-1 ? 'border-bottom:1px solid rgba(255,255,255,.03);' : '' ?>">
                <!-- Bolinha da timeline -->
                <div style="position:absolute;left:-20px;top:4px;width:14px;height:14px;border-radius:50%;background:<?= $cor ?>;display:flex;align-items:center;justify-content:center;font-size:7px;z-index:1;box-shadow:0 0 0 3px var(--sv-bg);"></div>

                <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;margin-bottom:.3rem;">
                    <span style="font-size:.88rem;font-weight:700;color:var(--sv-accent);"><?= sv_formatar_data($and['data_andamento']) ?></span>
                    <span style="background:<?= $cor ?>20;color:<?= $cor ?>;padding:2px 8px;border-radius:6px;font-size:.7rem;font-weight:700;letter-spacing:.3px;"><?= $icon ?> <?= sv_e($label) ?></span>
                </div>
                <div style="color:var(--sv-text);font-size:.9rem;line-height:1.6;" id="and-desc-<?= $andId ?>"><?= nl2br(sv_e($descTraduzidaSimples)) ?></div>

                <?php if ($_iaTraducaoOn && $andId > 0): ?>
                    <?php if ($traducaoIaCache !== ''): ?>
                        <!-- Tradução já em cache: mostra logo abaixo, em destaque sutil -->
                        <div class="sv-traducao-leiga" style="margin-top:.5rem;padding:.65rem .8rem;background:rgba(99,102,241,.08);border-left:3px solid #6366f1;border-radius:6px;font-size:.85rem;color:var(--sv-text);line-height:1.55;">
                            <span style="font-size:.7rem;font-weight:700;color:#818cf8;text-transform:uppercase;letter-spacing:.4px;display:block;margin-bottom:.25rem;">📖 Em linguagem comum</span>
                            <?= nl2br(sv_e($traducaoIaCache)) ?>
                        </div>
                    <?php else: ?>
                        <!-- Botão pra disparar tradução sob demanda -->
                        <button type="button"
                                onclick="svTraduzirAndamento(<?= $andId ?>, this)"
                                style="margin-top:.4rem;background:transparent;color:#818cf8;border:1px solid rgba(99,102,241,.4);padding:4px 12px;border-radius:6px;font-size:.78rem;font-weight:600;cursor:pointer;transition:background .15s;display:inline-flex;align-items:center;gap:.35rem;"
                                onmouseover="this.style.background='rgba(99,102,241,.12)'"
                                onmouseout="this.style.background='transparent'">
                            ❓ Em linguagem comum
                        </button>
                        <div id="and-traducao-<?= $andId ?>" style="display:none;"></div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if ($totalPaginas > 1): ?>
        <div style="display:flex;justify-content:center;align-items:center;gap:.4rem;margin-top:1.25rem;flex-wrap:wrap;">
            <?php if ($paginaAtual > 1): ?>
                <a href="<?= sv_url('pages/processo_detalhe.php?id=' . $caseId . '&pg=' . ($paginaAtual - 1)) ?>" style="padding:6px 12px;border-radius:6px;font-size:.82rem;font-weight:600;text-decoration:none;color:var(--sv-accent);background:var(--sv-accent-bg);border:1px solid var(--sv-border);">← Anterior</a>
            <?php endif; ?>

            <?php for ($pg = 1; $pg <= $totalPaginas; $pg++):
                $ativo = ($pg === $paginaAtual);
            ?>
                <?php if ($pg === 1 || $pg === $totalPaginas || abs($pg - $paginaAtual) <= 2): ?>
                    <a href="<?= sv_url('pages/processo_detalhe.php?id=' . $caseId . '&pg=' . $pg) ?>" style="padding:6px 12px;border-radius:6px;font-size:.82rem;font-weight:<?= $ativo ? '700' : '500' ?>;text-decoration:none;color:<?= $ativo ? '#fff' : 'var(--sv-accent)' ?>;background:<?= $ativo ? 'var(--sv-accent)' : 'var(--sv-accent-bg)' ?>;border:1px solid <?= $ativo ? 'var(--sv-accent)' : 'var(--sv-border)' ?>;"><?= $pg ?></a>
                <?php elseif (abs($pg - $paginaAtual) === 3): ?>
                    <span style="color:var(--sv-text-muted);font-size:.82rem;padding:0 4px;">…</span>
                <?php endif; ?>
            <?php endfor; ?>

            <?php if ($paginaAtual < $totalPaginas): ?>
                <a href="<?= sv_url('pages/processo_detalhe.php?id=' . $caseId . '&pg=' . ($paginaAtual + 1)) ?>" style="padding:6px 12px;border-radius:6px;font-size:.82rem;font-weight:600;text-decoration:none;color:var(--sv-accent);background:var(--sv-accent-bg);border:1px solid var(--sv-border);">Próxima →</a>
            <?php endif; ?>
        </div>
        <div style="text-align:center;color:var(--sv-text-muted);font-size:.72rem;margin-top:.5rem;">
            Página <?= $paginaAtual ?> de <?= $totalPaginas ?>
        </div>
        <?php endif; ?>

    <?php endif; ?>
</div>

<?php if (!empty($documentos)): ?>
<!-- Documentos Pendentes -->
<div class="sv-card" style="margin-bottom:1.5rem;">
    <h3>Documentos</h3>
    <div style="display:flex;flex-direction:column;gap:.6rem;">
        <?php foreach ($documentos as $doc):
            $docCor = $doc['status'] === 'recebido' ? '#059669' : '#f59e0b';
            $docLabel = $doc['status'] === 'recebido' ? '✅ Recebido' : '⏳ Pendente';
        ?>
        <div style="display:flex;align-items:center;gap:.75rem;padding:.6rem .8rem;background:var(--sv-accent-bg);border-radius:8px;">
            <div style="flex:1;">
                <div style="color:var(--sv-text);font-size:.88rem;"><?= sv_e($doc['descricao']) ?></div>
                <?php if (!empty($doc['solicitado_em'])): ?>
                <div style="color:var(--sv-text-muted);font-size:.72rem;margin-top:.15rem;">Solicitado em <?= sv_formatar_data($doc['solicitado_em']) ?></div>
                <?php endif; ?>
            </div>
            <span style="background:<?= $docCor ?>;color:#fff;padding:3px 10px;border-radius:6px;font-size:.72rem;font-weight:700;white-space:nowrap;"><?= $docLabel ?></span>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($eventos)): ?>
<!-- Próximos Compromissos -->
<div class="sv-card" style="margin-bottom:1.5rem;">
    <h3>Próximos Compromissos</h3>
    <div style="display:flex;flex-direction:column;gap:.6rem;">
        <?php foreach ($eventos as $ev): ?>
        <div style="display:flex;align-items:center;gap:.75rem;padding:.6rem .8rem;background:var(--sv-accent-bg);border-radius:8px;">
            <div style="text-align:center;min-width:50px;">
                <div style="font-family:'Playfair Display',serif;font-size:1.5rem;font-weight:700;color:var(--sv-accent);line-height:1;"><?= date('d', strtotime($ev['data_inicio'])) ?></div>
                <div style="font-size:.65rem;color:var(--sv-text-muted);text-transform:uppercase;"><?= date('M', strtotime($ev['data_inicio'])) ?></div>
            </div>
            <div style="flex:1;">
                <div style="color:var(--sv-text);font-size:.88rem;font-weight:600;"><?= sv_e($ev['titulo']) ?></div>
                <div style="color:var(--sv-text-muted);font-size:.78rem;"><?= sv_e(sv_nome_tipo_evento($ev['tipo'])) ?><?php if (!empty($ev['local'])): ?> · <?= sv_e($ev['local']) ?><?php endif; ?></div>
            </div>
            <span style="color:var(--sv-text-muted);font-size:.82rem;white-space:nowrap;"><?= date('H:i', strtotime($ev['data_inicio'])) ?></span>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($treinamentosCli)): ?>
<!-- Certificados de Treinamento -->
<div class="sv-card" style="margin-bottom:1.5rem;">
    <h3>🎓 Meus Certificados</h3>
    <p style="color:var(--sv-text-muted);font-size:.82rem;margin:.4rem 0 .8rem;">Certificados de treinamento obrigatório de audiência remota que você já concluiu. Uma cópia também está na pasta do processo do escritório.</p>
    <div style="display:flex;flex-direction:column;gap:.6rem;">
        <?php foreach ($treinamentosCli as $tr): ?>
        <div style="display:flex;align-items:center;gap:.75rem;padding:.7rem .85rem;background:linear-gradient(135deg,#ecfdf5,#d1fae5);border-radius:10px;border:1px solid #86efac;">
            <div style="font-size:1.6rem;flex-shrink:0;">🎓</div>
            <div style="flex:1;min-width:0;">
                <div style="color:var(--sv-text);font-size:.9rem;font-weight:700;line-height:1.3;">
                    Certificado de Treinamento
                    <?php if (!empty($tr['audiencia_titulo'])): ?>
                        <span style="font-weight:500;color:var(--sv-text-muted);font-size:.8rem;">· <?= sv_e($tr['audiencia_titulo']) ?></span>
                    <?php endif; ?>
                </div>
                <div style="color:var(--sv-text-muted);font-size:.75rem;margin-top:3px;">
                    Assinado em <?= date('d/m/Y \à\s H:i', strtotime($tr['aceite_em'])) ?>
                    <?php if (!empty($tr['audiencia_data_hora'])): ?>
                        · Audiência: <?= date('d/m/Y H:i', strtotime($tr['audiencia_data_hora'])) ?>
                    <?php endif; ?>
                </div>
            </div>
            <a href="https://ferreiraesa.com.br/conecta/publico/treinamento_audiencia.php?t=<?= urlencode($tr['token']) ?>" target="_blank" rel="noopener" class="sv-btn sv-btn-sm" style="background:#059669;color:#fff;padding:.4rem .7rem;font-size:.75rem;text-decoration:none;border-radius:6px;white-space:nowrap;">
                📄 Ver certificado
            </a>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Ação -->
<div style="margin-top:1rem;">
    <a href="<?= sv_url('pages/mensagem_nova.php?processo=' . $caseId) ?>" class="sv-btn sv-btn-gold" style="gap:6px;">💬 Enviar Mensagem sobre este Processo</a>
</div>

<script>
// Traduz um andamento jurídico em linguagem comum (IA, cache permanente).
// 1 clique = 1 chamada ao endpoint. Próximas visitas pegam do cache já salvo no DB.
function svTraduzirAndamento(andId, btnEl) {
    if (btnEl.disabled) return;
    var textoOriginal = btnEl.innerHTML;
    btnEl.disabled = true;
    btnEl.innerHTML = '⏳ Traduzindo...';

    fetch('<?= sv_url('pages/ia_traduzir_andamento.php') ?>?andamento_id=' + encodeURIComponent(andId), {
        credentials: 'same-origin'
    }).then(function(r) { return r.json(); }).then(function(j) {
        var holder = document.getElementById('and-traducao-' + andId);
        if (j.ok && j.traducao) {
            holder.style.display = '';
            holder.innerHTML =
                '<div style="margin-top:.5rem;padding:.65rem .8rem;background:rgba(99,102,241,.08);border-left:3px solid #6366f1;border-radius:6px;font-size:.85rem;color:var(--sv-text);line-height:1.55;">' +
                '<span style="font-size:.7rem;font-weight:700;color:#818cf8;text-transform:uppercase;letter-spacing:.4px;display:block;margin-bottom:.25rem;">📖 Em linguagem comum</span>' +
                _svEscape(j.traducao).replace(/\n/g, '<br>') +
                '</div>';
            // Esconde o botão (traduziu, missão cumprida)
            btnEl.style.display = 'none';
        } else {
            btnEl.disabled = false;
            btnEl.innerHTML = textoOriginal;
            alert(j.erro || 'Não foi possível traduzir agora. Tente em alguns instantes.');
        }
    }).catch(function() {
        btnEl.disabled = false;
        btnEl.innerHTML = textoOriginal;
        alert('Erro de conexão. Tente novamente.');
    });
}
function _svEscape(s) {
    var d = document.createElement('div');
    d.textContent = String(s || '');
    return d.innerHTML;
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
