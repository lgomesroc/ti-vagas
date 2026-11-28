import { chromium } from 'playwright';
import { launchPersistentBrowser } from './persistent-browser.mjs';
import { createInterface } from 'node:readline/promises';
import { stdin, stdout } from 'node:process';
import path from 'node:path';
import { existsSync, mkdirSync, readFileSync } from 'node:fs';
import { collectLinkedInJobs } from './jobs.mjs';
import { chromiumArgs, createTimeBudget, resolveHeadless } from './runtime.mjs';

const profile = path.resolve(
  process.env.LINKEDIN_SECONDARY_ACCOUNT_PROFILE ??
  '.local/linkedin-secondary-profile'
);

const edgeCandidates = [
  path.join(
    process.env['PROGRAMFILES(X86)'] ?? 'C:\\Program Files (x86)',
    'Microsoft',
    'Edge',
    'Application',
    'msedge.exe'
  ),
  path.join(
    process.env.PROGRAMFILES ?? 'C:\\Program Files',
    'Microsoft',
    'Edge',
    'Application',
    'msedge.exe'
  ),
];

const browserExecutable =
  process.env.LINKEDIN_SECONDARY_ACCOUNT_EXECUTABLE ??
  edgeCandidates.find(candidate => existsSync(candidate));

const storageStatePath = process.env.LINKEDIN_STORAGE_STATE_PATH
  ? path.resolve(process.env.LINKEDIN_STORAGE_STATE_PATH)
  : null;

const mode = process.argv[2] ?? 'search';
const linkedinBaseUrl = new URL(
  process.env.LINKEDIN_BASE_URL ?? 'https://www.linkedin.com'
).origin;

const isMockLinkedInOrigin = linkedinBaseUrl !== 'https://www.linkedin.com';

function isLinkedInUrl(value) {
  try {
    const url = new URL(value);

    return isMockLinkedInOrigin
      ? url.origin === linkedinBaseUrl
      : /(^|\.)linkedin\.com$/i.test(url.hostname);
  } catch {
    return false;
  }
}

function isLinkedInPostUrl(value) {
  try {
    const url = new URL(value);
    return isLinkedInUrl(value) && /\/(?:feed\/update|posts)\//i.test(url.pathname);
  } catch {
    return false;
  }
}

