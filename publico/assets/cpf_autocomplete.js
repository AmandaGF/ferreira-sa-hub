/**
 * cpf_autocomplete.js — mascara + consulta API + preenche nome (vermelho+CAIXA ALTA)
 *
 * Amanda 15/07/2026: padroniza CPF obrigatorio + auto-preenchimento em todos os
 * formularios enviados aos clientes. Nome preenchido pela API vem em VERMELHO
 * e MAIUSCULO pra pessoa notar e conferir se ta certo.
 *
 * Uso minimo:
 *   <input type="text" name="cpf" data-cpf-autocomplete required>
 *   <input type="text" name="nome" required>
 *
 * A lib procura os campos pela presenca dos atributos abaixo. Pode customizar
 * seletor do nome via data-cpf-target="#meuInputDeNome".
 *
 * Comportamento:
 * - Aplica mascara 000.000.000-00 no input.
 * - Ao completar 11 digitos, valida localmente e chama /conecta/publico/api_cpf.php.
 * - Se API voltou nome: preenche o campo alvo, aplica classe .fsa-cpf-preenchido
 *   (fundo vermelho claro + texto vermelho escuro + text-transform uppercase +
 *   font-weight 700) e insere um aviso "✓ Puxado da base — confira se está
 *   correto" abaixo do CPF.
 * - Se o usuario editar o nome depois, tira o estilo (perdeu o "vem da base").
 * - Callback opcional window.fsaCpfExtraFill(dados, inputCpf) — usa pra preencher
 *   outros campos (email, telefone, endereco). A lib nao mexe em outros campos
 *   pra evitar bagunca em cada form.
 */
