/* ================================================
   ORBITHOST — Main JavaScript
   ================================================ */

// Carousel
(function () {
  const wrapper = document.querySelector('.carousel-slides');
  const dots = document.querySelector('.carousel-dots');
  const prev = document.querySelector('.carousel-prev');
  const next = document.querySelector('.carousel-next');
  if (!wrapper) return;

  const slides = wrapper.querySelectorAll('.carousel-slide');
  let current = 0, timer;

  slides.forEach((_, i) => {
    const d = document.createElement('button');
    d.className = 'dot' + (i === 0 ? ' active' : '');
    d.setAttribute('aria-label', 'Go to slide ' + (i + 1));
    d.addEventListener('click', () => goTo(i));
    if (dots) dots.appendChild(d);
  });

  function goTo(n) {
    const allDots = dots ? dots.querySelectorAll('.dot') : [];
    if (allDots[current]) allDots[current].classList.remove('active');
    current = (n + slides.length) % slides.length;
    wrapper.style.transform = `translateX(-${current * 100}%)`;
    if (allDots[current]) allDots[current].classList.add('active');
    clearInterval(timer);
    timer = setInterval(() => goTo(current + 1), 5800);
  }

  if (prev) prev.addEventListener('click', () => goTo(current - 1));
  if (next) next.addEventListener('click', () => goTo(current + 1));

  // Touch swipe
  let tx = 0;
  wrapper.addEventListener('touchstart', e => { tx = e.touches[0].clientX; }, { passive: true });
  wrapper.addEventListener('touchend', e => {
    const diff = tx - e.changedTouches[0].clientX;
    if (Math.abs(diff) > 50) diff > 0 ? goTo(current + 1) : goTo(current - 1);
  });

  // Keyboard
  document.addEventListener('keydown', e => {
    if (e.key === 'ArrowRight') goTo(current + 1);
    if (e.key === 'ArrowLeft') goTo(current - 1);
  });

  timer = setInterval(() => goTo(current + 1), 5800);
})();

// Mobile Nav
(function () {
  const toggle = document.querySelector('.nav-toggle');
  const mobileNav = document.querySelector('.mobile-nav');
  if (!toggle || !mobileNav) return;

  toggle.addEventListener('click', () => {
    const open = mobileNav.classList.toggle('open');
    toggle.classList.toggle('open', open);
    document.body.style.overflow = open ? 'hidden' : '';
  });

  mobileNav.querySelectorAll('.mobile-nav-link[data-sub]').forEach(link => {
    link.addEventListener('click', e => {
      e.preventDefault();
      const item = link.closest('.mobile-nav-item');
      item.classList.toggle('sub-open');
    });
  });
})();

// FAQ Accordion
(function () {
  document.querySelectorAll('.faq-q').forEach(q => {
    q.addEventListener('click', () => {
      const item = q.closest('.faq-item');
      const answer = item.querySelector('.faq-a');
      const isOpen = item.classList.contains('open');
      document.querySelectorAll('.faq-item').forEach(i => {
        i.classList.remove('open');
        const a = i.querySelector('.faq-a');
        if (a) a.style.maxHeight = '0';
      });
      if (!isOpen) {
        item.classList.add('open');
        answer.style.maxHeight = answer.scrollHeight + 'px';
      }
    });
  });
})();

// Plan Billing Tabs
(function () {
  const tabs = document.querySelectorAll('.plan-tab');
  tabs.forEach(tab => {
    tab.addEventListener('click', () => {
      tabs.forEach(t => t.classList.remove('active'));
      tab.classList.add('active');
      const billing = tab.dataset.billing;
      document.querySelectorAll('.price-monthly').forEach(el => {
        el.style.display = billing === 'monthly' ? '' : 'none';
      });
      document.querySelectorAll('.price-annual').forEach(el => {
        el.style.display = billing === 'annual' ? '' : 'none';
      });
    });
  });
})();

// Sticky offer bar
(function () {
  const bar = document.querySelector('.sticky-offer');
  const closeBtn = document.querySelector('.sticky-close');
  if (!bar) return;
  let dismissed = false;

  window.addEventListener('scroll', () => {
    if (dismissed) return;
    bar.classList.toggle('show', window.scrollY > 700);
  });

  if (closeBtn) closeBtn.addEventListener('click', () => {
    dismissed = true;
    bar.classList.remove('show');
  });
})();

