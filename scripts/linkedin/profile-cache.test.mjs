import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { encryptProfile, decryptProfile } from './profile-cache.mjs';

const key = Buffer.alloc(32, 7).toString('base64');

test('criptografa e restaura o perfil sem deixar dados em texto claro no snapshot', async () => {
  const root = await fs.mkdtemp(path.join(os.tmpdir(), 'ti-vagas-profile-cache-'));
  const profileDir = path.join(root, 'profile');
  const encryptedFile = path.join(root, 'profile.enc');

  try {
    await fs.mkdir(path.join(profileDir, 'Default'), { recursive: true });
    await fs.writeFile(path.join(profileDir, 'Default', 'Preferences'), '{"marker":"private-profile-data"}');
    await fs.writeFile(path.join(profileDir, 'SingletonLock'), 'volatile-lock');

    const saved = await encryptProfile({ profileDir, encryptedFile, key });
    assert.equal(saved.saved, true);

    const snapshot = await fs.readFile(encryptedFile);
    assert.equal(snapshot.includes(Buffer.from('private-profile-data')), false);

    await fs.rm(profileDir, { recursive: true, force: true });
    const restored = await decryptProfile({ profileDir, encryptedFile, key });
    assert.equal(restored.restored, true);
    assert.equal(
      await fs.readFile(path.join(profileDir, 'Default', 'Preferences'), 'utf8'),
      '{"marker":"private-profile-data"}'
    );
    await assert.rejects(fs.access(path.join(profileDir, 'SingletonLock')), { code: 'ENOENT' });
  } finally {
    await fs.rm(root, { recursive: true, force: true });
  }
});

test('recusa chave incorreta sem extrair o perfil', async () => {
  const root = await fs.mkdtemp(path.join(os.tmpdir(), 'ti-vagas-profile-cache-invalid-'));
  const profileDir = path.join(root, 'profile');
  const encryptedFile = path.join(root, 'profile.enc');

  try {
    await fs.mkdir(profileDir, { recursive: true });
    await fs.writeFile(path.join(profileDir, 'data'), 'secret');
    await encryptProfile({ profileDir, encryptedFile, key });

    await fs.rm(profileDir, { recursive: true, force: true });
    await assert.rejects(
      decryptProfile({
        profileDir,
        encryptedFile,
        key: Buffer.alloc(32, 8).toString('base64'),
      }),
      /não foi possível autenticar o snapshot/i
    );
    await assert.rejects(fs.access(profileDir), { code: 'ENOENT' });
  } finally {
    await fs.rm(root, { recursive: true, force: true });
  }
});

test('rejeita chave inválida', async () => {
  await assert.rejects(
    encryptProfile({
      profileDir: '/tmp/does-not-matter',
      encryptedFile: '/tmp/does-not-matter.enc',
      key: 'invalid',
    }),
    /exatamente 32 bytes/
  );
});
