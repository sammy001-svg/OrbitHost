<?php
/**
 * Orbit Cloud — Knowledge Base admin (categories + articles).
 * Public-facing browse/search lives at /kb/index.php and /kb/article.php.
 */
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

auth_check();
$page_title = 'Knowledge Base';

// ── Schema (auto-migration) ──
$schema_ok = true;
try {
    db()->exec("CREATE TABLE IF NOT EXISTS kb_categories (
        id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        name       VARCHAR(150) NOT NULL,
        slug       VARCHAR(160) NOT NULL UNIQUE,
        icon       VARCHAR(50)  NOT NULL DEFAULT 'fa-book',
        sort_order INT NOT NULL DEFAULT 100,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    db()->exec("CREATE TABLE IF NOT EXISTS kb_articles (
        id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        category_id  INT UNSIGNED DEFAULT NULL,
        title        VARCHAR(255) NOT NULL,
        slug         VARCHAR(270) NOT NULL UNIQUE,
        excerpt      VARCHAR(300) DEFAULT NULL,
        body         LONGTEXT NOT NULL,
        is_published TINYINT(1) NOT NULL DEFAULT 1,
        views        INT UNSIGNED NOT NULL DEFAULT 0,
        sort_order   INT NOT NULL DEFAULT 100,
        created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_kba_category (category_id),
        FOREIGN KEY (category_id) REFERENCES kb_categories(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (\Throwable $e) {
    $schema_ok = false;
}

/** Turn a title into a unique URL slug, e.g. "How do I...?" -> "how-do-i". */
function kb_unique_slug(string $table, string $title, int $excludeId = 0): string
{
    $base = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $title), '-'));
    $base = $base !== '' ? $base : 'article';
    $slug = $base;
    $n = 2;
    while (true) {
        $stmt = db()->prepare("SELECT id FROM {$table} WHERE slug = ? AND id != ?");
        $stmt->execute([$slug, $excludeId]);
        if (!$stmt->fetch()) return $slug;
        $slug = $base . '-' . $n++;
    }
}

if ($schema_ok && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'save_category') {
            $id   = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            if ($name === '') throw new RuntimeException('Category name is required.');
            $icon = trim($_POST['icon'] ?? '') ?: 'fa-book';
            $sort = (int)($_POST['sort_order'] ?? 100);
            if ($id) {
                $slug = kb_unique_slug('kb_categories', $name, $id);
                db()->prepare('UPDATE kb_categories SET name=?, slug=?, icon=?, sort_order=? WHERE id=?')
                    ->execute([$name, $slug, $icon, $sort, $id]);
            } else {
                $slug = kb_unique_slug('kb_categories', $name);
                db()->prepare('INSERT INTO kb_categories (name, slug, icon, sort_order) VALUES (?,?,?,?)')
                    ->execute([$name, $slug, $icon, $sort]);
            }
            flash_set('success', 'Category saved.');

        } elseif ($action === 'delete_category') {
            db()->prepare('DELETE FROM kb_categories WHERE id=?')->execute([(int)($_POST['id'] ?? 0)]);
            flash_set('success', 'Category deleted. Its articles are kept, just uncategorised.');

        } elseif ($action === 'save_article') {
            $id      = (int)($_POST['id'] ?? 0);
            $title   = trim($_POST['title'] ?? '');
            if ($title === '') throw new RuntimeException('Article title is required.');
            $catId   = (int)($_POST['category_id'] ?? 0) ?: null;
            $excerpt = trim($_POST['excerpt'] ?? '') ?: null;
            $body    = trim($_POST['body'] ?? '');
            $sort    = (int)($_POST['sort_order'] ?? 100);
            $active  = !empty($_POST['is_published']) ? 1 : 0;
            $slug    = kb_unique_slug('kb_articles', $title, $id);

            if ($id) {
                db()->prepare('UPDATE kb_articles SET category_id=?, title=?, slug=?, excerpt=?, body=?, is_published=?, sort_order=? WHERE id=?')
                    ->execute([$catId, $title, $slug, $excerpt, $body, $active, $sort, $id]);
            } else {
                db()->prepare('INSERT INTO kb_articles (category_id, title, slug, excerpt, body, is_published, sort_order) VALUES (?,?,?,?,?,?,?)')
                    ->execute([$catId, $title, $slug, $excerpt, $body, $active, $sort]);
                $id = (int) db()->lastInsertId();
            }
            log_activity('kb_article_save', 'kb_article', $id, $title);
            flash_set('success', 'Article "' . $title . '" saved.');

        } elseif ($action === 'delete_article') {
            $id = (int)($_POST['id'] ?? 0);
            db()->prepare('DELETE FROM kb_articles WHERE id=?')->execute([$id]);
            log_activity('kb_article_delete', 'kb_article', $id, '');
            flash_set('success', 'Article deleted.');
        }
    } catch (\Throwable $e) {
        flash_set('error', $e->getMessage());
    }
    header('Location: ' . APP_URL . '/kb/');
    exit;
}

