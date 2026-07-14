<?php
require_once '../../includes/config.php';
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../includes/providers/Provider.php';
require_once '../../includes/DomainClient.php';
require_once '../../includes/Currency.php';

auth_check();
$page_title = 'TLD Pricing';

// ── Auto-migration (schema_v5.sql as manual fallback) ──
$schema_ok = true;
try {
    db()->exec("CREATE TABLE IF NOT EXISTS domain_tlds (
        id             INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
        tld            VARCHAR(32)   NOT NULL UNIQUE,
        provider       VARCHAR(50)   DEFAULT NULL,
        currency       VARCHAR(10)   NOT NULL DEFAULT 'USD',
        register_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        transfer_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        renew_price    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        register_cost  DECIMAL(10,2) DEFAULT NULL,
        transfer_cost  DECIMAL(10,2) DEFAULT NULL,
        renew_cost     DECIMAL(10,2) DEFAULT NULL,
        is_active      TINYINT(1)   NOT NULL DEFAULT 0,
        sort_order     INT          NOT NULL DEFAULT 100,
        updated_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // registrar column must accept any provider key (was a 3-value ENUM)
    $col = db()->query("SHOW COLUMNS FROM domain_registrations LIKE 'registrar'")->fetch();
    if ($col && stripos($col['Type'] ?? '', 'enum') !== false) {
        db()->exec("ALTER TABLE domain_registrations MODIFY COLUMN registrar VARCHAR(50) NOT NULL DEFAULT 'manual'");
    }
} catch (\Throwable $e) {
    $schema_ok = false;
}
if ($schema_ok) Currency::ensureSchema();

$registrar_key = Provider::activeFor('registrar');
$site_currency = defined('CURRENCY') ? CURRENCY : 'USD';

// ── Actions ──
if ($schema_ok && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'sync') {
            if (!$registrar_key) throw new RuntimeException('No active domain registrar. Enable one in Providers first.');
            $pricing  = Provider::registrar($registrar_key)->getTldPricing();
            // Registrar APIs quote in USD; register_price_kes is left NULL so
            // Currency::ensureSchema()'s seed backfill fills a ~130x starting
            // value next load — admin corrects it to the real KES price.
            $ins = db()->prepare(
                'INSERT INTO domain_tlds (tld, provider, currency, register_price, transfer_price, renew_price,
                                           register_price_usd, transfer_price_usd, renew_price_usd,
                                           register_cost, transfer_cost, renew_cost, is_active)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,0)
                 ON DUPLICATE KEY UPDATE provider=?, register_cost=?, transfer_cost=?, renew_cost=?'
            );
            $n = 0;
            foreach ($pricing as $tld => $p) {
                $ins->execute([
                    $tld, $registrar_key, $site_currency,
                    $p['register'], $p['transfer'], $p['renew'],   // retail defaults = cost
                    $p['register'], $p['transfer'], $p['renew'],
                    $p['register'], $p['transfer'], $p['renew'],
                    $registrar_key, $p['register'], $p['transfer'], $p['renew'],
                ]);
                $n++;
            }
            log_activity('tld_sync', 'integration', 0, "Synced {$n} TLDs from {$registrar_key}");
            flash_set('success', "Synced {$n} TLDs from " . ucfirst($registrar_key) . ". New TLDs are inactive with retail = cost — set your prices (both currencies) and activate them.");

        } elseif ($action === 'save') {
            $id = (int)($_POST['id'] ?? 0);
            db()->prepare('UPDATE domain_tlds SET
                    register_price_usd=?, register_price_kes=?,
                    transfer_price_usd=?, transfer_price_kes=?,
                    renew_price_usd=?,    renew_price_kes=?,
                    is_active=?, sort_order=?
                WHERE id=?')
                ->execute([
                    (float)($_POST['register_price_usd'] ?? 0), (float)($_POST['register_price_kes'] ?? 0),
                    (float)($_POST['transfer_price_usd'] ?? 0), (float)($_POST['transfer_price_kes'] ?? 0),
                    (float)($_POST['renew_price_usd'] ?? 0),    (float)($_POST['renew_price_kes'] ?? 0),
                    !empty($_POST['is_active']) ? 1 : 0,
                    (int)($_POST['sort_order'] ?? 100),
                    $id,
                ]);
            flash_set('success', 'TLD updated.');

        } elseif ($action === 'add') {
            $tld = strtolower(trim(ltrim($_POST['tld'] ?? '', '.')));
            if (!preg_match('/^[a-z0-9.]{2,32}$/', $tld)) throw new RuntimeException('Enter a valid TLD, e.g. com or co.ke');
            db()->prepare('INSERT INTO domain_tlds
                    (tld, provider, currency, register_price, transfer_price, renew_price,
                     register_price_usd, register_price_kes, transfer_price_usd, transfer_price_kes,
                     renew_price_usd, renew_price_kes, is_active)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1)')
                ->execute([
                    $tld, $registrar_key, $site_currency,
                    (float)($_POST['register_price_usd'] ?? 0), (float)($_POST['transfer_price_usd'] ?? 0), (float)($_POST['renew_price_usd'] ?? 0),
                    (float)($_POST['register_price_usd'] ?? 0), (float)($_POST['register_price_kes'] ?? 0),
                    (float)($_POST['transfer_price_usd'] ?? 0), (float)($_POST['transfer_price_kes'] ?? 0),
                    (float)($_POST['renew_price_usd'] ?? 0),    (float)($_POST['renew_price_kes'] ?? 0),
                ]);
            flash_set('success', ".{$tld} added.");

        } elseif ($action === 'delete') {
            db()->prepare('DELETE FROM domain_tlds WHERE id=?')->execute([(int)($_POST['id'] ?? 0)]);
            flash_set('success', 'TLD removed.');
        }
    } catch (\Throwable $e) {
        flash_set('error', $e->getMessage());
    }

    header('Location: ' . APP_URL . '/integrations/domains/tlds.php');
    exit;
}

