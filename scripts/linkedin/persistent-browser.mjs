import fs from 'node:fs/promises';
import path from 'node:path';

/**
 * Abre um contexto Chromium persistente, exclusivo para uma execução.
 *
 * O diretório do perfil nunca é removido por este módulo. O chamador é
 * responsável por fechar o contexto por meio de close().
 */
export async function launchPersistentBrowser({
  chromium,
  profileDir,
  launchOptions = {},
  contextOptions = {},
}) {
  if (!chromium?.launchPersistentContext) {
    throw new TypeError('É necessário fornecer chromium.launchPersistentContext.');
  }

  if (!profileDir || typeof profileDir !== 'string') {
    throw new TypeError('profileDir deve ser um caminho não vazio.');
  }

  const resolvedProfileDir = path.resolve(profileDir);
  await fs.mkdir(resolvedProfileDir, { recursive: true });

  const lockPath = path.join(resolvedProfileDir, '.ti-vagas-browser.lock');
  let lockHandle;

  try {
    lockHandle = await fs.open(lockPath, 'wx', 0o600);
  } catch (error) {
    if (error?.code === 'EEXIST') {
      const conflict = new Error('O perfil persistente já está em uso por outra execução.');
      conflict.code = 'PROFILE_ALREADY_IN_USE';
      throw conflict;
    }
    throw error;
  }

  let context;

  try {
    await lockHandle.writeFile(JSON.stringify({
      pid: process.pid,
      startedAt: new Date().toISOString(),
    }));

    context = await chromium.launchPersistentContext(
      resolvedProfileDir,
      contextOptions,
    );

    let closed = false;

    return {
      context,
      profileDir: resolvedProfileDir,
      async close() {
        if (closed) return;
        closed = true;

        try {
          await context.close();
        } finally {
          await lockHandle.close();
          await fs.rm(lockPath, { force: true });
        }
      },
    };
  } catch (error) {
    await lockHandle.close().catch(() => {});
    await fs.rm(lockPath, { force: true }).catch(() => {});
    throw error;
  }
}
