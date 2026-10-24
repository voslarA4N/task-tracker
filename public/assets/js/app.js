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

  /* True, ak htmx vkladal odpoved priamo do #app-alert (napr. HX-Retarget pri CSRF 403). */
  function targetsAlert(e) {
    var box = alertBox();
    return !!box && !!e && !!e.detail && e.detail.target === box;
  }

  document.addEventListener('DOMContentLoaded', function () {
    var body = document.body;
    /* Fragment zo servera (vlastna sprava) zviditelnit, #app-alert je v layoute hidden. */
    body.addEventListener('htmx:afterSwap', function (e) {
      if (targetsAlert(e)) { e.detail.target.removeAttribute('hidden'); }
    });
    /* htmx 2 vola responseError az po swape: serverovy fragment v #app-alert neprepisovat. */
    body.addEventListener('htmx:responseError', function (e) {
      if (targetsAlert(e)) { return; }
      show(body.dataset.msgServer);
    });
    body.addEventListener('htmx:sendError', function () { show(body.dataset.msgNetwork); });
    body.addEventListener('htmx:beforeRequest', hide);
  });

  /* Jedno odoslanie: po submite zablokovat tlacidlo a ukazat spinner (formular funguje aj bez JS). */
  document.addEventListener('DOMContentLoaded', function () {
    document.addEventListener('submit', function (e) {
      var form = e && e.target;
      if (!form || !form.matches || !form.matches('form[data-submit-once]')) { return; }
      var button = form.querySelector('button[type=submit]');
      var spinner = form.querySelector('button[type=submit] .spinner-border');
      if (button) { button.disabled = true; }
      if (spinner) { spinner.classList.remove('d-none'); }
    }, true);
  });

  /* Vzor registracie pre Alpine CSP build (musi sa nacitat pred Alpine). */
  document.addEventListener('alpine:init', function () {
    window.Alpine.data('appShell', function () { return {}; });
    /* Kopirovanie TOTP tajomstva do schranky; texty a tajomstvo z data-* atributov. */
    window.Alpine.data('copySecret', function () {
      return {
        label: '',
        init: function () { this.label = this.$el.dataset.labelCopy || ''; },
        copy: function () {
          var self = this;
          var el = this.$el;
          if (!navigator.clipboard) { return; }
          navigator.clipboard.writeText(el.dataset.secret).then(function () {
            self.label = el.dataset.labelCopied || '';
            setTimeout(function () { self.label = el.dataset.labelCopy || ''; }, 2000);
          }, function () {});
        }
      };
    });
  });
})();
