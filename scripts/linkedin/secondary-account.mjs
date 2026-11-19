import { chromium } from 'playwright';
import { createInterface } from 'node:readline/promises';
import { stdin, stdout } from 'node:process';
import path from 'node:path';
import { existsSync } from 'node:fs';

const profile = path.resolve(process.env.LINKEDIN_SECONDARY_ACCOUNT_PROFILE ?? '.local/linkedin-secondary-profile');
const defaultChromePath = path.join(process.env.PROGRAMFILES ?? 'C:\\Program Files', 'Google', 'Chrome', 'Application', 'chrome.exe');
const chromeExecutable = process.env.LINKEDIN_SECONDARY_ACCOUNT_EXECUTABLE ??
  (existsSync(defaultChromePath) ? defaultChromePath : undefined);
const mode = process.argv[2] ?? 'search';
const keywords = [
  'vaga suporte técnico',
  'analista de suporte júnior',
  'suporte técnico júnior',
  'help desk júnior',
  'service desk júnior',
  'technical support junior',
];
const lookbackHours = Number(process.env.LINKEDIN_POSTS_LOOKBACK_HOURS ?? 72);

function parseDate(text, datetime) {
  if (datetime && !Number.isNaN(Date.parse(datetime))) return new Date(datetime);
  const value = (text ?? '').toLowerCase();
  const match = value.match(/(\d+)\s*(m|min|minuto|minutos|h|hr|hora|horas|d|dia|dias|w|semana|semanas)/);
  if (match) {
    const amount = Number(match[1]);
    const unit = match[2];
    const ms = unit.startsWith('m') ? amount * 60000
      : (unit.startsWith('h') ? amount * 3600000
      : (unit.startsWith('w') || unit.startsWith('s') ? amount * 604800000 : amount * 86400000));
    return new Date(Date.now() - ms);
  }
  if (/\b(hoje|today)\b/.test(value)) return new Date(new Date().setHours(0,0,0,0));
  if (/\b(ontem|yesterday)\b/.test(value)) return new Date(Date.now() - 86400000);
  return null;
}

async function expandComments(page, card) {
  const labels = /show more comments|view more comments|ver mais comentarios|mostrar mais comentarios|mais comentarios/i;
  for (let i = 0; i < 4; i++) {
    const buttons = card.getByRole('button');
    const count = Math.min(await buttons.count(), 60);
    let clicked = false;
    for (let j = 0; j < count; j++) {
      const button = buttons.nth(j);
      const label = ((await button.getAttribute('aria-label')) ?? '') + ' ' + ((await button.innerText({ timeout: 1000 }).catch(() => '')) ?? '');
      if (labels.test(label)) {
        await button.click({ timeout: 1500 }).catch(() => {});
        await page.waitForTimeout(500);
        clicked = true;
        break;
      }
    }
    if (!clicked) break;
  }
}

async function enrichPost(page, post) {
  await page.goto(post.postUrl, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1400);

  const bodyText = (await page.locator('main').innerText().catch(() => '')) ||
    (await page.locator('body').innerText().catch(() => ''));
  if (/\b(promoted|patrocinado|promovido)\b/i.test(bodyText.slice(0, 1800))) {
    post.isPromoted = true;
    return post;
  }

  const moreLabels = /show more comments|view more comments|ver mais comentarios|mostrar mais comentarios|mais comentarios/i;
  for (let attempt = 0; attempt < 5; attempt++) {
    const buttons = page.getByRole('button');
    const count = Math.min(await buttons.count(), 80);
    let clicked = false;
    for (let i = 0; i < count; i++) {
      const button = buttons.nth(i);
      const label = ((await button.getAttribute('aria-label').catch(() => '')) ?? '') +
        ' ' + ((await button.innerText().catch(() => '')) ?? '');
      if (moreLabels.test(label)) {
        await button.click({ timeout: 1200 }).catch(() => {});
        await page.waitForTimeout(450);
        clicked = true;
        break;
      }
    }
    if (!clicked) break;
  }

  const commentNodes = await page.locator(
    '.comments-comment-item, .comments-comment-entity, [class*="comments-comment-item"], [data-test-id*="comment"]'
  ).all();
  const comments = [];
  for (const node of commentNodes.slice(0, 60)) {
    const value = (await node.innerText().catch(() => '')).trim();
    if (value && !comments.some(comment => comment.text === value)) comments.push({ text: value });
  }

  const links = await page.locator('main a[href], article a[href]').evaluateAll(nodes =>
    nodes.map(a => a.href).filter(url => /^https?:/i.test(url) && !/linkedin\.com/i.test(url))
  ).catch(() => []);
  const allText = [bodyText, ...comments.map(comment => comment.text)].join('\n');
  const emails = [...new Set([...allText.matchAll(/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/gi)].map(match => match[0].toLowerCase()))];

  post.text = bodyText.slice(0, 12000) || post.text;
  post.topComments = comments;
  post.links = [...new Set([...(post.links ?? []), ...links])];
  post.emails = emails;
  post.searchSnippet = post.text.slice(0, 1000);
  return post;
}

