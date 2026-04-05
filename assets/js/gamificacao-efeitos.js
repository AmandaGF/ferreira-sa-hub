/**
 * Ferreira & Sá — Gamificação: Efeitos Visuais + Sons
 * Confetes, fogos, level up, popup global, sons via Web Audio API
 * Carregado em TODAS as páginas via footer.php
 */
(function() {
'use strict';

var BASE = (typeof _appBase !== 'undefined') ? _appBase : '/conecta';
var _lastEventCheck = Date.now();

// ══════════════════════════════════════
// SONS (Web Audio API — sem arquivos)
// ══════════════════════════════════════
var AudioCtx = window.AudioContext || window.webkitAudioContext;
var _ctx = null;

function getCtx() {
    if (!_ctx) _ctx = new AudioCtx();
    return _ctx;
}

var sons = {
    moedas: function() {
        var ac = getCtx();
        var freqs = [523,659,784,1047];
        freqs.forEach(function(freq, i) {
            var osc = ac.createOscillator();
            var gain = ac.createGain();
            osc.connect(gain); gain.connect(ac.destination);
            osc.frequency.value = freq;
            osc.type = 'sine';
            gain.gain.setValueAtTime(0.25, ac.currentTime + i*0.08);
            gain.gain.exponentialRampToValueAtTime(0.001, ac.currentTime + i*0.08 + 0.15);
            osc.start(ac.currentTime + i*0.08);
            osc.stop(ac.currentTime + i*0.08 + 0.16);
        });
    },
    fanfarra: function() {
        var ac = getCtx();
        var notas = [523,523,523,659,523,659,784];
        var tempos = [0,.15,.3,.45,.6,.75,.9];
        notas.forEach(function(freq, i) {
            var osc = ac.createOscillator();
            var gain = ac.createGain();
            osc.connect(gain); gain.connect(ac.destination);
            osc.frequency.value = freq;
            osc.type = 'square';
            gain.gain.setValueAtTime(0.15, ac.currentTime + tempos[i]);
            gain.gain.exponentialRampToValueAtTime(0.001, ac.currentTime + tempos[i] + 0.12);
            osc.start(ac.currentTime + tempos[i]);
            osc.stop(ac.currentTime + tempos[i] + 0.13);
        });
    },
    levelup: function() {
        var ac = getCtx();
        var notas = [523,659,784,1047,1319];
        notas.forEach(function(freq, i) {
            var osc = ac.createOscillator();
            var gain = ac.createGain();
            osc.connect(gain); gain.connect(ac.destination);
            osc.frequency.value = freq;
            osc.type = 'sine';
            gain.gain.setValueAtTime(0.2, ac.currentTime + i*0.1);
            gain.gain.exponentialRampToValueAtTime(0.001, ac.currentTime + i*0.1 + 0.3);
            osc.start(ac.currentTime + i*0.1);
            osc.stop(ac.currentTime + i*0.1 + 0.31);
        });
    },
    aplauso: function() {
        var ac = getCtx();
        var buffer = ac.createBuffer(1, ac.sampleRate * 1.2, ac.sampleRate);
        var data = buffer.getChannelData(0);
        for (var i = 0; i < data.length; i++) data[i] = Math.random() * 2 - 1;
        var source = ac.createBufferSource();
        var filter = ac.createBiquadFilter();
        var gain = ac.createGain();
        filter.type = 'bandpass'; filter.frequency.value = 1200; filter.Q.value = 0.5;
        source.buffer = buffer;
        source.connect(filter); filter.connect(gain); gain.connect(ac.destination);
        gain.gain.setValueAtTime(0.12, ac.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.001, ac.currentTime + 1.2);
        source.start();
    }
};

// Ativar contexto de áudio no primeiro clique
document.addEventListener('click', function() { getCtx(); }, { once: true });

// ══════════════════════════════════════
// CONFETES (contrato fechado)
// ══════════════════════════════════════
function dispararConfetes() {
    var canvas = document.createElement('canvas');
    canvas.style.cssText = 'position:fixed;inset:0;pointer-events:none;z-index:9998;';
    canvas.width = window.innerWidth;
    canvas.height = window.innerHeight;
    document.body.appendChild(canvas);
    var ctx2 = canvas.getContext('2d');

    var cores = ['#B87333','#FFD700','#FFFFFF','#052228','#C8873A','#E8C94A'];
    var particulas = [];
    for (var i = 0; i < 120; i++) {
        particulas.push({
            x: Math.random() * canvas.width,
            y: Math.random() * canvas.height * 0.3 - canvas.height * 0.1,
            vx: (Math.random()-0.5) * 8,
            vy: Math.random() * 3 + 1,
            rot: Math.random() * 360,
            rotV: (Math.random()-0.5) * 10,
            cor: cores[Math.floor(Math.random()*cores.length)],
            w: Math.random() * 8 + 3,
            h: Math.random() * 4 + 2,
            life: 1
        });
    }

    var start = Date.now();
    function frame() {
        var elapsed = Date.now() - start;
        if (elapsed > 3500) { canvas.remove(); return; }
        ctx2.clearRect(0, 0, canvas.width, canvas.height);
        particulas.forEach(function(p) {
            p.x += p.vx;
            p.vy += 0.12;
            p.y += p.vy;
            p.rot += p.rotV;
            p.life = Math.max(0, 1 - elapsed/3500);
            ctx2.save();
            ctx2.translate(p.x, p.y);
            ctx2.rotate(p.rot * Math.PI / 180);
            ctx2.globalAlpha = p.life;
            ctx2.fillStyle = p.cor;
            ctx2.fillRect(-p.w/2, -p.h/2, p.w, p.h);
            ctx2.restore();
        });
        requestAnimationFrame(frame);
    }
    requestAnimationFrame(frame);
}

// ══════════════════════════════════════
// FOGOS DE ARTIFÍCIO (meta atingida)
// ══════════════════════════════════════
function dispararFogos() {
    var canvas = document.createElement('canvas');
    canvas.style.cssText = 'position:fixed;inset:0;pointer-events:none;z-index:9998;';
    canvas.width = window.innerWidth;
    canvas.height = window.innerHeight;
    document.body.appendChild(canvas);
    var ctx2 = canvas.getContext('2d');

    var explosoes = [];
    for (var e = 0; e < 5; e++) {
        var cx = Math.random() * canvas.width * 0.6 + canvas.width * 0.2;
        var cy = Math.random() * canvas.height * 0.4 + canvas.height * 0.1;
        var cor = ['#FFD700','#B87333','#E8C94A','#FF6B6B','#4ECDC4'][e];
        var parts = [];
        for (var p = 0; p < 50; p++) {
            var ang = (Math.PI * 2 / 50) * p;
            var vel = Math.random() * 3 + 2;
            parts.push({ x:cx, y:cy, vx:Math.cos(ang)*vel, vy:Math.sin(ang)*vel, life:1, cor:cor });
        }
        explosoes.push({ parts:parts, delay:e*350 });
    }

    var start = Date.now();
    function frame() {
        var elapsed = Date.now() - start;
        if (elapsed > 3000) { canvas.remove(); return; }
        ctx2.clearRect(0, 0, canvas.width, canvas.height);
        explosoes.forEach(function(exp) {
            if (elapsed < exp.delay) return;
            var t = elapsed - exp.delay;
            exp.parts.forEach(function(p) {
                p.x += p.vx;
                p.vy += 0.05;
                p.y += p.vy;
                p.life = Math.max(0, 1 - t/2000);
                ctx2.beginPath();
                ctx2.arc(p.x, p.y, 2, 0, Math.PI*2);
                ctx2.fillStyle = p.cor;
                ctx2.globalAlpha = p.life;
                ctx2.fill();
            });
        });
        requestAnimationFrame(frame);
    }
    requestAnimationFrame(frame);
}

// ══════════════════════════════════════
// LEVEL UP
// ══════════════════════════════════════
function animarLevelUp(nome, emoji) {
    var div = document.createElement('div');
    div.innerHTML = '<div style="position:fixed;inset:0;background:rgba(4,14,18,0.85);display:flex;flex-direction:column;align-items:center;justify-content:center;z-index:10000;animation:gamFadeIn .3s ease;">'
        + '<div style="position:absolute;width:200px;height:200px;border-radius:50%;border:3px solid #FFD700;animation:gamRingPulse 1s ease-in-out infinite;box-shadow:0 0 60px rgba(255,215,0,0.5);"></div>'
        + '<div style="font-size:80px;animation:gamEmojiIn .6s cubic-bezier(.34,1.56,.64,1) both;position:relative;z-index:1;">' + emoji + '</div>'
        + '<div style="font-family:Cormorant Garamond,serif;font-size:48px;font-weight:700;background:linear-gradient(135deg,#FFD700,#B87333);-webkit-background-clip:text;-webkit-text-fill-color:transparent;animation:gamSlideUp .5s ease .3s both;">LEVEL UP!</div>'
        + '<div style="font-size:20px;color:rgba(240,237,232,0.8);margin-top:8px;animation:gamSlideUp .5s ease .5s both;">' + nome + '</div>'
        + '</div>';
    document.body.appendChild(div);
    setTimeout(function() { div.remove(); }, 3500);
}

// ══════════════════════════════════════
// POP-UP GLOBAL DE PONTOS
// ══════════════════════════════════════
var _popupQueue = [];
var _popupActive = false;

function mostrarPopupPontos(dados) {
    _popupQueue.push(dados);
    if (!_popupActive) _processarPopup();
}

function _processarPopup() {
    if (_popupQueue.length === 0) { _popupActive = false; return; }
    _popupActive = true;
    var d = _popupQueue.shift();

    var popup = document.createElement('div');
    popup.className = 'gamif-popup';
    popup.innerHTML = '<div class="gamif-popup-avatar">' + (d.iniciais || '?') + '</div>'
        + '<div class="gamif-popup-info"><div class="gamif-popup-nome">' + (d.nome || '') + '</div>'
        + '<div class="gamif-popup-evento">' + (d.descricao || d.evento || '') + '</div></div>'
        + '<div class="gamif-popup-pts">+' + d.pontos + '</div>'
        + '<div class="gamif-popup-barra"></div>';
    document.body.appendChild(popup);

    setTimeout(function() { popup.classList.add('saindo'); }, 3600);
    setTimeout(function() { popup.remove(); _processarPopup(); }, 4000);
}

// ══════════════════════════════════════
// POLLING GLOBAL DE EVENTOS
// ══════════════════════════════════════
setInterval(function() {
    var xhr = new XMLHttpRequest();
    xhr.open('GET', BASE + '/modules/gamificacao/api.php?action=check_eventos');
    xhr.onload = function() {
        try {
            var eventos = JSON.parse(xhr.responseText);
            if (!eventos || !eventos.length) return;
            eventos.forEach(function(ev) {
                // Não mostrar popup do próprio evento que acabei de criar
                mostrarPopupPontos(ev);
                if (ev.evento === 'contrato_fechado') {
                    dispararConfetes();
                    try { sons.moedas(); sons.aplauso(); } catch(e) {}
                }
                if (ev.evento === 'meta_atingida') {
                    dispararFogos();
                    try { sons.fanfarra(); } catch(e) {}
                }
                if (ev.nivel_novo) {
                    animarLevelUp(ev.nivel_novo.nome, ev.nivel_novo.emoji);
                    try { sons.levelup(); } catch(e) {}
                }
            });
        } catch(e) {}
    };
    xhr.send();
}, 10000);

// ══════════════════════════════════════
// CSS GLOBAL (injetado)
// ══════════════════════════════════════
var style = document.createElement('style');
style.textContent = ''
    + '.gamif-popup{position:fixed;bottom:24px;right:24px;background:linear-gradient(135deg,#071820,#0D2535);border:1px solid rgba(200,135,58,0.4);border-radius:16px;padding:14px 18px;display:flex;align-items:center;gap:12px;min-width:280px;max-width:340px;z-index:9999;box-shadow:0 8px 32px rgba(0,0,0,0.4);animation:gamPopupIn .4s cubic-bezier(.34,1.56,.64,1) both;overflow:hidden;}'
    + '.gamif-popup.saindo{animation:gamPopupOut .4s ease forwards;}'
    + '.gamif-popup-avatar{width:40px;height:40px;border-radius:50%;background:#112D3E;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:700;color:#F0EDE8;border:2px solid #C8873A;flex-shrink:0;}'
    + '.gamif-popup-info{flex:1;}'
    + '.gamif-popup-nome{font-size:14px;font-weight:700;color:#F0EDE8;}'
    + '.gamif-popup-evento{font-size:12px;color:rgba(240,237,232,0.5);}'
    + '.gamif-popup-pts{font-family:"Cormorant Garamond",serif;font-size:28px;font-weight:700;color:#FFD700;animation:gamPtsIn .5s cubic-bezier(.34,1.56,.64,1) .2s both;}'
    + '.gamif-popup-barra{position:absolute;bottom:0;left:0;height:3px;background:linear-gradient(90deg,#B87333,#FFD700);width:100%;animation:gamBarraTimer 4s linear forwards;}'
    + '@keyframes gamPopupIn{from{transform:translateY(100px);opacity:0;}to{transform:translateY(0);opacity:1;}}'
    + '@keyframes gamPopupOut{to{transform:translateX(120%);opacity:0;}}'
    + '@keyframes gamPtsIn{from{transform:scale(0) rotate(-20deg);opacity:0;}to{transform:scale(1) rotate(0);opacity:1;}}'
    + '@keyframes gamBarraTimer{from{width:100%;}to{width:0%;}}'
    + '@keyframes gamFadeIn{from{opacity:0;}to{opacity:1;}}'
    + '@keyframes gamRingPulse{0%,100%{transform:scale(1);opacity:1;}50%{transform:scale(1.1);opacity:.6;}}'
    + '@keyframes gamEmojiIn{from{transform:scale(0);opacity:0;}to{transform:scale(1);opacity:1;}}'
    + '@keyframes gamSlideUp{from{transform:translateY(20px);opacity:0;}to{transform:translateY(0);opacity:1;}}';
document.head.appendChild(style);

// Exportar
window._gamSons = sons;
window._gamConfetes = dispararConfetes;
window._gamFogos = dispararFogos;
window._gamLevelUp = animarLevelUp;
window._gamPopup = mostrarPopupPontos;

})();
