import test from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';
import { spawn } from 'node:child_process';
import { existsSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const script = fileURLToPath(new URL('./jobs.mjs', import.meta.url));

/**
 * Sobe um servidor local que imita o endpoint público do LinkedIn e registra
 * as requisições recebidas. handler(req, res, count) decide a resposta.
 */
async function withServer(handler, run) {
  const requests = [];
  const server = http.createServer((req, res) => {
    requests.push(req.url);
    handler(req, res, requests.length);
  });
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  try {
    return await run(`http://127.0.0.1:${server.address().port}`, requests);
  } finally {
    await new Promise(resolve => server.close(resolve));
  }
}

function runScript(baseUrl, extraEnv = {}) {
  return new Promise(resolve => {
    const child = spawn(process.execPath, [script], {
      env: {
        ...process.env,
        LINKEDIN_JOBS_BASE_URL: baseUrl,
        LINKEDIN_JOBS_DELAY_MS: '500',
        LINKEDIN_JOBS_JITTER_MS: '0',
        LINKEDIN_JOBS_MAX_RETRIES: '2',
        LINKEDIN_JOBS_BACKOFF_BASE_MS: '10',
        LINKEDIN_JOBS_BACKOFF_MAX_MS: '100',
        ...extraEnv,
      },
    });
    let stdout = '';
    let stderr = '';
    child.stdout.on('data', chunk => { stdout += chunk; });
    child.stderr.on('data', chunk => { stderr += chunk; });
    child.on('close', code => resolve({ code, stdout, stderr }));
  });
}

test('429 persistente na etapa do geoId: tentativas limitadas, código 75 e marcador', async () => {
  await withServer((req, res) => {
    res.writeHead(429, { 'Retry-After': '0' });
    res.end('Too Many Requests');
  }, async (baseUrl, requests) => {
    const result = await runScript(baseUrl);
    assert.equal(result.code, 75);
    assert.equal(result.stdout, '');
    assert.match(result.stderr, /LINKEDIN_RATE_LIMITED retry_after=0 attempts=3/);
    assert.match(result.stderr, /COLETA INTERROMPIDA/);
    assert.match(result.stderr, /pesquisas concluídas: 0\//);
    assert.equal(requests.length, 3);
    assert.ok(requests.every(url => url.includes('typeaheadHits')));
  });
});

test('Retry-After acima do teto interrompe de imediato, sem esperar nem insistir', async () => {
  await withServer((req, res) => {
    res.writeHead(429, { 'Retry-After': '3600' });
    res.end();
  }, async (baseUrl, requests) => {
    const startedAt = Date.now();
    const result = await runScript(baseUrl);
    assert.equal(result.code, 75);
    assert.match(result.stderr, /LINKEDIN_RATE_LIMITED retry_after=3600/);
    assert.equal(requests.length, 1);
    assert.ok(Date.now() - startedAt < 10000);
  });
});

test('recupera-se de um 429 transitório e conclui a coleta normalmente', async () => {
  await withServer((req, res, count) => {
    if (count === 1) {
      res.writeHead(429, { 'Retry-After': '0' });
      res.end();
      return;
    }
    if (req.url.includes('typeaheadHits')) {
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify([{ id: '106057199' }]));
      return;
    }
    res.writeHead(200, { 'Content-Type': 'text/html' });
    res.end('<html></html>');
  }, async (baseUrl, requests) => {
    const result = await runScript(baseUrl);
    assert.equal(result.code, 0, result.stderr);
    assert.equal(result.stdout, '[]');
    assert.match(result.stderr, /HTTP 429 em .*typeaheadHits/);
    assert.ok(requests.length > 2);
  });
});

test('erros que não são 429 continuam saindo com código 1', async () => {
  await withServer((req, res) => {
    res.writeHead(500);
    res.end();
  }, async baseUrl => {
    const result = await runScript(baseUrl);
    assert.equal(result.code, 1);
    assert.doesNotMatch(result.stderr, /LINKEDIN_RATE_LIMITED/);
  });
});

test('respeita o intervalo mínimo configurado entre requisições', async () => {
  const stamps = [];
  await withServer((req, res) => {
    stamps.push(Date.now());
    if (req.url.includes('typeaheadHits')) {
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify([{ id: '1' }]));
      return;
    }
    res.writeHead(200, { 'Content-Type': 'text/html' });
    res.end('<html></html>');
  }, async baseUrl => {
    const result = await runScript(baseUrl, { LINKEDIN_JOBS_DELAY_MS: '700' });
    assert.equal(result.code, 0, result.stderr);
  });
  assert.ok(stamps.length >= 3);
  for (let i = 1; i < stamps.length; i++) {
    assert.ok(stamps[i] - stamps[i - 1] >= 650, 'intervalo entre requisições ' + i);
  }
});