$tlds = $schema_ok
    ? db()->query('SELECT * FROM domain_tlds ORDER BY sort_order, tld')->fetchAll()
    : [];
$active_count = count(array_filter($tlds, fn($t) => $t['is_active']));

require_once '../../includes/header.php';
?>

<div class="breadcrumb"><a href="<?php echo APP_URL; ?>/integrations/domains/">Domains</a> <span class="breadcrumb-sep">/</span> TLD Pricing</div>

<div class="content-header">
  <div>
    <h1 class="content-title">TLD Pricing</h1>
    <p class="page-subtitle">The extensions your customers can search and buy. Active TLDs appear in the website domain search with these prices.</p>
  </div>
  <div class="page-header-actions">
    <button class="btn btn-ghost" data-drawer-open="drawer-tld-add"><i class="fas fa-plus"></i> Add TLD</button>
    <form method="POST" style="margin:0">
      <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />
      <input type="hidden" name="action" value="sync" />
      <button type="submit" class="btn btn-primary" <?php echo $registrar_key ? '' : 'disabled title="Enable a registrar in Providers first"'; ?>>
        <i class="fas fa-cloud-arrow-down"></i> Sync from <?php echo $registrar_key ? ucfirst($registrar_key) : 'provider'; ?>
      </button>
    </form>
  </div>
</div>

<?php if (!$schema_ok): ?>
  <div class="alert alert-danger"><i class="fas fa-triangle-exclamation"></i> Could not create the pricing table automatically. Import <code>admin/install/schema_v5.sql</code> in phpMyAdmin, then reload this page.</div>
<?php else: ?>

<?php if (!$registrar_key): ?>
  <div class="alert alert-info"><i class="fas fa-circle-info"></i> No domain registrar is active. Enable <a href="<?php echo APP_URL; ?>/integrations/#prov-netearthone" style="font-weight:600">NetEarthOne</a> (or another registrar) in Providers, then sync TLD costs here.</div>
<?php endif; ?>

<div class="stat-grid" style="grid-template-columns:repeat(3,1fr)">
  <div class="stat-card"><div class="stat-icon navy"><i class="fas fa-globe"></i></div><div><div class="stat-label">TLDs</div><div class="stat-value"><?php echo count($tlds); ?></div></div></div>
  <div class="stat-card"><div class="stat-icon green"><i class="fas fa-eye"></i></div><div><div class="stat-label">Active in search</div><div class="stat-value"><?php echo $active_count; ?></div></div></div>
  <div class="stat-card"><div class="stat-icon purple"><i class="fas fa-plug"></i></div><div><div class="stat-label">Registrar</div><div class="stat-value" style="font-size:18px"><?php echo $registrar_key ? ucfirst($registrar_key) : '—'; ?></div></div></div>
</div>

