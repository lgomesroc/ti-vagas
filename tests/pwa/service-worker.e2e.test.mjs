/*
 * Testes de comportamento real do service worker (public/sw.js) no Chromium.
 *
 * Um servidor HTTP local serve os arquivos reais de public/ e páginas HTML
 * simuladas. Falhas de rede são reproduzidas encerrando o socket da conexão,
 * o que faz o fetch() do service worker rejeitar como numa queda de rede.
 *
 * Cenários:
 *   1. Cache de arquivos estáticos;
 *   2. Navegação sem conexão;
 *   3. Falha de rede sem cópia em cache;
 *   4. Atualização e substituição da versão do cache.
 *
 * Execução: npm run test:pwa
 */

import test from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const PUBLIC_DIR = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../public');
const VERSION_LINE = "const CACHE_VERSION = 'v1';";

const MIME = {
    '.html': 'text/html; charset=utf-8',
    '.js': 'text/javascript; charset=utf-8',
    '.svg': 'image/svg+xml',
    '.png': 'image/png',
    '.ico': 'image/x-icon',
    '.webmanifest': 'application/manifest+json',
};

const PAGE = (title) => `<!DOCTYPE html>
<html lang="pt-BR">
<head><meta charset="utf-8"><title>${title}</title><link rel="manifest" href="/manifest.webmanifest"></head>
<body>
<h1>${title}</h1>
<script>
    window.swReady = navigator.serviceWorker
        .register('/sw.js', { scope: '/', updateViaCache: 'none' })
        .then(() => navigator.serviceWorker.ready);
</script>
</body>
</html>`;

function createServer() {
    const state = {
        down: false,
        version: null,
        hits: [],
        posts: 0,
        buildCounter: 0,
        failOffline: false,
    };

    const server = http.createServer(async (req, res) => {
        const url = new URL(req.url, 'http://localhost');

        state.hits.push(`${req.method} ${url.pathname}`);

        if (state.down) {
            // Queda de rede: o navegador recebe um erro de conexão.
            req.socket.destroy();

            return;
        }

        if (req.method === 'POST') {
            state.posts += 1;
            res.writeHead(204).end();

            return;
        }

        if (url.pathname === '/' || url.pathname.startsWith('/vagas')) {
            res.writeHead(200, { 'Content-Type': MIME['.html'], 'Cache-Control': 'no-store' });
            res.end(PAGE(`Página ${url.pathname}`));

            return;
        }

        if (state.failOffline && url.pathname === '/offline.html') {
            res.writeHead(500, { 'Content-Type': 'text/plain' }).end('erro');

            return;
        }

        if (url.pathname === '/icons/parcial.png') {
            res.writeHead(206, { 'Content-Type': 'image/png', 'Content-Range': 'bytes 0-3/10' });
            res.end('abcd');

            return;
        }

        if (url.pathname.startsWith('/build/assets/')) {
            state.buildCounter += 1;
            res.writeHead(200, { 'Content-Type': MIME['.js'] });
            res.end(`/* ${url.pathname} #${state.buildCounter} */`);

            return;
        }

        const file = path.join(PUBLIC_DIR, path.normalize(url.pathname));

        if (!file.startsWith(PUBLIC_DIR)) {
            res.writeHead(403).end();

            return;
        }

        try {
            let body = await readFile(file);

            if (url.pathname === '/sw.js' && state.version) {
                body = body.toString().replace(VERSION_LINE, `const CACHE_VERSION = '${state.version}';`);
            }

            res.writeHead(200, {
                'Content-Type': MIME[path.extname(file)] ?? 'application/octet-stream',
                'Cache-Control': url.pathname === '/sw.js' ? 'no-cache' : 'no-store',
            });
            res.end(body);
        } catch {
            res.writeHead(404, { 'Content-Type': 'text/plain' }).end('not found');
        }
    });

    return new Promise((resolve) => {
        server.listen(0, '127.0.0.1', () => {
            resolve({ server, state, origin: `http://localhost:${server.address().port}` });
        });
    });
}

async function setup(t) {
    const { server, state, origin } = await createServer();

    t.after(() => new Promise((resolve) => {
        server.closeAllConnections();
        server.close(resolve);
    }));

    // PWA_TEST_CHROMIUM permite apontar para um Chromium já instalado
    // (ex.: ambientes sem `npx playwright install`).
    const browser = await chromium.launch(
        process.env.PWA_TEST_CHROMIUM ? { executablePath: process.env.PWA_TEST_CHROMIUM } : {}
    );

    t.after(() => browser.close());

    const context = await browser.newContext();
    const page = await context.newPage();

    await page.goto(`${origin}/`);
    await page.evaluate(() => window.swReady);
    // Recarrega para a página passar a ser controlada pelo service worker.
    await page.reload();
    await page.waitForFunction(() => navigator.serviceWorker.controller !== null);

    return { state, origin, page, context };
}

const cacheState = (page) => page.evaluate(async () => {
    const result = {};

    for (const name of await caches.keys()) {
        const cache = await caches.open(name);
        result[name] = (await cache.keys()).map((request) => new URL(request.url).pathname);
    }

    return result;
});