test('anúncio cujo detalhe já foi consultado não gera nova requisição detalhada', async () => {
  const directory = mkdtempSync(path.join(tmpdir(), 'ti-vagas-linkedin-known-'));
  const knownFile = path.join(directory, 'known.json');
  const scanFile = path.join(directory, 'scans.jsonl');
  writeFileSync(knownFile, JSON.stringify(['123456']));
  const card = `<li>
    <div data-entity-urn="urn:li:jobPosting:123456"></div>
    <time datetime="${new Date().toISOString()}"></time>
    <h3 class="base-search-card__title">Desenvolvedor Júnior</h3>
    <h4 class="base-search-card__subtitle">Acme Tecnologia</h4>
    <span class="job-search-card__location">Brasil</span>
  </li>`;

  try {
    await withServer((req, res) => {
      if (req.url.includes('typeaheadHits')) {
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify([{ id: '1' }]));
        return;
      }
      if (req.url.includes('/jobs-guest/jobs/api/seeMoreJobPostings/search')) {
        res.writeHead(200, { 'Content-Type': 'text/html' });
        res.end(card);
        return;
      }
      res.writeHead(500);
      res.end();
    }, async (baseUrl, requests) => {
      const result = await runScript(baseUrl, {
        LINKEDIN_JOBS_KNOWN_IDS_FILE: knownFile,
        LINKEDIN_JOBS_SCAN_LOG_FILE: scanFile,
      });
      assert.equal(result.code, 0, result.stderr);
      assert.equal(result.stdout, '[]');
      assert.equal(requests.filter(url => url.includes('/jobPosting/')).length, 0);
      assert.match(result.stderr, /Detalhes já consultados e ignorados: 1/);
    });
    assert.equal(existsSync(scanFile), false, 'IDs conhecidos não devem gerar registro de nova consulta.');
  } finally {
    rmSync(directory, { recursive: true, force: true });
  }
});

test('registra o ID depois de receber a resposta detalhada mesmo quando a descrição não existe', async () => {
  const directory = mkdtempSync(path.join(tmpdir(), 'ti-vagas-linkedin-scanned-'));
  const scanFile = path.join(directory, 'scans.jsonl');
  const card = `<li>
    <div data-entity-urn="urn:li:jobPosting:654321"></div>
    <time datetime="${new Date().toISOString()}"></time>
    <h3 class="base-search-card__title">Analista de Suporte Júnior</h3>
    <h4 class="base-search-card__subtitle">Acme Tecnologia</h4>
    <span class="job-search-card__location">Brasil</span>
  </li>`;

  try {
    await withServer((req, res) => {
      if (req.url.includes('typeaheadHits')) {
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify([{ id: '1' }]));
        return;
      }
      if (req.url.includes('/jobs-guest/jobs/api/seeMoreJobPostings/search')) {
        res.writeHead(200, { 'Content-Type': 'text/html' });
        res.end(card);
        return;
      }
      if (req.url.includes('/jobs-guest/jobs/api/jobPosting/654321')) {
        res.writeHead(200, { 'Content-Type': 'text/html' });
        res.end('<html><body>Sem descrição disponível</body></html>');
        return;
      }
      res.writeHead(404);
      res.end();
    }, async (baseUrl, requests) => {
      const result = await runScript(baseUrl, { LINKEDIN_JOBS_SCAN_LOG_FILE: scanFile });
      assert.equal(result.code, 0, result.stderr);
      assert.equal(result.stdout, '[]');
      assert.equal(requests.filter(url => url.includes('/jobPosting/654321')).length, 1);
    });
    const records = readFileSync(scanFile, 'utf8').trim().split('\n').map(line => JSON.parse(line));
    assert.equal(records.length, 1);
    assert.equal(records[0].jobId, '654321');
    assert.equal(records[0].httpStatus, 200);
  } finally {
    rmSync(directory, { recursive: true, force: true });
  }
});