<div class="table-wrap">
  <div class="table-toolbar">
    <span class="card-title">Extensions &amp; prices</span>
    <span class="table-count">Costs are your wholesale prices from the registrar; retail is what customers pay.</span>
  </div>
  <div class="table-scroll">
  <table>
    <thead>
      <tr>
        <th>TLD</th>
        <th>Register ($ / KSh)</th>
        <th>Transfer ($ / KSh)</th>
        <th>Renewal ($ / KSh)</th>
        <th>Cost (reg/trf/ren)</th>
        <th>Order</th>
        <th>Active</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$tlds): ?>
        <tr><td colspan="8"><div class="empty-state"><i class="fas fa-globe"></i><p>No TLDs yet. Sync from your registrar or add one manually.</p></div></td></tr>
      <?php else: foreach ($tlds as $t): ?>
        <tr>
          <form method="POST">
          <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />
          <input type="hidden" name="action" value="save" />
          <input type="hidden" name="id" value="<?php echo $t['id']; ?>" />
          <td><span class="td-name mono">.<?php echo h($t['tld']); ?></span>
            <?php if ($t['provider']): ?><div class="td-sub"><?php echo h($t['provider']); ?></div><?php endif; ?></td>
          <td style="display:flex;gap:5px">
            <input type="number" step="0.01" min="0" name="register_price_usd" class="form-control" style="width:78px;padding:6px 8px" value="<?php echo h($t['register_price_usd'] ?? $t['register_price']); ?>" title="USD" />
            <input type="number" step="0.01" min="0" name="register_price_kes" class="form-control" style="width:88px;padding:6px 8px" value="<?php echo h($t['register_price_kes'] ?? 0); ?>" title="KES" />
          </td>
          <td style="display:flex;gap:5px">
            <input type="number" step="0.01" min="0" name="transfer_price_usd" class="form-control" style="width:78px;padding:6px 8px" value="<?php echo h($t['transfer_price_usd'] ?? $t['transfer_price']); ?>" title="USD" />
            <input type="number" step="0.01" min="0" name="transfer_price_kes" class="form-control" style="width:88px;padding:6px 8px" value="<?php echo h($t['transfer_price_kes'] ?? 0); ?>" title="KES" />
          </td>
          <td style="display:flex;gap:5px">
            <input type="number" step="0.01" min="0" name="renew_price_usd" class="form-control" style="width:78px;padding:6px 8px" value="<?php echo h($t['renew_price_usd'] ?? $t['renew_price']); ?>" title="USD" />
            <input type="number" step="0.01" min="0" name="renew_price_kes" class="form-control" style="width:88px;padding:6px 8px" value="<?php echo h($t['renew_price_kes'] ?? 0); ?>" title="KES" />
          </td>
          <td style="font-size:12px;color:var(--text-muted);white-space:nowrap">
            <?php echo $t['register_cost'] !== null
                ? number_format((float)$t['register_cost'],2) . ' / ' . number_format((float)$t['transfer_cost'],2) . ' / ' . number_format((float)$t['renew_cost'],2)
                : '—'; ?>
          </td>
          <td><input type="number" name="sort_order" class="form-control" style="width:66px;padding:6px 8px" value="<?php echo (int)$t['sort_order']; ?>" /></td>
          <td>
            <label class="switch" style="gap:0"><input type="checkbox" name="is_active" value="1" <?php echo $t['is_active'] ? 'checked' : ''; ?> /><span class="track"></span></label>
          </td>
          <td>
            <div class="actions" style="justify-content:flex-end">
              <button type="submit" class="btn btn-primary btn-xs"><i class="fas fa-save"></i></button>
          </form>
              <form method="POST" style="margin:0">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />
                <input type="hidden" name="action" value="delete" />
                <input type="hidden" name="id" value="<?php echo $t['id']; ?>" />
                <button type="submit" class="action-link danger" data-confirm="Remove .<?php echo h($t['tld']); ?> from your catalogue?"><i class="fas fa-trash"></i></button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
  </div>
</div>

<!-- Add TLD drawer -->
<div class="drawer-scrim" id="drawer-tld-add-scrim"></div>
<div class="drawer" id="drawer-tld-add">
  <div class="drawer-head">
    <div><div style="font-weight:700">Add TLD</div><div class="text-muted" style="font-size:11.5px">Manual extension entry</div></div>
    <button type="button" class="drawer-close" data-drawer-close>&times;</button>
  </div>
  <form method="POST" style="display:contents">
    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />
    <input type="hidden" name="action" value="add" />
    <div class="drawer-body">
      <div class="form-group">
        <label class="form-label">TLD <span class="req">*</span></label>
        <input type="text" name="tld" class="form-control mono" placeholder="co.ke" required />
      </div>
      <div class="form-grid-2" style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div class="form-group"><label class="form-label">Register price (USD)</label><input type="number" step="0.01" min="0" name="register_price_usd" class="form-control" /></div>
        <div class="form-group"><label class="form-label">Register price (KES)</label><input type="number" step="0.01" min="0" name="register_price_kes" class="form-control" /></div>
        <div class="form-group"><label class="form-label">Transfer price (USD)</label><input type="number" step="0.01" min="0" name="transfer_price_usd" class="form-control" /></div>
        <div class="form-group"><label class="form-label">Transfer price (KES)</label><input type="number" step="0.01" min="0" name="transfer_price_kes" class="form-control" /></div>
        <div class="form-group"><label class="form-label">Renewal price (USD)</label><input type="number" step="0.01" min="0" name="renew_price_usd" class="form-control" /></div>
        <div class="form-group"><label class="form-label">Renewal price (KES)</label><input type="number" step="0.01" min="0" name="renew_price_kes" class="form-control" /></div>
      </div>
    </div>
    <div class="drawer-foot">
      <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Add TLD</button>
      <button type="button" class="btn btn-ghost" data-drawer-close>Cancel</button>
    </div>
  </form>
</div>

<?php endif; ?>

<?php require_once '../../includes/footer.php'; ?>
