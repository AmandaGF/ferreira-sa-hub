<?php
/**
 * Sala VIP F&S — Perguntas Frequentes
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
$pdo = sv_db();
$user = salavip_current_user();
$clienteId = salavip_current_cliente_id();

// --- Buscar FAQs ---
$stmt = $pdo->query("SELECT * FROM salavip_faq WHERE ativo = 1 ORDER BY ordem ASC");
$faqs = $stmt->fetchAll();

$pageTitle = 'Perguntas Frequentes';
require_once __DIR__ . '/../includes/header.php';
?>

<?php if (empty($faqs)): ?>
    <div class="sv-empty">Nenhuma pergunta cadastrada ainda.</div>
<?php else: ?>
    <div style="display:flex;flex-direction:column;gap:0;">
        <?php foreach ($faqs as $i => $faq): ?>
            <div style="border-bottom:1px solid rgba(201,169,78,.15);">
                <div onclick="var r=document.getElementById('faq-resp-<?= $i ?>');r.style.display=r.style.display==='none'?'block':'none';var seta=document.getElementById('faq-seta-<?= $i ?>');seta.textContent=r.style.display==='none'?'+':'-';"
                     style="display:flex;justify-content:space-between;align-items:center;padding:1rem .5rem;cursor:pointer;user-select:none;">
                    <span style="color:#c9a94e;font-weight:600;font-size:1rem;flex:1;"><?= sv_e($faq['pergunta']) ?></span>
                    <span id="faq-seta-<?= $i ?>" style="color:#c9a94e;font-size:1.25rem;font-weight:700;margin-left:1rem;min-width:1.5rem;text-align:center;">+</span>
                </div>
                <div id="faq-resp-<?= $i ?>" style="display:none;padding:0 .5rem 1rem 1rem;">
                    <div style="color:#cbd5e1;line-height:1.7;background:rgba(30,41,59,.4);padding:1rem;border-radius:.5rem;">
                        <?= nl2br(sv_e($faq['resposta'])) ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
