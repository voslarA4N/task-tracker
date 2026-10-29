'use strict';
/*
 * Alpine komponent etVars v public/assets/js/app.js (vkladanie premennych do sablony e-mailu).
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
  vm.runInNewContext(SOURCE, { document, window, Event });
  (listeners['alpine:init'] || []).forEach((fn) => fn({}));
  return components;
}

function field(name, value, pos) {
  return {
    name, value, selectionStart: pos, selectionEnd: pos, events: [],
    setSelectionRange(a, b) { this.selectionStart = a; this.selectionEnd = b; },
    focus() {}, dispatchEvent(e) { this.events.push(e.type); return true; },
  };
}

function make(body, subject) {
  const c = boot().etVars();
  c.$el = { querySelector(sel) { return sel === 'textarea[name=body]' ? body : subject; } };
  return c;
}

test('insert puts the variable at the cursor of the last focused field', () => {
  const body = field('body', 'Dobrý deň', 5);
  const c = make(body, field('subject', '', 0));
  c.$event = { target: body };
  c.track();
  c.$event = { currentTarget: { dataset: { var: '{{number}}' } } };
  c.insert();
  assert.equal(body.value, 'Dobrý{{number}} deň');
  assert.equal(body.selectionStart, 15);
  assert.equal(body.selectionEnd, 15);
  assert.deepEqual(body.events, ['input']);
});

test('without prior focus the variable is appended to the body', () => {
  const body = field('body', 'Dobrý deň', 0);
  const c = make(body, field('subject', '', 0));
  c.$event = { currentTarget: { dataset: { var: '{{iban}}' } } };
  c.insert();
  assert.equal(body.value, 'Dobrý deň{{iban}}');
});

test('focus on subject redirects insertion to the subject', () => {
  const subject = field('subject', 'Faktúra ', 8);
  const c = make(field('body', 'x', 0), subject);
  c.$event = { target: subject };
  c.track();
  c.$event = { currentTarget: { dataset: { var: '{{number}}' } } };
  c.insert();
  assert.equal(subject.value, 'Faktúra {{number}}');
});
