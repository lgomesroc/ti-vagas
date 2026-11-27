/*
 * Service worker do TI Vagas (PWA).
 *
 * Princípios de segurança e consistência:
 * - Páginas HTML NUNCA são armazenadas em cache. Elas carregam sessão, token
 *   CSRF, consentimento de cookies e a região validada no servidor; servir uma
 *   cópia antiga causaria erro 419 nos formulários, listagens de outra região
 *   ou estado de consentimento incorreto. Navegações vão sempre à rede e, sem
 *   conexão, recebem a página offline estática.
 * - Requisições que não são GET (POST de aceite, cookies e localização) e
 *   requisições de outras origens (Google Analytics, AdSense, fontes) não são
 *   interceptadas: seguem exatamente o comportamento padrão do navegador.
 * - Apenas arquivos estáticos públicos (ícones, manifesto, favicon, página
 *   offline e arquivos versionados de /build/) são guardados em cache.
 *
 * Para publicar uma nova versão do cache, altere CACHE_VERSION.
 */

const CACHE_VERSION = 'v1';
const CACHE_PREFIX = 'ti-vagas-';
const STATIC_CACHE = `${CACHE_PREFIX}static-${CACHE_VERSION}`;
const OFFLINE_URL = '/offline.html';

const PRECACHE_URLS = [
    OFFLINE_URL,
    '/favicon.svg',
    '/manifest.webmanifest',
    '/icons/icon-192.png',
    '/icons/icon-512.png',
    '/icons/icon-maskable-192.png',
    '/icons/icon-maskable-512.png',
    '/icons/apple-touch-icon.png',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(STATIC_CACHE)
            // cache: 'reload' evita gravar uma cópia antiga vinda do cache HTTP.
            .then((cache) => cache.addAll(PRECACHE_URLS.map((url) => new Request(url, { cache: 'reload' }))))
            // Como nenhuma página HTML fica em cache, ativar a nova versão
            // imediatamente não deixa abas com conteúdo misturado.
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(
                keys
                    .filter((key) => key.startsWith(CACHE_PREFIX) && key !== STATIC_CACHE)
                    .map((key) => caches.delete(key))
            ))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
});

function isStaticAsset(url) {
    return url.pathname.startsWith('/icons/')
        || url.pathname === '/favicon.svg'
        || url.pathname === '/favicon.ico'
        || url.pathname === '/manifest.webmanifest'
        || url.pathname === OFFLINE_URL;
}

function isVersionedBuildAsset(url) {
    // Arquivos gerados pelo Vite têm hash no nome: o conteúdo nunca muda.
    return url.pathname.startsWith('/build/assets/');
}

async function networkFirstNavigation(request) {
    try {
        // Sempre da rede, sem gravar a resposta: ver comentário no topo.
        return await fetch(request);
    } catch (error) {
        const cache = await caches.open(STATIC_CACHE);
        const offline = await cache.match(OFFLINE_URL);

        return offline || new Response(
            '<!DOCTYPE html><html lang="pt-BR"><meta charset="utf-8"><title>Sem conexão | TI Vagas</title><p>Sem conexão com a internet.</p></html>',
            { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } }
        );
    }
}

async function cacheFirst(request) {
    const cache = await caches.open(STATIC_CACHE);
    const cached = await cache.match(request);

    if (cached) {
        return cached;
    }

    const response = await fetch(request);

    if (response.ok && response.type === 'basic') {
        cache.put(request, response.clone());
    }

    return response;
}

async function staleWhileRevalidate(request, event) {
    const cache = await caches.open(STATIC_CACHE);
    const cached = await cache.match(request);

    const network = fetch(request)
        .then((response) => {
            if (response.ok && response.type === 'basic') {
                return cache.put(request, response.clone()).then(() => response);
            }

            return response;
        })
        .catch(() => cached);

    if (cached) {
        event.waitUntil(network.catch(() => undefined));

        return cached;
    }

    return network;
}

self.addEventListener('fetch', (event) => {
    const { request } = event;

    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    if (url.origin !== self.location.origin) {
        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(networkFirstNavigation(request));

        return;
    }

    if (isVersionedBuildAsset(url)) {
        event.respondWith(cacheFirst(request));

        return;
    }

    if (isStaticAsset(url)) {
        event.respondWith(staleWhileRevalidate(request, event));
    }

    // Demais requisições: comportamento padrão do navegador.
});
