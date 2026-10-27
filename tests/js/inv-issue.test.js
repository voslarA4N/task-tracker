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
    body: { addEventListener() {}, removeEventListener() {}, dataset: {} },
    getElementById() { return null; },
    removeEventListener() {},
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

const D = { issue_date: '2026-10-27', delivery_date: '2026-10-31', due_date: '2026-11-10' };
const getEvt = (id) => ({ detail: { requestConfig: { verb: 'get' }, target: { id } } });

test('sameDates detects any differing date', () => {
  const c = issue({ canIssue: '1', needsConfirm: '0' });
  assert.equal(c.sameDates(D, { ...D }), true);
  for (const k of Object.keys(D)) {
    assert.equal(c.sameDates(D, { ...D, [k]: '2026-01-01' }), false);
  }
});

test('preview refresh in flight disables, POST does not', () => {
  const c = issue({ canIssue: '1', needsConfirm: '0' });
  c.onBeforeRequest({ detail: { requestConfig: { verb: 'post' }, target: { id: 'inv-preview' } } });
  assert.equal(c.inFlight, false);
  c.onBeforeRequest(getEvt('inv-preview'));
  assert.equal(c.inFlight, true);
  assert.equal(c.disabled, true);
  c.onAfterRequest(getEvt('inv-preview'));
  assert.equal(c.disabled, false);
});

test('failed preview refresh keeps button disabled', () => {
  const c = issue({ canIssue: '1', needsConfirm: '0' });
  c.onBeforeRequest(getEvt('inv-preview'));
  c.onRefreshError(getEvt('inv-preview'));
  c.onAfterRequest(getEvt('inv-preview'));
  assert.equal(c.stale, true);
  assert.equal(c.disabled, true);
  const o = issue({ canIssue: '1', needsConfirm: '0' });
  o.onRefreshError(getEvt('other'));
  assert.equal(o.stale, false);
});

test('date mismatch disables even when confirmed', () => {
  const c = issue({ canIssue: '1', needsConfirm: '1' });
  c.toggle();
  assert.equal(c.disabled, false);
  c.datesMatch = false;
  assert.equal(c.disabled, true);
});

test('destroy is safe', () => {
  const c = issue({ canIssue: '1', needsConfirm: '0' });
  c.destroy();
  c.destroy();
});
