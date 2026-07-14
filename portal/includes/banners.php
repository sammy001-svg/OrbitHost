<?php
/**
 * OrbitHost — shared hero banner carousel for the client portal.
 * Admin manages banners under Marketing › Portal Banners (placement 'hero').
 * Call portal_render_banners() at the top of a page's .container — it
 * renders nothing when no active hero banners exist, outputs its own
 * CSS/JS once, and is safe to call on any portal page.
 */
function portal_render_banners(): void
{
    static $done = false;
    if ($done) return; // once per page
    $done = true;

    try {
        $rows = db()->query("SELECT * FROM portal_banners WHERE is_active = 1 AND placement = 'hero' ORDER BY sort_order, id")->fetchAll();
    } catch (\Throwable $e) {
        $rows = []; // table not created yet
    }
    if (!$rows) return;

    $site_base = preg_replace('#/portal/?$#', '', PORTAL_URL);
    $img = function (?string $u) use ($site_base): string {
        if (!$u) return '';
        return preg_match('#^https?://#i', $u) ? $u : $site_base . '/' . ltrim($u, '/');
    };
    ?>
  <div class="bn-hero" id="bnHero">
    <?php foreach ($rows as $i => $b): ?>
      <div class="bn-slide<?php echo $i === 0 ? ' on' : ''; ?>"
           style="background:<?php echo htmlspecialchars($b['bg_color'] ?: 'var(--navy)'); ?><?php
             echo $b['image_url'] ? ' url(' . htmlspecialchars($img($b['image_url'])) . ') center/cover no-repeat' : ''; ?>">
        <div class="bn-slide-inner">
          <div class="bn-title"><?php echo htmlspecialchars($b['title']); ?></div>
          <?php if ($b['subtitle']): ?><div class="bn-sub"><?php echo htmlspecialchars($b['subtitle']); ?></div><?php endif; ?>
          <?php if ($b['link_url']): ?>
            <a href="<?php echo htmlspecialchars($b['link_url']); ?>" class="btn btn-white btn-sm" style="margin-top:12px;display:inline-flex">
              <?php echo htmlspecialchars($b['link_label'] ?: 'Learn more'); ?> <i class="fas fa-arrow-right" style="font-size:11px"></i>
            </a>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
    <?php if (count($rows) > 1): ?>
      <div class="bn-dots">
        <?php foreach ($rows as $i => $b): ?><button type="button" class="bn-dot<?php echo $i === 0 ? ' on' : ''; ?>" aria-label="Banner <?php echo $i + 1; ?>"></button><?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
  <style>
    .bn-hero { position:relative; border-radius:14px; overflow:hidden; margin-bottom:20px; height:180px; box-shadow:0 6px 20px rgba(11,36,71,.12); }
    .bn-slide { position:absolute; inset:0; opacity:0; transition:opacity .6s; display:flex; align-items:center; }
    .bn-slide.on { opacity:1; z-index:1; }
    .bn-slide-inner { padding:28px 34px; max-width:640px; background:linear-gradient(90deg, rgba(4,14,30,.62) 0%, rgba(4,14,30,.25) 70%, transparent 100%); height:100%; display:flex; flex-direction:column; justify-content:center; align-items:flex-start; }
    .bn-title { color:#fff; font-size:21px; font-weight:800; line-height:1.25; text-shadow:0 1px 4px rgba(0,0,0,.3); }
    .bn-sub { color:rgba(255,255,255,.85); font-size:13.5px; margin-top:6px; text-shadow:0 1px 3px rgba(0,0,0,.3); }
    .bn-dots { position:absolute; bottom:10px; right:16px; display:flex; gap:6px; z-index:2; }
    .bn-dot { width:9px; height:9px; border-radius:50%; border:none; background:rgba(255,255,255,.45); cursor:pointer; padding:0; }
    .bn-dot.on { background:#fff; }
    @media (max-width: 860px) { .bn-hero { height:150px; } .bn-title { font-size:17px; } }
  </style>
  <script>
  (function () {
    var root = document.getElementById('bnHero');
    if (!root) return;
    var slides = root.querySelectorAll('.bn-slide');
    if (slides.length < 2) return;
    var dots = root.querySelectorAll('.bn-dot');
    var cur = 0, timer;
    function show(i) {
      slides[cur].classList.remove('on');
      if (dots[cur]) dots[cur].classList.remove('on');
      cur = (i + slides.length) % slides.length;
      slides[cur].classList.add('on');
      if (dots[cur]) dots[cur].classList.add('on');
    }
    function start() { timer = setInterval(function () { show(cur + 1); }, 6000); }
    dots.forEach(function (d, i) {
      d.addEventListener('click', function () { clearInterval(timer); show(i); start(); });
    });
    start();
  })();
  </script>
    <?php
}
