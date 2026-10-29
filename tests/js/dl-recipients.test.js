'use strict';
/*
 * Alpine komponent dlRecipients v public/assets/js/app.js (editor adresatov).
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

function make(rawText, clients, newValue) {
  const raw = { value: rawText };
  const input = { value: newValue || '' };
  const wrap = { classList: { added: [], add(c) { this.added.push(c); } } };
  const form = {
    dataset: { clientEmails: clients || '' },
    querySelectorAll() { return [raw]; },
    addEventListener() {},
  };
  const c = boot().dlRecipients();
  c.$el = {
    closest() { return form; },
    querySelector(sel) {
      if (sel === '[data-dl-raw]') { return raw; }
      if (sel === '[data-dl-new]') { return input; }
      if (sel === '[data-dl-rawwrap]') { return wrap; }
      return null;
    },
  };
  c.init();
  return { c, raw, input, wrap };
}

test('init parses and lowercases the raw field and hides it', () => {
  const { c, wrap } = make('a@x.sk, B@Y.sk');
  assert.equal(JSON.stringify(c.list), '["a@x.sk","b@y.sk"]');
  assert.equal(wrap.classList.added.join(), 'd-none');
});

test('add appends, remove drops, raw stays in sync', () => {
  const { c, raw, input } = make('a@x.sk, B@Y.sk');
  input.value = ' C@Z.sk ';
  c.add();
  assert.equal(c.list.length, 3);
  assert.equal(raw.value, 'a@x.sk, b@y.sk, c@z.sk');
  assert.equal(input.value, '');
  c.$event = { currentTarget: { dataset: { addr: 'a@x.sk' } } };
  c.remove();
  assert.equal(raw.value, 'b@y.sk, c@z.sk');
});

test('add rejects duplicates, empty and addresses without @', () => {
  const { c, input } = make('a@x.sk');
  ['a@x.sk', 'bez-zavinaca', '   '].forEach((v) => { input.value = v; c.add(); });
  assert.equal(c.list.join(), 'a@x.sk');
});

test('warnOutside is true only when no address belongs to the client', () => {
  assert.equal(make('ja@gmail.com', 'faktury@klient.sk').c.warnOutside, true);
  assert.equal(make('FAKTURY@klient.sk', 'faktury@klient.sk').c.warnOutside, false);
  assert.equal(make('', 'faktury@klient.sk').c.warnOutside, false);
});
