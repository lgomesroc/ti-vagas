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

const LOOKBACK_CALENDAR_DAYS = 3;
const MAX_PAGES_PER_SEARCH = 20;
const PAGE_SIZE = 25;

const clean = value => String(value ?? '').replace(/\s+/g, ' ').trim();

function normalize(value) {
  return clean(value).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
}

function log(message) {
  process.stderr.write(`[LinkedIn Jobs] ${message}\n`);
}

function startOfLocalDay(date) {
  return new Date(date.getFullYear(), date.getMonth(), date.getDate());
}

function subtractCalendarDays(date, days) {
  return new Date(date.getFullYear(), date.getMonth(), date.getDate() - days);
}

function parseRelativeDate(value, scrapedAt) {
  const text = normalize(value);
  if (!text) return null;

  if (/\b(agora|just now)\b/.test(text)) return new Date(scrapedAt);
  if (/\b(hoje|today)\b/.test(text)) return startOfLocalDay(scrapedAt);

  if (/\b(ontem|yesterday)\b/.test(text)) {
    return subtractCalendarDays(scrapedAt, 1);
  }

  // "1 dia", "2 dias", "3 days ago" e "3d" são tratados como dias
  // de calendário, não como blocos exatos de 24 horas.
  const dayMatch = text.match(/(?:ha\s*)?(\d+)\s*(d|dia|dias|day|days)\b/i);
  if (dayMatch) {
    return subtractCalendarDays(scrapedAt, Number(dayMatch[1]));
  }

  const match = text.match(/(?:ha\s*)?(\d+)\s*(min|minuto|minutos|minute|minutes|h|hr|hrs|hora|horas|hour|hours)\b/i);
  if (!match) return null;

  const amount = Number(match[1]);
  const unit = match[2].toLowerCase();
  const factor = /^(min|minuto|minute)/.test(unit) ? 60000 : 3600000;
  return new Date(scrapedAt.getTime() - amount * factor);
}

