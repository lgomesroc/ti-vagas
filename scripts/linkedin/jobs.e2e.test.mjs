import test from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';
import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const script = fileURLToPath(new URL('./jobs.mjs', import.meta.url));

/**
 * Sobe um servidor local que imita o endpoint público do LinkedIn e registra
 * as requisições recebidas. `handler(req, res, count)` decide a resposta.
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
  await withServer(
    (req, res) => {
      res.writeHead(429, { 'Retry-After': '0' });
      res.end('Too Many Requests');
    },
    async (baseUrl, requests) => {
      const result = await runScript(baseUrl);

      assert.equal(result.code, 75);
      assert.equal(result.stdout, '', 'nada é entregue ao importador');
      assert.match(result.stderr, /LINKEDIN_RATE_LIMITED retry_after=0 attempts=3/);
      assert.match(result.stderr, /COLETA INTERROMPIDA/);
      assert.match(result.stderr, /pesquisas concluídas: 0\//);

      // 1 requisição inicial + 2 novas tentativas, e a coleta para por aí.
      assert.equal(requests.length, 3);
      assert.ok(requests.every(url => url.includes('typeaheadHits')));
    }
  );
});

test('Retry-After acima do teto interrompe de imediato, sem esperar nem insistir', async () => {
  await withServer(
    (req, res) => {
      res.writeHead(429, { 'Retry-After': '3600' });
      res.end();
    },
    async (baseUrl, requests) => {
      const startedAt = Date.now();
      const result = await runScript(baseUrl);

      assert.equal(result.code, 75);
      assert.match(result.stderr, /LINKEDIN_RATE_LIMITED retry_after=3600/);
      assert.equal(requests.length, 1);
      assert.ok(Date.now() - startedAt < 10000, 'não ficou esperando o Retry-After');
    }
  );
});

test('recupera-se de um 429 transitório e conclui a coleta normalmente', async () => {
  await withServer(
    (req, res, count) => {
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

      // Buscas sem cartões: nenhuma vaga, coleta termina vazia.
      res.writeHead(200, { 'Content-Type': 'text/html' });
      res.end('<html></html>');
    },
    async (baseUrl, requests) => {
      const result = await runScript(baseUrl);

      assert.equal(result.code, 0, result.stderr);
      assert.equal(result.stdout, '[]');
      assert.match(result.stderr, /HTTP 429 em .*typeaheadHits/);
      assert.ok(requests.length > 2);
    }
  );
});

test('erros que não são 429 continuam saindo com código 1', async () => {
  await withServer(
    (req, res) => {
      res.writeHead(500);
      res.end();
    },
    async baseUrl => {
      const result = await runScript(baseUrl);

      assert.equal(result.code, 1);
      assert.doesNotMatch(result.stderr, /LINKEDIN_RATE_LIMITED/);
    }
  );
});

test('respeita o intervalo mínimo configurado entre requisições', async () => {
  const stamps = [];

  await withServer(
    (req, res) => {
      stamps.push(Date.now());

      if (req.url.includes('typeaheadHits')) {
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify([{ id: '1' }]));

        return;
      }

      res.writeHead(200, { 'Content-Type': 'text/html' });
      res.end('<html></html>');
    },
    async baseUrl => {
      const result = await runScript(baseUrl, { LINKEDIN_JOBS_DELAY_MS: '700' });

      assert.equal(result.code, 0, result.stderr);
    }
  );

  assert.ok(stamps.length >= 3);

  for (let i = 1; i < stamps.length; i++) {
    // Tolerância de 50ms para a granularidade dos timers.
    assert.ok(stamps[i] - stamps[i - 1] >= 650, 'intervalo entre requisições ' + i);
  }
});
