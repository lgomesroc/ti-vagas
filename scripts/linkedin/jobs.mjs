const SEARCH_TERMS = [
  'desenvolvedor júnior',
  'desenvolvedor backend júnior',
  'programador júnior',
  'analista de suporte júnior',
  'técnico de suporte júnior',
  'infraestrutura júnior',
  'junior software engineer',
];

const LOOKBACK_HOURS = 72;
const MAX_PAGES_PER_SEARCH = 3;
const PAGE_SIZE = 25;
const CARD_SELECTOR = '[componentkey^="job-card-component-ref-"]';

const clean = value => String(value ?? '').replace(/\s+/g, ' ').trim();

function normalize(value) {
  return clean(value).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
}

function log(message) {
  process.stderr.write('[LinkedIn Jobs] ' + message + '\n');
}

function parseRelativeDate(value) {
  const text = normalize(value);
  if (!text) return null;
  if (/\b(agora|just now|hoje|today)\b/.test(text)) return new Date();

  const match = text.match(/(?:ha\s*)?(\d+)\s*(min|minuto|minutos|minute|minutes|h|hr|hrs|hora|horas|hour|hours|d|dia|dias|day|days)\b/i);
  if (!match) return null;

  const amount = Number(match[1]);
  const unit = match[2].toLowerCase();
  const factor = /^(min|minuto|minute)/.test(unit) ? 60000
    : /^(h|hr|hora|hour)/.test(unit) ? 3600000
    : 86400000;

  return new Date(Date.now() - amount * factor);
}

function isWithinWindow(date) {
  if (!date || Number.isNaN(date.getTime())) return false;
  const age = Date.now() - date.getTime();
  return age >= 0 && age <= LOOKBACK_HOURS * 3600000;
}

function isJuniorTitle(title) {
  const value = normalize(title);

  if (!value || /wordpress/.test(value)) return false;
  if (/\b(jr|junior)\s*(\/|&|-|\|)\s*(pl|pleno|mid|mid-level)\b/.test(value)) return false;
  if (/\b(pleno|mid.level|midlevel|senior|sr|staff|principal|lead|manager|gerente|diretor|head|especialista|coordenador|supervisor)\b/.test(value)) return false;
  if (/\b(estagio|trainee|intern|internship|apprentice)\b/.test(value)) return false;

  return /\b(junior|jr)\b/.test(value);
}

function isTechnologyTitle(title) {
  const value = normalize(title);
  const terms = [
    'desenvolvedor', 'developer', 'programador', 'software engineer',
    'engenheiro de software', 'backend', 'back-end', 'frontend', 'front-end',
    'full stack', 'fullstack', 'php', '.net', 'java', 'python',
    'javascript', 'typescript', 'analista de sistemas',
    'analista de desenvolvimento', 'suporte', 'help desk', 'helpdesk',
    'service desk', 'infraestrutura', 'infrastructure', 'redes', 'network',
    'devops', 'sysadmin', 'qa', 'quality assurance', 'analista de testes',
    'technical support', 'it support',
  ];
  return terms.some(term => value.includes(normalize(term)));
}

function hasIncompatibleRequirements(description) {
  const value = normalize(description);
  return /\b(?:minimum of |at least |minimum |minimo de |pelo menos )?(?:3|4|5|6|7|8|9|10)\s*\+?\s*(?:years?|anos?)\b/.test(value)
    || /\b(?:3|4|5|6|7|8|9|10)\s*(?:or more|ou mais)\s*(?:years?|anos?)\b/.test(value)
    || /\b(?:senior|mid.level|midlevel|pleno)\s+(?:experience|position|role|level|experiencia|nivel|posicao)\b/.test(value);
}

function extractCardSummary(text, id) {
  const lines = String(text).split('\n').map(clean).filter(Boolean)
    .map(line => line.replace(/^selecionada,\s*/i, ''));
  const titleIndex = lines.findIndex(line => isJuniorTitle(line) && isTechnologyTitle(line));
  if (titleIndex < 0) return null;

  const title = lines[titleIndex].replace(/\s*\(vaga verificada\)/i, '');
  const company = lines[titleIndex + 1] || '';
  const location = lines[titleIndex + 2] || '';
  const dateText = lines.find(line =>
    /\b(anunciada|posted|ha\s*\d|just now|agora|hoje|today)\b/i.test(normalize(line))
  ) || '';

  return { id, title, company, location, dateText, publishedAt: parseRelativeDate(dateText) };
}

