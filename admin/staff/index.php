<?php
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

auth_check();
require_role('super_admin');
$page_title = 'Staff Accounts';

$roles = ['support' => 'Support', 'admin' => 'Admin', 'super_admin' => 'Super Admin'];
$me    = current_admin();

function super_admin_count(?int $excludeId = null): int
{
    $sql = "SELECT COUNT(*) FROM admin_users WHERE role = 'super_admin'";
    if ($excludeId) $sql .= ' AND id != ' . (int) $excludeId;
    return (int) db()->query($sql)->fetchColumn();
}

// ── Save (add / edit / delete) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    if (($_POST['action'] ?? '') === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id === (int) $me['id']) {
            flash_set('error', "You can't delete your own account.");
        } else {
            $stmt = db()->prepare('SELECT name, role FROM admin_users WHERE id = ?');
            $stmt->execute([$id]);
            $u = $stmt->fetch();
            if ($u) {
                if ($u['role'] === 'super_admin' && super_admin_count($id) < 1) {
                    flash_set('error', 'Cannot delete the last remaining Super Admin.');
                } else {
                    db()->prepare('DELETE FROM admin_users WHERE id = ?')->execute([$id]);
                    log_activity('staff_delete', 'admin_user', $id, "Deleted staff account {$u['name']}");
                    flash_set('success', "Staff account \"{$u['name']}\" deleted.");
                }
            }
        }
        header('Location: ' . APP_URL . '/staff/');
        exit;
    }

    $id       = (int) ($_POST['id'] ?? 0);
    $name     = trim($_POST['name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $role     = array_key_exists($_POST['role'] ?? '', $roles) ? $_POST['role'] : 'support';
    $password = (string) ($_POST['password'] ?? '');

    if ($name === '' || $email === '') {
        flash_set('error', 'Name and email are required.');
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash_set('error', 'Enter a valid email address.');
    } elseif (!$id && $password === '') {
        flash_set('error', 'A password is required for a new staff account.');
    } elseif ($id && $id === (int) $me['id'] && $role !== 'super_admin' && super_admin_count($id) < 1) {
        flash_set('error', "You're the last Super Admin — you can't demote your own account.");
    } else {
        $dupe = db()->prepare('SELECT id FROM admin_users WHERE email = ? AND id != ?');
        $dupe->execute([$email, $id]);
        if ($dupe->fetch()) {
            flash_set('error', 'Another staff account already uses that email.');
        } elseif ($password !== '' && ($policy_errors = password_policy_errors($password, [$email, $name]))) {
            flash_set('error', implode(' ', $policy_errors));
        } else {
            if ($id) {
                if ($password !== '') {
                    db()->prepare('UPDATE admin_users SET name=?, email=?, role=?, password=? WHERE id=?')
                        ->execute([$name, $email, $role, password_hash($password, PASSWORD_BCRYPT), $id]);
                } else {
                    db()->prepare('UPDATE admin_users SET name=?, email=?, role=? WHERE id=?')
                        ->execute([$name, $email, $role, $id]);
                }
                log_activity('staff_save', 'admin_user', $id, "Updated staff account {$name}");
                flash_set('success', "Staff account \"{$name}\" updated.");
            } else {
                db()->prepare('INSERT INTO admin_users (name, email, password, role) VALUES (?,?,?,?)')
                    ->execute([$name, $email, password_hash($password, PASSWORD_BCRYPT), $role]);
                $id = (int) db()->lastInsertId();
                log_activity('staff_create', 'admin_user', $id, "Created staff account {$name} ({$role})");
                flash_set('success', "Staff account \"{$name}\" created.");
            }
        }
    }

    header('Location: ' . APP_URL . '/staff/');
    exit;
}

// ── Load staff ──
$staff = db()->query('SELECT id, name, email, role, last_login, created_at FROM admin_users ORDER BY FIELD(role,"super_admin","admin","support"), name')->fetchAll();

require_once '../includes/header.php';
?>

<div class="content-header">
  <div>
    <h1 class="content-title">Staff Accounts</h1>
    <p class="page-subtitle">Manage who has access to this admin panel and what they're allowed to do. Only Super Admins can see this page.</p>
  </div>
  <div class="page-header-actions">
    <button class="btn btn-primary staff-open" data-drawer-open="drawer-staff"
            data-staff='{"id":0,"name":"","email":"","role":"support"}'>
      <i class="fas fa-user-plus"></i> Add Staff
    </button>
  </div>
</div>

<div class="stat-grid" style="grid-template-columns:repeat(3,1fr)">
  <div class="stat-card">
    <div class="stat-icon navy"><i class="fas fa-users-gear"></i></div>
    <div><div class="stat-label">Total staff</div><div class="stat-value"><?php echo count($staff); ?></div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon orange"><i class="fas fa-crown"></i></div>
    <div><div class="stat-label">Super Admins</div><div class="stat-value"><?php echo count(array_filter($staff, fn($s) => $s['role'] === 'super_admin')); ?></div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon green"><i class="fas fa-headset"></i></div>
    <div><div class="stat-label">Support staff</div><div class="stat-value"><?php echo count(array_filter($staff, fn($s) => $s['role'] === 'support')); ?></div></div>
  </div>
</div>

<div class="alert alert-info"><i class="fas fa-circle-info"></i>
  <strong>Support</strong> handles day-to-day tickets, clients, and billing collection.
  <strong>Admin</strong> adds the ability to delete clients/invoices and change site settings or provider credentials.
  <strong>Super Admin</strong> adds managing staff accounts themselves.
</div>

<div class="table-wrap">
  <div class="table-toolbar">
    <span class="card-title">Staff</span>
    <span class="table-count"><?php echo count($staff); ?> accounts</span>
  </div>
  <div class="table-scroll">
  <table>
    <thead>
      <tr>
        <th>Name</th>
        <th>Email</th>
        <th>Role</th>
        <th>Last login</th>
        <th>Added</th>
        <th style="text-align:right">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$staff): ?>
        <tr><td colspan="6"><div class="empty-state"><i class="fas fa-users-gear"></i><p>No staff accounts yet.</p></div></td></tr>
      <?php else: foreach ($staff as $s):
        $roleBadge = match ($s['role']) {
            'super_admin' => 'badge-danger',
            'admin'       => 'badge-primary',
            default       => 'badge-secondary',
        };
        $json = htmlspecialchars(json_encode([
            'id' => (int) $s['id'], 'name' => $s['name'], 'email' => $s['email'], 'role' => $s['role'],
        ], JSON_UNESCAPED_SLASHES), ENT_QUOTES);
        $isMe = (int) $s['id'] === (int) $me['id'];
      ?>
        <tr>
          <td>
            <div class="td-name"><?php echo h($s['name']); ?><?php if ($isMe): ?> <span class="text-muted" style="font-size:11px;font-weight:400">(you)</span><?php endif; ?></div>
          </td>
          <td><?php echo h($s['email']); ?></td>
          <td><span class="badge <?php echo $roleBadge; ?>"><?php echo h($roles[$s['role']] ?? $s['role']); ?></span></td>
          <td><?php echo $s['last_login'] ? format_datetime($s['last_login']) : '<span class="text-muted">Never</span>'; ?></td>
          <td><?php echo format_datetime($s['created_at']); ?></td>
          <td>
            <div class="actions" style="justify-content:flex-end">
              <button class="action-link edit staff-open" data-drawer-open="drawer-staff" data-staff="<?php echo $json; ?>"><i class="fas fa-pen"></i> Edit</button>
              <?php if (!$isMe): ?>
              <form method="POST" style="margin:0">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />
                <input type="hidden" name="action" value="delete" />
                <input type="hidden" name="id" value="<?php echo (int) $s['id']; ?>" />
                <button type="submit" class="action-link danger" data-confirm="Delete staff account &quot;<?php echo h($s['name']); ?>&quot;? They will immediately lose access to this admin panel."><i class="fas fa-trash"></i></button>
              </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
  </div>
