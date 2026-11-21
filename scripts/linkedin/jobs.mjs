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

const LOOKBACK_HOURS = 72;
const MAX_PAGES_PER_SEARCH = 20;
const PAGE_SIZE = 25;

const clean = value => String(value ?? '').replace(/\s+/g, ' ').trim();

function normalize(value) {
  return clean(value).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
}

function log(message) {
  process.stderr.write(`[LinkedIn Jobs] ${message}\n`);
}

function parseRelativeDate(value) {
  const text = normalize(value);
  if (!text) return null;

  if (/\b(agora|just now|hoje|today)\b/.test(text)) {
    return new Date();
  }

  const match = text.match(
    /(?:ha\s*)?(\d+)\s*(min|minuto|minutos|minute|minutes|h|hr|hrs|hora|horas|hour|hours|d|dia|dias|day|days|sem|semana|semanas|week|weeks|mo|mes|meses|month|months)\b/i
  );

  if (!match) return null;

  const amount = Number(match[1]);
  const unit = match[2].toLowerCase();

  const factor =
    /^(min|minuto|minute)/.test(unit) ? 60000 :
    /^(h|hr|hora|hour)/.test(unit) ? 3600000 :
    /^(d|dia|day)/.test(unit) ? 86400000 :
    /^(sem|week)/.test(unit) ? 604800000 :
    2592000000;

  return new Date(Date.now() - amount * factor);
}

function isWithinWindow(date) {
  if (!date || Number.isNaN(date.getTime())) return false;

  const age = Date.now() - date.getTime();
  return age >= 0 && age <= LOOKBACK_HOURS * 3600000;
}

function isJuniorTitle(title) {
  const value = normalize(title);

  if (/\b(pleno|mid.level|midlevel|senior|sr|staff|principal|lead|manager|gerente|diretor|head|especialista|coordenador|supervisor)\b/.test(value)) {
    return false;
  }

  if (/\b(estagio|trainee|intern|internship|apprentice)\b/.test(value)) {
    return false;
  }

  return /\b(junior|jr)\b/.test(value);
}

function isTechnologyTitle(title) {
  const value = normalize(title);

  const terms = [
    'desenvolvedor', 'developer', 'programador',
    'software engineer', 'engenheiro de software',
    'backend', 'back-end', 'frontend', 'front-end',
    'full stack', 'fullstack', 'php', '.net', 'java',
    'python', 'javascript', 'typescript',
    'analista de sistemas', 'analista de desenvolvimento',
    'suporte', 'help desk', 'helpdesk', 'service desk',
    'infraestrutura', 'infrastructure', 'redes', 'network',
    'devops', 'sysadmin', 'qa', 'quality assurance',
    'analista de testes', 'technical support', 'it support',
  ];

  return terms.some(term => value.includes(normalize(term)));
}

function extractCardSummary(text, id) {
  const lines = String(text)
    .split('\n')
    .map(clean)
    .filter(Boolean)
    .map(line => line.replace(/^selecionada,\s*/i, ''));

  if (!lines.length) return null;

  const titleIndex = lines.findIndex(line =>
    isJuniorTitle(line) && isTechnologyTitle(line)
  );

  if (titleIndex < 0) return null;

  const title = clean(lines[titleIndex]).replace(/\s*\(vaga verificada\)/i, '');
  const company = lines[titleIndex + 1] || '';
  const location = lines[titleIndex + 2] || '';

  const dateText = lines.find(line =>
    /\b(anunciada|posted|ha\s*\d|just now|agora|hoje|today)\b/i.test(normalize(line))
  ) || '';

  return {
    id,
    title,
    company,
    location,
    dateText,
    publishedAt: parseRelativeDate(dateText),
  };
}

async function getCurrentCards(page) {
  return page.locator('[componentkey^="job-card-component-ref-"]').evaluateAll(elements => {
    const unique = new Map();

    for (const element of elements) {
      const key = element.getAttribute('componentkey') || '';
      const match = key.match(/job-card-component-ref-(\d+)/);

      if (!match) continue;

      const id = match[1];

      if (!unique.has(id)) {
        unique.set(id, {
          id,
          text: element.innerText || '',
        });
      }
    }

    return [...unique.values()];
  });
}

