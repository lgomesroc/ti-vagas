import test from 'node:test';
import assert from 'node:assert/strict';

import { chromiumArgs, createTimeBudget, resolveHeadless } from './runtime.mjs';

test('headless explícito é respeitado', () => {
  assert.equal(resolveHeadless({ LINKEDIN_HEADLESS: 'true', DISPLAY: ':0' }, 'linux'), true);
  assert.equal(resolveHeadless({ LINKEDIN_HEADLESS: '1', DISPLAY: ':0' }, 'linux'), true);
});

test('no GitHub Actions o navegador é sempre headless, mesmo com LINKEDIN_HEADLESS=false', () => {
  assert.equal(resolveHeadless({ LINKEDIN_HEADLESS: 'false', GITHUB_ACTIONS: 'true', DISPLAY: ':0' }, 'linux'), true);
  assert.equal(resolveHeadless({ CI: 'true' }, 'linux'), true);
});

test('Linux sem servidor gráfico cai para headless em vez de falhar', () => {
  assert.equal(resolveHeadless({}, 'linux'), true);
  assert.equal(resolveHeadless({ LINKEDIN_HEADLESS: 'false' }, 'linux'), true);
});

test('com tela disponível e sem pedido de headless, mantém a janela visível', () => {
  assert.equal(resolveHeadless({ LINKEDIN_HEADLESS: 'false', DISPLAY: ':0' }, 'linux'), false);
  assert.equal(resolveHeadless({}, 'win32'), false);
  assert.equal(resolveHeadless({}, 'darwin'), false);
});

test('argumentos de sandbox só em headless', () => {
  assert.deepEqual(chromiumArgs(true), ['--no-sandbox', '--disable-dev-shm-usage']);
  assert.deepEqual(chromiumArgs(false), []);
});

test('orçamento de tempo expira e nunca fica negativo', () => {
  let clock = 1000;
  const budget = createTimeBudget(10, () => clock);

  assert.equal(budget.expired(), false);
  assert.equal(budget.remainingMs(), 10000);

  clock += 9999;
  assert.equal(budget.expired(), false);

  clock += 1;
  assert.equal(budget.expired(), true);
  assert.equal(budget.remainingMs(), 0);

  clock += 5000;
  assert.equal(budget.remainingMs(), 0);
});

test('orçamento inválido ou zero significa sem limite', () => {
  assert.equal(createTimeBudget(0).expired(), false);
  assert.equal(createTimeBudget('abc').expired(), false);
  assert.equal(createTimeBudget(undefined).limitMs, Infinity);
});
