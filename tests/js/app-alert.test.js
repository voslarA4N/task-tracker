'use strict';
/*
 * Spravanie public/assets/js/app.js pri chybach htmx (CR-01), bez prehliadaca.
 * Minimalny falosny DOM + udalosti v poradi htmx 2.0.11: beforeRequest, swap,
 * afterSwap, responseError. Spustenie: node --test tests/js/
 */
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const SOURCE = fs.readFileSync(path.join(__dirname, '../../public/assets/js/app.js'), 'utf8');

function element(id) {
  const listeners = {};
  const attrs = {};
  let html = '';
  return {
    id,
    className: '',
    dataset: {},
    get innerHTML() { return html; },
    set innerHTML(v) { html = String(v); },
    get textContent() { return html.replace(/<[^>]*>/g, ''); },
    set textContent(v) { html = String(v).replace(/&/g, '&amp;').replace(/</g, '&lt;'); },
    setAttribute(n, v) { attrs[n] = String(v); },
    removeAttribute(n) { delete attrs[n]; },
    hasAttribute(n) { return Object.prototype.hasOwnProperty.call(attrs, n); },
    addEventListener(type, fn) { (listeners[type] = listeners[type] || []).push(fn); },
    dispatch(type, detail) { (listeners[type] || []).forEach((fn) => fn({ type, detail })); },
  };
}

function boot() {
  const body = element('body');
  body.dataset.msgServer = 'SERVER_MSG';
  body.dataset.msgNetwork = 'NETWORK_MSG';
  const box = element('app-alert');
  box.setAttribute('hidden', '');
  const form = element('calc');
  const document = element('document');
  document.body = body;
  document.getElementById = (id) => (id === 'app-alert' ? box : null);
  vm.runInNewContext(SOURCE, { document, window: {} });
  document.dispatch('DOMContentLoaded', {});
  return { body, box, form };
}

const FRAGMENT = '<div class="alert alert-danger" role="alert">CSRF_MSG</div>';

/* htmx 2: HX-Retarget #app-alert, swap innerHTML, potom afterSwap a responseError. */
function csrf403(env, order) {
  const detail = { target: env.box, elt: env.form, xhr: { status: 403 } };
  env.body.dispatch('htmx:beforeRequest', { target: env.form, elt: env.form });
  env.box.innerHTML = FRAGMENT;
  order.forEach((type) => env.body.dispatch(type, detail));
}

test('CSRF 403 fragment v #app-alert je viditelny a neprepisany', () => {
  const env = boot();
  csrf403(env, ['htmx:afterSwap', 'htmx:responseError']);
  assert.equal(env.box.hasAttribute('hidden'), false);
  assert.equal(env.box.textContent, 'CSRF_MSG');
  assert.ok(!env.box.textContent.includes('SERVER_MSG'));
});

test('vysledok nezavisi od poradia afterSwap a responseError', () => {
  const env = boot();
  csrf403(env, ['htmx:responseError', 'htmx:afterSwap']);
  assert.equal(env.box.hasAttribute('hidden'), false);
  assert.equal(env.box.textContent, 'CSRF_MSG');
});

test('chyba servera bez fragmentu ukaze vseobecnu spravu', () => {
  const env = boot();
  env.body.dispatch('htmx:beforeRequest', { target: env.form });
  env.body.dispatch('htmx:responseError', { target: env.form, xhr: { status: 500 } });
  assert.equal(env.box.hasAttribute('hidden'), false);
  assert.equal(env.box.textContent, 'SERVER_MSG');
});

test('sietova chyba ukaze spravu o pripojeni', () => {
  const env = boot();
  env.body.dispatch('htmx:sendError', { target: env.form });
  assert.equal(env.box.textContent, 'NETWORK_MSG');
});

test('swap do ineho ciela #app-alert neodkryje, dalsia poziadavka ho skryje', () => {
  const env = boot();
  env.body.dispatch('htmx:afterSwap', { target: env.form });
  assert.equal(env.box.hasAttribute('hidden'), true);

  csrf403(env, ['htmx:afterSwap', 'htmx:responseError']);
  env.body.dispatch('htmx:beforeRequest', { target: env.form });
  assert.equal(env.box.hasAttribute('hidden'), true);
  assert.equal(env.box.textContent, '');
});
