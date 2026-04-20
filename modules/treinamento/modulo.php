<?php
/**
 * Tela interna de um módulo de treinamento.
 * 3 abas: Conteúdo · Missão · Quiz. URL: ?slug=visao-geral
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();

$pdo = db();
$user = current_user();
$userId = (int)$user['id'];
$csrf = generate_csrf_token();

$slug = $_GET['slug'] ?? '';
if (!preg_match('/^[a-z0-9-]+$/', $slug)) { flash_set('error','Slug inválido.'); redirect(module_url('treinamento')); }

$stmt = $pdo->prepare("SELECT * FROM treinamento_modulos WHERE slug = ? AND ativo = 1");
$stmt->execute(array($slug));
$modulo = $stmt->fetch();
if (!$modulo) { flash_set('error','Módulo não encontrado.'); redirect(module_url('treinamento')); }

// Whitelist: módulos financeiros só pra Amanda/Rodrigo/Luiz (mesma regra do módulo real)
$slugsFinanceiros = array('financeiro', 'cobranca-honorarios');
if (in_array($slug, $slugsFinanceiros, true) && !can_access_financeiro()) {
    flash_set('error','Este treinamento é restrito.');
    redirect(module_url('treinamento'));
}

// Cria/carrega progresso
$pdo->prepare("INSERT IGNORE INTO treinamento_progresso (user_id, modulo_slug) VALUES (?, ?)")
    ->execute(array($userId, $slug));
$progStmt = $pdo->prepare("SELECT * FROM treinamento_progresso WHERE user_id = ? AND modulo_slug = ?");
$progStmt->execute(array($userId, $slug));
$prog = $progStmt->fetch() ?: array('conteudo_visto'=>0,'missao_feita'=>0,'quiz_concluido'=>0,'concluido'=>0,'quiz_acertos'=>0,'quiz_tentativas'=>0,'pontos_ganhos'=>0);

// Quiz
$quizStmt = $pdo->prepare("SELECT * FROM treinamento_quiz WHERE modulo_slug = ? ORDER BY ordem, id");
$quizStmt->execute(array($slug));
$perguntas = $quizStmt->fetchAll();

// Conteúdo didático
$conteudos = require __DIR__ . '/conteudo.php';
$cont = $conteudos[$slug] ?? array('por_que' => 'Conteúdo em preparação.', 'passos' => array(), 'atencao' => null, 'dica' => null, 'missao' => 'Explore o módulo no sistema.');

$aba = $_GET['aba'] ?? 'conteudo';
if (!in_array($aba, array('conteudo','missao','quiz'), true)) $aba = 'conteudo';

$pageTitle = 'Treinamento · ' . $modulo['titulo'];
require_once APP_ROOT . '/templates/layout_start.php';
?>

<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600;700&family=Outfit:wght@400;600;700&display=swap" rel="stylesheet">

<style>
.tm-wrap { max-width:920px; margin:0 auto; }
.tm-back { display:inline-flex; align-items:center; gap:5px; padding:6px 12px; background:#fff; border:1px solid #e5e7eb; border-radius:8px; text-decoration:none; color:#052228; font-size:.78rem; font-weight:600; margin-bottom:1rem; }
.tm-back:hover { border-color:#B87333; color:#B87333; }
.tm-hdr { background:linear-gradient(135deg,#052228,#0a3842); color:#fff; padding:1.8rem 2rem; border-radius:16px; margin-bottom:1.2rem; display:flex; gap:1rem; align-items:center; }
.tm-hdr .ico { font-size:3rem; line-height:1; }
.tm-hdr h1 { font-family:'Cormorant Garamond',serif; font-size:2rem; margin:0; color:#fff; font-weight:600; }
.tm-hdr .sub { font-size:.85rem; opacity:.85; margin-top:4px; font-family:'Outfit',sans-serif; }
.tm-abas { display:flex; gap:2px; background:#f3f4f6; padding:3px; border-radius:12px; margin-bottom:1.2rem; }
.tm-aba { flex:1; text-align:center; padding:10px; font-size:.82rem; font-weight:700; text-decoration:none; color:#6b7280; border-radius:9px; transition:all .15s; }
.tm-aba:hover { background:rgba(184,115,51,.1); color:#B87333; }
.tm-aba.active { background:#fff; color:#052228; box-shadow:0 2px 8px rgba(0,0,0,.08); }
.tm-aba.done { color:#059669; }
.tm-box { background:#fff; border:1px solid #e5e7eb; border-radius:14px; padding:1.8rem 2rem; }
.tm-box h2 { font-family:'Cormorant Garamond',serif; color:#052228; font-size:1.5rem; margin:0 0 .8rem; font-weight:600; }
.tm-box h3 { font-family:'Cormorant Garamond',serif; color:#B87333; font-size:1.2rem; margin:1.5rem 0 .6rem; font-weight:600; }
.tm-box p, .tm-box li { font-size:.92rem; line-height:1.65; color:#1A1A1A; }
.tm-box ol { padding-left:1.4rem; }
.tm-box ol li { margin-bottom:.6rem; }
.tm-callout { padding:1rem 1.2rem; border-radius:10px; margin:1.2rem 0; display:flex; gap:.8rem; }
.tm-callout .icon { font-size:1.4rem; flex-shrink:0; }
.tm-callout.warn { background:#fef3c7; border-left:4px solid #d97706; color:#78350f; }
.tm-callout.tip { background:#f5ede3; border-left:4px solid #B87333; color:#78350f; }
.tm-missao-btn { background:#059669; color:#fff; border:none; padding:12px 28px; border-radius:10px; font-size:.95rem; font-weight:700; cursor:pointer; margin-top:1rem; }
.tm-missao-btn:hover { background:#047857; }
.tm-missao-btn:disabled { background:#9ca3af; cursor:not-allowed; }
.tm-quiz-card { background:#f9fafb; border:2px solid #e5e7eb; border-radius:12px; padding:1.5rem; margin-bottom:1rem; }
.tm-quiz-pergunta { font-size:1rem; font-weight:600; color:#052228; margin-bottom:1rem; }
.tm-quiz-opts { display:flex; flex-direction:column; gap:8px; }
.tm-quiz-opt { padding:12px 16px; border:2px solid #e5e7eb; border-radius:10px; cursor:pointer; background:#fff; font-size:.88rem; transition:all .12s; text-align:left; }
.tm-quiz-opt:hover { border-color:#B87333; background:#fff7ed; }
.tm-quiz-opt.selected { border-color:#052228; background:#f5ede3; }
.tm-quiz-opt.correct { border-color:#059669; background:#d1fae5; }
.tm-quiz-opt.wrong { border-color:#dc2626; background:#fee2e2; }
.tm-quiz-feedback { margin-top:12px; padding:12px; border-radius:10px; font-size:.85rem; }
.tm-quiz-feedback.ok { background:#d1fae5; color:#065f46; border-left:4px solid #059669; }
.tm-quiz-feedback.nok { background:#fee2e2; color:#991b1b; border-left:4px solid #dc2626; }
.tm-quiz-result { text-align:center; padding:2rem; background:#f5ede3; border-radius:14px; }
.tm-quiz-result .score { font-family:'Cormorant Garamond',serif; font-size:3rem; font-weight:600; color:#052228; }
.tm-next-btn { background:#B87333; color:#fff; border:none; padding:12px 28px; border-radius:10px; font-size:.95rem; font-weight:700; cursor:pointer; margin-top:1rem; }
.tm-next-btn:hover { background:#a06428; }
</style>

<div class="tm-wrap">

<a href="<?= module_url('treinamento') ?>" class="tm-back">← Voltar aos módulos</a>

<div class="tm-hdr">
    <div class="ico"><?= e($modulo['icone']) ?></div>
    <div>
        <h1><?= e($modulo['titulo']) ?></h1>
        <div class="sub"><?= e($modulo['descricao']) ?></div>
    </div>
    <div style="margin-left:auto; text-align:right;">
        <div style="font-size:1.6rem; font-weight:800; color:#D7AB90;">+<?= (int)$modulo['pontos'] ?></div>
        <div style="font-size:.7rem; opacity:.85;">ao concluir</div>
    </div>
</div>

<div class="tm-abas">
    <?php
    $abas = array('conteudo' => array('📖', 'Conteúdo', $prog['conteudo_visto']),
                  'missao'   => array('🎯', 'Missão',   $prog['missao_feita']),
                  'quiz'     => array('❓', 'Quiz',     $prog['quiz_concluido']));
    foreach ($abas as $k => $v):
        $active = $aba === $k ? 'active' : '';
        $done = $v[2] ? 'done' : '';
    ?>
        <a href="?slug=<?= e($slug) ?>&aba=<?= $k ?>" class="tm-aba <?= $active ?> <?= $done ?>">
            <?= $v[0] ?> <?= $v[1] ?> <?= $v[2] ? '✓' : '' ?>
        </a>
    <?php endforeach; ?>
</div>

<?php if ($aba === 'conteudo'): ?>
<div class="tm-box">
    <h3>POR QUE ISSO IMPORTA</h3>
    <p><?= nl2br(e($cont['por_que'])) ?></p>

    <?php if (!empty($cont['passos'])): ?>
    <h3>PASSO A PASSO</h3>
    <ol>
        <?php foreach ($cont['passos'] as $p):
            $txt = e($p);
            $txt = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $txt);
            $txt = preg_replace('/`(.+?)`/', '<code>$1</code>', $txt);
        ?>
        <li><?= $txt ?></li>
        <?php endforeach; ?>
    </ol>
    <?php endif; ?>

    <?php if (!empty($cont['atencao'])): ?>
    <div class="tm-callout warn">
        <div class="icon">⚠️</div>
        <div><strong>ATENÇÃO — erros comuns</strong><br><?= nl2br(e($cont['atencao'])) ?></div>
    </div>
    <?php endif; ?>

    <?php if (!empty($cont['dica'])): ?>
    <div class="tm-callout tip">
        <div class="icon">💡</div>
        <div><strong>DICA DE OURO</strong><br><?= nl2br(e($cont['dica'])) ?></div>
    </div>
    <?php endif; ?>

    <div style="margin-top:2rem; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:1rem;">
        <button id="btnConteudo" class="tm-missao-btn" <?= $prog['conteudo_visto'] ? 'disabled' : '' ?>>
            <?= $prog['conteudo_visto'] ? '✓ Conteúdo lido' : 'Marcar como lido →' ?>
        </button>
        <?php if (!$prog['missao_feita']): ?>
            <a href="?slug=<?= e($slug) ?>&aba=missao" class="tm-next-btn" style="text-decoration:none;">Ir pra missão 🎯</a>
        <?php elseif (!$prog['quiz_concluido']): ?>
            <a href="?slug=<?= e($slug) ?>&aba=quiz" class="tm-next-btn" style="text-decoration:none;">Ir pro quiz ❓</a>
        <?php endif; ?>
    </div>
</div>

<?php elseif ($aba === 'missao'): ?>
<div class="tm-box">
    <h2>🎯 Missão prática</h2>
    <p style="font-size:1rem; line-height:1.7; color:#1A1A1A;"><?= nl2br(e($cont['missao'])) ?></p>

    <div class="tm-callout tip">
        <div class="icon">💪</div>
        <div>Abra o sistema em outra aba, execute a tarefa, volte aqui e clique no botão verde abaixo quando tiver feito.</div>
    </div>

    <button id="btnMissao" class="tm-missao-btn" <?= $prog['missao_feita'] ? 'disabled' : '' ?>>
        <?= $prog['missao_feita'] ? '✓ Missão concluída' : 'Missão concluída ✓' ?>
    </button>

    <?php if (!$prog['quiz_concluido'] && $prog['missao_feita']): ?>
        <a href="?slug=<?= e($slug) ?>&aba=quiz" class="tm-next-btn" style="text-decoration:none; margin-left:10px;">Ir pro quiz ❓</a>
    <?php endif; ?>
</div>

<?php else: /* quiz */ ?>
<div class="tm-box" id="quizContainer">
    <?php if (empty($perguntas)): ?>
        <p>Quiz deste módulo ainda em preparação.</p>
    <?php elseif ($prog['quiz_concluido']):
        $totalQ = count($perguntas);
        $pctAcerto = round($prog['quiz_acertos'] / max($totalQ, 1) * 100);
        $moduloConcluido = (int)$prog['concluido'] === 1;
        $falta = array();
        if (!$prog['conteudo_visto']) $falta[] = array('📖 Ler conteúdo', 'conteudo');
        if (!$prog['missao_feita'])   $falta[] = array('🎯 Fazer missão', 'missao');
    ?>
        <?php if ($moduloConcluido): ?>
            <div class="tm-quiz-result">
                <div class="score" style="color:#059669;">🎉</div>
                <h2>Módulo concluído!</h2>
                <p>Você acertou <?= (int)$prog['quiz_acertos'] ?> de <?= $totalQ ?> (<?= $pctAcerto ?>%).</p>
                <p style="margin-top:1rem;">+<strong style="color:#B87333;"><?= (int)$prog['pontos_ganhos'] ?> pts</strong> creditados 🏆</p>
                <a href="?slug=<?= e($slug) ?>&aba=quiz&refazer=1" class="tm-next-btn" style="text-decoration:none; margin-top:1rem; display:inline-block;">🔄 Refazer quiz</a>
            </div>
        <?php else: ?>
            <div class="tm-quiz-result">
                <div class="score" style="color:#059669;">✅</div>
                <h2 style="color:#059669;">Quiz aprovado!</h2>
                <p>Você acertou <?= (int)$prog['quiz_acertos'] ?> de <?= $totalQ ?> (<?= $pctAcerto ?>%) — excelente!</p>
                <p style="margin-top:1rem; color:#78350f; background:#fef3c7; padding:.8rem; border-radius:8px;">
                    <strong>Pra finalizar o módulo e receber os +<?= (int)$modulo['pontos'] ?> pts, falta:</strong><br>
                    <?= implode(' e ', array_map(function($f){ return $f[0]; }, $falta)) ?>
                </p>
                <div style="display:flex; gap:.5rem; justify-content:center; margin-top:1rem; flex-wrap:wrap;">
                    <?php foreach ($falta as $f): ?>
                        <a href="?slug=<?= e($slug) ?>&aba=<?= $f[1] ?>" class="tm-next-btn" style="text-decoration:none;"><?= e($f[0]) ?></a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <h2>❓ Quiz — <?= count($perguntas) ?> pergunta(s)</h2>
        <p style="color:#6b7280; font-size:.85rem;">Precisa acertar pelo menos <strong>70%</strong> pra concluir o módulo.</p>
        <form id="quizForm">
            <?php foreach ($perguntas as $i => $p): ?>
            <div class="tm-quiz-card" data-qid="<?= (int)$p['id'] ?>">
                <div class="tm-quiz-pergunta"><?= ($i+1) ?>. <?= e($p['pergunta']) ?></div>
                <div class="tm-quiz-opts">
                    <?php foreach (array('a','b','c','d') as $letra): ?>
                    <label class="tm-quiz-opt">
                        <input type="radio" name="q<?= (int)$p['id'] ?>" value="<?= $letra ?>" style="display:none;">
                        <strong><?= strtoupper($letra) ?>)</strong> <?= e($p['opcao_' . $letra]) ?>
                    </label>
                    <?php endforeach; ?>
                </div>
                <div class="tm-quiz-feedback" style="display:none;"></div>
            </div>
            <?php endforeach; ?>
            <button type="button" id="btnEnviarQuiz" class="tm-next-btn" style="display:block; width:100%; padding:15px;">Enviar respostas ✓</button>
        </form>
    <?php endif; ?>
