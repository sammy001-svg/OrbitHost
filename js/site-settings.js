/**
 * OrbitHost — applies admin-managed site settings to every page:
 * favicon, logo, announcement bar, header phone, footer content and
 * (on contact.html only) the contact page fields. Safe no-op for any
 * element that isn't present on the current page.
 */
(function () {
  function siteBase() {
    var s = document.querySelector('script[src*="site-settings.js"]');
    if (s && s.src) return s.src.replace(/\/js\/site-settings\.js.*$/i, '');
    return '';
  }
  var BASE = siteBase();

  fetch(BASE + '/api/site-settings.php')
    .then(function (r) { return r.json(); })
    .then(function (d) { if (d.ok) apply(d.settings); })
    .catch(function () {});

  function apply(s) {
    applyBranding(s.branding || {});
    applyHeader(s.header || {}, s.business || {});
    applyFooter(s.footer || {}, s.business || {});
    applyContact(s.contact || {}, s.business || {});
  }

  function fileUrl(path) { return path ? BASE + path : ''; }

  // ── Branding: logo + favicon ──
  function applyBranding(b) {
    var logoUrl = fileUrl(b.logo_image);
    document.querySelectorAll('.logo-icon, .logo-orb, .brand-orb').forEach(function (el) {
      if (!logoUrl) return;
      el.style.background = 'transparent';
      el.innerHTML = '<img src="' + logoUrl + '" alt="Logo" style="width:100%;height:100%;object-fit:contain">';
    });
    if (!logoUrl && (b.site_name_primary || b.site_name_accent)) {
      document.querySelectorAll('.logo-text, .portal-logo span:last-child').forEach(function (el) {
        var accent = el.querySelector('span');
        el.textContent = b.site_name_primary || 'Orbit';
        var span = document.createElement('span');
        span.textContent = b.site_name_accent || 'Host';
        el.appendChild(span);
      });
    }

    var favUrl = fileUrl(b.favicon_image);
    if (favUrl) {
      document.querySelectorAll('link[rel="icon"], link[rel="shortcut icon"]').forEach(function (l) { l.remove(); });
      var link = document.createElement('link');
      link.rel = 'icon';
      link.href = favUrl;
      document.head.appendChild(link);
    }
  }

  // ── Top header: announcement bar + phone chip ──
  function applyHeader(h, biz) {
    var bar = document.querySelector('.announcement-bar');
    if (bar) {
      if (!h.announcement_enabled) {
        bar.style.display = 'none';
      } else {
        bar.style.display = '';
        var content = bar.querySelector('.announcement-content');
        var span = content && content.querySelector('span');
        var link = content && content.querySelector('a');
        if (span && h.announcement_text) span.innerHTML = h.announcement_text;
        if (!link && content && h.announcement_link_url) {
          link = document.createElement('a');
          content.appendChild(link);
        }
        if (link) {
          if (h.announcement_link_text) link.textContent = h.announcement_link_text + ' →';
          if (h.announcement_link_url) link.setAttribute('href', h.announcement_link_url);
        }
      }
    }

    if (h.show_header_phone && biz.phone) {
      document.querySelectorAll('.header-right').forEach(function (hr) {
        if (hr.querySelector('.header-phone')) return;
        var a = document.createElement('a');
        a.className = 'header-phone';
        a.href = 'tel:' + biz.phone.replace(/[^+\d]/g, '');
        a.style.cssText = 'display:flex;align-items:center;gap:6px;color:rgba(255,255,255,.85);font-size:13px;font-weight:600;text-decoration:none;margin-right:6px;white-space:nowrap';
        a.innerHTML = '<i class="fas fa-phone" style="font-size:11px"></i> ' + biz.phone;
        hr.insertBefore(a, hr.firstChild);
      });
    }
  }

  // ── Footer: about text, contacts, copyright, socials ──
  function applyFooter(f, biz) {
    var brand = document.querySelector('.footer-brand');
    if (!brand) return;

    if (f.about_text) {
      var p = brand.querySelector('p');
      if (p) p.textContent = f.about_text;
    }

    var contacts = brand.querySelector('.footer-contacts');
    if (contacts && (biz.phone || biz.support_email || biz.address_line)) {
      var rows = [];
      if (biz.phone)         rows.push('<div class="footer-contact"><i class="fas fa-phone"></i> ' + esc(biz.phone) + '</div>');
      if (biz.support_email) rows.push('<div class="footer-contact"><i class="fas fa-envelope"></i> ' + esc(biz.support_email) + '</div>');
      if (biz.address_line)  rows.push('<div class="footer-contact"><i class="fas fa-map-marker-alt"></i> ' + esc(biz.address_line) + '</div>');
      contacts.innerHTML = rows.join('');
    }

    var socials = [
      ['social_facebook', 'fa-facebook-f'], ['social_twitter', 'fa-x-twitter'],
      ['social_linkedin', 'fa-linkedin-in'], ['social_instagram', 'fa-instagram'],
    ].filter(function (s) { return f[s[0]]; });
    if (socials.length && !brand.querySelector('.socials')) {
      var wrap = document.createElement('div');
      wrap.className = 'socials';
      wrap.style.cssText = 'display:flex;gap:10px;margin-top:14px';
      socials.forEach(function (s) {
        var a = document.createElement('a');
        a.href = f[s[0]]; a.target = '_blank'; a.rel = 'noopener';
        a.style.cssText = 'width:34px;height:34px;border-radius:8px;background:rgba(255,255,255,.08);display:flex;align-items:center;justify-content:center;color:#fff';
        a.innerHTML = '<i class="fab ' + s[1] + '"></i>';
        wrap.appendChild(a);
      });
      brand.appendChild(wrap);
    }

    var bottom = document.querySelector('.footer-bottom span');
    if (bottom && f.copyright_text) {
      bottom.textContent = f.copyright_text.replace('{year}', new Date().getFullYear());
    }
  }

  // ── Contact page (only present on contact.html) ──
  function applyContact(c, biz) {
    var map = {
      'hero-heading': c.hero_heading,
      'hero-subtext': c.hero_subtext,
      'phone': biz.phone,
      'general-email': biz.general_email,
      'support-email': biz.support_email,
      'sales-email': biz.sales_email,
      'office1-title': c.office1_title,
      'office1-address': c.office1_address,
      'office2-title': c.office2_title,
      'office2-address': c.office2_address,
    };
    Object.keys(map).forEach(function (key) {
      var el = document.querySelector('[data-field="' + key + '"]');
      if (!el || !map[key]) return;
      if (key.indexOf('address') !== -1) el.innerHTML = esc(map[key]).replace(/\n/g, '<br>');
      else el.textContent = map[key];
    });
    if (!c.office2_title && !c.office2_address) {
      var card = document.querySelector('[data-card="office2"]');
      if (card) card.style.display = 'none';
    }
  }

  function esc(s) {
    var d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
  }
})();
