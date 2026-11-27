import { appendFileSync, readFileSync } from 'node:fs';
import { pathToFileURL } from 'node:url';

import {
  RATE_LIMIT_EXIT_CODE,
  RATE_LIMIT_MARKER,
  RateLimitError,
  createThrottle,
  loadRateLimitConfig,
  requestWithBackoff,
} from './rate-limit.mjs';
import { createTimeBudget } from './runtime.mjs';

const DEFAULT_SEARCH_TERMS = [
  'desenvolvedor júnior',
  'desenvolvedor backend júnior',
  'desenvolvedor frontend júnior',
  'desenvolvedor full stack júnior',
  'programador júnior',
  'analista de suporte júnior',
  'técnico de suporte júnior',
  'help desk júnior',
  'service desk júnior',
  'infraestrutura júnior',
  'analista de redes júnior',
  'analista de QA júnior',
  'junior software engineer',
  'junior developer',
  'junior IT support',
];
const SEARCH_TERMS = (process.env.LINKEDIN_JOBS_SEARCH_TERMS ?? '')
  .split(';').map(value => value.trim()).filter(Boolean);
if (SEARCH_TERMS.length === 0) SEARCH_TERMS.push(...DEFAULT_SEARCH_TERMS);
const COMPANY_FILTER = (process.env.LINKEDIN_JOBS_COMPANY_FILTER ?? '').trim().toLocaleLowerCase();

const LOOKBACK_HOURS = Math.max(1, Number(process.env.LINKEDIN_JOBS_LOOKBACK_HOURS ?? 168));
const MAX_PAGES = Math.max(1, Number(process.env.LINKEDIN_JOBS_MAX_PAGES ?? 5));
const PAGE_SIZE = 10;
const LOCATION = process.env.LINKEDIN_JOBS_LOCATION ?? 'Brasil';
// Permite apontar para um servidor local nos testes; em produção é o LinkedIn.
const BASE_URL = (process.env.LINKEDIN_JOBS_BASE_URL ?? 'https://www.linkedin.com').replace(/\/+$/, '');
const RATE_LIMIT = loadRateLimitConfig();
const throttle = createThrottle(RATE_LIMIT);
// Tempo total da coleta (segundos). Ao esgotar, devolve o que já foi coletado
// em vez de ser morto pelo timeout do worker e perder tudo. 0 = sem limite.
const TIME_BUDGET_SECONDS = Number(process.env.LINKEDIN_JOBS_TIME_BUDGET_SECONDS ?? 900);
// Fração do orçamento reservada às pesquisas; o restante vai para os detalhes.
const SEARCH_BUDGET_SHARE = 0.4;
const KNOWN_IDS_FILE = process.env.LINKEDIN_JOBS_KNOWN_IDS_FILE;
const SCAN_LOG_FILE = process.env.LINKEDIN_JOBS_SCAN_LOG_FILE;

function loadKnownJobIds() {
  if (!KNOWN_IDS_FILE) return new Set();
  try {
    const values = JSON.parse(readFileSync(KNOWN_IDS_FILE, 'utf8'));
    return new Set(Array.isArray(values) ? values.map(String) : []);
  } catch (error) {
    throw new Error('Não foi possível ler a lista de anúncios já consultados: ' + error.message);
  }
}

const knownJobIds = loadKnownJobIds();

function recordConsultedJob(id, httpStatus) {
  if (!SCAN_LOG_FILE) return;
  appendFileSync(
    SCAN_LOG_FILE,
    JSON.stringify({ jobId: String(id), httpStatus, consultedAt: new Date().toISOString() }) + '\n',
    'utf8'
  );
}
const clean = value => String(value ?? '').replace(/\s+/g, ' ').trim();

// Contadores para diferenciar o que foi alcançado caso a coleta seja interrompida.
const progress = {
  searchesDone: 0,
  uniqueCards: 0,
  detailsRequested: 0,
  withDescription: 0,
};

function log(message) {
  process.stderr.write('[LinkedIn Jobs público] ' + message + '\n');
}

