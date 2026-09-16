import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import Ajv2020 from 'ajv/dist/2020.js';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const readJson = (relative) => JSON.parse(fs.readFileSync(path.join(root, relative), 'utf8'));

// strictRequired and strictTypes are off: "then: { required: [...] }" is the point of the if/then rules.
const ajv = new Ajv2020({ allErrors: true, strict: true, strictRequired: false, strictTypes: false });
ajv.addSchema(readJson('schema/event.v1.schema.json'));
const validateBeacon = ajv.compile(readJson('schema/beacon.v1.schema.json'));

const base = () => structuredClone(readJson('examples/valid/product-page-session.json'));
const expectInvalid = (beacon, because) => {
  assert.equal(validateBeacon(beacon), false, `expected invalid: ${because}`);
};

test('every example in examples/valid is a valid beacon', () => {
  for (const file of fs.readdirSync(path.join(root, 'examples/valid'))) {
    const ok = validateBeacon(readJson(`examples/valid/${file}`));
    assert.ok(ok, `${file}: ${JSON.stringify(validateBeacon.errors, null, 2)}`);
  }
});

test('every example in examples/invalid is rejected', () => {
  for (const file of fs.readdirSync(path.join(root, 'examples/invalid'))) {
    assert.equal(validateBeacon(readJson(`examples/invalid/${file}`)), false, `${file} should be invalid`);
  }
});

test('widget interactions must say which candidate, bank version and eligible set they belong to', () => {
  for (const field of ['candidate', 'bank_version', 'eligible', 'bucket']) {
    const beacon = base();
    delete beacon.events[1][field];
    expectInvalid(beacon, `open event without ${field}`);
  }
});

test('privacy: no query strings in page paths', () => {
  const beacon = base();
  beacon.events[0].page.path = '/product/hammer?email=someone@example.com';
  expectInvalid(beacon, 'query string in path');
});

test('privacy: chat question text never travels in events', () => {
  const beacon = base();
  beacon.events[4].data.text = 'my phone is 050-0000000';
  expectInvalid(beacon, 'chat text in event');
});

test('privacy: visitor ids are anonymous tokens, not emails', () => {
  const beacon = base();
  beacon.vid = 'someone@example.com';
  expectInvalid(beacon, 'email as visitor id');
});

test('unknown fields are refused rather than silently stored', () => {
  const beacon = base();
  beacon.events[0].page.referrer = 'https://google.com/?q=hammer';
  expectInvalid(beacon, 'unknown page field');
});

test('a survey answer must be one of the fixed choices', () => {
  const beacon = base();
  beacon.events[2].data.answer = 'too expensive, my budget is 100';
  expectInvalid(beacon, 'free-text answer');
});

test('a beacon carries at most 50 events and at least one', () => {
  const full = base();
  full.events = Array.from({ length: 51 }, (_, i) => ({ ...base().events[3], id: `e-${String(i).padStart(16, '0')}` }));
  expectInvalid(full, '51 events');

  const empty = base();
  empty.events = [];
  expectInvalid(empty, 'no events');
});

test('at most 12 eligible candidates, matching the bank cap', () => {
  const beacon = base();
  beacon.events[0].eligible = Array.from({ length: 13 }, (_, i) => `cand_${i}`);
  expectInvalid(beacon, '13 eligible');
});

test('open and click carry no data payload', () => {
  const beacon = base();
  beacon.events[1].data = { anything: true };
  expectInvalid(beacon, 'data on open');
});
