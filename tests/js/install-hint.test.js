'use strict';
/*
 * Instalacny panel (public/assets/js/pwa.js), bez prehliadaca.
 * Spustenie: node --test tests/js/
 */
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const SOURCE = fs.readFileSync(path.join(__dirname, '../../public/assets/js/pwa.js'), 'utf8');

const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';
const IOS_CHROME = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/130.0 Mobile/15E148 Safari/604.1';
const DESKTOP = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

function element() {
  const listeners = {};
  const attrs = {};
  return {
    setAttribute(n, v) { attrs[n] = String(v); },
    removeAttribute(n) { delete attrs[n]; },
    hasAttribute(n) { return Object.prototype.hasOwnProperty.call(attrs, n); },
    addEventListener(type, fn) { (listeners[type] = listeners[type] || []).push(fn); },
    dispatch(type) { (listeners[type] || []).forEach((fn) => fn({ type })); },
  };
}

function storage(data, throws) {
  return {
    getItem(k) { if (throws) throw new Error('denied'); return Object.prototype.hasOwnProperty.call(data, k) ? data[k] : null; },
    setItem(k, v) { if (throws) throw new Error('denied'); data[k] = String(v); },
  };
}

function boot(opts) {
  const hint = element();
  hint.setAttribute('hidden', '');
  const close = element();
  const document = element();
  document.querySelector = (s) => (s === '[data-install-hint]' ? hint : s === '[data-install-hint-close]' ? close : null);
  const data = opts.data || {};
  const win = {
    navigator: { userAgent: opts.ua, standalone: opts.standalone },
    matchMedia: () => ({ matches: !!opts.displayMode }),
    localStorage: storage(data, opts.throws),
  };
  vm.runInNewContext(SOURCE, { document, window: win, navigator: win.navigator });
  document.dispatch('DOMContentLoaded');
  return { hint, close, data, win };
}

test('iPhone Safari mimo standalone zobrazi panel', () => {
  const { hint } = boot({ ua: IPHONE, standalone: false });
  assert.equal(hint.hasAttribute('hidden'), false);
});

test('navigator.standalone true panel skryje', () => {
  assert.equal(boot({ ua: IPHONE, standalone: true }).hint.hasAttribute('hidden'), true);
});

test('display-mode standalone panel skryje', () => {
  assert.equal(boot({ ua: IPHONE, standalone: false, displayMode: true }).hint.hasAttribute('hidden'), true);
});

test('iOS Chrome (CriOS) panel skryje', () => {
  assert.equal(boot({ ua: IOS_CHROME, standalone: false }).hint.hasAttribute('hidden'), true);
});

test('desktop panel skryje', () => {
  assert.equal(boot({ ua: DESKTOP }).hint.hasAttribute('hidden'), true);
});

test('predtym zatvoreny panel ostane skryty', () => {
  const r = boot({ ua: IPHONE, standalone: false, data: { wt_install_hint_dismissed: '1' } });
  assert.equal(r.hint.hasAttribute('hidden'), true);
});

test('zatvorenie skryje panel a ulozi 1', () => {
  const r = boot({ ua: IPHONE, standalone: false });
  r.close.dispatch('click');
  assert.equal(r.hint.hasAttribute('hidden'), true);
  assert.equal(r.data.wt_install_hint_dismissed, '1');
});

test('localStorage vyhodi vynimku: panel sa zobrazi a zatvorenie nespadne', () => {
  const r = boot({ ua: IPHONE, standalone: false, throws: true });
  assert.equal(r.hint.hasAttribute('hidden'), false);
  assert.doesNotThrow(() => r.close.dispatch('click'));
  assert.equal(r.hint.hasAttribute('hidden'), true);
});