function decodeEntities(value) {
  return String(value ?? '')
    .replace(/&nbsp;/gi, ' ')
    .replace(/&amp;/gi, '&')
    .replace(/&quot;/gi, '"')
    .replace(/&#39;|&apos;/gi, "'")
    .replace(/&lt;/gi, '<')
    .replace(/&gt;/gi, '>')
    .replace(/&#(\d+);/g, (_, code) => String.fromCodePoint(Number(code)))
    .replace(/&#x([0-9a-f]+);/gi, (_, code) => String.fromCodePoint(parseInt(code, 16)));
}

function htmlText(value) {
  return clean(decodeEntities(
    String(value ?? '')
      .replace(/<\s*(br|\/p|\/li|\/ul|\/ol|\/h[1-6]|\/div)\s*\/?>/gi, '\n')
      .replace(/<li[^>]*>/gi, '• ')
      .replace(/<[^>]*>/g, ' ')
  )).replace(/\s*\n\s*/g, '\n').trim();
}

function matchText(html, regex) {
  const match = regex.exec(html);
  return match ? htmlText(match[1]) : '';
}

async function request(url, { onResponse = null } = {}) {
  const path = new URL(url).pathname;

  // Intervalo mínimo entre requisições, backoff exponencial para 429 e
  // Retry-After respeitado. Esgotadas as tentativas, lança RateLimitError.
  const response = await requestWithBackoff(
    async () => {
      await throttle.wait();

      const res = await fetch(url, {
        headers: {
          'User-Agent': 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/140.0 Safari/537.36',
          'Accept-Language': 'pt-BR,pt;q=0.9,en;q=0.8',
          'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        },
        signal: AbortSignal.timeout(20000),
      });

      const limited = res.status === 429;

      if (limited) {
        await res.body?.cancel().catch(() => {});
      }

      return {
        limited,
        retryAfter: res.headers.get('retry-after'),
        value: res,
      };
    },
    RATE_LIMIT,
    { log, label: path }
  );

  onResponse?.(response.status);

  if (!response.ok) {
    throw new Error('LinkedIn respondeu HTTP ' + response.status + ' em ' + url);
  }

  return response.text();
}

async function geoId(location) {
  if (/^\d+$/.test(location)) return location;

  const params = new URLSearchParams({
    origin: 'jserp',
    typeaheadType: 'GEO',
    query: location,
    geoTypes: 'POPULATED_PLACE,ADMIN_DIVISION_2,MARKET_AREA,COUNTRY_REGION',
  });

  const raw = await request(BASE_URL + '/jobs-guest/api/typeaheadHits?' + params);
  const hits = JSON.parse(raw);

  if (!Array.isArray(hits) || !hits[0]?.id) {
    throw new Error('O LinkedIn não retornou geoId para a localização: ' + location);
  }

  return String(hits[0].id);
}

function parseCards(page) {
  const cards = [];
  const fragments = page.match(/<li\b[\s\S]*?<\/li>/gi) ?? [];

  for (const card of fragments) {
    const id = card.match(/data-entity-urn="urn:li:jobPosting:(\d+)"/i)?.[1];
    if (!id) continue;

    const dateMatch = card.match(/<time\b[^>]*datetime="([^"]+)"/i);
    const publishedAt = dateMatch?.[1] && !Number.isNaN(Date.parse(dateMatch[1]))
      ? new Date(dateMatch[1]).toISOString()
      : null;

    const title = matchText(card, /<h3\b[^>]*class="[^"]*base-search-card__title[^"]*"[^>]*>([\s\S]*?)<\/h3>/i);
    const company = matchText(card, /<h4\b[^>]*class="[^"]*base-search-card__subtitle[^"]*"[^>]*>([\s\S]*?)<\/h4>/i);
    const location = matchText(card, /<span\b[^>]*class="[^"]*job-search-card__location[^"]*"[^>]*>([\s\S]*?)<\/span>/i);
    const salary = matchText(card, /<span\b[^>]*class="[^"]*job-search-card__salary-info[^"]*"[^>]*>([\s\S]*?)<\/span>/i);

    if (!title || !company || !publishedAt) continue;

    cards.push({
      id,
      title,
      company,
      location,
      salary: salary || null,
      publishedAt,
      url: 'https://www.linkedin.com/jobs/view/' + id + '/',
    });
  }

  return cards;
}

function extractDescription(page) {
  const description = page.match(
    /<div\b[^>]*class="[^"]*show-more-less-html__markup[^"]*"[^>]*>([\s\S]*?)<\/div>\s*(?:<button|<\/section>)/i
  );

  return description ? htmlText(description[1]).slice(0, 30000) : '';
}

function extractApplicationUrl(page, fallback) {
  const anchors = page.match(/<a\b[^>]*href="([^"]+)"[^>]*>[\s\S]*?<\/a>/gi) ?? [];

  for (const anchor of anchors) {
    const label = htmlText(anchor);
    const tracking = anchor.match(/data-tracking-control-name="([^"]+)"/i)?.[1] ?? '';

    if (!/apply|candidatar|inscrever/i.test(label + ' ' + tracking)) continue;

    const href = anchor.match(/href="([^"]+)"/i)?.[1];
    if (!href) continue;

    const decoded = decodeEntities(href);
    try {
      const url = new URL(decoded, 'https://www.linkedin.com');
      const redirect = url.searchParams.get('url');
      return redirect ? new URL(redirect, url).toString() : url.toString();
    } catch {
      continue;
    }
  }

  return fallback;
}

