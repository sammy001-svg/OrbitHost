/**
 * Orbit Cloud — KES / USD currency toggle
 *
 * Resolution order on first load: existing `orbit_currency` cookie (a
 * prior geo-IP lookup, or an explicit toggle) wins; otherwise this asks
 * api/geo-currency.php to geo-detect from the visitor's IP (Kenya -> KES,
 * everywhere else -> USD) and remembers the result server-side so it's
 * only ever looked up once per visitor.
 *
 * Any price element can opt in with data-usd-html/data-kes-html holding
 * the exact markup to show for each currency — this file only swaps
 * innerHTML, it never tries to parse or reformat a price itself, so each
 * page controls its own layout (digit/decimal split, "/mo" suffix, etc).
 */
(function () {
  var COOKIE_NAME = 'orbit_currency';

  function getCookie(name) {
    var m = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
    return m ? decodeURIComponent(m[1]) : null;
  }

  function siteBase() {
    var s = document.querySelector('script[src*="currency.js"]');
    if (s && s.src) return s.src.replace(/\/js\/currency\.js.*$/i, '');
    return '';
  }
  var BASE = siteBase();

  function applyCurrency(cur) {
    cur = cur === 'KES' ? 'KES' : 'USD';
    document.documentElement.setAttribute('data-currency', cur);

    document.querySelectorAll('[data-usd-html]').forEach(function (el) {
      var html = cur === 'KES' ? el.getAttribute('data-kes-html') : el.getAttribute('data-usd-html');
      if (html != null) el.innerHTML = html;
    });
    document.querySelectorAll('[data-usd][data-kes]').forEach(function (el) {
      var val = cur === 'KES' ? el.getAttribute('data-kes') : el.getAttribute('data-usd');
      if (val != null) el.textContent = val;
    });
    document.querySelectorAll('.currency-toggle [data-cur]').forEach(function (btn) {
      btn.classList.toggle('active', btn.getAttribute('data-cur') === cur);
    });

    document.dispatchEvent(new CustomEvent('orbit:currency-changed', { detail: { currency: cur } }));
  }

  // Exposed so widgets that fetch their own prices (e.g. the live TLD
  // pricing table) can read the current choice and re-render on change.
  window.OrbitCurrency = {
    get: function () { return getCookie(COOKIE_NAME) === 'KES' ? 'KES' : 'USD'; },
    apply: applyCurrency,
    set: function (cur) {
      cur = cur === 'KES' ? 'KES' : 'USD';
      applyCurrency(cur); // optimistic — don't make the click feel laggy
      fetch(BASE + '/api/geo-currency.php?set=' + cur, { credentials: 'same-origin' }).catch(function () {});
    },
  };

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.currency-toggle [data-cur]').forEach(function (btn) {
      btn.addEventListener('click', function () { window.OrbitCurrency.set(btn.getAttribute('data-cur')); });
    });

    var existing = getCookie(COOKIE_NAME);
    if (existing === 'KES' || existing === 'USD') {
      applyCurrency(existing);
      return;
    }
    fetch(BASE + '/api/geo-currency.php', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) { applyCurrency(d && d.currency === 'KES' ? 'KES' : 'USD'); })
      .catch(function () { applyCurrency('USD'); });
  });
})();
