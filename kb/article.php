<?php
/**
 * Orbit Cloud — public Knowledge Base: single article view.
 */
require_once __DIR__ . '/../admin/includes/config.php';
require_once __DIR__ . '/../admin/includes/db.php';

function h($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }

$slug = trim($_GET['slug'] ?? '');
$article = null;
try {
    $stmt = db()->prepare('SELECT a.*, c.name AS cat_name, c.slug AS cat_slug FROM kb_articles a LEFT JOIN kb_categories c ON c.id = a.category_id WHERE a.slug = ? AND a.is_published = 1');
    $stmt->execute([$slug]);
    $article = $stmt->fetch();
    if ($article) {
        db()->prepare('UPDATE kb_articles SET views = views + 1 WHERE id = ?')->execute([$article['id']]);
    }
} catch (\Throwable $e) { /* table missing */ }

if (!$article) {
    http_response_code(404);
}

// Related articles from the same category
$related = [];
if ($article && $article['category_id']) {
    $stmt = db()->prepare('SELECT title, slug FROM kb_articles WHERE category_id = ? AND id != ? AND is_published = 1 ORDER BY sort_order LIMIT 5');
    $stmt->execute([$article['category_id'], $article['id']]);
    $related = $stmt->fetchAll();
}
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
  <title><?php echo $article ? h($article['title']) . ' — Orbit Cloud Knowledge Base' : 'Article not found — Orbit Cloud'; ?></title>
  <?php if ($article): ?><meta name="description" content="<?php echo h($article['excerpt'] ?: mb_strimwidth(strip_tags($article['body']), 0, 160, '…')); ?>" /><?php endif; ?>
  <meta name="robots" content="<?php echo $article ? 'index, follow' : 'noindex'; ?>" />
  <?php if ($article): ?><link rel="canonical" href="https://orbitcloud.co.ke/kb/article.php?slug=<?php echo urlencode($article['slug']); ?>" /><?php endif; ?>
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

<section class="section-sm"><div class="container" style="max-width:760px">

  <a href="index.php<?php echo ($article && $article['cat_slug']) ? '?cat=' . urlencode($article['cat_slug']) : ''; ?>" style="color:var(--text-muted);font-size:13.5px;text-decoration:none;display:inline-flex;align-items:center;gap:6px;margin-bottom:20px">
    <i class="fas fa-arrow-left" style="font-size:11px"></i> Back to <?php echo ($article && $article['cat_name']) ? h($article['cat_name']) : 'Knowledge Base'; ?>
  </a>

  <?php if (!$article): ?>
    <div style="text-align:center;padding:60px 0">
      <i class="fas fa-circle-question" style="font-size:44px;opacity:.25;display:block;margin-bottom:16px"></i>
      <h1 style="font-size:20px">Article not found</h1>
      <p style="color:var(--text-muted);margin-top:8px">It may have been moved or unpublished. <a href="index.php">Browse the knowledge base</a> or <a href="../contact.html">contact support</a>.</p>
    </div>
  <?php else: ?>
    <h1 style="font-size:26px;margin-bottom:18px"><?php echo h($article['title']); ?></h1>
    <div style="font-size:15px;line-height:1.75;color:var(--text)"><?php echo $article['body']; /* trusted admin HTML — same treatment as banners/plan descriptions */ ?></div>

    <?php if ($related): ?>
      <div style="margin-top:48px;padding-top:24px;border-top:1px solid var(--border)">
        <h3 style="font-size:15px;margin-bottom:14px">Related articles</h3>
        <ul style="list-style:none;padding:0;display:flex;flex-direction:column;gap:8px">
          <?php foreach ($related as $r): ?>
            <li><a href="article.php?slug=<?php echo urlencode($r['slug']); ?>" style="color:var(--green);font-weight:600;font-size:14px"><i class="fas fa-file-lines" style="font-size:11px;margin-right:6px"></i><?php echo h($r['title']); ?></a></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <div style="margin-top:40px;padding:22px;background:var(--surface,#f8fafc);border-radius:12px;text-align:center">
      <p style="margin-bottom:12px;font-size:14px">Still need help?</p>
      <a href="../contact.html" class="btn btn-green btn-sm"><i class="fas fa-headset"></i> Contact Support</a>
    </div>
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
