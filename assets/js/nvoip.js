/**
 * Ferreira & Sá Hub — Widget de chamada Nvoip (VoIP)
 *
 * Fluxo:
 *   iniciarLigacao(tel, clientId, leadId, caseId) → mostra widget + POST
 *   realizar_chamada → polling 2s consultar_chamada → encerra e fecha widget.
 *   Timeout de 5 min encerra automaticamente se não finalizar.
 */
(function(){
    if (window.NvoipWidget) return; // já carregado
    window.NvoipWidget = true;

    var NVOIP_API = (window.FSA_URL_BASE || '/conecta') + '/api/nvoip_api.php';
    var MAX_SEGUNDOS = 300; // 5 min — corta polling

    var state = {
        callId: null, pollTimer: null, tickTimer: null, segundos: 0,
        ultStatus: null, clientId: null, caseId: null, leadId: null, nome: ''
    };

    function $(id) { return document.getElementById(id); }

    function criarWidget() {
        if ($('nvoipWidget')) return;
        var w = document.createElement('div');
        w.id = 'nvoipWidget';
        w.setAttribute('hidden', '');
        w.innerHTML =
            '<div class="nv-avatar">📞</div>' +
            '<div class="nv-info">' +
                '<div class="nv-nome" id="nvoipNome">—</div>' +
                '<div class="nv-tel" id="nvoipTel">—</div>' +
                '<div class="nv-status" id="nvoipStatus">Conectando...</div>' +
                '<div class="nv-timer" id="nvoipTimer">00:00</div>' +
            '</div>' +
            '<button class="nv-desligar" onclick="window.nvoipEncerrar()" title="Desligar" aria-label="Desligar">📵</button>';
        document.body.appendChild(w);
    }

    function csrf() {
        return (window._FSA_CSRF || window.FSA_CSRF || '');
    }

    function mostrar(nome, tel) {
        criarWidget();
        state.nome = nome || '';
        $('nvoipNome').textContent = nome || 'Cliente';
        $('nvoipTel').textContent = tel || '';
        $('nvoipStatus').textContent = '⏳ Conectando...';
        $('nvoipTimer').textContent = '00:00';
        $('nvoipWidget').removeAttribute('hidden');
    }

    function esconder() {
        var el = $('nvoipWidget'); if (el) el.setAttribute('hidden', '');
        if (state.pollTimer) { clearInterval(state.pollTimer); state.pollTimer = null; }
        if (state.tickTimer) { clearInterval(state.tickTimer); state.tickTimer = null; }
        state.callId = null; state.segundos = 0; state.ultStatus = null;
    }

    function fmtTimer(s) {
        var m = Math.floor(s / 60), ss = s % 60;
        return (m < 10 ? '0' : '') + m + ':' + (ss < 10 ? '0' : '') + ss;
    }

    function atualizarStatus(s) {
        var el = $('nvoipStatus'); if (!el) return;
        if (s === 'calling')     el.textContent = '⏳ Chamando...';
        else if (s === 'established') el.textContent = '🟢 Em ligação';
        else if (s === 'finished') el.textContent = '✓ Encerrada';
        else if (s === 'noanswer') el.textContent = '📞 Não atendeu';
        else if (s === 'busy')     el.textContent = '📵 Ocupado';
        else if (s === 'failed')   el.textContent = '❌ Falhou';
        else el.textContent = s;
    }

    function tick() {
        state.segundos++;
        var t = $('nvoipTimer'); if (t) t.textContent = fmtTimer(state.segundos);
        if (state.segundos >= MAX_SEGUNDOS) {
            atualizarStatus('finished');
            nvoipEncerrar(true);
        }
    }

    function polling() {
        if (!state.callId) return;
        var url = NVOIP_API + '?action=consultar_chamada&call_id=' + encodeURIComponent(state.callId);
        fetch(url, { credentials: 'same-origin' })
            .then(function(r){ return r.json(); })
            .then(function(d){
                if (!d || d.error) return;
                var s = d.state || state.ultStatus || 'calling';
                atualizarStatus(s);
                state.ultStatus = s;
                if (s === 'finished' || s === 'failed' || s === 'noanswer' || s === 'busy') {
                    if (state.pollTimer) clearInterval(state.pollTimer);
                    if (state.tickTimer) clearInterval(state.tickTimer);
                    setTimeout(esconder, 3000);
                }
            })
            .catch(function(){});
    }

    window.nvoipIniciar = function(tel, clientId, leadId, caseId, nomeContato) {
        mostrar(nomeContato || '', tel || '');
        state.clientId = clientId || null;
        state.leadId   = leadId   || null;
        state.caseId   = caseId   || null;

        var fd = new FormData();
        fd.append('action', 'realizar_chamada');
        fd.append('telefone', tel);
        fd.append('csrf_token', csrf());
        if (clientId) fd.append('client_id', clientId);
        if (leadId)   fd.append('lead_id', leadId);
        if (caseId)   fd.append('case_id', caseId);

        fetch(NVOIP_API, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function(r){ return r.json(); })
            .then(function(d){
                if (d && d.error) { alert('Não consegui iniciar a ligação: ' + d.error); esconder(); return; }
                if (!d || !d.callId) { alert('Resposta inválida da Nvoip.'); esconder(); return; }
                state.callId = d.callId;
                state.segundos = 0;
                state.tickTimer = setInterval(tick, 1000);
                state.pollTimer = setInterval(polling, 2000);
                polling(); // primeira consulta imediata
            })
            .catch(function(e){
                alert('Falha de rede ao ligar: ' + e);
                esconder();
            });
    };

    window.nvoipEncerrar = function(silent) {
        if (!state.callId) { esconder(); return; }
        var fd = new FormData();
        fd.append('action', 'encerrar_chamada');
        fd.append('call_id', state.callId);
        fd.append('csrf_token', csrf());
        fetch(NVOIP_API, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function(){ if (!silent) atualizarStatus('finished'); setTimeout(esconder, 1500); })
            .catch(function(){ esconder(); });
    };
})();