async function openSearchPage(page, term, pageNo) {
  const searchUrl = new URL('https://www.linkedin.com/jobs/search/');

  searchUrl.searchParams.set('keywords', term);
  searchUrl.searchParams.set('location', 'Brazil');
  searchUrl.searchParams.set('f_E', '1,2');
  searchUrl.searchParams.set('f_TPR', 'r259200');
  searchUrl.searchParams.set('sortBy', 'DD');
  searchUrl.searchParams.set('start', String((pageNo - 1) * PAGE_SIZE));

  log(`Abrindo página ${pageNo} da pesquisa: ${term}`);

  await page.goto(searchUrl.toString(), {
    waitUntil: 'domcontentloaded',
    timeout: 45000,
  });

  await page.locator('[componentkey^="job-card-component-ref-"]')
    .first()
    .waitFor({ state: 'attached', timeout: 15000 })
    .catch(() => {});

  await page.waitForTimeout(1800);

  return getCurrentCards(page);
}

async function firstText(page, selectors) {
  for (const selector of selectors) {
    const locator = page.locator(selector).first();
    const value = clean(await locator.innerText().catch(() => ''));

    if (value) return value;
  }

  return '';
}

function extractDescriptionFromBody(bodyText) {
  const text = String(bodyText || '');

  const markers = [
    /sobre a vaga/i,
    /about the job/i,
    /descri[cç][aã]o da vaga/i,
    /job description/i,
  ];

  const endMarkers = [
    /sobre a empresa/i,
    /about the company/i,
    /compet[eê]ncias/i,
    /see less/i,
  ];

  let start = -1;

  for (const marker of markers) {
    const match = marker.exec(text);
    if (match && (start < 0 || match.index < start)) {
      start = match.index + match[0].length;
    }
  }

  if (start < 0) return '';

  const remaining = text.slice(start);
  let end = remaining.length;

  for (const marker of endMarkers) {
    const match = marker.exec(remaining);
    if (match && match.index > 0 && match.index < end) {
      end = match.index;
    }
  }

  return clean(remaining.slice(0, end));
}

function resolveApplicationUrl(href) {
  if (!href) return null;

  try {
    const parsed = new URL(href);
    const redirected = parsed.searchParams.get('url');

    if (redirected) {
      try {
        return new URL(redirected).toString();
      } catch {
        return redirected;
      }
    }

    return href;
  } catch {
    return href;
  }
}

async function extractApplyUrl(page) {
  const href = await page.locator('a[href]').evaluateAll(links => {
    const link = links.find(element => {
      const text = (element.innerText || element.textContent || '').trim();
      return /candidatar-se|apply|easy apply/i.test(text) &&
        element.offsetParent !== null;
    });

    return link?.href || null;
  }).catch(() => null);

  return resolveApplicationUrl(href);
}

async function extractJobDetail(page, job) {
  const url = `https://www.linkedin.com/jobs/view/${job.id}/`;

  log(`Abrindo detalhes da vaga ${job.id}: ${job.title}`);

  await page.goto(url, {
    waitUntil: 'domcontentloaded',
    timeout: 45000,
  });

  await page.waitForTimeout(1800);

  const title = await firstText(page, [
    '.job-details-jobs-unified-top-card__job-title',
    '.jobs-unified-top-card__job-title',
    'h1',
  ]) || job.title;

  const company = await firstText(page, [
    '.job-details-jobs-unified-top-card__company-name',
    '.jobs-unified-top-card__company-name',
    'a[href*="/company/"]',
  ]) || job.company;

  const location = await firstText(page, [
    '.job-details-jobs-unified-top-card__primary-description-container',
    '.jobs-unified-top-card__primary-description',
    '.job-details-jobs-unified-top-card__tertiary-description-container',
  ]) || job.location;

  let description = await firstText(page, [
    '.jobs-description__content',
    '.jobs-box__html-content',
    '#job-details',
    '[class*="jobs-description-content"]',
    '[class*="jobs-description"]',
  ]);

  const bodyText = await page.locator('body').innerText().catch(() => '');

  if (!description) {
    description = extractDescriptionFromBody(bodyText);
  }

  if (!description || description.length < 40) {
    log(`Descrição não localizada para ${job.id}.`);
    return null;
  }

  const applyHref = await extractApplyUrl(page);
  const normalized = normalize(`${location} ${bodyText}`);

  const workModel =
    /\b(remote|remoto|home office)\b/.test(normalized) ? 'Remoto' :
    /\b(hybrid|hibrido)\b/.test(normalized) ? 'Híbrido' :
    /\b(on.site|onsite|presencial)\b/.test(normalized) ? 'Presencial' :
    null;

  const employmentType =
    /\b(part.time|meio periodo)\b/.test(normalized) ? 'Meio período' :
    /\b(contract|temporario|temporary)\b/.test(normalized) ? 'Temporário' :
    /\b(full.time|tempo integral)\b/.test(normalized) ? 'Tempo integral' :
    null;

  if (!isJuniorTitle(title) || !isTechnologyTitle(title)) {
    log(`Reprovada no filtro de título: ${title}`);
    return null;
  }

  return {
    title: title.slice(0, 255),
    company,
    description: description.slice(0, 30000),
    location: location || null,
    work_model: workModel,
    employment_type: employmentType,
    salary: null,
    application_url: applyHref || url,
    external_id: `linkedin-jobs:${job.id}`,
    original_published_at: job.publishedAt.toISOString(),
    source: 'linkedin-jobs',
    linkedin_job_url: url,
  };
}