// Announcement bar close
(function () {
  const btn = document.querySelector('.announcement-close');
  const bar = document.querySelector('.announcement-bar');
  if (btn && bar) btn.addEventListener('click', () => bar.remove());
})();

// Domain Search
(function () {
  const form = document.querySelector('.domain-form');
  const input = document.querySelector('.domain-input');
  const results = document.querySelector('.domain-results');
  if (!form) return;

  const tldData = [
    { tld: '.com',   price: '$12.99/yr',  avail: true  },
    { tld: '.net',   price: '$14.99/yr',  avail: true  },
    { tld: '.org',   price: '$11.99/yr',  avail: false },
    { tld: '.co.ke', price: '$9.99/yr',   avail: true  },
    { tld: '.ke',    price: '$19.99/yr',  avail: false },
    { tld: '.io',    price: '$39.99/yr',  avail: true  },
  ];

  form.addEventListener('submit', e => {
    e.preventDefault();
    if (!input || !results) return;
    const raw = input.value.trim();
    if (!raw) return;
    const name = raw.replace(/\.[a-z.]+$/, '').replace(/[^a-zA-Z0-9-]/g, '').toLowerCase();
    if (!name) return;

    const submitBtn = form.querySelector('button[type="submit"]');
    if (submitBtn) { submitBtn.disabled = true; submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Searching…'; }
    results.innerHTML = '<div style="text-align:center;padding:28px 0;color:var(--text-muted);font-size:0.9375rem"><i class="fas fa-spinner fa-spin"></i> Checking availability…</div>';

    setTimeout(() => {
      if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = 'Search <i class="fas fa-search"></i>'; }
      const rows = tldData.map(({ tld, price, avail }) => {
        const cls = avail ? 'result-available' : 'result-taken';
        const icon = avail ? 'fa-check-circle' : 'fa-times-circle';
        const statusText = avail ? 'Available' : 'Taken';
        const action = avail
          ? `<a href="#" class="btn btn-green btn-sm">Add to Cart</a>`
          : `<span class="btn btn-outline-navy btn-sm" style="opacity:.45;cursor:default;">Unavailable</span>`;
        return `
          <div class="domain-result-row ${cls}">
            <span class="result-name">${name}${tld}</span>
            <span class="result-status"><i class="fas ${icon}"></i> ${statusText}</span>
            <span class="result-price">${avail ? price : ''}</span>
            ${action}
          </div>`;
      }).join('');
      results.innerHTML = `<div class="domain-results-inner">${rows}</div>`;
    }, 700);
  });

  // TLD pills fill search input
  document.querySelectorAll('.tld-pill').forEach(pill => {
    pill.addEventListener('click', () => {
      if (input) {
        const tld = pill.dataset.tld;
        if (!input.value) input.value = 'yourdomain';
        input.value = input.value.replace(/\.[a-z.]+$/, '') + tld;
        input.focus();
      }
    });
  });
})();

// Scroll animations
(function () {
  const io = new IntersectionObserver(entries => {
    entries.forEach(e => {
      if (e.isIntersecting) { e.target.classList.add('visible'); io.unobserve(e.target); }
    });
  }, { threshold: 0.08 });
  document.querySelectorAll('.animate-in').forEach(el => io.observe(el));
})();

// Animated counters
(function () {
  const io = new IntersectionObserver(entries => {
    entries.forEach(e => {
      if (!e.isIntersecting) return;
      const el = e.target;
      const end = parseFloat(el.dataset.count);
      const suffix = el.dataset.suffix || '';
      const dec = el.dataset.decimal || 0;
      let current = 0;
      const step = end / 55;
      const run = () => {
        current = Math.min(current + step, end);
        el.textContent = current.toFixed(dec) + suffix;
        if (current < end) requestAnimationFrame(run);
        else el.textContent = end.toFixed(dec) + suffix;
      };
      requestAnimationFrame(run);
      io.unobserve(el);
    });
  }, { threshold: 0.5 });
  document.querySelectorAll('[data-count]').forEach(el => io.observe(el));
})();