</div>

<script>
(function(){
    // Seleção de opções
    document.querySelectorAll('.tm-quiz-opt').forEach(function(opt){
        opt.addEventListener('click', function(){
            var card = opt.closest('.tm-quiz-card');
            if (!card) return;
            if (card.dataset.answered === '1') return; // já respondeu
            card.querySelectorAll('.tm-quiz-opt').forEach(function(o){ o.classList.remove('selected'); });
            opt.classList.add('selected');
            opt.querySelector('input').checked = true;
        });
    });

    var btn = document.getElementById('btnEnviarQuiz');
    if (btn) {
        btn.addEventListener('click', function(){
            var respostas = {};
            var todasRespondidas = true;
            document.querySelectorAll('.tm-quiz-card').forEach(function(card){
                var sel = card.querySelector('input[type="radio"]:checked');
                if (!sel) { todasRespondidas = false; return; }
                respostas[card.dataset.qid] = sel.value;
            });
            if (!todasRespondidas) { alert('Responda todas as perguntas antes de enviar.'); return; }

            btn.disabled = true; btn.textContent = 'Enviando...';
            var fd = new FormData();
            fd.append('action', 'salvar_quiz');
            fd.append('csrf_token', '<?= e($csrf) ?>');
            fd.append('slug', '<?= e($slug) ?>');
            fd.append('respostas', JSON.stringify(respostas));

            fetch('<?= module_url('treinamento','api.php') ?>', { method: 'POST', body: fd })
                .then(function(r){ return r.json(); })
                .then(function(d){
                    if (d.error) { alert(d.error); btn.disabled = false; btn.textContent = 'Enviar respostas ✓'; return; }
                    // Mostra feedback em cada pergunta
                    (d.detalhes || []).forEach(function(det){
                        var card = document.querySelector('.tm-quiz-card[data-qid="' + det.id + '"]');
                        if (!card) return;
                        card.dataset.answered = '1';
                        card.querySelectorAll('.tm-quiz-opt').forEach(function(o){
                            var inp = o.querySelector('input'); if (!inp) return;
                            o.classList.remove('selected');
                            if (inp.value === det.correta) o.classList.add('correct');
                            else if (inp.value === det.escolhida) o.classList.add('wrong');
                        });
                        var fb = card.querySelector('.tm-quiz-feedback');
                        fb.className = 'tm-quiz-feedback ' + (det.acertou ? 'ok' : 'nok');
                        fb.innerHTML = (det.acertou ? '✅ Correto! ' : '❌ Errado. ') + (det.explicacao || '');
                        fb.style.display = 'block';
                    });
                    btn.style.display = 'none';
                    var resultado = document.createElement('div');
                    resultado.className = 'tm-quiz-result';
                    resultado.style.marginTop = '1.5rem';

                    if (d.concluido) {
                        // Cenário A: 70%+ E módulo concluído (3 etapas feitas)
                        resultado.innerHTML = '<div class="score" style="color:#059669;">🎉</div>' +
                            '<h2>Parabéns! Módulo concluído!</h2>' +
                            '<p>Acertou ' + d.acertos + '/' + d.total + ' (' + d.percentual + '%)</p>' +
                            '<p style="margin-top:1rem;">+<strong style="color:#B87333;">' + d.pontos + ' pontos</strong> creditados 🏆</p>' +
                            '<a href="<?= module_url('treinamento') ?>" class="tm-next-btn" style="text-decoration:none; margin-top:1rem; display:inline-block;">Voltar aos módulos</a>';
                    } else if (d.quiz_passou) {
                        // Cenário B: passou no quiz MAS faltou conteúdo/missão
                        var falta = [];
                        if (d.pendencias && d.pendencias.indexOf('conteudo') >= 0) falta.push('📖 marcar o conteúdo como lido');
                        if (d.pendencias && d.pendencias.indexOf('missao') >= 0) falta.push('🎯 fazer a missão prática');
                        var txt = falta.length ? falta.join(' e ') : 'marcar os outros passos';
                        resultado.innerHTML = '<div class="score" style="color:#059669;">✅</div>' +
                            '<h2 style="color:#059669;">Quiz aprovado!</h2>' +
                            '<p>Acertou ' + d.acertos + '/' + d.total + ' (' + d.percentual + '%) — excelente!</p>' +
                            '<p style="margin-top:1rem; color:#78350f; background:#fef3c7; padding:.8rem; border-radius:8px;"><strong>Pra concluir o módulo e receber os pontos, falta ' + txt + '.</strong></p>' +
                            '<div style="display:flex; gap:.5rem; justify-content:center; margin-top:1rem; flex-wrap:wrap;">' +
                                (d.pendencias.indexOf('conteudo') >= 0 ? '<a href="?slug=<?= e($slug) ?>&aba=conteudo" class="tm-next-btn" style="text-decoration:none;">📖 Ler conteúdo</a>' : '') +
                                (d.pendencias.indexOf('missao') >= 0 ? '<a href="?slug=<?= e($slug) ?>&aba=missao" class="tm-next-btn" style="text-decoration:none;">🎯 Ir pra missão</a>' : '') +
                            '</div>';
                    } else {
                        // Cenário C: < 70% — não passou no quiz
                        resultado.innerHTML = '<div class="score" style="color:#dc2626;">💪</div>' +
                            '<h2>Quase lá!</h2>' +
                            '<p>Acertou ' + d.acertos + '/' + d.total + ' (' + d.percentual + '%)</p>' +
                            '<p style="color:#6b7280;">Precisa de pelo menos 70%. Revise o conteúdo e tente novamente.</p>' +
                            '<a href="?slug=<?= e($slug) ?>&aba=quiz&refazer=1" class="tm-next-btn" style="text-decoration:none; margin-top:1rem; display:inline-block;">🔄 Tentar novamente</a>';
                    }
                    document.getElementById('quizContainer').appendChild(resultado);
                    resultado.scrollIntoView({ behavior: 'smooth' });
                });
        });
    }
})();
</script>
<?php endif; ?>

</div>

<script>
// Marcar conteúdo/missão
var CSRF = '<?= e($csrf) ?>', API = '<?= module_url('treinamento','api.php') ?>', SLUG = '<?= e($slug) ?>';

document.getElementById('btnConteudo')?.addEventListener('click', function(){
    this.disabled = true; this.textContent = 'Salvando...';
    var fd = new FormData(); fd.append('action','marcar_conteudo'); fd.append('csrf_token',CSRF); fd.append('slug',SLUG);
    fetch(API,{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
        if (d.ok) { location.reload(); }
        else { alert(d.error||'Erro'); this.disabled=false; this.textContent='Marcar como lido →'; }
    });
});
document.getElementById('btnMissao')?.addEventListener('click', function(){
    this.disabled = true; this.textContent = 'Salvando...';
    var fd = new FormData(); fd.append('action','marcar_missao'); fd.append('csrf_token',CSRF); fd.append('slug',SLUG);
    fetch(API,{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
        if (d.ok) { location.reload(); }
        else { alert(d.error||'Erro'); this.disabled=false; this.textContent='Missão concluída ✓'; }
    });
});
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
