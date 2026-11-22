const SEARCH_TERMS = [
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

const LOOKBACK_HOURS = Math.max(1, Number(process.env.LINKEDIN_JOBS_LOOKBACK_HOURS ?? 168));
const MAX_PAGES = Math.max(1, Number(process.env.LINKEDIN_JOBS_MAX_PAGES ?? 5));
const PAGE_SIZE = 10;
const DELAY_MS = Math.max(500, Number(process.env.LINKEDIN_JOBS_DELAY_MS ?? 1200));
const LOCATION = process.env.LINKEDIN_JOBS_LOCATION ?? 'Brasil';
const clean = value => String(value ?? '').replace(/\s+/g, ' ').trim();

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

async function request(url) {
  const response = await fetch(url, {
    headers: {
      'User-Agent': 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/140.0 Safari/537.36',
      'Accept-Language': 'pt-BR,pt;q=0.9,en;q=0.8',
      'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
    },
    signal: AbortSignal.timeout(20000),
  });

  if (response.status === 429) {
    throw new Error('LinkedIn limitou temporariamente o endpoint público (HTTP 429). A coleta foi interrompida sem tentar contornar o limite.');
  }

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

  const raw = await request('https://www.linkedin.com/jobs-guest/api/typeaheadHits?' + params);
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

async function searchJobs(term, geo) {
  const found = new Map();
  const params = new URLSearchParams({
    keywords: term,
    geoId: geo,
    f_TPR: 'r' + String(Math.round(LOOKBACK_HOURS * 3600)),
    sortBy: 'DD',
  });

  for (let page = 0; page < MAX_PAGES; page++) {
    params.set('start', String(page * PAGE_SIZE));
    const url = 'https://www.linkedin.com/jobs-guest/jobs/api/seeMoreJobPostings/search?' + params;
    const html = await request(url);
    const cards = parseCards(html);

    for (const card of cards) found.set(card.id, card);

    log('"' + term + '" | página ' + (page + 1) + ' | ' + cards.length + ' cartões únicos');

    if (cards.length < PAGE_SIZE) break;
    await new Promise(resolve => setTimeout(resolve, DELAY_MS));
  }

  return [...found.values()];
}

async function collectLinkedInJobs() {
  const scrapedAt = new Date();
  const geo = await geoId(LOCATION);
  const unique = new Map();

  log('Busca pública sem login. Localização: ' + LOCATION + ' | janela: ' + LOOKBACK_HOURS + 'h');

  for (const term of SEARCH_TERMS) {
    try {
      for (const job of await searchJobs(term, geo)) {
        if (!unique.has(job.id)) unique.set(job.id, { ...job, matchedKeywords: [term] });
        else unique.get(job.id).matchedKeywords.push(term);
      }
    } catch (error) {
      log('Pesquisa interrompida para "' + term + '": ' + error.message);
      if (/HTTP 429/.test(error.message)) throw error;
    }

    await new Promise(resolve => setTimeout(resolve, DELAY_MS));
  }

  const results = [];
  const jobs = [...unique.values()];

  log('Vagas únicas nos cartões: ' + jobs.length);

  for (const [index, job] of jobs.entries()) {
    try {
      const detailHtml = await request(
        'https://www.linkedin.com/jobs-guest/jobs/api/jobPosting/' + job.id
      );
      const description = extractDescription(detailHtml);

      if (description.length < 30) {
        log('Descrição ausente/curta; mantida fora da importação: ' + job.id);
        continue;
      }

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
      if (/HTTP 429/.test(error.message)) throw error;
    }

    if (index < jobs.length - 1) {
      await new Promise(resolve => setTimeout(resolve, DELAY_MS));
    }
  }

  log('Vagas com descrição extraída: ' + results.length + ' | coleta concluída em ' + scrapedAt.toISOString());
  return results;
}

collectLinkedInJobs()
  .then(vacancies => process.stdout.write(JSON.stringify(vacancies)))
  .catch(error => {
    log(error.stack || error.message || String(error));
    process.exitCode = 1;
  });
