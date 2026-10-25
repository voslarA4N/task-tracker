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

  /* Zivy prepocet sumy: cele cisla v stotinach (BigInt), half-up ako PHP Money. Bez float. */
  function parseCents(text, maxWhole) {
    var plain = String(text == null ? '' : text).replace(/[\s ]+/g, '').replace(',', '.');
    var re = new RegExp('^\\d{1,' + maxWhole + '}(\\.\\d{1,2})?$');
    if (!re.test(plain)) { return null; }
    var parts = plain.split('.');
    var frac = (parts[1] || '') + '00';
    return BigInt(parts[0]) * BigInt(100) + BigInt(frac.substring(0, 2));
  }

  /* hodiny x sadzba -> 'celé.dd' alebo '' pri neplatnom vstupe */
  function calcAmount(hours, rate) {
    var h = parseCents(hours, 5);
    var r = parseCents(rate, 8);
    if (h === null || r === null) { return ''; }
    var cents = (h * r + BigInt(50)) / BigInt(100);
    var digits = cents.toString();
    while (digits.length < 3) { digits = '0' + digits; }
    return digits.substring(0, digits.length - 2) + '.' + digits.substring(digits.length - 2);
  }

  function formatAmount(decimalText, decimal, group, symbol, symbolBefore) {
    if (!decimalText) { return ''; }
    var parts = decimalText.split('.');
    var whole = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, group || '');
    var number = whole + (decimal || ',') + parts[1];
    return symbolBefore ? symbol + number : number + ' ' + symbol;
  }

  window.AppMoney = { calcAmount: calcAmount, formatAmount: formatAmount };

  /* Vzor registracie pre Alpine CSP build (musi sa nacitat pred Alpine). */
  document.addEventListener('alpine:init', function () {
    window.Alpine.data('appShell', function () { return {}; });
    /* Kopirovanie TOTP tajomstva do schranky; texty a tajomstvo z data-* atributov. */
    /* Potvrdenie ulozenia zalozych kodov odomkne tlacidlo Pokracovat. */
    window.Alpine.data('codesAck', function () {
      return {
        acked: false,
        toggle: function () { this.acked = this.$event.target.checked; }
      };
    });
    /* Formular klienta: vypnutie hodnoty identifikatora pri type "none", povinne IC DPH pri eu_b2b. */
    window.Alpine.data('clientForm', function () {
      return {
        identifierType: '',
        billingMode: '',
        init: function () {
          this.identifierType = this.$el.dataset.identifierType || '';
          this.billingMode = this.$el.dataset.billingMode || '';
        },
        setIdentifier: function () { this.identifierType = this.$event.target.value; },
        setBilling: function () { this.billingMode = this.$event.target.value; },
        get identifierDisabled() { return this.identifierType === 'none'; },
        get vatRequired() { return this.billingMode === 'eu_b2b'; }
      };
    });
    /* Formular ulohy: zivy prepocet nacenenej sumy (server pocita vzdy sam). */
    window.Alpine.data('taskForm', function () {
      return {
        hours: '',
        rate: '',
        currency: '',
        decimal: ',',
        group: ' ',
        symbolBefore: false,
        init: function () {
          var d = this.$el.dataset;
          this.hours = d.hours || '';
          this.rate = d.rate || '';
          this.readFormat(d);
        },
        readFormat: function (d) {
          this.currency = d.currencySymbol || '';
          this.decimal = d.decimal || ',';
          this.group = d.group === undefined ? ' ' : d.group;
          this.symbolBefore = !!d.symbolBefore;
        },
        setHours: function () { this.hours = this.$event.target.value; },
        setRate: function () { this.rate = this.$event.target.value; },
        syncRate: function () {
          var d = this.$el.dataset;
          this.rate = d.rate || '';
          this.readFormat(d);
        },
        get sumText() {
          var amount = calcAmount(this.hours, this.rate);
          if (amount === '') { return '—'; }
          return formatAmount(amount, this.decimal, this.group, this.currency, this.symbolBefore);
        }
      };
    });
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