function classifyWorkModel(text) {
  const value = text.toLowerCase();
  if (/\b(remote|remoto|home office)\b/.test(value)) return 'Remoto';
  if (/\b(hybrid|h[ií]brido)\b/.test(value)) return 'Híbrido';
  if (/\b(on.site|onsite|presencial)\b/.test(value)) return 'Presencial';
  return null;
}

function classifyEmployment(text) {
  const value = text.toLowerCase();
  if (/\b(part.time|meio per[ií]odo)\b/.test(value)) return 'Meio período';
  if (/\b(contract|tempor[aá]rio|temporary|contrato)\b/.test(value)) return 'Temporário';
  if (/\b(full.time|tempo integral)\b/.test(value)) return 'Tempo integral';
  return null;
}

async function searchJobs(term, geo, shouldStop = () => false) {
  const found = new Map();
  const params = new URLSearchParams({
    keywords: term,
    geoId: geo,
    f_TPR: 'r' + String(Math.round(LOOKBACK_HOURS * 3600)),
    sortBy: 'DD',
  });

  for (let page = 0; page < MAX_PAGES; page++) {
    if (shouldStop()) break;

    params.set('start', String(page * PAGE_SIZE));
    const url = BASE_URL + '/jobs-guest/jobs/api/seeMoreJobPostings/search?' + params;
    const html = await request(url);
    const cards = parseCards(html);

    for (const card of cards) found.set(card.id, card);

    log('"' + term + '" | página ' + (page + 1) + ' | ' + cards.length + ' cartões únicos');

    if (cards.length < PAGE_SIZE) break;
  }

  return [...found.values()];
}