async function extractCard(card, keyword) {
  const text = (await card.innerText().catch(() => '')).trim();
  if (!text || /\b(promoted|patrocinado|promovido)\b/i.test(text)) return null;

  const anchors = await card.locator('a[href]').evaluateAll(nodes => nodes.map(a => ({
    href: a.href,
    text: (a.innerText ?? '').trim()
  })));
  const postAnchor = anchors.find(a => /linkedin\.com\/(?:feed\/update|posts)\//i.test(a.href));
  if (!postAnchor) return null;

  const href = new URL(postAnchor.href);
  href.search = '';
  href.hash = '';
  const postUrl = href.toString();
  const time = await card.locator('time').first().getAttribute('datetime', { timeout: 1000 }).catch(() => null);
  const timeText = await card.locator('time').first().innerText().catch(() => '');
  const date = parseDate(timeText || text, time);
  if (!date || date < new Date(Date.now() - lookbackHours * 3600000) || date > new Date(Date.now() + 7200000)) return null;

  const comments = [];
  const commentNodes = await card.locator(
    '[data-test-id*="comment"], .comments-comment-item, .comments-comment-entity, [class*="comments-comment-item"]'
  ).all();
  for (const node of commentNodes.slice(0, 40)) {
    const commentText = (await node.innerText().catch(() => '')).trim();
    if (commentText) comments.push({ text: commentText });
  }

  const emailMatches = [...text.matchAll(/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/gi)].map(m => m[0].toLowerCase());
  const links = [...new Set(anchors.map(a => a.href).filter(url => /^https?:/i.test(url) && !/linkedin\.com/i.test(url)))];
  const authorLink = anchors.find(a => /linkedin\.com\/(?:in|company)\//i.test(a.href));
  const urn = await card.getAttribute('data-urn') || await card.getAttribute('data-id');
  const titleLine = text.split('\n').map(s => s.trim()).find(s => /suporte|help desk|service desk|technical support/i.test(s)) || keyword;

  return {
    postId: urn || Buffer.from(postUrl).toString('base64url'),
    postUrl,
    title: titleLine.slice(0, 255),
    text,
    datePosted: date.toISOString(),
    matchedKeyword: keyword,
    authorName: authorLink?.text || null,
    authorUrl: authorLink?.href || null,
    authorHeadline: null,
    authorType: null,
    topComments: comments,
    links,
    searchProvider: 'linkedin-secondary-account',
    searchSnippet: text.slice(0, 1000),
    emails: [...new Set(emailMatches)],
  };
}

async function main() {
  const context = await chromium.launchPersistentContext(profile, {
    ...(chromeExecutable ? { executablePath: chromeExecutable } : {}),
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
      const searchUrl = 'https://www.linkedin.com/search/results/content/?keywords=' + encodeURIComponent(keyword) + '&origin=GLOBAL_SEARCH_HEADER';
      await page.goto(searchUrl, { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(2200);

      for (let pageNo = 0; pageNo < 3; pageNo++) {
        const cards = page.locator('div[data-urn], div.feed-shared-update-v2, li.reusable-search__result-container');
        const count = Math.min(await cards.count(), 30);
        for (let i = 0; i < count; i++) {
          const card = cards.nth(i);
          await expandComments(page, card);
          const post = await extractCard(card, keyword);
          if (post) posts.set(post.postUrl, post);
        }
        await page.mouse.wheel(0, 1800);
        await page.waitForTimeout(900);
      }
      await page.waitForTimeout(1100);
    }

    const enriched = [];
    for (const post of [...posts.values()].slice(0, 60)) {
      try {
        enriched.push(await enrichPost(page, post));
      } catch {
        enriched.push(post);
      }
      await page.waitForTimeout(700);
    }

    process.stdout.write(JSON.stringify(enriched));
  } finally {
    await context.close();
  }
}

main().catch(error => {
  console.error(error?.message ?? String(error));
  process.exitCode = 1;
});
