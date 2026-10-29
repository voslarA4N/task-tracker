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

  /* Panel casti zospodu (Bootstrap Offcanvas): otvorenie po swape do #sheet, zatvorenie po allocationChanged. */
  function offcanvasApi() {
    return window.bootstrap && window.bootstrap.Offcanvas ? window.bootstrap.Offcanvas : null;
  }

  function cleanupSheet() {
    if (document.querySelector('.offcanvas.show')) { return; }
    var backdrops = document.querySelectorAll('.offcanvas-backdrop');
    for (var i = 0; i < backdrops.length; i++) { backdrops[i].parentNode.removeChild(backdrops[i]); }
    document.body.style.removeProperty('overflow');
    document.body.style.removeProperty('padding-right');
  }

  document.addEventListener('DOMContentLoaded', function () {
    var body = document.body;
    body.addEventListener('htmx:afterSwap', function (e) {
      var api = offcanvasApi();
      var target = e && e.detail && e.detail.target;
      if (!api || !target || target.id !== 'sheet') { return; }
      var el = target.querySelector('.offcanvas');
      if (!el) { return; }
      el.addEventListener('shown.bs.offcanvas', function () {
        var first = el.querySelector('input:not([type=hidden]):not([type=radio]), select, textarea, button');
        if (first && first.focus) { first.focus(); }
      }, { once: true });
      api.getOrCreateInstance(el).show();
    });
    body.addEventListener('allocationChanged', function () {
      var api = offcanvasApi();
      var open = document.querySelectorAll('.offcanvas.show');
      if (!api) { return; }
      for (var i = 0; i < open.length; i++) {
        var inst = api.getInstance(open[i]);
        if (inst) { inst.hide(); }
      }
    });
    /* Po HX-Location swape tela mohol ostat backdrop a zamknuty scroll. */
    body.addEventListener('htmx:afterSettle', cleanupSheet);
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
    /* Editor adresatov e-mailu (To/CC): zoznam adries po riadkoch, bez JS ostava textove pole s ciarkami. */
    window.Alpine.data('dlRecipients', function () {
      function parse(text) {
        var out = [];
        String(text || '').split(/[,;\s]+/).forEach(function (a) {
          a = a.trim().toLowerCase();
          if (a !== '' && out.indexOf(a) === -1) { out.push(a); }
        });
        return out;
      }
      return {
        list: [],
        union: [],
        enhanced: false,
        clients: [],
        init: function () {
          var self = this;
          var form = this.$el.closest('form');
          this.raw = this.$el.querySelector('[data-dl-raw]');
          this.list = parse(this.raw ? this.raw.value : '');
          this.clients = parse(form && form.dataset ? form.dataset.clientEmails : '');
          this.enhanced = true;
          var wrap = this.$el.querySelector('[data-dl-rawwrap]') || this.raw;
          if (wrap) { wrap.classList.add('d-none'); }
          if (form && form.addEventListener) {
            form.addEventListener('dl-change', function () { self.refresh(); });
          }
          this.refresh();
        },
        refresh: function () {
          var form = this.$el.closest('form');
          var all = [];
          if (form && form.querySelectorAll) {
            form.querySelectorAll('[data-dl-raw]').forEach(function (r) {
              parse(r.value).forEach(function (a) { if (all.indexOf(a) === -1) { all.push(a); } });
            });
          } else {
            all = this.list.slice();
          }
          this.union = all;
        },
        sync: function () {
          if (this.raw) { this.raw.value = this.list.join(', '); }
          this.refresh();
          var form = this.$el.closest('form');
          if (form && form.dispatchEvent && typeof CustomEvent === 'function') {
            form.dispatchEvent(new CustomEvent('dl-change'));
          }
        },
        add: function () {
          var input = this.$el.querySelector('[data-dl-new]');
          if (!input) { return; }
          var addr = String(input.value || '').trim().toLowerCase();
          if (addr === '' || addr.indexOf('@') < 1 || this.list.indexOf(addr) !== -1) { return; }
          this.list = this.list.concat([addr]);
          input.value = '';
          this.sync();
        },
        remove: function () {
          var addr = this.$event.currentTarget.dataset.addr;
          this.list = this.list.filter(function (a) { return a !== addr; });
          this.sync();
        },
        get notEnhanced() { return !this.enhanced; },
        get warnOutside() {
          var clients = this.clients;
          return this.union.length > 0 && !this.union.some(function (a) { return clients.indexOf(a) !== -1; });
        },
        get noWarn() { return !this.warnOutside; }
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
    /* Formular fakturacnej casti: volba mesiaca a zivy prepocet sumy (server pocita vzdy sam). */
    window.Alpine.data('allocForm', function () {
      return {
        hours: '',
        rate: '',
        monthChoice: 'current',
        currency: '',
        decimal: ',',
        group: ' ',
        symbolBefore: false,
        init: function () {
          var d = this.$el.dataset;
          this.hours = d.hours || '';
          this.rate = d.rate || '';
          this.monthChoice = d.monthChoice || 'current';
          this.currency = d.currencySymbol || '';
          this.decimal = d.decimal || ',';
          this.group = d.group === undefined ? ' ' : d.group;
          this.symbolBefore = !!d.symbolBefore;
        },
        setHours: function () { this.hours = this.$event.target.value; },
        setRate: function () { this.rate = this.$event.target.value; },
        setMonthChoice: function () { this.monthChoice = this.$event.target.value; },
        get otherMonth() { return this.monthChoice === 'other'; },
        get sumText() {
          var amount = calcAmount(this.hours, this.rate);
          if (amount === '') { return '—'; }
          return formatAmount(amount, this.decimal, this.group, this.currency, this.symbolBefore);
        }
      };
    });
    /* Nahlad faktury: tlacidlo Vystavit je neaktivne bez moznosti vystavenia alebo bez potvrdenia spatneho datumu. */
    window.Alpine.data('invIssue', function () {
      var DATE_IDS = { issue_date: 'inv-issue-date', delivery_date: 'inv-delivery-date', due_date: 'inv-due-date' };
      return {
        needsConfirm: false,
        confirmed: false,
        canIssue: false,
        inFlight: false,
        stale: false,
        datesMatch: true,
        handlers: null,
        init: function () {
          var d = this.$el.dataset;
          var self = this;
          this.needsConfirm = d.needsConfirm === '1';
          this.canIssue = d.canIssue === '1';
          this.handlers = {
            before: function (e) { self.onBeforeRequest(e); },
            after: function (e) { self.onAfterRequest(e); },
            error: function (e) { self.onRefreshError(e); },
            dates: function () { self.syncDates(); }
          };
          document.body.addEventListener('htmx:beforeRequest', this.handlers.before);
          document.body.addEventListener('htmx:afterRequest', this.handlers.after);
          document.body.addEventListener('htmx:responseError', this.handlers.error);
          document.body.addEventListener('htmx:sendError', this.handlers.error);
          document.addEventListener('input', this.handlers.dates);
          document.addEventListener('change', this.handlers.dates);
          this.syncDates();
        },
        destroy: function () {
          var h = this.handlers;
          if (!h) { return; }
          document.body.removeEventListener('htmx:beforeRequest', h.before);
          document.body.removeEventListener('htmx:afterRequest', h.after);
          document.body.removeEventListener('htmx:responseError', h.error);
          document.body.removeEventListener('htmx:sendError', h.error);
          document.removeEventListener('input', h.dates);
          document.removeEventListener('change', h.dates);
          this.handlers = null;
        },
        isPreviewRefresh: function (e) {
          var det = e && e.detail;
          if (!det || !det.requestConfig || !det.target) { return false; }
          return String(det.requestConfig.verb).toLowerCase() === 'get' && det.target.id === 'inv-preview';
        },
        onBeforeRequest: function (e) { if (this.isPreviewRefresh(e)) { this.inFlight = true; } },
        onAfterRequest: function (e) { if (this.isPreviewRefresh(e)) { this.inFlight = false; } },
        onRefreshError: function (e) {
          if (this.isPreviewRefresh(e)) { this.stale = true; this.inFlight = false; }
        },
        sameDates: function (visible, hidden) {
          return visible.issue_date === hidden.issue_date &&
            visible.delivery_date === hidden.delivery_date &&
            visible.due_date === hidden.due_date;
        },
        syncDates: function () {
          var form = document.getElementById('inv-issue-form');
          var visible = {};
          var hidden = {};
          var key;
          if (!form) { return; }
          for (key in DATE_IDS) {
            var input = document.getElementById(DATE_IDS[key]);
            var field = form.elements ? form.elements[key] : null;
            if (!input || !field) { return; }
            visible[key] = input.value;
            hidden[key] = field.value;
          }
          this.datesMatch = this.sameDates(visible, hidden);
        },
        toggle: function () { this.confirmed = !this.confirmed; },
        get fresh() { return !this.stale; },
        get disabled() {
          return !this.canIssue || this.inFlight || this.stale || !this.datesMatch || (this.needsConfirm && !this.confirmed);
        }
      };
    });
    /* Nahlad faktury: forma uhrady, poznamka (pocitadlo, dirty priznak) a rekapitulacia v potvrdzovacom paneli. */
    window.Alpine.data('invPay', function () {
      var NOTE_MAX = 500;
      return {
        method: 'transfer',
        note: '',
        dirty: false,
        init: function () {
          var sel = document.getElementById('inv-payment-method');
          var area = document.getElementById('inv-payment-note');
          this.dirty = this.$el.dataset.dirty === '1';
          if (sel) { this.method = sel.value; }
          if (area) { this.note = area.value; }
        },
        onMethodChange: function () { this.method = this.$event.target.value; },
        onNoteInput: function () {
          this.note = this.$event.target.value;
          this.dirty = true;
        },
        get isTransfer() { return this.method === 'transfer'; },
        get isNotTransfer() { return this.method !== 'transfer'; },
        get dirtyValue() { return this.dirty ? '1' : '0'; },
        get countLabel() { return this.note.length + ' / ' + NOTE_MAX; },
        get methodLabel() {
          var sel = document.getElementById('inv-payment-method');
          var i;
          if (!sel || !sel.options) { return this.method; }
          for (i = 0; i < sel.options.length; i++) {
            if (sel.options[i].value === this.method) { return sel.options[i].textContent; }
          }
          return this.method;
        }
      };
    });
    /* Panel presunu casti: volba Dalsi / Iny mesiac. */
    window.Alpine.data('invMove', function () {
      return {
        monthChoice: 'next',
        init: function () { this.monthChoice = this.$el.dataset.monthChoice === 'other' ? 'other' : 'next'; },
        setMonthChoice: function () { this.monthChoice = this.$event.target.value; },
        get otherMonth() { return this.monthChoice === 'other'; }
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
