import test from 'node:test';
import assert from 'node:assert/strict';
import { parseSessionSecret } from './restore-session.mjs';

const state = JSON.stringify({ cookies: [{ name: 'li_at', domain: '.linkedin.com', expires: 4102444800 }], origins: [] });
const b64 = Buffer.from(state).toString('base64');

test('base64 normal', () => assert.equal(parseSessionSecret(b64).ok, true));
test('base64 com quebras de linha, espaços e aspas', () => {
  const messy = `"${b64.slice(0, 20)}\r\n${b64.slice(20)}  "\n`;
  assert.equal(parseSessionSecret(messy).ok, true);
});
test('JSON puro colado direto', () => assert.equal(parseSessionSecret(state).ok, true));
test('base64 de arquivo UTF-16 LE com BOM (PowerShell)', () => {
  const utf16 = Buffer.concat([Buffer.from([0xff, 0xfe]), Buffer.from(state, 'utf16le')]).toString('base64');
  assert.equal(parseSessionSecret(utf16).ok, true);
});
test('base64 de arquivo UTF-8 com BOM', () => {
  const bom = Buffer.concat([Buffer.from([0xef, 0xbb, 0xbf]), Buffer.from(state)]).toString('base64');
  assert.equal(parseSessionSecret(bom).ok, true);
});
test('erros claros e sem vazar conteúdo', () => {
  assert.match(parseSessionSecret('').error, /vazio/);
  assert.match(parseSessionSecret('C:\\Users\\x\\file.json').error, /não é base64/);
  assert.match(parseSessionSecret(b64.slice(0, 30)).error, /JSON válido|não é/);
  const noLiAt = Buffer.from(JSON.stringify({ cookies: [{ name: 'x' }] })).toString('base64');
  assert.match(parseSessionSecret(noLiAt).error, /li_at/);
  assert.ok(!JSON.stringify(parseSessionSecret(b64.slice(0, 30))).includes(b64.slice(0, 30)));
});