export async function collectLinkedInJobs() {
  const scrapedAt = new Date();
  const budget = createTimeBudget(TIME_BUDGET_SECONDS);
  const searchDeadlineMs = Number.isFinite(budget.limitMs)
    ? budget.limitMs * SEARCH_BUDGET_SHARE
    : Infinity;
  const searchExpired = () => budget.elapsedMs() >= searchDeadlineMs;
  const geo = await geoId(LOCATION);
  const unique = new Map();

  log(
    'Busca pública sem login. Localização: ' + LOCATION + ' | janela: ' + LOOKBACK_HOURS + 'h | ' +
    'empresa: ' + (COMPANY_FILTER || 'todas') + ' | termos: ' + SEARCH_TERMS.length + ' | ' +
    'intervalo mínimo: ' + RATE_LIMIT.requestDelayMs + 'ms | orçamento: ' +
    (TIME_BUDGET_SECONDS > 0 ? TIME_BUDGET_SECONDS + 's' : 'sem limite') + ' | até ' +
    RATE_LIMIT.maxRetries + ' novas tentativas por 429'
  );

  for (const term of SEARCH_TERMS) {
    if (searchExpired()) {
      log('Orçamento de tempo das pesquisas esgotado; pulando os termos restantes a partir de "' + term + '".');
      break;
    }

    try {
      for (const job of await searchJobs(term, geo, searchExpired)) {
        if (COMPANY_FILTER && !job.company.toLocaleLowerCase().includes(COMPANY_FILTER)) continue;
        if (!unique.has(job.id)) unique.set(job.id, { ...job, matchedKeywords: [term] });
        else unique.get(job.id).matchedKeywords.push(term);
      }

      progress.searchesDone++;
      progress.uniqueCards = unique.size;
    } catch (error) {
      log('Pesquisa interrompida para "' + term + '": ' + error.message);
      if (error instanceof RateLimitError) throw error;
    }
  }

  const results = [];
  const discoveredJobs = [...unique.values()];
  // Mais recentes primeiro: se o tempo acabar, o que fica para a próxima execução
  // são as vagas mais antigas.
  const jobs = discoveredJobs
    .filter(job => !knownJobIds.has(String(job.id)))
    .sort((a, b) => Date.parse(b.publishedAt) - Date.parse(a.publishedAt));

  log('Vagas únicas nos cartões: ' + discoveredJobs.length);
  log('Detalhes já consultados e ignorados: ' + (discoveredJobs.length - jobs.length));
  log('Novos detalhes que serão consultados: ' + jobs.length);

  for (const job of jobs) {
    if (budget.expired()) {
      log(
        'Orçamento de tempo esgotado (' + TIME_BUDGET_SECONDS + 's). Detalhes consultados: ' +
        progress.detailsRequested + '/' + jobs.length +
        '. O restante ficará para a próxima execução.'
      );
      break;
    }

    try {
      progress.detailsRequested++;

      const detailHtml = await request(
        BASE_URL + '/jobs-guest/jobs/api/jobPosting/' + job.id,
        { onResponse: status => recordConsultedJob(job.id, status) }
      );
      const description = extractDescription(detailHtml);

      if (description.length < 30) {
        log('Descrição ausente/curta; mantida fora da importação: ' + job.id);
        continue;
      }

      progress.withDescription++;

      const combined = job.location + ' ' + description;
      results.push({
        title: job.title.slice(0, 255),
        company: job.company,
        description,
        location: job.location || null,
        work_model: classifyWorkModel(combined),
        employment_type: classifyEmployment(combined),
        salary: job.salary,
        application_url: extractApplicationUrl(detailHtml, job.url),
        external_id: 'linkedin-jobs:' + job.id,
        original_published_at: job.publishedAt,
        source: 'linkedin-jobs',
        linkedin_job_url: job.url,
        matched_keywords: [...new Set(job.matchedKeywords)],
      });
    } catch (error) {
      log('Falha ao consultar detalhe ' + job.id + ': ' + error.message);
      if (error instanceof RateLimitError) throw error;
    }
  }

  log('Vagas com descrição extraída: ' + results.length + ' | coleta concluída em ' + scrapedAt.toISOString());
  return results;
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  collectLinkedInJobs()
    .then(vacancies => process.stdout.write(JSON.stringify(vacancies)))
    .catch(error => {
      if (error instanceof RateLimitError) {
        log(
          'COLETA INTERROMPIDA por limitação do LinkedIn (HTTP 429). Motivo: ' + error.message +
          ' | pesquisas concluídas: ' + progress.searchesDone + '/' + SEARCH_TERMS.length +
          ' | vagas únicas nos cartões: ' + progress.uniqueCards +
          ' | detalhes consultados: ' + progress.detailsRequested +
          ' | com descrição extraída: ' + progress.withDescription +
          '. Nova coleta deve ocorrer em execução posterior.'
        );
        process.stderr.write(
          RATE_LIMIT_MARKER + ' retry_after=' + (error.retryAfterSeconds ?? '') +
          ' attempts=' + error.attempts + '\n'
        );
        process.exitCode = RATE_LIMIT_EXIT_CODE;
        return;
      }

      log(error.stack || error.message || String(error));
      process.exitCode = 1;
    });
}
