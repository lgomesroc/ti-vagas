import test from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';
import { spawn } from 'node:child_process';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const script = fileURLToPath(new URL('./secondary-account.mjs', import.meta.url));

test('navegador extrai publicação e contato de um LinkedIn simulado localmente', async () => {
  const directory = mkdtempSync(path.join(tmpdir(), 'ti-vagas-linkedin-browser-'));
  const storageStatePath = path.join(directory, 'storage-state.json');
  const requests = [];
  let baseUrl = '';

  const server = http.createServer((request, response) => {
    requests.push({ url: request.url ?? '', cookie: request.headers.cookie ?? '' });

    if (request.url === '/feed/') {
      response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
      response.end('<html><head><title>Feed simulado</title></head><body><main>Feed autenticado de teste</main></body></html>');
      return;
    }

    if (request.url === '/favicon.ico') {
      response.writeHead(204);
      response.end();
      return;
    }

    if ((request.url ?? '').startsWith('/search/results/content/')) {
      const publishedAt = new Date().toISOString();
      const postUrl = `${baseUrl}/feed/update/urn:li:activity:7530395139467337728/`;
      const companyUrl = `${baseUrl}/company/acme`;
      const description = [
        'Vaga aberta | Desenvolvedor PHP Júnior',
        'Empresa: Acme Tecnologia. Remoto Brasil.',
        'Responsabilidades: desenvolver e manter APIs, corrigir falhas, escrever testes automatizados e colaborar com o time de produto.',
        'Requisitos: conhecimento em PHP, orientação a objetos, SQL, HTTP, APIs REST e Git.',
        'Contato: talentos@acme.com.br',
      ].join(' ');

      const card = `
        <article componentkey="update-card-focus-mock">
          <button aria-label="Abrir menu de controle da publicação de Acme Tecnologia">Menu</button>
          <time datetime="${publishedAt}"></time>
          <a href="${postUrl}">Abrir publicação</a>
          <a href="${companyUrl}">Acme Tecnologia</a>
          <div data-testid="expandable-text-box">${description}</div>
          <div class="comments-comment-item">Contato adicional: talentos@acme.com.br</div>
          <a href="https://careers.acme.example/vagas/123">Página oficial da vaga</a>
        </article>
      `;

      response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
      response.end(`<html><head><title>Resultados simulados</title></head><body><main>${card}</main></body></html>`);
      return;
    }

    response.writeHead(404, { 'Content-Type': 'text/plain; charset=utf-8' });
    response.end('Not found');
  });

  try {
    await new Promise((resolve, reject) => {
      server.once('error', reject);
      server.listen(0, '127.0.0.1', resolve);
    });

    baseUrl = `http://127.0.0.1:${server.address().port}`;

    writeFileSync(storageStatePath, JSON.stringify({
      cookies: [{
        name: 'li_at',
        value: 'mock-session-only',
        domain: '127.0.0.1',
        path: '/',
        expires: Math.floor(Date.now() / 1000) + 3600,
        httpOnly: true,
        secure: false,
        sameSite: 'Lax',
      }],
      origins: [],
    }));

    const result = await new Promise((resolve, reject) => {
      const child = spawn(process.execPath, [script, 'search'], {
        env: {
          ...process.env,
          CI: 'true',
          LINKEDIN_BASE_URL: baseUrl,
          LINKEDIN_STORAGE_STATE_PATH: storageStatePath,
          LINKEDIN_HEADLESS: 'true',
          LINKEDIN_POSTS_LOOKBACK_HOURS: '168',
          LINKEDIN_POSTS_TIME_BUDGET_SECONDS: '30',
          LINKEDIN_SECONDARY_ACCOUNT_KEYWORDS: 'vaga desenvolvedor PHP júnior',
        },
      });

      let stdout = '';
      let stderr = '';
      child.stdout.on('data', chunk => { stdout += chunk; });
      child.stderr.on('data', chunk => { stderr += chunk; });
      child.once('error', reject);
      child.once('close', code => resolve({ code, stdout, stderr }));
    });

    assert.equal(result.code, 0, result.stderr);
    const posts = JSON.parse(result.stdout);

    assert.equal(posts.length, 1);
    assert.equal(posts[0].postId, 'linkedin:post:7530395139467337728');
    assert.equal(posts[0].postUrl, `${baseUrl}/feed/update/urn:li:activity:7530395139467337728/`);
    assert.match(posts[0].title, /Desenvolvedor PHP Júnior/);
    assert.match(posts[0].text, /Responsabilidades:/);
    assert.match(posts[0].text, /Requisitos:/);
    assert.equal(posts[0].authorName, 'Acme Tecnologia');
    assert.ok(posts[0].emails.includes('talentos@acme.com.br'));
    assert.ok(posts[0].links.includes('https://careers.acme.example/vagas/123'));
    assert.ok(requests.some(request => request.url.startsWith('/search/results/content/')));
    assert.ok(requests.every(request => request.url.startsWith('/feed/') || request.url.startsWith('/search/results/content/') || request.url === '/favicon.ico'));
    assert.ok(requests.some(request => request.url === '/feed/' && request.cookie.includes('li_at=mock-session-only')), 'o cookie de sessão deve chegar à primeira navegação do perfil persistente');
  } finally {
    await new Promise(resolve => server.close(resolve));
    rmSync(directory, { recursive: true, force: true });
  }
});
