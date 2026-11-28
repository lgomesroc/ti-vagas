import { createCipheriv, createDecipheriv, randomBytes } from 'node:crypto';
import { existsSync } from 'node:fs';
import {
  chmod,
  mkdir,
  mkdtemp,
  readFile,
  rename,
  rm,
  writeFile,
} from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';

const FORMAT = Buffer.from('TIVP1');
const IV_BYTES = 12;
const TAG_BYTES = 16;

function resolveKey(value = process.env.LINKEDIN_PROFILE_ENCRYPTION_KEY) {
  if (!value) {
    throw new Error(
      'Secret LINKEDIN_PROFILE_ENCRYPTION_KEY ausente. Gere uma chave aleatória de 32 bytes e cadastre-a nos Secrets do GitHub.'
    );
  }

  const key = Buffer.from(String(value).trim(), 'base64');

  if (key.length !== 32) {
    throw new Error(
      'LINKEDIN_PROFILE_ENCRYPTION_KEY inválida: o valor deve ser Base64 de exatamente 32 bytes.'
    );
  }

  return key;
}

function runTar(args) {
  const result = spawnSync('tar', args, {
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
  });

  if (result.error) throw result.error;
  if (result.status !== 0) {
    throw new Error(
      `Falha ao compactar/restaurar o perfil Chromium: ${(result.stderr || '').trim() || `tar encerrou com código ${result.status}`}`
    );
  }
}

function resolvePaths(profileDir, encryptedFile) {
  const profile = path.resolve(
    profileDir ?? process.env.LINKEDIN_SECONDARY_ACCOUNT_PROFILE ?? '.local/linkedin-secondary-profile'
  );
  const encrypted = path.resolve(
    encryptedFile ?? process.env.LINKEDIN_PROFILE_ENCRYPTED_FILE ?? '.local/linkedin-profile.enc'
  );

  if (profile === path.parse(profile).root || profile === process.cwd()) {
    throw new Error('Caminho do perfil Chromium inválido; a raiz do sistema e o diretório do projeto não podem ser usados.');
  }

  return { profile, encrypted };
}

export async function encryptProfile({
  profileDir,
  encryptedFile,
  key,
} = {}) {
  const secret = resolveKey(key);
  const paths = resolvePaths(profileDir, encryptedFile);

  if (!existsSync(paths.profile)) {
    return { saved: false, reason: 'profile-not-found' };
  }

  const tempDir = await mkdtemp(path.join(os.tmpdir(), 'ti-vagas-profile-encrypt-'));
  const archive = path.join(tempDir, 'profile.tar.gz');
  const outputTemp = `${paths.encrypted}.tmp-${process.pid}-${randomBytes(4).toString('hex')}`;

  try {
    runTar([
      '-czf',
      archive,
      '--exclude=./.ti-vagas-browser.lock',
      '--exclude=./SingletonLock',
      '--exclude=./SingletonSocket',
      '--exclude=./SingletonCookie',
      '--exclude=./Default/Cache',
      '--exclude=./Default/Code Cache',
      '--exclude=./Default/GPUCache',
      '--exclude=./Default/GrShaderCache',
      '--exclude=./Default/ShaderCache',
      '--exclude=./Default/Service Worker/CacheStorage',
      '-C',
      paths.profile,
      '.',
    ]);

    const plaintext = await readFile(archive);
    const iv = randomBytes(IV_BYTES);
    const cipher = createCipheriv('aes-256-gcm', secret, iv);
    const ciphertext = Buffer.concat([cipher.update(plaintext), cipher.final()]);
    const payload = Buffer.concat([FORMAT, iv, cipher.getAuthTag(), ciphertext]);

    await mkdir(path.dirname(paths.encrypted), { recursive: true });
    await writeFile(outputTemp, payload, { mode: 0o600 });
    await rename(outputTemp, paths.encrypted);
    await chmod(paths.encrypted, 0o600);

    return {
      saved: true,
      bytes: payload.length,
      encryptedFile: paths.encrypted,
    };
  } finally {
    await rm(tempDir, { recursive: true, force: true });
    await rm(outputTemp, { force: true });
  }
}

export async function decryptProfile({
  profileDir,
  encryptedFile,
  key,
} = {}) {
  const secret = resolveKey(key);
  const paths = resolvePaths(profileDir, encryptedFile);

  if (!existsSync(paths.encrypted)) {
    return { restored: false, reason: 'encrypted-profile-not-found' };
  }

  const tempDir = await mkdtemp(path.join(os.tmpdir(), 'ti-vagas-profile-decrypt-'));
  const archive = path.join(tempDir, 'profile.tar.gz');

  try {
    const payload = await readFile(paths.encrypted);
    const headerBytes = FORMAT.length + IV_BYTES + TAG_BYTES;

    if (
      payload.length <= headerBytes ||
      !payload.subarray(0, FORMAT.length).equals(FORMAT)
    ) {
      throw new Error('Snapshot criptografado inválido ou incompatível.');
    }

    const ivStart = FORMAT.length;
    const tagStart = ivStart + IV_BYTES;
    const ciphertextStart = tagStart + TAG_BYTES;
    const iv = payload.subarray(ivStart, tagStart);
    const tag = payload.subarray(tagStart, ciphertextStart);
    const ciphertext = payload.subarray(ciphertextStart);

    const decipher = createDecipheriv('aes-256-gcm', secret, iv);
    decipher.setAuthTag(tag);

    let plaintext;
    try {
      plaintext = Buffer.concat([decipher.update(ciphertext), decipher.final()]);
    } catch {
      throw new Error(
        'Não foi possível autenticar o snapshot. A chave pode estar incorreta ou o cache foi alterado.'
      );
    }

    await writeFile(archive, plaintext, { mode: 0o600 });
    await rm(paths.profile, { recursive: true, force: true });
    await mkdir(paths.profile, { recursive: true });

    runTar([
      '-xzf',
      archive,
      '-C',
      paths.profile,
      '--no-same-owner',
      '--no-same-permissions',
    ]);

    return {
      restored: true,
      encryptedFile: paths.encrypted,
    };
  } finally {
    await rm(tempDir, { recursive: true, force: true });
  }
}

async function main() {
  const command = process.argv[2];

  if (command === 'restore') {
    const result = await decryptProfile();
    console.log(
      result.restored
        ? 'Perfil secundário restaurado do snapshot criptografado.'
        : 'Nenhum snapshot anterior encontrado; será usado o estado inicial da sessão.'
    );
    return;
  }

  if (command === 'save') {
    const result = await encryptProfile();
    console.log(
      result.saved
        ? `Perfil secundário criptografado e pronto para persistência (${result.bytes} bytes).`
        : 'Perfil ainda não criado; nenhum snapshot foi gerado.'
    );
    return;
  }

  throw new Error('Uso: node scripts/linkedin/profile-cache.mjs restore|save');
}

if (process.argv[1] && path.resolve(process.argv[1]) === path.resolve(new URL(import.meta.url).pathname)) {
  main().catch(error => {
    console.error(error?.message ?? String(error));
    process.exitCode = 1;
  });
}