function normalizePostUrl(value) {
  try {
    const url = new URL(value);
    url.search = '';
    url.hash = '';
    return url.href.replace(/\/$/, '').toLowerCase();
  } catch {
    return String(value).split(/[?#]/, 1)[0].replace(/\/$/, '').toLowerCase();
  }
}

function loadKnownPosts() {
  const file = process.env.LINKEDIN_POSTS_KNOWN_POSTS_FILE;
  if (!file) return { ids: new Set(), urls: new Set() };

  try {
    const records = JSON.parse(readFileSync(file, 'utf8'));
    const posts = Array.isArray(records) ? records : [];
    return {
      ids: new Set(posts.map(post => String(post.postId ?? '')).filter(Boolean)),
      urls: new Set(posts.map(post => normalizePostUrl(String(post.postUrl ?? ''))).filter(Boolean)),
    };
  } catch (error) {
    throw new Error('Não foi possível ler a lista de publicações já consultadas: ' + error.message);
  }
}

const knownPosts = loadKnownPosts();

// Em CI ou Linux sem servidor gráfico não há tela: força headless para o Chromium
// não falhar com "Missing X server or $DISPLAY".
const headless = resolveHeadless();

// Tempo total da pesquisa de publicações (segundos). Ao esgotar, devolve o que
// já foi coletado. 0 = sem limite.
const timeBudget = createTimeBudget(
  process.env.LINKEDIN_POSTS_TIME_BUDGET_SECONDS ?? 480
);

const defaultKeywords = [
  // Suporte e infraestrutura
  'vaga suporte técnico',
  'vaga suporte TI',
  'analista de suporte júnior',
  'suporte técnico júnior',
  'técnico de suporte',
  'help desk vaga',
  'service desk vaga',
  'suporte N1 vaga',
  'suporte ao usuário vaga',
  'field service suporte',
  'infraestrutura suporte júnior',
  'technical support junior',

  // Desenvolvimento backend, frontend e full stack
  'vaga desenvolvedor júnior',
  'desenvolvedor backend júnior',
  'desenvolvedor fullstack júnior',
  'desenvolvedor full stack júnior',
  'programador júnior vaga',
  'junior software engineer',
  'junior web developer',
  'backend developer junior',
  'fullstack developer junior',
  'full stack developer junior',

  // Linguagens e ecossistemas
  'vaga desenvolvedor PHP Laravel',
  'vaga desenvolvedor C# .NET',
  'vaga desenvolvedor Java júnior',
  'vaga desenvolvedor Python júnior',
  'vaga desenvolvedor JavaScript TypeScript',
  'vaga desenvolvedor Node.js júnior',
  'vaga desenvolvedor fullstack PHP .NET',
  'estágio desenvolvimento software',
];

const configuredKeywords = process.env.LINKEDIN_SECONDARY_ACCOUNT_KEYWORDS;
const keywords = configuredKeywords
  ? configuredKeywords.split(';').map(keyword => keyword.trim()).filter(Boolean)
  : defaultKeywords;

const lookbackHours = Number(
  process.env.LINKEDIN_POSTS_LOOKBACK_HOURS ?? 24
);

const keywordDiagnostics = new Map();

function canonicalPostUrl(value) {
  try {
    const url = new URL(value);

    // O LinkedIn usa URNs no /feed/update e IDs embutidos no slug
    // de /posts. Ambos precisam produzir a mesma chave persistente.
    const activity = url.pathname.match(
      /(?:urn:li:)?(?:activity|share|ugcPost)[-:](\d{10,})/i
    );

    if (activity) {
      return `linkedin:post:${activity[1]}`;
    }

    const slug = url.pathname.match(/\/posts\/([^/?]+)/i);

    if (slug) {
      return `linkedin:post:${slug[1]
        .replace(/-[a-f0-9]{8,}$/i, '')
        .toLowerCase()}`;
    }

    url.search = '';
    url.hash = '';

    return url.href.replace(/\/$/, '').toLowerCase();
  } catch {
    return String(value)
      .split(/[?#]/, 1)[0]
      .replace(/\/$/, '')
      .toLowerCase();
  }
}

function parseDate(text, datetime) {
  if (datetime && !Number.isNaN(Date.parse(datetime))) {
    return new Date(datetime);
  }

  const value = (text ?? '').toLowerCase().trim();

  const match = value.match(
    /\b(\d+)\s*(meses?|mos?|months?|minutos?|mins?|min|horas?|hrs?|h|dias?|days?|d|semanas?|sems?|weeks?|wks?|w|anos?|years?|yrs?|y)\b/i
  );

  if (match) {
    const amount = Number(match[1]);
    const unit = match[2].toLowerCase();

    const ms =
      /^(mes|meses|mo|mos|month|months)$/.test(unit)
        ? amount * 30 * 86400000
        : /^(ano|anos|year|years|yr|yrs|y)$/.test(unit)
          ? amount * 365 * 86400000
          : /^(hora|horas|hr|hrs|h)$/.test(unit)
            ? amount * 3600000
            : /^(dia|dias|day|days|d)$/.test(unit)
              ? amount * 86400000
              : /^(sem|sems|semana|semanas|week|weeks|wk|wks|w)$/.test(unit)
                ? amount * 604800000
                : amount * 60000;

    return new Date(Date.now() - ms);
  }

  if (/\b(hoje|today|agora|just now)\b/.test(value)) {
    return new Date();
  }

  if (/\b(ontem|yesterday)\b/.test(value)) {
    return new Date(Date.now() - 86400000);
  }

  return null;
}

async function expandComments(page, card) {
  const labels =
    /show more comments|view more comments|ver mais comentarios|mostrar mais comentarios|mais comentarios/i;

  for (let attempt = 0; attempt < 2; attempt++) {
    // Lê rótulos em uma única avaliação do DOM. Isso evita esperar 30s por
    // um nth() que deixou de existir após o LinkedIn atualizar o cartão.
    const candidates = await card.locator('button').evaluateAll(nodes =>
      nodes.slice(0, 60).map((node, index) => ({
        index,
        label: [
          node.getAttribute('aria-label') || '',
          node.innerText || node.textContent || '',
        ].join(' '),
      }))
    ).catch(() => []);

    const candidate = candidates.find(item => labels.test(item.label));
    if (!candidate) break;

    const clicked = await card.locator('button').nth(candidate.index)
      .click({ timeout: 1200 }).then(() => true).catch(() => false);

    if (!clicked) continue;
    await page.waitForTimeout(250);
  }
}

async function enrichPost(page, post) {
  await page.goto(post.postUrl, {
    waitUntil: 'domcontentloaded',
    timeout: 15000,
  });

  await page.waitForTimeout(900);

  const bodyText =
    (await page.locator('main').innerText().catch(() => '')) ||
    (await page.locator('body').innerText().catch(() => ''));

  if (/\b(promoted|patrocinado|promovido)\b/i.test(bodyText.slice(0, 1800))) {
    post.isPromoted = true;
    return post;
  }

  const moreLabels =
    /show more comments|view more comments|ver mais comentarios|mostrar mais comentarios|mais comentarios/i;

  for (let attempt = 0; attempt < 2; attempt++) {
    const buttons = page.getByRole('button');
    const count = Math.min(await buttons.count(), 80);

    let clicked = false;

    for (let i = 0; i < count; i++) {
      const button = buttons.nth(i);

      const label =
        (await button.getAttribute('aria-label').catch(() => '')) +
        ' ' +
        (await button.innerText().catch(() => ''));

      if (moreLabels.test(label)) {
        await button.click({ timeout: 1200 }).catch(() => {});
        await page.waitForTimeout(300);
        clicked = true;
        break;
      }
    }

    if (!clicked) break;
  }

  const postContainer = page.locator(
    'main [componentkey^="update-card-focus"], main article, main [data-testid="expandable-text-box"]'
  ).first();

  const postTextNode = page.locator(
    'main [data-testid="expandable-text-box"]'
  ).first();

  const focusedPostText =
    (await postTextNode.innerText().catch(() => '')).trim() ||
    (await postContainer.innerText().catch(() => '')).trim() ||
    post.text;

  const commentNodes = await postContainer.locator(
    '.comments-comment-item, .comments-comment-entity, [class*="comments-comment-item"], [data-test-id*="comment"]'
  ).all().catch(() => []);

  const comments = [];

  for (const node of commentNodes.slice(0, 60)) {
    const value = (await node.innerText().catch(() => '')).trim();

    if (value && !comments.some(comment => comment.text === value)) {
      comments.push({ text: value });
    }
  }

  const links = await postContainer.locator('a[href]').evaluateAll(nodes =>
    nodes
      .map(a => a.href)
      .filter(url => /^https?:/i.test(url) && !isLinkedInUrl(url))
  ).catch(() => []);

  const allText = [
    bodyText,
    ...comments.map(comment => comment.text),
  ].join('\n');

  const emails = [
    ...new Set(
      [...allText.matchAll(
        /[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/gi
      )].map(match => match[0].toLowerCase())
    ),
  ];

  post.text = focusedPostText.slice(0, 12000) || post.text;
  post.topComments = comments;
  post.links = [...new Set([...(post.links ?? []), ...links])];
  post.emails = emails;
  post.searchSnippet = post.text.slice(0, 1000);

  return post;
}

async function copyPostLink(page, card) {
  const menuButton = card.getByRole('button', {
    name: /^(Abrir menu de controle da publicação de|Open control menu for post by)/i,
  }).first();

  const copyItem = page.getByRole('menuitem', {
    name: /^(Copiar link da publicação|Copy link to post)$/i,
  }).first();

  try {
    await page.context().grantPermissions(
      ['clipboard-read', 'clipboard-write'],
      { origin: linkedinBaseUrl }
    );

    await page.evaluate(() => navigator.clipboard.writeText(''));

    await menuButton.click({ timeout: 1800 });

    await copyItem.waitFor({
      state: 'visible',
      timeout: 1800,
    });

    await copyItem.click({ timeout: 1800 });

    for (let attempt = 0; attempt < 4; attempt++) {
      await page.waitForTimeout(150);

      const copied = await page.evaluate(
        () => navigator.clipboard.readText()
      ).catch(() => '');

      if (copied) {
        const resolved = new URL(copied, linkedinBaseUrl);

        resolved.search = '';
        resolved.hash = '';

        if (isLinkedInPostUrl(resolved.href)) {
          return resolved.href;
        }
      }
    }
  } catch {
    await page.keyboard.press('Escape').catch(() => {});
  }

  return null;
}

async function extractCard(page, card, keyword) {
  const text = (await card.innerText().catch(() => '')).trim();

  if (!text || /\b(promoted|patrocinado|promovido)\b/i.test(text)) {
    return null;
  }

  const componentKey = await card.getAttribute('componentkey').catch(() => null);

  const anchors = await card.locator('a[href]').evaluateAll(nodes =>
    nodes.map(a => ({
      href: a.href,
      text: (a.innerText ?? '').trim(),
      aria: a.getAttribute('aria-label') ?? '',
    }))
  );

  const postAnchor = anchors.find(a => isLinkedInPostUrl(a.href));

  let postUrl = null;

  if (postAnchor) {
    const href = new URL(postAnchor.href);
    href.search = '';
    href.hash = '';
    postUrl = href.toString();
  } else if (componentKey) {
    postUrl = await copyPostLink(page, card);
  }

  if (!postUrl) return null;

  if (
    knownPosts.ids.has(canonicalPostUrl(postUrl)) ||
    knownPosts.urls.has(normalizePostUrl(postUrl))
  ) {
    return null;
  }

  await expandComments(page, card);

  const lines = text
    .split('\n')
    .map(line => line.replace(/\s+/g, ' ').trim())
    .filter(Boolean);

  const menuLabel = await card.locator('[aria-label]').evaluateAll(nodes =>
    nodes
      .map(node => node.getAttribute('aria-label') || '')
      .find(label =>
        /^(Abrir menu de controle da publicação de|Open control menu for post by)\s+/i.test(label)
      ) || ''
  ).catch(() => '');

  const authorMatch = menuLabel.match(
    /^(?:Abrir menu de controle da publicação de|Open control menu for post by)\s+(.+)$/i
  );

  const authorName = authorMatch?.[1]?.trim() || null;

  const timeCandidates = await card.locator('time').evaluateAll(nodes =>
    nodes.flatMap(node => [
      node.getAttribute('datetime'),
      node.innerText,
      node.getAttribute('aria-label'),
    ]).filter(Boolean)
  );

  const timestampText = lines.find(line =>
    /^(?:há\s*)?\d+\s*(?:min|minuto|minutos|h|hr|hrs|hora|horas|d|dia|dias|sem|semana|semanas|mo|mês|meses|a|ano|anos)\b/i.test(line)
  ) || '';

  const date = parseDate(
    timestampText,
    timeCandidates.find(value =>
      value &&
      !Number.isNaN(Date.parse(value)) &&
      /^\d{4}-\d\d-\d\d/.test(value)
    )
  );

  const diagnostic = keywordDiagnostics.get(keyword) ?? {
    cards: 0,
    samples: [],
  };

  diagnostic.cards++;

  if (diagnostic.samples.length < 5) {
    diagnostic.samples.push({
      componentKey,
      authorName,
      timestampText,
      timeCandidates,
      parsedDate: date?.toISOString() ?? null,
      postUrl,
      text: text.slice(0, 240),
    });
  }

  keywordDiagnostics.set(keyword, diagnostic);

  if (
    !date ||
    date < new Date(Date.now() - lookbackHours * 3600000) ||
    date > new Date(Date.now() + 7200000)
  ) {
    return null;
  }

  const expandable = card.locator(
    '[data-testid="expandable-text-box"]'
  ).first();

  const postText =
    (await expandable.innerText().catch(() => '')).trim() || text;

  const authorLink = anchors.find(a =>
    isLinkedInUrl(a.href) &&
    /\/(?:in|company)\//i.test(new URL(a.href).pathname) &&
    (!authorName || a.text.includes(authorName) || a.aria.includes(authorName))
  );

  const links = [
    ...new Set(
      anchors
        .map(a => a.href)
        .filter(url => /^https?:/i.test(url) && !isLinkedInUrl(url))
    ),
  ];

  const comments = [];

  const commentNodes = await card.locator(
    '[data-test-id*="comment"], .comments-comment-item, .comments-comment-entity, [class*="comments-comment-item"]'
  ).all();

  for (const node of commentNodes.slice(0, 40)) {
    const commentText = (await node.innerText().catch(() => '')).trim();

    if (
      commentText &&
      !comments.some(comment => comment.text === commentText)
    ) {
      comments.push({ text: commentText });
    }
  }

  const emailSource = [
    postText,
    ...comments.map(comment => comment.text),
  ].join('\n');

  const emailMatches = [
    ...emailSource.matchAll(
      /[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/gi
    ),
  ].map(match => match[0].toLowerCase());

  const postLines = postText
    .split('\n')
    .map(line => line.replace(/\s+/g, ' ').trim())
    .filter(Boolean);

  const titleLine = postLines.find(line =>
    /vaga|oportunidade|suporte|help.?desk|service.?desk|technical support|desenvolvedor|developer|programador|analista|estágio|junior|júnior|n1\b/i.test(line)
  ) || postLines[0] || keyword;

  return {
    postId: canonicalPostUrl(postUrl),
    postUrl,
    title: titleLine.slice(0, 255),
    text: postText,
    datePosted: date.toISOString(),
    matchedKeyword: keyword,
    authorName,
    authorUrl: authorLink?.href || null,
    authorHeadline: null,
    authorType: null,
    topComments: comments,
    links,
    searchProvider: 'linkedin-secondary-account',
    searchSnippet: postText.slice(0, 1000),
    emails: [...new Set(emailMatches)],
  };
}

async function launchBrowser() {
  const launchOptions = {
    ...(browserExecutable ? { executablePath: browserExecutable } : {}),
    headless,
    args: chromiumArgs(headless),
  };

  mkdirSync(profile, { recursive: true });

  // Um perfil Chromium restaurado do cache já contém cookies e armazenamento
  // local. O storage-state só é usado como bootstrap na primeira execução.
  const profileInitialized =
    existsSync(path.join(profile, 'Local State')) ||
    existsSync(path.join(profile, 'Default'));

  const contextOptions = {
    ...launchOptions,
    viewport: { width: 1440, height: 1000 },
    locale: 'pt-BR',
    ...(storageStatePath && existsSync(storageStatePath) && !profileInitialized
      ? { storageState: storageStatePath }
      : {}),
  };

  const persistent = await launchPersistentBrowser({
    chromium,
    profileDir: profile,
    contextOptions,
  });

  return {
    browser: null,
    context: persistent.context,
    close: persistent.close,
  };
}

async function logAuthenticationDiagnostics(context, page) {
  const cookies = await context.cookies(linkedinBaseUrl).catch(() => []);
  const authCookie = cookies.find(cookie => cookie.name === 'li_at');
  let currentUrl;

  try {
    const url = new URL(page.url());
    currentUrl = `${url.origin}${url.pathname}`;
  } catch {
    currentUrl = 'URL indisponível';
  }

  const diagnostics = {
    currentUrl,
    pageTitle: await page.title().catch(() => 'Título indisponível'),
    linkedinCookieCount: cookies.length,
    cookieNames: cookies.map(cookie => cookie.name).sort(),
    liAtPresent: Boolean(authCookie),
    liAtExpiresAt: authCookie?.expires > 0
      ? new Date(authCookie.expires * 1000).toISOString()
      : authCookie ? 'cookie de sessão' : null,
  };

  process.stderr.write(
    '\\n[LinkedIn authentication diagnostics]\\n' +
    JSON.stringify(diagnostics, null, 2) +
    '\\n'
  );
}

async function main() {
  if (!['login', 'search', 'posts', 'jobs', 'all'].includes(mode)) {
    throw new Error(
      `Modo inválido: ${mode}. Use login, search, posts, jobs ou all.`
    );
  }

  const { browser, context, close } = await launchBrowser();

  try {
    const page = context.pages()[0] ?? await context.newPage();

    if (mode === 'login') {
      await page.goto(`${linkedinBaseUrl}/login`, {
        waitUntil: 'domcontentloaded',
      });

      const rl = createInterface({
        input: stdin,
        output: stdout,
      });

      await rl.question(
        'Entre manualmente na conta SECUNDÁRIA. Depois de entrar, pressione ENTER aqui para salvar a sessão local. '
      );

      rl.close();

      const stateFile = path.join(profile, 'storage-state.json');

      mkdirSync(profile, { recursive: true });

      await context.storageState({ path: stateFile });

      process.stdout.write(
        `Sessão local salva. Arquivo de estado: ${stateFile}\n`
      );

      return;
    }

    if (!isLinkedInUrl(page.url())) {
      await page.goto(`${linkedinBaseUrl}/feed/`, {
        waitUntil: 'domcontentloaded',
      });
    }

    await page.waitForTimeout(1800);

    if (/\/(login|checkpoint|challenge)/i.test(new URL(page.url()).pathname)) {
      await logAuthenticationDiagnostics(context, page);
      throw new Error(
        'A sessão da conta secundária não está autenticada. Execute npm run linkedin:secondary:login.'
      );
    }

    if (mode === 'jobs') {
      process.stderr.write(
        '\n[LinkedIn] Iniciando coleta de vagas.\n'
      );

      const vacancies = await collectLinkedInJobs(page);

      process.stdout.write(JSON.stringify(vacancies));

      return;
    }

    const posts = new Map();

    for (const keyword of keywords) {
      if (timeBudget.expired()) {
        process.stderr.write(
          '\n[LinkedIn Posts] Orçamento de tempo esgotado; encerrando as pesquisas restantes.\n'
        );
        break;
      }

      try {
      const searchUrl =
        `${linkedinBaseUrl}/search/results/content/?keywords=` +
        encodeURIComponent(keyword) +
        '&origin=GLOBAL_SEARCH_HEADER&datePosted=%22past-24h%22&sortBy=%22date_posted%22';

      process.stderr.write(
        `\n[LinkedIn Posts] Pesquisa: ${keyword}\n`
      );

      await page.goto(searchUrl, {
        waitUntil: 'domcontentloaded',
      });

      await page.waitForTimeout(900);

      const diagnostic = keywordDiagnostics.get(keyword) ?? {
        cards: 0,
        samples: [],
        pages: 0,
        cardElements: 0,
      };
      diagnostic.pages++;
      keywordDiagnostics.set(keyword, diagnostic);

      for (let pageNo = 0; pageNo < 2; pageNo++) {
        const cards = page.locator(
          '[componentkey^="update-card-focus"], [data-urn^="urn:li:activity"], [data-id^="urn:li:activity"], [data-urn^="urn:li:aggregate"]'
        );

        const count = Math.min(await cards.count(), 12);

        const pageDiagnostic = keywordDiagnostics.get(keyword) ?? {
          cards: 0,
          samples: [],
        };

        pageDiagnostic.cardElements =
          (pageDiagnostic.cardElements ?? 0) + count;

        pageDiagnostic.postContainers =
          '[componentkey^="update-card-focus"], activity/aggregate data-urn/data-id';

        keywordDiagnostics.set(keyword, pageDiagnostic);

        for (let i = 0; i < count; i++) {
          const card = cards.nth(i);

          const post = await extractCard(page, card, keyword);

          if (post) {
            const key = canonicalPostUrl(post.postUrl);
            const existing = posts.get(key);

            if (!existing) {
              posts.set(key, {
                ...post,
                matchedKeywords: [keyword],
              });
            } else {
              existing.matchedKeywords = [
                ...new Set([
                  ...(existing.matchedKeywords ?? [existing.matchedKeyword]),
                  keyword,
                ]),
              ];

              existing.links = [
                ...new Set([
                  ...(existing.links ?? []),
                  ...(post.links ?? []),
                ]),
              ];

              existing.emails = [
                ...new Set([
                  ...(existing.emails ?? []),
                  ...(post.emails ?? []),
                ]),
              ];

              if (
                (!existing.authorName || !existing.authorUrl) &&
                (post.authorName || post.authorUrl)
              ) {
                existing.authorName =
                  existing.authorName || post.authorName;

                existing.authorUrl =
                  existing.authorUrl || post.authorUrl;
              }
            }
          }
        }

        await page.mouse.wheel(0, 1800);
        await page.waitForTimeout(400);
      }
      } catch (error) {
        // Uma pesquisa com falha não derruba as demais.
        process.stderr.write(
          `[LinkedIn Posts] Falha na pesquisa "${keyword}": ${error?.message ?? error}\n`
        );
      }
    }

    // O cartão já foi expandido e os links, e-mails e comentários públicos
    // foram extraídos durante a busca. Evita abrir até 25 URLs individualmente.
    const enriched = [...posts.values()].sort((a, b) => Date.parse(b.datePosted ?? 0) - Date.parse(a.datePosted ?? 0));

    process.stderr.write(
      '[LinkedIn secondary diagnostics]\n' +
      JSON.stringify(Object.fromEntries(keywordDiagnostics), null, 2) +
      '\n'
    );

    if (mode === 'all') {
      process.stderr.write(
        '\n[LinkedIn] Iniciando também a coleta de vagas.\n'
      );

      const vacancies = await collectLinkedInJobs(page);

      process.stdout.write(
        JSON.stringify({
          posts: enriched,
          vacancies,
        })
      );
    } else {
      process.stdout.write(JSON.stringify(enriched));
    }
  } finally {
    if (close) {
      await close();
    } else {
      await context.close();

      if (browser) {
        await browser.close();
      }
    }
  }
}

main().catch(error => {
  console.error(error?.message ?? String(error));
  process.exitCode = 1;
});