async function waitForCaches(page, predicate, timeoutMs = 10000) {
    const deadline = Date.now() + timeoutMs;
    let current = await cacheState(page);

    while (!predicate(current)) {
        if (Date.now() > deadline) {
            assert.fail(`Estado do cache inesperado: ${JSON.stringify(current)}`);
        }

        await new Promise((resolve) => setTimeout(resolve, 100));
        current = await cacheState(page);
    }

    return current;
}

const PRECACHED = [
    '/favicon.svg',
    '/icons/apple-touch-icon.png',
    '/icons/icon-192.png',
    '/icons/icon-512.png',
    '/icons/icon-maskable-192.png',
    '/icons/icon-maskable-512.png',
    '/manifest.webmanifest',
    '/offline.html',
];

const pageFetch = (page, url, init) => page.evaluate(async ([target, options]) => {
    try {
        const response = await fetch(target, options);

        return { ok: true, status: response.status, body: await response.text() };
    } catch (error) {
        return { ok: false, error: String(error) };
    }
}, [url, init ?? {}]);

test('verifica o sw.js real contém a linha de versão usada pelos testes', async () => {
    const source = await readFile(path.join(PUBLIC_DIR, 'sw.js'), 'utf8');

    assert.ok(source.includes(VERSION_LINE));
});

test('1. cache de arquivos estáticos', async (t) => {
    const { state, page } = await setup(t);

    const caches = await cacheState(page);

    assert.deepEqual(Object.keys(caches).sort(), ['ti-vagas-static-v1']);

    for (const pathname of PRECACHED) {
        assert.ok(caches['ti-vagas-static-v1'].includes(pathname), pathname);
    }

    // Além do pré-cache, só arquivos estáticos (ex.: /favicon.ico pedido
    // automaticamente pelo navegador) podem estar no cache.
    for (const pathname of caches['ti-vagas-static-v1']) {
        assert.ok(PRECACHED.includes(pathname) || pathname === '/favicon.ico', pathname);
    }

    await t.test('páginas HTML nunca são gravadas', async () => {
        await page.goto(page.url().replace(/\/[^/]*$/, '/vagas/10'));
        const all = Object.values(await cacheState(page)).flat();

        assert.ok(!all.some((pathname) => pathname === '/' || pathname.startsWith('/vagas')));
    });

    await t.test('arquivo estático é servido do cache quando a rede cai', async () => {
        state.down = true;
        const response = await pageFetch(page, '/manifest.webmanifest');
        state.down = false;

        assert.equal(response.ok, true);
        assert.equal(response.status, 200);
        assert.equal(JSON.parse(response.body).short_name, 'TI Vagas');
    });

    await t.test('arquivo de /build/assets/ é gravado no cache de build e reaproveitado', async () => {
        const first = await pageFetch(page, '/build/assets/app-abc123.js');
        state.down = true;
        const second = await pageFetch(page, '/build/assets/app-abc123.js');
        state.down = false;

        assert.equal(first.status, 200);
        assert.equal(second.status, 200);
        assert.equal(second.body, first.body);
        assert.ok((await cacheState(page))['ti-vagas-build-v1'].includes('/build/assets/app-abc123.js'));
    });

    await t.test('o cache de build mantém no máximo 60 arquivos, descartando os mais antigos', async () => {
        for (let i = 0; i < 65; i += 1) {
            await pageFetch(page, `/build/assets/chunk-${i}.js`);
        }

        const build = (await waitForCaches(
            page,
            (current) => current['ti-vagas-build-v1'].includes('/build/assets/chunk-64.js')
                && current['ti-vagas-build-v1'].length <= 60
        ))['ti-vagas-build-v1'];

        assert.equal(build.length, 60);
        assert.ok(build.includes('/build/assets/chunk-64.js'));
        assert.ok(!build.includes('/build/assets/app-abc123.js'));
    });

    await t.test('somente status 200 é gravado (206 e 404 ficam fora do cache)', async () => {
        const partial = await pageFetch(page, '/icons/parcial.png');
        const missing = await pageFetch(page, '/icons/nao-existe.png');
        const all = Object.values(await cacheState(page)).flat();

        assert.equal(partial.status, 206);
        assert.equal(missing.status, 404);
        assert.ok(!all.includes('/icons/nao-existe.png'));
        assert.ok(!all.includes('/icons/parcial.png'));
    });

    await t.test('requisições POST não são interceptadas nem gravadas', async () => {
        const before = state.posts;
        const response = await pageFetch(page, '/localizacao/atualizar', { method: 'POST', body: 'x=1' });

        assert.equal(response.status, 204);
        assert.equal(state.posts, before + 1);
        assert.ok(!Object.values(await cacheState(page)).flat().includes('/localizacao/atualizar'));
    });
});

