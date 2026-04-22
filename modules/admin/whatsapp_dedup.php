<?php
/**
 * ============================================================
 * whatsapp_dedup.php — Ferramenta de mesclar conversas WhatsApp
 * duplicadas (problema @lid vs número real)
 * ============================================================
 *
 * Quando um contato WhatsApp envia mensagem, às vezes a Z-API
 * entrega o payload com identificador interno @lid em vez do
 * número de telefone. O sistema cria conversa separada pra
 * cada variação, gerando "uma conversa pra recebidas, outra
 * pra enviadas" do mesmo contato.
 *
 * Esta tela lista pares suspeitos (mesmo canal + mesmo nome OU
 * mesmo client_id) e permite mesclar: move todas as mensagens
 * da conversa origem pra destino, atualiza vínculos, delete a
 * origem vazia.
 *
 * ACESSO: admin only.
 * ============================================================
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();
if (!has_role('admin')) { flash_set('error', 'Sem permissao.'); redirect(url('modules/dashboard/')); }

$pdo = db();
$csrfToken = generate_csrf_token();

// ============================================================
// AJAX — mesclar 2 conversas
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mesclar') {
    header('Content-Type: application/json; charset=utf-8');
    if (!validate_csrf()) { echo json_encode(array('ok' => false, 'erro' => 'CSRF inválido', 'csrf' => generate_csrf_token())); exit; }

    $destinoId = (int)($_POST['destino_id'] ?? 0);  // conversa que FICA
    $origemId  = (int)($_POST['origem_id'] ?? 0);   // conversa que some (mensagens vão pra destino)
    if (!$destinoId || !$origemId || $destinoId === $origemId) {
        echo json_encode(array('ok' => false, 'erro' => 'IDs inválidos', 'csrf' => generate_csrf_token()));
        exit;
    }

    try {
        $pdo->beginTransaction();

        // Confere que as 2 existem e são do mesmo canal
        $stmt = $pdo->prepare("SELECT * FROM zapi_conversas WHERE id IN (?,?)");
        $stmt->execute(array($destinoId, $origemId));
        $convs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($convs) !== 2) {
            throw new Exception('Conversas não encontradas');
        }
        $dest = null; $orig = null;
        foreach ($convs as $c) {
            if ((int)$c['id'] === $destinoId) $dest = $c;
            if ((int)$c['id'] === $origemId)  $orig = $c;
        }
        if (!$dest || !$orig) throw new Exception('IDs trocados');
        if ($dest['canal'] !== $orig['canal']) {
            throw new Exception('Canais diferentes (21 vs 24) — não posso mesclar');
        }

        // 1. Move mensagens
        $upd1 = $pdo->prepare("UPDATE zapi_mensagens SET conversa_id = ? WHERE conversa_id = ?");
        $upd1->execute(array($destinoId, $origemId));
        $movidas = $upd1->rowCount();

        // 2. Move etiquetas (se houver tabela pivot)
        try {
            $upd2 = $pdo->prepare("UPDATE IGNORE zapi_conversa_etiquetas SET conversa_id = ? WHERE conversa_id = ?");
            $upd2->execute(array($destinoId, $origemId));
            // Remove duplicatas que podem ter surgido
            $pdo->prepare("DELETE FROM zapi_conversa_etiquetas WHERE conversa_id = ?")->execute(array($origemId));
        } catch (Exception $e) {}

        // 3. Preserva client_id / lead_id / atendente da origem se destino está vazio
        $camposPreservar = array('client_id', 'lead_id', 'atendente_id');
        $updates = array();
        $params = array();
        foreach ($camposPreservar as $c) {
            if (empty($dest[$c]) && !empty($orig[$c])) {
                $updates[] = "$c = ?";
                $params[] = $orig[$c];
            }
        }
        if (!empty($updates)) {
            $params[] = $destinoId;
            $pdo->prepare("UPDATE zapi_conversas SET " . implode(',', $updates) . " WHERE id = ?")
                ->execute($params);
        }

        // 4. Atualiza última mensagem do destino
        try {
            $pdo->prepare(
                "UPDATE zapi_conversas SET
                    ultima_msg_em = (SELECT MAX(created_at) FROM zapi_mensagens WHERE conversa_id = ?),
                    nao_lidas = (SELECT COUNT(*) FROM zapi_mensagens WHERE conversa_id = ? AND direcao='recebida' AND lida=0)
                 WHERE id = ?"
            )->execute(array($destinoId, $destinoId, $destinoId));
        } catch (Exception $e) {}

        // 5. Apaga conversa origem (agora vazia de mensagens)
        $pdo->prepare("DELETE FROM zapi_conversas WHERE id = ?")->execute(array($origemId));

        $pdo->commit();

        audit_log('WHATSAPP_CONVERSAS_MESCLADAS', 'zapi_conversas', $destinoId, 'absorveu #' . $origemId . ' (' . $movidas . ' msgs)');

        echo json_encode(array(
            'ok' => true,
            'msg' => "Mescla OK: {$movidas} mensagens movidas pra conversa #{$destinoId}.",
            'csrf' => generate_csrf_token(),
        ));
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(array('ok' => false, 'erro' => $e->getMessage(), 'csrf' => generate_csrf_token()));
    }
    exit;
}

// ============================================================
// Carrega pares candidatos à mesclagem
// ============================================================
// Estratégias de match (em ordem de confiança):
//   1) Mesmo canal + mesmo client_id (alta confiança)
//   2) Mesmo canal + mesmo nome_contato (média)
//   3) Mesmo canal + telefone "puro" igual (compara só dígitos)
$pares = array();

// Estratégia 1: client_id
try {
    $stmt = $pdo->query(
        "SELECT c1.id AS id1, c1.telefone AS tel1, c1.nome_contato AS nome1, c1.canal AS canal1,
                c1.ultima_msg_em AS ult1, c1.client_id AS cli,
                c2.id AS id2, c2.telefone AS tel2, c2.nome_contato AS nome2, c2.ultima_msg_em AS ult2,
                (SELECT COUNT(*) FROM zapi_mensagens WHERE conversa_id = c1.id) AS msgs1,
                (SELECT COUNT(*) FROM zapi_mensagens WHERE conversa_id = c2.id) AS msgs2
         FROM zapi_conversas c1
         INNER JOIN zapi_conversas c2
           ON c1.client_id = c2.client_id
          AND c1.canal = c2.canal
          AND c1.id < c2.id
         WHERE c1.client_id IS NOT NULL AND c1.client_id > 0
           AND (c1.eh_grupo = 0 OR c1.eh_grupo IS NULL)
           AND (c2.eh_grupo = 0 OR c2.eh_grupo IS NULL)
         ORDER BY c1.ultima_msg_em DESC
         LIMIT 100"
    );
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $pares[] = array_merge($r, array('motivo' => 'Mesmo cliente'));
    }
} catch (Exception $e) {}

// Estratégia 2: mesmo nome_contato (onde não tem client_id)
try {
    $stmt = $pdo->query(
        "SELECT c1.id AS id1, c1.telefone AS tel1, c1.nome_contato AS nome1, c1.canal AS canal1,
                c1.ultima_msg_em AS ult1, c1.client_id AS cli,
                c2.id AS id2, c2.telefone AS tel2, c2.nome_contato AS nome2, c2.ultima_msg_em AS ult2,
                (SELECT COUNT(*) FROM zapi_mensagens WHERE conversa_id = c1.id) AS msgs1,
                (SELECT COUNT(*) FROM zapi_mensagens WHERE conversa_id = c2.id) AS msgs2
         FROM zapi_conversas c1
         INNER JOIN zapi_conversas c2
           ON c1.nome_contato = c2.nome_contato
          AND c1.canal = c2.canal
          AND c1.id < c2.id
         WHERE c1.nome_contato IS NOT NULL AND c1.nome_contato != ''
           AND (c1.eh_grupo = 0 OR c1.eh_grupo IS NULL)
           AND (c2.eh_grupo = 0 OR c2.eh_grupo IS NULL)
           AND (c1.client_id IS NULL OR c2.client_id IS NULL OR c1.client_id != c2.client_id)
         ORDER BY c1.ultima_msg_em DESC
         LIMIT 100"
    );
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $pares[] = array_merge($r, array('motivo' => 'Mesmo nome'));
    }
} catch (Exception $e) {}

$pageTitle = 'WhatsApp — Mesclar duplicadas';
require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.dd-wrap { max-width:1200px; margin:0 auto; }
.dd-card { background:var(--bg-card); border:1px solid var(--border); border-radius:12px; padding:1rem 1.3rem; margin-bottom:1rem; }
.dd-pair { display:grid; grid-template-columns: 1fr auto 1fr auto; gap:.8rem; align-items:center; padding:.8rem 0; border-bottom:1px solid var(--border); }
.dd-pair:last-child { border:none; }
.dd-conv { background:#f8fafc; padding:.7rem .9rem; border-radius:8px; font-size:.82rem; }
.dd-conv.winner { background:#dcfce7; border:1px solid #86efac; }
.dd-conv h4 { margin:0 0 3px; font-size:.82rem; color:var(--petrol-900); font-weight:700; }
.dd-conv .tel { font-family:ui-monospace,monospace; font-size:.7rem; color:#64748b; }
.dd-conv .meta { font-size:.68rem; color:#64748b; margin-top:3px; }
.dd-arrow { font-size:1.5rem; color:#6366f1; font-weight:900; text-align:center; }
.dd-btn-merge { background:#B87333; color:#fff; border:none; border-radius:6px; padding:8px 14px; font-size:.78rem; cursor:pointer; font-weight:700; }
.dd-btn-merge:hover { background:#a0632b; }
.dd-btn-merge:disabled { background:#cbd5e1; cursor:wait; }
.dd-motivo { display:inline-block; padding:2px 8px; border-radius:12px; font-size:.6rem; font-weight:700; text-transform:uppercase; letter-spacing:.3px; background:#eef2ff; color:#4338ca; }
.dd-motivo.cliente { background:#dcfce7; color:#15803d; }
</style>

<div class="dd-wrap">
    <h2 style="color:var(--petrol-900);">🔀 WhatsApp — Mesclar conversas duplicadas</h2>
    <p style="font-size:.82rem;color:var(--text-muted);margin-bottom:1rem;">
        Pares de conversas suspeitas de serem o mesmo contato (diferem só por <code>@lid</code> vs número normal).
        A conversa com <strong>MAIS mensagens</strong> ou <strong>telefone mais "limpo"</strong> (sem @lid) é a destino.
        A outra é absorvida: todas as mensagens são movidas, etiquetas mesclam, cliente/lead/atendente preserva o que tinha.
    </p>

    <?php if (empty($pares)): ?>
        <div class="dd-card" style="text-align:center;color:#15803d;">✅ Nenhum par suspeito encontrado. Tudo consolidado.</div>
    <?php else: ?>
        <div class="dd-card">
            <h3 style="margin:0 0 .6rem;font-size:1rem;"><?= count($pares) ?> par(es) pra revisar</h3>
            <?php foreach ($pares as $i => $p):
                // Decide qual é destino e qual é origem
                // Prioridade: 1) telefone SEM @lid vence @lid, 2) mais mensagens, 3) mais recente
                $ehLid1 = strpos($p['tel1'], '@lid') !== false;
                $ehLid2 = strpos($p['tel2'], '@lid') !== false;
                if ($ehLid1 && !$ehLid2)      { $destino = 2; }
                elseif (!$ehLid1 && $ehLid2)  { $destino = 1; }
                elseif ((int)$p['msgs1'] > (int)$p['msgs2']) { $destino = 1; }
                elseif ((int)$p['msgs2'] > (int)$p['msgs1']) { $destino = 2; }
                else { $destino = strtotime($p['ult1']) >= strtotime($p['ult2']) ? 1 : 2; }

                $destId  = $destino === 1 ? $p['id1'] : $p['id2'];
                $origId  = $destino === 1 ? $p['id2'] : $p['id1'];
            ?>
            <div class="dd-pair" data-pair="<?= $i ?>">
                <div class="dd-conv <?= $destino === 1 ? 'winner' : '' ?>">
                    <h4>Conversa #<?= (int)$p['id1'] ?> <?= $destino === 1 ? '🏆 FICA' : '→ absorvida' ?></h4>
                    <div class="tel"><?= htmlspecialchars($p['tel1'], ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="meta">
                        <?= htmlspecialchars($p['nome1'] ?: '(sem nome)', ENT_QUOTES, 'UTF-8') ?> ·
                        <?= (int)$p['msgs1'] ?> msg<?= (int)$p['msgs1'] === 1 ? '' : 's' ?> ·
                        última: <?= $p['ult1'] ? date('d/m/Y H:i', strtotime($p['ult1'])) : '—' ?>
                    </div>
                </div>
                <div class="dd-arrow"><?= $destino === 1 ? '←' : '→' ?></div>
                <div class="dd-conv <?= $destino === 2 ? 'winner' : '' ?>">
                    <h4>Conversa #<?= (int)$p['id2'] ?> <?= $destino === 2 ? '🏆 FICA' : '→ absorvida' ?></h4>
                    <div class="tel"><?= htmlspecialchars($p['tel2'], ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="meta">
                        <?= htmlspecialchars($p['nome2'] ?: '(sem nome)', ENT_QUOTES, 'UTF-8') ?> ·
                        <?= (int)$p['msgs2'] ?> msg<?= (int)$p['msgs2'] === 1 ? '' : 's' ?> ·
                        última: <?= $p['ult2'] ? date('d/m/Y H:i', strtotime($p['ult2'])) : '—' ?>
                    </div>
                </div>
                <div>
                    <span class="dd-motivo <?= $p['motivo'] === 'Mesmo cliente' ? 'cliente' : '' ?>"><?= htmlspecialchars($p['motivo'], ENT_QUOTES, 'UTF-8') ?></span>
                    <button class="dd-btn-merge" data-dest="<?= (int)$destId ?>" data-orig="<?= (int)$origId ?>" data-pair="<?= $i ?>" onclick="mesclar(this)">🔀 Mesclar</button>
                    <button onclick="inverter(this,<?= $i ?>)" style="background:none;border:none;color:#6366f1;font-size:.7rem;cursor:pointer;margin-top:4px;display:block;">↻ Inverter direção</button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<script>
var DD_CSRF = <?= json_encode($csrfToken) ?>;

function mesclar(btn) {
    var dest = btn.dataset.dest;
    var orig = btn.dataset.orig;
    if (!confirm('Mesclar? A conversa #' + orig + ' será absorvida pela #' + dest + '. Todas as mensagens vão pra #' + dest + '.')) return;

    btn.disabled = true;
    btn.textContent = '⏳ Mesclando...';

    var fd = new FormData();
    fd.append('action', 'mesclar');
    fd.append('csrf_token', DD_CSRF);
    fd.append('destino_id', dest);
    fd.append('origem_id', orig);

    fetch(window.location.pathname, { method:'POST', body:fd, credentials:'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(j) {
            if (j.csrf) DD_CSRF = j.csrf;
            if (j.ok) {
                btn.closest('.dd-pair').style.opacity = '.4';
                btn.closest('.dd-pair').style.pointerEvents = 'none';
                btn.textContent = '✓ Mesclado';
                btn.style.background = '#15803d';
            } else {
                btn.disabled = false;
                btn.textContent = '🔀 Mesclar';
                alert('Erro: ' + (j.erro || '?'));
            }
        })
        .catch(function(e) {
            btn.disabled = false;
            btn.textContent = '🔀 Mesclar';
            alert('Erro de rede: ' + e.message);
        });
}

function inverter(btn, pairIdx) {
    var mergeBtn = btn.parentElement.querySelector('.dd-btn-merge');
    var atualDest = mergeBtn.dataset.dest;
    var atualOrig = mergeBtn.dataset.orig;
    mergeBtn.dataset.dest = atualOrig;
    mergeBtn.dataset.orig = atualDest;

    // Atualiza visual
    var par = btn.closest('.dd-pair');
    var convs = par.querySelectorAll('.dd-conv');
    convs[0].classList.toggle('winner');
    convs[1].classList.toggle('winner');
    var hs = par.querySelectorAll('.dd-conv h4');
    hs[0].textContent = hs[0].textContent.indexOf('FICA') > 0
        ? hs[0].textContent.replace('🏆 FICA', '→ absorvida')
        : hs[0].textContent.replace('→ absorvida', '🏆 FICA');
    hs[1].textContent = hs[1].textContent.indexOf('FICA') > 0
        ? hs[1].textContent.replace('🏆 FICA', '→ absorvida')
        : hs[1].textContent.replace('→ absorvida', '🏆 FICA');
    var arrow = par.querySelector('.dd-arrow');
    arrow.textContent = arrow.textContent === '→' ? '←' : '→';
}
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
