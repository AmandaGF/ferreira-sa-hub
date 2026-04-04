/**
 * Ferreira & Sá Conecta — Busca CPF/CNPJ Global
 *
 * Uso: adicionar data-busca-doc ao campo CPF/CNPJ
 *   <input data-busca-doc data-nome="[name=nome]" ...>
 *
 * Atributos opcionais (seletores CSS para preencher campos próximos):
 *   data-nome         → campo nome
 *   data-nascimento   → campo nascimento
 *   data-email        → campo email
 *   data-telefone     → campo telefone
 *   data-endereco     → campo endereço
 *   data-cidade       → campo cidade
 *   data-uf           → campo UF
 *   data-cep          → campo CEP
 *   data-rg           → campo RG
 *   data-profissao    → campo profissão
 *   data-estado-civil → campo estado civil
 *   data-razao        → campo razão social (CNPJ)
 *   data-representante→ campo representante (CNPJ)
 *
 * Se data-nome não for definido, tenta encontrar automaticamente
 * o campo "nome" mais próximo na mesma row/section.
 */

(function() {
    'use strict';

    var BASE = (typeof _appBase !== 'undefined') ? _appBase : '/conecta';

    // ─── Máscara CPF/CNPJ ──────────────────────────────

    function mascaraCpfCnpj(el) {
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

    // ─── Buscar documento ──────────────────────────────

    function buscarDocumento(campo) {
        var doc = campo.value.replace(/\D/g, '');
        if (doc.length !== 11 && doc.length !== 14) return;

        // Encontrar campo nome
        var nomeEl = resolverCampo(campo, 'nome');
        if (nomeEl && nomeEl.value.trim() !== '') return; // já preenchido

        // Spinner
        var spinner = criarSpinner(campo);

        var xhr = new XMLHttpRequest();
        xhr.open('GET', BASE + '/api/buscar_documento.php?doc=' + doc);
        xhr.timeout = 12000;

        xhr.onload = function() {
            removerSpinner(spinner);
            try {
                var r = JSON.parse(xhr.responseText);
                if (r.erro) {
                    mostrarFeedback(campo, r.erro, 'warning');
                    return;
                }
                var d = r.dados;
                var isCnpj = doc.length === 14;

                // Preencher campos
                if (isCnpj) {
                    setVal(campo, 'nome', d.razao_social || d.nome_fantasia);
                    setVal(campo, 'razao', d.razao_social);
                    setVal(campo, 'endereco', montarEnderecoCnpj(d));
                    setVal(campo, 'cidade', d.municipio);
                    setVal(campo, 'uf', d.uf);
                    setVal(campo, 'cep', d.cep);
                    setVal(campo, 'email', d.email);
                    setVal(campo, 'telefone', d.telefone);
                    setVal(campo, 'representante', d.representante);
                } else {
                    setVal(campo, 'nome', d.nome);
                    setVal(campo, 'nascimento', d.nascimento);
                    setVal(campo, 'email', d.email);
                    setVal(campo, 'telefone', d.telefone);
                    setVal(campo, 'endereco', d.endereco);
                    setVal(campo, 'cidade', d.cidade);
                    setVal(campo, 'uf', d.uf);
                    setVal(campo, 'cep', d.cep);
                    setVal(campo, 'rg', d.rg);
                    setVal(campo, 'profissao', d.profissao);
                    setVal(campo, 'estado-civil', d.estado_civil);
                }

                mostrarFeedback(campo, 'Dados encontrados (' + r.fonte + ')', 'success');
            } catch(e) {
                mostrarFeedback(campo, 'Erro na busca', 'warning');
            }
        };

        xhr.onerror = function() {
            removerSpinner(spinner);
            mostrarFeedback(campo, 'Erro de conexão', 'warning');
        };

        xhr.ontimeout = function() {
            removerSpinner(spinner);
            mostrarFeedback(campo, 'Tempo esgotado', 'warning');
        };

        xhr.send();
    }

    // ─── Helpers ────────────────────────────────────────

    function montarEnderecoCnpj(d) {
        var parts = [];
        if (d.logradouro) {
            var end = d.logradouro;
            if (d.numero) end += ', ' + d.numero;
            parts.push(end);
        }
        if (d.bairro) parts.push(d.bairro);
        return parts.join(' — ');
    }

    function resolverCampo(campo, tipo) {
        // 1. Atributo explícito data-[tipo]="[seletor]"
        var seletor = campo.getAttribute('data-' + tipo);
        if (seletor) {
            // Tentar no formulário pai primeiro
            var form = campo.closest('form') || campo.closest('.parte-row') || document;
            var el = form.querySelector(seletor);
            if (el) return el;
            return document.querySelector(seletor);
        }

        // 2. Busca automática: campo mais próximo na mesma row
        var row = campo.closest('.parte-row') || campo.closest('.form-row') || campo.closest('.row') || campo.closest('div');
        if (row && tipo === 'nome') {
            var nomeFields = row.querySelectorAll('input[name*="nome"], input[name*="name"], input[name*="razao"]');
            for (var i = 0; i < nomeFields.length; i++) {
                if (nomeFields[i] !== campo) return nomeFields[i];
            }
        }
        return null;
    }

    function setVal(campo, tipo, valor) {
        if (!valor) return;
        var el = resolverCampo(campo, tipo);
        if (!el) return;
        if (el.value && el.value.trim() !== '' && el.value !== '—') return; // não sobrescrever
        el.value = valor;
        el.style.borderColor = '#059669';
        setTimeout(function() { el.style.borderColor = ''; }, 2500);
    }

    function criarSpinner(campo) {
        var span = document.createElement('span');
        span.className = '_doc-spinner';
        span.style.cssText = 'font-size:.68rem;color:#d97706;margin-left:4px;';
        span.textContent = 'Buscando...';
        campo.parentNode.appendChild(span);
        return span;
    }

    function removerSpinner(span) {
        if (span && span.parentNode) span.parentNode.removeChild(span);
    }

    function mostrarFeedback(campo, msg, tipo) {
        var existing = campo.parentNode.querySelector('._doc-feedback');
        if (existing) existing.parentNode.removeChild(existing);

        var span = document.createElement('span');
        span.className = '_doc-feedback';
        var cor = tipo === 'success' ? '#059669' : '#d97706';
        span.style.cssText = 'font-size:.65rem;color:' + cor + ';display:block;margin-top:2px;';
        span.textContent = tipo === 'success' ? '✓ ' + msg : '⚠ ' + msg;
        campo.parentNode.appendChild(span);
        setTimeout(function() {
            if (span.parentNode) span.parentNode.removeChild(span);
        }, 4000);
    }

    // ─── Auto-vincular campos ──────────────────────────

    function vincularCampos() {
        var campos = document.querySelectorAll('[data-busca-doc]');
        campos.forEach(function(campo) {
            if (campo._buscaDocVinculado) return;
            campo._buscaDocVinculado = true;

            // Máscara ao digitar
            campo.addEventListener('input', function() { mascaraCpfCnpj(this); });

            // Buscar ao sair do campo
            campo.addEventListener('blur', function() { buscarDocumento(this); });
        });
    }

    // Vincular ao carregar
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', vincularCampos);
    } else {
        vincularCampos();
    }

    // Observer para campos adicionados dinamicamente (ex: "+ Adicionar Parte")
    var observer = new MutationObserver(function() { vincularCampos(); });
    observer.observe(document.body, { childList: true, subtree: true });

    // Exportar para uso manual
    window.buscarDocumento = function(valor, callback) {
        var doc = valor.replace(/\D/g, '');
        if (doc.length !== 11 && doc.length !== 14) return;

        var xhr = new XMLHttpRequest();
        xhr.open('GET', BASE + '/api/buscar_documento.php?doc=' + doc);
        xhr.timeout = 12000;
        xhr.onload = function() {
            try {
                var r = JSON.parse(xhr.responseText);
                if (callback) callback(r);
            } catch(e) {}
        };
        xhr.send();
    };
})();