// Reading progress bar
(function () {
  const bar = document.createElement('div');
  bar.className = 'reading-progress';
  document.body.prepend(bar);
  window.addEventListener('scroll', () => {
    const h = document.documentElement.scrollHeight - window.innerHeight;
    bar.style.width = h > 0 ? (window.scrollY / h * 100) + '%' : '0%';
  }, { passive: true });
})();

// Back to top + floating element positioning
(function () {
  const btn = document.createElement('button');
  btn.type = 'button';
  btn.className = 'back-to-top';
  btn.setAttribute('aria-label', 'Back to top');
  btn.innerHTML = '<i class="fas fa-chevron-up"></i>';
  document.body.appendChild(btn);

  btn.addEventListener('click', () => window.scrollTo({ top: 0, behavior: 'smooth' }));

  function reposition() {
    const stickyOn = document.querySelector('.sticky-offer')?.classList.contains('show');
    const base = stickyOn ? 90 : 24;
    const chat = document.querySelector('.chat-widget');
    if (chat) chat.style.bottom = base + 'px';
    btn.style.bottom = (base + 64) + 'px';
    btn.classList.toggle('visible', window.scrollY > 600);
  }

  window.addEventListener('scroll', reposition, { passive: true });
  reposition();
})();

// Live chat widget
(function () {
  const widget = document.createElement('div');
  widget.className = 'chat-widget';
  widget.style.bottom = '24px';
  widget.innerHTML =
    '<div class="chat-panel" id="chatPanel">' +
      '<div class="chat-ph">' +
        '<div class="chat-ph-av"><i class="fas fa-headset"></i></div>' +
        '<div class="chat-ph-info">' +
          '<div class="chat-ph-name">OrbitHost Support</div>' +
          '<div class="chat-ph-status"><span class="chat-status-dot"></span> Online &mdash; avg reply &lt;3 min</div>' +
        '</div>' +
        '<button type="button" class="chat-ph-x" aria-label="Close chat"><i class="fas fa-times"></i></button>' +
      '</div>' +
      '<div class="chat-pb">' +
        '<div class="chat-bubble">&#128075; Hi there! How can we help you today?</div>' +
        '<div class="chat-opts">' +
          '<button type="button" class="chat-opt">&#128172; Start a live chat</button>' +
          '<button type="button" class="chat-opt">&#128140; Send us an email</button>' +
          '<button type="button" class="chat-opt">&#128214; Browse Knowledge Base</button>' +
        '</div>' +
      '</div>' +
      '<div class="chat-pf">' +
        '<input type="text" class="chat-input" placeholder="Type a message…" />' +
        '<button type="button" class="chat-send" aria-label="Send message"><i class="fas fa-paper-plane"></i></button>' +
      '</div>' +
    '</div>' +
    '<button type="button" class="chat-btn" id="chatToggle" aria-label="Open live chat">' +
      '<i class="fas fa-comments"></i>' +
      '<span class="chat-badge">1</span>' +
    '</button>';
  document.body.appendChild(widget);

  const panel  = widget.querySelector('#chatPanel');
  const toggle = widget.querySelector('#chatToggle');
  const badge  = widget.querySelector('.chat-badge');
  const closeX = widget.querySelector('.chat-ph-x');

  toggle.addEventListener('click', () => {
    const opening = !panel.classList.contains('open');
    panel.classList.toggle('open', opening);
    if (opening && badge) badge.style.display = 'none';
  });
  if (closeX) closeX.addEventListener('click', () => panel.classList.remove('open'));
})();