function isWithinCalendarWindow(date, scrapedAt) {
  if (!date || Number.isNaN(date.getTime()) || date.getTime() > scrapedAt.getTime()) {
    return false;
  }

  const firstEligibleDay = subtractCalendarDays(scrapedAt, LOOKBACK_CALENDAR_DAYS);
  return startOfLocalDay(date).getTime() >= firstEligibleDay.getTime();
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

function extractCardSummary(card, id, scrapedAt) {
  const lines = String(card.text || '')
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
  const company = clean(card.company || '');
  const location = clean(card.location || '');
  const dateCandidates = [...(card.dateCandidates || []), ...lines];
  const dateText = dateCandidates.find(line =>
    /\b(agora|just now|hoje|today|ontem|yesterday|\d+\s*(?:min|minuto|minutos|minute|minutes|h|hr|hrs|hora|horas|hour|hours|d|dia|dias|day|days))\b/i.test(normalize(line))
  ) || '';
  const publishedAt = card.datetime
    ? new Date(card.datetime)
    : parseRelativeDate(dateText, scrapedAt);

  return { id, title, company, location, dateText, publishedAt };
}

async function getCurrentCards(page) {
  return page.locator('[componentkey^="job-card-component-ref-"]').evaluateAll(elements => {
    const unique = new Map();

    for (const element of elements) {
      const key = element.getAttribute('componentkey') || '';
      const match = key.match(/job-card-component-ref-(\d+)/);
      if (!match) continue;

      const id = match[1];
      if (unique.has(id)) continue;

      const dateElements = [...element.querySelectorAll('time[datetime], [datetime], [aria-label], [title]')];
      const dateCandidates = [];
      let datetime = null;

      for (const node of dateElements) {
        const candidate = [
          node.getAttribute('datetime'),
          node.getAttribute('aria-label'),
          node.getAttribute('title'),
          node.innerText,
          node.textContent,
        ].filter(Boolean);

        for (const value of candidate) dateCandidates.push(value);
        const rawDate = node.getAttribute('datetime');
        if (!datetime && rawDate && !Number.isNaN(Date.parse(rawDate))) datetime = rawDate;
      }

      const textOf = selector => {
        const node = element.querySelector(selector);
        return (node?.innerText || node?.textContent || '').trim();
      };
      const company = textOf('.artdeco-entity-lockup__subtitle, .job-card-container__primary-description, a[data-control-name="company_link"]');
      const location = textOf('.job-card-container__metadata-item, .job-card-container__footer-item, .job-card-container__metadata-wrapper');
      unique.set(id, { id, text: element.innerText || '', company, location, dateCandidates, datetime });
    }

    return [...unique.values()];
  });
}

async function openSearchPage(page, term, pageNo) {
  const searchUrl = new URL('https://www.linkedin.com/jobs/search/');

  searchUrl.searchParams.set('keywords', term);
  searchUrl.searchParams.set('location', 'Brazil');
  searchUrl.searchParams.set('f_E', '1,2');
  // 4 dias na busca para não perder o terceiro dia até 23:59.
  // A validação final usa a data local da raspagem e três dias de calendário.
  searchUrl.searchParams.set('f_TPR', 'r345600');
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

function cleanJobDescription(value) {
  let text = String(value || '')
    .replace(/\u00a0/g, ' ')
    .replace(/[ \t]+/g, ' ')
    .replace(/\n{3,}/g, '\n\n')
    .trim();

  const stopMarkers = [
    /ative um alerta para vagas semelhantes/i,
    /activate an alert for similar jobs/i,
    /pesquise vagas mais rapido com premium/i,
    /search faster with premium/i,
    /milhoes de usuarios tem contas premium/i,
    /millions of members use premium/i,
    /demonstracao gratuita de \d+ mes/i,
    /free \d+[- ]month trial/i,
    /sobre a empresa/i,
    /about the company/i,
    /veja mais vagas/i,
    /see more jobs/i,
  ];

  let end = text.length;
  for (const marker of stopMarkers) {
    const match = marker.exec(text);
    if (match && match.index < end) end = match.index;
  }
  text = text.slice(0, end).trim();
  text = text.replace(/^(mais|see more|ver mais)\s*/i, '').trim();

  const startMarkers = [
    /sobre a vaga\s*/i,
    /about the job\s*/i,
    /descri[cç][aã]o da vaga\s*/i,
    /job description\s*/i,
  ];
  let start = -1;
  for (const marker of startMarkers) {
    const match = marker.exec(text);
    if (match && (start < 0 || match.index < start)) {
      start = match.index + match[0].length;
    }
  }
  if (start >= 0) text = text.slice(start).trim();

  return text.replace(/\n{3,}/g, '\n\n').trim();
}

async function extractJobDescription(page) {
  const selectors = [
    '.jobs-description-content__text',
    '.jobs-description__content',
    '.jobs-box__html-content',
    '#job-details',
  ];

  const candidates = await page.locator(selectors.join(', ')).evaluateAll(nodes =>
    nodes.map(node => ({
      text: node.innerText || node.textContent || '',
      className: typeof node.className === 'string' ? node.className : '',
      id: node.id || '',
    }))
  ).catch(() => []);

  const cleaned = candidates
    .map(candidate => ({ ...candidate, text: candidate.text.replace(/\r/g, '').trim() }))
    .filter(candidate => candidate.text.length >= 80)
    .sort((a, b) => a.text.length - b.text.length);

  for (const candidate of cleaned) {
    const description = cleanJobDescription(candidate.text);
    if (description.length >= 100) return description;
  }

  return '';
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

  const locationLine = await firstText(page, [
    '.job-details-jobs-unified-top-card__tertiary-description-container',
    '.jobs-unified-top-card__primary-description',
    '.job-details-jobs-unified-top-card__primary-description-container',
  ]);
  // O bloco terciário costuma apresentar "localidade · data · candidaturas".
  // Guarda somente o primeiro segmento para não gravar empresa/data como cidade.
  const location = clean(locationLine.split(/\s*[·•]\s*/)[0]) || job.location;

  const description = await extractJobDescription(page);
  const bodyText = await page.locator('body').innerText().catch(() => '');

  if (description.length < 100) {
    log(`Descrição real não localizada ou curta para ${job.id}; descartada para não importar texto da interface.`);
    return null;
  }

  if (/ative um alerta para vagas semelhantes|pesquise vagas mais rapido com premium|milhoes de usuarios tem contas premium/i.test(normalize(description))) {
    log(`Descrição contaminada por texto da interface do LinkedIn: ${job.id}`);
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

  if (/\b(wordpress|marketing|vendas|sales|recruiter|recrutamento|customer success|data analyst|analista de dados|estagio|trainee)\b/i.test(normalize(title))) {
    log(`Área ou modalidade fora do escopo: ${title}`);
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
  // Captura uma única referência de tempo para toda a execução.
  const scrapedAt = new Date();
  const jobs = new Map();

  log(`Início da raspagem: ${scrapedAt.toString()}`);
  log('Janela: hoje + os três dias de calendário anteriores (até 23:59 do terceiro dia).');

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

        const summary = extractCardSummary(card, card.id, scrapedAt);

        if (!summary) continue;

        if (!isJuniorTitle(summary.title) || !isTechnologyTitle(summary.title)) {
          continue;
        }

        if (!summary.publishedAt) {
          log(`Data não identificada; descartada por segurança: ${summary.title} | ${summary.dateText || 'sem metadado de data'}`);
          continue;
        }

        if (!isWithinCalendarWindow(summary.publishedAt, scrapedAt)) {
          log(`Fora da janela de calendário: ${summary.title} | ${summary.dateText}`);
          continue;
        }

        jobs.set(card.id, {
          ...summary,
          url: `https://www.linkedin.com/jobs/view/${card.id}/`,
          matchedTerms: new Set([term]),
        });

        log(`Elegível: ${summary.title} | ${summary.dateText} | ${summary.publishedAt.toString()}`);
      }

      if (pageNo > 1 && newIdsOnPage === 0) {
        log(`A página ${pageNo} não trouxe novos cartões. Encerrando esta pesquisa.`);
        break;
      }

      if (cards.length < PAGE_SIZE) {
        log('Menos de 25 cartões encontrados. Última página provável.');
        break;
      }
    }
  }

  const ordered = [...jobs.values()].sort(
    (a, b) => b.publishedAt.getTime() - a.publishedAt.getTime()
  );

  log(`\nPesquisas concluídas: ${SEARCH_TERMS.length}`);
  log(`Vagas únicas dentro da janela de calendário: ${ordered.length}`);

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