async function getCurrentCards(page) {
  return page.locator(CARD_SELECTOR).evaluateAll(elements => {
    const unique = new Map();
    for (const element of elements) {
      const match = (element.getAttribute('componentkey') || '').match(/job-card-component-ref-(\d+)/);
      if (match && !unique.has(match[1])) {
        unique.set(match[1], { id: match[1], text: element.innerText || '' });
      }
    }
    return [...unique.values()];
  });
}

async function openSearchPage(page, term, pageNo) {
  const url = new URL('https://www.linkedin.com/jobs/search/');
  url.searchParams.set('keywords', term);
  url.searchParams.set('location', 'Brazil');
  url.searchParams.set('f_E', '1,2');
  url.searchParams.set('f_TPR', 'r259200');
  url.searchParams.set('sortBy', 'DD');
  url.searchParams.set('start', String((pageNo - 1) * PAGE_SIZE));

  log('Pesquisa "' + term + '" | página ' + pageNo);
  await page.goto(url.toString(), { waitUntil: 'domcontentloaded', timeout: 45000 });
  await page.locator(CARD_SELECTOR).first().waitFor({ state: 'attached', timeout: 15000 }).catch(() => {});
  await page.waitForTimeout(700);
  return getCurrentCards(page);
}

async function firstText(root, selectors) {
  for (const selector of selectors) {
    const value = clean(await root.locator(selector).first().innerText().catch(() => ''));
    if (value) return value;
  }
  return '';
}

function extractDescriptionFromBody(text) {
  const startPatterns = [/sobre a vaga/i, /about the job/i, /descri[cç][aã]o da vaga/i, /job description/i];
  const endPatterns = [
    /sobre a empresa/i, /about the company/i, /compet[eê]ncias/i, /see less/i,
    /ative um alerta para vagas semelhantes/i, /activate alert/i,
  ];

  let start = -1;
  for (const pattern of startPatterns) {
    const match = pattern.exec(text);
    if (match && (start < 0 || match.index < start)) start = match.index + match[0].length;
  }
  if (start < 0) return '';

  const remaining = text.slice(start);
  let end = remaining.length;
  for (const pattern of endPatterns) {
    const match = pattern.exec(remaining);
    if (match && match.index > 0 && match.index < end) end = match.index;
  }
  return clean(remaining.slice(0, end));
}

function resolveApplicationUrl(href) {
  if (!href) return null;
  try {
    const parsed = new URL(href);
    const redirect = parsed.searchParams.get('url');
    if (!redirect) return href;
    try { return new URL(redirect).toString(); } catch { return redirect; }
  } catch {
    return href;
  }
}

async function extractApplyUrl(panel) {
  const href = await panel.locator('a[href]').evaluateAll(links => {
    const link = links.find(element => {
      const label = (element.innerText || element.textContent || '').trim();
      return /candidatar-se|apply|easy apply/i.test(label) && element.offsetParent !== null;
    });
    return link?.href || null;
  }).catch(() => null);
  return resolveApplicationUrl(href);
}

async function getDetailPanel(page) {
  for (const selector of [
    '.scaffold-layout__detail',
    '.jobs-search__job-details--container',
    '.jobs-search__job-details',
  ]) {
    const panel = page.locator(selector).last();
    if (await panel.count().catch(() => 0)) return panel;
  }
  return page.locator('body');
}

