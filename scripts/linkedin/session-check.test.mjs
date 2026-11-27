import test from 'node:test';
import assert from 'node:assert/strict';
import { evaluateSessionState } from './session-check.mjs';

const nowSeconds = 1_800_000_000;
const validCookie = {
  name: 'li_at',
  domain: '.linkedin.com',
  expires: nowSeconds + 86400,
};

test('considera válida uma sessão presente que chega ao feed', () => {
  const result = evaluateSessionState({
    url: 'https://www.linkedin.com/feed/',
    cookies: [validCookie],
    nowSeconds,
    responseStatus: 200,
  });

  assert.equal(result.valid, true);
  assert.equal(result.expiresAt, new Date(validCookie.expires * 1000).toISOString());
});

test('recusa cookie ausente, domínio incorreto ou expirado', () => {
  assert.equal(evaluateSessionState({
    url: 'https://www.linkedin.com/feed/',
    cookies: [],
    nowSeconds,
  }).valid, false);

  assert.equal(evaluateSessionState({
    url: 'https://www.linkedin.com/feed/',
    cookies: [{ ...validCookie, domain: '.example.com' }],
    nowSeconds,
  }).valid, false);

  assert.equal(evaluateSessionState({
    url: 'https://www.linkedin.com/feed/',
    cookies: [{ ...validCookie, expires: nowSeconds - 1 }],
    nowSeconds,
  }).valid, false);
});

test('recusa redirecionamentos de login, checkpoint e challenge', () => {
  for (const pathname of ['/login', '/checkpoint/challenge', '/challenge/']) {
    const result = evaluateSessionState({
      url: `https://www.linkedin.com${pathname}`,
      cookies: [validCookie],
      nowSeconds,
    });
    assert.equal(result.valid, false, pathname);
  }
});

test('recusa respostas HTTP de erro', () => {
  const result = evaluateSessionState({
    url: 'https://www.linkedin.com/feed/',
    cookies: [validCookie],
    nowSeconds,
    responseStatus: 503,
  });

  assert.equal(result.valid, false);
  assert.match(result.reason, /HTTP 503/);
});