</div>

<!-- ── Single add/edit drawer, populated by JS ── -->
<div class="drawer-scrim" id="drawer-staff-scrim"></div>
<div class="drawer" id="drawer-staff">
  <div class="drawer-head">
    <div><div style="font-weight:700" id="staffDrawerTitle">Staff</div>
    <div class="text-muted" style="font-size:11.5px">Admin panel access</div></div>
    <button type="button" class="drawer-close" data-drawer-close>&times;</button>
  </div>
  <form method="POST" style="display:contents">
    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>" />
    <input type="hidden" name="id" id="staffId" value="0" />
    <div class="drawer-body">
      <div class="form-group">
        <label class="form-label">Full name <span class="req">*</span></label>
        <input type="text" name="name" id="staffName" class="form-control" required />
      </div>
      <div class="form-group">
        <label class="form-label">Email <span class="req">*</span></label>
        <input type="email" name="email" id="staffEmail" class="form-control" required />
      </div>
      <div class="form-group">
        <label class="form-label">Role</label>
        <select name="role" id="staffRole" class="form-select">
          <?php foreach ($roles as $v => $l): ?><option value="<?php echo $v; ?>"><?php echo $l; ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label" id="staffPasswordLabel">Password <span class="req">*</span></label>
        <input type="password" name="password" id="staffPassword" class="form-control" autocomplete="new-password" placeholder="Min 10 characters" />
        <small class="form-hint" id="staffPasswordHint">Leave blank to keep their current password.</small>
      </div>
    </div>
    <div class="drawer-foot">
      <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Staff</button>
      <button type="button" class="btn btn-ghost" data-drawer-close>Cancel</button>
    </div>
  </form>
</div>

<script>
document.addEventListener('click', function (e) {
  var btn = e.target.closest ? e.target.closest('.staff-open') : null;
  if (!btn) return;
  var d;
  try { d = JSON.parse(btn.getAttribute('data-staff')); } catch (err) { return; }

  document.getElementById('staffDrawerTitle').textContent = d.id ? 'Edit: ' + d.name : 'Add Staff';
  document.getElementById('staffId').value    = d.id || 0;
  document.getElementById('staffName').value  = d.name || '';
  document.getElementById('staffEmail').value = d.email || '';
  document.getElementById('staffRole').value  = d.role || 'support';

  var pw = document.getElementById('staffPassword');
  var label = document.getElementById('staffPasswordLabel');
  var hint  = document.getElementById('staffPasswordHint');
  pw.value = '';
  if (d.id) {
    pw.required = false;
    label.innerHTML = 'Password';
    hint.textContent = 'Leave blank to keep their current password.';
  } else {
    pw.required = true;
    label.innerHTML = 'Password <span class="req">*</span>';
    hint.textContent = 'At least 10 characters, mixing 3 of: lowercase, uppercase, numbers, symbols.';
  }
});
</script>

<?php require_once '../includes/footer.php'; ?>
