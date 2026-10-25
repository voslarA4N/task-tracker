'use strict';
/*
 * Zivy prepocet nacenenej sumy (BigInt, half-up) v public/assets/js/app.js musi davat
 * rovnake vysledky ako PHP Money (tests/unit/Platform/MoneyTest.php).
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
  return { components, window };
}

function make(hours, rate) {
  const { components } = boot();
  assert.equal(typeof components.taskForm, 'function');
  const c = components.taskForm();
  c.$el = { dataset: { hours, rate, currencySymbol: '€', decimal: ',', group: ' ', symbolBefore: '' } };
  c.init();
  return c;
}

const NBSP = ' ';

test('calcAmount parity with Money', () => {
  const { window } = boot();
  const calc = window.AppMoney.calcAmount;
  assert.equal(calc('8,1', '27'), '218.70');
  assert.equal(calc('8.1', '27.00'), '218.70');
  assert.equal(calc('0.01', '0.5'), '0.01');
  assert.equal(calc('0.01', '0.49'), '0.00');
  assert.equal(calc('1', '99999999.99'), '99999999.99');
});

test('calcAmount rejects invalid input', () => {
  const { window } = boot();
  const calc = window.AppMoney.calcAmount;
  assert.equal(calc('abc', '27'), '');
  assert.equal(calc('8.555', '27'), '');
  assert.equal(calc('', '27'), '');
});

test('sumText formats with separators and symbol', () => {
  assert.equal(make('2', '27.00').sumText, '54,00' + NBSP + '€');
  assert.equal(make('40', '27').sumText, '1' + NBSP + '080,00' + NBSP + '€');
});

test('sumText shows dash for invalid input', () => {
  assert.equal(make('x', '27').sumText, '—');
});

test('setHours and setRate read the event target', () => {
  const c = make('2', '27.00');
  c.$event = { target: { value: '3' } };
  c.setHours();
  assert.equal(c.sumText, '81,00' + NBSP + '€');
  c.$event = { target: { value: '10' } };
  c.setRate();
  assert.equal(c.sumText, '30,00' + NBSP + '€');
});
