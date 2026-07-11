/**
 * FSA Feedback — biblioteca global de confirmação visual de save.
 *
 * Motivação: Amanda (review 11/07/2026) reportou 3 bugs intermitentes onde
 * o sistema simplesmente não salvava, sem mostrar erro. Sem feedback visível,
 * o usuário não sabe se persistiu — precisa recarregar página pra conferir.
 *
 * API pública (todos os métodos globais em window.FsaFeedback):
 *
 *   Toast global (canto sup. direito, auto-dismiss):
 *     FsaFeedback.ok('Salvo com sucesso');
 *     FsaFeedback.erro('Não foi possível salvar');
 *     FsaFeedback.aviso('Verifique os dados');
 *     FsaFeedback.info('Processando...');
 *
 *   Feedback inline por campo (badge ao lado do input):
 *     FsaFeedback.campoOk(inputEl);              // ✓ verde 2s
 *     FsaFeedback.campoErro(inputEl, 'mensagem'); // ✗ vermelho persistente
 *     FsaFeedback.campoLimpar(inputEl);           // remove badge
 *
 *   Wrap de form POST tradicional (watchdog + botão desabilitado):
 *     FsaFeedback.wrapForm(formEl, {
 *         watchdog: 15000,        // ms até alertar "travou"
 *         confirmMsg: 'Deseja...' // opcional: confirm() antes
 *     });
 *
 *   AutoSave em input (AJAX inline com feedback visual):
 *     FsaFeedback.autoSave(inputEl, {
 *         url:    '/modules/x/api.php',
 *         body:   function(el) { return new FormData(); },
 *         onSaved: function(json, el) {}          // callback opcional
 *     });
 */
