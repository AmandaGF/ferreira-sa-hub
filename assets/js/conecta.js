/**
 * Ferreira & Sá Conecta — JavaScript Compartilhado
 */

document.addEventListener('DOMContentLoaded', () => {
    initSidebar();
    initAlerts();
    initModals();
    initConfirmActions();
    initNotifications();
});

/* ─── Sidebar Toggle (mobile) ────────────────────────── */
function initSidebar() {
    const toggle = document.querySelector('.btn-sidebar-toggle');
    const sidebar = document.querySelector('.sidebar');
    const overlay = document.querySelector('.sidebar-overlay');

    if (!toggle || !sidebar) return;

    toggle.addEventListener('click', () => {
        sidebar.classList.toggle('open');
        if (overlay) overlay.classList.toggle('open');
    });

    if (overlay) {
        overlay.addEventListener('click', () => {
            sidebar.classList.remove('open');
            overlay.classList.remove('open');
        });
    }

    // Mobile: ao clicar em qualquer link interno da sidebar, fecha automaticamente
    // pra não cobrir o conteúdo. Sem isso, usuário abre menu → clica link → pagina
    // carrega com sidebar ainda aberta visualmente, bloqueando conteúdo.
    sidebar.addEventListener('click', (e) => {
        if (window.innerWidth > 768) return;
        const link = e.target.closest('a[href]');
        if (!link) return;
        sidebar.classList.remove('open');
        if (overlay) overlay.classList.remove('open');
    });
}

/* ─── Auto-dismiss alerts ────────────────────────────── */
function initAlerts() {
    document.querySelectorAll('.alert').forEach(alert => {
        setTimeout(() => {
            alert.style.transition = 'opacity .4s ease, transform .4s ease';
            alert.style.opacity = '0';
            alert.style.transform = 'translateY(-10px)';
            setTimeout(() => alert.remove(), 400);
        }, 5000);
    });
}

/* ─── Modais ──────────────────────────────────────────── */
// Amanda 09/07/2026: migrado de bind direto pra event delegation no document.
// Bug: apos navegar de Dashboard -> Prazos, primeiro clique no botao
// '+ Novo Prazo' nao abria modal — 2o clique funcionava. Provavel causa:
// timing entre PWA cache + DOMContentLoaded fazia initModals() rodar
// antes de todos [data-modal] existirem, ou algum re-render movia o botao.
// Delegation resolve os 2 casos: elementos futuros ganham binding automatico.
function initModals() {
    // Abrir modal — delegado
    document.addEventListener('click', (e) => {
        const trigger = e.target.closest('[data-modal]');
        if (!trigger) return;
        e.preventDefault();
        const modal = document.getElementById(trigger.dataset.modal);
        if (modal) modal.classList.add('open');
    });

    // Fechar (botao close / botao data-modal-close / click no overlay) — delegado
    document.addEventListener('click', (e) => {
        // Botao interno de fechar
        const closeBtn = e.target.closest('.modal-close, [data-modal-close]');
        if (closeBtn) {
            const overlay = closeBtn.closest('.modal-overlay');
            if (overlay) overlay.classList.remove('open');
            return;
        }
        // Click direto no overlay (fundo escuro)
        if (e.target.classList && e.target.classList.contains('modal-overlay')) {
            e.target.classList.remove('open');
        }
    });

    // Fechar com ESC
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal-overlay.open').forEach(m => m.classList.remove('open'));
        }
    });
}

/* ─── Confirmação de ações perigosas ──────────────────── */
function initConfirmActions() {
    document.querySelectorAll('[data-confirm]').forEach(el => {
        el.addEventListener('click', (e) => {
            const msg = el.dataset.confirm || 'Tem certeza?';
            if (!confirm(msg)) {
                e.preventDefault();
                e.stopImmediatePropagation();
            }
        });
    });
}

/* ─── Notificações dropdown ─────────────────────────── */
function initNotifications() {
    var bell = document.getElementById('notifBell');
    var dropdown = document.getElementById('notifDropdown');
    if (!bell || !dropdown) return;

    bell.addEventListener('click', function(e) {
        e.stopPropagation();
        dropdown.classList.toggle('open');
    });

    document.addEventListener('click', function(e) {
        if (!dropdown.contains(e.target) && e.target !== bell) {
            dropdown.classList.remove('open');
        }
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') dropdown.classList.remove('open');
    });
}

/* ─── Utilitários globais ────────────────────────────── */
function copyToClipboard(text) {
    if (navigator.clipboard) {
        navigator.clipboard.writeText(text);
    } else {
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
    }
}

function showToast(message, duration = 2500) {
    let toast = document.getElementById('toast');
    if (!toast) {
        toast = document.createElement('div');
        toast.id = 'toast';
        toast.style.cssText = 'position:fixed;bottom:2rem;left:50%;transform:translateX(-50%) translateY(20px);' +
            'background:#052228;color:#fff;padding:.7rem 1.5rem;border-radius:12px;font-size:.88rem;font-weight:600;' +
            'opacity:0;transition:all .3s ease;z-index:9999;pointer-events:none;font-family:var(--font)';
        document.body.appendChild(toast);
    }
    toast.textContent = message;
    toast.style.opacity = '1';
    toast.style.transform = 'translateX(-50%) translateY(0)';
    clearTimeout(toast._timer);
    toast._timer = setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateX(-50%) translateY(20px)';
    }, duration);
}