async function extractFromOpenPanel(page, job) {
  const card = page.locator('[componentkey="job-card-component-ref-' + job.id + '"]').first();
  if (!(await card.count().catch(() => 0))) {
    log('Cartão não encontrado na página atual: ' + job.id);
    return null;
  }

  await card.click({ timeout: 10000 });
  const panel = await getDetailPanel(page);
  const title = await firstText(panel, [
    '.job-details-jobs-unified-top-card__job-title',
    '.jobs-unified-top-card__job-title',
    'h1',
  ]);

  // Não usar um painel antigo ou de outra vaga caso o clique não tenha atualizado os detalhes.
  if (!title || normalize(title) !== normalize(job.title)) {
    log('Painel não confirmou o título esperado para ' + job.id + ': ' + (title || 'título ausente'));
    return null;
  }
  if (!isJuniorTitle(title) || !isTechnologyTitle(title)) return null;

  const company = await firstText(panel, [
    '.job-details-jobs-unified-top-card__company-name',
    '.jobs-unified-top-card__company-name',
    'a[href*="/company/"]',
  ]) || job.company;

  let description = await firstText(panel, [
    '.jobs-description__content',
    '.jobs-box__html-content',
    '#job-details',
    '[class*="jobs-description-content"]',
    '[class*="jobs-description"]',
  ]);

  if (!description || description.length < 80) {
    description = extractDescriptionFromBody(await panel.innerText().catch(() => ''));
  }
  description = clean(description);

  if (description.length < 80 || hasIncompatibleRequirements(description)) {
    log('Descrição insuficiente ou requisitos incompatíveis: ' + title);
    return null;
  }

  const applyUrl = await extractApplyUrl(panel);
  const normalized = normalize(job.location + ' ' + description);
  const workModel = /\b(remote|remoto|home office)\b/.test(normalized) ? 'Remoto'
    : /\b(hybrid|hibrido)\b/.test(normalized) ? 'Híbrido'
    : /\b(on.site|onsite|presencial)\b/.test(normalized) ? 'Presencial' : null;

  const employmentType = /\b(part.time|meio periodo)\b/.test(normalized) ? 'Meio período'
    : /\b(contract|temporario|temporary)\b/.test(normalized) ? 'Temporário'
    : /\b(full.time|tempo integral)\b/.test(normalized) ? 'Tempo integral' : null;

  const linkedinUrl = 'https://www.linkedin.com/jobs/view/' + job.id + '/';
  return {
    title: title.slice(0, 255),
    company,
    description: description.slice(0, 30000),
    location: job.location || null,
    work_model: workModel,
    employment_type: employmentType,
    salary: null,
    application_url: applyUrl || linkedinUrl,
    external_id: 'linkedin-jobs:' + job.id,
    original_published_at: job.publishedAt.toISOString(),
    source: 'linkedin-jobs',
    linkedin_job_url: linkedinUrl,
  };
}

export async function collectLinkedInJobs(page) {
  const processedIds = new Set();
  const results = [];

  for (const term of SEARCH_TERMS) {
    log('\nIniciando pesquisa: ' + term);
    const seenIdsInSearch = new Set();

    for (let pageNo = 1; pageNo <= MAX_PAGES_PER_SEARCH; pageNo++) {
      let cards;
      try {
        cards = await openSearchPage(page, term, pageNo);
      } catch (error) {
        log('Falha na pesquisa "' + term + '", página ' + pageNo + ': ' + error.message);
        break;
      }

      log('"' + term + '" | página ' + pageNo + ' | ' + cards.length + ' cartões');
      if (!cards.length) break;

      let newIds = 0;
      for (const cardData of cards) {
        if (seenIdsInSearch.has(cardData.id)) continue;
        seenIdsInSearch.add(cardData.id);
        newIds++;

        if (processedIds.has(cardData.id)) continue;

        const job = extractCardSummary(cardData.text, cardData.id);
        if (!job) continue;

        // Primeiro filtro: título, sem abrir o painel.
        if (!isJuniorTitle(job.title) || !isTechnologyTitle(job.title)) {
          log('Descartada pelo título: ' + job.title);
          processedIds.add(job.id);
          continue;
        }

        if (!isWithinWindow(job.publishedAt)) {
          log('Descartada por data ausente/fora das 72h: ' + job.title);
          processedIds.add(job.id);
          continue;
        }

        processedIds.add(job.id);
        log('Abrindo painel para validar: ' + job.title);

        try {
          const vacancy = await extractFromOpenPanel(page, job);
          if (vacancy) {
            results.push(vacancy);
            log('APROVADA: ' + vacancy.title);
          } else {
            log('REPROVADA após análise: ' + job.title);
          }
        } catch (error) {
          log('Erro no painel da vaga ' + job.id + ': ' + (error?.message ?? error));
        }
      }

      if (pageNo > 1 && newIds === 0) {
        log('Página sem novos cartões; encerrando esta pesquisa.');
        break;
      }
      if (cards.length < PAGE_SIZE) break;
    }
  }

  log('\nPesquisas concluídas: ' + SEARCH_TERMS.length);
  log('Vagas únicas processadas: ' + processedIds.size);
  log('Total aprovado para prévia: ' + results.length);
  return results;
}
