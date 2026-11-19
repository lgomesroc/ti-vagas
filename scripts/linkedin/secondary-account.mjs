import { chromium } from 'playwright';
import { createInterface } from 'node:readline/promises';
import { stdin, stdout } from 'node:process';
import path from 'node:path';
import { existsSync } from 'node:fs';

const profile = path.resolve(process.env.LINKEDIN_SECONDARY_ACCOUNT_PROFILE ?? '.local/linkedin-secondary-profile');
const edgeCandidates = [
  path.join(process.env['PROGRAMFILES(X86)'] ?? 'C:\\Program Files (x86)', 'Microsoft', 'Edge', 'Application', 'msedge.exe'),
  path.join(process.env.PROGRAMFILES ?? 'C:\\Program Files', 'Microsoft', 'Edge', 'Application', 'msedge.exe'),
];
const browserExecutable = process.env.LINKEDIN_SECONDARY_ACCOUNT_EXECUTABLE ??
  edgeCandidates.find(candidate => existsSync(candidate));
const mode = process.argv[2] ?? 'search';
const keywords = [
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
  'desenvolvedor frontend júnior',
  'desenvolvedor full stack júnior',
  'programador júnior vaga',
  'junior software engineer',
  'junior web developer',
  'backend developer junior',
  'frontend developer junior',
  'full stack developer junior',
  // Linguagens e ecossistemas
  'vaga desenvolvedor PHP Laravel',
  'vaga desenvolvedor C# .NET',
  'vaga desenvolvedor Java júnior',
  'vaga desenvolvedor Python júnior',
  'vaga desenvolvedor JavaScript TypeScript',
  'vaga desenvolvedor Node.js júnior',
  'vaga React Angular júnior',
  'estágio desenvolvimento software',
];
const lookbackHours = Number(process.env.LINKEDIN_POSTS_LOOKBACK_HOURS ?? 72);
const keywordDiagnostics = new Map();

