/**
 * Busca de cidades por UF via API IBGE
 * Uso: ibgeCidades(selectUfId, inputCidadeId, datalistId)
 * Ex: ibgeCidades('comarcaUf', 'comarcaCidade', 'listaCidades')
 */
var _ibgeCidadesCache = {};
function ibgeCidades(ufSelectId, cidadeInputId, datalistId) {
    var selUf = document.getElementById(ufSelectId);
    var inputCidade = document.getElementById(cidadeInputId);
    var datalist = document.getElementById(datalistId);
    if (!selUf || !inputCidade || !datalist) return;

    selUf.addEventListener('change', function() {
        var uf = selUf.value;
        datalist.innerHTML = '';
        if (!uf) return;

        if (_ibgeCidadesCache[uf]) {
            _ibgePopular(datalist, _ibgeCidadesCache[uf]);
            return;
        }

        var xhr = new XMLHttpRequest();
        xhr.open('GET', 'https://servicodados.ibge.gov.br/api/v1/localidades/estados/' + uf + '/municipios?orderBy=nome');
        xhr.onload = function() {
            try {
                var cidades = JSON.parse(xhr.responseText);
                var nomes = [];
                for (var i = 0; i < cidades.length; i++) nomes.push(cidades[i].nome);
                _ibgeCidadesCache[uf] = nomes;
                _ibgePopular(datalist, nomes);
            } catch(e) {}
        };
        xhr.send();
    });

    // Disparar se UF já tiver valor (pré-preenchido)
    if (selUf.value) {
        var evt = new Event('change');
        selUf.dispatchEvent(evt);
    }
}

function _ibgePopular(datalist, nomes) {
    datalist.innerHTML = '';
    for (var i = 0; i < nomes.length; i++) {
        var opt = document.createElement('option');
        opt.value = nomes[i];
        datalist.appendChild(opt);
    }
}