$categories = $schema_ok ? db()->query('SELECT * FROM kb_categories ORDER BY sort_order, name')->fetchAll() : [];
$articles   = $schema_ok
    ? db()->query('SELECT a.*, c.name AS cat_name FROM kb_articles a LEFT JOIN kb_categories c ON c.id = a.category_id ORDER BY a.sort_order, a.title')->fetchAll()
    : [];
$published_count = count(array_filter($articles, fn($a) => $a['is_published']));
$site_base = preg_replace('#/admin/?$#', '', APP_URL);

require_once '../includes/header.php';
?>

<div class="content-header">
  <div>
    <h1 class="content-title">Knowledge Base</h1>
    <p class="page-subtitle">Help articles clients (and prospects) can search before opening a ticket.</p>
  </div>
  <div class="page-header-actions">
    <a href="<?php echo $site_base; ?>/kb/index.php" target="_blank" rel="noopener" class="btn btn-ghost"><i class="fas fa-arrow-up-right-from-square"></i> View public page</a>
    <button class="btn btn-ghost" data-drawer-open="drawer-cat" data-cat='{"id":0,"name":"","icon":"fa-book","sort_order":100}'><i class="fas fa-folder-plus"></i> Add Category</button>
    <button class="btn btn-primary" data-drawer-open="drawer-article" data-article='{"id":0,"category_id":"","title":"","excerpt":"","body":"","is_published":1,"sort_order":100}'><i class="fas fa-plus"></i> Add Article</button>
  </div>
</div>

<?php if (!$schema_ok): ?>
  <div class="alert alert-danger"><i class="fas fa-triangle-exclamation"></i> Could not create the knowledge base tables automatically — check DB privileges and reload.</div>
<?php else: ?>

<div class="stat-grid" style="grid-template-columns:repeat(3,1fr)">
  <div class="stat-card"><div class="stat-icon navy"><i class="fas fa-book"></i></div><div><div class="stat-label">Articles</div><div class="stat-value"><?php echo count($articles); ?></div></div></div>
  <div class="stat-card"><div class="stat-icon green"><i class="fas fa-eye"></i></div><div><div class="stat-label">Published</div><div class="stat-value"><?php echo $published_count; ?></div></div></div>
  <div class="stat-card"><div class="stat-icon purple"><i class="fas fa-folder"></i></div><div><div class="stat-label">Categories</div><div class="stat-value"><?php echo count($categories); ?></div></div></div>
</div>

<div class="flex-gap" style="margin:22px 0 12px">
  <i class="fas fa-folder" style="color:var(--navy)"></i>
  <span style="font-weight:700;font-size:14.5px;color:var(--navy)">Categories</span>
</div>
<?php if (!$categories): ?>
  <div class="card"><div class="card-body"><span class="text-muted" style="font-size:13px">No categories yet — articles will show as uncategorised.</span></div></div>
<?php else: ?>
  <div style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:8px">
    <?php foreach ($categories as $c):
      $cjson = htmlspecialchars(json_encode(['id'=>(int)$c['id'],'name'=>$c['name'],'icon'=>$c['icon'],'sort_order'=>(int)$c['sort_order']], JSON_UNESCAPED_SLASHES), ENT_QUOTES); ?>
      <div class="code-chip" style="display:flex;align-items:center;gap:8px;padding:8px 12px">
        <i class="fas <?php echo h($c['icon']); ?>"></i> <?php echo h($c['name']); ?>
        <button type="button" class="action-link edit" data-drawer-open="drawer-cat" data-cat="<?php echo $cjson; ?>" style="margin-left:4px"><i class="fas fa-pen" style="font-size:11px"></i></button>
        <form method="POST" style="margin:0;display:inline">
          <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />
          <input type="hidden" name="action" value="delete_category" />
          <input type="hidden" name="id" value="<?php echo (int)$c['id']; ?>" />
          <button type="submit" class="action-link danger" data-confirm="Delete category &quot;<?php echo h($c['name']); ?>&quot;? Its articles stay, just uncategorised."><i class="fas fa-trash" style="font-size:11px"></i></button>
        </form>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="flex-gap" style="margin:22px 0 12px">
  <i class="fas fa-book" style="color:var(--navy)"></i>
  <span style="font-weight:700;font-size:14.5px;color:var(--navy)">Articles</span>
