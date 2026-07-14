<?php
/**
 * Orbit Cloud — public Knowledge Base (browse + search).
 * Read-only, unauthenticated — reduces support-ticket volume for both
 * prospects and existing clients. Article content lives in admin > KB.
 */
require_once __DIR__ . '/../admin/includes/config.php';
require_once __DIR__ . '/../admin/includes/db.php';

$schema_ok = true;
try {
    db()->query('SELECT 1 FROM kb_articles LIMIT 1');
} catch (\Throwable $e) {
    $schema_ok = false;
}

$q = trim($_GET['q'] ?? '');
$catSlug = trim($_GET['cat'] ?? '');

$categories = $schema_ok ? db()->query('SELECT * FROM kb_categories ORDER BY sort_order, name')->fetchAll() : [];

$activeCat = null;
foreach ($categories as $c) if ($c['slug'] === $catSlug) $activeCat = $c;

$articles = [];
if ($schema_ok) {
    $where = ['is_published = 1'];
    $args  = [];
    if ($q !== '') {
        $where[] = '(title LIKE ? OR excerpt LIKE ? OR body LIKE ?)';
        $like = '%' . $q . '%';
        array_push($args, $like, $like, $like);
    }
    if ($activeCat) {
        $where[] = 'category_id = ?';
        $args[] = $activeCat['id'];
    }
    $sql = 'SELECT id, category_id, title, slug, excerpt FROM kb_articles WHERE ' . implode(' AND ', $where) . ' ORDER BY sort_order, title';
    $stmt = db()->prepare($sql);
    $stmt->execute($args);
    $articles = $stmt->fetchAll();
}

function h($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <link rel="apple-touch-icon" sizes="180x180" href="../apple-touch-icon.png" />
  <link rel="icon" type="image/png" sizes="32x32" href="../favicon-32x32.png" />
  <link rel="icon" type="image/png" sizes="16x16" href="../favicon-16x16.png" />
  <link rel="manifest" href="../site.webmanifest" />
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Knowledge Base — Orbit Cloud</title>
  <meta name="description" content="Search Orbit Cloud's help articles — hosting, domains, billing and account guides." />
  <meta name="robots" content="index, follow" />
  <link rel="canonical" href="https://orbitcloud.co.ke/kb/index.php<?php echo $catSlug ? '?cat=' . urlencode($catSlug) : ''; ?>" />
  <link rel="preload" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" as="style" onload="this.onload=null;this.rel='stylesheet'" /><noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" /></noscript>
  <link rel="stylesheet" href="../css/style.min.css" />
</head>
<body>

<header class="site-header">
  <div class="container">
    <div class="header-inner">
      <a href="../index.html" class="logo">
        <div class="logo-icon"><i class="fas fa-satellite"></i></div>
        <span class="logo-text">Orbit<span>Cloud</span></span>
      </a>
      <nav class="main-nav" aria-label="Main navigation">
        <ul class="nav-list">
          <li class="nav-item"><a href="../hosting/shared.html" class="nav-link">Hosting</a></li>
          <li class="nav-item"><a href="../domains.html" class="nav-link">Domains</a></li>
          <li class="nav-item"><a href="index.php" class="nav-link">Knowledge Base</a></li>
          <li class="nav-item"><a href="../contact.html" class="nav-link">Support</a></li>
        </ul>
      </nav>
      <div class="header-right">
        <a href="#" class="btn btn-outline-white btn-sm" data-whmcs-action="login">Log In</a>
        <a href="#" class="btn btn-green btn-sm" data-whmcs-action="register">Get Started</a>
      </div>
      <button type="button" class="nav-toggle" aria-label="Toggle navigation" aria-expanded="false"><span></span><span></span><span></span></button>
    </div>
  </div>
</header>

<section class="page-hero page-hero-contact"><div class="container"><div class="page-hero-contact-inner">
  <span class="section-tag tag-white">Help Center</span>
  <h1>How can we help?</h1>
  <p>Search our knowledge base, or browse by category below.</p>
  <form method="GET" style="max-width:520px;margin:24px auto 0;display:flex;gap:10px">
    <input type="text" name="q" value="<?php echo h($q); ?>" placeholder="Search articles…" class="form-control" style="flex:1;padding:13px 16px;border-radius:10px;border:none;font-size:15px" />
    <button type="submit" class="btn btn-green" style="padding:13px 20px"><i class="fas fa-magnifying-glass"></i> Search</button>
  </form>
</div></div></section>

<section class="section-sm"><div class="container">

<?php if (!$schema_ok): ?>
  <p style="text-align:center;color:var(--text-muted)">The knowledge base is being set up — please check back soon, or <a href="../contact.html">contact support</a>.</p>
<?php else: ?>

  <?php if ($categories): ?>
  <div style="display:flex;flex-wrap:wrap;gap:10px;justify-content:center;margin-bottom:36px">
    <a href="index.php<?php echo $q ? '?q=' . urlencode($q) : ''; ?>" class="btn <?php echo !$activeCat ? 'btn-green' : 'btn-outline'; ?> btn-sm">All Categories</a>
    <?php foreach ($categories as $c): ?>
      <a href="index.php?cat=<?php echo urlencode($c['slug']); ?><?php echo $q ? '&q=' . urlencode($q) : ''; ?>" class="btn <?php echo $activeCat && $activeCat['id'] === $c['id'] ? 'btn-green' : 'btn-outline'; ?> btn-sm">
        <i class="fas <?php echo h($c['icon']); ?>"></i> <?php echo h($c['name']); ?>
      </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if (!$articles): ?>
    <div style="text-align:center;padding:40px 0;color:var(--text-muted)">
      <i class="fas fa-circle-question" style="font-size:40px;opacity:.25;display:block;margin-bottom:14px"></i>
      <p><?php echo $q ? 'No articles matched "' . h($q) . '".' : 'No articles in this category yet.'; ?> Try <a href="../contact.html">contacting support</a> directly.</p>
    </div>
  <?php else: ?>
    <div class="info-grid info-grid-narrow">
      <?php foreach ($articles as $a): ?>
        <a href="article.php?slug=<?php echo urlencode($a['slug']); ?>" class="info-card border-top-green animate-in" style="text-decoration:none;display:block">
          <h4 style="color:var(--navy)"><?php echo h($a['title']); ?></h4>
          <?php if ($a['excerpt']): ?><p><?php echo h($a['excerpt']); ?></p><?php endif; ?>
          <span style="color:var(--green);font-weight:600;font-size:13.5px">Read article <i class="fas fa-arrow-right" style="font-size:11px"></i></span>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
<?php endif; ?>

</div></section>

<footer class="site-footer">
  <div class="container">
    <div class="footer-bottom">
      <span>&copy; <?php echo date('Y'); ?> Orbit Cloud Ltd. All rights reserved.</span>
      <div class="footer-bottom-links">
        <a href="../legal.html#privacy">Privacy Policy</a>
        <a href="../legal.html#terms">Terms of Service</a>
      </div>
    </div>
  </div>
</footer>

<script src="../js/site-settings.js?v=3" defer></script>
<script src="../js/whmcs.js?v=5" defer></script>
<script src="../js/main.min.js?v=7" defer></script>
</body>
</html>