(function () {
    if (window.__fsaCpfLoaded) return;
    window.__fsaCpfLoaded = true;

    // CSS injetado uma vez — tema em vermelho pra chamar atencao.
    var css = ''
        + '.fsa-cpf-preenchido {'
        + '  background:#fff1f1 !important;'
        + '  color:#b91c1c !important;'
        + '  text-transform:uppercase !important;'
        + '  font-weight:700 !important;'
        + '  border:2px solid #b91c1c !important;'
        + '  letter-spacing:.02em !important;'
        + '}'
        + '.fsa-cpf-status {'
        + '  display:block;margin-top:6px;font-size:12px;font-weight:600;'
        + '  min-height:16px;line-height:1.2;'
        + '}'
        + '.fsa-cpf-status.buscando { color:#6b7280; }'
        + '.fsa-cpf-status.ok       { color:#059669; }'
        + '.fsa-cpf-status.warn     { color:#b45309; }'
        + '.fsa-cpf-status.err      { color:#b91c1c; }'
        + '.fsa-cpf-conferir {'
        + '  display:block;margin-top:4px;font-size:12px;font-weight:700;'
        + '  color:#b91c1c;background:#fff1f1;border-left:3px solid #b91c1c;'
        + '  padding:6px 10px;border-radius:4px;'
        + '}';
    try {
        var st = document.createElement('style');
        st.textContent = css;
        document.head.appendChild(st);
    } catch (e) {}

    function mask(v) {
        v = (v || '').replace(/\D/g, '').slice(0, 11);
        if (v.length > 9)  return v.replace(/(\d{3})(\d{3})(\d{3})(\d{1,2})/, '$1.$2.$3-$4');
        if (v.length > 6)  return v.replace(/(\d{3})(\d{3})(\d{0,3})/, '$1.$2.$3');
        if (v.length > 3)  return v.replace(/(\d{3})(\d{0,3})/, '$1.$2');
        return v;
    }

    function validaCpf(cpf) {
        cpf = (cpf || '').replace(/\D/g, '');
        if (cpf.length !== 11) return false;
        if (/^(\d)\1{10}$/.test(cpf)) return false;
        for (var t = 9; t < 11; t++) {
            for (var d = 0, c = 0; c < t; c++) d += parseInt(cpf[c], 10) * ((t + 1) - c);
            d = ((10 * d) % 11) % 10;
            if (parseInt(cpf[c], 10) !== d) return false;
        }
        return true;
    }

    function acharAlvoNome(cpfEl) {
        // data-cpf-target > name="nome" > name="nome_completo" > name="name"
        var sel = cpfEl.getAttribute('data-cpf-target');
        if (sel) { var t = document.querySelector(sel); if (t) return t; }
        var form = cpfEl.form || document;
        return form.querySelector('[name="nome"]')
            || form.querySelector('[name="nome_completo"]')
            || form.querySelector('[name="name"]');
    }

    function statusEl(cpfEl) {
        var wrapper = cpfEl.parentElement;
        if (!wrapper) return null;
        var st = wrapper.querySelector('.fsa-cpf-status');
        if (st) return st;
        st = document.createElement('span');
        st.className = 'fsa-cpf-status';
        cpfEl.insertAdjacentElement('afterend', st);
        return st;
    }

    function setStatus(cpfEl, tipo, txt) {
        var st = statusEl(cpfEl);
        if (!st) return;
        st.className = 'fsa-cpf-status' + (tipo ? ' ' + tipo : '');
        st.textContent = txt || '';
    }

    function avisoConferir(nomeEl, msg) {
        if (!nomeEl || !nomeEl.parentElement) return;
        // Remove aviso anterior
        var old = nomeEl.parentElement.querySelector('.fsa-cpf-conferir');
        if (old) old.remove();
        if (!msg) return;
        var a = document.createElement('div');
        a.className = 'fsa-cpf-conferir';
        a.textContent = msg;
        nomeEl.insertAdjacentElement('afterend', a);
    }

    function marcarNomePreenchido(nomeEl, nome) {
        if (!nomeEl) return;
        nomeEl.value = String(nome || '').toUpperCase();
        nomeEl.classList.add('fsa-cpf-preenchido');
        // Se o usuario editar, tira o estilo (nao veio mais da base)
        var off = function () {
            nomeEl.classList.remove('fsa-cpf-preenchido');
            avisoConferir(nomeEl, null);
            nomeEl.removeEventListener('input', off);
        };
        nomeEl.addEventListener('input', off);
    }

    function consultar(cpfEl, cpf11) {
        setStatus(cpfEl, 'buscando', 'Buscando dados oficiais…');
        var xhr = new XMLHttpRequest();
        xhr.open('GET', '/conecta/publico/api_cpf.php?cpf=' + cpf11, true);
        xhr.timeout = 12000;
        xhr.onload = function () {
            try {
                var r = JSON.parse(xhr.responseText);
                var d = r && r.dados ? r.dados : null;
                if (r && r.erro) { setStatus(cpfEl, 'err', '✗ ' + r.erro); return; }
                if (!d || !d.nome) {
                    setStatus(cpfEl, 'warn', '⚠ Nome não encontrado na base — preencha manualmente.');
                    return;
                }
                var alvo = acharAlvoNome(cpfEl);
                if (alvo) {
                    marcarNomePreenchido(alvo, d.nome);
                    avisoConferir(alvo, '✓ Preenchido pela base oficial — confira se é você mesmo.');
                }
                if (r.fonte === 'portal') {
                    setStatus(cpfEl, 'warn', '⚠ CPF já cadastrado na base — se é você, continue normalmente.');
                } else {
                    setStatus(cpfEl, 'ok', '✓ Dados oficiais encontrados.');
                }
                // Callback pra o form preencher outros campos se quiser
                if (typeof window.fsaCpfExtraFill === 'function') {
                    try { window.fsaCpfExtraFill(d, cpfEl); } catch (e) {}
                }
            } catch (e) {
                setStatus(cpfEl, 'err', '✗ Erro ao ler resposta.');
            }
        };
        xhr.ontimeout = function () { setStatus(cpfEl, 'err', '✗ Timeout — tenta de novo.'); };
        xhr.onerror   = function () { setStatus(cpfEl, 'err', '✗ Sem conexão.'); };
        xhr.send();
    }

    function attach(cpfEl) {
        if (!cpfEl || cpfEl.__fsaCpf) return;
        cpfEl.__fsaCpf = true;
        // Marca como obrigatorio sempre
        cpfEl.setAttribute('required', 'required');
        cpfEl.setAttribute('aria-required', 'true');
        cpfEl.setAttribute('inputmode', 'numeric');
        cpfEl.setAttribute('autocomplete', 'off');
        cpfEl.setAttribute('maxlength', '14');
        if (!cpfEl.placeholder) cpfEl.placeholder = '000.000.000-00';

        cpfEl.addEventListener('input', function () {
            var raw = (this.value || '').replace(/\D/g, '').slice(0, 11);
            this.value = mask(raw);
            if (raw.length === 11) {
                if (!validaCpf(raw)) { setStatus(cpfEl, 'err', '✗ CPF inválido — confira os dígitos.'); return; }
                clearTimeout(cpfEl.__t);
                cpfEl.__t = setTimeout(function () { consultar(cpfEl, raw); }, 350);
            } else {
                setStatus(cpfEl, '', '');
            }
        });
        cpfEl.addEventListener('blur', function () {
            var raw = (this.value || '').replace(/\D/g, '');
            if (raw.length === 11 && validaCpf(raw)) consultar(cpfEl, raw);
        });
    }

    function init() {
        document.querySelectorAll('[data-cpf-autocomplete]').forEach(attach);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Expor pra uso manual (caso o campo apareca dinamicamente)
    window.fsaCpfAttach = attach;
})();
