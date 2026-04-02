// Ferreira & Sá Hub — Service Worker
var CACHE_NAME = 'fshub-v2';
var urlsToCache = [
    '/conecta/assets/css/conecta.css',
    '/conecta/assets/js/conecta.js',
    '/conecta/assets/js/helpers.js',
    '/conecta/assets/js/drawer.js',
    '/conecta/assets/img/logo.png',
    '/conecta/assets/img/logo-sidebar.png'
];

// Install — cache estático
self.addEventListener('install', function(event) {
    event.waitUntil(
        caches.open(CACHE_NAME).then(function(cache) {
            return cache.addAll(urlsToCache);
        })
    );
    self.skipWaiting();
});

// Activate — limpar caches antigos
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

// Fetch — network first, fallback cache
self.addEventListener('fetch', function(event) {
    // Não cachear POST ou APIs
    if (event.request.method !== 'GET') return;
    if (event.request.url.indexOf('api.php') !== -1) return;

    event.respondWith(
        fetch(event.request).then(function(response) {
            // Cachear assets estáticos
            if (response.ok && (event.request.url.match(/\.(css|js|png|jpg|woff2?)$/))) {
                var clone = response.clone();
                caches.open(CACHE_NAME).then(function(cache) {
                    cache.put(event.request, clone);
                });
            }
            return response;
        }).catch(function() {
            return caches.match(event.request);
        })
    );
});
