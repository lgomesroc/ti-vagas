import test from 'node:test';
import assert from 'node:assert/strict';
import {
  inspectStorageState,
  filterLinkedInStorageState,
} from './export-session.mjs';

const now = 1_800_000_000;
const state = cookies => JSON.stringify({ cookies, origins: [] });

test('aceita sessão com li_at válido e informa dias restantes', () => {
  const result = inspectStorageState(
    state([
      {
        name: 'li_at',
        domain: '.linkedin.com',
        expires: now + 86400 * 30,
      },
    ]),
    now
  );

  assert.equal(result.ok, true);
  assert.equal(result.daysLeft, 30);
});

test('recusa login incompleto sem li_at', () => {
  const result = inspectStorageState(
    state([
      {
        name: 'JSESSIONID',
        domain: '.linkedin.com',
      },
    ]),
    now
  );

  assert.equal(result.ok, false);
  assert.match(result.error, /li_at/);
});

test('recusa sessão expirada e JSON inválido', () => {
  assert.equal(
    inspectStorageState(
      state([
        {
          name: 'li_at',
          domain: '.linkedin.com',
          expires: now - 10,
        },
      ]),
      now
    ).ok,
    false
  );

  assert.equal(inspectStorageState('nao-json', now).ok, false);
});

test('mantém somente cookies permitidos do LinkedIn e descarta localStorage e terceiros', () => {
  const input = {
    cookies: [
      {
        name: 'li_at',
        value: 'session',
        domain: '.www.linkedin.com',
        expires: now + 86400,
      },
      {
        name: 'bcookie',
        value: 'browser',
        domain: '.linkedin.com',
      },
      {
        name: 'JSESSIONID',
        value: 'csrf',
        domain: 'www.linkedin.com',
      },
      {
        name: 'NID',
        value: 'google',
        domain: '.google.com',
      },
      {
        name: 'MUID',
        value: 'bing',
        domain: '.bing.com',
      },
      {
        name: 'AnalyticsSyncHistory',
        value: 'tracking',
        domain: '.linkedin.com',
      },
    ],
    origins: [
      {
        origin: 'https://www.linkedin.com',
        localStorage: [
          {
            name: 'x',
            value: 'large-data',
          },
        ],
      },
      {
        origin: 'https://www.google.com',
        localStorage: [
          {
            name: 'x',
            value: 'y',
          },
        ],
      },
    ],
  };

  const filtered = filterLinkedInStorageState(input);

  assert.deepEqual(
    filtered.cookies.map(cookie => cookie.name),
    ['li_at', 'bcookie', 'JSESSIONID']
  );

  assert.deepEqual(filtered.origins, []);

  assert.equal(
    inspectStorageState(JSON.stringify(filtered), now).ok,
    true
  );
});
