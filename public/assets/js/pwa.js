/*
 * PWA: instalacny panel pre iPhone Safari (D-24). ES5, bez inline skriptov (CSP).
 * Registracia service workera (02-13) a tlacidlo [data-reload] na offline stranke.
 */
(function () {
  'use strict';

  var DISMISS_KEY = 'wt_install_hint_dismissed';

  function shouldShowInstallHint(nav, win, storage) {
    var ua = (nav && nav.userAgent) || '';
    if (!/iPhone|iPad|iPod/.test(ua)) { return false; }
    if (!/Safari/.test(ua) || /CriOS|FxiOS|EdgiOS/.test(ua)) { return false; }
    if (nav.standalone === true) { return false; }
    try {
      if (win && win.matchMedia && win.matchMedia('(display-mode: standalone)').matches === true) { return false; }
    } catch (e) { /* ignoruj */ }
    try {
      if (storage && storage.getItem(DISMISS_KEY) === '1') { return false; }
    } catch (e) { /* sukromny rezim: berieme ako nezatvorene */ }
    return true;
  }

  window.WtPwa = { shouldShowInstallHint: shouldShowInstallHint };

  document.addEventListener('DOMContentLoaded', function () {
    var hint = document.querySelector('[data-install-hint]');
    if (!hint) { return; }
    var storage = null;
    try { storage = window.localStorage; } catch (e) { storage = null; }

    if (shouldShowInstallHint(window.navigator, window, storage)) {
      hint.removeAttribute('hidden');
    }

    var close = document.querySelector('[data-install-hint-close]');
    if (close) {
      close.addEventListener('click', function () {
        hint.setAttribute('hidden', '');
        try { storage.setItem(DISMISS_KEY, '1'); } catch (e) { /* ignoruj */ }
      });
    }
  });

  if (typeof window.addEventListener === 'function') { window.addEventListener('load', function () {
    if (window.navigator && 'serviceWorker' in window.navigator) {
      try {
        window.navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(function () { /* ticho */ });
      } catch (e) { /* ticho */ }
    }
  }); }

  document.addEventListener('click', function (ev) {
    var t = ev.target;
    if (t && t.closest && t.closest('[data-reload]')) { window.location.reload(); }
  });
}());
