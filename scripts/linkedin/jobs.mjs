const SEARCH_TERMS = [
  'desenvolvedor júnior',
  'desenvolvedor backend júnior',
  'desenvolvedor full stack júnior',
  'programador júnior',
  'analista de suporte júnior',
  'suporte técnico júnior',
  'técnico de suporte júnior',
  'infraestrutura júnior',
  'junior software engineer',
  'junior backend developer',
  'junior full stack developer',
  'technical support junior',
];

const CARD_SELECTORS = [
  'li.jobs-search-results__list-item',
  'div.job-card-container',
  'li[data-occludable-job-id]',
];

const DETAIL_SELECTORS = [
  '.jobs-search__job-details--container',
  '.job-view-layout',
  '.jobs-details',
  'main',
];

const clean = value => String(value ?? '').replace(/\\s+/g, ' ').trim();

function parseRelativeDate(value) {
  const text = clean(value).toLowerCase();
  if (!text) return null;
  const match = text.match(/(\\d+)\\s*(min|minute|minutes|h|hr|hour|hours|d|day|days|sem|week|weeks|mo|month|months)/i);
  if (!match) {
    if (/today|hoje|just now|agora/.test(text)) return new Date().toISOString();
    if (/yesterday|ontem/.test(text)) return new Date(Date.now() - 86400000).toISOString();
    return null;
  }
  const amount = Number(match[1]);
  const unit = match[2].toLowerCase();
  const factor = /^(min|minute)/.test(unit) ? 60000
    : /^(h|hr|hour)/.test(unit) ? 3600000
    : /^(d|day)/.test(unit) ? 86400000
    : /^sem|^week/.test(unit) ? 604800000
    : 2592000000;
  return new Date(Date.now() - amount * factor).toISOString();
}

async function firstText(root, selectors) {
  for (const selector of selectors) {
    const locator = root.locator(selector).first();
    const value = clean(await locator.innerText().catch(() => ''));
    if (value) return value;
  }
  return '';
}

async function firstAttribute(root, selectors, attribute) {
  for (const selector of selectors) {
    const value = await root.locator(selector).first().getAttribute(attribute).catch(() => null);
    if (value) return value;
  }
  return null;
}

async function collectCards(page) {
  for (const selector of CARD_SELECTORS) {
    const cards = page.locator(selector);
    const count = await cards.count().catch(() => 0);
    if (count > 0) return { cards, count };
  }
  return { cards: page.locator('li'), count: 0 };
}

