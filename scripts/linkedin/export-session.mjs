/**
 * Exporta somente cookies de autenticação/sessão do LinkedIn para o secret
 * LINKEDIN_STORAGE_STATE_B64 usado pelo GitHub Actions.
 */
import { existsSync, readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ALLOWED_COOKIES = new Set([
  'li_at',
  'JSESSIONID',
  'bcookie',
  'bscookie',
  'lidc',
  'liap',
  'lang',
  'timezone',
  'sdsc',
]);

function isLinkedInDomain(value) {
  const domain = String(value ?? '').replace(/^\./, '').toLowerCase();
  return domain === 'linkedin.com' || domain.endsWith('.linkedin.com');
}

export function filterLinkedInStorageState(state) {
  const cookies = Array.isArray(state?.cookies) ? state.cookies : [];

  const filteredCookies = cookies
    .filter(cookie => isLinkedInDomain(cookie.domain) && ALLOWED_COOKIES.has(cookie.name))
    .map(cookie => ({
      name: cookie.name,
      value: cookie.value,
      domain: cookie.domain,
      path: cookie.path ?? '/',
      expires: cookie.expires ?? -1,
      httpOnly: Boolean(cookie.httpOnly),
      secure: Boolean(cookie.secure),
      sameSite: cookie.sameSite ?? 'Lax',
    }));

  // Cookies são suficientes para a sessão autenticada. Não exportamos
  // localStorage, que pode conter dados volumosos e não necessários.
  return { cookies: filteredCookies, origins: [] };
}

export function inspectStorageState(raw, nowSeconds = Date.now() / 1000) {
  let state;
  try {
    state = JSON.parse(raw);
  } catch {
    return { ok: false, error: 'O arquivo não é um JSON válido.' };
  }

  const cookies = Array.isArray(state?.cookies) ? state.cookies : [];
  const liAt = cookies.find(cookie =>
    cookie.name === 'li_at' && isLinkedInDomain(cookie.domain)
  );

  if (!liAt) {
    return {
      ok: false,
      error: 'Cookie de sessão "li_at" ausente: refaça o login da conta secundária e só exporte depois de entrar no feed.',
    };
  }

  const expires = Number(liAt.expires);
  if (Number.isFinite(expires) && expires > 0 && expires < nowSeconds) {
    return { ok: false, error: 'A sessão salva já expirou. Refaça o login.' };
  }

  const daysLeft = Number.isFinite(expires) && expires > 0
    ? Math.floor((expires - nowSeconds) / 86400)
    : null;

  return { ok: true, cookies: cookies.length, daysLeft };
}

function main() {
  const defaultFile = path.join('.local', 'linkedin-secondary-profile', 'storage-state.json');
  const file = process.env.LINKEDIN_STORAGE_STATE_PATH ?? defaultFile;

  if (!existsSync(file)) {
    console.error(`Arquivo não encontrado: ${file}\nRode antes: npm run linkedin:secondary:login`);
    process.exit(1);
  }

  let state;
  try {
    state = JSON.parse(readFileSync(file, 'utf8'));
  } catch {
    console.error('O arquivo de sessão não contém JSON válido.');
    process.exit(1);
  }

  const filteredState = filterLinkedInStorageState(state);
  const filteredRaw = JSON.stringify(filteredState);
  const info = inspectStorageState(filteredRaw);

  if (!info.ok) {
    console.error(info.error);
    process.exit(1);
  }

  const b64 = Buffer.from(filteredRaw, 'utf8').toString('base64');
  const out = path.join('.local', 'linkedin-storage-state.b64');
  mkdirSync(path.dirname(out), { recursive: true });
  writeFileSync(out, b64, { mode: 0o600 });

  console.log(`Sessão filtrada: ${info.cookies} cookies permitidos do LinkedIn.`);
  console.log(`Tamanho do Base64: ${Buffer.byteLength(b64, 'utf8')} caracteres.`);
  console.log(`Arquivo: ${out}`);

  if (process.argv.includes('--gh')) {
    const result = spawnSync('gh', ['secret', 'set', 'LINKEDIN_STORAGE_STATE_B64', '--repo', 'lgomesroc/ti-vagas'], {
      input: b64,
      stdio: ['pipe', 'inherit', 'inherit'],
    });
    if (result.status !== 0) {
      console.error('Falha ao atualizar o secret pelo GitHub CLI.');
      process.exit(result.status ?? 1);
    }
    console.log('Secret LINKEDIN_STORAGE_STATE_B64 atualizado.');
    return;
  }

  console.log('Atualize o secret LINKEDIN_STORAGE_STATE_B64 com o conteúdo completo desse arquivo.');
}

if (process.argv[1] && path.resolve(process.argv[1]) === path.resolve(fileURLToPath(import.meta.url))) {
  main();
}
