/**
 * Orbit Cloud — Internal Client Portal link wiring
 *
 * This file used to redirect "Log In" and "Get Started" actions to an
 * external WHMCS billing system. That integration has been removed —
 * every action now routes to the in-house client portal at /portal.
 * No third-party system is contacted.
 *
 * Most nav/header/footer CTAs across the site now carry their real
 * portal href directly in the static HTML (no JS dependency for core
 * navigation). This file still does two things:
 *   1. Keeps window.WHMCS.domainUrl() available — main.js's live
 *      domain-search results still call it to build each "Add to Cart"
 *      link, so this can't be removed even though most of its own
 *      auto-wiring below now has nothing left to do.
 *   2. Auto-wires any leftover placeholder ([href="#"]) tagged with a
 *      data-whmcs-* attribute, for any future button added without a
 *      real href yet.
 *
 * IMPORTANT: every selector below is scoped to [href="#"] specifically
 * so this never touches a link that already points somewhere real —
 * e.g. hosting/dedicated.html's "Order Server" and ssl.html's "Order
 * EV/OV SSL" buttons deliberately route to contact.html (sales-assisted,
 * not self-service) despite still carrying data-whmcs-product for
 * bookkeeping. An earlier version of this file wired unconditionally
 * and silently overwrote those to the self-service signup link on every
 * page load — this guard is what prevents that regression.
 *
 *   data-whmcs-action="login"   → portal login        (/portal/login.php)
 *   data-whmcs-product="slug"   → portal signup        (/portal/register.php?plan=slug)
 *   data-whmcs-action="ticket"  → portal login (tickets live in the portal)
 *   data-whmcs-action="kb"      → public knowledge base (/kb/index.php)
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
    // Domain search "Add to Cart" → portal cart
    domainUrl: function (domain) {
      return PORTAL + '/cart.php?add=' + encodeURIComponent(domain);
    },
    clientArea:    PORTAL + '/login.php',
    register:      PORTAL + '/register.php',
    cart:          PORTAL + '/cart.php',
    submitTicket:  PORTAL + '/login.php',
    knowledgeBase: BASE + '/kb/index.php',
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

    // Plan "Get Started" buttons: [data-whmcs-product="slug"] — only
    // when still a placeholder; never touch an already-real href.
    document.querySelectorAll('[data-whmcs-product][href="#"]').forEach(function (el) {
      var slug = el.getAttribute('data-whmcs-product');
      wire(el, window.WHMCS.orderUrl(slug));
    });

    // Log In / Client Area
    document.querySelectorAll('[data-whmcs-action="login"][href="#"]').forEach(function (el) {
      wire(el, window.WHMCS.clientArea);
    });

    // Create Account / Get Started
    document.querySelectorAll('[data-whmcs-action="register"][href="#"]').forEach(function (el) {
      wire(el, window.WHMCS.register);
    });

    // Cart
    document.querySelectorAll('[data-whmcs-action="cart"][href="#"]').forEach(function (el) {
      wire(el, window.WHMCS.cart);
    });

    // Submit Ticket → portal login (support tickets live in the portal)
    document.querySelectorAll('[data-whmcs-action="ticket"][href="#"]').forEach(function (el) {
      wire(el, window.WHMCS.submitTicket);
    });

    // Knowledge Base / Server Status → contact page (no separate internal pages)
    document.querySelectorAll('[data-whmcs-action="kb"][href="#"], [data-whmcs-action="status"][href="#"]').forEach(function (el) {
      wire(el, window.WHMCS.knowledgeBase);
    });

  });
})();
