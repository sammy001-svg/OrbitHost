/**
 * OrbitHost — Internal Client Portal link wiring
 *
 * This file used to redirect "Log In" and "Get Started" actions to an
 * external WHMCS billing system. That integration has been removed —
 * every action now routes to the in-house client portal at /portal.
 * No third-party system is contacted.
 *
 * The site markup is unchanged: buttons are still wired by their
 * data-attributes, and the window.WHMCS global name is kept so the
 * domain-search widget in main.js keeps working without edits.
 *
 *   data-whmcs-action="login"   → portal login        (/portal/login.php)
 *   data-whmcs-product="slug"   → portal signup        (/portal/register.php?plan=slug)
 *   data-whmcs-action="ticket"  → portal login (tickets live in the portal)
 *   data-whmcs-action="kb"      → contact page
 *   data-whmcs-action="status"  → contact page
 */

(function () {
  // Derive the site base from this script's own resolved URL, so links
  // work whether the site is served from the domain root or a subfolder,
  // and from both top-level pages (js/whmcs.js) and /hosting/ pages
  // (../js/whmcs.js) — the browser resolves both to an absolute .src.
  function siteBase() {
    var s = document.querySelector('script[src*="whmcs.js"]');
    if (s && s.src) {
      return s.src.replace(/\/js\/whmcs\.js.*$/i, '');
    }
    return ''; // fall back to root-relative paths
  }

  var BASE   = siteBase();
  var PORTAL = BASE + '/portal';

  /* ── Internal portal URL helpers (same global name for compatibility) ── */
  window.WHMCS = {
    base: PORTAL,
    // "Get Started" on a plan → registration, carrying the chosen plan slug
    orderUrl: function (slug) {
      return PORTAL + '/register.php?plan=' + encodeURIComponent(slug);
    },
    // Domain search "Add to Cart" → registration, carrying the domain
    domainUrl: function (domain) {
      return PORTAL + '/register.php?domain=' + encodeURIComponent(domain);
    },
    clientArea:    PORTAL + '/login.php',
    submitTicket:  PORTAL + '/login.php',
    knowledgeBase: BASE + '/contact.html',
    serverStatus:  BASE + '/contact.html',
  };

  /* ── Auto-wire on DOM ready ────────────────── */
  document.addEventListener('DOMContentLoaded', function () {

    // Point an element at an internal URL and keep it in the same tab.
    function wire(el, url) {
      el.href = url;
      el.removeAttribute('target');
      el.removeAttribute('rel');
    }

    // Plan "Get Started" buttons: [data-whmcs-product="slug"]
    document.querySelectorAll('[data-whmcs-product]').forEach(function (el) {
      var slug = el.getAttribute('data-whmcs-product');
      wire(el, window.WHMCS.orderUrl(slug));
    });

    // Log In / Client Area
    document.querySelectorAll('[data-whmcs-action="login"]').forEach(function (el) {
      wire(el, window.WHMCS.clientArea);
    });

    // Submit Ticket → portal login (support tickets live in the portal)
    document.querySelectorAll('[data-whmcs-action="ticket"]').forEach(function (el) {
      wire(el, window.WHMCS.submitTicket);
    });

    // Knowledge Base / Server Status → contact page (no separate internal pages)
    document.querySelectorAll('[data-whmcs-action="kb"], [data-whmcs-action="status"]').forEach(function (el) {
      wire(el, window.WHMCS.knowledgeBase);
    });

  });
})();