export async function collectLinkedInJobs(page) {
  const jobs = new Map();

  for (const term of SEARCH_TERMS) {
    log(`\nIniciando pesquisa: ${term}`);

    const seenIdsInSearch = new Set();

    for (let pageNo = 1; pageNo <= MAX_PAGES_PER_SEARCH; pageNo++) {
      let cards = [];

      try {
        cards = await openSearchPage(page, term, pageNo);
      } catch (error) {
        log(`Falha ao abrir página ${pageNo} de "${term}": ${error.message}`);
        break;
      }

      log(`"${term}" | Página ${pageNo} | ${cards.length} cartões únicos`);

      if (!cards.length) {
        log(`Nenhum cartão encontrado na página ${pageNo}. Encerrando esta pesquisa.`);
        break;
      }

      let newIdsOnPage = 0;

      for (const card of cards) {
        if (seenIdsInSearch.has(card.id)) continue;

        seenIdsInSearch.add(card.id);
        newIdsOnPage++;

        if (jobs.has(card.id)) {
          jobs.get(card.id).matchedTerms.add(term);
          continue;
        }

        const summary = extractCardSummary(card.text, card.id);

        if (!summary) continue;

        if (!isJuniorTitle(summary.title) || !isTechnologyTitle(summary.title)) {
          continue;
        }

        if (!isWithinWindow(summary.publishedAt)) {
          log(`Fora das 72h ou sem data: ${summary.title} | ${summary.dateText || 'data ausente'}`);
          continue;
        }

        jobs.set(card.id, {
          ...summary,
          url: `https://www.linkedin.com/jobs/view/${card.id}/`,
          matchedTerms: new Set([term]),
        });

        log(`Elegível: ${summary.title} | ${summary.dateText}`);
      }

      if (pageNo > 1 && newIdsOnPage === 0) {
        log(`A página ${pageNo} não trouxe novos cartões. Encerrando esta pesquisa.`);
        break;
      }

      if (cards.length < PAGE_SIZE) {
        log(`Menos de ${PAGE_SIZE} cartões encontrados. Última página provável.`);
        break;
      }
    }
  }

  const ordered = [...jobs.values()].sort(
    (a, b) => b.publishedAt.getTime() - a.publishedAt.getTime()
  );

  log(`\nPesquisas concluídas: ${SEARCH_TERMS.length}`);
  log(`Vagas únicas dentro de 72h: ${ordered.length}`);

  const results = [];

  for (const [index, job] of ordered.entries()) {
    log(`\nDetalhando ${index + 1}/${ordered.length}: ${job.title}`);

    try {
      const vacancy = await extractJobDetail(page, job);

      if (vacancy) {
        results.push(vacancy);
        log(`APROVADA: ${job.title}`);
      } else {
        log(`Não aprovada na extração: ${job.title}`);
      }
    } catch (error) {
      log(`Erro ao detalhar ${job.id}: ${error?.message ?? error}`);
    }
  }

  log(`\nTotal aprovado para importação: ${results.length}`);

  return results;
}
