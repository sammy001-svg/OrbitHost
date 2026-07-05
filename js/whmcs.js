/**
 * OrbitHost — WHMCS Integration Config
 *
 * HOW TO GO LIVE:
 *  1. Set WHMCS_BASE to your WHMCS installation URL (no trailing slash)
 *  2. Update each pid below to match the Product ID from WHMCS → Products/Services
 *  3. Save — the whole site updates automatically. No rebuild required.
 */

(function () {
  var WHMCS_BASE = 'https://billing.orbithost.com'; // ← change when live

  var PRODUCTS = {
    /* ── Shared Hosting ─────────────────────── */
    'shared-starter':          1,
    'shared-business':         2,
    'shared-pro':              3,
    /* ── VPS Hosting ────────────────────────── */
    'vps-starter':             4,
    'vps-business':            5,
    'vps-pro':                 6,
    /* ── Dedicated Servers ──────────────────── */
    'dedicated-essential':     7,
    'dedicated-business':      8,
    'dedicated-enterprise':    9,
    /* ── Cloud Hosting ──────────────────────── */
    'cloud-starter':          10,
    'cloud-business':         11,
    'cloud-enterprise':       12,
    /* ── WordPress Hosting ──────────────────── */
    'wp-starter':             13,
    'wp-business':            14,
    'wp-pro':                 15,
    /* ── Reseller Hosting ───────────────────── */
    'reseller-starter':       16,
    'reseller-business':      17,
    'reseller-pro':           18,
    /* ── SSL Certificates ───────────────────── */
    'ssl-ov':                 19,
    'ssl-ev':                 20,
    /* ── Email Hosting ──────────────────────── */
    'email-orbitmail':        21,
    'email-m365':             22,
    'email-gworkspace':       23,
  };

  /* ── URL helpers ───────────────────────────── */
  window.WHMCS = {
    base: WHMCS_BASE,
    orderUrl: function (pid) {
      return WHMCS_BASE + '/cart.php?a=add&pid=' + pid;
    },
    domainUrl: function (domain) {
      return WHMCS_BASE + '/cart.php?a=add&domain=' + encodeURIComponent(domain) + '&domaincycle=register';
    },
    clientArea:    WHMCS_BASE + '/clientarea.php',
    submitTicket:  WHMCS_BASE + '/submitticket.php',
    knowledgeBase: WHMCS_BASE + '/knowledgebase.php',
    serverStatus:  WHMCS_BASE + '/serverstatus.php',
  };

  /* ── Auto-wire on DOM ready ────────────────── */
  document.addEventListener('DOMContentLoaded', function () {

    // Plan order buttons: [data-whmcs-product="slug"]
    document.querySelectorAll('[data-whmcs-product]').forEach(function (el) {
      var slug = el.getAttribute('data-whmcs-product');
      var pid  = PRODUCTS[slug];
      if (pid) {
        el.href   = window.WHMCS.orderUrl(pid);
        el.target = '_blank';
        el.rel    = 'noopener noreferrer';
      }
    });

    // Log In / Client Area
    document.querySelectorAll('[data-whmcs-action="login"]').forEach(function (el) {
      el.href   = window.WHMCS.clientArea;
      el.target = '_blank';
      el.rel    = 'noopener noreferrer';
    });

    // Submit Ticket
    document.querySelectorAll('[data-whmcs-action="ticket"]').forEach(function (el) {
      el.href   = window.WHMCS.submitTicket;
      el.target = '_blank';
      el.rel    = 'noopener noreferrer';
    });

    // Knowledge Base
    document.querySelectorAll('[data-whmcs-action="kb"]').forEach(function (el) {
      el.href   = window.WHMCS.knowledgeBase;
      el.target = '_blank';
      el.rel    = 'noopener noreferrer';
    });

    // Server Status
    document.querySelectorAll('[data-whmcs-action="status"]').forEach(function (el) {
      el.href   = window.WHMCS.serverStatus;
      el.target = '_blank';
      el.rel    = 'noopener noreferrer';
    });

  });
})();
