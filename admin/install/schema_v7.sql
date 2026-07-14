-- ============================================================
--  Orbit Cloud — Schema v7 Migration
--  Site Settings (branding, header, business info, footer, contact page)
--
--  NOTE: admin/settings/index.php creates this table automatically on
--  first visit. Import manually only if that page shows a warning.
--
--  phpMyAdmin: select your database in the left panel first,
--  then use Import — do NOT run a USE statement.
-- ============================================================

CREATE TABLE IF NOT EXISTS site_settings (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    section    VARCHAR(50)  NOT NULL UNIQUE,
    settings   JSON         NOT NULL,
    updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
