-- ============================================================
--  OrbitHost — Schema v9 Migration
--  Guest contact details on tickets (for the public contact form —
--  submissions from visitors who don't have a client account yet)
--
--  NOTE: api/contact.php applies this automatically on first use.
--  Import manually only if that page shows a warning.
--
--  phpMyAdmin: select your database in the left panel first,
--  then use Import — do NOT run a USE statement.
-- ============================================================

ALTER TABLE tickets
  ADD COLUMN IF NOT EXISTS guest_name  VARCHAR(150) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS guest_email VARCHAR(150) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS guest_phone VARCHAR(30)  DEFAULT NULL;
