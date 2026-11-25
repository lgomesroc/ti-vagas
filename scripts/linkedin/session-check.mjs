import { existsSync, readFileSync } from 'node:fs';
import path from 'node:path';
import { chromium } from 'playwright';
import { chromiumArgs, resolveHeadless } from './runtime.mjs';

const storageStatePath = path.resolve(
  process.env.LINKEDIN_STORAGE_STATE_PATH ??
  '.local/linkedin-secondary-profile/storage-state.json'
);
const linkedinBaseUrl = new URL(
  process.env.LINKEDIN_BASE_URL ?? 'https://www.linkedin.com'
).origin;

function isLinkedInDomain(value) {
  const domain = String(value ?? '').replace(/^\./, '').toLowerCase();
  return domain === 'linkedin.com' || domain.endsWith('.linkedin.com');
}

export function evaluateSessionState({
  url,
  cookies,
  nowSeconds = Date.now() / 1000,
  responseStatus = null,
}) {
  const parsedUrl = (() => {
    try { return new URL(url); } catch { return null; }
  })();

  if (!parsedUrl) {
    return { valid: false, reason: 'URL final inválida ou indisponível.' };
  }

  const pathname = parsedUrl.pathname.toLowerCase();
  if (/\/(login|checkpoint|challenge)(\/|$)/i.test(pathname)) {
    return { valid: false, reason: 'LinkedIn redirecionou para login, checkpoint ou challenge.' };
  }

  const liAt = (Array.isArray(cookies) ? cookies : []).find(cookie =>
    cookie?.name === 'li_at' && isLinkedInDomain(cookie.domain)
  );

  if (!liAt) {
    return { valid: false, reason: 'Cookie de autenticação li_at ausente no domínio do LinkedIn.' };
  }

  const expires = Number(liAt.expires);
  if (Number.isFinite(expires) && expires > 0 && expires <= nowSeconds) {
    return { valid: false, reason: 'Cookie li_at expirado.' };
  }

  if (Number.isFinite(responseStatus) && responseStatus >= 400) {
    return { valid: false, reason: `A página autenticada respondeu HTTP ${responseStatus}.` };
  }

  return {
    valid: true,
    reason: 'O cookie li_at está presente e a navegação para o feed não foi redirecionada para autenticação.',
    expiresAt: Number.isFinite(expires) && expires > 0
      ? new Date(expires * 1000).toISOString()
      : null,
  };
}

async function main() {
  if (!existsSync(storageStatePath)) {
    throw new Error('Arquivo de sessão não encontrado. Restaure o secret antes da verificação.');
  }

  let state;
  try {
    state = JSON.parse(readFileSync(storageStatePath, 'utf8'));
  } catch {
    throw new Error('O arquivo de sessão não contém JSON válido.');
  }

  const storedCookies = Array.isArray(state?.cookies) ? state.cookies : [];
  const storedLiAt = storedCookies.find(cookie =>
    cookie?.name === 'li_at' && isLinkedInDomain(cookie.domain)
  );

  if (!storedLiAt) {
    throw new Error('O storage-state não contém um cookie li_at válido para o domínio LinkedIn.');
  }

  const headless = resolveHeadless();
  const browser = await chromium.launch({
    headless,
    args: chromiumArgs(headless),
  });

  try {
    const context = await browser.newContext({
      storageState: storageStatePath,
      viewport: { width: 1280, height: 900 },
      locale: 'pt-BR',
    });
    try {
      const page = await context.newPage();
      const response = await page.goto(`${linkedinBaseUrl}/feed/`, {
        waitUntil: 'domcontentloaded',
        timeout: 30000,
      });
      await page.waitForTimeout(1200);

      const cookies = await context.cookies(linkedinBaseUrl);
      const result = evaluateSessionState({
        url: page.url(),
        cookies,
        responseStatus: response?.status() ?? null,
      });

      const finalUrl = new URL(page.url());
      const report = {
        status: result.valid ? 'VALID' : 'INVALID',
        checkedAt: new Date().toISOString(),
        finalUrlPath: finalUrl.pathname,
        liAtPresent: cookies.some(cookie => cookie.name === 'li_at'),
        cookieExpiresAt: result.expiresAt ?? null,
        reason: result.reason,
      };

      process.stdout.write(`${JSON.stringify(report, null, 2)}\n`);

      if (!result.valid) process.exitCode = 1;
    } finally {
      await context.close();
    }
  } finally {
    await browser.close();
  }
}

if (process.argv[1] && path.resolve(process.argv[1]) === path.resolve(new URL(import.meta.url).pathname)) {
  main().catch(error => {
    console.error(error?.message ?? String(error));
    process.exitCode = 1;
  });
}
