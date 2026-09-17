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
const find = (beacon, predicate) => {
  const event = beacon.events.find(predicate);
  assert.ok(event, 'example is missing the event this test needs');
  return event;
};
const byType = (type) => (e) => e.type === type;
const widgetAdd = (e) => e.type === 'add_to_cart' && e.data.source === 'widget';
const pageAdd = (e) => e.type === 'add_to_cart' && e.data.source === 'page';

const expectValid = (beacon, because) => {
  assert.ok(validateBeacon(beacon), `expected valid: ${because}\n${JSON.stringify(validateBeacon.errors, null, 2)}`);
};
const expectInvalid = (beacon, because) => {
  assert.equal(validateBeacon(beacon), false, `expected invalid: ${because}`);
};

test('every example in examples/valid is a valid beacon', () => {
  for (const file of fs.readdirSync(path.join(root, 'examples/valid'))) {
    expectValid(readJson(`examples/valid/${file}`), file);
  }
});

test('every example in examples/invalid is rejected', () => {
  for (const file of fs.readdirSync(path.join(root, 'examples/invalid'))) {
    expectInvalid(readJson(`examples/invalid/${file}`), file);
  }
});

test('widget interactions must say which candidate, bank version and eligible set they belong to', () => {
  for (const field of ['candidate', 'bank_version', 'eligible', 'bucket']) {
    const beacon = base();
    delete find(beacon, byType('open'))[field];
    expectInvalid(beacon, `open event without ${field}`);
  }
});

test('add to cart from the widget must be attributed to the suggestion', () => {
  for (const field of ['candidate', 'bank_version', 'eligible', 'bucket']) {
    const beacon = base();
    delete find(beacon, widgetAdd)[field];
    expectInvalid(beacon, `widget add_to_cart without ${field}`);
  }
});

test('add to cart from the page needs no suggestion', () => {
  const beacon = base();
  const event = find(beacon, pageAdd);
  assert.equal(event.candidate, undefined);
  expectValid(beacon, 'page add_to_cart without candidate');
});

test('add to cart records failed attempts with a fixed result', () => {
  for (const result of ['needs_options', 'out_of_stock', 'error']) {
    const beacon = base();
    find(beacon, widgetAdd).data.result = result;
    expectValid(beacon, `result ${result}`);
  }

  const bad = base();
  find(bad, widgetAdd).data.result = 'failed because the theme cart is broken';
  expectInvalid(bad, 'free-text result');

  const missing = base();
  delete find(missing, widgetAdd).data.result;
  expectInvalid(missing, 'no result');

  const unknownSource = base();
  find(unknownSource, pageAdd).data.source = 'chat';
  expectInvalid(unknownSource, 'unknown source');
});

test('a project checklist can add several products under one batch id', () => {
  const beacon = base();
  const first = find(beacon, widgetAdd);
  first.data.batch = 'batch-7f3a9c2e';
  beacon.events.push({ ...structuredClone(first), id: 'e-01k5a8m2q7w3x9z4c2', data: { ...first.data, product_id: 'p_888' } });
  expectValid(beacon, 'checklist batch');
});

test('privacy: no query strings in page paths', () => {
  const beacon = base();
  find(beacon, byType('exposure')).page.path = '/product/hammer?email=someone@example.com';
  expectInvalid(beacon, 'query string in path');
});

test('privacy: chat question text never travels in events', () => {
  const beacon = base();
  find(beacon, byType('chat_question')).data.text = 'my phone is 050-0000000';
  expectInvalid(beacon, 'chat text in event');
});

test('privacy: visitor ids are anonymous tokens, not emails', () => {
  const beacon = base();
  beacon.vid = 'someone@example.com';
  expectInvalid(beacon, 'email as visitor id');
});

test('unknown fields are refused rather than silently stored', () => {
  const beacon = base();
  find(beacon, byType('exposure')).page.referrer = 'https://google.com/?q=hammer';
  expectInvalid(beacon, 'unknown page field');
});

test('a survey answer must be one of the fixed choices', () => {
  const beacon = base();
  find(beacon, byType('answer')).data.answer = 'too expensive, my budget is 100';
  expectInvalid(beacon, 'free-text answer');
});

test('a beacon carries at most 50 events and at least one', () => {
  const template = find(base(), pageAdd);
  const full = base();
  full.events = Array.from({ length: 51 }, (_, i) => ({ ...structuredClone(template), id: `e-${String(i).padStart(16, '0')}` }));
  expectInvalid(full, '51 events');

  const empty = base();
  empty.events = [];
  expectInvalid(empty, 'no events');
});

test('at most 12 eligible candidates, matching the bank cap', () => {
  const beacon = base();
  find(beacon, byType('exposure')).eligible = Array.from({ length: 13 }, (_, i) => `cand_${i}`);
  expectInvalid(beacon, '13 eligible');
});

test('open and click carry no data payload', () => {
  const beacon = base();
  find(beacon, byType('open')).data = { anything: true };
  expectInvalid(beacon, 'data on open');
});

test('a page view carries only the page, and article pages say which article', () => {
  const beacon = base();
  beacon.preview = true;
  beacon.events = [{ id: 'e-pageview0000000001', type: 'page_view', ts: 1758040120000, page: { type: 'content', path: '/how-to-choose-a-deck', content_id: '5146' } }];
  expectValid(beacon, 'page view on an article in preview');

  const withCandidate = structuredClone(beacon);
  withCandidate.events[0].candidate = { id: 'position_x', version: 1, model: 'position', slot: 'teaser' };
  expectInvalid(withCandidate, 'page view with a candidate');
});

test('products shown for an article and a spec summary are display models', () => {
  for (const model of ['article_products', 'specs']) {
    const beacon = base();
    find(beacon, byType('open')).candidate.model = model;
    expectValid(beacon, model);
  }
});

test('circles for other sizes, sales and jobs are display models, and each circle has a slot', () => {
  for (const model of ['family', 'on_sale', 'good_for', 'compare']) {
    const beacon = base();
    find(beacon, byType('open')).candidate.model = model;
    expectValid(beacon, model);
  }

  const eighth = base();
  find(eighth, byType('open')).candidate.slot = 'chip_8';
  expectValid(eighth, 'the eighth circle');

  const ninth = base();
  find(ninth, byType('open')).candidate.slot = 'chip_9';
  expectInvalid(ninth, 'there is no ninth circle');
});
