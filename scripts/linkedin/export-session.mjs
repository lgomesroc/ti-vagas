/**
 * Prepara o secret LINKEDIN_STORAGE_STATE_B64 a partir da sessão salva por
 * `npm run linkedin:secondary:login`.
 *
 * Uso:
 *   npm run linkedin:secondary:export            valida e grava o .b64 local
 *   npm run linkedin:secondary:export -- --gh    também envia o secret via GitHub CLI
 *
 * Nunca imprime cookies. Login e senha NÃO são necessários nem guardados: o
 * GitHub Actions reaproveita a sessão (cookies) já autenticada.
 */
import { existsSync, readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import path from 'node:path';

export function filterLinkedInStorageState(state) {
  const isLinkedInDomain = domain =>
    String(domain ?? '').replace(/^\\./, '').toLowerCase() === 'linkedin.com' ||
    String(domain ?? '').replace(/^\\./, '').toLowerCase().endsWith('.linkedin.com');

  return {
    ...state,
    cookies: (Array.isArray(state?.cookies) ? state.cookies : [])
      .filter(cookie => isLinkedInDomain(cookie.domain)),
    origins: (Array.isArray(state?.origins) ? state.origins : [])
      .filter(item => {
        try {
          const hostname = new URL(item.origin).hostname.toLowerCase();
          return hostname === 'linkedin.com' || hostname.endsWith('.linkedin.com');
        } catch {
          return false;
        }
      }),
  };
}

export function inspectStorageState(raw, nowSeconds = Date.now() / 1000) {
  let state;
  try {
    state = JSON.parse(raw);
  } catch {
    return { ok: false, error: 'O arquivo não é um JSON válido.' };
  }

  const cookies = Array.isArray(state?.cookies) ? state.cookies : [];
  const liAt = cookies.find(c => c.name === 'li_at' && /linkedin\.com$/i.test(c.domain ?? ''));

  if (!liAt) {
    return {
      ok: false,
      error: 'Cookie de sessão "li_at" ausente: o login não foi concluído antes de apertar ENTER. Rode o login de novo e só aperte ENTER depois de ver o feed.',
    };
  }

  const expires = Number(liAt.expires);
  if (Number.isFinite(expires) && expires > 0 && expires < nowSeconds) {
    return { ok: false, error: 'A sessão salva já expirou. Rode o login de novo.' };
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

  const raw = readFileSync(file, 'utf8');
  let state;

  try {
    state = JSON.parse(raw);
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

  const removedCookies = (Array.isArray(state.cookies) ? state.cookies.length : 0) - info.cookies;
  const b64 = Buffer.from(filteredRaw, 'utf8').toString('base64');
  const out = path.join('.local', 'linkedin-storage-state.b64');
  mkdirSync(path.dirname(out), { recursive: true });
  writeFileSync(out, b64, { mode: 0o600 });

  console.log(`Sessão válida: ${info.cookies} cookies do LinkedIn mantidos; ${removedCookies} cookies de outros domínios descartados${info.daysLeft !== null ? `, expira em ~${info.daysLeft} dias` : ''}.`);
  console.log(`Secret gravado em: ${out}  (não versionar; já está em .local/)`);

  if (process.argv.includes('--gh')) {
    const result = spawnSync('gh', ['secret', 'set', 'LINKEDIN_STORAGE_STATE_B64', '--repo', 'lgomesroc/ti-vagas'], {
      input: b64,
      stdio: ['pipe', 'inherit', 'inherit'],
    });
    if (result.status !== 0) {
      console.error('Não consegui enviar via gh. Use o passo manual abaixo.');
      process.exit(result.status ?? 1);
    }
    console.log('Secret LINKEDIN_STORAGE_STATE_B64 atualizado no GitHub.');
    return;
  }

  console.log('\nPróximo passo: GitHub > Settings > Secrets and variables > Actions >');
  console.log('LINKEDIN_STORAGE_STATE_B64 > cole TODO o conteúdo do arquivo acima (uma linha só).');
}

if (process.argv[1] && path.resolve(process.argv[1]) === path.resolve(new URL(import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1'))) {
  main();
}
