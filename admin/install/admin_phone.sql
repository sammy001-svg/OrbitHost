-- ─────────────────────────────────────────────────────────────
-- Orbit Cloud — set the admin's mobile number for SMS sign-in codes.
-- Run in phpMyAdmin (SQL tab) on the live database. Safe to run again.
-- ─────────────────────────────────────────────────────────────

-- 1. Add admin_users.phone if it isn't there yet (the app also adds it
--    on first use; this just makes the UPDATE below work right away).
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_users' AND COLUMN_NAME = 'phone'
);
SET @ddl := IF(@col_exists = 0,
  'ALTER TABLE admin_users ADD COLUMN phone VARCHAR(30) DEFAULT NULL',
  'SELECT ''phone column already exists'' AS note');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. Set the number on the main admin account — the first Super Admin
--    (the one created at install).
--    To target a specific account instead, replace the WHERE line with:
--      WHERE email = 'you@example.com'
UPDATE admin_users
SET phone = '+254111862053'
WHERE id = (SELECT id FROM (SELECT MIN(id) AS id FROM admin_users WHERE role = 'super_admin') AS first_super);

-- 3. Check: the account that now has the number.
SELECT id, name, email, role, phone FROM admin_users ORDER BY id;
