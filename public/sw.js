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
 *   offline e arquivos versionados de /build/assets/) são guardados em cache.
 * - Somente respostas com status exatamente 200, da própria origem, são
 *   gravadas. Outros 2xx ficam de fora: 206 (conteúdo parcial) é recusado pela
 *   Cache API e 204 não tem corpo para reaproveitar.
 * - Uma falha de rede sem cópia em cache sempre produz uma resposta válida
 *   (503), nunca uma promessa rejeitada ou `undefined` no respondWith.
 *
 * Para publicar uma nova versão do cache, altere CACHE_VERSION. Ver
 * docs/AplicativoPWA.md, seção "Atualização e substituição da versão".
 */

const CACHE_VERSION = 'v1';
const CACHE_PREFIX = 'ti-vagas-';
const STATIC_CACHE = `${CACHE_PREFIX}static-${CACHE_VERSION}`;
const BUILD_CACHE = `${CACHE_PREFIX}build-${CACHE_VERSION}`;
const CURRENT_CACHES = [STATIC_CACHE, BUILD_CACHE];

// Arquivos do Vite têm hash no nome; cada deploy gera nomes novos. O limite
// impede que versões antigas se acumulem indefinidamente no aparelho.
const MAX_BUILD_ENTRIES = 60;

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
            // Se qualquer arquivo falhar, a instalação falha e a versão
            // anterior continua ativa, com o cache anterior intacto.
            .then((cache) => cache.addAll(PRECACHE_URLS.map((url) => new Request(url, { cache: 'reload' }))))
            // Pré-cache incompleto não pode ficar ocupando espaço no aparelho.
            .catch((error) => caches.delete(STATIC_CACHE).then(() => Promise.reject(error)))
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
                    .filter((key) => key.startsWith(CACHE_PREFIX) && !CURRENT_CACHES.includes(key))
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
    return url.pathname.startsWith('/build/assets/');
}

function isCacheable(response) {
    return response.status === 200 && response.type === 'basic';
}

function unavailableResponse() {
    return new Response('Recurso indisponível sem conexão.', {
        status: 503,
        statusText: 'Service Unavailable',
        headers: {
            'Content-Type': 'text/plain; charset=utf-8',
            'Cache-Control': 'no-store',
        },
    });
}

function offlineFallbackPage() {
    return new Response(
        '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8">'
        + '<meta name="viewport" content="width=device-width, initial-scale=1">'
        + '<title>Sem conexão | TI Vagas</title></head><body>'
        + '<h1>Você está sem conexão</h1><p>Verifique sua internet e tente novamente.</p>'
        + '</body></html>',
        {
            status: 503,
            statusText: 'Service Unavailable',
            headers: {
                'Content-Type': 'text/html; charset=utf-8',
                'Cache-Control': 'no-store',
            },
        }
    );
}

async function trimCache(cacheName, maxEntries) {
    const cache = await caches.open(cacheName);
    const keys = await cache.keys();

    // cache.keys() devolve na ordem de inserção: remove os mais antigos.
    await Promise.all(
        keys.slice(0, Math.max(0, keys.length - maxEntries)).map((key) => cache.delete(key))
    );
}

async function store(cacheName, request, response, maxEntries) {
    try {
        const cache = await caches.open(cacheName);

        await cache.put(request, response);

        if (maxEntries) {
            await trimCache(cacheName, maxEntries);
        }
    } catch (error) {
        // Cota excedida ou cache removido: a resposta já foi entregue.
    }
}

async function networkFirstNavigation(request) {
    try {
        // Sempre da rede, sem gravar a resposta: ver comentário no topo.
        return await fetch(request);
    } catch (error) {
        const offline = await caches.match(OFFLINE_URL, { cacheName: STATIC_CACHE });

        return offline || offlineFallbackPage();
    }
}

async function cacheFirst(request, event) {
    const cached = await caches.match(request, { cacheName: BUILD_CACHE });

    if (cached) {
        return cached;
    }

    try {
        const response = await fetch(request);

        if (isCacheable(response)) {
            event.waitUntil(store(BUILD_CACHE, request, response.clone(), MAX_BUILD_ENTRIES));
        }

        return response;
    } catch (error) {
        return unavailableResponse();
    }
}

async function staleWhileRevalidate(request, event) {
    const cached = await caches.match(request, { cacheName: STATIC_CACHE });

    const network = fetch(request).then(async (response) => {
        if (isCacheable(response)) {
            await store(STATIC_CACHE, request, response.clone());
        }

        return response;
    });

    if (cached) {
        // Atualiza em segundo plano; uma falha de rede mantém a cópia atual.
        event.waitUntil(network.catch(() => undefined));

        return cached;
    }

    try {
        return await network;
    } catch (error) {
        return unavailableResponse();
    }
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
        event.respondWith(cacheFirst(request, event));

        return;
    }

    if (isStaticAsset(url)) {
        event.respondWith(staleWhileRevalidate(request, event));
    }

    // Demais requisições: comportamento padrão do navegador.
});
