
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
  return clean(value)
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase();
}

function log(message) {
  process.stderr.write('[LinkedIn Jobs] ' + message + '\n');
}

function startOfLocalDay(date) {
  return new Date(date.getFullYear(), date.getMonth(), date.getDate());
}

function subtractCalendarDays(date, days) {
  return new Date(
    date.getFullYear(),
    date.getMonth(),
    date.getDate() - days
  );
}

function parseRelativeDate(value, scrapedAt) {
  const text = normalize(value);

  if (!text) return null;

  if (/\b(agora|just now)\b/.test(text)) {
    return new Date(scrapedAt);
  }

  if (/\b(hoje|today)\b/.test(text)) {
    return startOfLocalDay(scrapedAt);
  }

  if (/\b(ontem|yesterday)\b/.test(text)) {
    return subtractCalendarDays(scrapedAt, 1);
  }

  const match = text.match(
    /(?:ha\s*)?(\d+)\s*(min|minuto|minutos|minute|minutes|h|hr|hrs|hora|horas|hour|hours|d|dia|dias|day|days)\b/i
  );

  if (!match) return null;

  const amount = Number(match[1]);
  const unit = match[2].toLowerCase();

  const factor = /^(min|minuto|minute)/.test(unit)
    ? 60000
    : /^(h|hr|hora|hour)/.test(unit)
      ? 3600000
      : 86400000;

  return new Date(scrapedAt.getTime() - amount * factor);
}

function isWithinWindow(date, scrapedAt) {
  if (!date || Number.isNaN(date.getTime())) return false;

  const age = scrapedAt.getTime() - date.getTime();

  return age >= 0 && age <= LOOKBACK_HOURS * 3600000;
}

function isJuniorTitle(title) {
  // A busca já usa termos de entrada. Não exigir "júnior" no título:
  // títulos ambíguos seguem para o importador central, evitando falsos negativos.
  return Boolean(normalize(title)) && !/\bwordpress\b/.test(normalize(title));
}

function isTechnologyTitle(title) {
  const value = normalize(title);

  const terms = [
    'desenvolvedor',
    'developer',
    'programador',
    'software engineer',
    'engenheiro de software',
    'backend',
    'back-end',
    'frontend',
    'front-end',
    'full stack',
    'fullstack',
    'php',
    '.net',
    'java',
    'python',
    'javascript',
    'typescript',
    'analista de sistemas',
    'analista de desenvolvimento',
    'suporte',
    'help desk',
    'helpdesk',
    'service desk',
    'infraestrutura',
    'infrastructure',
    'redes',
    'network',
    'devops',
    'sysadmin',
    'qa',
    'quality assurance',
    'analista de testes',
    'technical support',
    'it support',
  ];

  return terms.some(term => value.includes(normalize(term)));
}

function hasIncompatibleRequirements(description) {
  const value = normalize(description);

  return /\b(?:minimum of |at least |minimum |minimo de |pelo menos )?(?:3|4|5|6|7|8|9|10)\s*\+?\s*(?:years?|anos?)\b/.test(value)
    || /\b(?:3|4|5|6|7|8|9|10)\s*(?:or more|ou mais)\s*(?:years?|anos?)\b/.test(value)
    || /\b(?:senior|mid.level|midlevel|pleno)\s+(?:experience|position|role|level|experiencia|nivel|posicao)\b/.test(value);
}

function extractCardSummary(card, id, scrapedAt) {
  const lines = String(card.text || '')
    .split('\n')
    .map(clean)
    .filter(Boolean)
    .map(line => line.replace(/^selecionada,\s*/i, ''));

  const titleIndex = lines.findIndex(
    line => isJuniorTitle(line) && isTechnologyTitle(line)
  );

  if (titleIndex < 0) return null;

  const title = clean(lines[titleIndex])
    .replace(/\s*\(vaga verificada\)/i, '');

  const company = clean(card.company || '');
  const location = clean(card.location || '');

  const dateCandidates = [
    ...(card.dateCandidates || []),
    ...lines,
  ];

  const dateText = dateCandidates.find(line =>
    /\b(agora|just now|hoje|today|ontem|yesterday|\d+\s*(?:min|minuto|minutos|minute|minutes|h|hr|hrs|hora|horas|hour|hours|d|dia|dias|day|days))\b/i.test(normalize(line))
  ) || '';

  const publishedAt = card.datetime
    ? new Date(card.datetime)
    : parseRelativeDate(dateText, scrapedAt);

  return {
    id,
    title,
    company,
    location,
    dateText,
    publishedAt,
  };
}

