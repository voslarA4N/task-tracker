'use strict';
/* Alpine invIssue a invMove: povolenie tlacidla Vystavit a volba mesiaca presunu. Spustenie: node --test tests/js/ */
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

function issue(dataset) {
  const components = boot();
  assert.equal(typeof components.invIssue, 'function');
  const c = components.invIssue();
  c.$el = { dataset };
  c.init();
  return c;
}

test('backdate confirmation toggles the disabled state', () => {
  const c = issue({ canIssue: '1', needsConfirm: '1' });
  assert.equal(c.disabled, true);
  c.toggle();
  assert.equal(c.disabled, false);
  c.toggle();
  assert.equal(c.disabled, true);
});

test('issuing without confirmation requirement is enabled', () => {
  const c = issue({ canIssue: '1', needsConfirm: '0' });
  assert.equal(c.disabled, false);
});

test('cannot issue stays disabled even when confirmed', () => {
  const c = issue({ canIssue: '0', needsConfirm: '1' });
  assert.equal(c.disabled, true);
  c.toggle();
  assert.equal(c.disabled, true);
  assert.equal(issue({ canIssue: '0', needsConfirm: '0' }).disabled, true);
});

test('invMove defaults to next and switches to other', () => {
  const components = boot();
  assert.equal(typeof components.invMove, 'function');
  const c = components.invMove();
  assert.equal(c.monthChoice, 'next');
  assert.equal(c.otherMonth, false);
  c.$event = { target: { value: 'other' } };
  c.setMonthChoice();
  assert.equal(c.otherMonth, true);
});
