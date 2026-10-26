'use strict';
/* Alpine allocForm: volba mesiaca a zivy prepocet sumy casti. Spustenie: node --test tests/js/ */
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

function make(data) {
  const { components } = boot();
  assert.equal(typeof components.allocForm, 'function');
  const c = components.allocForm();
  c.$el = { dataset: Object.assign({ currencySymbol: '€', decimal: ',', group: '\u00A0', symbolBefore: '' }, data) };
  c.init();
  return c;
}

const NBSP = '\u00A0';

test('monthChoice is read from data attribute', () => {
  const c = make({ hours: '', rate: '27', monthChoice: 'next' });
  assert.equal(c.monthChoice, 'next');
  assert.equal(c.otherMonth, false);
});

test('setMonthChoice other shows the month input', () => {
  const c = make({ hours: '', rate: '27', monthChoice: 'current' });
  c.$event = { target: { value: 'other' } };
  c.setMonthChoice();
  assert.equal(c.otherMonth, true);
});

test('sumText for 8,5 h x 27', () => {
  const c = make({ hours: '8,5', rate: '27', monthChoice: 'current' });
  assert.equal(c.sumText, '229,50' + NBSP + '€');
});

test('sumText with invalid hours is a dash', () => {
  const c = make({ hours: 'abc', rate: '27', monthChoice: 'current' });
  assert.equal(c.sumText, '—');
  c.$event = { target: { value: '2' } };
  c.setHours();
  assert.equal(c.hours, '2');
});
