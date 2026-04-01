/**
 * Ferreira & Sá Hub — Helpers JS Globais
 * Máscara R$, busca CEP (ViaCEP), busca CPF (base interna)
 */

// ═══════════════════════════════════════
// MÁSCARA MONETÁRIA (R$)
// ═══════════════════════════════════════
function formatarReais(valor) {
    var v = valor.replace(/\D/g, '');
    if (v === '') return '';
    v = (parseInt(v, 10) / 100).toFixed(2);
    v = v.replace('.', ',');
    v = v.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    return v;
}

// Aplicar em inputs com class="input-reais"
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.input-reais').forEach(function(el) {
        el.addEventListener('input', function() {
            var pos = this.selectionStart;
            this.value = formatarReais(this.value);
        });
        // Formatar valor inicial se tiver
        if (el.value && !el.value.match(/\./)) {
            el.value = formatarReais(el.value);
        }
    });
});

// ═══════════════════════════════════════
// BUSCA CEP (ViaCEP gratuita)
// ═══════════════════════════════════════
function buscarCEP(cepInput, opts) {
    var cep = cepInput.value.replace(/\D/g, '');
    if (cep.length !== 8) return;

    var loading = cepInput.nextElementSibling;
    if (loading && loading.classList.contains('cep-loading')) loading.style.display = 'inline';

    var xhr = new XMLHttpRequest();
    xhr.open('GET', 'https://viacep.com.br/ws/' + cep + '/json/');
    xhr.timeout = 5000;
    xhr.onload = function() {
        if (loading) loading.style.display = 'none';
        try {
            var data = JSON.parse(xhr.responseText);
            if (data.erro) return;
            if (opts.endereco) { var el = document.querySelector(opts.endereco); if (el) el.value = data.logradouro + (data.complemento ? ', ' + data.complemento : '') + ', ' + data.bairro; }
            if (opts.cidade) { var el = document.querySelector(opts.cidade); if (el) el.value = data.localidade; }
            if (opts.uf) { var el = document.querySelector(opts.uf); if (el) { el.value = data.uf; if (typeof el.onchange === 'function') el.onchange(); } }
            // Highlight preenchido
            [opts.endereco, opts.cidade, opts.uf].forEach(function(sel) {
                if (sel) { var el = document.querySelector(sel); if (el) { el.style.borderColor = '#059669'; setTimeout(function(){ el.style.borderColor = ''; }, 2000); } }
            });
        } catch(e) {}
    };
    xhr.onerror = function() { if (loading) loading.style.display = 'none'; };
    xhr.ontimeout = function() { if (loading) loading.style.display = 'none'; };
    xhr.send();
}

// ═══════════════════════════════════════
// MÁSCARA CEP (00000-000)
// ═══════════════════════════════════════
function formatarCEP(el) {
    var v = el.value.replace(/\D/g, '');
    if (v.length > 5) v = v.substring(0,5) + '-' + v.substring(5,8);
    el.value = v;
}

// ═══════════════════════════════════════
// MÁSCARA CPF/CNPJ
// ═══════════════════════════════════════
function formatarCpfCnpj(el) {
    var v = el.value.replace(/\D/g, '');
    if (v.length <= 11) {
        v = v.replace(/(\d{3})(\d)/, '$1.$2');
        v = v.replace(/(\d{3})(\d)/, '$1.$2');
        v = v.replace(/(\d{3})(\d{1,2})$/, '$1-$2');
    } else {
        v = v.replace(/^(\d{2})(\d)/, '$1.$2');
        v = v.replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3');
        v = v.replace(/\.(\d{3})(\d)/, '.$1/$2');
        v = v.replace(/(\d{4})(\d{1,2})$/, '$1-$2');
    }
    el.value = v;
}

// ═══════════════════════════════════════
// BUSCA CPF — ReceitaWS (CNPJ) + Base interna (CPF)
// ═══════════════════════════════════════
function buscarCPF(cpfInput, opts) {
    var doc = cpfInput.value.replace(/\D/g, '');
    var nomeEl = opts.nome ? document.querySelector(opts.nome) : null;

    // Se nome já preenchido, não buscar
    if (nomeEl && nomeEl.value.trim() !== '') return;

    if (doc.length === 14) {
        // CNPJ — ReceitaWS
        var xhr = new XMLHttpRequest();
        xhr.open('GET', 'https://www.receitaws.com.br/v1/cnpj/' + doc);
        xhr.timeout = 8000;
        xhr.onload = function() {
            try {
                var data = JSON.parse(xhr.responseText);
                if (data.nome && nomeEl) {
                    nomeEl.value = data.nome;
                    nomeEl.style.borderColor = '#059669';
                    setTimeout(function(){ nomeEl.style.borderColor = ''; }, 2000);
                }
            } catch(e) {}
        };
        xhr.send();
    } else if (doc.length === 11 && opts.searchUrl) {
        // CPF — base interna
        var cpfFormatado = doc.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4');
        var xhr2 = new XMLHttpRequest();
        xhr2.open('GET', opts.searchUrl + '?q=' + encodeURIComponent(cpfFormatado));
        xhr2.onload = function() {
            try {
                var clientes = JSON.parse(xhr2.responseText);
                if (clientes.length > 0 && nomeEl) {
                    nomeEl.value = clientes[0].name;
                    nomeEl.style.borderColor = '#059669';
                    setTimeout(function(){ nomeEl.style.borderColor = ''; }, 2000);
                }
            } catch(e) {}
        };
        xhr2.send();
    }
}

// ═══════════════════════════════════════
// MÁSCARA TELEFONE (00) 00000-0000
// ═══════════════════════════════════════
function formatarTelefone(el) {
    var v = el.value.replace(/\D/g, '');
    if (v.length > 11) v = v.substring(0, 11);
    if (v.length > 6) v = '(' + v.substring(0,2) + ') ' + v.substring(2,7) + '-' + v.substring(7);
    else if (v.length > 2) v = '(' + v.substring(0,2) + ') ' + v.substring(2);
    el.value = v;
}
