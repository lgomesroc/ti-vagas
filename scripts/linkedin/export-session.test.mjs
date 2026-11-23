import test from 'node:test';
import assert from 'node:assert/strict';
import { inspectStorageState, filterLinkedInStorageState } from './export-session.mjs';

const now = 1_800_000_000;
const state = cookies => JSON.stringify({ cookies, origins: [] });

test('aceita sessão com li_at válido e informa dias restantes', () => {
  const r = inspectStorageState(state([{ name: 'li_at', domain: '.linkedin.com', expires: now + 86400 * 30 }]), now);
  assert.equal(r.ok, true);
  assert.equal(r.daysLeft, 30);
});

test('recusa login incompleto (sem li_at)', () => {
  const r = inspectStorageState(state([{ name: 'JSESSIONID', domain: '.linkedin.com' }]), now);
  assert.equal(r.ok, false);
  assert.match(r.error, /li_at/);
});

test('recusa sessão expirada e JSON inválido', () => {
  assert.equal(inspectStorageState(state([{ name: 'li_at', domain: '.linkedin.com', expires: now - 10 }]), now).ok, false);
  assert.equal(inspectStorageState('nao-json', now).ok, false);
});


test('exporta somente cookies e origens do LinkedIn', () => {
  const input = {
    cookies: [
      { name: 'li_at', domain: '.www.linkedin.com', expires: now + 86400 },
      { name: 'bcookie', domain: '.linkedin.com' },
      { name: 'NID', domain: '.google.com' },
      { name: 'MUID', domain: '.bing.com' },
    ],
    origins: [
      { origin: 'https://www.linkedin.com', localStorage: [{ name: 'li_theme', value: 'light' }] },
      { origin: 'https://www.google.com', localStorage: [{ name: 'x', value: 'y' }] },
    ],
  };

  const filtered = filterLinkedInStorageState(input);
  assert.deepEqual(filtered.cookies.map(cookie => cookie.name), ['li_at', 'bcookie']);
  assert.deepEqual(filtered.origins.map(origin => origin.origin), ['https://www.linkedin.com']);
  assert.equal(inspectStorageState(JSON.stringify(filtered), now).ok, true);
});
