'use strict';
/*
 * Alpine komponent clientForm v public/assets/js/app.js (formular klienta).
 * Spustenie: node --test tests/js/
 */
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const SOURCE = fs.readFileSync(path.join(__dirname, '../../public/assets/js/app.js'), 'utf8');

function boot() {
  const listeners = {};
  const components = {};
  const document = {
    body: { addEventListener() {}, dataset: {} },
    getElementById() { return null; },
    addEventListener(type, fn) { (listeners[type] = listeners[type] || []).push(fn); },
  };
  const window = { Alpine: { data(name, fn) { components[name] = fn; } } };
  vm.runInNewContext(SOURCE, { document, window });
  (listeners['alpine:init'] || []).forEach((fn) => fn({}));
  return components;
}

function make(identifierType, billingMode) {
  const components = boot();
  assert.equal(typeof components.clientForm, 'function');
  const c = components.clientForm();
  c.$el = { dataset: { identifierType, billingMode } };
  c.init();
  return c;
}

test('identifier none disables the value field', () => {
  assert.equal(make('none', 'domestic').identifierDisabled, true);
  assert.equal(make('local_id', 'domestic').identifierDisabled, false);
});

test('eu_b2b makes VAT id required', () => {
  assert.equal(make('local_id', 'eu_b2b').vatRequired, true);
  assert.equal(make('local_id', 'domestic').vatRequired, false);
});

test('setters read the event target value', () => {
  const c = make('local_id', 'domestic');
  c.$event = { target: { value: 'none' } };
  c.setIdentifier();
  assert.equal(c.identifierDisabled, true);
  c.$event = { target: { value: 'eu_b2b' } };
  c.setBilling();
  assert.equal(c.vatRequired, true);
});