(function() {
    'use strict';

    if (window.FsaFeedback) return; // já carregado

    // ── Container de toasts ──────────────────────────
    var toastContainer = null;
    function ensureContainer() {
        if (toastContainer && document.body.contains(toastContainer)) return toastContainer;
        toastContainer = document.createElement('div');
        toastContainer.id = 'fsaToastContainer';
        toastContainer.setAttribute('aria-live', 'polite');
        toastContainer.style.cssText = 'position:fixed;top:20px;right:20px;z-index:99997;display:flex;flex-direction:column;gap:10px;pointer-events:none;';
        document.body.appendChild(toastContainer);
        return toastContainer;
    }

    var TOAST_CONFIG = {
        ok:    { icon: '✓', bg: '#16a34a', duracao: 3000 },
        erro:  { icon: '✗', bg: '#dc2626', duracao: 6000 },
        aviso: { icon: '⚠', bg: '#d97706', duracao: 4500 },
        info:  { icon: 'ℹ', bg: '#0369a1', duracao: 3000 }
    };

    function toast(tipo, mensagem) {
        var cfg = TOAST_CONFIG[tipo] || TOAST_CONFIG.info;
        var cont = ensureContainer();
        var t = document.createElement('div');
        t.className = 'fsa-toast fsa-toast-' + tipo;
        t.style.cssText = 'background:' + cfg.bg + ';color:#fff;padding:12px 16px;border-radius:10px;box-shadow:0 6px 20px rgba(0,0,0,.25);font-size:.88rem;font-weight:600;font-family:inherit;max-width:360px;min-width:200px;pointer-events:auto;display:flex;align-items:center;gap:10px;opacity:0;transform:translateX(20px);transition:opacity .25s,transform .25s;cursor:pointer;';
        t.innerHTML = '<span style="font-size:1.1rem;font-weight:800;flex-shrink:0;">' + cfg.icon + '</span><span style="flex:1;">' + String(mensagem).replace(/</g, '&lt;') + '</span>';
        t.addEventListener('click', function() { dismissToast(t); });
        cont.appendChild(t);
        // Anima entrada
        requestAnimationFrame(function() {
            t.style.opacity = '1';
            t.style.transform = 'translateX(0)';
        });
        // Auto-dismiss
        setTimeout(function() { dismissToast(t); }, cfg.duracao);
        return t;
    }
    function dismissToast(t) {
        if (!t || !t.parentNode) return;
        t.style.opacity = '0';
        t.style.transform = 'translateX(20px)';
        setTimeout(function() { if (t.parentNode) t.parentNode.removeChild(t); }, 300);
    }

    // ── Feedback inline por campo ─────────────────────
    function campoBadge(inputEl, tipo, mensagem, persistente) {
        if (!inputEl || !inputEl.parentNode) return;
        // Remove badge antigo se houver
        campoLimpar(inputEl);
        var cfg = TOAST_CONFIG[tipo] || TOAST_CONFIG.info;
        var b = document.createElement('span');
        b.className = 'fsa-campo-feedback fsa-campo-' + tipo;
        b.setAttribute('data-fsa-campo', '1');
        b.style.cssText = 'display:inline-block;margin-left:6px;padding:2px 8px;background:' + cfg.bg + ';color:#fff;font-size:.7rem;font-weight:700;border-radius:12px;vertical-align:middle;animation:fsaFadeIn .2s;';
        b.innerHTML = cfg.icon + (mensagem ? ' ' + String(mensagem).replace(/</g, '&lt;') : '');
        // Insere após o input
        if (inputEl.nextSibling) inputEl.parentNode.insertBefore(b, inputEl.nextSibling);
        else inputEl.parentNode.appendChild(b);
        // Também dá outline colorido no input
        inputEl.style.transition = 'outline .2s';
        inputEl.style.outline = '2px solid ' + cfg.bg;
        inputEl.style.outlineOffset = '1px';
        if (!persistente) {
            setTimeout(function() { campoLimpar(inputEl); }, 2500);
        }
    }
    function campoLimpar(inputEl) {
        if (!inputEl) return;
        inputEl.style.outline = '';
        inputEl.style.outlineOffset = '';
        if (inputEl.parentNode) {
            var antigos = inputEl.parentNode.querySelectorAll('[data-fsa-campo="1"]');
            for (var i = 0; i < antigos.length; i++) {
                // Só remove os que estão logo depois do input dele
                if (antigos[i].previousElementSibling === inputEl) antigos[i].parentNode.removeChild(antigos[i]);
            }
        }
    }

    // ── Wrap form POST tradicional ───────────────────
    function wrapForm(formEl, opts) {
        if (!formEl || formEl.dataset.fsaWrapped === '1') return;
        formEl.dataset.fsaWrapped = '1';
        opts = opts || {};
        var watchdogMs = opts.watchdog || 15000;
        var confirmMsg = opts.confirmMsg || null;

        formEl.addEventListener('submit', function(ev) {
            if (formEl.dataset.fsaEnviando === '1') { ev.preventDefault(); return false; }
            if (confirmMsg && !confirm(confirmMsg)) { ev.preventDefault(); return false; }
            formEl.dataset.fsaEnviando = '1';
            var btn = formEl.querySelector('button[type=submit],input[type=submit]');
            var textoOriginal = btn ? (btn.tagName === 'BUTTON' ? btn.innerHTML : btn.value) : '';
            if (btn) {
                btn.disabled = true;
                if (btn.tagName === 'BUTTON') btn.innerHTML = '⏳ Enviando...';
                else btn.value = '⏳ Enviando...';
            }
            setTimeout(function() {
                if (formEl.dataset.fsaEnviando === '1') {
                    formEl.dataset.fsaEnviando = '';
                    if (btn) {
                        btn.disabled = false;
                        if (btn.tagName === 'BUTTON') btn.innerHTML = textoOriginal;
                        else btn.value = textoOriginal;
                    }
                    toast('erro', 'O envio parece ter travado — clique de novo pra tentar. Se persistir, avise o suporte.');
                }
            }, watchdogMs);
        });
    }

    // ── AutoSave AJAX em input ────────────────────────
    function autoSave(inputEl, opts) {
        if (!inputEl || inputEl.dataset.fsaAutoSaved === '1') return;
        inputEl.dataset.fsaAutoSaved = '1';
        if (!opts || !opts.url || typeof opts.body !== 'function') {
            console.warn('FsaFeedback.autoSave: opts.url e opts.body(el) obrigatorios');
            return;
        }
        var saving = false;
        var trigger = function() {
            if (saving) return;
            saving = true;
            var body = opts.body(inputEl);
            var fetchOpts = { method: opts.method || 'POST', body: body };
            fetch(opts.url, fetchOpts)
                .then(function(r) { return r.json().catch(function() { return { ok: false, erro: 'Resposta inválida' }; }); })
                .then(function(j) {
                    saving = false;
                    if (j && j.ok) {
                        campoBadge(inputEl, 'ok', 'salvo', false);
                        if (typeof opts.onSaved === 'function') opts.onSaved(j, inputEl);
                    } else {
                        var msg = (j && j.erro) ? j.erro : 'Falha ao salvar';
                        campoBadge(inputEl, 'erro', msg, true);
                    }
                })
                .catch(function(e) {
                    saving = false;
                    campoBadge(inputEl, 'erro', 'Erro de rede', true);
                });
        };
        // Múltiplos gatilhos redundantes (padrão que resolve bug intermitente)
        inputEl.addEventListener('change', trigger);
        inputEl.addEventListener('blur', trigger);
        if (inputEl.tagName === 'INPUT' && (inputEl.type === 'text' || inputEl.type === 'number')) {
            inputEl.addEventListener('keydown', function(ev) {
                if (ev.key === 'Enter') { ev.preventDefault(); inputEl.blur(); }
            });
        }
    }

    // ── Detecta flash mensagens do PHP e vira toast ──
    // Sistema renderiza flash como <div class="alert alert-success|error|warning|info">.
    // Convertemos pra toast e escondemos o div original.
    function absorverFlash() {
        var mapa = { 'alert-success': 'ok', 'alert-error': 'erro', 'alert-warning': 'aviso', 'alert-info': 'info' };
        Object.keys(mapa).forEach(function(cls) {
            var els = document.querySelectorAll('.alert.' + cls);
            for (var i = 0; i < els.length; i++) {
                var el = els[i];
                // Se ja foi absorvido antes, pula
                if (el.dataset.fsaAbsorvido === '1') continue;
                // Remove ícone interno (span.alert-icon) e pega texto útil
                var iconEl = el.querySelector('.alert-icon');
                if (iconEl) iconEl.remove();
                var msg = (el.textContent || '').trim();
                if (msg) toast(mapa[cls], msg);
                el.dataset.fsaAbsorvido = '1';
                el.style.display = 'none';
            }
        });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', absorverFlash);
    } else {
        absorverFlash();
    }

    // ── Injeta keyframe único ─────────────────────────
    if (!document.getElementById('fsaFeedbackStyle')) {
        var s = document.createElement('style');
        s.id = 'fsaFeedbackStyle';
        s.textContent = '@keyframes fsaFadeIn{from{opacity:0;transform:scale(.85)}to{opacity:1;transform:scale(1)}}';
        document.head.appendChild(s);
    }

    // ── Exposição pública ─────────────────────────────
    window.FsaFeedback = {
        ok:    function(msg) { return toast('ok', msg); },
        erro:  function(msg) { return toast('erro', msg); },
        aviso: function(msg) { return toast('aviso', msg); },
        info:  function(msg) { return toast('info', msg); },
        campoOk:    function(el) { campoBadge(el, 'ok', 'salvo', false); },
        campoErro:  function(el, msg) { campoBadge(el, 'erro', msg || 'erro', true); },
        campoLimpar: campoLimpar,
        wrapForm:   wrapForm,
        autoSave:   autoSave
    };
})();
