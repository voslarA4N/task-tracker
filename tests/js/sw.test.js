'use strict';
/*
 * Service worker (app/Views/pwa/service_worker.js) v simulovanom prostredi.
 * Spustenie: node --test tests/js/
 */
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const TEMPLATE = fs.readFileSync(path.join(__dirname, '../../app/Views/pwa/service_worker.js'), 'utf8');
const PRECACHE = ['/offline', '/assets/css/app.css?v=1'];
const SOURCE = TEMPLATE.replace('__SW_VERSION__', 'test123').replace('__PRECACHE__', JSON.stringify(PRECACHE));
const ORIGIN = 'https://wt.test';

function boot(opts) {
  opts = opts || {};
  const handlers = {};
  const store = Object.assign({}, opts.cached || {});
  const calls = { addAll: null, put: [], fetch: [], deleted: [], skipWaiting: 0, claim: 0, opened: [] };
  const cacheObj = {
    addAll(list) { calls.addAll = list; return Promise.resolve(); },
    put(req, res) { calls.put.push(req); return Promise.resolve(); },
  };
  const caches = {
    open(name) { calls.opened.push(name); return Promise.resolve(cacheObj); },
    keys() { return Promise.resolve(opts.cacheNames || []); },
    delete(n) { calls.deleted.push(n); return Promise.resolve(true); },
    match(req) {
      const key = typeof req === 'string' ? req : req.url.replace(ORIGIN, '');
      return Promise.resolve(Object.prototype.hasOwnProperty.call(store, key) ? store[key] : undefined);
    },
  };
  const self = {
    addEventListener(type, fn) { handlers[type] = fn; },
    skipWaiting() { calls.skipWaiting++; return Promise.resolve(); },
    clients: { claim() { calls.claim++; return Promise.resolve(); } },
    location: { origin: ORIGIN },
  };
  const sandbox = {
    self, caches, URL, Promise,
    location: { origin: ORIGIN },
    fetch(req) {
      calls.fetch.push(req);
      if (opts.networkFail) return Promise.reject(new Error('offline'));
      return Promise.resolve(opts.response || { ok: true, redirected: false, clone() { return this; }, marker: 'NET' });
    },
  };
  vm.runInNewContext(SOURCE, sandbox);
  return { handlers, calls, sandbox };
}

function lifecycle(handler) {
  let p;
  handler({ waitUntil(x) { p = x; } });
  return p;
}

function fetchEvent(h, url, o) {
  o = o || {};
  const req = { url: ORIGIN + url, method: o.method || 'GET', mode: o.mode || 'cors' };
  if (/^https?:/.test(url)) req.url = url;
  const ev = { request: req, responded: false, result: null, respondWith(p) { ev.responded = true; ev.result = p; } };
  h.handlers.fetch(ev);
  return ev;
}

test('install precaches list incl. /offline and skipWaiting', async () => {
  const h = boot();
  await lifecycle(h.handlers.install);
  assert.ok(h.calls.addAll.includes('/offline'));
  assert.deepEqual(h.calls.opened, ['wt-static-test123']);
  assert.equal(h.calls.skipWaiting, 1);
});

test('activate deletes other caches and claims clients', async () => {
  const h = boot({ cacheNames: ['wt-static-old', 'wt-static-test123', 'other'] });
  await lifecycle(h.handlers.activate);
  assert.deepEqual(h.calls.deleted.sort(), ['other', 'wt-static-old']);
  assert.equal(h.calls.claim, 1);
});

test('navigation uses network and never stores', async () => {
  const h = boot();
  const ev = fetchEvent(h, '/tasks', { mode: 'navigate' });
  assert.ok(ev.responded);
  const res = await ev.result;
  assert.equal(res.marker, 'NET');
  assert.equal(h.calls.put.length, 0);
});

test('navigation failure falls back to /offline', async () => {
  const h = boot({ networkFail: true, cached: { '/offline': 'OFFLINE_PAGE' } });
  const ev = fetchEvent(h, '/tasks', { mode: 'navigate' });
  assert.equal(await ev.result, 'OFFLINE_PAGE');
});

test('POST is not handled', () => {
  const h = boot();
  assert.equal(fetchEvent(h, '/assets/css/app.css', { method: 'POST' }).responded, false);
});

test('cross-origin is not handled', () => {
  const h = boot();
  assert.equal(fetchEvent(h, 'https://evil.test/assets/x.js').responded, false);
});

test('cached asset is served without fetch', async () => {
  const h = boot({ cached: { '/assets/css/app.css?v=1': 'CACHED' } });
  const ev = fetchEvent(h, '/assets/css/app.css?v=1');
  assert.equal(await ev.result, 'CACHED');
  assert.equal(h.calls.fetch.length, 0);
});

test('uncached asset is fetched and stored when ok and not redirected', async () => {
  const h = boot();
  const ev = fetchEvent(h, '/assets/js/app.js');
  await ev.result;
  assert.equal(h.calls.fetch.length, 1);
  assert.equal(h.calls.put.length, 1);
});

test('redirected asset response is not stored', async () => {
  const res = { ok: true, redirected: true, clone() { return this; } };
  const h = boot({ response: res });
  const ev = fetchEvent(h, '/assets/js/app.js');
  await ev.result;
  assert.equal(h.calls.put.length, 0);
});

test('non-ok asset response is not stored', async () => {
  const res = { ok: false, redirected: false, clone() { return this; } };
  const h = boot({ response: res });
  await fetchEvent(h, '/assets/js/app.js').result;
  assert.equal(h.calls.put.length, 0);
});

test('htmx / non-asset same-origin GET is not handled', () => {
  const h = boot();
  assert.equal(fetchEvent(h, '/tasks/list').responded, false);
});
