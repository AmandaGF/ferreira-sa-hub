// Ferreira & Sá Hub — Service Worker
// Estratégia: network-first para HTML/API, cache-first para assets estáticos,
// offline.html como fallback quando estiver sem rede.

var CACHE_NAME = 'fshub-v19';
var OFFLINE_URL = '/conecta/offline.html';

// Shell mínimo pré-cacheado — assets que compõem o layout base
var PRECACHE_URLS = [
    OFFLINE_URL,
    '/conecta/assets/css/conecta.css',
    '/conecta/assets/js/conecta.js',
    '/conecta/assets/js/helpers.js',
    '/conecta/assets/js/drawer.js',
    '/conecta/assets/js/wa_sender.js',
    '/conecta/assets/js/busca_cpf.js',
    '/conecta/assets/js/gamificacao-efeitos.js',
    '/conecta/assets/img/logo.png',
    '/conecta/assets/img/logo-sidebar.png',
    '/conecta/assets/img/favicon.svg'
];

// Install — pré-cacheia shell
self.addEventListener('install', function(event) {
    event.waitUntil(
        caches.open(CACHE_NAME).then(function(cache) {
            // addAll é atômico (falhou um, falha tudo). Usar add individual silencioso pra não quebrar o install
            return Promise.all(PRECACHE_URLS.map(function(url) {
                return cache.add(new Request(url, { cache: 'reload' })).catch(function() {});
            }));
        })
    );
    self.skipWaiting();
});

// Activate — limpa caches antigos
self.addEventListener('activate', function(event) {
    event.waitUntil(
        caches.keys().then(function(names) {
            return Promise.all(
                names.filter(function(n) { return n !== CACHE_NAME; })
                     .map(function(n) { return caches.delete(n); })
            );
        })
    );
    self.clients.claim();
});

// Fetch — estratégia híbrida
self.addEventListener('fetch', function(event) {
    var req = event.request;

    // Não interceptar não-GET
    if (req.method !== 'GET') return;

    var url = new URL(req.url);

    // Não interceptar cross-origin (WhatsApp, Asaas, ReceitaWS etc)
    if (url.origin !== self.location.origin) return;

    // APIs internas — network-only (não cachear dados dinâmicos)
    if (url.pathname.indexOf('/api.php') !== -1 ||
        url.pathname.indexOf('/conecta/api/') !== -1 ||
        url.pathname.indexOf('/api/') !== -1 ||
        url.searchParams.has('action')) {
        return; // deixa o navegador lidar
    }

    // Navegação (HTML) — network-first com fallback offline
    if (req.mode === 'navigate' || (req.headers.get('accept') || '').indexOf('text/html') !== -1) {
        event.respondWith(
            fetch(req).catch(function() {
                return caches.match(OFFLINE_URL);
            })
        );
        return;
    }

    // Assets estáticos — cache-first com atualização em background
    if (/\.(css|js|png|jpg|jpeg|svg|woff2?|ttf|ico)(\?|$)/i.test(url.pathname)) {
        event.respondWith(
            caches.match(req).then(function(cached) {
                var fetchPromise = fetch(req).then(function(response) {
                    if (response.ok) {
                        var clone = response.clone();
                        caches.open(CACHE_NAME).then(function(cache) { cache.put(req, clone); });
                    }
                    return response;
                }).catch(function() { return cached; });
                return cached || fetchPromise;
            })
        );
        return;
    }

    // Default — network com fallback pra cache se existir
    event.respondWith(
        fetch(req).catch(function() { return caches.match(req); })
    );
});

// Mensagem pra forçar ativação (chamado pelo app quando detecta SW novo)
self.addEventListener('message', function(event) {
    if (event.data && event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
});

// ── Web Push ──
self.addEventListener('push', function(event) {
    var data = {};
    try { data = event.data ? event.data.json() : {}; } catch (e) { data = {}; }

    var title = data.title || 'F&S Hub';
    var options = {
        body:    data.body || '',
        icon:    '/conecta/assets/img/logo-sidebar.png',
        badge:   '/conecta/assets/img/logo-sidebar.png',
        data:    { url: data.url || '/conecta/' },
        vibrate: [200, 100, 200],
        requireInteraction: !!data.urgente,
        tag:     data.tag || undefined
    };

    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', function(event) {
    event.notification.close();
    var targetUrl = (event.notification.data && event.notification.data.url) || '/conecta/';

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function(list) {
            // Se já houver janela aberta, foca
            for (var i = 0; i < list.length; i++) {
                var c = list[i];
                if (c.url.indexOf('/conecta/') !== -1 && 'focus' in c) {
                    c.focus();
                    if ('navigate' in c) { try { c.navigate(targetUrl); } catch (e) {} }
                    return;
                }
            }
            if (self.clients.openWindow) return self.clients.openWindow(targetUrl);
        })
    );
});
