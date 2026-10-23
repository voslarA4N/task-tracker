/* Externy skript (prisna CSP): chyby htmx a registracia Alpine CSP komponentov. */
(function () {
  'use strict';

  function alertBox() {
    return document.getElementById('app-alert');
  }

  function show(message) {
    var box = alertBox();
    if (!box) { return; }
    box.textContent = message || '';
    box.className = 'alert alert-danger';
    box.removeAttribute('hidden');
  }

  function hide() {
    var box = alertBox();
    if (!box) { return; }
    box.textContent = '';
    box.className = '';
    box.setAttribute('hidden', '');
  }

  document.addEventListener('DOMContentLoaded', function () {
    var body = document.body;
    body.addEventListener('htmx:responseError', function () { show(body.dataset.msgServer); });
    body.addEventListener('htmx:sendError', function () { show(body.dataset.msgNetwork); });
    body.addEventListener('htmx:beforeRequest', hide);
  });

  /* Vzor registracie pre Alpine CSP build (musi sa nacitat pred Alpine). */
  document.addEventListener('alpine:init', function () {
    window.Alpine.data('appShell', function () { return {}; });
  });
})();
