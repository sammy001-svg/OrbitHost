-- ============================================================
--  OrbitHost — Schema v4 Migration
--  Link website plans (services catalogue) to hosting-panel packages
--
--  NOTE: The "Plans & Packages" admin page applies this automatically
--  on first visit if the DB user has ALTER privileges (cPanel users
--  normally do). Import this manually only if that page shows a
--  warning that it could not add the columns.
--
--  phpMyAdmin: select your database in the left panel first,
--  then use Import — do NOT run a USE statement.
-- ============================================================

ALTER TABLE services
  ADD COLUMN IF NOT EXISTS panel_provider VARCHAR(50)  DEFAULT NULL,  -- e.g. whm
  ADD COLUMN IF NOT EXISTS panel_package  VARCHAR(100) DEFAULT NULL;  -- WHM package name