</div>
<div class="table-wrap">
  <div class="table-scroll">
  <table>
    <thead><tr><th>Title</th><th>Category</th><th>Views</th><th>Order</th><th>Status</th><th style="text-align:right">Actions</th></tr></thead>
    <tbody>
      <?php if (!$articles): ?>
        <tr><td colspan="6"><div class="empty-state"><i class="fas fa-book"></i><p>No articles yet. Add your first help article.</p></div></td></tr>
      <?php else: foreach ($articles as $a):
        $ajson = htmlspecialchars(json_encode([
            'id'=>(int)$a['id'], 'category_id'=>(string)($a['category_id'] ?? ''), 'title'=>$a['title'],
            'excerpt'=>(string)$a['excerpt'], 'body'=>$a['body'], 'is_published'=>(int)$a['is_published'], 'sort_order'=>(int)$a['sort_order'],
        ], JSON_UNESCAPED_SLASHES), ENT_QUOTES); ?>
        <tr>
          <td>
            <div class="td-name"><?php echo h($a['title']); ?></div>
            <div class="td-sub mono"><?php echo h($a['slug']); ?></div>
          </td>
          <td><?php echo $a['cat_name'] ? h($a['cat_name']) : '<span class="text-muted">—</span>'; ?></td>
          <td><?php echo (int)$a['views']; ?></td>
          <td><?php echo (int)$a['sort_order']; ?></td>
          <td><?php echo $a['is_published'] ? '<span class="badge badge-success">Published</span>' : '<span class="badge badge-secondary">Draft</span>'; ?></td>
          <td>
            <div class="actions" style="justify-content:flex-end">
              <?php if ($a['is_published']): ?>
                <a href="<?php echo $site_base; ?>/kb/article.php?slug=<?php echo urlencode($a['slug']); ?>" target="_blank" rel="noopener" class="action-link" title="View"><i class="fas fa-arrow-up-right-from-square"></i></a>
              <?php endif; ?>
              <button class="action-link edit" data-drawer-open="drawer-article" data-article="<?php echo $ajson; ?>"><i class="fas fa-pen"></i> Edit</button>
              <form method="POST" style="margin:0">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />
                <input type="hidden" name="action" value="delete_article" />
                <input type="hidden" name="id" value="<?php echo (int)$a['id']; ?>" />
                <button type="submit" class="action-link danger" data-confirm="Delete article &quot;<?php echo h($a['title']); ?>&quot;?"><i class="fas fa-trash"></i></button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
  </div>
</div>

<!-- ── Category drawer ── -->
<div class="drawer-scrim" id="drawer-cat-scrim"></div>
<div class="drawer" id="drawer-cat">
  <div class="drawer-head">
    <div><div style="font-weight:700" id="catDrawerTitle">Category</div><div class="text-muted" style="font-size:11.5px">Knowledge base grouping</div></div>
    <button type="button" class="drawer-close" data-drawer-close>&times;</button>
  </div>
  <form method="POST" style="display:contents">
    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />
    <input type="hidden" name="action" value="save_category" />
    <input type="hidden" name="id" id="catId" value="0" />
    <div class="drawer-body">
      <div class="form-group">
        <label class="form-label">Name <span class="req">*</span></label>
        <input type="text" name="name" id="catName" class="form-control" required placeholder="e.g. Getting Started" />
      </div>
      <div class="form-group">
        <label class="form-label">Icon <span class="text-muted" style="font-weight:400">(Font Awesome class)</span></label>
        <input type="text" name="icon" id="catIcon" class="form-control mono" placeholder="fa-rocket" />
        <small class="form-hint">e.g. fa-rocket, fa-server, fa-credit-card — see fontawesome.com/icons.</small>
      </div>
      <div class="form-group">
        <label class="form-label">Sort order</label>
        <input type="number" name="sort_order" id="catSort" class="form-control" value="100" />
      </div>
    </div>
    <div class="drawer-foot">
      <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Category</button>
      <button type="button" class="btn btn-ghost" data-drawer-close>Cancel</button>
    </div>
  </form>
