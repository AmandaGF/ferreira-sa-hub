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
    } else if (doc.length === 11) {
        // CPF — 1º base interna, 2º API externa
        var cpfFormatado = doc.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4');
        var found = false;

        function tryExternalCPF() {
            var xhr3 = new XMLHttpRequest();
            xhr3.open('GET', '/conecta/publico/api_cpf.php?cpf=' + doc);
            xhr3.timeout = 10000;
            xhr3.onload = function() {
                try {
                    var data = JSON.parse(xhr3.responseText);
                    if (data.status === 'OK' && data.nome && nomeEl) {
                        nomeEl.value = data.nome;
                        nomeEl.style.borderColor = '#059669';
                        setTimeout(function(){ nomeEl.style.borderColor = ''; }, 2000);
                    }
                } catch(e) {}
            };
            xhr3.send();
        }

        if (opts.searchUrl) {
            var xhr2 = new XMLHttpRequest();
            xhr2.open('GET', opts.searchUrl + '?q=' + encodeURIComponent(cpfFormatado));
            xhr2.onload = function() {
                try {
                    var clientes = JSON.parse(xhr2.responseText);
                    if (clientes.length > 0 && nomeEl) {
                        nomeEl.value = clientes[0].name;
                        nomeEl.style.borderColor = '#059669';
                        setTimeout(function(){ nomeEl.style.borderColor = ''; }, 2000);
                        found = true;
                    }
                } catch(e) {}
                if (!found) tryExternalCPF();
            };
            xhr2.onerror = function() { tryExternalCPF(); };
            xhr2.send();
        } else {
            tryExternalCPF();
        }
    }
}

// ═══════════════════════════════════════
// MÁSCARA NÚMERO DE PROCESSO CNJ
// Padrão: NNNNNNN-DD.AAAA.J.TR.OOOO
// ═══════════════════════════════════════
function formatarCNJ(el) {
    var v = el.value.replace(/\D/g, '');
    if (v.length > 20) v = v.substring(0, 20);
    // NNNNNNN-DD.AAAA.J.TR.OOOO
    if (v.length > 13) v = v.substring(0,7) + '-' + v.substring(7,9) + '.' + v.substring(9,13) + '.' + v.substring(13,14) + '.' + v.substring(14,16) + '.' + v.substring(16,20);
    else if (v.length > 9) v = v.substring(0,7) + '-' + v.substring(7,9) + '.' + v.substring(9);
    else if (v.length > 7) v = v.substring(0,7) + '-' + v.substring(7);
    el.value = v;
}

// Auto-aplicar máscara CNJ em todos os inputs de nº processo
document.addEventListener('DOMContentLoaded', function() {
    // Selecionar por: data-mask="cnj", name contendo case_number, placeholder com padrão CNJ
    var seletores = 'input[data-mask="cnj"], input[name="case_number"], input[name="proc_numero"], input[data-field="case_number"], input[name="numero_processo"]';
    document.querySelectorAll(seletores).forEach(function(el) {
        el.addEventListener('input', function() { formatarCNJ(el); });
        el.setAttribute('placeholder', '0000000-00.0000.0.00.0000');
        el.setAttribute('maxlength', '25');
    });

    // Inputs com placeholder que já indica CNJ
    document.querySelectorAll('input[placeholder*="0000000-00"]').forEach(function(el) {
        if (!el.dataset.mask) {
            el.addEventListener('input', function() { formatarCNJ(el); });
            el.setAttribute('maxlength', '25');
        }
    });

    // Inputs do modal de distribuição (procNumero)
    var procNumero = document.getElementById('procNumero');
    if (procNumero) {
        procNumero.addEventListener('input', function() { formatarCNJ(procNumero); });
        procNumero.setAttribute('maxlength', '25');
    }
    var distConfirmNumero = document.getElementById('distConfirmNumero');
    if (distConfirmNumero) {
        distConfirmNumero.addEventListener('input', function() { formatarCNJ(distConfirmNumero); });
        distConfirmNumero.setAttribute('maxlength', '25');
    }
});

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
