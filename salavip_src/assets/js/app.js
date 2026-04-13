/**
 * Sala VIP — Frontend JavaScript
 * Ferreira & Sá Advocacia
 * Pure JS (no jQuery)
 */

// =========================================
// Theme Toggle (dark/light)
// =========================================
function toggleTheme() {
    var html = document.documentElement;
    var current = html.getAttribute('data-theme') || 'dark';
    var next = current === 'dark' ? 'light' : 'dark';
    html.setAttribute('data-theme', next);
    localStorage.setItem('salavip_theme', next);
    var btn = document.getElementById('themeToggle');
    if (btn) btn.textContent = next === 'dark' ? '\uD83C\uDF19' : '\u2600\uFE0F';
}

// Apply saved theme on load (default: light)
(function() {
    var saved = localStorage.getItem('salavip_theme');
    var theme = saved || 'light';
    document.documentElement.setAttribute('data-theme', theme);
    document.addEventListener('DOMContentLoaded', function() {
        var t = document.documentElement.getAttribute('data-theme') || 'light';
        var btn = document.getElementById('themeToggle');
        if (btn) btn.textContent = t === 'dark' ? '\uD83C\uDF19' : '\u2600\uFE0F';
    });
})();

// =========================================
// Profile Photo Preview + Auto Upload
// =========================================
function previewFoto(input) {
    if (input.files && input.files[0]) {
        var file = input.files[0];
        if (file.size > 5 * 1024 * 1024) {
            alert('Foto deve ter no m\u00e1ximo 5MB.');
            input.value = '';
            return;
        }
        if (!file.type.match(/^image\/(jpeg|jpg|png|webp)$/)) {
            alert('Formato aceito: JPG, PNG ou WebP.');
            input.value = '';
            return;
        }
        var reader = new FileReader();
        reader.onload = function(e) {
            var img = document.getElementById('fotoPreview');
            if (img) { img.src = e.target.result; img.style.display = 'block'; }
            var placeholder = document.getElementById('fotoPlaceholder');
            if (placeholder) placeholder.style.display = 'none';
            // Auto submit the form
            document.getElementById('formFoto').submit();
        };
        reader.readAsDataURL(file);
    }
}

document.addEventListener('DOMContentLoaded', function () {

    // =========================================
    // CPF Mask — formato 000.000.000-00
    // =========================================
    function cpfMask(value) {
        var digits = value.replace(/\D/g, '').substring(0, 11);
        if (digits.length <= 3) return digits;
        if (digits.length <= 6) return digits.slice(0, 3) + '.' + digits.slice(3);
        if (digits.length <= 9) return digits.slice(0, 3) + '.' + digits.slice(3, 6) + '.' + digits.slice(6);
        return digits.slice(0, 3) + '.' + digits.slice(3, 6) + '.' + digits.slice(6, 9) + '-' + digits.slice(9);
    }

    var cpfInputs = document.querySelectorAll('input[data-mask="cpf"]');
    cpfInputs.forEach(function (input) {
        input.addEventListener('input', function () {
            var pos = this.selectionStart;
            var oldLen = this.value.length;
            this.value = cpfMask(this.value);
            var newLen = this.value.length;
            this.setSelectionRange(pos + (newLen - oldLen), pos + (newLen - oldLen));
        });
    });

    // =========================================
    // Toggle Password Visibility
    // =========================================
    var toggleBtns = document.querySelectorAll('.toggle-password');
    toggleBtns.forEach(function (btn) {
        btn.addEventListener('click', function () {
            var input = this.parentElement.querySelector('input');
            if (!input) return;
            if (input.type === 'password') {
                input.type = 'text';
                this.textContent = '\uD83D\uDE48'; // 🙈
            } else {
                input.type = 'password';
                this.textContent = '\uD83D\uDC41'; // 👁
            }
        });
    });

    // =========================================
    // Login Form — Spinner + Submit
    // =========================================
    var loginForm = document.getElementById('login-form');
    if (loginForm) {
        loginForm.addEventListener('submit', function (e) {
            var btn = loginForm.querySelector('.btn-login');
            if (!btn || btn.disabled) {
                e.preventDefault();
                return;
            }
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner"></span> Entrando...';
            // form submits normally (no preventDefault)
        });
    }

    // =========================================
    // Password Strength Checker
    // =========================================
    var strengthInputs = document.querySelectorAll('input[data-strength]');
    strengthInputs.forEach(function (input) {
        var bar = input.parentElement.querySelector('.strength-bar');
        if (!bar) return;

        input.addEventListener('input', function () {
            var val = this.value;
            var fill = bar.querySelector('.strength-fill');
            if (!fill) return;

            // Remove previous classes
            bar.classList.remove('strength-weak', 'strength-medium', 'strength-strong');

            if (val.length === 0) {
                fill.style.width = '0';
                return;
            }

            var hasUpper = /[A-Z]/.test(val);
            var hasLower = /[a-z]/.test(val);
            var hasNumber = /[0-9]/.test(val);
            var hasSpecial = /[^A-Za-z0-9]/.test(val);

            if (val.length >= 8 && hasUpper && hasLower && hasNumber && hasSpecial) {
                bar.classList.add('strength-strong');
                fill.style.width = '100%';
            } else if (val.length >= 8 && (hasUpper || hasLower) && (hasNumber || hasSpecial)) {
                bar.classList.add('strength-medium');
                fill.style.width = '66%';
            } else {
                bar.classList.add('strength-weak');
                fill.style.width = '33%';
            }
        });
    });

    // =========================================
    // Mobile Menu Toggle
    // =========================================
    var hamburger = document.getElementById('sv-hamburger');
    var mobileMenu = document.querySelector('.sv-mobile-menu');
    var overlay = document.querySelector('.sv-overlay');

    function closeMobileMenu() {
        if (mobileMenu) mobileMenu.classList.remove('open');
        if (overlay) overlay.classList.remove('open');
    }

    if (hamburger) {
        hamburger.addEventListener('click', function () {
            if (mobileMenu) mobileMenu.classList.toggle('open');
            if (overlay) overlay.classList.toggle('open');
        });
    }

    if (overlay) {
        overlay.addEventListener('click', closeMobileMenu);
    }

    // =========================================
    // Auto-close Flash Messages (5s)
    // =========================================
    var flashMsgs = document.querySelectorAll('.error-msg, .success-msg');
    flashMsgs.forEach(function (msg) {
        setTimeout(function () {
            msg.style.transition = 'opacity .4s, max-height .4s';
            msg.style.opacity = '0';
            msg.style.maxHeight = '0';
            msg.style.overflow = 'hidden';
            msg.style.padding = '0';
            msg.style.marginBottom = '0';
            msg.style.borderWidth = '0';
            setTimeout(function () {
                msg.remove();
            }, 400);
        }, 5000);
    });

});
