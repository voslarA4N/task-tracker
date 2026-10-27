'use strict';
/* Alpine invPay: forma uhrady, pocitadlo poznamky a dirty priznak. Spustenie: node --test tests/js/ */
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const SOURCE = fs.readFileSync(path.join(__dirname, '../../public/assets/js/app.js'), 'utf8');

function boot(elements) {
  const listeners = {};
  const components = {};
  const document = {
    body: { addEventListener() {}, removeEventListener() {}, dataset: {} },
    getElementById(id) { return elements[id] || null; },
    removeEventListener() {},
    addEventListener(type, fn) { (listeners[type] = listeners[type] || []).push(fn); },
  };
  const window = { Alpine: { data(name, fn) { components[name] = fn; } } };
  vm.runInNewContext(SOURCE, { document, window });
  (listeners['alpine:init'] || []).forEach((fn) => fn({}));
  return components;
}

function pay(dirty) {
  const elements = {
    'inv-payment-method': { value: 'transfer', options: [{ value: 'transfer', textContent: 'Prevod' }, { value: 'cash', textContent: 'Hotovost' }] },
    'inv-payment-note': { value: 'Predvolene' },
  };
  const c = boot(elements).invPay();
  c.$el = { dataset: { dirty } };
  c.init();
  return c;
}

test('starts with transfer, counts the prefilled note and is not dirty', () => {
  const c = pay('0');
  assert.equal(c.isTransfer, true);
  assert.equal(c.isNotTransfer, false);
  assert.equal(c.countLabel, '10 / 500');
  assert.equal(c.dirtyValue, '0');
  assert.equal(c.methodLabel, 'Prevod');
});

test('changing the method updates hints and label', () => {
  const c = pay('0');
  c.$event = { target: { value: 'cash' } };
  c.onMethodChange();
  assert.equal(c.isTransfer, false);
  assert.equal(c.isNotTransfer, true);
  assert.equal(c.methodLabel, 'Hotovost');
});

test('editing the note marks it dirty and updates the counter', () => {
  const c = pay('0');
  c.$event = { target: { value: 'abc' } };
  c.onNoteInput();
  assert.equal(c.dirtyValue, '1');
  assert.equal(c.countLabel, '3 / 500');
});

test('server-side dirty flag is respected', () => {
  assert.equal(pay('1').dirtyValue, '1');
});
