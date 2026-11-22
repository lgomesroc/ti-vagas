import test from 'node:test';
import assert from 'node:assert/strict';

import {
  RateLimitError,
  computeBackoffMs,
  createThrottle,
  loadRateLimitConfig,
  parseRetryAfter,
  requestWithBackoff,
} from './rate-limit.mjs';

const config = {
  requestDelayMs: 5000,
  panelDelayMs: 1500,
  jitterMs: 0,
  maxRetries: 3,
  baseBackoffMs: 1000,
  maxBackoffMs: 8000,
};

test('loadRateLimitConfig usa padrões conservadores e aceita variáveis de ambiente', () => {
  const defaults = loadRateLimitConfig({});

  assert.equal(defaults.requestDelayMs, 5000);
  assert.equal(defaults.maxRetries, 3);
  assert.equal(defaults.baseBackoffMs, 30000);

  const custom = loadRateLimitConfig({
    LINKEDIN_REQUEST_DELAY_MS: '9000',
    LINKEDIN_RATE_LIMIT_MAX_RETRIES: '1',
    LINKEDIN_PANEL_DELAY_MS: 'abc',
  });

  assert.equal(custom.requestDelayMs, 9000);
  assert.equal(custom.maxRetries, 1);
  assert.equal(custom.panelDelayMs, 1500, 'valor inválido volta ao padrão');
});

test('parseRetryAfter lê segundos, data HTTP e rejeita valores inválidos', () => {
  assert.equal(parseRetryAfter('120'), 120000);
  assert.equal(parseRetryAfter(undefined), null);
  assert.equal(parseRetryAfter(''), null);
  assert.equal(parseRetryAfter('abc'), null);

  const now = Date.parse('2026-11-22T10:00:00Z');

  assert.equal(parseRetryAfter('Sun, 22 Nov 2026 10:01:00 GMT', now), 60000);
  assert.equal(parseRetryAfter('Sun, 22 Nov 2026 09:00:00 GMT', now), 0);
});

test('computeBackoffMs dobra a cada tentativa, respeita o teto e prioriza Retry-After maior', () => {
  assert.equal(computeBackoffMs(1, config), 1000);
  assert.equal(computeBackoffMs(2, config), 2000);
  assert.equal(computeBackoffMs(3, config), 4000);
  assert.equal(computeBackoffMs(5, config), 8000, 'limitado ao teto');
  assert.equal(computeBackoffMs(1, config, 5000), 5000, 'Retry-After maior prevalece');
  assert.equal(computeBackoffMs(3, config, 500), 4000, 'Retry-After menor não reduz o backoff');
});

test('requestWithBackoff devolve o valor sem esperar quando não há 429', async () => {
  const sleeps = [];

  const value = await requestWithBackoff(
    async () => ({ limited: false, value: 'ok' }),
    config,
    { sleep: async ms => sleeps.push(ms) }
  );

  assert.equal(value, 'ok');
  assert.deepEqual(sleeps, []);
});

test('requestWithBackoff espera em backoff exponencial e se recupera', async () => {
  const sleeps = [];
  let calls = 0;

  const value = await requestWithBackoff(
    async () => {
      calls++;

      return calls < 3
        ? { limited: true, retryAfter: null }
        : { limited: false, value: 'recuperado' };
    },
    config,
    { sleep: async ms => sleeps.push(ms) }
  );

  assert.equal(value, 'recuperado');
  assert.equal(calls, 3);
  assert.deepEqual(sleeps, [1000, 2000]);
});

test('requestWithBackoff respeita Retry-After quando maior que o backoff', async () => {
  const sleeps = [];
  let calls = 0;

  await requestWithBackoff(
    async () => {
      calls++;

      return calls === 1
        ? { limited: true, retryAfter: '6' }
        : { limited: false, value: 'ok' };
    },
    config,
    { sleep: async ms => sleeps.push(ms) }
  );

  assert.deepEqual(sleeps, [6000]);
});

test('requestWithBackoff lança RateLimitError após esgotar as tentativas', async () => {
  const sleeps = [];
  let calls = 0;

  await assert.rejects(
    requestWithBackoff(
      async () => {
        calls++;

        return { limited: true, retryAfter: '3' };
      },
      config,
      { sleep: async ms => sleeps.push(ms) }
    ),
    error => {
      assert.ok(error instanceof RateLimitError);
      assert.equal(error.retryAfterSeconds, 3);
      assert.equal(error.attempts, 4);

      return true;
    }
  );

  // 1 requisição inicial + 3 novas tentativas, nunca mais que isso.
  assert.equal(calls, 4);
  assert.equal(sleeps.length, 3);
});

test('requestWithBackoff não espera dentro do processo se Retry-After excede o teto', async () => {
  const sleeps = [];

  await assert.rejects(
    requestWithBackoff(
      async () => ({ limited: true, retryAfter: '3600' }),
      config,
      { sleep: async ms => sleeps.push(ms) }
    ),
    error => {
      assert.ok(error instanceof RateLimitError);
      assert.equal(error.retryAfterSeconds, 3600);

      return true;
    }
  );

  assert.deepEqual(sleeps, [], 'a espera longa fica para o reagendamento do job');
});

test('createThrottle impõe o intervalo mínimo entre chamadas', async () => {
  let clock = 1000;
  const sleeps = [];

  const throttle = createThrottle(
    { ...config, jitterMs: 0 },
    {
      now: () => clock,
      sleep: async ms => {
        sleeps.push(ms);
        clock += ms;
      },
      random: () => 0,
    }
  );

  await throttle.wait(5000); // primeira chamada: sem espera
  clock += 2000;
  await throttle.wait(5000); // faltam 3000ms
  await throttle.wait(5000); // intervalo completo

  assert.deepEqual(sleeps, [3000, 5000]);
});

test('collectLinkedInJobs interrompe de forma controlada após 429 persistente', async () => {
  process.env.LINKEDIN_REQUEST_DELAY_MS = '0';
  process.env.LINKEDIN_PANEL_DELAY_MS = '0';
  process.env.LINKEDIN_REQUEST_JITTER_MS = '0';
  process.env.LINKEDIN_RATE_LIMIT_MAX_RETRIES = '2';
  process.env.LINKEDIN_RATE_LIMIT_BASE_BACKOFF_MS = '1';
  process.env.LINKEDIN_RATE_LIMIT_MAX_BACKOFF_MS = '5';

  const { collectLinkedInJobs } = await import('./jobs.mjs');

  let gotoCalls = 0;
  let listeners = 0;

  const page = {
    on: () => { listeners++; },
    off: () => { listeners--; },
    goto: async () => {
      gotoCalls++;

      return {
        status: () => 429,
        headers: () => ({ 'retry-after': '0' }),
      };
    },
  };

  await assert.rejects(
    collectLinkedInJobs(page),
    error => error instanceof RateLimitError
  );

  // 1 requisição inicial + 2 novas tentativas, e a coleta inteira para aí:
  // não segue para as demais pesquisas nem para outras páginas.
  assert.equal(gotoCalls, 3);
  assert.equal(listeners, 0, 'o observador de respostas é removido');
});
