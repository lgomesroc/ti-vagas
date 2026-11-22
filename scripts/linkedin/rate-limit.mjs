/**
 * Controle conservador de ritmo de requisições para a coleta do LinkedIn Jobs.
 *
 * Este módulo apenas espaça as chamadas e reage ao HTTP 429 com espera
 * crescente, respeitando Retry-After. Ele NÃO tenta contornar o limite:
 * quando o LinkedIn continua respondendo 429, a coleta é interrompida e
 * o chamador decide reagendar para uma execução posterior.
 */

/** Código de saída (EX_TEMPFAIL) que sinaliza limitação temporária. */
export const RATE_LIMIT_EXIT_CODE = 75;

/** Marcador escrito em stderr e lido pelo Laravel. */
export const RATE_LIMIT_MARKER = 'LINKEDIN_RATE_LIMITED';

export class RateLimitError extends Error {
  constructor(message, { retryAfterSeconds = null, attempts = 0, url = null } = {}) {
    super(message);

    this.name = 'RateLimitError';
    this.code = RATE_LIMIT_MARKER;
    this.retryAfterSeconds = retryAfterSeconds;
    this.attempts = attempts;
    this.url = url;
  }
}

function intFromEnv(env, name, fallback) {
  const raw = env[name];

  if (raw === undefined || raw === null || String(raw).trim() === '') {
    return fallback;
  }

  const value = Number.parseInt(String(raw), 10);

  return Number.isFinite(value) && value >= 0 ? value : fallback;
}

export function loadRateLimitConfig(env = process.env) {
  return {
    // Intervalo mínimo entre navegações de busca.
    requestDelayMs: intFromEnv(env, 'LINKEDIN_REQUEST_DELAY_MS', 5000),
    // Intervalo mínimo entre aberturas de painel de vaga.
    panelDelayMs: intFromEnv(env, 'LINKEDIN_PANEL_DELAY_MS', 1500),
    // Variação aleatória somada aos intervalos, para não gerar ritmo exato.
    jitterMs: intFromEnv(env, 'LINKEDIN_REQUEST_JITTER_MS', 1000),
    // Quantidade de novas tentativas após um 429 (além da requisição inicial).
    maxRetries: intFromEnv(env, 'LINKEDIN_RATE_LIMIT_MAX_RETRIES', 3),
    // Espera da primeira nova tentativa; dobra a cada tentativa seguinte.
    baseBackoffMs: intFromEnv(env, 'LINKEDIN_RATE_LIMIT_BASE_BACKOFF_MS', 30000),
    // Teto do backoff exponencial e da espera aceitável dentro do processo.
    maxBackoffMs: intFromEnv(env, 'LINKEDIN_RATE_LIMIT_MAX_BACKOFF_MS', 300000),
  };
}

/**
 * Interpreta o cabeçalho Retry-After (segundos ou data HTTP).
 * Retorna milissegundos ou null quando ausente/inválido.
 */
export function parseRetryAfter(value, now = Date.now()) {
  if (value === undefined || value === null) return null;

  const text = String(value).trim();

  if (!text) return null;

  if (/^\d+$/.test(text)) {
    return Number.parseInt(text, 10) * 1000;
  }

  const date = Date.parse(text);

  if (Number.isNaN(date)) return null;

  return Math.max(0, date - now);
}

/**
 * Espera antes da nova tentativa número `attempt` (1 = primeira nova tentativa).
 * Backoff exponencial limitado ao teto; Retry-After, quando maior, prevalece.
 */
export function computeBackoffMs(attempt, config, retryAfterMs = null) {
  const exponential = Math.min(
    config.maxBackoffMs,
    config.baseBackoffMs * 2 ** Math.max(0, attempt - 1)
  );

  return Math.max(exponential, retryAfterMs ?? 0);
}

const defaultSleep = ms => new Promise(resolve => setTimeout(resolve, ms));

/**
 * Garante um intervalo mínimo entre chamadas consecutivas.
 */
export function createThrottle(config, {
  now = Date.now,
  sleep = defaultSleep,
  random = Math.random,
} = {}) {
  let lastAt = null;

  return {
    async wait(minIntervalMs) {
      if (lastAt !== null) {
        const jitter = Math.floor(random() * (config.jitterMs + 1));
        const remaining = lastAt + minIntervalMs + jitter - now();

        if (remaining > 0) {
          await sleep(remaining);
        }
      }

      lastAt = now();
    },
  };
}

/**
 * Executa `send` com backoff exponencial em caso de 429.
 *
 * `send(attempt)` deve retornar { limited, retryAfter, value }.
 * Retorna `value` na primeira resposta que não for 429. Lança RateLimitError
 * quando as tentativas se esgotam ou quando o Retry-After pede uma espera
 * maior do que o teto aceitável dentro do processo.
 */
export async function requestWithBackoff(send, config, {
  sleep = defaultSleep,
  now = Date.now,
  log = () => {},
  label = 'requisição',
} = {}) {
  for (let attempt = 1; ; attempt++) {
    const outcome = await send(attempt);

    if (!outcome.limited) {
      return outcome.value;
    }

    const retryAfterMs = parseRetryAfter(outcome.retryAfter, now());
    const retriesDone = attempt - 1;

    if (retriesDone >= config.maxRetries) {
      throw new RateLimitError(
        'HTTP 429 persistente em ' + label + ' após ' + attempt + ' tentativa(s).',
        {
          retryAfterSeconds: retryAfterMs === null ? null : Math.ceil(retryAfterMs / 1000),
          attempts: attempt,
        }
      );
    }

    const delayMs = computeBackoffMs(attempt, config, retryAfterMs);

    if (delayMs > config.maxBackoffMs) {
      throw new RateLimitError(
        'HTTP 429 em ' + label + ' com Retry-After de ' +
        Math.ceil(delayMs / 1000) + 's, acima do limite de espera do processo.',
        {
          retryAfterSeconds: Math.ceil(delayMs / 1000),
          attempts: attempt,
        }
      );
    }

    log(
      'HTTP 429 em ' + label + ' (tentativa ' + attempt + '/' +
      (config.maxRetries + 1) + '). Aguardando ' +
      Math.round(delayMs / 1000) + 's' +
      (retryAfterMs === null ? '' : ' (Retry-After respeitado)') + '.'
    );

    await sleep(delayMs);
  }
}
