import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { launchPersistentBrowser } from './persistent-browser.mjs';

test('abre contexto persistente e mantém o diretório após fechar', async () => {
  const root = await fs.mkdtemp(path.join(os.tmpdir(), 'ti-vagas-profile-'));
  const profileDir = path.join(root, 'profile');
  let closed = false;

  const chromium = {
    async launchPersistentContext(receivedPath, options) {
      assert.equal(receivedPath, profileDir);
      assert.deepEqual(options, { headless: true });
      await fs.writeFile(path.join(receivedPath, 'profile-data'), 'persist');
      return {
        async close() { closed = true; },
      };
    },
  };

  try {
    const browser = await launchPersistentBrowser({
      chromium,
      profileDir,
      contextOptions: { headless: true },
    });

    assert.equal(await fs.readFile(path.join(profileDir, 'profile-data'), 'utf8'), 'persist');
    await browser.close();
    assert.equal(closed, true);
    assert.equal(await fs.readFile(path.join(profileDir, 'profile-data'), 'utf8'), 'persist');
    await assert.rejects(
      fs.access(path.join(profileDir, '.ti-vagas-browser.lock')),
      { code: 'ENOENT' },
    );
  } finally {
    await fs.rm(root, { recursive: true, force: true });
  }
});

test('impede duas execuções simultâneas no mesmo perfil', async () => {
  const root = await fs.mkdtemp(path.join(os.tmpdir(), 'ti-vagas-profile-lock-'));
  const profileDir = path.join(root, 'profile');
  let releaseClose;

  const chromium = {
    async launchPersistentContext() {
      return {
        close() {
          return new Promise(resolve => { releaseClose = resolve; });
        },
      };
    },
  };

  try {
    const first = await launchPersistentBrowser({ chromium, profileDir });

    await assert.rejects(
      launchPersistentBrowser({ chromium, profileDir }),
      error => error.code === 'PROFILE_ALREADY_IN_USE',
    );

    const closing = first.close();
    while (!releaseClose) await new Promise(resolve => setImmediate(resolve));
    releaseClose();
    await closing;

    const second = await launchPersistentBrowser({ chromium, profileDir });
    await second.close();
  } finally {
    await fs.rm(root, { recursive: true, force: true });
  }
});
