/**
 * Utilitários de execução compartilhados pelos coletores do LinkedIn:
 * decisão de modo headless e orçamento de tempo.
 */

/**
 * Decide se o Chromium deve rodar sem janela.
 *
 * Em CI (GitHub Actions) e em Linux sem servidor gráfico (DISPLAY ausente) não
 * existe tela para abrir uma janela, então o modo headless é obrigatório,
 * mesmo que LINKEDIN_HEADLESS esteja ausente ou como "false".
 */
export function resolveHeadless(env = process.env, platform = process.platform) {
  const flag = String(env.LINKEDIN_HEADLESS ?? '').trim().toLowerCase();

  if (flag === 'true' || flag === '1') return true;

  if (env.CI === 'true' || env.GITHUB_ACTIONS === 'true') return true;

  if (platform === 'linux' && !env.DISPLAY && !env.WAYLAND_DISPLAY) return true;

  return false;
}

/** Argumentos extras do Chromium para runners/containers sem sandbox de usuário. */
export function chromiumArgs(headless) {
  return headless ? ['--no-sandbox', '--disable-dev-shm-usage'] : [];
}

/**
 * Cria um orçamento de tempo. `expired()` fica verdadeiro quando o tempo
 * acabou; `remainingMs()` nunca é negativo.
 */
export function createTimeBudget(seconds, now = Date.now) {
  const total = Number(seconds);
  const limitMs = Number.isFinite(total) && total > 0 ? total * 1000 : Infinity;
  const startedAt = now();

  return {
    limitMs,
    elapsedMs: () => now() - startedAt,
    remainingMs: () => Math.max(0, limitMs - (now() - startedAt)),
    expired: () => now() - startedAt >= limitMs,
  };
}