</div>

<!-- ── Article drawer ── -->
<div class="drawer-scrim" id="drawer-article-scrim"></div>
<div class="drawer" id="drawer-article" style="width:640px">
  <div class="drawer-head">
    <div><div style="font-weight:700" id="articleDrawerTitle">Article</div><div class="text-muted" style="font-size:11.5px">Knowledge base</div></div>
    <button type="button" class="drawer-close" data-drawer-close>&times;</button>
  </div>
  <form method="POST" style="display:contents">
    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />
    <input type="hidden" name="action" value="save_article" />
    <input type="hidden" name="id" id="artId" value="0" />
    <div class="drawer-body">
      <div class="form-group">
        <label class="form-label">Title <span class="req">*</span></label>
        <input type="text" name="title" id="artTitle" class="form-control" required placeholder="e.g. How do I point my domain's nameservers?" />
      </div>
      <div class="form-group">
        <label class="form-label">Category</label>
        <select name="category_id" id="artCategory" class="form-select">
          <option value="">— Uncategorised —</option>
          <?php foreach ($categories as $c): ?><option value="<?php echo (int)$c['id']; ?>"><?php echo h($c['name']); ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Excerpt <span class="text-muted" style="font-weight:400">(shown in search results)</span></label>
        <input type="text" name="excerpt" id="artExcerpt" class="form-control" maxlength="300" placeholder="One-line summary" />
      </div>
      <div class="form-group">
        <label class="form-label">Body <span class="req">*</span></label>
        <textarea name="body" id="artBody" class="form-control" rows="12" required placeholder="Full answer. Basic HTML (e.g. <p>, <strong>, <ul><li>) is supported."></textarea>
        <small class="form-hint">Basic HTML is rendered as-is — this is trusted admin content, same as banners and plan descriptions elsewhere in this panel.</small>
      </div>
      <div class="form-group">
        <label class="form-label">Sort order</label>
        <input type="number" name="sort_order" id="artSort" class="form-control" value="100" />
      </div>
      <div class="form-group">
        <label class="switch">
          <input type="checkbox" name="is_published" id="artPublished" value="1" />
          <span class="track"></span><span>Published (visible on the public knowledge base)</span>
        </label>
      </div>
    </div>
    <div class="drawer-foot">
      <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Article</button>
      <button type="button" class="btn btn-ghost" data-drawer-close>Cancel</button>
    </div>
  </form>
</div>

<script>
document.addEventListener('click', function (e) {
  var catBtn = e.target.closest ? e.target.closest('[data-drawer-open="drawer-cat"]') : null;
  if (catBtn) {
    var d; try { d = JSON.parse(catBtn.getAttribute('data-cat')); } catch (err) { return; }
    document.getElementById('catDrawerTitle').textContent = d.id ? 'Edit: ' + d.name : 'Add Category';
    document.getElementById('catId').value   = d.id || 0;
    document.getElementById('catName').value = d.name || '';
    document.getElementById('catIcon').value = d.icon || 'fa-book';
    document.getElementById('catSort').value = d.sort_order || 100;
    return;
  }
  var artBtn = e.target.closest ? e.target.closest('[data-drawer-open="drawer-article"]') : null;
  if (artBtn) {
    var a; try { a = JSON.parse(artBtn.getAttribute('data-article')); } catch (err) { return; }
    document.getElementById('articleDrawerTitle').textContent = a.id ? 'Edit: ' + a.title : 'Add Article';
    document.getElementById('artId').value        = a.id || 0;
    document.getElementById('artCategory').value  = a.category_id || '';
    document.getElementById('artTitle').value     = a.title || '';
    document.getElementById('artExcerpt').value   = a.excerpt || '';
    document.getElementById('artBody').value      = a.body || '';
    document.getElementById('artSort').value      = a.sort_order || 100;
    document.getElementById('artPublished').checked = !!Number(a.is_published);
  }
});
</script>

<?php endif; ?>

<?php require_once '../includes/footer.php'; ?>