test('2. navegação sem conexão', async (t) => {
    const { state, origin, page } = await setup(t);

    await t.test('exibe a página offline e preserva o endereço solicitado', async () => {
        state.down = true;
        await page.goto(`${origin}/vagas/123`);

        assert.equal(await page.title(), 'Sem conexão | TI Vagas');
        assert.equal(page.url(), `${origin}/vagas/123`);
        assert.ok(await page.isVisible('#retry-button'));
    });

    await t.test('quando a conexão volta, a mesma página é carregada da rede', async () => {
        state.down = false;
        await page.reload();

        assert.equal(await page.title(), 'Página /vagas/123');
    });

    await t.test('sem offline.html no cache, responde com página mínima 503', async () => {
        await page.evaluate(async () => {
            const cache = await caches.open('ti-vagas-static-v1');
            await cache.delete('/offline.html');
        });

        state.down = true;
        const response = await page.goto(`${origin}/vagas/456`);
        state.down = false;

        assert.equal(response.status(), 503);
        assert.equal(await page.title(), 'Sem conexão | TI Vagas');
    });
});

test('3. falha de rede sem cópia em cache', async (t) => {
    const { state, page } = await setup(t);

    state.down = true;

    await t.test('arquivo estático não armazenado recebe 503 em vez de erro', async () => {
        const response = await pageFetch(page, '/icons/novo-icone.png');

        assert.equal(response.ok, true, response.error);
        assert.equal(response.status, 503);
        assert.equal(response.body, 'Recurso indisponível sem conexão.');
    });

    await t.test('arquivo de build não armazenado recebe 503 em vez de erro', async () => {
        const response = await pageFetch(page, '/build/assets/inexistente-999.js');

        assert.equal(response.ok, true, response.error);
        assert.equal(response.status, 503);
    });

    await t.test('respostas 503 de falha não são gravadas no cache', async () => {
        const all = Object.values(await cacheState(page)).flat();

        assert.ok(!all.includes('/icons/novo-icone.png'));
        assert.ok(!all.includes('/build/assets/inexistente-999.js'));
    });

    state.down = false;
});

test('4. atualização e substituição da versão do cache', async (t) => {
    const { state, page } = await setup(t);

    await pageFetch(page, '/build/assets/app-v1.js');

    assert.deepEqual(
        Object.keys(await cacheState(page)).sort(),
        ['ti-vagas-build-v1', 'ti-vagas-static-v1']
    );

    await t.test('uma falha no pré-cache mantém a versão anterior ativa e o cache intacto', async () => {
        state.version = 'v2-falha';
        state.failOffline = true;

        // A nova versão é baixada, mas o pré-cache falha (offline.html com 500).
        const finalState = await page.evaluate(async () => {
            const registration = await navigator.serviceWorker.getRegistration();
            await registration.update();
            const worker = registration.installing;

            if (!worker) {
                return 'sem-instalacao';
            }

            if (worker.state === 'redundant') {
                return 'redundant';
            }

            return new Promise((resolve) => {
                worker.addEventListener('statechange', () => {
                    if (worker.state === 'redundant' || worker.state === 'activated') {
                        resolve(worker.state);
                    }
                });
            });
        });

        state.failOffline = false;

        const caches = await waitForCaches(page, (current) => !('ti-vagas-static-v2-falha' in current));
        const activeScript = await page.evaluate(async () => {
            const registration = await navigator.serviceWorker.getRegistration();

            return registration.active.scriptURL;
        });

        assert.equal(finalState, 'redundant');
        assert.deepEqual(Object.keys(caches).sort(), ['ti-vagas-build-v1', 'ti-vagas-static-v1']);
        assert.ok(activeScript.endsWith('/sw.js'));
        assert.ok(caches['ti-vagas-static-v1'].includes('/offline.html'));
        assert.ok(caches['ti-vagas-build-v1'].includes('/build/assets/app-v1.js'));
    });

    await t.test('nova versão é instalada, assume o controle e remove os caches antigos', async () => {
        state.version = 'v2';
        state.down = false;

        await page.evaluate(async () => {
            const registration = await navigator.serviceWorker.getRegistration();
            const changed = new Promise((resolve) => {
                navigator.serviceWorker.addEventListener('controllerchange', resolve, { once: true });
            });

            await registration.update();
            await changed;
        });

        const caches = await waitForCaches(
            page,
            (current) => !('ti-vagas-static-v1' in current) && !('ti-vagas-build-v1' in current)
        );

        assert.deepEqual(Object.keys(caches), ['ti-vagas-static-v2']);

        for (const pathname of PRECACHED) {
            assert.ok(caches['ti-vagas-static-v2'].includes(pathname), pathname);
        }

        const controller = await page.evaluate(() => navigator.serviceWorker.controller.state);

        assert.equal(controller, 'activated');
    });

    await t.test('a nova versão continua funcionando offline', async () => {
        state.down = true;
        const manifest = await pageFetch(page, '/manifest.webmanifest');
        const lostBuild = await pageFetch(page, '/build/assets/app-v1.js');
        state.down = false;

        assert.equal(manifest.status, 200);
        // O cache de build da versão anterior foi descartado.
        assert.equal(lostBuild.status, 503);
    });
});
