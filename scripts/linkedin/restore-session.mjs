/**
 * Restaura o storage-state do LinkedIn a partir do secret LINKEDIN_STORAGE_STATE_B64.
 *
 * Aceita o que costuma ser colado por engano no Windows: base64 com quebras de
 * linha/aspas/espaços, JSON puro, e conteúdo em UTF-16 ou com BOM.
 * Nunca imprime o conteúdo do secret.
 */
import { mkdirSync, writeFileSync } from 'node:fs';
import path from 'node:path';

function decodeText(buffer) {
  if (buffer.length >= 2 && buffer[0] === 0xff && buffer[1] === 0xfe) {
    return buffer.subarray(2).toString('utf16le');
  }
  if (buffer.length >= 3 && buffer[0] === 0xef && buffer[1] === 0xbb && buffer[2] === 0xbf) {
    return buffer.subarray(3).toString('utf8');
  }
  // UTF-16 LE sem BOM: bytes nulos intercalados.
  if (buffer.length > 4 && buffer[1] === 0 && buffer[3] === 0) {
    return buffer.toString('utf16le');
  }
  return buffer.toString('utf8');
}

export function parseSessionSecret(secret) {
  const cleaned = String(secret ?? '').replace(/^[\s"'`]+|[\s"'`]+$/g, '');

  if (!cleaned) return { ok: false, error: 'O secret LINKEDIN_STORAGE_STATE_B64 está vazio.' };

  let text;
  if (cleaned.startsWith('{')) {
    text = cleaned; // JSON puro colado direto
  } else {
    const compact = cleaned.replace(/\s+/g, '').replace(/-/g, '+').replace(/_/g, '/');
    if (!/^[A-Za-z0-9+/]+={0,2}$/.test(compact)) {
      return {
        ok: false,
        error: `O secret não é base64 nem JSON (${cleaned.length} caracteres, começa com "${cleaned.slice(0, 1)}"). Cole o conteúdo de .local/linkedin-storage-state.b64.`,
      };
    }
    text = decodeText(Buffer.from(compact, 'base64'));
  }

  let state;
  try {
    state = JSON.parse(text.replace(/^\uFEFF/, ''));
  } catch {
    return {
      ok: false,
      error: `O secret foi lido (${cleaned.length} caracteres), mas não contém um JSON válido. Provavelmente foi colado cortado ou gerado de outro arquivo. Gere de novo com: npm run linkedin:secondary:export`,
    };
  }

  const cookies = Array.isArray(state?.cookies) ? state.cookies : [];
  const liAt = cookies.find(c => c.name === 'li_at');

  if (!liAt) {
    return { ok: false, error: `Sessão sem o cookie li_at (${cookies.length} cookies): login incompleto. Rode o login e só aperte ENTER depois do feed carregar.` };
  }
  if (liAt.expires > 0 && liAt.expires < Date.now() / 1000) {
    return { ok: false, error: 'A sessão do LinkedIn expirou. Refaça o login e atualize o secret.' };
  }

  return { ok: true, json: JSON.stringify(state), cookies: cookies.length };
}

function main() {
  const target = process.env.LINKEDIN_STORAGE_STATE_PATH;
  if (!target) {
    console.log('::error::LINKEDIN_STORAGE_STATE_PATH não definido.');
    process.exit(1);
  }

  const result = parseSessionSecret(process.env.LINKEDIN_STORAGE_STATE_B64);
  if (!result.ok) {
    console.log(`::error::${result.error}`);
    process.exit(1);
  }

  mkdirSync(path.dirname(target), { recursive: true });
  writeFileSync(target, result.json, { mode: 0o600 });
  console.log(`Sessão do LinkedIn restaurada (${result.cookies} cookies).`);
}

if (process.argv[1] && path.basename(process.argv[1]) === 'restore-session.mjs') main();