async function getCurrentCards(page) {
  return page.locator(CARD_SELECTOR).evaluateAll(elements => {
    const unique = new Map();

    for (const element of elements) {
      const key = element.getAttribute('componentkey') || '';
      const match = key.match(/job-card-component-ref-(\d+)/);

      if (!match) continue;

      const id = match[1];

      if (unique.has(id)) continue;

      const dateElements = [
        ...element.querySelectorAll(
          'time[datetime], [datetime], [aria-label], [title]'
        ),
      ];

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

        for (const value of candidate) {
          dateCandidates.push(value);
        }

        const rawDate = node.getAttribute('datetime');

        if (
          !datetime &&
          rawDate &&
          !Number.isNaN(Date.parse(rawDate))
        ) {
          datetime = rawDate;
        }
      }

      const textOf = selector => {
        const node = element.querySelector(selector);

        return (
          node?.innerText ||
          node?.textContent ||
          ''
        ).trim();
      };

      const company = textOf(
        '.artdeco-entity-lockup__subtitle, .job-card-container__primary-description, a[data-control-name="company_link"]'
      );

      const location = textOf(
        '.job-card-container__metadata-item, .job-card-container__footer-item, .job-card-container__metadata-wrapper'
      );

      unique.set(id, {
        id,
        text: element.innerText || '',
        company,
        location,
        dateCandidates,
        datetime,
      });
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

  await page.goto(url.toString(), {
    waitUntil: 'domcontentloaded',
    timeout: 45000,
  });

  await page.locator(CARD_SELECTOR)
    .first()
    .waitFor({ state: 'attached', timeout: 15000 })
    .catch(() => {});

  await page.waitForTimeout(450);

  return getCurrentCards(page);
}

async function firstText(root, selectors) {
  for (const selector of selectors) {
    const value = clean(
      await root.locator(selector).first().innerText().catch(() => '')
    );

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

    if (match && match.index < end) {
      end = match.index;
    }
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

  if (start >= 0) {
    text = text.slice(start).trim();
  }

  return text.replace(/\n{3,}/g, '\n\n').trim();
}

async function extractJobDescription(panel) {
  const selectors = [
    '.jobs-description-content__text',
    '.jobs-description__content',
    '.jobs-box__html-content',
    '#job-details',
    '[class*="jobs-description-content"]',
    '[class*="jobs-description"]',
  ];

  const candidates = await panel.locator(selectors.join(', '))
    .evaluateAll(nodes =>
      nodes.map(node => ({
        text: node.innerText || node.textContent || '',
      }))
    )
    .catch(() => []);

  const cleaned = candidates
    .map(candidate => candidate.text.replace(/\r/g, '').trim())
    .filter(text => text.length >= 80)
    .sort((a, b) => a.length - b.length);

  for (const candidate of cleaned) {
    const description = cleanJobDescription(candidate);

    if (description.length >= 100) {
      return description;
    }
  }

  // Fallback: tenta extrair a descrição a partir do painel completo.
  const panelText = await panel.innerText().catch(() => '');

  const fallback = cleanJobDescription(panelText);

  return fallback.length >= 100 ? fallback : '';
}

function resolveApplicationUrl(href) {
  if (!href) return null;

  try {
    const parsed = new URL(href);
    const redirect = parsed.searchParams.get('url');

    if (!redirect) return href;

    try {
      return new URL(redirect).toString();
    } catch {
      return redirect;
    }
  } catch {
    return href;
  }
}

async function extractApplyUrl(panel) {
  const href = await panel.locator(
    'a[href], a[data-tracking-control-name*="apply"], .jobs-apply-button a[href], [class*="jobs-apply-button"] a[href]'
  ).evaluateAll(links => {
    const link = links.find(element => {
      const label = [
        element.innerText,
        element.textContent,
        element.getAttribute('aria-label'),
        element.getAttribute('title'),
        element.getAttribute('data-tracking-control-name'),
      ].filter(Boolean).join(' ').trim();

      return /candidatar-se|candidate-se|apply|easy apply|inscrever-se/i.test(label)
        && element.offsetParent !== null;
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

    if (await panel.count().catch(() => 0)) {
      return panel;
    }
  }

  return page.locator('body');
}

async function extractFromOpenPanel(page, job) {
  const card = page.locator(
    '[componentkey="job-card-component-ref-' + job.id + '"]'
  ).first();

  if (!(await card.count().catch(() => 0))) {
    log('Cartão não encontrado na página atual: ' + job.id);
    return null;
  }

  await card.click({ timeout: 10000 });

  const panel = await getDetailPanel(page);

  const titleSelector = [
    '.job-details-jobs-unified-top-card__job-title',
    '.jobs-unified-top-card__job-title',
    'h1',
  ].join(', ');

  await panel.locator(titleSelector)
    .filter({ hasText: job.title })
    .first()
    .waitFor({ state: 'visible', timeout: 8000 })
    .catch(() => {});

  const title = await firstText(panel, [
    '.job-details-jobs-unified-top-card__job-title',
    '.jobs-unified-top-card__job-title',
    'h1',
  ]);

  if (!title || normalize(title) !== normalize(job.title)) {
    log(
      'Painel não confirmou o título esperado para ' +
      job.id +
      ': ' +
      (title || 'título ausente')
    );

    return null;
  }

  if (!isJuniorTitle(title) || !isTechnologyTitle(title)) {
    return null;
  }

  const company = await firstText(panel, [
    '.job-details-jobs-unified-top-card__company-name',
    '.jobs-unified-top-card__company-name',
    'a[href*="/company/"]',
  ]) || job.company;

  const locationLine = await firstText(panel, [
    '.job-details-jobs-unified-top-card__tertiary-description-container',
    '.jobs-unified-top-card__primary-description',
    '.job-details-jobs-unified-top-card__primary-description-container',
  ]);

  const location = clean(
    locationLine.split(/\s*[·•]\s*/)[0]
  ) || job.location;

  const description = await extractJobDescription(panel);

  if (description.length < 100) {
    log(
      'Descrição real não localizada ou curta para ' +
      job.id +
      '; descartada para não importar texto da interface.'
    );

    return null;
  }

  if (
    /ative um alerta para vagas semelhantes|pesquise vagas mais rapido com premium|milhoes de usuarios tem contas premium/i.test(
      normalize(description)
    )
  ) {
    log('Descrição contaminada por texto da interface do LinkedIn: ' + job.id);
    return null;
  }

  if (/\bwordpress\b/i.test(normalize(title))) {
    log('WordPress permanece fora do escopo: ' + title);
    return null;
  }

  const applyUrl = await extractApplyUrl(panel);

  const normalized = normalize(location + ' ' + description);

  const workModel = /\b(remote|remoto|home office)\b/.test(normalized)
    ? 'Remoto'
    : /\b(hybrid|hibrido)\b/.test(normalized)
      ? 'Híbrido'
      : /\b(on.site|onsite|presencial)\b/.test(normalized)
        ? 'Presencial'
        : null;

  const employmentType = /\b(part.time|meio periodo)\b/.test(normalized)
    ? 'Meio período'
    : /\b(contract|temporario|temporary)\b/.test(normalized)
      ? 'Temporário'
      : /\b(full.time|tempo integral)\b/.test(normalized)
        ? 'Tempo integral'
        : null;

  const linkedinUrl =
    'https://www.linkedin.com/jobs/view/' + job.id + '/';

  return {
    title: title.slice(0, 255),
    company,
    description: description.slice(0, 30000),
    location: location || null,
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
  const scrapedAt = new Date();
  const processedIds = new Set();
  const results = [];

  log('Início da raspagem: ' + scrapedAt.toString());
  log('Janela de publicação: últimas 72 horas.');
  log('Máximo de páginas por pesquisa: ' + MAX_PAGES_PER_SEARCH);

  for (const term of SEARCH_TERMS) {
    log('\nIniciando pesquisa: ' + term);

    const seenIdsInSearch = new Set();

    for (
      let pageNo = 1;
      pageNo <= MAX_PAGES_PER_SEARCH;
      pageNo++
    ) {
      let cards;

      try {
        cards = await openSearchPage(page, term, pageNo);
      } catch (error) {
        log(
          'Falha na pesquisa "' +
          term +
          '", página ' +
          pageNo +
          ': ' +
          error.message
        );

        break;
      }

      log(
        '"' +
        term +
        '" | página ' +
        pageNo +
        ' | ' +
        cards.length +
        ' cartões'
      );

      if (!cards.length) break;

      let newIds = 0;

      for (const cardData of cards) {
        if (seenIdsInSearch.has(cardData.id)) {
          continue;
        }

        seenIdsInSearch.add(cardData.id);
        newIds++;

        if (processedIds.has(cardData.id)) {
          continue;
        }

        const job = extractCardSummary(
          cardData,
          cardData.id,
          scrapedAt
        );

        if (!job) {
          continue;
        }

        // Primeiro filtro: título, sem abrir o painel.
        if (
          !isJuniorTitle(job.title) ||
          !isTechnologyTitle(job.title)
        ) {
          log('Descartada pelo título: ' + job.title);
          processedIds.add(job.id);
          continue;
        }

        // Data precisa ser identificada e estar dentro das últimas 72 horas.
        if (!isWithinWindow(job.publishedAt, scrapedAt)) {
          log(
            'Descartada por data ausente ou fora das 72 horas: ' +
            job.title +
            ' | ' +
            (job.dateText || 'sem metadado de data')
          );

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
          log(
            'Erro no painel da vaga ' +
            job.id +
            ': ' +
            (error?.message ?? error)
          );
        }
      }

      if (pageNo > 1 && newIds === 0) {
        log('Página sem novos cartões; encerrando esta pesquisa.');
        break;
      }

      if (cards.length < PAGE_SIZE) {
        log('Menos de 25 cartões encontrados. Última página provável.');
        break;
      }
    }
  }

  log('\nPesquisas concluídas: ' + SEARCH_TERMS.length);
  log('Vagas únicas processadas: ' + processedIds.size);
  log('Total aprovado para prévia: ' + results.length);

  return results;
}
