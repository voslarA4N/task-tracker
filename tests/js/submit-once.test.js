'use strict';
/*
 * data-submit-once v public/assets/js/app.js: po odoslani sa tlacidlo zablokuje
 * a ukaze sa spinner. Spustenie: node --test tests/js/
 */
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const SOURCE = fs.readFileSync(path.join(__dirname, '../../public/assets/js/app.js'), 'utf8');

function classList(initial) {
  const set = new Set(initial);
  return {
    remove(c) { set.delete(c); },
    add(c) { set.add(c); },
    contains(c) { return set.has(c); },
  };
}

function makeForm(once) {
  const button = { disabled: false };
  const spinner = { classList: classList(['spinner-border', 'd-none']) };
  const form = {
    matches(sel) { return once && sel === 'form[data-submit-once]'; },
    hasAttribute(n) { return once && n === 'data-submit-once'; },
    querySelector(sel) {
      if (sel === 'button[type=submit]') { return button; }
      if (sel === 'button[type=submit] .spinner-border' || sel === '.spinner-border') { return spinner; }
      return null;
    },
    querySelectorAll(sel) {
      if (sel === 'button[type=submit]') { return [button]; }
      if (sel === '.spinner-border' || sel === 'button[type=submit] .spinner-border') { return [spinner]; }
      return [];
    },
    closest(sel) { return once && sel === 'form[data-submit-once]' ? form : null; },
  };
  return { form, button, spinner };
}

function boot() {
  const listeners = {};
  const document = {
    body: { addEventListener() {}, dataset: {} },
    getElementById() { return null; },
    addEventListener(type, fn) { (listeners[type] = listeners[type] || []).push(fn); },
    dispatch(type, event) { (listeners[type] || []).forEach((fn) => fn(event)); },
  };
  vm.runInNewContext(SOURCE, { document, window: {} });
  document.dispatch('DOMContentLoaded', {});
  return document;
}

test('submit of data-submit-once form disables button and shows spinner', () => {
  const document = boot();
  const { form, button, spinner } = makeForm(true);
  document.dispatch('submit', { type: 'submit', target: form });
  assert.equal(button.disabled, true);
  assert.equal(spinner.classList.contains('d-none'), false);
});

test('form without data-submit-once is untouched', () => {
  const document = boot();
  const { form, button, spinner } = makeForm(false);
  document.dispatch('submit', { type: 'submit', target: form });
  assert.equal(button.disabled, false);
  assert.equal(spinner.classList.contains('d-none'), true);
});