// Contact form validation
(function () {
  const form = document.getElementById('contactForm');
  if (!form) return;

  const REQUIRED = ['cf-first', 'cf-last', 'cf-email', 'cf-subject', 'cf-message'];

  function val(id) { const el = document.getElementById(id); return el ? el.value.trim() : ''; }

  function setErr(id, msg) {
    const errEl = document.getElementById(id + '-err');
    const input = document.getElementById(id);
    if (errEl) errEl.textContent = msg;
    if (input) {
      input.classList.toggle('is-invalid', !!msg);
      input.classList.toggle('is-valid', !msg && input.value.trim() !== '');
    }
    return !msg;
  }

  function validateField(id) {
    const v = val(id);
    if (id === 'cf-email') {
      if (!v) return setErr(id, 'Email address is required.');
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v)) return setErr(id, 'Please enter a valid email address.');
    } else if (id === 'cf-subject') {
      if (!v) return setErr(id, 'Please select a subject.');
    } else if (id === 'cf-message') {
      if (!v) return setErr(id, 'Message is required.');
      if (v.length < 20) return setErr(id, 'Please write at least 20 characters.');
    } else if (id === 'cf-first') {
      if (!v) return setErr(id, 'First name is required.');
    } else if (id === 'cf-last') {
      if (!v) return setErr(id, 'Last name is required.');
    }
    return setErr(id, '');
  }

  REQUIRED.forEach(id => {
    const el = document.getElementById(id);
    if (!el) return;
    el.addEventListener('blur', () => validateField(id));
    el.addEventListener('input', () => { if (el.classList.contains('is-invalid')) validateField(id); });
  });

  form.addEventListener('submit', e => {
    e.preventDefault();
    const allValid = REQUIRED.map(validateField).every(Boolean);
    if (!allValid) {
      const first = form.querySelector('.is-invalid');
      if (first) first.scrollIntoView({ behavior: 'smooth', block: 'center' });
      return;
    }
    const btn = document.getElementById('cf-submit');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending…';
    setTimeout(() => {
      const success = document.getElementById('cf-success');
      if (success) { success.hidden = false; success.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
      btn.hidden = true;
      REQUIRED.forEach(id => {
        const el = document.getElementById(id);
        if (el) { el.value = ''; el.classList.remove('is-valid', 'is-invalid'); }
      });
    }, 1600);
  });
})();

// Newsletter form validation
(function () {
  const form = document.getElementById('newsletterForm');
  if (!form) return;
  const input = document.getElementById('nl-email');
  const errEl = document.getElementById('nl-email-err');
  const success = document.getElementById('nl-success');

  form.addEventListener('submit', e => {
    e.preventDefault();
    const v = input ? input.value.trim() : '';
    if (!v) { if (errEl) errEl.textContent = 'Please enter your email address.'; return; }
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v)) { if (errEl) errEl.textContent = 'Please enter a valid email address.'; return; }
    if (errEl) errEl.textContent = '';
    const btn = form.querySelector('button[type="submit"]');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>'; }
    setTimeout(() => {
      if (success) { success.hidden = false; }
      form.querySelector('.newsletter-field').style.display = 'none';
      if (errEl) errEl.textContent = '';
    }, 1200);
  });

  if (input) input.addEventListener('input', () => { if (errEl) errEl.textContent = ''; });
})();

// Service Worker registration
if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/sw.js').catch(() => {});
  });
}

// Cookie consent
(function () {
  if (localStorage.getItem('oh_cookies')) return;

  const bar = document.createElement('div');
  bar.className = 'cookie-bar';
  bar.innerHTML =
    '<div class="container">' +
      '<div class="cookie-inner">' +
        '<p class="cookie-text">We use cookies to improve your experience and analyse site traffic. By clicking “Accept All” you agree to our <a href="#">Cookie Policy</a> and <a href="#">Privacy Policy</a>.</p>' +
        '<div class="cookie-btns">' +
          '<button type="button" class="btn btn-outline-white btn-sm" id="cookieManage">Manage</button>' +
          '<button type="button" class="btn btn-green btn-sm" id="cookieAccept">Accept All</button>' +
        '</div>' +
      '</div>' +
    '</div>';
  document.body.appendChild(bar);

  setTimeout(() => bar.classList.add('show'), 1400);

  function dismiss() {
    localStorage.setItem('oh_cookies', '1');
    bar.classList.remove('show');
    setTimeout(() => bar.remove(), 500);
  }

  bar.querySelector('#cookieAccept').addEventListener('click', dismiss);
  bar.querySelector('#cookieManage').addEventListener('click', dismiss);
})();