async function extractJobDetail(page, job) {
  await page.goto(job.url, { waitUntil: 'domcontentloaded', timeout: 20000 });
  await page.waitForTimeout(900);

  const detail = page.locator(DETAIL_SELECTORS.join(', ')).last();
  const title = await firstText(detail, [
    '.job-details-jobs-unified-top-card__job-title',
    '.jobs-unified-top-card__job-title',
    'h1',
  ]) || job.title;

  const company = await firstText(detail, [
    '.job-details-jobs-unified-top-card__company-name',
    '.jobs-unified-top-card__company-name',
    '.job-details-jobs-unified-top-card__company-name a',
  ]);

  const location = await firstText(detail, [
    '.job-details-jobs-unified-top-card__primary-description-container',
    '.jobs-unified-top-card__primary-description',
    '.job-details-jobs-unified-top-card__tertiary-description-container',
  ]);

  const description = await firstText(detail, [
    '.jobs-description__content',
    '.jobs-box__html-content',
    '#job-details',
    '[class*="jobs-description-content"]',
  ]);

  const metaDate = await firstAttribute(page, [
    'meta[property="article:published_time"]',
    'meta[name="datePosted"]',
    'time[datetime]',
  ], 'content') || await firstAttribute(page, ['time[datetime]'], 'datetime');

  const dateText = await firstText(detail, [
    '.jobs-unified-top-card__subtitle-primary-grouping',
    '.job-details-jobs-unified-top-card__tertiary-description-container',
    'time',
  ]);

  let publishedAt = null;
  if (metaDate && !Number.isNaN(Date.parse(metaDate))) publishedAt = new Date(metaDate).toISOString();
  else publishedAt = parseRelativeDate(dateText);

  const applyHref = await firstAttribute(detail, [
    'a.jobs-apply-button',
    'a[data-tracking-control-name*="public_jobs_apply"]',
    'a[href*="apply"]',
  ], 'href');

  const employmentAndModel = await firstText(detail, [
    '.job-details-preferences-and-skills',
    '.jobs-unified-top-card__job-insight',
    '.job-details-jobs-unified-top-card__job-insight',
  ]);

  const fullText = await detail.innerText().catch(() => '');
  const normalized = clean([location, employmentAndModel, fullText.slice(0, 1800)].join(' ')).toLowerCase();

  const workModel = /\\b(remote|remoto|home office)\\b/.test(normalized) ? 'Remoto'
    : /\\b(hybrid|híbrido|hibrido)\\b/.test(normalized) ? 'Híbrido'
    : /\\b(on-site|onsite|presencial)\\b/.test(normalized) ? 'Presencial'
    : null;

  const employmentType = /part.?time|meio período/.test(normalized) ? 'Meio período'
    : /contract|temporário|temporario/.test(normalized) ? 'Temporário'
    : /full.?time|tempo integral/.test(normalized) ? 'Tempo integral'
    : /internship|estágio|estagio/.test(normalized) ? 'Estágio'
    : null;

  const idMatch = job.url.match(/\\/jobs\\/view\\/(\\d+)/);
  const externalId = idMatch?.[1] || job.id;
  const applicationUrl = applyHref
    ? new URL(applyHref, 'https://www.linkedin.com').href
    : job.url;

  if (!title || !company || !description || !publishedAt || !externalId) return null;

  return {
    title: title.slice(0, 255),
    company,
    description: description.slice(0, 30000),
    location: location || null,
    work_model: workModel,
    employment_type: employmentType,
    salary: null,
    application_url: applicationUrl,
    external_id: `linkedin-jobs:${externalId}`,
    original_published_at: publishedAt,
    source: 'linkedin-jobs',
    linkedin_job_url: job.url,
  };
}

/**
 * Coleta vagas pelo navegador autenticado já aberto pelo secondary-account.
 * Não cria browser, contexto nem sessão próprios.
 */
export async function collectLinkedInJobs(page) {
  const jobs = new Map();

  for (const term of SEARCH_TERMS) {
    const url = 'https://www.linkedin.com/jobs/search/?keywords='
      + encodeURIComponent(term)
      + '&location=Brazil&f_E=1%2C2&sortBy=DD';

    await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 20000 });
    await page.waitForTimeout(1300);

    for (let pageNo = 0; pageNo < 2; pageNo++) {
      const { cards, count } = await collectCards(page);
      const limit = Math.min(count, 15);

      for (let index = 0; index < limit; index++) {
        const card = cards.nth(index);
        const href = await firstAttribute(card, [
          'a.job-card-list__title',
          'a.job-card-container__link',
          'a[href*="/jobs/view/"]',
        ], 'href');
        if (!href) continue;

        const jobUrl = new URL(href, 'https://www.linkedin.com');
        jobUrl.search = '';
        jobUrl.hash = '';
        const match = jobUrl.pathname.match(/\\/jobs\\/view\\/(\\d+)/);
        if (!match || jobs.has(match[1])) continue;

        const title = await firstText(card, [
          '.job-card-list__title',
          '.job-card-container__link',
          'strong',
          'h3',
        ]);
        jobs.set(match[1], { id: match[1], url: jobUrl.href, title });
      }

      if (pageNo === 0) {
        const next = page.locator('button[aria-label*="Next"], button[aria-label*="Avançar"]').first();
        if (await next.isVisible().catch(() => false) && await next.isEnabled().catch(() => false)) {
          await next.click().catch(() => {});
          await page.waitForTimeout(900);
        } else {
          break;
        }
      }
    }
  }

  const results = [];
  for (const job of [...jobs.values()].slice(0, 80)) {
    try {
      const vacancy = await extractJobDetail(page, job);
      if (vacancy) results.push(vacancy);
    } catch (error) {
      process.stderr.write(`[LinkedIn Jobs] Falha ao extrair ${job.url}: ${error?.message ?? error}\\n`);
    }
  }

  return results;
}