function canonicalPostUrl(value) {
  try {
    const url = new URL(value);
    const activity = url.pathname.match(/urn:li:(?:activity|share):(\\d+)/i);
    if (activity) return `linkedin:post:${activity[1]}`;
    const slug = url.pathname.match(/\\/posts\\/([^/?]+)/i);
    if (slug) return `linkedin:post:${slug[1].replace(/-[a-f0-9]{8,}$/i, '')}`.toLowerCase();
    url.search = '';
    url.hash = '';
    return url.href.replace(/\\/$/, '').toLowerCase();
  } catch {
    return String(value).split(/[?#]/, 1)[0].replace(/\\/$/, '').toLowerCase();
  }
}

function parseDate(text, datetime) {
  if (datetime && !Number.isNaN(Date.parse(datetime))) return new Date(datetime);
  const value = (text ?? '').toLowerCase().trim();
  // Unidades longas vêm antes das abreviações para não ler "9 meses" como "9 minutos".
  const match = value.match(/\b(\d+)\s*(meses?|mos?|months?|minutos?|mins?|min|horas?|hrs?|h|dias?|days?|d|semanas?|sems?|weeks?|wks?|w|anos?|years?|yrs?|y)\b/i);
  if (match) {
    const amount = Number(match[1]);
    const unit = match[2].toLowerCase();
    const ms = /^(mes|meses|mo|mos|month|months)$/.test(unit) ? amount * 30 * 86400000
      : /^(ano|anos|year|years|yr|yrs|y)$/.test(unit) ? amount * 365 * 86400000
      : /^(hora|horas|hr|hrs|h)$/.test(unit) ? amount * 3600000
      : /^(dia|dias|day|days|d)$/.test(unit) ? amount * 86400000
      : /^(sem|sems|semana|semanas|week|weeks|wk|wks|w)$/.test(unit) ? amount * 604800000
      : amount * 60000;
    return new Date(Date.now() - ms);
  }
  if (/\b(hoje|today|agora|just now)\b/.test(value)) return new Date();
  if (/\b(ontem|yesterday)\b/.test(value)) return new Date(Date.now() - 86400000);
  return null;
}

async function expandComments(page, card) {
  const labels = /show more comments|view more comments|ver mais comentarios|mostrar mais comentarios|mais comentarios/i;
  for (let i = 0; i < 2; i++) {
    const buttons = card.getByRole('button');
    const count = Math.min(await buttons.count(), 60);
    let clicked = false;
    for (let j = 0; j < count; j++) {
      const button = buttons.nth(j);
      const label = ((await button.getAttribute('aria-label')) ?? '') + ' ' + ((await button.innerText({ timeout: 1000 }).catch(() => '')) ?? '');
      if (labels.test(label)) {
        await button.click({ timeout: 1500 }).catch(() => {});
        await page.waitForTimeout(300);
        clicked = true;
        break;
      }
    }
    if (!clicked) break;
  }
}

async function enrichPost(page, post) {
  await page.goto(post.postUrl, { waitUntil: 'domcontentloaded', timeout: 15000 });
  await page.waitForTimeout(900);

  const bodyText = (await page.locator('main').innerText().catch(() => '')) ||
    (await page.locator('body').innerText().catch(() => ''));
  if (/\b(promoted|patrocinado|promovido)\b/i.test(bodyText.slice(0, 1800))) {
    post.isPromoted = true;
    return post;
  }

  const moreLabels = /show more comments|view more comments|ver mais comentarios|mostrar mais comentarios|mais comentarios/i;
  for (let attempt = 0; attempt < 2; attempt++) {
    const buttons = page.getByRole('button');
    const count = Math.min(await buttons.count(), 80);
    let clicked = false;
    for (let i = 0; i < count; i++) {
      const button = buttons.nth(i);
      const label = ((await button.getAttribute('aria-label').catch(() => '')) ?? '') +
        ' ' + ((await button.innerText().catch(() => '')) ?? '');
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
  const postTextNode = page.locator('main [data-testid="expandable-text-box"]').first();
  const focusedPostText = (await postTextNode.innerText().catch(() => '')).trim()
    || (await postContainer.innerText().catch(() => '')).trim()
    || post.text;

  const commentNodes = await postContainer.locator(
    '.comments-comment-item, .comments-comment-entity, [class*="comments-comment-item"], [data-test-id*="comment"]'
  ).all().catch(() => []);
  const comments = [];
  for (const node of commentNodes.slice(0, 60)) {
    const value = (await node.innerText().catch(() => '')).trim();
    if (value && !comments.some(comment => comment.text === value)) comments.push({ text: value });
  }

  const links = await postContainer.locator('a[href]').evaluateAll(nodes =>
    nodes.map(a => a.href).filter(url => /^https?:/i.test(url) && !/linkedin\.com/i.test(url))
  ).catch(() => []);
  const allText = [bodyText, ...comments.map(comment => comment.text)].join('\n');
  const emails = [...new Set([...allText.matchAll(/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/gi)].map(match => match[0].toLowerCase()))];

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
      { origin: 'https://www.linkedin.com' }
    );
    await page.evaluate(() => navigator.clipboard.writeText(''));
    await menuButton.click({ timeout: 1800 });
    await copyItem.waitFor({ state: 'visible', timeout: 1800 });
    await copyItem.click({ timeout: 1800 });

    for (let attempt = 0; attempt < 4; attempt++) {
      await page.waitForTimeout(150);
      const copied = await page.evaluate(() => navigator.clipboard.readText()).catch(() => '');
      if (copied) {
        const resolved = new URL(copied, 'https://www.linkedin.com');
        resolved.search = '';
        resolved.hash = '';
        if (/linkedin\.com\/(?:feed\/update|posts)\//i.test(resolved.href)) {
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
  if (!text || /\b(promoted|patrocinado|promovido)\b/i.test(text)) return null;

  const componentKey = await card.getAttribute('componentkey').catch(() => null);
  const anchors = await card.locator('a[href]').evaluateAll(nodes => nodes.map(a => ({
    href: a.href,
    text: (a.innerText ?? '').trim(),
    aria: a.getAttribute('aria-label') ?? '',
  })));

  // O layout atual não expõe necessariamente o permalink como <a>.
  // Nesse caso usamos a ação oficial "Copiar link da publicação".
  const postAnchor = anchors.find(a => /linkedin\.com\/(?:feed\/update|posts)\//i.test(a.href));
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

  const lines = text.split('\n').map(line => line.replace(/\s+/g, ' ').trim()).filter(Boolean);
  const menuLabel = await card.locator('[aria-label]').evaluateAll(nodes =>
    nodes.map(node => node.getAttribute('aria-label') || '')
      .find(label => /^(Abrir menu de controle da publicação de|Open control menu for post by)\s+/i.test(label)) || ''
  ).catch(() => '');
  const authorMatch = menuLabel.match(/^(?:Abrir menu de controle da publicação de|Open control menu for post by)\s+(.+)$/i);
  const authorName = authorMatch?.[1]?.trim() || null;

  const timeCandidates = await card.locator('time').evaluateAll(nodes => nodes.flatMap(node => [
    node.getAttribute('datetime'),
    node.innerText,
    node.getAttribute('aria-label'),
  ]).filter(Boolean));
  const timestampText = lines.find(line =>
    /^(?:há\s*)?\d+\s*(?:min|minuto|minutos|h|hr|hrs|hora|horas|d|dia|dias|sem|semana|semanas|mo|mês|meses|a|ano|anos)\b/i.test(line)
  ) || '';
  const date = parseDate(timestampText, timeCandidates.find(value =>
    value && !Number.isNaN(Date.parse(value)) && /^\d{4}-\d\d-\d\d/.test(value)
  ));
  const diagnostic = keywordDiagnostics.get(keyword) ?? { cards: 0, samples: [] };
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

  if (!date || date < new Date(Date.now() - lookbackHours * 3600000) ||
      date > new Date(Date.now() + 7200000)) return null;

  const expandable = card.locator('[data-testid="expandable-text-box"]').first();
  const postText = (await expandable.innerText().catch(() => '')).trim() || text;
  const authorLink = anchors.find(a => /linkedin\.com\/(?:in|company)\//i.test(a.href) &&
    (!authorName || a.text.includes(authorName) || a.aria.includes(authorName)));
  const links = [...new Set(anchors.map(a => a.href)
    .filter(url => /^https?:/i.test(url) && !/linkedin\.com/i.test(url)))];
  const comments = [];
  const commentNodes = await card.locator(
    '[data-test-id*="comment"], .comments-comment-item, .comments-comment-entity, [class*="comments-comment-item"]'
  ).all();
  for (const node of commentNodes.slice(0, 40)) {
    const commentText = (await node.innerText().catch(() => '')).trim();
    if (commentText && !comments.some(comment => comment.text === commentText)) {
      comments.push({ text: commentText });
    }
  }

  const emailMatches = [...postText.matchAll(/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/gi)]
    .map(match => match[0].toLowerCase());
  const postLines = postText.split('\n').map(line => line.replace(/\s+/g, ' ').trim()).filter(Boolean);
  const titleLine = postLines.find(line =>
    /vaga|oportunidade|suporte|help.?desk|service.?desk|technical support|desenvolvedor|developer|programador|analista|estágio|junior|júnior|n1\b/i.test(line)
  ) || postLines[0] || keyword;

  return {
    postId: componentKey || Buffer.from(postUrl).toString('base64url'),
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

async function main() {
  const context = await chromium.launchPersistentContext(profile, {
    ...(browserExecutable ? { executablePath: browserExecutable } : {}),
    headless: false,
    viewport: { width: 1440, height: 1000 },
    locale: 'pt-BR',
    args: [],
  });

  try {
    const page = context.pages()[0] ?? await context.newPage();
    if (mode === 'login') {
      await page.goto('https://www.linkedin.com/login', { waitUntil: 'domcontentloaded' });
      const rl = createInterface({ input: stdin, output: stdout });
      await rl.question('Entre manualmente na conta SECUNDÁRIA. Depois de entrar, pressione ENTER aqui para salvar a sessão local. ');
      rl.close();
      process.stdout.write('Sessão da conta secundária disponível no perfil local.\n');
      return;
    }

    if (!page.url().includes('linkedin.com')) {
      await page.goto('https://www.linkedin.com/feed/', { waitUntil: 'domcontentloaded' });
    }
    await page.waitForTimeout(1800);
    if (/\/(login|checkpoint|challenge)/i.test(new URL(page.url()).pathname)) {
      throw new Error('A sessão da conta secundária não está autenticada. Execute npm run linkedin:secondary:login.');
    }

    const posts = new Map();
    for (const keyword of keywords) {
      const searchUrl = 'https://www.linkedin.com/search/results/content/?keywords=' + encodeURIComponent(keyword) + '&origin=GLOBAL_SEARCH_HEADER&datePosted=%22past-week%22&sortBy=%22date_posted%22';
      await page.goto(searchUrl, { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(2200);
      const diagnostic = keywordDiagnostics.get(keyword) ?? { cards: 0, samples: [] };
      diagnostic.searchUrl = page.url();
      diagnostic.pageTitle = await page.title().catch(() => '');
      diagnostic.bodyText = (await page.locator('body').innerText().catch(() => '')).slice(0, 1800);
      diagnostic.mainText = (await page.locator('main').innerText().catch(() => '')).slice(0, 1200);
      diagnostic.selectorCounts = {
        urnDivs: await page.locator('div[data-urn]').count().catch(() => -1),
        feedUpdates: await page.locator('div.feed-shared-update-v2').count().catch(() => -1),
        reusableResults: await page.locator('li.reusable-search__result-container').count().catch(() => -1),
        articles: await page.locator('main article').count().catch(() => -1),
        listItems: await page.locator('main li').count().catch(() => -1),
      };
      diagnostic.listItemSamples = await page.locator('main li').evaluateAll(nodes => nodes.slice(0, 10).map(node => ({
        className: typeof node.className === 'string' ? node.className : '',
        text: (node.innerText || '').slice(0, 700),
        links: [...node.querySelectorAll('a[href]')].slice(0, 12).map(a => ({
          text: (a.innerText || '').trim().slice(0, 100),
          href: a.href
        })),
        time: [...node.querySelectorAll('time')].slice(0, 3).map(t => ({
          text: (t.innerText || '').trim(),
          datetime: t.getAttribute('datetime'),
          ariaLabel: t.getAttribute('aria-label')
        }))
      }))).catch(() => []);
      diagnostic.timestampAncestors = await page.locator('main').evaluate(main => {
        const candidates = [...main.querySelectorAll('*')].filter(node => {
          const text = (node.innerText || '').trim();
          return text.length >= 250 && text.length <= 10000 &&
            /\\b\\d+\\s*(min|minuto|minutos|h|hr|hora|horas|d|dia|dias)\\b/i.test(text) &&
            ![...node.children].some(child => {
              const childText = (child.innerText || '').trim();
              return childText.length >= 250 && childText.length <= 10000 &&
                /\\b\\d+\\s*(min|minuto|minutos|h|hr|hora|horas|d|dia|dias)\\b/i.test(childText);
            });
        }).slice(0, 5);
        return candidates.map(node => {
          const chain = [];
          let current = node;
          for (let i = 0; current && i < 7; i++, current = current.parentElement) {
            chain.push({
              tag: current.tagName,
              className: typeof current.className === 'string' ? current.className : '',
              role: current.getAttribute('role'),
              dataUrn: current.getAttribute('data-urn'),
              text: (current.innerText || '').slice(0, 900),
              links: [...current.querySelectorAll('a[href]')].slice(0, 8).map(a => ({text:(a.innerText||'').trim().slice(0,80),href:a.href})),
              times: [...current.querySelectorAll('time')].slice(0, 3).map(t => ({text:(t.innerText||'').trim(),datetime:t.getAttribute('datetime'),aria:t.getAttribute('aria-label')}))
            });
          }
          return chain;
        });
      }).catch(() => []);
      keywordDiagnostics.set(keyword, diagnostic);

      for (let pageNo = 0; pageNo < 2; pageNo++) {
        const cards = page.locator('[componentkey^="update-card-focus"], [data-urn^="urn:li:activity"], [data-id^="urn:li:activity"], [data-urn^="urn:li:aggregate"]');
        const count = Math.min(await cards.count(), 12);
        const diagnostic = keywordDiagnostics.get(keyword) ?? { cards: 0, samples: [] };
        diagnostic.cardElements = (diagnostic.cardElements ?? 0) + count;
        diagnostic.postContainers = '[componentkey^="update-card-focus"], activity/aggregate data-urn/data-id';
        keywordDiagnostics.set(keyword, diagnostic);
        for (let i = 0; i < count; i++) {
          const card = cards.nth(i);
          await expandComments(page, card);
          const post = await extractCard(page, card, keyword);
          if (post) {
            const key = canonicalPostUrl(post.postUrl);
            const existing = posts.get(key);
            if (!existing) {
              posts.set(key, { ...post, matchedKeywords: [keyword] });
            } else {
              existing.matchedKeywords = [...new Set([...(existing.matchedKeywords ?? [existing.matchedKeyword]), keyword])];
              existing.links = [...new Set([...(existing.links ?? []), ...(post.links ?? [])])];
              existing.emails = [...new Set([...(existing.emails ?? []), ...(post.emails ?? [])])];
              if ((!existing.authorName || !existing.authorUrl) && (post.authorName || post.authorUrl)) {
                existing.authorName = existing.authorName || post.authorName;
                existing.authorUrl = existing.authorUrl || post.authorUrl;
              }
            }
          }
        }
        await page.mouse.wheel(0, 1800);
        await page.waitForTimeout(900);
      }
      await page.waitForTimeout(1100);
    }

    const enriched = [];
    for (const post of [...posts.values()].slice(0, 25)) {
      try {
        enriched.push(await enrichPost(page, post));
      } catch {
        enriched.push(post);
      }
      await page.waitForTimeout(350);
    }

    process.stderr.write('[LinkedIn secondary diagnostics]\\n' + JSON.stringify(Object.fromEntries(keywordDiagnostics), null, 2) + '\\n');
    process.stdout.write(JSON.stringify(enriched));
  } finally {
    await context.close();
  }
}

main().catch(error => {
  console.error(error?.message ?? String(error));
  process.exitCode = 1;
});
